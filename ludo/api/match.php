<?php
/**
 * ======================================================
 * MATCH.PHP - Match Management API
 * Ludo Tournament Platform - Complete Match System
 * Version: 3.0.0 - PRODUCTION READY
 * ======================================================
 */

// Prevent direct access
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once dirname(__DIR__) . '/config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

header('Access-Control-Allow-Origin: ' . BASE_URL);
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-CSRF-Token');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ==============================================
// AUTHENTICATION
// ==============================================
if (!isLoggedIn()) {
    jsonResponse(false, 'Please login to join a match', [], 401);
}

$userId = getCurrentUserId();
if (!$userId) {
    jsonResponse(false, 'Invalid session', [], 401);
}

// ==============================================
// ROUTING
// ==============================================
$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

switch ($action) {
    case 'join':
        handleJoinMatch();
        break;
    case 'get_active':
        handleGetActiveMatch();
        break;
    case 'get_history':
        handleGetMatchHistory();
        break;
    case 'get_room':
        handleGetRoomDetails();
        break;
    case 'search':
        handleSearchMatch();
        break;
    default:
        jsonResponse(false, 'Invalid action specified', [], 400);
        break;
}

// ==============================================
// HANDLER: Join Match
// ==============================================
function handleJoinMatch() {
    global $userId;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    // Validate required fields
    if (!isset($input['entry_fee']) || !isset($input['tournament_id'])) {
        jsonResponse(false, 'Entry fee and tournament ID required', [], 400);
    }
    
    $entryFee = floatval($input['entry_fee']);
    $tournamentId = intval($input['tournament_id']);
    
    // Validate CSRF token
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!CSRFToken::validate($csrfToken)) {
        jsonResponse(false, 'Invalid CSRF token', [], 403);
    }
    
    if ($entryFee <= 0 || $entryFee > 10000) {
        jsonResponse(false, 'Invalid entry fee amount', [], 400);
    }
    
    if ($tournamentId <= 0) {
        jsonResponse(false, 'Invalid tournament ID', [], 400);
    }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $db->beginTransaction();
        
        // Get user with lock
        $stmt = $conn->prepare("
            SELECT id, username, wallet_balance, is_active, is_verified
            FROM users 
            WHERE id = :user_id 
            FOR UPDATE
        ");
        $stmt->execute([':user_id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user || $user['is_active'] != 1) {
            $db->rollback();
            jsonResponse(false, 'User not found or inactive', [], 404);
        }
        
        // Check balance
        if ($user['wallet_balance'] < $entryFee) {
            $db->rollback();
            // BUG FIX: jsonResponse() only accepts 4 args. Merge extra data into $data.
            jsonResponse(false, 'Insufficient wallet balance', [
                'balance' => floatval($user['wallet_balance']),
                'required' => $entryFee,
                'shortfall' => round($entryFee - $user['wallet_balance'], 2)
            ], 400);
        }
        
        // Get tournament details
        $stmt = $conn->prepare("
            SELECT id, entry_fee, max_players, current_players, status, name
            FROM tournaments 
            WHERE id = :tournament_id 
            AND status IN ('active', 'scheduled')
            FOR UPDATE
        ");
        $stmt->execute([':tournament_id' => $tournamentId]);
        $tournament = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$tournament) {
            $db->rollback();
            jsonResponse(false, 'Tournament not found or not active', [], 404);
        }
        
        if ($tournament['current_players'] >= $tournament['max_players']) {
            $db->rollback();
            jsonResponse(false, 'Tournament is full', [], 409);
        }
        
        // Check if user already joined
        $stmt = $conn->prepare("
            SELECT id FROM matches 
            WHERE tournament_id = :tournament_id 
            AND (player1_id = :user_id OR player2_id = :user_id)
            AND status IN ('waiting', 'ready', 'playing')
        ");
        $stmt->execute([
            ':tournament_id' => $tournamentId,
            ':user_id' => $userId
        ]);
        if ($stmt->fetch()) {
            $db->rollback();
            jsonResponse(false, 'You have already joined this tournament', [], 409);
        }
        
        // Generate room code
        $roomCode = generateRoomCode();
        
        // Deduct wallet balance
        $newBalance = $user['wallet_balance'] - $entryFee;
        $stmt = $conn->prepare("
            UPDATE users 
            SET wallet_balance = wallet_balance - :amount, 
                updated_at = CURRENT_TIMESTAMP 
            WHERE id = :user_id
        ");
        $stmt->execute([
            ':amount' => $entryFee,
            ':user_id' => $userId
        ]);
        
        // Calculate platform fee and prize pool
        $platformFee = calculatePlatformFee($entryFee);
        $prizePool = calculatePrizePool($entryFee, $tournament['max_players']);
        
        // Check for existing waiting match
        $stmt = $conn->prepare("
            SELECT id FROM matches 
            WHERE tournament_id = :tournament_id 
            AND entry_fee = :entry_fee
            AND status = 'waiting'
            AND player2_id IS NULL
            AND player1_id != :user_id
            ORDER BY created_at ASC
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([
            ':tournament_id' => $tournamentId,
            ':entry_fee' => $entryFee,
            ':user_id' => $userId
        ]);
        $existingMatch = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingMatch) {
            // Join existing match
            $matchId = $existingMatch['id'];

            // BUG FIX #1: current_turn_id is a user_id (FK → users.id), NOT a player
            // number. Previous code set it to 1 or 2 (player numbers), which pointed
            // to the wrong users entirely.
            $stmt = $conn->prepare("SELECT player1_id, room_code FROM matches WHERE id = :mid");
            $stmt->execute([':mid' => $matchId]);
            $existingMatchRow = $stmt->fetch(PDO::FETCH_ASSOC);
            $player1UserId = intval($existingMatchRow['player1_id']);
            $existingRoomCode = $existingMatchRow['room_code'] ?? $roomCode;
            $firstTurn = rand(0, 1) === 0 ? $player1UserId : $userId;

            $stmt = $conn->prepare("
                UPDATE matches 
                SET 
                    player2_id = :player2_id,
                    player2_name = :player2_name,
                    status = 'ready',
                    current_turn_id = :current_turn,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :match_id
                AND status = 'waiting'
            ");
            $stmt->execute([
                ':player2_id' => $userId,
                ':player2_name' => $user['username'],
                ':current_turn' => $firstTurn,
                ':match_id' => $matchId
            ]);
            
            $db->commit();
            
            jsonResponse(true, 'Match found!', [
                'match_id' => $matchId,
                // BUG FIX #2: Was returning a newly-generated $roomCode instead of the
                // existing match's room_code, causing the joining player to use a wrong room.
                'room_code' => $existingRoomCode,
                'status' => 'ready',
                'entry_fee' => $entryFee,
                'prize_pool' => $prizePool,
                'tournament_name' => $tournament['name'],
                'balance_after' => $newBalance,
                'is_creator' => false,
                'redirect_url' => BASE_URL . '/game.php?match_id=' . $matchId
            ]);
        } else {
            // Create new match
            $stmt = $conn->prepare("
                INSERT INTO matches (
                    tournament_id,
                    room_code,
                    entry_fee,
                    prize_pool,
                    platform_fee,
                    player1_id,
                    player1_name,
                    status,
                    current_turn_id,
                    turn_number,
                    created_at,
                    updated_at
                ) VALUES (
                    :tournament_id,
                    :room_code,
                    :entry_fee,
                    :prize_pool,
                    :platform_fee,
                    :player1_id,
                    :player1_name,
                    'waiting',
                    :player1_id,
                    0,
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP
                )
            ");
            $stmt->execute([
                ':tournament_id' => $tournamentId,
                ':room_code' => $roomCode,
                ':entry_fee' => $entryFee,
                ':prize_pool' => $prizePool,
                ':platform_fee' => $platformFee,
                ':player1_id' => $userId,
                ':player1_name' => $user['username']
            ]);
            
            $matchId = $conn->lastInsertId();
            
            // Update tournament player count
            $stmt = $conn->prepare("
                UPDATE tournaments 
                SET current_players = current_players + 1 
                WHERE id = :tournament_id
            ");
            $stmt->execute([':tournament_id' => $tournamentId]);
            
            // Record transaction
            $orderId = 'MATCH-' . strtoupper(uniqid() . bin2hex(random_bytes(4)));
            $stmt = $conn->prepare("
                INSERT INTO transactions (
                    user_id,
                    tournament_id,
                    match_id,
                    amount,
                    type,
                    source,
                    description,
                    order_id,
                    status,
                    balance_before,
                    balance_after,
                    created_at
                ) VALUES (
                    :user_id,
                    :tournament_id,
                    :match_id,
                    :amount,
                    'debit',
                    'match_fee',
                    :description,
                    :order_id,
                    'success',
                    :balance_before,
                    :balance_after,
                    CURRENT_TIMESTAMP
                )
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':tournament_id' => $tournamentId,
                ':match_id' => $matchId,
                ':amount' => $entryFee,
                ':description' => "Match entry fee for tournament: {$tournament['name']}",
                ':order_id' => $orderId,
                ':balance_before' => $user['wallet_balance'],
                ':balance_after' => $newBalance
            ]);
            
            $db->commit();
            
            jsonResponse(true, 'Match created. Waiting for opponent...', [
                'match_id' => $matchId,
                'room_code' => $roomCode,
                'status' => 'waiting',
                'entry_fee' => $entryFee,
                'prize_pool' => $prizePool,
                'tournament_name' => $tournament['name'],
                'balance_after' => $newBalance,
                'is_creator' => true,
                'poll_interval' => 1200
            ]);
        }
        
    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollback();
        }
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollback();
        }
        jsonResponse(false, 'Error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Search Match
// ==============================================
function handleSearchMatch() {
    global $userId;
    
    $entryFee = isset($_GET['entry_fee']) ? floatval($_GET['entry_fee']) : 0;
    $tournamentId = isset($_GET['tournament_id']) ? intval($_GET['tournament_id']) : 0;
    
    if ($entryFee <= 0 || $tournamentId <= 0) {
        jsonResponse(false, 'Invalid search parameters', [], 400);
    }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("
            SELECT 
                id,
                room_code,
                player1_name,
                entry_fee,
                prize_pool,
                created_at
            FROM matches 
            WHERE tournament_id = :tournament_id 
            AND entry_fee = :entry_fee
            AND status = 'waiting'
            AND player2_id IS NULL
            AND player1_id != :user_id
            ORDER BY created_at ASC
            LIMIT 5
        ");
        $stmt->execute([
            ':tournament_id' => $tournamentId,
            ':entry_fee' => $entryFee,
            ':user_id' => $userId
        ]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse(true, 'Matches found', [
            'matches' => $matches,
            'count' => count($matches)
        ]);
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Get Active Match
// ==============================================
function handleGetActiveMatch() {
    global $userId;
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("
            SELECT 
                id,
                room_code,
                entry_fee,
                prize_pool,
                status,
                player1_name,
                player2_name,
                current_turn_id,
                dice_value,
                turn_number,
                created_at,
                p1_token1, p1_token2, p1_token3, p1_token4,
                p2_token1, p2_token2, p2_token3, p2_token4,
                p1_home_count, p2_home_count
            FROM matches 
            WHERE (player1_id = :user_id OR player2_id = :user_id)
            AND status IN ('waiting', 'ready', 'playing')
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $userId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$match) {
            jsonResponse(true, 'No active match', [
                'has_active_match' => false
            ]);
        }
        
        jsonResponse(true, 'Active match found', [
            'has_active_match' => true,
            'match' => $match
        ]);
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Get Match History
// ==============================================
function handleGetMatchHistory() {
    global $userId;
    
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
    $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        // Get total count
        $stmt = $conn->prepare("
            SELECT COUNT(*) as total 
            FROM matches 
            WHERE (player1_id = :user_id OR player2_id = :user_id)
            AND status IN ('completed', 'cancelled')
        ");
        $stmt->execute([':user_id' => $userId]);
        $total = intval($stmt->fetchColumn());
        
        // Get matches
        $stmt = $conn->prepare("
            SELECT 
                id,
                room_code,
                entry_fee,
                prize_pool,
                status,
                player1_name,
                player2_name,
                winner_name,
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

// ==============================================
// HANDLER: Get Room Details
// ==============================================
function handleGetRoomDetails() {
    global $userId;
    
    $roomCode = isset($_GET['room_code']) ? trim($_GET['room_code']) : '';
    
    if (empty($roomCode)) {
        jsonResponse(false, 'Room code required', [], 400);
    }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("
            SELECT 
                id,
                room_code,
                entry_fee,
                prize_pool,
                status,
                player1_name,
                player2_name,
                player1_id,
                player2_id,
                current_turn_id,
                dice_value,
                turn_number,
                created_at,
                p1_token1, p1_token2, p1_token3, p1_token4,
                p2_token1, p2_token2, p2_token3, p2_token4,
                p1_home_count, p2_home_count
            FROM matches 
            WHERE room_code = :room_code
        ");
        $stmt->execute([':room_code' => $roomCode]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$match) {
            jsonResponse(false, 'Room not found', [], 404);
        }
        
        // Check if user is in this match
        $isParticipant = ($match['player1_id'] == $userId || $match['player2_id'] == $userId);
        
        jsonResponse(true, 'Room details retrieved', [
            'match' => $match,
            'is_participant' => $isParticipant
        ]);
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}
?>
