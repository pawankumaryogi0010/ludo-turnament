/**
 * ======================================================
 * SERVER.JS - WebSocket Server for Ludo Tournament
 * Ludo Tournament Platform - Socket.io Server
 * Version: 2.0.0 - COMPLETE
 * ======================================================
 */

const express = require('express');
const http = require('http');
const socketIO = require('socket.io');
const cors = require('cors');
const mysql = require('mysql2/promise');

// ==============================================
// CONFIGURATION
// ==============================================
const PORT = process.env.PORT || 3000;

// BUG FIX: Use environment variables instead of hardcoded credentials.
// Previous code had wrong DB name/user/password not matching .env file.
// BUG FIX: Use createPool (below) for resilience — single createConnection
// breaks permanently if the connection drops.
const DB_CONFIG = {
    host: process.env.DB_HOST || 'localhost',
    user: process.env.DB_USER || 'root',
    password: process.env.DB_PASS || '',
    database: process.env.DB_NAME || 'ludo_tournament',
    waitForConnections: true,
    connectionLimit: 10,
    queueLimit: 0
};

// ==============================================
// EXPRESS SETUP
// ==============================================
const app = express();
const server = http.createServer(app);
const io = socketIO(server, {
    cors: {
        origin: '*',
        methods: ['GET', 'POST'],
        credentials: true
    },
    pingTimeout: 60000,
    pingInterval: 25000
});

app.use(cors());
app.use(express.json());

// ==============================================
// DATABASE CONNECTION
// ==============================================
let db;

async function connectDatabase() {
    try {
        // BUG FIX: Use createPool instead of createConnection so a dropped
        // connection doesn't crash the server permanently.
        db = await mysql.createPool(DB_CONFIG);
        console.log('✅ Database pool created');
        
        // BUG FIX: pool.execute instead of connection.execute
        await db.execute(`
            CREATE TABLE IF NOT EXISTS websocket_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                socket_id VARCHAR(255) NOT NULL,
                user_id INT NOT NULL,
                match_id INT,
                room_code VARCHAR(10),
                is_active BOOLEAN DEFAULT TRUE,
                connected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_socket_id (socket_id),
                INDEX idx_user_id (user_id),
                INDEX idx_room_code (room_code)
            )
        `);
        console.log('✅ WebSocket sessions table ready');
    } catch (error) {
        console.error('❌ Database connection failed:', error);
        process.exit(1);
    }
}

// ==============================================
// GAME ROOM MANAGEMENT
// ==============================================
const rooms = new Map();
const userSessions = new Map();

class GameRoom {
    constructor(roomCode, matchId) {
        this.roomCode = roomCode;
        this.matchId = matchId;
        this.players = [];
        this.gameState = {
            status: 'waiting',
            currentTurn: null,
            diceValue: 0,
            diceHistory: [],
            turnNumber: 0,
            board: {},
            winner: null,
            isGameOver: false
        };
        this.maxPlayers = 2;
        this.diceRollHistory = [];
        this.consecutiveSixes = {};
        this.createdAt = Date.now();
    }

    addPlayer(userId, username) {
        if (this.players.length >= this.maxPlayers) {
            return { success: false, message: 'Room is full' };
        }
        
        const playerNumber = this.players.length + 1;
        const player = {
            userId,
            username,
            playerNumber,
            socketId: null,
            isReady: false,
            isActive: true,
            joinedAt: Date.now()
        };
        
        this.players.push(player);
        
        if (this.players.length === 2) {
            this.gameState.status = 'ready';
        }
        
        return { success: true, player };
    }

    removePlayer(userId) {
        const index = this.players.findIndex(p => p.userId === userId);
        if (index === -1) return null;
        const player = this.players[index];
        this.players.splice(index, 1);
        if (this.players.length < 2) {
            this.gameState.status = 'waiting';
        }
        return player;
    }

    getPlayer(userId) {
        return this.players.find(p => p.userId === userId);
    }

    getPlayerNumber(userId) {
        const player = this.getPlayer(userId);
        return player ? player.playerNumber : null;
    }

    isFull() {
        return this.players.length >= this.maxPlayers;
    }

    setPlayerSocket(userId, socketId) {
        const player = this.getPlayer(userId);
        if (player) {
            player.socketId = socketId;
            return true;
        }
        return false;
    }

    updateGameState(state) {
        this.gameState = { ...this.gameState, ...state };
    }

    addDiceHistory(value, userId) {
        this.diceRollHistory.push({ value, userId, timestamp: Date.now() });
        if (this.diceRollHistory.length > 100) {
            this.diceRollHistory.shift();
        }
    }

    getConsecutiveSixes(userId) {
        return this.consecutiveSixes[userId] || 0;
    }

    incrementConsecutiveSixes(userId) {
        if (!this.consecutiveSixes[userId]) {
            this.consecutiveSixes[userId] = 0;
        }
        this.consecutiveSixes[userId]++;
        return this.consecutiveSixes[userId];
    }

    resetConsecutiveSixes(userId) {
        this.consecutiveSixes[userId] = 0;
    }

    getOpponent(userId) {
        return this.players.find(p => p.userId !== userId);
    }

    toJSON() {
        return {
            roomCode: this.roomCode,
            matchId: this.matchId,
            players: this.players.map(p => ({
                userId: p.userId,
                username: p.username,
                playerNumber: p.playerNumber,
                isReady: p.isReady
            })),
            gameState: this.gameState,
            maxPlayers: this.maxPlayers,
            isFull: this.isFull()
        };
    }
}

// ==============================================
// WEBSOCKET EVENT HANDLERS
// ==============================================
io.on('connection', (socket) => {
    console.log('🔌 New client connected:', socket.id);
    
    let currentUserId = null;
    let currentRoomCode = null;

    // ==========================================
    // HANDLER: Join Room
    // ==========================================
    socket.on('join_room', async (data) => {
        try {
            const { roomCode, userId, username, matchId } = data;
            
            if (!roomCode || !userId || !username) {
                socket.emit('error', { message: 'Missing required fields' });
                return;
            }

            currentUserId = userId;
            currentRoomCode = roomCode;

            let room = rooms.get(roomCode);
            
            if (!room) {
                room = new GameRoom(roomCode, matchId || 0);
                rooms.set(roomCode, room);
                console.log(`📦 Room created: ${roomCode}`);
            }

            const existingPlayer = room.getPlayer(userId);
            if (existingPlayer) {
                room.setPlayerSocket(userId, socket.id);
                socket.join(roomCode);
                socket.emit('room_joined', {
                    success: true,
                    room: room.toJSON(),
                    playerNumber: existingPlayer.playerNumber
                });
                socket.to(roomCode).emit('player_reconnected', {
                    userId,
                    username,
                    playerNumber: existingPlayer.playerNumber
                });
                console.log(`♻️ Player ${username} reconnected to room ${roomCode}`);
                return;
            }

            const result = room.addPlayer(userId, username);
            
            if (!result.success) {
                socket.emit('error', { message: result.message });
                return;
            }

            room.setPlayerSocket(userId, socket.id);
            socket.join(roomCode);
            userSessions.set(userId, socket.id);

            if (db) {
                await db.execute(
                    `INSERT INTO websocket_sessions (socket_id, user_id, match_id, room_code) 
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE 
                     last_activity = CURRENT_TIMESTAMP, is_active = TRUE`,
                    [socket.id, userId, room.matchId || 0, roomCode]
                );
            }

            socket.emit('room_joined', {
                success: true,
                room: room.toJSON(),
                playerNumber: result.player.playerNumber
            });

            socket.to(roomCode).emit('player_joined', {
                userId,
                username,
                playerNumber: result.player.playerNumber,
                playerCount: room.players.length
            });

            console.log(`👤 Player ${username} joined room ${roomCode} (${room.players.length}/${room.maxPlayers})`);

            if (room.isFull()) {
                const firstTurn = Math.random() < 0.5 ? 1 : 2;
                room.gameState.currentTurn = firstTurn;
                room.gameState.status = 'playing';
                room.gameState.turnNumber = 1;
                
                io.to(roomCode).emit('game_started', {
                    roomCode,
                    matchId: room.matchId,
                    players: room.players.map(p => ({
                        userId: p.userId,
                        username: p.username,
                        playerNumber: p.playerNumber
                    })),
                    firstTurn
                });
                console.log(`🎮 Game started in room ${roomCode}`);
            }

        } catch (error) {
            console.error('❌ join_room error:', error);
            socket.emit('error', { message: 'Failed to join room' });
        }
    });

    // ==========================================
    // HANDLER: Roll Dice (SERVER-SIDE)
    // ==========================================
    socket.on('roll_dice', async (data) => {
        try {
            const { roomCode, userId } = data;
            
            if (!roomCode || !userId) {
                socket.emit('error', { message: 'Missing room code or user ID' });
                return;
            }

            const room = rooms.get(roomCode);
            if (!room) {
                socket.emit('error', { message: 'Room not found' });
                return;
            }

            const player = room.getPlayer(userId);
            if (!player) {
                socket.emit('error', { message: 'Player not in room' });
                return;
            }

            const playerNumber = player.playerNumber;
            
            if (room.gameState.currentTurn !== playerNumber) {
                socket.emit('error', { message: 'Not your turn!' });
                return;
            }

            if (room.gameState.status !== 'playing') {
                socket.emit('error', { message: 'Game not in playing state' });
                return;
            }

            const lastRoll = room.diceRollHistory[room.diceRollHistory.length - 1];
            if (lastRoll && (Date.now() - lastRoll.timestamp) < 1000) {
                socket.emit('error', { message: 'Please wait before rolling again' });
                return;
            }

            // SERVER-SIDE DICE GENERATION
            const diceValue = Math.floor(Math.random() * 6) + 1;
            
            const consecutiveSixes = room.getConsecutiveSixes(userId);
            let extraTurn = false;
            let penalty = false;
            
            if (diceValue === 6) {
                const newCount = room.incrementConsecutiveSixes(userId);
                if (newCount >= 3) {
                    penalty = true;
                    room.resetConsecutiveSixes(userId);
                } else {
                    extraTurn = true;
                }
            } else {
                room.resetConsecutiveSixes(userId);
            }

            room.addDiceHistory(diceValue, userId);
            room.gameState.diceValue = diceValue;
            room.gameState.turnNumber++;

            const rollResult = {
                userId,
                playerNumber,
                diceValue,
                extraTurn: extraTurn && !penalty,
                penalty,
                turnNumber: room.gameState.turnNumber,
                timestamp: Date.now()
            };

            io.to(roomCode).emit('dice_rolled', rollResult);
            console.log(`🎲 Player ${playerNumber} rolled ${diceValue} in room ${roomCode}`);

            if (penalty) {
                setTimeout(() => {
                    const nextTurn = room.gameState.currentTurn === 1 ? 2 : 1;
                    room.gameState.currentTurn = nextTurn;
                    io.to(roomCode).emit('turn_changed', {
                        currentTurn: nextTurn,
                        turnNumber: room.gameState.turnNumber,
                        nextPlayer: room.players[nextTurn - 1]
                    });
                    console.log(`⏭️ Turn changed to Player ${nextTurn} (penalty)`);
                }, 1500);
            } else if (!extraTurn) {
                setTimeout(() => {
                    const nextTurn = room.gameState.currentTurn === 1 ? 2 : 1;
                    room.gameState.currentTurn = nextTurn;
                    io.to(roomCode).emit('turn_changed', {
                        currentTurn: nextTurn,
                        turnNumber: room.gameState.turnNumber,
                        nextPlayer: room.players[nextTurn - 1]
                    });
                    console.log(`⏭️ Turn changed to Player ${nextTurn}`);
                }, 1500);
            } else {
                io.to(roomCode).emit('extra_turn', {
                    playerNumber,
                    message: '🎉 Extra turn! Roll again.'
                });
                console.log(`🔄 Extra turn for Player ${playerNumber}`);
            }

        } catch (error) {
            console.error('❌ roll_dice error:', error);
            socket.emit('error', { message: 'Failed to roll dice' });
        }
    });

    // ==========================================
    // HANDLER: Move Token
    // ==========================================
    socket.on('move_token', async (data) => {
        try {
            const { roomCode, userId, tokenId, fromPosition, toPosition, isCapture } = data;
            
            if (!roomCode || !userId) {
                socket.emit('error', { message: 'Missing data' });
                return;
            }

            const room = rooms.get(roomCode);
            if (!room) {
                socket.emit('error', { message: 'Room not found' });
                return;
            }

            const player = room.getPlayer(userId);
            if (!player) {
                socket.emit('error', { message: 'Player not in room' });
                return;
            }

            const playerNumber = player.playerNumber;
            
            if (room.gameState.currentTurn !== playerNumber) {
                socket.emit('error', { message: 'Not your turn!' });
                return;
            }

            const moveData = {
                userId,
                playerNumber,
                tokenId,
                fromPosition,
                toPosition,
                isCapture: isCapture || false,
                timestamp: Date.now()
            };

            io.to(roomCode).emit('token_moved', moveData);
            console.log(`🎯 Player ${playerNumber} moved token ${tokenId} in room ${roomCode}`);

        } catch (error) {
            console.error('❌ move_token error:', error);
            socket.emit('error', { message: 'Failed to move token' });
        }
    });

    // ==========================================
    // HANDLER: Update Game State
    // ==========================================
    socket.on('update_game_state', async (data) => {
        try {
            const { roomCode, userId, gameState } = data;
            
            if (!roomCode || !userId) return;

            const room = rooms.get(roomCode);
            if (!room) return;

            const player = room.getPlayer(userId);
            if (!player) return;

            room.updateGameState(gameState);
            socket.to(roomCode).emit('game_state_updated', {
                userId,
                gameState: room.gameState,
                timestamp: Date.now()
            });

        } catch (error) {
            console.error('❌ update_game_state error:', error);
        }
    });

    // ==========================================
    // HANDLER: Game Over
    // ==========================================
    socket.on('game_over', async (data) => {
        try {
            const { roomCode, userId, winnerId } = data;
            
            if (!roomCode || !userId) return;

            const room = rooms.get(roomCode);
            if (!room) return;

            room.gameState.isGameOver = true;
            room.gameState.status = 'completed';
            room.gameState.winner = winnerId;

            const winner = room.getPlayer(winnerId);
            
            io.to(roomCode).emit('game_completed', {
                winnerId,
                winnerName: winner ? winner.username : 'Unknown',
                finalState: room.gameState,
                timestamp: Date.now()
            });

            console.log(`🏆 Game completed in room ${roomCode}`);

            setTimeout(() => {
                rooms.delete(roomCode);
                console.log(`🧹 Room ${roomCode} cleaned up`);
            }, 60000);

        } catch (error) {
            console.error('❌ game_over error:', error);
        }
    });

    // ==========================================
    // HANDLER: Disconnect
    // ==========================================
    socket.on('disconnect', async () => {
        console.log(`🔌 Client disconnected: ${socket.id}`);
        
        try {
            let userId = null;
            let roomCode = null;
            
            for (const [key, value] of userSessions) {
                if (value === socket.id) {
                    userId = key;
                    break;
                }
            }
            
            if (userId) {
                userSessions.delete(userId);
            }

            for (const [code, room] of rooms) {
                const player = room.players.find(p => p.socketId === socket.id);
                if (player) {
                    roomCode = code;
                    player.isActive = false;
                    io.to(code).emit('player_disconnected', {
                        userId: player.userId,
                        username: player.username,
                        playerNumber: player.playerNumber
                    });
                    console.log(`👋 Player ${player.username} disconnected from room ${code}`);
                    break;
                }
            }

            if (db && socket.id) {
                await db.execute(
                    'UPDATE websocket_sessions SET is_active = FALSE WHERE socket_id = ?',
                    [socket.id]
                );
            }

        } catch (error) {
            console.error('❌ disconnect error:', error);
        }
    });

    // ==========================================
    // HANDLER: Reconnect Check
    // ==========================================
    socket.on('reconnect_check', async (data) => {
        try {
            const { userId, roomCode } = data;
            
            if (!userId || !roomCode) {
                socket.emit('reconnect_status', { success: false, message: 'Missing data' });
                return;
            }

            const room = rooms.get(roomCode);
            if (!room) {
                socket.emit('reconnect_status', { success: false, message: 'Room not found' });
                return;
            }

            const player = room.getPlayer(userId);
            if (!player) {
                socket.emit('reconnect_status', { success: false, message: 'Player not in room' });
                return;
            }

            player.socketId = socket.id;
            player.isActive = true;
            socket.join(roomCode);
            
            socket.emit('reconnect_status', {
                success: true,
                room: room.toJSON(),
                playerNumber: player.playerNumber,
                gameState: room.gameState
            });

            socket.to(roomCode).emit('player_reconnected', {
                userId,
                username: player.username,
                playerNumber: player.playerNumber
            });

            console.log(`♻️ Player ${player.username} reconnected to room ${roomCode}`);

        } catch (error) {
            console.error('❌ reconnect_check error:', error);
            socket.emit('reconnect_status', { success: false, message: 'Reconnection failed' });
        }
    });
});

// ==============================================
// SERVER STATUS ENDPOINTS
// ==============================================
app.get('/api/rooms', (req, res) => {
    const roomList = [];
    for (const [code, room] of rooms) {
        roomList.push({
            roomCode: code,
            playerCount: room.players.length,
            maxPlayers: room.maxPlayers,
            status: room.gameState.status,
            createdAt: room.createdAt
        });
    }
    res.json({ success: true, rooms: roomList, count: roomList.length });
});

app.get('/api/room/:roomCode', (req, res) => {
    const { roomCode } = req.params;
    const room = rooms.get(roomCode);
    if (!room) {
        return res.status(404).json({ success: false, message: 'Room not found' });
    }
    res.json({ success: true, room: room.toJSON() });
});

app.get('/api/stats', (req, res) => {
    res.json({
        success: true,
        stats: {
            totalRooms: rooms.size,
            totalPlayers: Array.from(rooms.values()).reduce((sum, r) => sum + r.players.length, 0),
            activeSessions: userSessions.size,
            totalSessions: io.engine.clientsCount
        }
    });
});

// ==============================================
// START SERVER
// ==============================================
connectDatabase().then(() => {
    server.listen(PORT, () => {
        console.log(`🚀 WebSocket Server running on port ${PORT}`);
        console.log(`📡 WebSocket endpoint: ws://localhost:${PORT}`);
        console.log('✅ Ready for connections');
    });
});

// ==============================================
// GRACEFUL SHUTDOWN
// ==============================================
process.on('SIGINT', async () => {
    console.log('🛑 Shutting down gracefully...');
    for (const [code, room] of rooms) {
        io.to(code).emit('server_shutdown', { message: 'Server is shutting down' });
    }
    if (db) {
        // BUG FIX: pool uses .end() — same API so this is correct
        await db.end();
    }
    server.close(() => {
        console.log('✅ Server closed');
        process.exit(0);
    });
});

process.on('uncaughtException', (error) => {
    console.error('❌ Uncaught exception:', error);
});

process.on('unhandledRejection', (reason, promise) => {
    console.error('❌ Unhandled rejection at:', promise, 'reason:', reason);
});
