<?php
/**
 * ======================================================
 * GAME_SYNC.PHP - Game State Sync Endpoint (FINAL FIXED)
 * Ludo Tournament Platform - Save/Load Game State
 * Version: 2.0.0 - AUTH CHECK + ALL BUGS FIXED
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

// AUTHENTICATION REQUIRED FOR ALL ACTIONS
if (!isLoggedIn()) {
    jsonResponse(false, 'Not authenticated', [], 401);
}

$userId = getCurrentUserId();
if (!$userId) {
    jsonResponse(false, 'Invalid session', [], 401);
}

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

switch ($action) {
    case 'save_state':
        handleSaveState($userId);
        break;
    case 'get_state':
        handleGetState($userId);
        break;
    default:
        jsonResponse(false, 'Invalid action', [], 400);
}

// ==============================================
// SAVE GAME STATE
// ==============================================
function handleSaveState(int $userId): void
{
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
        
        // Verify user is in this match
        $stmt = $conn->prepare("
            SELECT id, player1_id, player2_id, status 
            FROM matches
            WHERE id = :mid 
            AND (player1_id = :uid OR player2_id = :uid)
            FOR UPDATE
        ");
        $stmt->execute([':mid' => $matchId, ':uid' => $userId]);
        
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$match) {
            $db->rollback();
            jsonResponse(false, 'Not authorized for this match', [], 403);
        }
        
        // FIXED: Whitelist allowed statuses
        $allowedStatuses = ['playing', 'paused'];
        $requestedStatus = $input['status'] ?? 'playing';
        $safeStatus = in_array($requestedStatus, $allowedStatuses, true) ? $requestedStatus : 'playing';

        // FIXED: Validate current_turn belongs to this match
        $player1Id = intval($match['player1_id'] ?? 0);
        $player2Id = intval($match['player2_id'] ?? 0);
        $validTurns = [$player1Id, $player2Id];
        $requestedTurn = intval($input['current_turn'] ?? 0);
        $safeTurn = in_array($requestedTurn, $validTurns, true) ? $requestedTurn : $player1Id;

        // Update match with game state
        $stmt = $conn->prepare("
            UPDATE matches
            SET 
                p1_token1 = :p1t1, p1_token2 = :p1t2, p1_token3 = :p1t3, p1_token4 = :p1t4,
                p1_home_count = :p1hc,
                p2_token1 = :p2t1, p2_token2 = :p2t2, p2_token3 = :p2t3, p2_token4 = :p2t4,
                p2_home_count = :p2hc,
                current_turn_id = :turn,
                dice_value = :dice,
                turn_number = :tnum,
                status = :status,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :mid
        ");
        $stmt->execute([
            ':p1t1' => $input['p1_tokens'][0] ?? -1,
            ':p1t2' => $input['p1_tokens'][1] ?? -1,
            ':p1t3' => $input['p1_tokens'][2] ?? -1,
            ':p1t4' => $input['p1_tokens'][3] ?? -1,
            ':p1hc' => $input['p1_home_count'] ?? 0,
            ':p2t1' => $input['p2_tokens'][0] ?? -1,
            ':p2t2' => $input['p2_tokens'][1] ?? -1,
            ':p2t3' => $input['p2_tokens'][2] ?? -1,
            ':p2t4' => $input['p2_tokens'][3] ?? -1,
            ':p2hc' => $input['p2_home_count'] ?? 0,
            ':turn' => $safeTurn,
            ':dice' => $input['dice_value'] ?? 0,
            ':tnum' => $input['turn_number'] ?? 0,
            ':status' => $safeStatus,
            ':mid' => $matchId
        ]);
        
        $db->commit();
        
        jsonResponse(true, 'Game state saved', [
            'match_id' => $matchId,
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// GET GAME STATE (FIXED: Added authorization)
// ==============================================
function handleGetState(int $userId): void
{
    $matchId = isset($_GET['match_id']) ? intval($_GET['match_id']) : 0;
    
    if ($matchId <= 0) {
        jsonResponse(false, 'Invalid match ID', [], 400);
    }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        // FIXED: Verify user is in this match before returning state
        $stmt = $conn->prepare("
            SELECT 
                id, room_code, status, current_turn_id, dice_value, turn_number,
                player1_id, player2_id,
                p1_token1, p1_token2, p1_token3, p1_token4, p1_home_count,
                p2_token1, p2_token2, p2_token3, p2_token4, p2_home_count
            FROM matches
            WHERE id = :mid
        ");
        $stmt->execute([':mid' => $matchId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$match) {
            jsonResponse(false, 'Match not found', [], 404);
        }
        
        // FIXED: Authorization check - user must be in this match
        $player1Id = intval($match['player1_id'] ?? 0);
        $player2Id = intval($match['player2_id'] ?? 0);
        
        if ($userId !== $player1Id && $userId !== $player2Id) {
            jsonResponse(false, 'Not authorized to view this match', [], 403);
        }
        
        jsonResponse(true, 'Game state retrieved', [
            'match' => $match
        ]);
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}
?>
