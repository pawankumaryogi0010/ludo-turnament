<?php
/**
 * ======================================================
 * GAME.PHP - Ludo Game Page (XSS FIXED)
 * Ludo Tournament Platform - Complete Game Interface
 * Version: 5.0.3 - XSS PROTECTION + ALL BUGS FIXED
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

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
} catch (Exception $e) {
    die("Database connection failed. Please try again.");
}

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

$player1Id = intval($match['player1_id'] ?? 0);
$player2Id = intval($match['player2_id'] ?? 0);

if ($player1Id !== $userId && $player2Id !== $userId) {
    header('Location: dashboard.php');
    exit;
}

$playerNumber = ($player1Id === $userId) ? 1 : 2;
$opponentNumber = $playerNumber === 1 ? 2 : 1;
$opponentName = htmlspecialchars($playerNumber === 1 ? ($match['player2_name'] ?? $match['p2_username'] ?? 'Player 2') : ($match['player1_name'] ?? $match['p1_username'] ?? 'Player 1'));
$myName = htmlspecialchars($playerNumber === 1 ? ($match['player1_name'] ?? $match['p1_username'] ?? 'Player 1') : ($match['player2_name'] ?? $match['p2_username'] ?? 'Player 2'));

$csrf_token = CSRFToken::generate();
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
if ($basePath === '') {
    $basePath = '';
}

// Load board state
$boardState = json_decode($match['board_state'] ?? '{}', true);
if (!is_array($boardState)) {
    $boardState = [];
}

if (!isset($boardState['player1']) || !is_array($boardState['player1'])) {
    $boardState['player1'] = [];
}
if (!isset($boardState['player2']) || !is_array($boardState['player2'])) {
    $boardState['player2'] = [];
}

// If board_state is empty, load from token columns
if (empty($boardState['player1']) && empty($boardState['player2'])) {
    $boardState['player1'] = [
        'token1' => intval($match['p1_token1'] ?? -1),
        'token2' => intval($match['p1_token2'] ?? -1),
        'token3' => intval($match['p1_token3'] ?? -1),
        'token4' => intval($match['p1_token4'] ?? -1)
    ];
    $boardState['player2'] = [
        'token1' => intval($match['p2_token1'] ?? -1),
        'token2' => intval($match['p2_token2'] ?? -1),
        'token3' => intval($match['p2_token3'] ?? -1),
        'token4' => intval($match['p2_token4'] ?? -1)
    ];
}

// Ensure all 4 tokens exist for both players with INTEGER values only
for ($i = 1; $i <= 4; $i++) {
    if (!isset($boardState['player1']['token' . $i])) {
        $boardState['player1']['token' . $i] = -1;
    } else {
        $boardState['player1']['token' . $i] = intval($boardState['player1']['token' . $i]);
    }
    if (!isset($boardState['player2']['token' . $i])) {
        $boardState['player2']['token' . $i] = -1;
    } else {
        $boardState['player2']['token' . $i] = intval($boardState['player2']['token' . $i]);
    }
}

// FIXED: XSS PROTECTION - Double encode for safe JavaScript injection
// json_encode with JSON_HEX_TAG prevents </script> injection
$boardStateJson = json_encode($boardState, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

$currentTurnUserId = intval($match['current_turn_id'] ?? 0);
if ($currentTurnUserId === $player1Id) {
    $currentTurn = 1;
} elseif ($currentTurnUserId === $player2Id) {
    $currentTurn = 2;
} else {
    $currentTurn = 1;
}

$isGameOver = ($match['status'] === 'completed');
$winnerId = intval($match['winner_id'] ?? 0);
$initialDiceValue = intval($match['dice_value'] ?? 0);
$matchStatus = $match['status'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#5B2D8E">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Ludo Game - Ludo Pro</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="<?php echo htmlspecialchars($basePath); ?>/assets/css/zupee-style.css">
    <link rel="manifest" href="<?php echo htmlspecialchars($basePath); ?>/manifest.json">

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Poppins', sans-serif;
            background: #5B2D8E;
            overflow: hidden;
            height: 100vh;
            height: 100dvh;
        }

        .game-wrapper {
            max-width: 480px;
            margin: 0 auto;
            height: 100vh;
            height: 100dvh;
            display: flex;
            flex-direction: column;
            background: linear-gradient(180deg, #5B2D8E 0%, #3B1A6A 100%);
            position: relative;
        }

        .game-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 16px;
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            flex-shrink: 0;
            z-index: 10;
        }

        .game-header .back-btn {
            color: white;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            padding: 6px 14px;
            background: rgba(255,255,255,0.15);
            border-radius: 20px;
            transition: background 0.2s;
        }

        .game-header .back-btn:hover { background: rgba(255,255,255,0.25); }

        .game-header .room-code {
            font-size: 16px;
            font-weight: 700;
            color: #FFD700;
            letter-spacing: 1px;
            text-shadow: 0 0 20px rgba(255,215,0,0.3);
        }

        .game-header .player-info {
            font-size: 11px;
            color: rgba(255,255,255,0.8);
            text-align: right;
        }

        .game-header .player-info span { color: white; font-weight: 600; }

        .player-bars {
            display: flex;
            justify-content: space-between;
            padding: 8px 16px;
            gap: 12px;
        }

        .player-bar {
            flex: 1;
            background: rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 10px 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            border: 2px solid transparent;
            transition: border-color 0.3s;
        }

        .player-bar.active-turn {
            border-color: #FFD700;
            background: rgba(255,215,0,0.1);
            animation: pulse-border 1.5s ease-in-out infinite;
        }

        @keyframes pulse-border {
            0%, 100% { border-color: #FFD700; box-shadow: 0 0 10px rgba(255,215,0,0.2); }
            50% { border-color: #FFA500; box-shadow: 0 0 20px rgba(255,215,0,0.4); }
        }

        .player-bar .avatar-mini {
            width: 36px; height: 36px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 16px; flex-shrink: 0;
        }

        .avatar-p1 { background: #EF4444; color: white; }
        .avatar-p2 { background: #3B82F6; color: white; }

        .player-bar .bar-info { flex: 1; min-width: 0; }

        .player-bar .bar-name {
            font-size: 13px; font-weight: 600; color: white;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }

        .player-bar .bar-tokens { font-size: 10px; color: rgba(255,255,255,0.7); }
        .player-bar .bar-score { font-size: 14px; font-weight: 700; color: #FFD700; }

        .game-canvas-container {
            flex: 1;
            display: flex; align-items: center; justify-content: center;
            padding: 8px; overflow: hidden; position: relative;
        }

        .game-canvas-container canvas {
            max-width: 100%; max-height: 100%;
            border-radius: 16px; background: #1A1A2E;
            box-shadow: 0 0 40px rgba(0,0,0,0.5), 0 0 80px rgba(91,45,142,0.3);
            cursor: pointer; touch-action: none;
        }

        .dice-display {
            position: absolute; top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            width: 70px; height: 70px;
            background: white; border-radius: 16px;
            display: none; align-items: center; justify-content: center;
            font-size: 36px; font-weight: 900; color: #1A1A2E;
            box-shadow: 0 8px 32px rgba(0,0,0,0.4);
            z-index: 20; pointer-events: none;
        }

        .dice-display.rolling { animation: diceRoll 0.6s ease; }

        @keyframes diceRoll {
            0% { transform: translate(-50%, -50%) rotate(0deg) scale(0.5); opacity: 0; }
            50% { transform: translate(-50%, -50%) rotate(360deg) scale(1.2); opacity: 1; }
            100% { transform: translate(-50%, -50%) rotate(720deg) scale(1); opacity: 1; }
        }

        .game-footer {
            padding: 12px 16px;
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
            flex-shrink: 0;
            display: flex; justify-content: space-between; align-items: center;
            gap: 8px; z-index: 10;
        }

        .game-footer .turn-text { font-size: 12px; color: rgba(255,255,255,0.8); }
        .game-footer .turn-text .highlight { color: #FFD700; font-weight: 700; }

        .btn-roll {
            padding: 14px 32px;
            background: linear-gradient(135deg, #00A859, #22C55E);
            color: white; border: none; border-radius: 30px;
            font-weight: 700; font-size: 16px; cursor: pointer;
            font-family: inherit;
            box-shadow: 0 4px 16px rgba(0,168,89,0.4);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .btn-roll:hover { transform: scale(1.05); box-shadow: 0 6px 24px rgba(0,168,89,0.6); }
        .btn-roll:active { transform: scale(0.95); }
        .btn-roll:disabled { opacity: 0.5; cursor: not-allowed; transform: none !important; box-shadow: none !important; }

        .timer-circle {
            width: 48px; height: 48px;
            border-radius: 50%;
            border: 3px solid rgba(255,255,255,0.2);
            display: flex; align-items: center; justify-content: center;
            position: relative;
        }

        .timer-circle .timer-text { font-size: 16px; font-weight: 700; color: white; z-index: 1; }
        .timer-circle.warning { border-color: #F59E0B; }
        .timer-circle.warning .timer-text { color: #F59E0B; }
        .timer-circle.danger { border-color: #EF4444; animation: pulse 0.5s infinite; }
        .timer-circle.danger .timer-text { color: #EF4444; }

        @keyframes pulse { 0%,100%{transform:scale(1)} 50%{transform:scale(1.1)} }

        .winner-overlay {
            position: absolute; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.8); backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            display: none; flex-direction: column;
            align-items: center; justify-content: center; z-index: 30;
        }

        .winner-overlay.active { display: flex; animation: fadeIn 0.5s ease; }
        @keyframes fadeIn { from{opacity:0} to{opacity:1} }

        .winner-overlay .trophy { font-size: 72px; animation: bounce 1s infinite; }
        @keyframes bounce { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-20px)} }

        .winner-overlay .win-title { font-size: 28px; font-weight: 800; color: #FFD700; margin-top: 12px; }
        .winner-overlay .win-amount { font-size: 16px; color: rgba(255,255,255,0.8); margin-top: 4px; }
        .winner-overlay .win-amount span { color: #FFD700; font-weight: 700; }

        .winner-overlay .btn-back {
            margin-top: 20px; padding: 12px 32px;
            background: white; color: #5B2D8E;
            border: none; border-radius: 30px;
            font-weight: 700; font-size: 16px; cursor: pointer; font-family: inherit;
        }

        .toast-zupee {
            position: fixed; bottom: 100px; left: 50%;
            transform: translateX(-50%) translateY(20px);
            padding: 12px 24px; border-radius: 30px;
            font-weight: 600; font-size: 14px; z-index: 2000;
            opacity: 0; transition: all 0.3s ease;
            pointer-events: none; white-space: nowrap;
        }

        .toast-zupee.show { opacity: 1; transform: translateX(-50%) translateY(0); }
        .toast-zupee.success { background: #D1FAE5; color: #00A859; }
        .toast-zupee.error { background: #FEE2E2; color: #EF4444; }
        .toast-zupee.info { background: #E0E7FF; color: #3730A3; }

        @media (max-width: 480px) {
            .game-header { padding: 8px 12px; }
            .game-footer { padding: 8px 12px; }
            .btn-roll { padding: 10px 24px; font-size: 14px; }
            .timer-circle { width: 40px; height: 40px; }
            .timer-circle .timer-text { font-size: 14px; }
            .dice-display { width: 56px; height: 56px; font-size: 28px; }
        }
    </style>
</head>
<body>
    <div class="game-wrapper">

        <div class="game-header">
            <a href="dashboard.php" class="back-btn">← Back</a>
            <div class="room-code">🔑 <?php echo htmlspecialchars($match['room_code']); ?></div>
            <div class="player-info"><?php echo $myName; ?> <span>vs</span> <?php echo $opponentName; ?></div>
        </div>

        <div class="player-bars">
            <div class="player-bar <?php echo $currentTurn === 1 ? 'active-turn' : ''; ?>" id="player1Bar">
                <div class="avatar-mini avatar-p1"><?php echo strtoupper(substr($match['player1_name'] ?? $match['p1_username'] ?? 'P1', 0, 1)); ?></div>
                <div class="bar-info">
                    <div class="bar-name"><?php echo htmlspecialchars($match['player1_name'] ?? $match['p1_username'] ?? 'Player 1'); ?></div>
                    <div class="bar-tokens">🏠 <span id="p1Home">0</span>/4 Home</div>
                </div>
                <div class="bar-score" id="p1Score">0</div>
            </div>
            <div class="player-bar <?php echo $currentTurn === 2 ? 'active-turn' : ''; ?>" id="player2Bar">
                <div class="avatar-mini avatar-p2"><?php echo strtoupper(substr($match['player2_name'] ?? $match['p2_username'] ?? 'P2', 0, 1)); ?></div>
                <div class="bar-info">
                    <div class="bar-name"><?php echo htmlspecialchars($match['player2_name'] ?? $match['p2_username'] ?? 'Player 2'); ?></div>
                    <div class="bar-tokens">🏠 <span id="p2Home">0</span>/4 Home</div>
                </div>
                <div class="bar-score" id="p2Score">0</div>
            </div>
        </div>

        <div class="game-canvas-container">
            <canvas id="ludoCanvas"></canvas>
            <div class="dice-display" id="diceDisplay">1</div>
            <div class="winner-overlay <?php echo $isGameOver ? 'active' : ''; ?>" id="winnerOverlay">
                <div class="trophy">🏆</div>
                <div class="win-title" id="winnerName"><?php echo ($winnerId === $userId) ? '🎉 You Won!' : '😔 You Lost'; ?></div>
                <div class="win-amount">Prize: <span id="winnerAmount">₹<?php echo number_format(floatval($match['winning_amount'] ?? 0), 2); ?></span></div>
                <button class="btn-back" onclick="window.location.href='dashboard.php'">← Back to Dashboard</button>
            </div>
        </div>

        <div class="game-footer">
            <div class="turn-text">
                Turn: <span class="highlight" id="turnDisplay">
                    <?php echo ($currentTurn === $playerNumber) ? 'Your Turn' : 'Opponent\'s Turn'; ?>
                </span>
            </div>
            <button class="btn-roll" id="rollBtn" <?php echo ($currentTurn !== $playerNumber || $isGameOver) ? 'disabled' : ''; ?>>
                🎲 Roll Dice
            </button>
            <div class="timer-circle" id="timerCircle">
                <span class="timer-text" id="timerText">15</span>
            </div>
        </div>

    </div>

    <div class="toast-zupee" id="toast"><span id="toastMessage"></span></div>

    <script>
        // ==============================================
        // GAME CONSTANTS
        // ==============================================
        const MATCH_ID = <?php echo $matchId; ?>;
        const USER_ID = <?php echo $userId; ?>;
        const PLAYER_NUMBER = <?php echo $playerNumber; ?>;
        const CSRF_TOKEN = '<?php echo $csrf_token; ?>';
        const API_BASE = '<?php echo htmlspecialchars($basePath); ?>/api/game.php';
        const OPPONENT_NAME = '<?php echo $opponentName; ?>';
        const MY_NAME = '<?php echo $myName; ?>';
        const INITIAL_DICE = <?php echo $initialDiceValue; ?>;
        const MATCH_STATUS = '<?php echo $matchStatus; ?>';

        // FIXED: XSS SAFE - Board state loaded with JSON_HEX_TAG protection
        let boardState = <?php echo $boardStateJson; ?>;
        let currentTurn = <?php echo $currentTurn; ?>;
        let isGameOver = <?php echo $isGameOver ? 'true' : 'false'; ?>;
        let canRoll = false;
        let timerInterval = null;
        let timeLeft = 15;
        const MAX_TIME = 15;
        let pollInterval = null;
        let lastDiceValue = INITIAL_DICE;

        // Ensure board state integrity
        if (!boardState.player1) boardState.player1 = {};
        if (!boardState.player2) boardState.player2 = {};
        for (let i = 1; i <= 4; i++) {
            if (boardState.player1['token' + i] === undefined || boardState.player1['token' + i] === null) {
                boardState.player1['token' + i] = -1;
            }
            if (boardState.player2['token' + i] === undefined || boardState.player2['token' + i] === null) {
                boardState.player2['token' + i] = -1;
            }
        }

        // ==============================================
        // CANVAS RENDERER
        // ==============================================
        const canvas = document.getElementById('ludoCanvas');
        const ctx = canvas.getContext('2d');

        function resizeCanvas() {
            const container = canvas.parentElement;
            const size = Math.min(container.clientWidth - 16, container.clientHeight - 16, 420);
            canvas.width = size;
            canvas.height = size;
            renderBoard();
        }

        function getTrackPositions() {
            const p = canvas.width * 0.04;
            const cs = (canvas.width - 2 * p) / 13;
            const positions = [];
            for (let i = 0; i < 13; i++) positions.push({ x: p + i * cs, y: p + 12 * cs });
            for (let i = 1; i < 13; i++) positions.push({ x: p + 12 * cs, y: p + (12 - i) * cs });
            for (let i = 1; i < 13; i++) positions.push({ x: p + (12 - i) * cs, y: p });
            for (let i = 1; i < 13; i++) positions.push({ x: p, y: p + i * cs });
            return positions;
        }

        function renderBoard() {
            const size = canvas.width;
            const p = size * 0.04;
            const cs = (size - 2 * p) / 13;
            const positions = getTrackPositions();

            ctx.clearRect(0, 0, size, size);

            const bg = ctx.createRadialGradient(size/2, size/2, 0, size/2, size/2, size/2);
            bg.addColorStop(0, '#1A1A2E');
            bg.addColorStop(1, '#0A0E1A');
            ctx.fillStyle = bg;
            ctx.fillRect(0, 0, size, size);

            ctx.strokeStyle = 'rgba(251,191,36,0.2)';
            ctx.lineWidth = 2;
            ctx.strokeRect(p, p, size - 2*p, size - 2*p);

            ctx.strokeStyle = 'rgba(255,255,255,0.03)';
            ctx.lineWidth = 1;
            for (let i = 0; i < 15; i++) {
                const pos = p + i * cs;
                ctx.beginPath(); ctx.moveTo(pos, p); ctx.lineTo(pos, size - p); ctx.stroke();
                ctx.beginPath(); ctx.moveTo(p, pos); ctx.lineTo(size - p, pos); ctx.stroke();
            }

            const homeColors = [
                { x: p, y: p, color: '#EF4444' },
                { x: size - p - 6*cs, y: p, color: '#3B82F6' },
                { x: p, y: size - p - 6*cs, color: '#10B981' },
                { x: size - p - 6*cs, y: size - p - 6*cs, color: '#F59E0B' }
            ];
            homeColors.forEach(h => {
                ctx.fillStyle = h.color + '15';
                ctx.fillRect(h.x, h.y, 6*cs, 6*cs);
                ctx.strokeStyle = h.color + '30';
                ctx.lineWidth = 1;
                ctx.strokeRect(h.x, h.y, 6*cs, 6*cs);
            });

            ctx.fillStyle = 'rgba(251,191,36,0.05)';
            ctx.fillRect(p + 6*cs, p + 6*cs, 2*cs, 2*cs);
            ctx.strokeStyle = 'rgba(251,191,36,0.1)';
            ctx.lineWidth = 1;
            ctx.strokeRect(p + 6*cs, p + 6*cs, 2*cs, 2*cs);

            const players = [
                { data: boardState.player1 || {}, color: '#EF4444' },
                { data: boardState.player2 || {}, color: '#3B82F6' }
            ];
            players.forEach(player => {
                for (let i = 1; i <= 4; i++) {
                    const pos = parseInt(player.data['token' + i] ?? -1);
                    if (pos < 0 || pos >= positions.length) continue;
                    const pt = positions[pos];
                    if (!pt) continue;
                    const x = pt.x, y = pt.y;
                    
                    const g = ctx.createRadialGradient(x-3, y-3, 2, x, y, 10);
                    g.addColorStop(0, '#FFFFFF');
                    g.addColorStop(0.3, player.color);
                    g.addColorStop(1, player.color);
                    ctx.fillStyle = g;
                    ctx.shadowColor = player.color + '60';
                    ctx.shadowBlur = 10;
                    ctx.beginPath();
                    ctx.arc(x, y, 10, 0, Math.PI * 2);
                    ctx.fill();
                    ctx.shadowBlur = 0;

                    ctx.strokeStyle = 'rgba(255,255,255,0.2)';
                    ctx.lineWidth = 1;
                    ctx.beginPath();
                    ctx.arc(x, y, 10, 0, Math.PI * 2);
                    ctx.stroke();

                    ctx.fillStyle = 'rgba(255,255,255,0.3)';
                    ctx.beginPath();
                    ctx.arc(x-3, y-4, 4, 0, Math.PI * 2);
                    ctx.fill();

                    ctx.fillStyle = '#FFFFFF';
                    ctx.font = 'bold 8px Poppins, sans-serif';
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.fillText(i, x, y + 1);
                }
            });

            updatePlayerBars();
        }

        function updatePlayerBars() {
            let p1Home = 0, p2Home = 0;
            for (let i = 1; i <= 4; i++) {
                if (parseInt(boardState.player1['token' + i] ?? -1) >= 56) p1Home++;
                if (parseInt(boardState.player2['token' + i] ?? -1) >= 56) p2Home++;
            }
            document.getElementById('p1Home').textContent = p1Home;
            document.getElementById('p2Home').textContent = p2Home;
            document.getElementById('p1Score').textContent = p1Home * 25;
            document.getElementById('p2Score').textContent = p2Home * 25;
            document.getElementById('player1Bar').classList.toggle('active-turn', currentTurn === 1);
            document.getElementById('player2Bar').classList.toggle('active-turn', currentTurn === 2);
        }

        // ==============================================
        // POLLING SYSTEM
        // ==============================================
        function startPolling() {
            if (pollInterval) return;
            pollInterval = setInterval(pollGameState, 1500);
            setTimeout(pollGameState, 500);
        }

        function stopPolling() {
            if (pollInterval) { clearInterval(pollInterval); pollInterval = null; }
        }

        async function pollGameState() {
            if (isGameOver) return;
            try {
                const res = await fetch(`${API_BASE}?action=get_state&match_id=${MATCH_ID}`);
                const data = await res.json();
                if (!data.success) return;

                const match = data.data.match;
                const newBoardState = data.data.board;
                
                if (newBoardState && newBoardState.player1 && newBoardState.player2) {
                    boardState = newBoardState;
                }

                currentTurn = match.current_turn || 1;
                isGameOver = (match.status === 'completed');
                lastDiceValue = parseInt(match.dice_value || 0);

                if (!boardState.player1) boardState.player1 = {};
                if (!boardState.player2) boardState.player2 = {};
                for (let i = 1; i <= 4; i++) {
                    if (boardState.player1['token' + i] === undefined) boardState.player1['token' + i] = -1;
                    if (boardState.player2['token' + i] === undefined) boardState.player2['token' + i] = -1;
                }

                renderBoard();
                updateTurnDisplay();

                const isMyTurn = (currentTurn === PLAYER_NUMBER);
                canRoll = isMyTurn && lastDiceValue === 0 && !isGameOver;
                document.getElementById('rollBtn').disabled = !canRoll;

                if (isMyTurn && !isGameOver) {
                    if (!timerInterval) startTimer();
                } else {
                    stopTimer();
                }

                if (isGameOver) {
                    stopPolling();
                    document.getElementById('rollBtn').disabled = true;
                    showWinner(match.winner_id);
                }
            } catch (e) {
                console.error('Poll error:', e);
            }
        }

        function updateTurnDisplay() {
            const turnDisplay = document.getElementById('turnDisplay');
            if (currentTurn === PLAYER_NUMBER) {
                turnDisplay.textContent = 'Your Turn';
                turnDisplay.style.color = '#FFD700';
            } else {
                turnDisplay.textContent = OPPONENT_NAME + "'s Turn";
                turnDisplay.style.color = 'rgba(255,255,255,0.6)';
            }
        }

        // ==============================================
        // ROLL DICE
        // ==============================================
        document.getElementById('rollBtn').addEventListener('click', async function() {
            if (!canRoll || isGameOver) return;
            stopTimer();
            this.disabled = true;
            canRoll = false;

            try {
                const res = await fetch(`${API_BASE}?action=roll`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                    body: JSON.stringify({ match_id: MATCH_ID, csrf_token: CSRF_TOKEN })
                });
                const data = await res.json();

                if (data.success) {
                    lastDiceValue = data.data.dice_value;
                    showDiceAnimation(lastDiceValue);
                    showToast('🎲 You rolled ' + lastDiceValue + '!', 'info');

                    if (data.data.extra_turn) {
                        setTimeout(() => {
                            showToast('🔄 Extra turn! Roll again.', 'success');
                            canRoll = true;
                            this.disabled = false;
                            startTimer();
                        }, 800);
                    } else {
                        setTimeout(pollGameState, 600);
                    }
                } else {
                    showToast(data.message || 'Roll failed', 'error');
                    this.disabled = false;
                }
            } catch (e) {
                showToast('Network error', 'error');
                this.disabled = false;
            }
        });

        function showDiceAnimation(value) {
            const dice = document.getElementById('diceDisplay');
            dice.textContent = value;
            dice.style.display = 'flex';
            dice.classList.add('rolling');
            setTimeout(() => {
                dice.classList.remove('rolling');
                setTimeout(() => { dice.style.display = 'none'; }, 500);
            }, 600);
        }

        // ==============================================
        // TIMER
        // ==============================================
        function startTimer() {
            stopTimer();
            timeLeft = MAX_TIME;
            updateTimerUI();
            timerInterval = setInterval(() => {
                timeLeft--;
                updateTimerUI();
                if (timeLeft <= 0) {
                    stopTimer();
                    showToast('⏰ Time\'s up! Turn skipped.', 'warning');
                }
            }, 1000);
        }

        function stopTimer() {
            if (timerInterval) { clearInterval(timerInterval); timerInterval = null; }
            document.getElementById('timerCircle').classList.remove('warning', 'danger');
        }

        function updateTimerUI() {
            document.getElementById('timerText').textContent = timeLeft;
            const circle = document.getElementById('timerCircle');
            circle.classList.remove('warning', 'danger');
            if (timeLeft <= 5) circle.classList.add('danger');
            else if (timeLeft <= 10) circle.classList.add('warning');
        }

        // ==============================================
        // WINNER OVERLAY
        // ==============================================
        function showWinner(winnerId) {
            const overlay = document.getElementById('winnerOverlay');
            const nameEl = document.getElementById('winnerName');
            
            if (winnerId == USER_ID) {
                nameEl.textContent = '🎉 You Won!';
                nameEl.style.color = '#FFD700';
            } else {
                nameEl.textContent = '😔 You Lost';
                nameEl.style.color = '#EF4444';
            }
            
            overlay.classList.add('active');
        }

        // ==============================================
        // TOAST
        // ==============================================
        function showToast(message, type = 'info') {
            const toast = document.getElementById('toast');
            const msg = document.getElementById('toastMessage');
            if (!toast || !msg) return;
            msg.textContent = message;
            toast.className = 'toast-zupee ' + type + ' show';
            clearTimeout(window._toastTimer);
            window._toastTimer = setTimeout(() => toast.classList.remove('show'), 3000);
        }

        // ==============================================
        // INITIALIZATION
        // ==============================================
        function init() {
            window.addEventListener('resize', resizeCanvas);
            resizeCanvas();
            renderBoard();
            updateTurnDisplay();

            if (!isGameOver) {
                startPolling();
                if (currentTurn === PLAYER_NUMBER) startTimer();
            } else {
                document.getElementById('rollBtn').disabled = true;
                showWinner(<?php echo $winnerId; ?>);
            }
            
            console.log('🎲 Game initialized - Board state:', boardState);
        }

        init();
    </script>
</body>
</html>
