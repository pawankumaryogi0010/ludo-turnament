<?php
/**
 * ======================================================
 * GAME_SYNC.PHP - Game State Sync Endpoint
 * Ludo Tournament Platform - Save Game State
 * Version: 1.0.0
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

if (!isLoggedIn()) {
    jsonResponse(false, 'Not authenticated', [], 401);
}

$userId = getCurrentUserId();
$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

switch ($action) {
    case 'save_state':
        handleSaveState();
        break;
    case 'get_state':
        handleGetState();
        break;
    default:
        jsonResponse(false, 'Invalid action', [], 400);
        break;
}

function handleSaveState() {
    global $userId;
    
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
        
        // BUG FIX: Fetch full match row (not just id) so we can validate current_turn below
        $stmt = $conn->prepare("
            SELECT id, player1_id, player2_id, status FROM matches
            WHERE id = :match_id
            AND (player1_id = :user_id OR player2_id = :user_id)
            FOR UPDATE
        ");
        $stmt->execute([
            ':match_id' => $matchId,
            ':user_id' => $userId
        ]);
        
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$match) {
            $db->rollback();
            jsonResponse(false, 'Not authorized for this match', [], 403);
        }
        
        // BUG FIX: Client was allowed to set ANY status (including 'completed') with no
        // server-side validation. Whitelist the allowed in-progress statuses only.
        $allowedStatuses = ['playing', 'paused'];
        $requestedStatus = $input['status'] ?? 'playing';
        $safeStatus = in_array($requestedStatus, $allowedStatuses, true) ? $requestedStatus : 'playing';

        // BUG FIX: Validate current_turn belongs to the match
        $validTurns = [$match['player1_id'] ?? 0, $match['player2_id'] ?? 0];
        $requestedTurn = intval($input['current_turn'] ?? 0);
        $safeTurn = in_array($requestedTurn, $validTurns, true) ? $requestedTurn : ($validTurns[0] ?? 0);

        // Update match with game state
        $stmt = $conn->prepare("
            UPDATE matches
            SET 
                p1_token1 = :p1_token1,
                p1_token2 = :p1_token2,
                p1_token3 = :p1_token3,
                p1_token4 = :p1_token4,
                p1_home_count = :p1_home_count,
                p2_token1 = :p2_token1,
                p2_token2 = :p2_token2,
                p2_token3 = :p2_token3,
                p2_token4 = :p2_token4,
                p2_home_count = :p2_home_count,
                current_turn_id = :current_turn,
                dice_value = :dice_value,
                turn_number = :turn_number,
                status = :status,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :match_id
        ");
        $stmt->execute([
            ':p1_token1' => $input['p1_tokens'][0] ?? -1,
            ':p1_token2' => $input['p1_tokens'][1] ?? -1,
            ':p1_token3' => $input['p1_tokens'][2] ?? -1,
            ':p1_token4' => $input['p1_tokens'][3] ?? -1,
            ':p1_home_count' => $input['p1_home_count'] ?? 0,
            ':p2_token1' => $input['p2_tokens'][0] ?? -1,
            ':p2_token2' => $input['p2_tokens'][1] ?? -1,
            ':p2_token3' => $input['p2_tokens'][2] ?? -1,
            ':p2_token4' => $input['p2_tokens'][3] ?? -1,
            ':p2_home_count' => $input['p2_home_count'] ?? 0,
            ':current_turn' => $safeTurn,
            ':dice_value' => $input['dice_value'] ?? 0,
            ':turn_number' => $input['turn_number'] ?? 0,
            ':status' => $safeStatus,
            ':match_id' => $matchId
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

function handleGetState() {
    global $userId;
    
    $matchId = isset($_GET['match_id']) ? intval($_GET['match_id']) : 0;
    
    if ($matchId <= 0) {
        jsonResponse(false, 'Invalid match ID', [], 400);
    }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("
            SELECT 
                id, room_code, status, current_turn_id, dice_value, turn_number,
                p1_token1, p1_token2, p1_token3, p1_token4, p1_home_count,
                p2_token1, p2_token2, p2_token3, p2_token4, p2_home_count
            FROM matches
            WHERE id = :match_id
        ");
        $stmt->execute([':match_id' => $matchId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$match) {
            jsonResponse(false, 'Match not found', [], 404);
        }
        
        jsonResponse(true, 'Game state retrieved', [
            'match' => $match
        ]);
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}
?>
