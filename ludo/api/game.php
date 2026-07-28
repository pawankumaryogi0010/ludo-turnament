<?php
/**
 * ======================================================
 * GAME.PHP - Authoritative Game API (V1)
 * Ludo Tournament Platform - Server Authority
 * Version: 1.0.0 - COMPLETE REWRITE
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

// FIX BUG 6: CORS — allow both localhost and production
$allowedOrigins = [
    rtrim(BASE_URL, '/'),
    'http://localhost',
    'http://localhost:3000',
    'http://127.0.0.1',
    'http://127.0.0.1/ludo',
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

// ==============================================
// AUTHENTICATION
// ==============================================
if (!isLoggedIn()) {
    jsonResponse(false, 'Please login first', [], 401);
}

$userId = getCurrentUserId();

// ==============================================
// INPUT & ROUTING
// ==============================================
$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
$input = json_decode(file_get_contents('php://input'), true) ?? [];

// CSRF Validation
$csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!CSRFToken::validate($csrfToken) && $action !== 'get_state') {
    jsonResponse(false, 'Invalid CSRF token', [], 403);
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
        break;
}

// ==============================================
// HANDLER: Get Game State
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
            SELECT
                m.id,
                m.room_code,
                m.status,
                m.current_turn_id,
                m.dice_value,
                m.turn_number,
                m.entry_fee,
                m.prize_pool,
                m.board_state,
                m.player1_id,
                m.player2_id,
                m.player1_name,
                m.player2_name,
                m.winner_id,
                m.winning_amount,
                m.updated_at,
                u1.username as player1_username,
                u2.username as player2_username,
                (SELECT COUNT(*) FROM game_actions WHERE match_id = m.id) as action_count
            FROM matches m
            LEFT JOIN users u1 ON m.player1_id = u1.id
            LEFT JOIN users u2 ON m.player2_id = u2.id
            WHERE m.id = :match_id
        ");
        $stmt->execute([':match_id' => $matchId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$match) {
            jsonResponse(false, 'Match not found', [], 404);
        }

        if ($match['player1_id'] != $userId && $match['player2_id'] != $userId) {
            jsonResponse(false, 'Not authorized for this match', [], 403);
        }

        $boardState = json_decode($match['board_state'] ?? '{}', true);

        jsonResponse(true, 'Game state retrieved', [
            'match' => [
                'id' => intval($match['id']),
                'room_code' => $match['room_code'],
                'status' => $match['status'],
                'current_turn' => intval($match['current_turn_id']),
                'dice_value' => intval($match['dice_value']),
                'turn_number' => intval($match['turn_number']),
                'entry_fee' => floatval($match['entry_fee']),
                'prize_pool' => floatval($match['prize_pool']),
                'winner_id' => $match['winner_id'] ? intval($match['winner_id']) : null,
                'winning_amount' => $match['winning_amount'] ? floatval($match['winning_amount']) : null,
                'is_my_turn' => intval($match['current_turn_id']) === $userId,
            ],
            'players' => [
                'player1' => [
                    'id' => intval($match['player1_id']),
                    'name' => $match['player1_name'] ?? $match['player1_username'],
                    'is_me' => $match['player1_id'] == $userId,
                ],
                'player2' => [
                    'id' => intval($match['player2_id']),
                    'name' => $match['player2_name'] ?? $match['player2_username'],
                    'is_me' => $match['player2_id'] == $userId,
                ],
            ],
            'board' => $boardState,
            'updated_at' => $match['updated_at'],
            'action_count' => intval($match['action_count']),
        ]);

    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Roll Dice (SERVER AUTHORITY)
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

        // Lock match for update
        $stmt = $conn->prepare("
            SELECT
                id, status, current_turn_id, player1_id, player2_id,
                board_state, turn_number, prize_pool, entry_fee, room_code
            FROM matches
            WHERE id = :match_id
            FOR UPDATE
        ");
        $stmt->execute([':match_id' => $matchId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$match) {
            $db->rollback();
            jsonResponse(false, 'Match not found', [], 404);
        }

        if ($match['status'] !== 'playing' && $match['status'] !== 'ready') {
            $db->rollback();
            jsonResponse(false, 'Match is not in playable state', [], 400);
        }

        if ($match['status'] === 'completed') {
            $db->rollback();
            jsonResponse(false, 'Match already completed', [], 400);
        }

        if (intval($match['current_turn_id']) !== $userId) {
            $db->rollback();
            jsonResponse(false, 'Not your turn', [], 403);
        }

        // Check if player is in match
        if ($match['player1_id'] != $userId && $match['player2_id'] != $userId) {
            $db->rollback();
            jsonResponse(false, 'Player not in this match', [], 403);
        }

        // SERVER GENERATES DICE VALUE - NOT CLIENT
        $diceValue = rand(1, 6);
        $extraTurn = ($diceValue === 6);

        // Check consecutive sixes (anti-cheat)
        $stmt = $conn->prepare("
            SELECT COUNT(*) as six_count
            FROM game_actions
            WHERE match_id = :match_id
            AND user_id = :user_id
            AND action_type = 'dice_roll'
            AND dice_value = 6
            AND created_at > DATE_SUB(NOW(), INTERVAL 10 SECOND)
            ORDER BY id DESC
            LIMIT 3
        ");
        $stmt->execute([
            ':match_id' => $matchId,
            ':user_id' => $userId
        ]);
        $sixCount = intval($stmt->fetchColumn());

        if ($sixCount >= 2 && $diceValue === 6) {
            // Skip extra turn after 3 consecutive sixes
            $extraTurn = false;
            $diceValue = rand(1, 5);
        }

        // Determine next turn
        $nextTurnId = $extraTurn ? $userId : (
            $match['player1_id'] == $userId ? $match['player2_id'] : $match['player1_id']
        );

        // Update match
        $stmt = $conn->prepare("
            UPDATE matches
            SET
                dice_value = :dice_value,
                current_turn_id = :next_turn_id,
                turn_number = turn_number + 1,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :match_id
        ");
        $stmt->execute([
            ':dice_value' => $diceValue,
            ':next_turn_id' => $nextTurnId,
            ':match_id' => $matchId
        ]);

        // Log action
        $stmt = $conn->prepare("
            INSERT INTO game_actions (
                match_id,
                user_id,
                action_type,
                dice_value,
                metadata,
                created_at
            ) VALUES (
                :match_id,
                :user_id,
                'dice_roll',
                :dice_value,
                :metadata,
                CURRENT_TIMESTAMP
            )
        ");
        $stmt->execute([
            ':match_id' => $matchId,
            ':user_id' => $userId,
            ':dice_value' => $diceValue,
            ':metadata' => json_encode([
                'extra_turn' => $extraTurn,
                'next_turn' => $nextTurnId,
                'six_count' => $sixCount
            ])
        ]);

        $actionId = $conn->lastInsertId();

        $db->commit();

        jsonResponse(true, 'Dice rolled successfully', [
            'match_id' => $matchId,
            'dice_value' => $diceValue,
            'extra_turn' => $extraTurn,
            'next_turn' => $nextTurnId,
            'action_id' => $actionId,
            'turn_number' => $match['turn_number'] + 1,
        ]);

    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Move Token (SERVER VALIDATES)
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

        // Lock match
        $stmt = $conn->prepare("
            SELECT
                id, status, current_turn_id, player1_id, player2_id,
                board_state, dice_value
            FROM matches
            WHERE id = :match_id
            FOR UPDATE
        ");
        $stmt->execute([':match_id' => $matchId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$match) {
            $db->rollback();
            jsonResponse(false, 'Match not found', [], 404);
        }

        if ($match['status'] !== 'playing') {
            $db->rollback();
            jsonResponse(false, 'Match is not in playing state', [], 400);
        }

        if (intval($match['current_turn_id']) !== $userId) {
            $db->rollback();
            jsonResponse(false, 'Not your turn', [], 403);
        }

        if ($match['dice_value'] <= 0) {
            $db->rollback();
            jsonResponse(false, 'Roll dice first', [], 400);
        }

        // Determine player number
        $playerNumber = ($match['player1_id'] == $userId) ? 1 : 2;
        $boardState = json_decode($match['board_state'] ?? '{}', true);

        // Validate the move on server
        $validMove = validateTokenMove($boardState, $playerNumber, $tokenNumber, $match['dice_value'], $targetPosition);

        if (!$validMove) {
            $db->rollback();
            jsonResponse(false, 'Invalid move - Server validation failed', [], 400);
        }

        // Update board state
        $playerKey = 'player' . $playerNumber;
        $tokenKey = 'token' . $tokenNumber;

        if (!isset($boardState[$playerKey])) {
            $boardState[$playerKey] = [];
        }

        $boardState[$playerKey][$tokenKey] = $targetPosition;

        // Check if player has won
        $winnerId = checkWinner($boardState, $matchId);

        if ($winnerId) {
            // Complete match - SERVER DECLARES WINNER
            $stmt = $conn->prepare("
                UPDATE matches
                SET
                    status = 'completed',
                    winner_id = :winner_id,
                    winning_amount = :winning_amount,
                    completed_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :match_id
            ");
            $stmt->execute([
                ':winner_id' => $winnerId,
                ':winning_amount' => floatval($match['prize_pool']),
                ':match_id' => $matchId
            ]);

            // BUG FIX: Commit the outer transaction BEFORE calling processSettlement.
            // processSettlement opens its own beginTransaction() — calling it while an
            // active transaction is open causes a nested-transaction error with PDO/MySQL.
            $db->commit();

            // Process settlement (runs in its own transaction)
            processSettlement($winnerId, $matchId, floatval($match['prize_pool']));

            jsonResponse(true, 'Game completed! Winner declared by server.', [
                'match_id' => $matchId,
                'winner_id' => $winnerId,
                'winning_amount' => floatval($match['prize_pool']),
                'game_over' => true,
                'board_state' => $boardState,
            ]);

            return;
        }

        // Update board state in database
        $stmt = $conn->prepare("
            UPDATE matches
            SET
                board_state = :board_state,
                dice_value = 0,
                current_turn_id = (
                    CASE
                        WHEN current_turn_id = player1_id THEN player2_id
                        WHEN current_turn_id = player2_id THEN player1_id
                    END
                ),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :match_id
        ");
        $stmt->execute([
            ':board_state' => json_encode($boardState),
            ':match_id' => $matchId
        ]);

        // Log action
        $stmt = $conn->prepare("
            INSERT INTO game_actions (
                match_id,
                user_id,
                action_type,
                token_number,
                from_position,
                to_position,
                metadata,
                created_at
            ) VALUES (
                :match_id,
                :user_id,
                'token_move',
                :token_number,
                :from_position,
                :to_position,
                :metadata,
                CURRENT_TIMESTAMP
            )
        ");
        $stmt->execute([
            ':match_id' => $matchId,
            ':user_id' => $userId,
            ':token_number' => $tokenNumber,
            ':from_position' => $boardState[$playerKey][$tokenKey] ?? -1,
            ':to_position' => $targetPosition,
            ':metadata' => json_encode(['player' => $playerNumber])
        ]);

        $db->commit();

        jsonResponse(true, 'Token moved successfully', [
            'match_id' => $matchId,
            'board_state' => $boardState,
            'player_number' => $playerNumber,
            'token_number' => $tokenNumber,
            'new_position' => $targetPosition,
        ]);

    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// VALIDATION FUNCTIONS
// ==============================================

/**
 * Validate token move on server
 */
function validateTokenMove(array $boardState, int $playerNumber, int $tokenNumber, int $diceValue, int $targetPosition): bool
{
    $playerKey = 'player' . $playerNumber;
    $tokenKey = 'token' . $tokenNumber;

    // Get current position
    $currentPosition = $boardState[$playerKey][$tokenKey] ?? -1;

    // If token is at home (-1), must roll 6 to enter
    if ($currentPosition === -1 && $diceValue !== 6) {
        return false;
    }

    // If token is at home and dice is 6, can enter at position 0
    if ($currentPosition === -1 && $diceValue === 6) {
        return $targetPosition === 0;
    }

    // Calculate expected position
    $expectedPosition = $currentPosition + $diceValue;

    // Check if target position matches expected
    if ($targetPosition !== $expectedPosition) {
        return false;
    }

    // Check if position is within valid range (0-56 for home stretch)
    if ($targetPosition < 0 || $targetPosition > 56) {
        return false;
    }

    // Check for collisions (can't land on own token)
    $ownTokens = $boardState[$playerKey] ?? [];
    foreach ($ownTokens as $otherToken => $position) {
        if ($otherToken !== $tokenKey && $position === $targetPosition && $position !== -1) {
            // Can't land on own token
            return false;
        }
    }

    return true;
}

/**
 * Check if player has won (all 4 tokens at position 56+ or home)
 */
function checkWinner(array $boardState, int $matchId): ?int
{
    $player1Tokens = $boardState['player1'] ?? [];
    $player2Tokens = $boardState['player2'] ?? [];

    $player1Home = 0;
    $player2Home = 0;

    foreach ($player1Tokens as $position) {
        if ($position >= 56 || $position === -2) { // -2 means home
            $player1Home++;
        }
    }

    foreach ($player2Tokens as $position) {
        if ($position >= 56 || $position === -2) {
            $player2Home++;
        }
    }

    if ($player1Home >= 4) {
        return getMatchPlayerId($matchId, 1);
    }

    if ($player2Home >= 4) {
        return getMatchPlayerId($matchId, 2);
    }

    return null;
}

/**
 * Get user ID for player number in match
 */
function getMatchPlayerId(int $matchId, int $playerNumber): ?int
{
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();

        $stmt = $conn->prepare("
            SELECT player1_id, player2_id
            FROM matches
            WHERE id = :match_id
        ");
        $stmt->execute([':match_id' => $matchId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$match) {
            return null;
        }

        return $playerNumber === 1 ? intval($match['player1_id']) : intval($match['player2_id']);

    } catch (Exception $e) {
        return null;
    }
}

/**
 * Process settlement server-side
 */
function processSettlement(int $winnerId, int $matchId, float $amount): void
{
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();

        $db->beginTransaction();

        // Lock winner row
        $stmt = $conn->prepare("
            SELECT wallet_balance, total_earnings, total_matches_played
            FROM users
            WHERE id = :user_id
            FOR UPDATE
        ");
        $stmt->execute([':user_id' => $winnerId]);
        $winner = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$winner) {
            $db->rollback();
            return;
        }

        // Calculate TDS (30% on winnings > ₹10,000 per financial year)
        $tdsAmount = 0;
        if ($amount > TDS_THRESHOLD) {
            $tdsAmount = round($amount * (TDS_RATE / 100), 2);
            $netAmount = $amount - $tdsAmount;
        } else {
            $netAmount = $amount;
        }

        // Credit winner
        $stmt = $conn->prepare("
            UPDATE users
            SET
                wallet_balance = wallet_balance + :net_amount,
                total_earnings = total_earnings + :amount,
                total_matches_played = total_matches_played + 1,
                total_matches_won = total_matches_won + 1,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :winner_id
        ");
        $stmt->execute([
            ':net_amount' => $netAmount,
            ':amount' => $amount,
            ':winner_id' => $winnerId
        ]);

        // Record transaction
        $orderId = 'WIN-' . strtoupper(uniqid());
        $stmt = $conn->prepare("
            INSERT INTO transactions (
                user_id,
                match_id,
                amount,
                type,
                source,
                description,
                order_id,
                status,
                balance_before,
                balance_after,
                tds_deducted,
                metadata,
                created_at
            ) VALUES (
                :user_id,
                :match_id,
                :amount,
                'credit',
                'match_win',
                :description,
                :order_id,
                'success',
                :balance_before,
                :balance_after,
                :tds,
                :metadata,
                CURRENT_TIMESTAMP
            )
        ");
        $stmt->execute([
            ':user_id' => $winnerId,
            ':match_id' => $matchId,
            ':amount' => $amount,
            ':description' => "Match win settlement - Match #{$matchId}",
            ':order_id' => $orderId,
            ':balance_before' => floatval($winner['wallet_balance']),
            ':balance_after' => floatval($winner['wallet_balance']) + $netAmount,
            ':tds' => $tdsAmount,
            ':metadata' => json_encode([
                'winner_id' => $winnerId,
                'match_id' => $matchId,
                'tds_deducted' => $tdsAmount,
                'gross_amount' => $amount,
                'net_amount' => $netAmount
            ])
        ]);

        $db->commit();

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        // Log error but don't fail the request
        error_log('Settlement failed: ' . $e->getMessage());
    }
}

// ==============================================
// HANDLER: Get Match History
// ==============================================
function handleGetMatchHistory(int $userId): void
{
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
    $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;

    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();

        $stmt = $conn->prepare("
            SELECT COUNT(*) as total
            FROM matches
            WHERE (player1_id = :user_id OR player2_id = :user_id)
            AND status IN ('completed', 'cancelled')
        ");
        $stmt->execute([':user_id' => $userId]);
        $total = intval($stmt->fetchColumn());

        $stmt = $conn->prepare("
            SELECT
                id,
                room_code,
                entry_fee,
                prize_pool,
                status,
                player1_name,
                player2_name,
                winner_id,
                winning_amount,
                turn_number,
                created_at,
                completed_at
            FROM matches
            WHERE (player1_id = :user_id OR player2_id = :user_id)
            AND status IN ('completed', 'cancelled')
            ORDER BY created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':limit' => $limit,
            ':offset' => $offset
        ]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse(true, 'Match history retrieved', [
            'matches' => $matches,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ]);

    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}
