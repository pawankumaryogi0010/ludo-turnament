<?php
/**
 * ======================================================
 * GAME_STATE.PHP - Real-time Game State Sync
 * Ludo Tournament Platform - Polling Endpoint
 * Version: 1.0.0
 * ======================================================
 */

// Prevent direct access
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

// Include configuration
require_once dirname(__DIR__) . '/config/db.php';

// Set headers for JSON response
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// ==============================================
// CORS Headers (Adjust for production)
// ==============================================
header('Access-Control-Allow-Origin: ' . BASE_URL);
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-CSRF-Token');
header('Access-Control-Allow-Credentials: true');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ==============================================
// INPUT VALIDATION
// ==============================================
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    // Try GET parameters
    $matchId = isset($_GET['match_id']) ? intval($_GET['match_id']) : 0;
    $userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
    $lastSync = isset($_GET['last_sync']) ? intval($_GET['last_sync']) : 0;
} else {
    $matchId = isset($input['match_id']) ? intval($input['match_id']) : 0;
    $userId = isset($input['user_id']) ? intval($input['user_id']) : 0;
    $lastSync = isset($input['last_sync']) ? intval($input['last_sync']) : 0;
}

// Validate required fields
if ($matchId <= 0) {
    jsonResponse(false, 'Invalid match ID', [], 400);
}

// Validate user authentication
if (!isLoggedIn()) {
    jsonResponse(false, 'User not authenticated', [], 401);
}

$currentUserId = getCurrentUserId();
if (!$currentUserId) {
    jsonResponse(false, 'Invalid user session', [], 401);
}

// If userId not provided in request, use authenticated user
if ($userId <= 0) {
    $userId = $currentUserId;
}

// Validate that user is part of this match
$isAuthorized = false;
$userRole = null;

// ==============================================
// MAIN GAME STATE RETRIEVAL
// ==============================================

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    // ==============================================
    // 1. GET MATCH DETAILS AND VERIFY AUTHORIZATION
    // ==============================================
    $stmt = $conn->prepare("
        SELECT 
            m.id,
            m.tournament_id,
            m.room_code,
            m.entry_fee,
            m.prize_pool,
            m.platform_fee,
            m.player1_id,
            m.player2_id,
            m.player3_id,
            m.player4_id,
            m.player1_name,
            m.player2_name,
            m.player3_name,
            m.player4_name,
            m.status,
            m.current_turn_id,
            m.dice_value,
            m.dice_rolled_by,
            m.last_dice_roll_time,
            m.p1_token1,
            m.p1_token2,
            m.p1_token3,
            m.p1_token4,
            m.p1_home_count,
            m.p2_token1,
            m.p2_token2,
            m.p2_token3,
            m.p2_token4,
            m.p2_home_count,
            m.p3_token1,
            m.p3_token2,
            m.p3_token3,
            m.p3_token4,
            m.p3_home_count,
            m.p4_token1,
            m.p4_token2,
            m.p4_token3,
            m.p4_token4,
            m.p4_home_count,
            m.winner_id,
            m.winner_name,
            m.winning_amount,
            m.turn_number,
            m.max_turns,
            m.started_at,
            m.completed_at,
            m.created_at,
            m.updated_at,
            t.name AS tournament_name,
            t.status AS tournament_status
        FROM matches m
        LEFT JOIN tournaments t ON m.tournament_id = t.id
        WHERE m.id = :match_id
    ");
    $stmt->execute([':match_id' => $matchId]);
    $match = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$match) {
        jsonResponse(false, 'Match not found', [], 404);
    }
    
    // ==============================================
    // 2. CHECK USER AUTHORIZATION
    // ==============================================
    $playerIds = [
        intval($match['player1_id']),
        intval($match['player2_id']),
        intval($match['player3_id']),
        intval($match['player4_id'])
    ];
    
    // Filter out null/zero values
    $playerIds = array_filter($playerIds);
    
    if (!in_array($userId, $playerIds)) {
        jsonResponse(false, 'User not authorized for this match', [], 403);
    }
    
    // Determine user's player number
    if ($userId == $match['player1_id']) {
        $userRole = 'player1';
    } elseif ($userId == $match['player2_id']) {
        $userRole = 'player2';
    } elseif ($userId == $match['player3_id']) {
        $userRole = 'player3';
    } elseif ($userId == $match['player4_id']) {
        $userRole = 'player4';
    }
    
    // ==============================================
    // 3. GET LATEST GAME ACTIONS (for delta sync)
    // ==============================================
    $actions = [];
    if ($lastSync > 0) {
        $stmt = $conn->prepare("
            SELECT 
                id,
                action_type,
                dice_value,
                token_number,
                from_position,
                to_position,
                opponent_captured,
                created_at,
                UNIX_TIMESTAMP(created_at) as timestamp
            FROM game_actions
            WHERE match_id = :match_id
            AND id > :last_sync
            ORDER BY id ASC
            LIMIT 100
        ");
        $stmt->execute([
            ':match_id' => $matchId,
            ':last_sync' => $lastSync
        ]);
        $actions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // ==============================================
    // 4. GET PLAYER DETAILS
    // ==============================================
    $players = [];
    $allPlayerIds = array_filter([
        $match['player1_id'],
        $match['player2_id'],
        $match['player3_id'],
        $match['player4_id']
    ]);
    
    if (!empty($allPlayerIds)) {
        $placeholders = implode(',', array_fill(0, count($allPlayerIds), '?'));
        $stmt = $conn->prepare("
            SELECT 
                id,
                username,
                mobile,
                wallet_balance,
                total_matches_played,
                total_matches_won,
                elo_rating,
                is_verified,
                is_active
            FROM users
            WHERE id IN ({$placeholders})
        ");
        $stmt->execute($allPlayerIds);
        $userData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($userData as $u) {
            $players[$u['id']] = $u;
        }
    }
    
    // ==============================================
    // 5. DETERMINE CURRENT PLAYER'S TURN STATUS
    // ==============================================
    $isMyTurn = ($match['current_turn_id'] == $userId);
    $canRoll = ($match['status'] === 'playing' || $match['status'] === 'ready') && $isMyTurn;
    
    // ==============================================
    // 6. BUILD RESPONSE DATA
    // ==============================================
    $responseData = [
        // Match metadata
        'match' => [
            'id' => intval($match['id']),
            'room_code' => $match['room_code'],
            'tournament_id' => intval($match['tournament_id']),
            'tournament_name' => $match['tournament_name'],
            'entry_fee' => floatval($match['entry_fee']),
            'prize_pool' => floatval($match['prize_pool']),
            'status' => $match['status'],
            'current_turn_id' => intval($match['current_turn_id']),
            'current_turn_name' => getPlayerName($match, $match['current_turn_id']),
            'dice_value' => intval($match['dice_value']),
            'dice_rolled_by' => $match['dice_rolled_by'] ? intval($match['dice_rolled_by']) : null,
            'last_dice_roll_time' => $match['last_dice_roll_time'],
            'turn_number' => intval($match['turn_number']),
            'max_turns' => intval($match['max_turns']),
            'started_at' => $match['started_at'],
            'completed_at' => $match['completed_at'],
            'created_at' => $match['created_at'],
            'updated_at' => $match['updated_at'],
            'is_my_turn' => $isMyTurn,
            'can_roll' => $canRoll,
        ],
        
        // Player data
        'players' => [
            'player1' => [
                'id' => intval($match['player1_id']),
                'name' => $match['player1_name'],
                'username' => isset($players[$match['player1_id']]) ? $players[$match['player1_id']]['username'] : $match['player1_name'],
                'elo_rating' => isset($players[$match['player1_id']]) ? intval($players[$match['player1_id']]['elo_rating']) : 1200,
                'is_verified' => isset($players[$match['player1_id']]) ? boolval($players[$match['player1_id']]['is_verified']) : false,
                'is_me' => ($match['player1_id'] == $userId),
            ],
            'player2' => $match['player2_id'] ? [
                'id' => intval($match['player2_id']),
                'name' => $match['player2_name'],
                'username' => isset($players[$match['player2_id']]) ? $players[$match['player2_id']]['username'] : $match['player2_name'],
                'elo_rating' => isset($players[$match['player2_id']]) ? intval($players[$match['player2_id']]['elo_rating']) : 1200,
                'is_verified' => isset($players[$match['player2_id']]) ? boolval($players[$match['player2_id']]['is_verified']) : false,
                'is_me' => ($match['player2_id'] == $userId),
            ] : null,
            'player3' => $match['player3_id'] ? [
                'id' => intval($match['player3_id']),
                'name' => $match['player3_name'],
                'username' => isset($players[$match['player3_id']]) ? $players[$match['player3_id']]['username'] : $match['player3_name'],
                'elo_rating' => isset($players[$match['player3_id']]) ? intval($players[$match['player3_id']]['elo_rating']) : 1200,
                'is_verified' => isset($players[$match['player3_id']]) ? boolval($players[$match['player3_id']]['is_verified']) : false,
                'is_me' => ($match['player3_id'] == $userId),
            ] : null,
            'player4' => $match['player4_id'] ? [
                'id' => intval($match['player4_id']),
                'name' => $match['player4_name'],
                'username' => isset($players[$match['player4_id']]) ? $players[$match['player4_id']]['username'] : $match['player4_name'],
                'elo_rating' => isset($players[$match['player4_id']]) ? intval($players[$match['player4_id']]['elo_rating']) : 1200,
                'is_verified' => isset($players[$match['player4_id']]) ? boolval($players[$match['player4_id']]['is_verified']) : false,
                'is_me' => ($match['player4_id'] == $userId),
            ] : null,
        ],
        
        // Board state
        'board' => [
            // Player 1 tokens (Red)
            'p1_tokens' => [
                intval($match['p1_token1']),
                intval($match['p1_token2']),
                intval($match['p1_token3']),
                intval($match['p1_token4'])
            ],
            'p1_home_count' => intval($match['p1_home_count']),
            
            // Player 2 tokens (Blue)
            'p2_tokens' => [
                intval($match['p2_token1']),
                intval($match['p2_token2']),
                intval($match['p2_token3']),
                intval($match['p2_token4'])
            ],
            'p2_home_count' => intval($match['p2_home_count']),
            
            // Player 3 tokens (Green) - if applicable
            'p3_tokens' => $match['p3_token1'] !== null ? [
                intval($match['p3_token1']),
                intval($match['p3_token2']),
                intval($match['p3_token3']),
                intval($match['p3_token4'])
            ] : null,
            'p3_home_count' => $match['p3_home_count'] !== null ? intval($match['p3_home_count']) : null,
            
            // Player 4 tokens (Yellow) - if applicable
            'p4_tokens' => $match['p4_token1'] !== null ? [
                intval($match['p4_token1']),
                intval($match['p4_token2']),
                intval($match['p4_token3']),
                intval($match['p4_token4'])
            ] : null,
            'p4_home_count' => $match['p4_home_count'] !== null ? intval($match['p4_home_count']) : null,
        ],
        
        // Winner info
        'winner' => $match['winner_id'] ? [
            'id' => intval($match['winner_id']),
            'name' => $match['winner_name'],
            'amount' => floatval($match['winning_amount']),
            'is_me' => ($match['winner_id'] == $userId),
        ] : null,
        
        // Recent actions (delta sync)
        'actions' => $actions,
        'last_action_id' => !empty($actions) ? intval(end($actions)['id']) : null,
        
        // Polling information
        'poll' => [
            'interval' => 1200,
            'timestamp' => time(),
            'match_updated' => strtotime($match['updated_at']),
        ],
        
        // User role and permissions
        'user' => [
            'id' => $userId,
            'role' => $userRole,
            'is_turn' => $isMyTurn,
            'can_roll' => $canRoll,
        ],
        
        // Client-side sync info
        'sync' => [
            'last_sync' => $lastSync,
            'needs_full_sync' => ($lastSync === 0),
            'actions_count' => count($actions),
        ],
    ];
    
    // ==============================================
    // 7. LOG POLLING ACTIVITY (Debug/Security)
    // ==============================================
    if (rand(1, 20) === 1) { // Log 5% of requests to reduce disk I/O
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'match_id' => $matchId,
            'user_id' => $userId,
            'status' => $match['status'],
            'last_sync' => $lastSync,
            'actions_count' => count($actions),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ];
        
        $logFile = dirname(__DIR__) . '/logs/game_state_polls.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        file_put_contents($logFile, json_encode($logEntry) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
    
    // ==============================================
    // 8. RETURN SUCCESS RESPONSE
    // ==============================================
    jsonResponse(true, 'Game state retrieved successfully', $responseData);
    
} catch (PDOException $e) {
    // Log error
    $errorLog = [
        'timestamp' => date('Y-m-d H:i:s'),
        'error' => $e->getMessage(),
        'code' => $e->getCode(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'match_id' => $matchId ?? null,
        'user_id' => $userId ?? null
    ];
    $logFile = dirname(__DIR__) . '/logs/game_state_errors.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    file_put_contents($logFile, json_encode($errorLog) . PHP_EOL, FILE_APPEND | LOCK_EX);
    
    jsonResponse(false, 'Database error occurred. Please try again.', [], 500);
    
} catch (Exception $e) {
    jsonResponse(false, $e->getMessage(), [], 500);
}

/**
 * ==============================================
 * HELPER: Get player name from match data
 * ==============================================
 */
function getPlayerName($match, $playerId) {
    if (!$playerId) return null;
    
    if ($playerId == $match['player1_id']) return $match['player1_name'];
    if ($playerId == $match['player2_id']) return $match['player2_name'];
    if ($playerId == $match['player3_id']) return $match['player3_name'];
    if ($playerId == $match['player4_id']) return $match['player4_name'];
    
    return 'Unknown Player';
}
?>
