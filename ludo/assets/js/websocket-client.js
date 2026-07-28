/**
 * ======================================================
 * WEBSOCKET-CLIENT.JS - WebSocket Client (FIXED)
 * Ludo Tournament Platform - Socket.io Client
 * Version: 3.0.0 - PROMISE FIX + RECONNECT FIX
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
            onConnect: null, onDisconnect: null, onRoomJoined: null,
            onPlayerJoined: null, onPlayerDisconnected: null,
            onDiceRolled: null, onTokenMoved: null, onTurnChanged: null,
            onGameStarted: null, onGameCompleted: null, onError: null,
            onExtraTurn: null, onGameStateUpdated: null, onPlayerReconnected: null
        };
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

                // FIXED: Guard promise settlement
                let settled = false;
                const safeResolve = () => { if (!settled) { settled = true; resolve(); } };
                const safeReject = (err) => { if (!settled) { settled = true; reject(err); } };

                this.socket.on('connect', () => {
                    this.connected = true;
                    this.reconnectAttempts = 0;
                    console.log('✅ WebSocket connected');
                    this.processPendingEvents();
                    if (this.callbacks.onConnect) this.callbacks.onConnect();
                    safeResolve();
                });

                this.socket.on('disconnect', (reason) => {
                    this.connected = false;
                    console.log('🔌 Disconnected:', reason);
                    if (this.callbacks.onDisconnect) this.callbacks.onDisconnect(reason);
                });

                this.socket.on('connect_error', (error) => {
                    console.error('❌ Connection error:', error);
                    if (this.reconnectAttempts < this.maxReconnectAttempts) {
                        this.reconnectAttempts++;
                    } else {
                        safeReject(error);
                    }
                });

                this.setupEventListeners();
            } catch (error) {
                reject(error);
            }
        });
    }

    setupEventListeners() {
        const events = ['room_joined', 'player_joined', 'player_disconnected', 'player_reconnected',
                       'game_started', 'dice_rolled', 'token_moved', 'turn_changed', 'extra_turn',
                       'game_completed', 'game_state_updated', 'error', 'reconnect_status', 'server_shutdown'];
        
        events.forEach(evt => {
            this.socket.on(evt, (data) => {
                const cbKey = 'on' + evt.split('_').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join('');
                if (this.callbacks[cbKey]) this.callbacks[cbKey](data);
            });
        });
    }

    joinRoom(roomCode, userId, username, matchId) {
        this.roomCode = roomCode; this.userId = userId;
        this.username = username; this.matchId = matchId;
        if (this.connected) {
            this.socket.emit('join_room', { roomCode, userId, username, matchId });
        } else {
            this.pendingEvents.push({ type: 'join_room', data: { roomCode, userId, username, matchId } });
        }
    }

    rollDice() {
        if (this.connected) this.socket.emit('roll_dice', { roomCode: this.roomCode, userId: this.userId });
    }

    moveToken(tokenId, fromPosition, toPosition, isCapture = false) {
        if (this.connected) this.socket.emit('move_token', { roomCode: this.roomCode, userId: this.userId, tokenId, fromPosition, toPosition, isCapture });
    }

    updateGameState(gameState) {
        if (this.connected) this.socket.emit('update_game_state', { roomCode: this.roomCode, userId: this.userId, gameState });
    }

    processPendingEvents() {
        while (this.pendingEvents.length > 0) {
            const event = this.pendingEvents.shift();
            if (this.socket && this.connected) this.socket.emit(event.type, event.data);
        }
    }

    on(event, callback) { if (this.callbacks.hasOwnProperty(event)) this.callbacks[event] = callback; }
    isConnected() { return this.connected && this.socket && this.socket.connected; }
    disconnect() { if (this.socket) { this.socket.disconnect(); this.connected = false; } }
}

window.LudoWebSocketClient = LudoWebSocketClient;
console.log('📡 WebSocket Client v3.0 loaded');
