<?php
/**
 * ======================================================
 * GAME.PHP - Ludo Game Page (FIXED - SERVER AUTHORITY)
 * Ludo Tournament Platform - Complete Game Interface
 * Version: 4.0.0 - ALL PATHS FIXED
 * ======================================================
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

require_once __DIR__ . '/config/db.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$userId = getCurrentUserId();
$matchId = isset($_GET['match_id']) ? intval($_GET['match_id']) : 0;

if ($matchId <= 0) {
    header('Location: dashboard.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();

$stmt = $conn->prepare("
    SELECT
        m.*,
        t.name as tournament_name,
        u1.username as p1_username,
        u2.username as p2_username
    FROM matches m
    LEFT JOIN tournaments t ON m.tournament_id = t.id
    LEFT JOIN users u1 ON m.player1_id = u1.id
    LEFT JOIN users u2 ON m.player2_id = u2.id
    WHERE m.id = :match_id
");
$stmt->execute([':match_id' => $matchId]);
$match = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$match) {
    header('Location: dashboard.php');
    exit;
}

if ($match['player1_id'] != $userId && $match['player2_id'] != $userId) {
    header('Location: dashboard.php');
    exit;
}

$playerNumber = ($match['player1_id'] == $userId) ? 1 : 2;
$opponentNumber = $playerNumber === 1 ? 2 : 1;
$opponentName = $playerNumber === 1 ? ($match['player2_name'] ?? $match['p2_username']) : ($match['player1_name'] ?? $match['p1_username']);

$csrf_token = CSRFToken::generate();

// Dynamic base path detection
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
if ($basePath === '') {
    $basePath = '';
}

// Get board state from JSON
$boardState = json_decode($match['board_state'] ?? '{}', true);
$p1Tokens = $boardState['player1'] ?? ['token1' => -1, 'token2' => -1, 'token3' => -1, 'token4' => -1];
$p2Tokens = $boardState['player2'] ?? ['token1' => -1, 'token2' => -1, 'token3' => -1, 'token4' => -1];

$USE_WEBSOCKET = false; // Use polling for shared hosting
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#0a0e1a">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Ludo Game - Ludo Tournament Pro</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

    <!-- FIXED: Dynamic CSS path -->
    <link rel="stylesheet" href="<?php echo $basePath; ?>/assets/css/style.css">
    <link rel="manifest" href="<?php echo $basePath; ?>/manifest.json">

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background: #0a0e1a;
            color: #f1f5f9;
            overflow: hidden;
            height: 100vh;
        }

        .game-wrapper {
            max-width: 480px;
            margin: 0 auto;
            height: 100vh;
            display: flex;
            flex-direction: column;
            background: #0a0e1a;
            position: relative;
        }

        .game-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 16px;
            background: rgba(10,14,26,0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255,255,255,0.06);
            flex-shrink: 0;
            z-index: 10;
        }

        .game-header .back-btn {
            color: #94a3b8;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            padding: 6px 12px;
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 8px;
            transition: background 0.2s;
        }

        .game-header .back-btn:hover { background: rgba(255,255,255,0.04); }

        .game-header .room-code {
            font-size: 14px;
            font-weight: 700;
            color: #fbbf24;
            letter-spacing: 1px;
        }

        .game-header .player-info {
            font-size: 12px;
            color: #94a3b8;
        }

        .game-header .player-info span {
            color: #f1f5f9;
            font-weight: 600;
        }

        .game-canvas-container {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 8px;
            overflow: hidden;
            position: relative;
        }

        .game-canvas-container canvas {
            max-width: 100%;
            max-height: 100%;
            border-radius: 12px;
            background: #1a1a2e;
            box-shadow: 0 0 40px rgba(0,0,0,0.5);
            cursor: pointer;
            touch-action: none;
        }

        .game-footer {
            padding: 10px 16px;
            background: rgba(10,14,26,0.95);
            backdrop-filter: blur(10px);
            border-top: 1px solid rgba(255,255,255,0.06);
            flex-shrink: 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            z-index: 10;
        }

        .game-footer .turn-info {
            font-size: 13px;
            color: #94a3b8;
        }

        .game-footer .turn-info .highlight {
            color: #fbbf24;
            font-weight: 700;
        }

        .game-footer .action-btn {
            padding: 8px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            font-family: inherit;
        }

        .game-footer .action-btn.primary {
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            color: #1a1a2e;
        }

        .game-footer .action-btn.primary:hover {
            transform: scale(1.04);
            box-shadow: 0 0 20px rgba(251,191,36,0.2);
        }

        .game-footer .action-btn.primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
        }

        .game-footer .action-btn.secondary {
            background: rgba(255,255,255,0.06);
            color: #f1f5f9;
            border: 1px solid rgba(255,255,255,0.08);
        }

        .game-footer .action-btn.secondary:hover { background: rgba(255,255,255,0.1); }

        .game-footer .right-controls {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .timer-display {
            font-size: 20px;
            font-weight: 700;
            color: #10b981;
            min-width: 40px;
            text-align: center;
            font-variant-numeric: tabular-nums;
            transition: color 0.3s ease;
        }

        .timer-display.warning {
            color: #f59e0b;
            animation: pulse-timer 1s ease-in-out infinite;
        }

        .timer-display.danger {
            color: #ef4444;
            animation: pulse-timer 0.5s ease-in-out infinite;
        }

        @keyframes pulse-timer {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.15); opacity: 0.7; }
        }

        .connection-status {
            position: fixed;
            top: 76px;
            right: 16px;
            padding: 4px 14px;
            border-radius: 9999px;
            font-size: 10px;
            font-weight: 600;
            z-index: 50;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .connection-status.online {
            background: rgba(16,185,129,0.2);
            color: #10b981;
            border: 1px solid rgba(16,185,129,0.2);
        }

        .connection-status.offline {
            background: rgba(239,68,68,0.2);
            color: #ef4444;
            border: 1px solid rgba(239,68,68,0.2);
            animation: pulse-badge 1s ease-in-out infinite;
        }

        .connection-status.connecting {
            background: rgba(245,158,11,0.2);
            color: #f59e0b;
            border: 1px solid rgba(245,158,11,0.2);
            animation: pulse-badge 1s ease-in-out infinite;
        }

        @media (max-width: 480px) {
            .game-header { padding: 6px 12px; }
            .game-header .room-code { font-size: 12px; }
            .game-footer { padding: 6px 12px; }
            .game-footer .action-btn { padding: 6px 14px; font-size: 12px; }
            .timer-display { font-size: 16px; min-width: 30px; }
            .connection-status { top: 60px; right: 8px; font-size: 8px; padding: 2px 8px; }
        }
    </style>
</head>
<body>
    <div class="game-wrapper">

        <div class="game-header">
            <a href="dashboard.php" class="back-btn">← Back</a>
            <div class="room-code">🔑 <?php echo htmlspecialchars($match['room_code']); ?></div>
            <div class="player-info">
                Player <span><?php echo $playerNumber; ?></span> vs <span><?php echo htmlspecialchars($opponentName); ?></span>
            </div>
        </div>

        <div class="game-canvas-container">
            <canvas id="ludoCanvas"></canvas>
        </div>

        <div class="game-footer">
            <div class="turn-info">
                Turn: <span class="highlight" id="turnDisplay">Waiting...</span>
            </div>
            <div class="right-controls">
                <div class="timer-display" id="timerDisplay">15</div>
                <button class="action-btn primary" id="rollBtn">🎲 Roll</button>
                <button class="action-btn secondary" id="resetBtn">↻</button>
            </div>
        </div>

        <div class="connection-status connecting" id="connectionStatus">🔄 Connecting...</div>

    </div>

    <!-- FIXED: Dynamic JS path -->
    <script src="<?php echo $basePath; ?>/assets/js/audio-synth.js"></script>

    <script>
        // ==============================================
        // GAME CONTROLLER - SERVER AUTHORITY
        // ==============================================
        document.addEventListener('DOMContentLoaded', function() {
            const canvas = document.getElementById('ludoCanvas');
            const turnDisplay = document.getElementById('turnDisplay');
            const timerDisplay = document.getElementById('timerDisplay');
            const rollBtn = document.getElementById('rollBtn');
            const resetBtn = document.getElementById('resetBtn');
            const connectionStatus = document.getElementById('connectionStatus');

            const playerNumber = <?php echo $playerNumber; ?>;
            const matchId = <?php echo $matchId; ?>;
            const userId = <?php echo $userId; ?>;
            const opponentName = '<?php echo htmlspecialchars($opponentName); ?>';
            const csrfToken = '<?php echo $csrf_token; ?>';
            const basePath = '<?php echo $basePath; ?>';

            let timerInterval = null;
            let timeLeft = 15;
            let timerRunning = false;
            const MAX_TIME = 15;

            let pollInterval = null;
            let lastActionId = 0;
            let isPolling = false;
            let currentTurn = <?php echo intval($match['current_turn_id']) == $userId ? 1 : 2; ?>;
            let isGameOver = false;
            let canRoll = true;
            let hasRolled = false;

            // ==============================================
            // CONNECTION STATUS
            // ==============================================
            function setConnectionStatus(status, message) {
                connectionStatus.className = 'connection-status ' + status;
                connectionStatus.textContent = message;

                if (status === 'online') {
                    setTimeout(() => {
                        connectionStatus.style.opacity = '0';
                        setTimeout(() => {
                            connectionStatus.style.display = 'none';
                        }, 500);
                    }, 3000);
                } else {
                    connectionStatus.style.display = 'block';
                    connectionStatus.style.opacity = '1';
                }
            }

            setConnectionStatus('connecting', '🔄 Connecting...');

            // ==============================================
            // LUDO RENDERER
            // ==============================================
            class LudoRenderer {
                constructor(canvas) {
                    this.canvas = canvas;
                    this.ctx = canvas.getContext('2d');
                    this.cellSize = 38;
                    this.padding = 20;
                    this.boardSize = 0;
                    this.trackPositions = [];

                    this.resize();
                    this.buildTrack();
                }

                resize() {
                    const container = this.canvas.parentElement;
                    const rect = container.getBoundingClientRect();
                    const size = Math.min(rect.width - 16, rect.height - 16, 420);

                    this.canvas.width = size;
                    this.canvas.height = size;
                    this.boardSize = size;
                    this.cellSize = (size - 2 * this.padding) / 13;
                    this.padding = this.cellSize * 0.5;
                    this.buildTrack();
                }

                buildTrack() {
                    this.trackPositions = [];
                    const cs = this.cellSize;
                    const p = this.padding;

                    for (let i = 0; i < 13; i++) {
                        this.trackPositions.push({ x: p + i * cs, y: p + 12 * cs });
                    }
                    for (let i = 1; i < 13; i++) {
                        this.trackPositions.push({ x: p + 12 * cs, y: p + (12 - i) * cs });
                    }
                    for (let i = 1; i < 13; i++) {
                        this.trackPositions.push({ x: p + (12 - i) * cs, y: p });
                    }
                    for (let i = 1; i < 13; i++) {
                        this.trackPositions.push({ x: p, y: p + i * cs });
                    }
                }

                render(boardState, diceValue, currentTurn, isGameOver, winnerId) {
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
                    this.drawTokens(ctx, boardState);

                    // Dice
                    if (diceValue > 0) {
                        this.drawDice(ctx, diceValue);
                    }

                    // Turn indicator
                    this.drawTurnIndicator(ctx, currentTurn);

                    // Winner overlay
                    if (isGameOver && winnerId) {
                        this.drawWinnerOverlay(ctx, winnerId);
                    }
                }

                drawHomeBase(ctx, x, y, cs, color, label) {
                    const size = 6 * cs;
                    ctx.fillStyle = color + '15';
                    ctx.fillRect(x, y, size, size);
                    ctx.strokeStyle = color + '40';
                    ctx.lineWidth = 1;
                    ctx.strokeRect(x, y, size, size);

                    ctx.fillStyle = color;
                    ctx.font = `${cs * 0.6}px Inter, sans-serif`;
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.fillText(label, x + size/2, y + size/2);
                }

                drawTokens(ctx, boardState) {
                    const players = {
                        1: { state: boardState?.player1 || {}, color: '#ef4444', label: 'P1' },
                        2: { state: boardState?.player2 || {}, color: '#3b82f6', label: 'P2' }
                    };

                    for (const [playerId, player] of Object.entries(players)) {
                        const color = player.color;
                        for (let i = 1; i <= 4; i++) {
                            const tokenKey = 'token' + i;
                            const position = player.state[tokenKey] ?? -1;

                            if (position < 0 || position > 56) continue;

                            const pos = this.trackPositions[position] || { x: 0, y: 0 };
                            const x = pos.x || 0;
                            const y = pos.y || 0;

                            if (x === 0 && y === 0) continue;

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

                            ctx.strokeStyle = '#ffffff30';
                            ctx.lineWidth = 1;
                            ctx.beginPath();
                            ctx.arc(x, y, 10, 0, Math.PI * 2);
                            ctx.stroke();

                            ctx.fillStyle = 'rgba(255,255,255,0.3)';
                            ctx.beginPath();
                            ctx.arc(x-3, y-4, 4, 0, Math.PI * 2);
                            ctx.fill();

                            ctx.fillStyle = '#ffffff';
                            ctx.font = '8px Inter, sans-serif';
                            ctx.textAlign = 'center';
                            ctx.textBaseline = 'middle';
                            ctx.fillText(i, x, y + 1);
                        }
                    }
                }

                drawDice(ctx, value) {
                    const size = this.boardSize;
                    const cx = size / 2;
                    const cy = size / 2 - 30;
                    const diceSize = 36;

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

                    ctx.strokeStyle = '#cbd5e1';
                    ctx.lineWidth = 1;
                    ctx.beginPath();
                    ctx.roundRect(cx - diceSize/2, cy - diceSize/2, diceSize, diceSize, 6);
                    ctx.stroke();

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

                drawTurnIndicator(ctx, currentTurn) {
                    const size = this.boardSize;
                    const player = currentTurn === 1 ? 'P1' : 'P2';
                    const color = currentTurn === 1 ? '#ef4444' : '#3b82f6';

                    ctx.fillStyle = color + '30';
                    ctx.fillRect(0, size - 30, size, 30);

                    ctx.fillStyle = color;
                    ctx.font = '12px Inter, sans-serif';
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.fillText(`🎯 ${player}'s Turn`, size/2, size - 15);
                }

                drawWinnerOverlay(ctx, winnerId) {
                    const size = this.boardSize;
                    const playerName = winnerId === 1 ? 'Player 1' : 'Player 2';

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
                    ctx.fillText(`${playerName} Wins!`, size/2, size/2 + 30);

                    ctx.fillStyle = '#94a3b8';
                    ctx.font = '16px Inter, sans-serif';
                    ctx.fillText('🎉 Congratulations!', size/2, size/2 + 65);
                }
            }

            // ==============================================
            // INIT RENDERER
            // ==============================================
            const renderer = new LudoRenderer(canvas);

            // Load initial board state from server
            let boardState = <?php echo json_encode($boardState); ?> || { player1: {}, player2: {} };

            // Ensure token structure
            if (!boardState.player1) boardState.player1 = {};
            if (!boardState.player2) boardState.player2 = {};
            for (let i = 1; i <= 4; i++) {
                if (boardState.player1['token' + i] === undefined) boardState.player1['token' + i] = -1;
                if (boardState.player2['token' + i] === undefined) boardState.player2['token' + i] = -1;
            }

            const diceValue = <?php echo intval($match['dice_value']); ?>;
            currentTurn = <?php echo intval($match['current_turn_id']) == $userId ? 1 : 2; ?>;
            isGameOver = <?php echo $match['status'] === 'completed' ? 'true' : 'false'; ?>;
            const winnerId = <?php echo intval($match['winner_id']); ?>;

            renderer.render(boardState, diceValue, currentTurn, isGameOver, winnerId);
            updateTurnDisplay();

            // ==============================================
            // POLLING SYSTEM
            // ==============================================
            function startPolling() {
                if (pollInterval) return;
                isPolling = true;
                setConnectionStatus('online', '✅ Connected');

                pollInterval = setInterval(() => {
                    pollForUpdates();
                }, 2000);

                setTimeout(pollForUpdates, 500);
            }

            function stopPolling() {
                if (pollInterval) {
                    clearInterval(pollInterval);
                    pollInterval = null;
                }
                isPolling = false;
            }

            function pollForUpdates() {
                if (!matchId || isGameOver) return;

                fetch(basePath + '/api/game?action=get_state&match_id=' + matchId, {
                    credentials: 'same-origin'
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        const match = data.data.match;
                        const players = data.data.players;
                        const board = data.data.board;

                        // Update board state
                        if (board) {
                            boardState = board;
                            if (!boardState.player1) boardState.player1 = {};
                            if (!boardState.player2) boardState.player2 = {};
                            for (let i = 1; i <= 4; i++) {
                                if (boardState.player1['token' + i] === undefined) boardState.player1['token' + i] = -1;
                                if (boardState.player2['token' + i] === undefined) boardState.player2['token' + i] = -1;
                            }
                        }

                        currentTurn = match.current_turn;
                        isGameOver = match.status === 'completed';

                        // Render
                        renderer.render(boardState, match.dice_value, currentTurn, isGameOver, match.winner_id);

                        // Update UI
                        updateTurnDisplay();

                        if (match.status === 'completed') {
                            stopPolling();
                            rollBtn.disabled = true;
                            if (match.winner_id == userId) {
                                showToast('🏆 You Won! 🏆', 'success');
                                if (typeof LudoAudioEngine !== 'undefined') {
                                    LudoAudioEngine.playWin();
                                }
                            } else {
                                showToast('😔 You Lost! Better luck next time.', 'error');
                                if (typeof LudoAudioEngine !== 'undefined') {
                                    LudoAudioEngine.playLose();
                                }
                            }
                            setTimeout(() => {
                                window.location.href = basePath + '/dashboard.php';
                            }, 5000);
                        }

                        // Enable/disable roll button
                        const isMyTurn = currentTurn === playerNumber;
                        canRoll = isMyTurn && !match.has_rolled && !isGameOver;
                        rollBtn.disabled = !canRoll;

                        if (isMyTurn && !timerRunning && !isGameOver) {
                            resetTimer();
                            startTimer();
                        }
                    }
                })
                .catch(err => {
                    console.error('Polling error:', err);
                });
            }

            // ==============================================
            // TIMER FUNCTIONS
            // ==============================================
            function startTimer() {
                stopTimer();
                timeLeft = MAX_TIME;
                updateTimerDisplay();
                timerRunning = true;

                timerInterval = setInterval(function() {
                    timeLeft--;
                    updateTimerDisplay();

                    if (timeLeft <= 5) {
                        timerDisplay.className = 'timer-display warning';
                        if (typeof LudoAudioEngine !== 'undefined') {
                            LudoAudioEngine.playNotification();
                        }
                    }

                    if (timeLeft <= 3) {
                        timerDisplay.className = 'timer-display danger';
                    }

                    if (timeLeft <= 0) {
                        stopTimer();
                        handleTimerExpired();
                    }
                }, 1000);
            }

            function stopTimer() {
                if (timerInterval) {
                    clearInterval(timerInterval);
                    timerInterval = null;
                }
                timerRunning = false;
                timerDisplay.className = 'timer-display';
            }

            function resetTimer() {
                stopTimer();
                timeLeft = MAX_TIME;
                updateTimerDisplay();
                timerDisplay.className = 'timer-display';
            }

            function updateTimerDisplay() {
                timerDisplay.textContent = timeLeft;
            }

            function handleTimerExpired() {
                showToast('⏰ Time\'s up! Turn skipped.', 'warning');
                if (typeof LudoAudioEngine !== 'undefined') {
                    LudoAudioEngine.playError();
                }

                // Server will handle turn change
                // Just reset timer for next turn
                resetTimer();
            }

            // ==============================================
            // UPDATE TURN DISPLAY
            // ==============================================
            function updateTurnDisplay() {
                const playerName = currentTurn === 1 ?
                    '<?php echo htmlspecialchars($match['player1_name'] ?? $match['p1_username']); ?>' :
                    '<?php echo htmlspecialchars($match['player2_name'] ?? $match['p2_username']); ?>';

                const isMyTurn = currentTurn === playerNumber;
                turnDisplay.textContent = isMyTurn ? `${playerName} (You)` : playerName;
                turnDisplay.style.color = isMyTurn ? '#fbbf24' : '#94a3b8';

                rollBtn.disabled = !canRoll;

                if (isMyTurn && !timerRunning && !isGameOver) {
                    resetTimer();
                    startTimer();
                }
            }

            // ==============================================
            // ROLL DICE - SERVER AUTHORITY
            // ==============================================
            rollBtn.addEventListener('click', function() {
                if (isGameOver) {
                    showToast('Game is already over!', 'error');
                    return;
                }

                const isMyTurn = currentTurn === playerNumber;
                if (!isMyTurn) {
                    showToast('Not your turn!', 'error');
                    return;
                }

                if (!canRoll) {
                    showToast('Wait for your turn!', 'error');
                    return;
                }

                stopTimer();
                rollBtn.disabled = true;

                fetch(basePath + '/api/game?action=roll', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({
                        match_id: matchId,
                        csrf_token: csrfToken
                    })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        canRoll = false;
                        showToast(`🎲 You rolled ${data.data.dice_value}`, 'info');
                        if (typeof LudoAudioEngine !== 'undefined') {
                            LudoAudioEngine.playDiceRoll();
                        }

                        if (data.data.extra_turn) {
                            setTimeout(() => {
                                showToast('🔄 Extra turn! Roll again.', 'success');
                                if (typeof LudoAudioEngine !== 'undefined') {
                                    LudoAudioEngine.playSuccess();
                                }
                                canRoll = true;
                                rollBtn.disabled = false;
                                resetTimer();
                                startTimer();
                            }, 800);
                        } else {
                            // Turn will change - wait for poll
                            setTimeout(() => {
                                rollBtn.disabled = true;
                            }, 500);
                        }

                        // Poll immediately for updated state
                        setTimeout(pollForUpdates, 600);
                    } else {
                        showToast(data.message || 'Failed to roll', 'error');
                        rollBtn.disabled = false;
                        if (typeof LudoAudioEngine !== 'undefined') {
                            LudoAudioEngine.playError();
                        }
                    }
                })
                .catch(() => {
                    showToast('Network error. Please try again.', 'error');
                    rollBtn.disabled = false;
                    if (typeof LudoAudioEngine !== 'undefined') {
                        LudoAudioEngine.playError();
                    }
                });
            });

            // ==============================================
            // TOAST NOTIFICATION
            // ==============================================
            function showToast(message, type = 'info') {
                const toast = document.createElement('div');
                const colors = {
                    success: 'rgba(16,185,129,0.2)',
                    error: 'rgba(239,68,68,0.2)',
                    warning: 'rgba(245,158,11,0.2)',
                    info: 'rgba(59,130,246,0.2)'
                };
                const borderColors = {
                    success: 'rgba(16,185,129,0.3)',
                    error: 'rgba(239,68,68,0.3)',
                    warning: 'rgba(245,158,11,0.3)',
                    info: 'rgba(59,130,246,0.3)'
                };
                const textColors = {
                    success: '#10b981',
                    error: '#ef4444',
                    warning: '#f59e0b',
                    info: '#3b82f6'
                };

                toast.style.cssText = `
                    position: fixed; bottom: 100px; left: 50%;
                    transform: translateX(-50%);
                    padding: 12px 24px; border-radius: 12px;
                    font-weight: 600; font-size: 14px;
                    z-index: 9999;
                    background: ${colors[type] || colors.info};
                    border: 1px solid ${borderColors[type] || borderColors.info};
                    color: ${textColors[type] || textColors.info};
                    max-width: 90%; text-align: center;
                    box-shadow: 0 8px 40px rgba(0,0,0,0.4);
                    animation: fadeInUp 0.3s ease;
                    transition: opacity 0.3s ease;
                `;
                toast.textContent = message;
                document.body.appendChild(toast);

                setTimeout(() => {
                    toast.style.opacity = '0';
                    setTimeout(() => {
                        if (toast.parentNode) {
                            toast.parentNode.removeChild(toast);
                        }
                    }, 300);
                }, 3000);
            }

            // ==============================================
            // RESET GAME
            // ==============================================
            resetBtn.addEventListener('click', function() {
                if (confirm('Reset the game? All progress will be lost.')) {
                    stopTimer();
                    showToast('Game reset!', 'info');
                    if (typeof LudoAudioEngine !== 'undefined') {
                        LudoAudioEngine.playClick();
                    }
                    // Reload from server
                    pollForUpdates();
                }
            });

            // ==============================================
            // KEYBOARD SHORTCUTS
            // ==============================================
            document.addEventListener('keydown', function(e) {
                if (e.key === ' ' || e.key === 'Enter') {
                    e.preventDefault();
                    if (!rollBtn.disabled) {
                        rollBtn.click();
                    }
                }
                if (e.key === 'r' && e.ctrlKey) {
                    e.preventDefault();
                    resetBtn.click();
                }
            });

            // ==============================================
            // START
            // ==============================================
            startPolling();

            // Handle resize
            window.addEventListener('resize', () => {
                renderer.resize();
                renderer.render(boardState, diceValue, currentTurn, isGameOver, winnerId);
            });

            console.log('🎲 Game loaded! Match ID:', matchId);
            console.log('👤 Player Number:', playerNumber);
            console.log('🎯 Current Turn:', currentTurn);
            console.log('📡 Mode: Polling (Server Authority)');
            console.log('📂 Base Path:', basePath);
        });
    </script>
</body>
</html>
