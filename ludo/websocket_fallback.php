<?php
/**
 * ======================================================
 * WEBSOCKET_FALLBACK.PHP - PHP Polling Alternative (FIXED)
 * Ludo Tournament Platform - No Node.js Required
 * Version: 2.0.0 - SESSION LOCK FIX + LONG-POLL FIX
 * ======================================================
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once dirname(__DIR__) . '/config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Access-Control-Allow-Origin: ' . BASE_URL);
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-CSRF-Token');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'poll':
        handlePoll();
        break;
    case 'broadcast':
        handleBroadcast();
        break;
    case 'roll':
        handleRollDice();
        break;
    case 'move':
        handleMoveToken();
        break;
    case 'get_room':
        handleGetRoom();
        break;
    case 'check_updates':
        handleCheckUpdates();
        break;
    default:
        jsonResponse(false, 'Invalid action', [], 400);
}

// ==============================================
// LONG-POLL FOR UPDATES
// ==============================================
function handlePoll() {
    if (!isLoggedIn()) {
        jsonResponse(false, 'Not authenticated', [], 401);
    }
    
    $userId = getCurrentUserId();
    $matchId = intval($_GET['match_id'] ?? 0);
    $lastSync = intval($_GET['last_sync'] ?? 0);
    $timeout = min(intval($_GET['timeout'] ?? 30), 30); // Max 30 seconds
    
    if ($matchId <= 0) {
        jsonResponse(false, 'Invalid match ID', [], 400);
    }
    
    try {
        // FIXED: Release session lock before long-polling
        session_write_close();
        
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $startTime = time();
        $updates = [];
        
        // Long-poll loop
        while ((time() - $startTime) < $timeout) {
            // Check for new game actions
            $stmt = $conn->prepare("
                SELECT id, action_type, dice_value, token_number,
                       from_position, to_position, opponent_captured,
                       created_at, UNIX_TIMESTAMP(created_at) as timestamp
                FROM game_actions
                WHERE match_id = :mid AND id > :last
                ORDER BY id ASC LIMIT 50
            ");
            $stmt->execute([':mid' => $matchId, ':last' => $lastSync]);
            $newActions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($newActions)) {
                $updates = $newActions;
                $lastSync = end($updates)['id'];
                break;
            }
            
            // Check match status change
            $stmt = $conn->prepare("
                SELECT status, current_turn_id, updated_at,
                       UNIX_TIMESTAMP(updated_at) as update_ts
                FROM matches WHERE id = :mid
            ");
            $stmt->execute([':mid' => $matchId]);
            $match = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($match && intval($match['update_ts'] ?? 0) > $startTime) {
                $updates[] = [
                    'type' => 'match_update',
                    'status' => $match['status'],
                    'current_turn' => $match['current_turn_id']
                ];
                break;
            }
            
            // Wait 200ms before next check
            usleep(200000);
        }
        
        jsonResponse(true, 'Poll results', [
            'updates' => $updates ?: [],
            'last_sync' => $lastSync,
            'has_updates' => !empty($updates),
            'poll_time' => time() - $startTime
        ]);
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// BROADCAST ACTION
// ==============================================
function handleBroadcast() {
    if (!isLoggedIn()) {
        jsonResponse(false, 'Not authenticated', [], 401);
    }
    
    $userId = getCurrentUserId();
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['match_id'])) {
        jsonResponse(false, 'Missing match ID', [], 400);
    }
    
    $matchId = intval($input['match_id']);
    $actionType = $input['action_type'] ?? 'custom';
    $data = $input['data'] ?? [];
    
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!CSRFToken::validate($csrfToken)) {
        jsonResponse(false, 'Invalid CSRF token', [], 403);
    }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        // Verify user is in match
        $stmt = $conn->prepare("
            SELECT id FROM matches
            WHERE id = :mid AND (player1_id = :uid OR player2_id = :uid)
        ");
        $stmt->execute([':mid' => $matchId, ':uid' => $userId]);
        if (!$stmt->fetch()) {
            jsonResponse(false, 'Not authorized for this match', [], 403);
        }
        
        // Store action
        $stmt = $conn->prepare("
            INSERT INTO game_actions (
                match_id, user_id, action_type,
                dice_value, token_number, from_position,
                to_position, opponent_captured,
                metadata, created_at
            ) VALUES (
                :mid, :uid, :atype,
                :dice, :token, :from_pos,
                :to_pos, :captured,
                :meta, CURRENT_TIMESTAMP
            )
        ");
        $stmt->execute([
            ':mid' => $matchId,
            ':uid' => $userId,
            ':atype' => $actionType,
            ':dice' => $data['dice_value'] ?? 0,
            ':token' => $data['token_number'] ?? 0,
            ':from_pos' => $data['from_position'] ?? 0,
            ':to_pos' => $data['to_position'] ?? 0,
            ':captured' => $data['opponent_captured'] ?? 0,
            ':meta' => json_encode($data)
        ]);
        
        $actionId = $conn->lastInsertId();
        
        jsonResponse(true, 'Action broadcasted', [
            'action_id' => $actionId,
            'match_id' => $matchId
        ]);
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// ROLL DICE (SERVER-SIDE)
// ==============================================
function handleRollDice() {
    if (!isLoggedIn()) {
        jsonResponse(false, 'Not authenticated', [], 401);
    }
    
    $userId = getCurrentUserId();
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['match_id'])) {
        jsonResponse(false, 'Missing match ID', [], 400);
    }
    
    $matchId = intval($input['match_id']);
    
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!CSRFToken::validate($csrfToken)) {
        jsonResponse(false, 'Invalid CSRF token', [], 403);
    }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $db->beginTransaction();
        
        // Get match with lock
        $stmt = $conn->prepare("
            SELECT id, status, current_turn_id, player1_id, player2_id
            FROM matches WHERE id = :mid FOR UPDATE
        ");
        $stmt->execute([':mid' => $matchId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$match) {
            $db->rollback();
            jsonResponse(false, 'Match not found', [], 404);
        }
        
        if (!in_array($match['status'], ['playing', 'ready'])) {
            $db->rollback();
            jsonResponse(false, 'Match not in playable state', [], 400);
        }
        
        if (intval($match['current_turn_id']) !== $userId) {
            $db->rollback();
            jsonResponse(false, 'Not your turn', [], 403);
        }
        
        // Generate dice value
        $diceValue = rand(1, 6);
        $extraTurn = ($diceValue === 6);
        
        // Update match
        $stmt = $conn->prepare("
            UPDATE matches
            SET dice_value = :dice,
                last_dice_roll_time = CURRENT_TIMESTAMP,
                turn_number = turn_number + 1,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :mid
        ");
        $stmt->execute([':dice' => $diceValue, ':mid' => $matchId]);
        
        // Determine next turn
        if (!$extraTurn) {
            $player1Id = intval($match['player1_id']);
            $player2Id = intval($match['player2_id']);
            $nextTurnId = (intval($match['current_turn_id']) === $player1Id) ? $player2Id : $player1Id;
            
            $stmt = $conn->prepare("UPDATE matches SET current_turn_id = :next WHERE id = :mid");
            $stmt->execute([':next' => $nextTurnId, ':mid' => $matchId]);
        }
        
        // Record action
        $stmt = $conn->prepare("
            INSERT INTO game_actions (match_id, user_id, action_type, dice_value, metadata, created_at)
            VALUES (:mid, :uid, 'dice_roll', :dice, :meta, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            ':mid' => $matchId,
            ':uid' => $userId,
            ':dice' => $diceValue,
            ':meta' => json_encode(['extra_turn' => $extraTurn])
        ]);
        
        $db->commit();
        
        jsonResponse(true, 'Dice rolled', [
            'dice_value' => $diceValue,
            'extra_turn' => $extraTurn,
            'match_id' => $matchId,
            'action_id' => $conn->lastInsertId()
        ]);
        
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollback();
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// MOVE TOKEN
// ==============================================
function handleMoveToken() {
    if (!isLoggedIn()) {
        jsonResponse(false, 'Not authenticated', [], 401);
    }
    
    $userId = getCurrentUserId();
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['match_id']) || !isset($input['token_number'])) {
        jsonResponse(false, 'Missing required fields', [], 400);
    }
    
    $matchId = intval($input['match_id']);
    $tokenNumber = intval($input['token_number']);
    $fromPosition = intval($input['from_position'] ?? -1);
    $toPosition = intval($input['to_position'] ?? -1);
    
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!CSRFToken::validate($csrfToken)) {
        jsonResponse(false, 'Invalid CSRF token', [], 403);
    }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $db->beginTransaction();
        
        // Verify match and turn
        $stmt = $conn->prepare("
            SELECT id, status, current_turn_id FROM matches
            WHERE id = :mid FOR UPDATE
        ");
        $stmt->execute([':mid' => $matchId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$match) {
            $db->rollback();
            jsonResponse(false, 'Match not found', [], 404);
        }
        
        if (intval($match['current_turn_id']) !== $userId) {
            $db->rollback();
            jsonResponse(false, 'Not your turn', [], 403);
        }
        
        // Record token move
        $stmt = $conn->prepare("
            INSERT INTO game_actions (
                match_id, user_id, action_type,
                token_number, from_position, to_position,
                metadata, created_at
            ) VALUES (
                :mid, :uid, 'token_move',
                :token, :from_pos, :to_pos,
                :meta, CURRENT_TIMESTAMP
            )
        ");
        $stmt->execute([
            ':mid' => $matchId,
            ':uid' => $userId,
            ':token' => $tokenNumber,
            ':from_pos' => $fromPosition,
            ':to_pos' => $toPosition,
            ':meta' => json_encode($input)
        ]);
        
        $db->commit();
        
        jsonResponse(true, 'Token moved', [
            'match_id' => $matchId,
            'action_id' => $conn->lastInsertId()
        ]);
        
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollback();
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// GET ROOM STATE
// ==============================================
function handleGetRoom() {
    if (!isLoggedIn()) {
        jsonResponse(false, 'Not authenticated', [], 401);
    }
    
    $matchId = intval($_GET['match_id'] ?? 0);
    
    if ($matchId <= 0) {
        jsonResponse(false, 'Invalid match ID', [], 400);
    }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("
            SELECT id, room_code, entry_fee, prize_pool, status,
                   player1_id, player2_id, player1_name, player2_name,
                   current_turn_id, dice_value, turn_number,
                   p1_token1, p1_token2, p1_token3, p1_token4, p1_home_count,
                   p2_token1, p2_token2, p2_token3, p2_token4, p2_home_count,
                   created_at, started_at, completed_at
            FROM matches WHERE id = :mid
        ");
        $stmt->execute([':mid' => $matchId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$match) {
            jsonResponse(false, 'Match not found', [], 404);
        }
        
        jsonResponse(true, 'Room state retrieved', ['match' => $match]);
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// CHECK UPDATES (Short Polling)
// ==============================================
function handleCheckUpdates() {
    if (!isLoggedIn()) {
        jsonResponse(false, 'Not authenticated', [], 401);
    }
    
    $matchId = intval($_GET['match_id'] ?? 0);
    $lastCheck = intval($_GET['last_check'] ?? 0);
    
    if ($matchId <= 0) {
        jsonResponse(false, 'Invalid match ID', [], 400);
    }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        // Check for new actions
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM game_actions
            WHERE match_id = :mid AND id > :last
        ");
        $stmt->execute([':mid' => $matchId, ':last' => $lastCheck]);
        $count = $stmt->fetchColumn();
        
        // Check match status
        $stmt = $conn->prepare("
            SELECT status, updated_at, UNIX_TIMESTAMP(updated_at) as update_ts
            FROM matches WHERE id = :mid
        ");
        $stmt->execute([':mid' => $matchId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        
        jsonResponse(true, 'Update check', [
            'has_updates' => ($count > 0),
            'new_action_count' => intval($count),
            'match_status' => $match['status'] ?? 'unknown',
            'last_updated' => intval($match['update_ts'] ?? time())
        ]);
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error', [], 500);
    }
}
?>
