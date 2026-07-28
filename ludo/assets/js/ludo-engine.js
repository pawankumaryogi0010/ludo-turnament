/**
 * ======================================================
 * LUDO-ENGINE.JS - Complete Ludo Game Engine
 * Ludo Tournament Platform - Canvas Rendering + Game Logic
 * Version: 2.0.0 - COMPLETE
 * ======================================================
 */

// ==============================================
// TRACK POSITIONS - 52 Cells on Board
// ==============================================
const TRACK_POSITIONS = (function() {
    const positions = [];
    const cellSize = 38;
    const offset = 20;
    
    // Bottom row (Player 1 home stretch) - 13 cells (0-12)
    for (let i = 0; i < 13; i++) {
        positions.push({ x: offset + i * cellSize, y: offset + 12 * cellSize });
    }
    // Right column (Player 2 home stretch) - 13 cells (13-25)
    for (let i = 1; i < 13; i++) {
        positions.push({ x: offset + 12 * cellSize, y: offset + (12 - i) * cellSize });
    }
    // Top row (Player 3 home stretch) - 13 cells (26-38)
    for (let i = 1; i < 13; i++) {
        positions.push({ x: offset + (12 - i) * cellSize, y: offset });
    }
    // Left column (Player 4 home stretch) - 13 cells (39-51)
    for (let i = 1; i < 13; i++) {
        positions.push({ x: offset, y: offset + i * cellSize });
    }
    
    return positions;
})();

// ==============================================
// LUDO ENGINE CLASS
// ==============================================
class LudoEngine {
    constructor(canvas, options = {}) {
        this.canvas = canvas;
        this.ctx = canvas.getContext('2d');
        this.cellSize = options.cellSize || 38;
        this.padding = 20;
        this.player1Name = options.player1Name || 'Player 1';
        this.player2Name = options.player2Name || 'Player 2';
        this.player2Human = options.player2Human !== undefined ? options.player2Human : true;
        
        this.gameState = {
            status: 'waiting', // waiting, ready, playing, completed
            currentTurn: 1,
            diceValue: 0,
            hasRolled: false,
            canRoll: true,
            turnNumber: 0,
            isGameOver: false,
            winner: null,
            winningAmount: 0
        };
        
        this.players = {
            1: {
                id: 1,
                name: this.player1Name,
                color: '#ef4444',
                lightColor: '#fca5a5',
                darkColor: '#b91c1c',
                homeCount: 0,
                tokens: [
                    { id: 1, trackIndex: -1, isHome: true, isActive: false, x: 0, y: 0 },
                    { id: 2, trackIndex: -1, isHome: true, isActive: false, x: 0, y: 0 },
                    { id: 3, trackIndex: -1, isHome: true, isActive: false, x: 0, y: 0 },
                    { id: 4, trackIndex: -1, isHome: true, isActive: false, x: 0, y: 0 }
                ]
            },
            2: {
                id: 2,
                name: this.player2Name,
                color: '#3b82f6',
                lightColor: '#93c5fd',
                darkColor: '#1d4ed8',
                homeCount: 0,
                tokens: [
                    { id: 5, trackIndex: -1, isHome: true, isActive: false, x: 0, y: 0 },
                    { id: 6, trackIndex: -1, isHome: true, isActive: false, x: 0, y: 0 },
                    { id: 7, trackIndex: -1, isHome: true, isActive: false, x: 0, y: 0 },
                    { id: 8, trackIndex: -1, isHome: true, isActive: false, x: 0, y: 0 }
                ]
            }
        };
        
        this.callbacks = {
            onTurnChange: null,
            onDiceRoll: null,
            onTokenMove: null,
            onWin: null,
            onGameStateUpdate: null
        };
        
        this.resize();
        this.setupEventListeners();
        this.render();
    }

    resize() {
        const container = this.canvas.parentElement;
        const rect = container.getBoundingClientRect();
        const size = Math.min(rect.width - 16, rect.height - 16, 420);
        
        this.canvas.width = size;
        this.canvas.height = size;
        this.canvas.style.width = size + 'px';
        this.canvas.style.height = size + 'px';
        
        this.boardSize = size;
        this.cellSize = (size - 2 * this.padding) / 13;
        this.padding = this.cellSize * 0.5;
        
        this.updateTokenPositions();
    }

    setupEventListeners() {
        let resizeTimeout;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => {
                this.resize();
                this.render();
            }, 200);
        });
        
        this.canvas.addEventListener('click', (e) => {
            const rect = this.canvas.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            this.handleCanvasClick(x, y);
        });
    }

    handleCanvasClick(x, y) {
        if (this.gameState.isGameOver) return;
        if (this.gameState.currentTurn !== 1) return;

        // BUG FIX: Previous code had `if (!canRoll || hasRolled) return` which
        // blocked token moves — after rolling (hasRolled=true), clicking a token
        // would exit immediately before reaching the move logic.
        // Split into two phases: (1) after roll → try to move a token,
        //                        (2) before roll → roll the dice.
        if (this.gameState.hasRolled) {
            // Phase 1: dice already rolled — click on a token to move it
            const player = this.players[1];
            for (const token of player.tokens) {
                if (!token.isActive && !token.isHome) continue;
                const dx = x - token.x;
                const dy = y - token.y;
                if (Math.sqrt(dx * dx + dy * dy) < 15) {
                    this.moveToken(1, token.id);
                    return;
                }
            }
            // No token clicked — do nothing (wait for user to pick a token)
            return;
        }

        if (!this.gameState.canRoll) return;

        // Phase 2: dice not rolled yet — check if a home token was clicked
        // (e.g. player wants to bring a token out). Otherwise roll.
        const player = this.players[1];
        for (const token of player.tokens) {
            const dx = x - token.x;
            const dy = y - token.y;
            if (Math.sqrt(dx * dx + dy * dy) < 15) {
                // Clicking a home token still rolls first; moveToken will handle it
                this.rollDice();
                return;
            }
        }

        // Clicked on empty board — roll dice
        this.rollDice();
    }

    rollDice() {
        if (this.gameState.isGameOver) return;
        if (this.gameState.currentTurn !== 1) return;
        if (!this.gameState.canRoll || this.gameState.hasRolled) return;
        
        const value = Math.floor(Math.random() * 6) + 1;
        this.gameState.diceValue = value;
        this.gameState.hasRolled = true;
        this.gameState.canRoll = false;
        this.gameState.turnNumber++;
        
        // Check for six
        const extraTurn = (value === 6);
        
        if (this.callbacks.onDiceRoll) {
            this.callbacks.onDiceRoll(value, extraTurn);
        }
        
        this.render();
        
        if (extraTurn) {
            setTimeout(() => {
                this.gameState.canRoll = true;
                this.gameState.hasRolled = false;
                this.render();
                if (this.callbacks.onDiceRoll) {
                    this.callbacks.onDiceRoll(value, true);
                }
            }, 1500);
        } else {
            setTimeout(() => {
                this.endTurn();
            }, 1200);
        }
    }

    moveToken(playerId, tokenId) {
        if (this.gameState.isGameOver) return;
        if (this.gameState.currentTurn !== playerId) return;
        if (!this.gameState.hasRolled) return;
        
        const player = this.players[playerId];
        const token = player.tokens.find(t => t.id === tokenId);
        if (!token) return;
        
        const diceValue = this.gameState.diceValue;
        
        // If token is at home and dice is 6, bring it out
        if (token.isHome && diceValue === 6) {
            token.isHome = false;
            token.isActive = true;
            token.trackIndex = 0;
            this.updateTokenPosition(playerId, token);
            this.gameState.canRoll = true;
            this.gameState.hasRolled = false;
            this.render();
            if (this.callbacks.onTokenMove) {
                this.callbacks.onTokenMove(playerId, tokenId, -1, 0);
            }
            return;
        }
        
        // If token is active, move forward
        if (token.isActive) {
            const newIndex = token.trackIndex + diceValue;
            if (newIndex > 51) {
                // Token reached home
                token.isHome = true;
                token.isActive = false;
                token.trackIndex = -1;
                player.homeCount++;
                
                if (player.homeCount === 4) {
                    this.gameState.isGameOver = true;
                    this.gameState.winner = playerId;
                    this.gameState.status = 'completed';
                    if (this.callbacks.onWin) {
                        this.callbacks.onWin(playerId);
                    }
                }
            } else {
                token.trackIndex = newIndex;
                this.updateTokenPosition(playerId, token);
            }
            
            this.render();
            if (this.callbacks.onTokenMove) {
                this.callbacks.onTokenMove(playerId, tokenId, token.trackIndex - diceValue, token.trackIndex);
            }
            
            this.gameState.canRoll = true;
            this.gameState.hasRolled = false;
        }
    }

    updateTokenPosition(playerId, token) {
        if (token.isHome) {
            token.x = 0;
            token.y = 0;
            return;
        }
        
        let trackIndex = token.trackIndex;
        if (trackIndex < 0 || trackIndex >= TRACK_POSITIONS.length) {
            trackIndex = 0;
        }
        
        const pos = TRACK_POSITIONS[trackIndex];
        if (pos) {
            token.x = pos.x;
            token.y = pos.y;
        }
    }

    updateTokenPositions() {
        for (const playerId in this.players) {
            const player = this.players[playerId];
            for (const token of player.tokens) {
                this.updateTokenPosition(parseInt(playerId), token);
            }
        }
    }

    endTurn() {
        const nextTurn = this.gameState.currentTurn === 1 ? 2 : 1;
        this.gameState.currentTurn = nextTurn;
        this.gameState.canRoll = true;
        this.gameState.hasRolled = false;
        
        if (this.callbacks.onTurnChange) {
            this.callbacks.onTurnChange(nextTurn);
        }
        
        this.render();
    }

    // ==============================================
    // RENDER
    // ==============================================
    render() {
        const ctx = this.ctx;
        const size = this.boardSize;
        const p = this.padding;
        const cs = this.cellSize;
        
        ctx.clearRect(0, 0, size, size);
        
        // Background
        const gradient = ctx.createRadialGradient(size/2, size/2, 0, size/2, size/2, size/2);
        gradient.addColorStop(0, '#1a1a2e');
        gradient.addColorStop(1, '#0a0e1a');
        ctx.fillStyle = gradient;
        ctx.fillRect(0, 0, size, size);
        
        // Board border
        ctx.strokeStyle = 'rgba(251,191,36,0.2)';
        ctx.lineWidth = 2;
        ctx.strokeRect(p, p, size - 2*p, size - 2*p);
        
        // Grid
        for (let i = 0; i < 15; i++) {
            const pos = p + i * cs;
            ctx.strokeStyle = 'rgba(255,255,255,0.03)';
            ctx.lineWidth = 1;
            ctx.beginPath();
            ctx.moveTo(pos, p);
            ctx.lineTo(pos, size - p);
            ctx.stroke();
            ctx.beginPath();
            ctx.moveTo(p, pos);
            ctx.lineTo(size - p, pos);
            ctx.stroke();
        }
        
        // Home bases
        this.drawHomeBase(ctx, p, p, cs, '#ef4444', 'P1');
        this.drawHomeBase(ctx, size - p - 6*cs, p, cs, '#3b82f6', 'P2');
        this.drawHomeBase(ctx, p, size - p - 6*cs, cs, '#10b981', 'P3');
        this.drawHomeBase(ctx, size - p - 6*cs, size - p - 6*cs, cs, '#f59e0b', 'P4');
        
        // Center
        ctx.fillStyle = 'rgba(251,191,36,0.05)';
        ctx.fillRect(p + 6*cs, p + 6*cs, 2*cs, 2*cs);
        ctx.strokeStyle = 'rgba(251,191,36,0.1)';
        ctx.lineWidth = 1;
        ctx.strokeRect(p + 6*cs, p + 6*cs, 2*cs, 2*cs);
        
        // Draw tokens
        this.drawTokens(ctx);
        
        // Dice
        if (this.gameState.diceValue > 0) {
            this.drawDice(ctx, this.gameState.diceValue);
        }
        
        // Turn indicator
        this.drawTurnIndicator(ctx);
        
        // Winner overlay
        if (this.gameState.isGameOver) {
            this.drawWinnerOverlay(ctx);
        }
    }

    drawHomeBase(ctx, x, y, cs, color, label) {
        const size = 6 * cs;
        ctx.fillStyle = color + '15';
        ctx.fillRect(x, y, size, size);
        ctx.strokeStyle = color + '40';
        ctx.lineWidth = 1;
        ctx.strokeRect(x, y, size, size);
        
        // Label
        ctx.fillStyle = color;
        ctx.font = `${cs * 0.6}px Inter, sans-serif`;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(label, x + size/2, y + size/2);
    }

    drawTokens(ctx) {
        for (const playerId in this.players) {
            const player = this.players[playerId];
            const color = player.color;
            
            for (const token of player.tokens) {
                if (token.isHome) continue;
                if (!token.isActive) continue;
                
                const x = token.x;
                const y = token.y;
                
                // Glow
                const gradient = ctx.createRadialGradient(x, y, 0, x, y, 16);
                gradient.addColorStop(0, color + '40');
                gradient.addColorStop(1, 'transparent');
                ctx.fillStyle = gradient;
                ctx.beginPath();
                ctx.arc(x, y, 16, 0, Math.PI * 2);
                ctx.fill();
                
                // Token body
                const gradient2 = ctx.createRadialGradient(x-3, y-3, 2, x, y, 10);
                gradient2.addColorStop(0, '#ffffff');
                gradient2.addColorStop(0.2, color);
                gradient2.addColorStop(1, color);
                ctx.fillStyle = gradient2;
                ctx.shadowColor = color + '60';
                ctx.shadowBlur = 10;
                ctx.beginPath();
                ctx.arc(x, y, 10, 0, Math.PI * 2);
                ctx.fill();
                ctx.shadowBlur = 0;
                
                // Border
                ctx.strokeStyle = '#ffffff30';
                ctx.lineWidth = 1;
                ctx.beginPath();
                ctx.arc(x, y, 10, 0, Math.PI * 2);
                ctx.stroke();
                
                // Highlight
                ctx.fillStyle = 'rgba(255,255,255,0.3)';
                ctx.beginPath();
                ctx.arc(x-3, y-4, 4, 0, Math.PI * 2);
                ctx.fill();
                
                // Token number
                ctx.fillStyle = '#ffffff';
                ctx.font = '8px Inter, sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(token.id, x, y + 1);
            }
        }
    }

    drawDice(ctx, value) {
        const size = this.boardSize;
        const cx = size / 2;
        const cy = size / 2 - 30;
        const diceSize = 36;
        
        // Dice background
        const gradient = ctx.createRadialGradient(cx-4, cy-4, 2, cx, cy, diceSize/2);
        gradient.addColorStop(0, '#ffffff');
        gradient.addColorStop(1, '#e2e8f0');
        ctx.fillStyle = gradient;
        ctx.shadowColor = 'rgba(251,191,36,0.3)';
        ctx.shadowBlur = 20;
        ctx.beginPath();
        ctx.roundRect(cx - diceSize/2, cy - diceSize/2, diceSize, diceSize, 6);
        ctx.fill();
        ctx.shadowBlur = 0;
        
        // Border
        ctx.strokeStyle = '#cbd5e1';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.roundRect(cx - diceSize/2, cy - diceSize/2, diceSize, diceSize, 6);
        ctx.stroke();
        
        // Dots
        const dotPositions = {
            1: [[0, 0]],
            2: [[-1, -1], [1, 1]],
            3: [[-1, -1], [0, 0], [1, 1]],
            4: [[-1, -1], [1, -1], [-1, 1], [1, 1]],
            5: [[-1, -1], [1, -1], [0, 0], [-1, 1], [1, 1]],
            6: [[-1, -1], [1, -1], [-1, 0], [1, 0], [-1, 1], [1, 1]]
        };
        
        const dots = dotPositions[value] || [];
        const dotSize = 5;
        const spacing = 10;
        
        ctx.fillStyle = '#1e293b';
        for (const [dx, dy] of dots) {
            const dotX = cx + dx * spacing;
            const dotY = cy + dy * spacing;
            ctx.beginPath();
            ctx.arc(dotX, dotY, dotSize, 0, Math.PI * 2);
            ctx.fill();
        }
    }

    drawTurnIndicator(ctx) {
        const size = this.boardSize;
        const currentTurn = this.gameState.currentTurn;
        const player = this.players[currentTurn];
        
        ctx.fillStyle = player.color + '30';
        ctx.fillRect(0, size - 30, size, 30);
        
        ctx.fillStyle = player.color;
        ctx.font = '12px Inter, sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        const turnText = `🎯 ${player.name}'s Turn`;
        ctx.fillText(turnText, size/2, size - 15);
    }

    drawWinnerOverlay(ctx) {
        const size = this.boardSize;
        const player = this.players[this.gameState.winner];
        
        ctx.fillStyle = 'rgba(0,0,0,0.6)';
        ctx.fillRect(0, 0, size, size);
        
        ctx.fillStyle = '#fbbf24';
        ctx.font = 'bold 32px Inter, sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.shadowColor = '#fbbf2440';
        ctx.shadowBlur = 30;
        ctx.fillText('🏆', size/2, size/2 - 30);
        ctx.shadowBlur = 0;
        
        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 24px Inter, sans-serif';
        ctx.fillText(`${player.name} Wins!`, size/2, size/2 + 30);
        
        ctx.fillStyle = '#94a3b8';
        ctx.font = '16px Inter, sans-serif';
        ctx.fillText('🎉 Congratulations!', size/2, size/2 + 65);
    }

    // ==============================================
    // STATE MANAGEMENT
    // ==============================================
    getState() {
        return {
            status: this.gameState.status,
            currentTurn: this.gameState.currentTurn,
            diceValue: this.gameState.diceValue,
            turnNumber: this.gameState.turnNumber,
            isGameOver: this.gameState.isGameOver,
            winner: this.gameState.winner,
            player1: {
                name: this.players[1].name,
                homeCount: this.players[1].homeCount,
                tokens: this.players[1].tokens.map(t => ({
                    id: t.id,
                    trackIndex: t.trackIndex,
                    isHome: t.isHome,
                    isActive: t.isActive
                }))
            },
            player2: {
                name: this.players[2].name,
                homeCount: this.players[2].homeCount,
                tokens: this.players[2].tokens.map(t => ({
                    id: t.id,
                    trackIndex: t.trackIndex,
                    isHome: t.isHome,
                    isActive: t.isActive
                }))
            }
        };
    }

    setState(state) {
        // BUG FIX: `if (state.diceValue)` skips value 0 because 0 is falsy.
        // Use `!== undefined` so every explicitly provided field is applied.
        if (state.status    !== undefined) this.gameState.status      = state.status;
        if (state.currentTurn !== undefined) this.gameState.currentTurn = state.currentTurn;
        if (state.diceValue  !== undefined) this.gameState.diceValue   = state.diceValue;
        if (state.turnNumber !== undefined) this.gameState.turnNumber  = state.turnNumber;
        if (state.isGameOver !== undefined) this.gameState.isGameOver  = state.isGameOver;
        if (state.winner     !== undefined) this.gameState.winner      = state.winner;
        
        if (state.player1) {
            this.players[1].homeCount = state.player1.homeCount || 0;
            state.player1.tokens.forEach((t, i) => {
                if (this.players[1].tokens[i]) {
                    this.players[1].tokens[i].trackIndex = t.trackIndex;
                    this.players[1].tokens[i].isHome = t.isHome;
                    this.players[1].tokens[i].isActive = t.isActive;
                }
            });
        }
        
        if (state.player2) {
            this.players[2].homeCount = state.player2.homeCount || 0;
            state.player2.tokens.forEach((t, i) => {
                if (this.players[2].tokens[i]) {
                    this.players[2].tokens[i].trackIndex = t.trackIndex;
                    this.players[2].tokens[i].isHome = t.isHome;
                    this.players[2].tokens[i].isActive = t.isActive;
                }
            });
        }
        
        this.updateTokenPositions();
        this.render();
    }

    resetGame() {
        for (const playerId in this.players) {
            const player = this.players[playerId];
            player.homeCount = 0;
            for (const token of player.tokens) {
                token.trackIndex = -1;
                token.isHome = true;
                token.isActive = false;
                token.x = 0;
                token.y = 0;
            }
        }
        
        this.gameState = {
            status: 'waiting',
            currentTurn: 1,
            diceValue: 0,
            hasRolled: false,
            canRoll: true,
            turnNumber: 0,
            isGameOver: false,
            winner: null,
            winningAmount: 0
        };
        
        this.updateTokenPositions();
        this.render();
    }

    // ==============================================
    // EVENTS
    // ==============================================
    on(event, callback) {
        if (this.callbacks.hasOwnProperty(event)) {
            this.callbacks[event] = callback;
        }
    }

    // ==============================================
    // AI PLAYER (for 2-player mode)
    // ==============================================
    aiTurn() {
        if (this.gameState.isGameOver) return;
        if (this.gameState.currentTurn !== 2) return;
        if (this.gameState.hasRolled) return;
        
        setTimeout(() => {
            this.rollDice();
        }, 800);
    }
}

// ==============================================
// POLYFILL: roundRect
// ==============================================
if (!CanvasRenderingContext2D.prototype.roundRect) {
    CanvasRenderingContext2D.prototype.roundRect = function(x, y, w, h, r) {
        if (r > w/2) r = w/2;
        if (r > h/2) r = h/2;
        this.moveTo(x + r, y);
        this.arcTo(x + w, y, x + w, y + h, r);
        this.arcTo(x + w, y + h, x, y + h, r);
        this.arcTo(x, y + h, x, y, r);
        this.arcTo(x, y, x + w, y, r);
        return this;
    };
}

// Export
window.LudoEngine = LudoEngine;
window.TRACK_POSITIONS = TRACK_POSITIONS;
console.log('🎲 Ludo Engine loaded successfully');
