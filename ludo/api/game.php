<?php
/**
 * ======================================================
 * GAME.PHP - Authoritative Game API (1vs1 + 1vs4)
 * Ludo Tournament Platform - Server Authority
 * Version: 2.1.0 - 4 PLAYER SUPPORT
 * ======================================================
 */

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once dirname(__DIR__) . '/config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

$allowedOrigins = [
    rtrim(BASE_URL, '/'),
    'http://localhost',
    'http://localhost:3000',
    'http://127.0.0.1',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array(rtrim($origin, '/'), $allowedOrigins) || empty($origin)) {
    header('Access-Control-Allow-Origin: ' . ($origin ?: rtrim(BASE_URL, '/')));
} else {
    header('Access-Control-Allow-Origin: ' . rtrim(BASE_URL, '/'));
}
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-CSRF-Token, X-Auth-Token');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (!isLoggedIn()) {
    jsonResponse(false, 'Please login first', [], 401);
}

$userId = getCurrentUserId();

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
$input = json_decode(file_get_contents('php://input'), true) ?? [];

$csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!CSRFToken::validate($csrfToken) && $action !== 'get_state') {
    jsonResponse(false, 'Invalid CSRF token', [], 403);
}

if ($action === 'get_state') {
    session_write_close();
}

switch ($action) {
    case 'get_state':
        handleGetGameState($userId);
        break;
    case 'roll':
        handleRollDice($userId, $input);
        break;
    case 'move':
        handleMoveToken($userId, $input);
        break;
    case 'get_history':
        handleGetMatchHistory($userId);
        break;
    default:
        jsonResponse(false, 'Invalid action', [], 400);
}

// ==============================================
// GET GAME STATE
// ==============================================
function handleGetGameState(int $userId): void
{
    $matchId = isset($_GET['match_id']) ? intval($_GET['match_id']) : 0;

    if ($matchId <= 0) {
        jsonResponse(false, 'Invalid match ID', [], 400);
    }

    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();

        $stmt = $conn->prepare("
            SELECT m.*, 
                   u1.username as player1_username,
                   u2.username as player2_username,
                   u3.username as player3_username,
                   u4.username as player4_username
            FROM matches m
            LEFT JOIN users u1 ON m.player1_id = u1.id
            LEFT JOIN users u2 ON m.player2_id = u2.id
            LEFT JOIN users u3 ON m.player3_id = u3.id
            LEFT JOIN users u4 ON m.player4_id = u4.id
            WHERE m.id = :match_id
        ");
        $stmt->execute([':match_id' => $matchId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$match) {
            jsonResponse(false, 'Match not found', [], 404);
        }

        // Get all player IDs
        $playerIds = [];
        for ($i = 1; $i <= 4; $i++) {
            $pid = intval($match["player{$i}_id"] ?? 0);
            if ($pid > 0) $playerIds[] = $pid;
        }

        if (!in_array($userId, $playerIds)) {
            jsonResponse(false, 'Not authorized for this match', [], 403);
        }

        $boardState = json_decode($match['board_state'] ?? '{}', true) ?: [];
        
        // Ensure all players exist in board state
        for ($i = 1; $i <= 4; $i++) {
            if (!isset($boardState["player{$i}"])) $boardState["player{$i}"] = [];
        }
        
        // Determine current turn as player number
        $currentTurnUserId = intval($match['current_turn_id'] ?? 0);
        $currentTurn = 1;
        for ($i = 1; $i <= 4; $i++) {
            if ($currentTurnUserId === intval($match["player{$i}_id"] ?? 0)) {
                $currentTurn = $i;
                break;
            }
        }

        // Build player list
        $players = [];
        for ($i = 1; $i <= 4; $i++) {
            $pid = intval($match["player{$i}_id"] ?? 0);
            if ($pid > 0) {
                $players["player{$i}"] = [
                    'id' => $pid,
                    'name' => $match["player{$i}_name"] ?? $match["player{$i}_username"] ?? "Player {$i}",
                    'is_me' => ($pid === $userId),
                ];
            }
        }

        jsonResponse(true, 'Game state retrieved', [
            'match' => [
                'id' => intval($match['id']),
                'room_code' => $match['room_code'],
                'status' => $match['status'],
                'current_turn' => $currentTurn,
                'current_turn_id' => $currentTurnUserId,
                'dice_value' => intval($match['dice_value'] ?? 0),
                'turn_number' => intval($match['turn_number'] ?? 0),
                'entry_fee' => floatval($match['entry_fee'] ?? 0),
                'prize_pool' => floatval($match['prize_pool'] ?? 0),
                'winner_id' => $match['winner_id'] ? intval($match['winner_id']) : null,
                'winning_amount' => $match['winning_amount'] ? floatval($match['winning_amount']) : null,
                'is_my_turn' => ($currentTurnUserId === $userId),
                'has_rolled' => (intval($match['dice_value'] ?? 0) > 0),
                'player_count' => count($playerIds),
            ],
            'players' => $players,
            'board' => $boardState,
            'updated_at' => $match['updated_at'],
        ]);

    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// ROLL DICE (1vs4 SUPPORT)
// ==============================================
function handleRollDice(int $userId, array $input): void
{
    $matchId = intval($input['match_id'] ?? 0);

    if ($matchId <= 0) {
        jsonResponse(false, 'Invalid match ID', [], 400);
    }

    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();

        $db->beginTransaction();

        $stmt = $conn->prepare("
            SELECT * FROM matches WHERE id = :match_id FOR UPDATE
        ");
        $stmt->execute([':match_id' => $matchId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$match) {
            $db->rollback();
            jsonResponse(false, 'Match not found', [], 404);
        }

        if (!in_array($match['status'], ['playing', 'ready'])) {
            $db->rollback();
            jsonResponse(false, 'Match not in playable state', [], 400);
        }

        $currentTurnId = intval($match['current_turn_id'] ?? 0);
        if ($currentTurnId !== $userId) {
            $db->rollback();
            jsonResponse(false, 'Not your turn', [], 403);
        }

        // Get all active player IDs
        $activePlayers = [];
        for ($i = 1; $i <= 4; $i++) {
            $pid = intval($match["player{$i}_id"] ?? 0);
            if ($pid > 0) $activePlayers[] = $pid;
        }

        if (!in_array($userId, $activePlayers)) {
            $db->rollback();
            jsonResponse(false, 'Player not in this match', [], 403);
        }

        // SERVER GENERATES DICE
        $diceValue = rand(1, 6);
        $extraTurn = ($diceValue === 6);

        // Anti-cheat: consecutive sixes check
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM game_actions
            WHERE match_id = :mid AND user_id = :uid 
            AND action_type = 'dice_roll' AND dice_value = 6
            AND created_at > DATE_SUB(NOW(), INTERVAL 10 SECOND)
        ");
        $stmt->execute([':mid' => $matchId, ':uid' => $userId]);
        $sixCount = intval($stmt->fetchColumn());

        if ($sixCount >= 2 && $diceValue === 6) {
            $extraTurn = false;
            $diceValue = rand(1, 5);
        }

        // Determine next turn (cycle through active players)
        $currentIndex = array_search($userId, $activePlayers);
        $nextIndex = $extraTurn ? $currentIndex : ($currentIndex + 1) % count($activePlayers);
        $nextTurnId = $activePlayers[$nextIndex];

        // Update match
        $stmt = $conn->prepare("
            UPDATE matches SET dice_value = :dice, current_turn_id = :next_turn,
            turn_number = turn_number + 1, updated_at = CURRENT_TIMESTAMP
            WHERE id = :mid
        ");
        $stmt->execute([':dice' => $diceValue, ':next_turn' => $nextTurnId, ':mid' => $matchId]);

        // Log action
        $stmt = $conn->prepare("
            INSERT INTO game_actions (match_id, user_id, action_type, dice_value, metadata, created_at)
            VALUES (:mid, :uid, 'dice_roll', :dice, :meta, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([':mid' => $matchId, ':uid' => $userId, ':dice' => $diceValue, ':meta' => json_encode(['extra_turn' => $extraTurn])]);

        $db->commit();

        // Determine current turn as player number
        $responseTurn = 1;
        for ($i = 1; $i <= 4; $i++) {
            if ($nextTurnId === intval($match["player{$i}_id"] ?? 0)) {
                $responseTurn = $i;
                break;
            }
        }

        jsonResponse(true, 'Dice rolled', [
            'match_id' => $matchId,
            'dice_value' => $diceValue,
            'extra_turn' => $extraTurn,
            'current_turn' => $responseTurn,
            'action_id' => $conn->lastInsertId(),
            'turn_number' => intval($match['turn_number']) + 1,
        ]);

    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollback();
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// MOVE TOKEN (1vs4 SUPPORT)
// ==============================================
function handleMoveToken(int $userId, array $input): void
{
    $matchId = intval($input['match_id'] ?? 0);
    $tokenNumber = intval($input['token_number'] ?? 0);
    $targetPosition = intval($input['target_position'] ?? -1);

    if ($matchId <= 0 || $tokenNumber < 1 || $tokenNumber > 4) {
        jsonResponse(false, 'Invalid move parameters', [], 400);
    }

    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        $db->beginTransaction();

        $stmt = $conn->prepare("SELECT * FROM matches WHERE id = :mid FOR UPDATE");
        $stmt->execute([':mid' => $matchId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$match || $match['status'] !== 'playing') {
            $db->rollback();
            jsonResponse(false, 'Match not in playing state', [], 400);
        }

        if (intval($match['current_turn_id'] ?? 0) !== $userId) {
            $db->rollback();
            jsonResponse(false, 'Not your turn', [], 403);
        }

        $diceValue = intval($match['dice_value'] ?? 0);
        if ($diceValue <= 0) {
            $db->rollback();
            jsonResponse(false, 'Roll dice first', [], 400);
        }

        // Determine player number
        $playerNumber = 1;
        for ($i = 1; $i <= 4; $i++) {
            if ($userId === intval($match["player{$i}_id"] ?? 0)) {
                $playerNumber = $i;
                break;
            }
        }

        $boardState = json_decode($match['board_state'] ?? '{}', true) ?: [];
        $validMove = validateTokenMove($boardState, $playerNumber, $tokenNumber, $diceValue, $targetPosition);

        if (!$validMove) {
            $db->rollback();
            jsonResponse(false, 'Invalid move', [], 400);
        }

        $playerKey = 'player' . $playerNumber;
        $tokenKey = 'token' . $tokenNumber;
        if (!isset($boardState[$playerKey])) $boardState[$playerKey] = [];
        $boardState[$playerKey][$tokenKey] = $targetPosition;

        // Check winner
        $winnerId = checkWinner($boardState, $matchId);

        if ($winnerId) {
            $stmt = $conn->prepare("UPDATE matches SET status = 'completed', winner_id = :wid, winning_amount = :wamt, completed_at = CURRENT_TIMESTAMP WHERE id = :mid");
            $stmt->execute([':wid' => $winnerId, ':wamt' => floatval($match['prize_pool'] ?? 0), ':mid' => $matchId]);
            $db->commit();
            processSettlement($winnerId, $matchId, floatval($match['prize_pool'] ?? 0));
            jsonResponse(true, 'Game completed!', ['match_id' => $matchId, 'winner_id' => $winnerId, 'game_over' => true]);
            return;
        }

        // Get active players for next turn
        $activePlayers = [];
        for ($i = 1; $i <= 4; $i++) {
            $pid = intval($match["player{$i}_id"] ?? 0);
            if ($pid > 0) $activePlayers[] = $pid;
        }
        $currentIndex = array_search($userId, $activePlayers);
        $nextIndex = ($currentIndex + 1) % count($activePlayers);
        $nextTurnId = $activePlayers[$nextIndex];

        $stmt = $conn->prepare("UPDATE matches SET board_state = :bs, dice_value = 0, current_turn_id = :nt, updated_at = CURRENT_TIMESTAMP WHERE id = :mid");
        $stmt->execute([':bs' => json_encode($boardState), ':nt' => $nextTurnId, ':mid' => $matchId]);

        $stmt = $conn->prepare("INSERT INTO game_actions (match_id, user_id, action_type, token_number, from_position, to_position, created_at) VALUES (:mid, :uid, 'token_move', :tn, :fp, :tp, CURRENT_TIMESTAMP)");
        $stmt->execute([':mid' => $matchId, ':uid' => $userId, ':tn' => $tokenNumber, ':fp' => -1, ':tp' => $targetPosition]);

        $db->commit();
        jsonResponse(true, 'Token moved', ['match_id' => $matchId, 'board_state' => $boardState]);

    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollback();
        jsonResponse(false, 'Database error', [], 500);
    }
}

// Helper functions
function validateTokenMove(array $boardState, int $playerNumber, int $tokenNumber, int $diceValue, int $targetPosition): bool
{
    $playerKey = 'player' . $playerNumber;
    $tokenKey = 'token' . $tokenNumber;
    $currentPosition = $boardState[$playerKey][$tokenKey] ?? -1;
    if ($currentPosition === -1 && $diceValue !== 6) return false;
    if ($currentPosition === -1 && $diceValue === 6) return $targetPosition === 0;
    return ($currentPosition + $diceValue === $targetPosition) && $targetPosition >= 0 && $targetPosition <= 56;
}

function checkWinner(array $boardState, int $matchId): ?int
{
    for ($p = 1; $p <= 4; $p++) {
        $tokens = $boardState["player{$p}"] ?? [];
        $homeCount = 0;
        foreach ($tokens as $pos) { if ($pos >= 56 || $pos === -2) $homeCount++; }
        if ($homeCount >= 4) return getMatchPlayerId($matchId, $p);
    }
    return null;
}

function getMatchPlayerId(int $matchId, int $playerNumber): ?int
{
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        $stmt = $conn->prepare("SELECT player{$playerNumber}_id FROM matches WHERE id = :mid");
        $stmt->execute([':mid' => $matchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? intval($row["player{$playerNumber}_id"]) : null;
    } catch (Exception $e) { return null; }
}

function processSettlement(int $winnerId, int $matchId, float $amount): void
{
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        $db->beginTransaction();

        $stmt = $conn->prepare("SELECT wallet_balance FROM users WHERE id = :uid FOR UPDATE");
        $stmt->execute([':uid' => $winnerId]);
        $winner = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$winner) { $db->rollback(); return; }

        $tdsAmount = ($amount > TDS_THRESHOLD) ? round($amount * (TDS_RATE / 100), 2) : 0;
        $netAmount = $amount - $tdsAmount;

        $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance + :net, total_earnings = total_earnings + :amt, total_matches_won = total_matches_won + 1 WHERE id = :uid");
        $stmt->execute([':net' => $netAmount, ':amt' => $amount, ':uid' => $winnerId]);

        $orderId = 'WIN-' . strtoupper(bin2hex(random_bytes(6)));
        $stmt = $conn->prepare("INSERT INTO transactions (user_id, match_id, amount, type, source, description, order_id, status, balance_before, balance_after, tds_deducted, created_at) VALUES (:uid, :mid, :amt, 'credit', 'match_win', :desc, :oid, 'success', :bb, :ba, :tds, CURRENT_TIMESTAMP)");
        $stmt->execute([':uid' => $winnerId, ':mid' => $matchId, ':amt' => $amount, ':desc' => "Match win #{$matchId}", ':oid' => $orderId, ':bb' => floatval($winner['wallet_balance']), ':ba' => floatval($winner['wallet_balance']) + $netAmount, ':tds' => $tdsAmount]);

        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollback();
        error_log('Settlement failed: ' . $e->getMessage());
    }
}

function handleGetMatchHistory(int $userId): void
{
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        $stmt = $conn->prepare("SELECT * FROM matches WHERE (player1_id = :uid OR player2_id = :uid OR player3_id = :uid OR player4_id = :uid) AND status IN ('completed','cancelled') ORDER BY created_at DESC LIMIT 20");
        $stmt->execute([':uid' => $userId]);
        jsonResponse(true, 'History', ['matches' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error', [], 500);
    }
}
?>
