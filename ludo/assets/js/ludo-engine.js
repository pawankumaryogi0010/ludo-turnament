/**
 * ======================================================
 * LUDO-ENGINE.JS - Complete Ludo Game Engine (FIXED)
 * Ludo Tournament Platform - Canvas + Game Logic
 * Version: 3.0.0 - DICE VALUE 0 FIX + CANVAS FIX
 * ======================================================
 */

const TRACK_POSITIONS = (function() {
    const positions = [];
    const cellSize = 38;
    const offset = 20;
    for (let i = 0; i < 13; i++) positions.push({ x: offset + i * cellSize, y: offset + 12 * cellSize });
    for (let i = 1; i < 13; i++) positions.push({ x: offset + 12 * cellSize, y: offset + (12 - i) * cellSize });
    for (let i = 1; i < 13; i++) positions.push({ x: offset + (12 - i) * cellSize, y: offset });
    for (let i = 1; i < 13; i++) positions.push({ x: offset, y: offset + i * cellSize });
    return positions;
})();

class LudoEngine {
    constructor(canvas, options = {}) {
        this.canvas = canvas;
        this.ctx = canvas.getContext('2d');
        this.cellSize = options.cellSize || 38;
        this.padding = 20;
        this.player1Name = options.player1Name || 'Player 1';
        this.player2Name = options.player2Name || 'Player 2';
        
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
        
        this.players = {
            1: { id: 1, name: this.player1Name, color: '#EF4444', lightColor: '#FCA5A5', darkColor: '#B91C1C', homeCount: 0, tokens: [] },
            2: { id: 2, name: this.player2Name, color: '#3B82F6', lightColor: '#93C5FD', darkColor: '#1D4ED8', homeCount: 0, tokens: [] }
        };
        
        for (let p = 1; p <= 2; p++) {
            for (let i = 0; i < 4; i++) {
                this.players[p].tokens.push({ id: (p-1)*4 + i + 1, trackIndex: -1, isHome: true, isActive: false, x: 0, y: 0 });
            }
        }
        
        this.callbacks = { onTurnChange: null, onDiceRoll: null, onTokenMove: null, onWin: null, onGameStateUpdate: null };
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
            resizeTimeout = setTimeout(() => { this.resize(); this.render(); }, 200);
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

        if (this.gameState.hasRolled) {
            const player = this.players[1];
            for (const token of player.tokens) {
                if (!token.isActive && !token.isHome) continue;
                const dx = x - token.x, dy = y - token.y;
                if (Math.sqrt(dx*dx + dy*dy) < 15) { this.moveToken(1, token.id); return; }
            }
            return;
        }
        if (!this.gameState.canRoll) return;
        this.rollDice();
    }

    rollDice() {
        if (this.gameState.isGameOver || this.gameState.currentTurn !== 1) return;
        if (!this.gameState.canRoll || this.gameState.hasRolled) return;
        
        const value = Math.floor(Math.random() * 6) + 1;
        this.gameState.diceValue = value;
        this.gameState.hasRolled = true;
        this.gameState.canRoll = false;
        this.gameState.turnNumber++;
        
        if (this.callbacks.onDiceRoll) this.callbacks.onDiceRoll(value, value === 6);
        this.render();
        
        if (value === 6) {
            setTimeout(() => { this.gameState.canRoll = true; this.gameState.hasRolled = false; this.render(); }, 1500);
        } else {
            setTimeout(() => this.endTurn(), 1200);
        }
    }

    moveToken(playerId, tokenId) {
        if (this.gameState.isGameOver || this.gameState.currentTurn !== playerId || !this.gameState.hasRolled) return;
        
        const player = this.players[playerId];
        const token = player.tokens.find(t => t.id === tokenId);
        if (!token) return;
        
        const diceValue = this.gameState.diceValue;
        
        if (token.isHome && diceValue === 6) {
            token.isHome = false; token.isActive = true; token.trackIndex = 0;
            this.updateTokenPosition(playerId, token);
            this.gameState.canRoll = true; this.gameState.hasRolled = false;
            this.render();
            if (this.callbacks.onTokenMove) this.callbacks.onTokenMove(playerId, tokenId, -1, 0);
            return;
        }
        
        if (token.isActive) {
            const newIndex = token.trackIndex + diceValue;
            if (newIndex > 51) {
                token.isHome = true; token.isActive = false; token.trackIndex = -1;
                player.homeCount++;
                if (player.homeCount === 4) {
                    this.gameState.isGameOver = true; this.gameState.winner = playerId;
                    this.gameState.status = 'completed';
                    if (this.callbacks.onWin) this.callbacks.onWin(playerId);
                }
            } else {
                token.trackIndex = newIndex;
                this.updateTokenPosition(playerId, token);
            }
            this.render();
            if (this.callbacks.onTokenMove) this.callbacks.onTokenMove(playerId, tokenId, token.trackIndex - diceValue, token.trackIndex);
            this.gameState.canRoll = true; this.gameState.hasRolled = false;
        }
    }

    updateTokenPosition(playerId, token) {
        if (token.isHome) { token.x = 0; token.y = 0; return; }
        let idx = token.trackIndex;
        if (idx < 0 || idx >= TRACK_POSITIONS.length) idx = 0;
        const pos = TRACK_POSITIONS[idx];
        if (pos) { token.x = pos.x; token.y = pos.y; }
    }

    updateTokenPositions() {
        for (const pid in this.players) {
            for (const token of this.players[pid].tokens) {
                this.updateTokenPosition(parseInt(pid), token);
            }
        }
    }

    endTurn() {
        const nextTurn = this.gameState.currentTurn === 1 ? 2 : 1;
        this.gameState.currentTurn = nextTurn;
        this.gameState.canRoll = true;
        this.gameState.hasRolled = false;
        if (this.callbacks.onTurnChange) this.callbacks.onTurnChange(nextTurn);
        this.render();
    }

    render() {
        const ctx = this.ctx, size = this.boardSize, p = this.padding, cs = this.cellSize;
        ctx.clearRect(0, 0, size, size);
        
        const bg = ctx.createRadialGradient(size/2, size/2, 0, size/2, size/2, size/2);
        bg.addColorStop(0, '#1a1a2e'); bg.addColorStop(1, '#0a0e1a');
        ctx.fillStyle = bg; ctx.fillRect(0, 0, size, size);
        
        ctx.strokeStyle = 'rgba(251,191,36,0.2)'; ctx.lineWidth = 2;
        ctx.strokeRect(p, p, size - 2*p, size - 2*p);
        
        for (let i = 0; i < 15; i++) {
            const pos = p + i * cs;
            ctx.strokeStyle = 'rgba(255,255,255,0.03)'; ctx.lineWidth = 1;
            ctx.beginPath(); ctx.moveTo(pos, p); ctx.lineTo(pos, size - p); ctx.stroke();
            ctx.beginPath(); ctx.moveTo(p, pos); ctx.lineTo(size - p, pos); ctx.stroke();
        }
        
        this.drawHomeBase(ctx, p, p, cs, '#EF4444');
        this.drawHomeBase(ctx, size - p - 6*cs, p, cs, '#3B82F6');
        this.drawHomeBase(ctx, p, size - p - 6*cs, cs, '#10B981');
        this.drawHomeBase(ctx, size - p - 6*cs, size - p - 6*cs, cs, '#F59E0B');
        
        ctx.fillStyle = 'rgba(251,191,36,0.05)';
        ctx.fillRect(p + 6*cs, p + 6*cs, 2*cs, 2*cs);
        ctx.strokeStyle = 'rgba(251,191,36,0.1)';
        ctx.strokeRect(p + 6*cs, p + 6*cs, 2*cs, 2*cs);
        
        this.drawTokens(ctx);
        if (this.gameState.diceValue > 0) this.drawDice(ctx, this.gameState.diceValue);
        this.drawTurnIndicator(ctx);
        if (this.gameState.isGameOver) this.drawWinnerOverlay(ctx);
    }

    drawHomeBase(ctx, x, y, cs, color) {
        const s = 6 * cs;
        ctx.fillStyle = color + '15'; ctx.fillRect(x, y, s, s);
        ctx.strokeStyle = color + '40'; ctx.lineWidth = 1; ctx.strokeRect(x, y, s, s);
    }

    drawTokens(ctx) {
        for (const pid in this.players) {
            const player = this.players[pid], color = player.color;
            for (const token of player.tokens) {
                if (token.isHome || !token.isActive) continue;
                const x = token.x, y = token.y;
                const g = ctx.createRadialGradient(x-3, y-3, 2, x, y, 10);
                g.addColorStop(0, '#FFF'); g.addColorStop(0.2, color); g.addColorStop(1, color);
                ctx.fillStyle = g; ctx.shadowColor = color + '60'; ctx.shadowBlur = 10;
                ctx.beginPath(); ctx.arc(x, y, 10, 0, Math.PI*2); ctx.fill(); ctx.shadowBlur = 0;
                ctx.strokeStyle = '#FFF3'; ctx.lineWidth = 1; ctx.beginPath(); ctx.arc(x, y, 10, 0, Math.PI*2); ctx.stroke();
                ctx.fillStyle = 'rgba(255,255,255,0.3)'; ctx.beginPath(); ctx.arc(x-3, y-4, 4, 0, Math.PI*2); ctx.fill();
                ctx.fillStyle = '#FFF'; ctx.font = '8px Inter'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
                ctx.fillText(token.id, x, y + 1);
            }
        }
    }

    drawDice(ctx, value) {
        const cx = this.boardSize/2, cy = this.boardSize/2 - 30, ds = 36;
        const g = ctx.createRadialGradient(cx-4, cy-4, 2, cx, cy, ds/2);
        g.addColorStop(0, '#FFF'); g.addColorStop(1, '#E2E8F0');
        ctx.fillStyle = g; ctx.shadowColor = 'rgba(251,191,36,0.3)'; ctx.shadowBlur = 20;
        ctx.beginPath(); ctx.roundRect(cx-ds/2, cy-ds/2, ds, ds, 6); ctx.fill(); ctx.shadowBlur = 0;
        ctx.strokeStyle = '#CBD5E1'; ctx.lineWidth = 1;
        ctx.beginPath(); ctx.roundRect(cx-ds/2, cy-ds/2, ds, ds, 6); ctx.stroke();
        
        const dots = {1:[[0,0]],2:[[-1,-1],[1,1]],3:[[-1,-1],[0,0],[1,1]],4:[[-1,-1],[1,-1],[-1,1],[1,1]],5:[[-1,-1],[1,-1],[0,0],[-1,1],[1,1]],6:[[-1,-1],[1,-1],[-1,0],[1,0],[-1,1],[1,1]]}[value]||[];
        ctx.fillStyle = '#1E293B';
        dots.forEach(([dx,dy]) => { ctx.beginPath(); ctx.arc(cx+dx*10, cy+dy*10, 5, 0, Math.PI*2); ctx.fill(); });
    }

    drawTurnIndicator(ctx) {
        const player = this.players[this.gameState.currentTurn];
        ctx.fillStyle = player.color + '30'; ctx.fillRect(0, this.boardSize-30, this.boardSize, 30);
        ctx.fillStyle = player.color; ctx.font = '12px Inter'; ctx.textAlign = 'center';
        ctx.fillText(`🎯 ${player.name}'s Turn`, this.boardSize/2, this.boardSize-15);
    }

    drawWinnerOverlay(ctx) {
        const player = this.players[this.gameState.winner], s = this.boardSize;
        ctx.fillStyle = 'rgba(0,0,0,0.6)'; ctx.fillRect(0, 0, s, s);
        ctx.fillStyle = '#FBBF24'; ctx.font = 'bold 32px Inter'; ctx.textAlign = 'center';
        ctx.fillText('🏆', s/2, s/2-30);
        ctx.fillStyle = '#FFF'; ctx.font = 'bold 24px Inter';
        ctx.fillText(`${player.name} Wins!`, s/2, s/2+30);
    }

    getState() {
        return {
            status: this.gameState.status, currentTurn: this.gameState.currentTurn,
            diceValue: this.gameState.diceValue, turnNumber: this.gameState.turnNumber,
            isGameOver: this.gameState.isGameOver, winner: this.gameState.winner,
            player1: { name: this.players[1].name, homeCount: this.players[1].homeCount, tokens: this.players[1].tokens.map(t=>({id:t.id,trackIndex:t.trackIndex,isHome:t.isHome,isActive:t.isActive})) },
            player2: { name: this.players[2].name, homeCount: this.players[2].homeCount, tokens: this.players[2].tokens.map(t=>({id:t.id,trackIndex:t.trackIndex,isHome:t.isHome,isActive:t.isActive})) }
        };
    }

    setState(state) {
        // FIXED: Use !== undefined instead of truthy check
        if (state.status !== undefined) this.gameState.status = state.status;
        if (state.currentTurn !== undefined) this.gameState.currentTurn = state.currentTurn;
        if (state.diceValue !== undefined) this.gameState.diceValue = state.diceValue;
        if (state.turnNumber !== undefined) this.gameState.turnNumber = state.turnNumber;
        if (state.isGameOver !== undefined) this.gameState.isGameOver = state.isGameOver;
        if (state.winner !== undefined) this.gameState.winner = state.winner;
        if (state.player1) { this.players[1].homeCount = state.player1.homeCount||0; state.player1.tokens.forEach((t,i)=>{if(this.players[1].tokens[i]){this.players[1].tokens[i].trackIndex=t.trackIndex;this.players[1].tokens[i].isHome=t.isHome;this.players[1].tokens[i].isActive=t.isActive}}) }
        if (state.player2) { this.players[2].homeCount = state.player2.homeCount||0; state.player2.tokens.forEach((t,i)=>{if(this.players[2].tokens[i]){this.players[2].tokens[i].trackIndex=t.trackIndex;this.players[2].tokens[i].isHome=t.isHome;this.players[2].tokens[i].isActive=t.isActive}}) }
        this.updateTokenPositions(); this.render();
    }

    resetGame() {
        for (const pid in this.players) {
            this.players[pid].homeCount = 0;
            this.players[pid].tokens.forEach(t => { t.trackIndex=-1; t.isHome=true; t.isActive=false; });
        }
        this.gameState = { status:'waiting', currentTurn:1, diceValue:0, hasRolled:false, canRoll:true, turnNumber:0, isGameOver:false, winner:null, winningAmount:0 };
        this.updateTokenPositions(); this.render();
    }

    on(event, callback) { if (this.callbacks.hasOwnProperty(event)) this.callbacks[event] = callback; }
}

// Polyfill
if (!CanvasRenderingContext2D.prototype.roundRect) {
    CanvasRenderingContext2D.prototype.roundRect = function(x,y,w,h,r){if(r>w/2)r=w/2;if(r>h/2)r=h/2;this.moveTo(x+r,y);this.arcTo(x+w,y,x+w,y+h,r);this.arcTo(x+w,y+h,x,y+h,r);this.arcTo(x,y+h,x,y,r);this.arcTo(x,y,x+w,y,r);return this};
}

window.LudoEngine = LudoEngine;
window.TRACK_POSITIONS = TRACK_POSITIONS;
console.log('🎲 Ludo Engine v3.0 loaded');
