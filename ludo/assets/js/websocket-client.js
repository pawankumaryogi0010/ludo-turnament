/**
 * ======================================================
 * WEBSOCKET-CLIENT.JS - WebSocket Client Integration
 * Ludo Tournament Platform - Socket.io Client
 * Version: 2.0.0 - COMPLETE
 * ======================================================
 */

class LudoWebSocketClient {
    constructor(options = {}) {
        this.url = options.url || 'ws://localhost:3000';
        this.socket = null;
        this.connected = false;
        this.roomCode = options.roomCode || null;
        this.userId = options.userId || null;
        this.username = options.username || 'Player';
        this.matchId = options.matchId || null;
        this.reconnectAttempts = 0;
        this.maxReconnectAttempts = 5;
        this.reconnectDelay = 1000;
        this.callbacks = {
            onConnect: null,
            onDisconnect: null,
            onRoomJoined: null,
            onPlayerJoined: null,
            onPlayerDisconnected: null,
            onDiceRolled: null,
            onTokenMoved: null,
            onTurnChanged: null,
            onGameStarted: null,
            onGameCompleted: null,
            onError: null,
            onExtraTurn: null,
            onGameStateUpdated: null,
            onPlayerReconnected: null
        };
        this.isReconnecting = false;
        this.pendingEvents = [];
    }

    connect() {
        return new Promise((resolve, reject) => {
            try {
                this.socket = io(this.url, {
                    transports: ['websocket', 'polling'],
                    reconnection: true,
                    reconnectionAttempts: this.maxReconnectAttempts,
                    reconnectionDelay: this.reconnectDelay,
                    timeout: 10000
                });

                this.socket.on('connect', () => {
                    this.connected = true;
                    this.reconnectAttempts = 0;
                    this.isReconnecting = false;
                    console.log('✅ WebSocket connected');
                    this.processPendingEvents();
                    if (this.callbacks.onConnect) {
                        this.callbacks.onConnect();
                    }
                    resolve();
                });

                this.socket.on('disconnect', (reason) => {
                    this.connected = false;
                    console.log('🔌 WebSocket disconnected:', reason);
                    if (this.callbacks.onDisconnect) {
                        this.callbacks.onDisconnect(reason);
                    }
                });

                // BUG FIX: connect_error fires on every failed attempt, so reject()
                // was called multiple times once maxReconnectAttempts was reached,
                // which violates the Promise contract. Guard with a settled flag.
                let settled = false;
                const safeReject = (err) => { if (!settled) { settled = true; reject(err); } };
                // Also mark settled on connect so we stop trying to reject after success.
                this.socket.once('connect', () => { settled = true; });

                this.socket.on('connect_error', (error) => {
                    console.error('❌ WebSocket connection error:', error);
                    if (this.reconnectAttempts < this.maxReconnectAttempts) {
                        this.reconnectAttempts++;
                        setTimeout(() => {
                            if (!this.connected) {
                                this.connect();
                            }
                        }, this.reconnectDelay * this.reconnectAttempts);
                    } else {
                        safeReject(error);
                    }
                });

                this.setupEventListeners();

            } catch (error) {
                console.error('❌ WebSocket init error:', error);
                reject(error);
            }
        });
    }

    setupEventListeners() {
        this.socket.on('room_joined', (data) => {
            if (this.callbacks.onRoomJoined) {
                this.callbacks.onRoomJoined(data);
            }
        });

        this.socket.on('player_joined', (data) => {
            if (this.callbacks.onPlayerJoined) {
                this.callbacks.onPlayerJoined(data);
            }
        });

        this.socket.on('player_disconnected', (data) => {
            if (this.callbacks.onPlayerDisconnected) {
                this.callbacks.onPlayerDisconnected(data);
            }
        });

        this.socket.on('player_reconnected', (data) => {
            if (this.callbacks.onPlayerReconnected) {
                this.callbacks.onPlayerReconnected(data);
            }
        });

        this.socket.on('game_started', (data) => {
            if (this.callbacks.onGameStarted) {
                this.callbacks.onGameStarted(data);
            }
        });

        this.socket.on('dice_rolled', (data) => {
            if (this.callbacks.onDiceRolled) {
                this.callbacks.onDiceRolled(data);
            }
        });

        this.socket.on('token_moved', (data) => {
            if (this.callbacks.onTokenMoved) {
                this.callbacks.onTokenMoved(data);
            }
        });

        this.socket.on('turn_changed', (data) => {
            if (this.callbacks.onTurnChanged) {
                this.callbacks.onTurnChanged(data);
            }
        });

        this.socket.on('extra_turn', (data) => {
            if (this.callbacks.onExtraTurn) {
                this.callbacks.onExtraTurn(data);
            }
        });

        this.socket.on('game_completed', (data) => {
            if (this.callbacks.onGameCompleted) {
                this.callbacks.onGameCompleted(data);
            }
        });

        this.socket.on('game_state_updated', (data) => {
            if (this.callbacks.onGameStateUpdated) {
                this.callbacks.onGameStateUpdated(data);
            }
        });

        this.socket.on('error', (data) => {
            if (this.callbacks.onError) {
                this.callbacks.onError(data);
            }
        });

        this.socket.on('reconnect_status', (data) => {
            if (data.success && this.callbacks.onRoomJoined) {
                this.callbacks.onRoomJoined(data);
            }
        });

        this.socket.on('server_shutdown', (data) => {
            console.warn('⚠️ Server shutting down:', data.message);
            if (this.callbacks.onError) {
                this.callbacks.onError({ message: 'Server is shutting down' });
            }
        });
    }

    joinRoom(roomCode, userId, username, matchId) {
        this.roomCode = roomCode;
        this.userId = userId;
        this.username = username;
        this.matchId = matchId;
        
        if (this.connected) {
            this.socket.emit('join_room', {
                roomCode,
                userId,
                username,
                matchId
            });
        } else {
            this.pendingEvents.push({
                type: 'join_room',
                data: { roomCode, userId, username, matchId }
            });
        }
    }

    rollDice() {
        if (this.connected && this.roomCode && this.userId) {
            this.socket.emit('roll_dice', {
                roomCode: this.roomCode,
                userId: this.userId
            });
        } else {
            console.warn('⚠️ Cannot roll dice - not connected');
        }
    }

    moveToken(tokenId, fromPosition, toPosition, isCapture = false) {
        if (this.connected && this.roomCode && this.userId) {
            this.socket.emit('move_token', {
                roomCode: this.roomCode,
                userId: this.userId,
                tokenId,
                fromPosition,
                toPosition,
                isCapture
            });
        } else {
            console.warn('⚠️ Cannot move token - not connected');
        }
    }

    updateGameState(gameState) {
        if (this.connected && this.roomCode && this.userId) {
            this.socket.emit('update_game_state', {
                roomCode: this.roomCode,
                userId: this.userId,
                gameState
            });
        }
    }

    gameOver(winnerId) {
        if (this.connected && this.roomCode && this.userId) {
            this.socket.emit('game_over', {
                roomCode: this.roomCode,
                userId: this.userId,
                winnerId
            });
        }
    }

    reconnectCheck() {
        if (this.connected && this.roomCode && this.userId) {
            this.socket.emit('reconnect_check', {
                userId: this.userId,
                roomCode: this.roomCode
            });
        }
    }

    processPendingEvents() {
        while (this.pendingEvents.length > 0) {
            const event = this.pendingEvents.shift();
            if (this.socket && this.connected) {
                this.socket.emit(event.type, event.data);
            }
        }
    }

    on(event, callback) {
        if (this.callbacks.hasOwnProperty(event)) {
            this.callbacks[event] = callback;
        }
    }

    isConnected() {
        return this.connected && this.socket && this.socket.connected;
    }

    disconnect() {
        if (this.socket) {
            this.socket.disconnect();
            this.connected = false;
        }
    }

    getRoomCode() {
        return this.roomCode;
    }

    getUserId() {
        return this.userId;
    }
}

window.LudoWebSocketClient = LudoWebSocketClient;
