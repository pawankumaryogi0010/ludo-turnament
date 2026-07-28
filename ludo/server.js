/**
 * ======================================================
 * SERVER.JS - WebSocket Server for Ludo (FIXED)
 * Ludo Tournament Platform - Socket.io Server
 * Version: 3.0.0 - DB CREDENTIALS FIX + POOL FIX
 * ======================================================
 */

const express = require('express');
const http = require('http');
const socketIO = require('socket.io');
const cors = require('cors');
const mysql = require('mysql2/promise');

// FIXED: Use environment variables matching .env file
const PORT = process.env.PORT || 3000;
const DB_CONFIG = {
    host: process.env.DB_HOST || 'localhost',
    user: process.env.DB_USER || 'root',
    password: process.env.DB_PASS || '',
    database: process.env.DB_NAME || 'ludo_tournament',
    waitForConnections: true,
    connectionLimit: 10,
    queueLimit: 0
};

const app = express();
const server = http.createServer(app);
const io = socketIO(server, {
    cors: { origin: '*', methods: ['GET', 'POST'], credentials: true },
    pingTimeout: 60000,
    pingInterval: 25000
});

app.use(cors());
app.use(express.json());

// FIXED: Use pool instead of single connection
let db;

async function connectDatabase() {
    try {
        db = await mysql.createPool(DB_CONFIG);
        console.log('✅ Database pool created');
        
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

// Game rooms
const rooms = new Map();
const userSessions = new Map();

class GameRoom {
    constructor(roomCode, matchId) {
        this.roomCode = roomCode;
        this.matchId = matchId;
        this.players = [];
        this.gameState = {
            status: 'waiting', currentTurn: null, diceValue: 0,
            diceHistory: [], turnNumber: 0, board: {}, winner: null, isGameOver: false
        };
        this.maxPlayers = 2;
        this.diceRollHistory = [];
        this.consecutiveSixes = {};
        this.createdAt = Date.now();
    }

    addPlayer(userId, username) {
        if (this.players.length >= this.maxPlayers) return { success: false, message: 'Room full' };
        const player = { userId, username, playerNumber: this.players.length + 1, socketId: null, isReady: false, isActive: true, joinedAt: Date.now() };
        this.players.push(player);
        if (this.players.length === 2) this.gameState.status = 'ready';
        return { success: true, player };
    }

    removePlayer(userId) {
        const idx = this.players.findIndex(p => p.userId === userId);
        if (idx === -1) return null;
        const player = this.players[idx];
        this.players.splice(idx, 1);
        if (this.players.length < 2) this.gameState.status = 'waiting';
        return player;
    }

    getPlayer(userId) { return this.players.find(p => p.userId === userId); }
    isFull() { return this.players.length >= this.maxPlayers; }
    setPlayerSocket(userId, socketId) { const p = this.getPlayer(userId); if (p) { p.socketId = socketId; return true; } return false; }
    
    addDiceHistory(value, userId) {
        this.diceRollHistory.push({ value, userId, timestamp: Date.now() });
        if (this.diceRollHistory.length > 100) this.diceRollHistory.shift();
    }
    
    getConsecutiveSixes(userId) { return this.consecutiveSixes[userId] || 0; }
    incrementConsecutiveSixes(userId) { this.consecutiveSixes[userId] = (this.consecutiveSixes[userId] || 0) + 1; return this.consecutiveSixes[userId]; }
    resetConsecutiveSixes(userId) { this.consecutiveSixes[userId] = 0; }
    getOpponent(userId) { return this.players.find(p => p.userId !== userId); }
}

// Socket.io handlers
io.on('connection', (socket) => {
    console.log('🔌 Connected:', socket.id);
    let currentUserId = null, currentRoomCode = null;

    socket.on('join_room', async (data) => {
        try {
            const { roomCode, userId, username, matchId } = data;
            if (!roomCode || !userId || !username) { socket.emit('error', { message: 'Missing fields' }); return; }
            
            currentUserId = userId;
            currentRoomCode = roomCode;
            
            let room = rooms.get(roomCode);
            if (!room) { room = new GameRoom(roomCode, matchId || 0); rooms.set(roomCode, room); }
            
            const existing = room.getPlayer(userId);
            if (existing) {
                room.setPlayerSocket(userId, socket.id);
                socket.join(roomCode);
                socket.emit('room_joined', { success: true, room: room, playerNumber: existing.playerNumber });
                socket.to(roomCode).emit('player_reconnected', { userId, username, playerNumber: existing.playerNumber });
                return;
            }
            
            const result = room.addPlayer(userId, username);
            if (!result.success) { socket.emit('error', { message: result.message }); return; }
            
            room.setPlayerSocket(userId, socket.id);
            socket.join(roomCode);
            userSessions.set(userId, socket.id);
            
            if (db) {
                await db.execute('INSERT INTO websocket_sessions (socket_id, user_id, match_id, room_code) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE last_activity = CURRENT_TIMESTAMP, is_active = TRUE', [socket.id, userId, room.matchId || 0, roomCode]);
            }
            
            socket.emit('room_joined', { success: true, room: room, playerNumber: result.player.playerNumber });
            socket.to(roomCode).emit('player_joined', { userId, username, playerNumber: result.player.playerNumber, playerCount: room.players.length });
            
            if (room.isFull()) {
                const firstTurn = Math.random() < 0.5 ? 1 : 2;
                room.gameState.currentTurn = firstTurn;
                room.gameState.status = 'playing';
                room.gameState.turnNumber = 1;
                io.to(roomCode).emit('game_started', { roomCode, matchId: room.matchId, players: room.players.map(p => ({ userId: p.userId, username: p.username, playerNumber: p.playerNumber })), firstTurn });
            }
        } catch (e) { console.error('join_room error:', e); }
    });

    socket.on('roll_dice', (data) => {
        try {
            const { roomCode, userId } = data;
            const room = rooms.get(roomCode);
            if (!room || !room.getPlayer(userId)) { socket.emit('error', { message: 'Invalid' }); return; }
            
            const player = room.getPlayer(userId);
            if (room.gameState.currentTurn !== player.playerNumber) { socket.emit('error', { message: 'Not your turn' }); return; }
            
            const diceValue = Math.floor(Math.random() * 6) + 1;
            let extraTurn = false, penalty = false;
            
            if (diceValue === 6) {
                const count = room.incrementConsecutiveSixes(userId);
                if (count >= 3) { penalty = true; room.resetConsecutiveSixes(userId); }
                else extraTurn = true;
            } else { room.resetConsecutiveSixes(userId); }
            
            room.addDiceHistory(diceValue, userId);
            room.gameState.diceValue = diceValue;
            room.gameState.turnNumber++;
            
            io.to(roomCode).emit('dice_rolled', { userId, playerNumber: player.playerNumber, diceValue, extraTurn: extraTurn && !penalty, penalty, turnNumber: room.gameState.turnNumber });
            
            if (!extraTurn || penalty) {
                setTimeout(() => {
                    room.gameState.currentTurn = room.gameState.currentTurn === 1 ? 2 : 1;
                    io.to(roomCode).emit('turn_changed', { currentTurn: room.gameState.currentTurn, turnNumber: room.gameState.turnNumber });
                }, 1500);
            }
        } catch (e) { console.error('roll_dice error:', e); }
    });

    socket.on('move_token', (data) => {
        try {
            const { roomCode, userId, tokenId, fromPosition, toPosition, isCapture } = data;
            const room = rooms.get(roomCode);
            if (!room || !room.getPlayer(userId)) return;
            const player = room.getPlayer(userId);
            io.to(roomCode).emit('token_moved', { userId, playerNumber: player.playerNumber, tokenId, fromPosition, toPosition, isCapture: isCapture || false });
        } catch (e) { console.error('move_token error:', e); }
    });

    socket.on('game_over', (data) => {
        const { roomCode, winnerId } = data;
        const room = rooms.get(roomCode);
        if (!room) return;
        room.gameState.isGameOver = true;
        room.gameState.status = 'completed';
        room.gameState.winner = winnerId;
        const winner = room.getPlayer(winnerId);
        io.to(roomCode).emit('game_completed', { winnerId, winnerName: winner?.username || 'Unknown', finalState: room.gameState });
        setTimeout(() => rooms.delete(roomCode), 60000);
    });

    socket.on('disconnect', async () => {
        for (const [uid, sid] of userSessions) { if (sid === socket.id) { currentUserId = uid; userSessions.delete(uid); break; } }
        for (const [code, room] of rooms) {
            const player = room.players.find(p => p.socketId === socket.id);
            if (player) {
                player.isActive = false;
                io.to(code).emit('player_disconnected', { userId: player.userId, username: player.username, playerNumber: player.playerNumber });
                break;
            }
        }
        if (db && socket.id) { await db.execute('UPDATE websocket_sessions SET is_active = FALSE WHERE socket_id = ?', [socket.id]); }
    });
});

// Health endpoints
app.get('/api/rooms', (req, res) => {
    const list = [];
    for (const [code, room] of rooms) list.push({ roomCode: code, playerCount: room.players.length, maxPlayers: room.maxPlayers, status: room.gameState.status });
    res.json({ success: true, rooms: list, count: list.length });
});

app.get('/api/stats', (req, res) => {
    let totalPlayers = 0;
    for (const room of rooms.values()) totalPlayers += room.players.length;
    res.json({ success: true, stats: { totalRooms: rooms.size, totalPlayers, activeSessions: userSessions.size } });
});

// Start server
connectDatabase().then(() => {
    server.listen(PORT, () => {
        console.log(`🚀 WebSocket Server running on port ${PORT}`);
        console.log('✅ Ready for connections');
    });
});

// Graceful shutdown
process.on('SIGINT', async () => {
    console.log('🛑 Shutting down...');
    for (const [code, room] of rooms) io.to(code).emit('server_shutdown', { message: 'Server shutting down' });
    if (db) await db.end();
    server.close(() => { console.log('✅ Server closed'); process.exit(0); });
});
