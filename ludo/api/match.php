<?php
/**
 * ======================================================
 * MATCH.PHP - Match Management API (FIXED)
 * Ludo Tournament Platform - Complete Match System
 * Version: 4.1.0 - AUTH FIX + 401 FIX
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
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-CSRF-Token');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ==============================================
// FIXED: AUTH CHECK WITH PROPER 401 RESPONSE
// ==============================================
if (!isLoggedIn()) {
    jsonResponse(false, 'Please login to join a match', [], 401);
}

$userId = getCurrentUserId();
if (!$userId || $userId <= 0) {
    jsonResponse(false, 'Invalid session', [], 401);
}

// ROUTING
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
}

// ==============================================
// JOIN MATCH
// ==============================================
function handleJoinMatch() {
    global $userId;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    if (!isset($input['entry_fee']) || !isset($input['tournament_id'])) {
        jsonResponse(false, 'Entry fee and tournament ID required', [], 400);
    }
    
    $entryFee = floatval($input['entry_fee']);
    $tournamentId = intval($input['tournament_id']);
    
    // CSRF
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
            FROM users WHERE id = :uid FOR UPDATE
        ");
        $stmt->execute([':uid' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user || $user['is_active'] != 1) {
            $db->rollback();
            jsonResponse(false, 'User not found or inactive', [], 404);
        }
        
        // Check balance
        if ($user['wallet_balance'] < $entryFee) {
            $db->rollback();
            jsonResponse(false, 'Insufficient wallet balance', [
                'balance' => floatval($user['wallet_balance']),
                'required' => $entryFee,
                'shortfall' => round($entryFee - $user['wallet_balance'], 2)
            ], 400);
        }
        
        // Check if already in active match
        $stmt = $conn->prepare("
            SELECT id FROM matches 
            WHERE (player1_id = :uid OR player2_id = :uid)
            AND status IN ('waiting', 'ready', 'playing')
        ");
        $stmt->execute([':uid' => $userId]);
        if ($stmt->fetch()) {
            $db->rollback();
            jsonResponse(false, 'You are already in an active match', [], 409);
        }
        
        // Generate room code
        $roomCode = generateRoomCode();
        
        // Deduct wallet
        $newBalance = $user['wallet_balance'] - $entryFee;
        $stmt = $conn->prepare("
            UPDATE users SET wallet_balance = wallet_balance - :amount, updated_at = CURRENT_TIMESTAMP 
            WHERE id = :uid
        ");
        $stmt->execute([':amount' => $entryFee, ':uid' => $userId]);
        
        // Calculate prize pool
        $platformFee = calculatePlatformFee($entryFee);
        $prizePool = calculatePrizePool($entryFee, 2);
        
        // Look for existing waiting match
        $stmt = $conn->prepare("
            SELECT id, room_code, player1_id FROM matches 
            WHERE entry_fee = :fee AND status = 'waiting'
            AND player2_id IS NULL AND player1_id != :uid
            ORDER BY created_at ASC LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([':fee' => $entryFee, ':uid' => $userId]);
        $existingMatch = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingMatch) {
            // Join existing match
            $matchId = $existingMatch['id'];
            $player1Id = intval($existingMatch['player1_id']);
            $firstTurn = rand(0, 1) === 0 ? $player1Id : $userId;
            
            $stmt = $conn->prepare("
                UPDATE matches SET 
                    player2_id = :p2id, player2_name = :p2name,
                    status = 'ready', current_turn_id = :turn,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :mid AND status = 'waiting'
            ");
            $stmt->execute([
                ':p2id' => $userId,
                ':p2name' => $user['username'],
                ':turn' => $firstTurn,
                ':mid' => $matchId
            ]);
            
            // Record transaction
            $orderId = 'MATCH-' . strtoupper(uniqid() . bin2hex(random_bytes(4)));
            $stmt = $conn->prepare("
                INSERT INTO transactions (
                    user_id, match_id, amount, type, source, description,
                    order_id, status, balance_before, balance_after, created_at
                ) VALUES (
                    :uid, :mid, :amount, 'debit', 'match_fee', :desc,
                    :oid, 'success', :bal_before, :bal_after, CURRENT_TIMESTAMP
                )
            ");
            $stmt->execute([
                ':uid' => $userId, ':mid' => $matchId,
                ':amount' => $entryFee,
                ':desc' => "Match entry fee",
                ':oid' => $orderId,
                ':bal_before' => $user['wallet_balance'],
                ':bal_after' => $newBalance
            ]);
            
            $db->commit();
            
            jsonResponse(true, 'Match found!', [
                'match_id' => $matchId,
                'room_code' => $existingMatch['room_code'],
                'status' => 'ready',
                'entry_fee' => $entryFee,
                'prize_pool' => $prizePool,
                'balance_after' => $newBalance,
                'redirect_url' => BASE_URL . '/game.php?match_id=' . $matchId
            ]);
            
        } else {
            // Create new match
            $stmt = $conn->prepare("
                INSERT INTO matches (
                    room_code, tournament_id, entry_fee, prize_pool, platform_fee,
                    player1_id, player1_name, status, current_turn_id,
                    turn_number, created_at, updated_at
                ) VALUES (
                    :rc, :tid, :fee, :prize, :pf,
                    :p1id, :p1name, 'waiting', :p1id,
                    0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                )
            ");
            $stmt->execute([
                ':rc' => $roomCode, ':tid' => $tournamentId,
                ':fee' => $entryFee,
                ':prize' => $prizePool, ':pf' => $platformFee,
                ':p1id' => $userId, ':p1name' => $user['username']
            ]);
            
            $matchId = $conn->lastInsertId();
            
            // Record transaction
            $orderId = 'MATCH-' . strtoupper(uniqid() . bin2hex(random_bytes(4)));
            $stmt = $conn->prepare("
                INSERT INTO transactions (
                    user_id, match_id, amount, type, source, description,
                    order_id, status, balance_before, balance_after, created_at
                ) VALUES (
                    :uid, :mid, :amount, 'debit', 'match_fee', :desc,
                    :oid, 'success', :bal_before, :bal_after, CURRENT_TIMESTAMP
                )
            ");
            $stmt->execute([
                ':uid' => $userId, ':mid' => $matchId,
                ':amount' => $entryFee,
                ':desc' => "Match entry fee",
                ':oid' => $orderId,
                ':bal_before' => $user['wallet_balance'],
                ':bal_after' => $newBalance
            ]);
            
            $db->commit();
            
            jsonResponse(true, 'Match created. Waiting for opponent...', [
                'match_id' => $matchId,
                'room_code' => $roomCode,
                'status' => 'waiting',
                'entry_fee' => $entryFee,
                'prize_pool' => $prizePool,
                'balance_after' => $newBalance,
                'poll_interval' => 1200
            ]);
        }
        
    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) $db->rollback();
        error_log('Match join error: ' . $e->getMessage());
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) $db->rollback();
        error_log('Match join error: ' . $e->getMessage());
        jsonResponse(false, 'Error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// GET ACTIVE MATCH
// ==============================================
function handleGetActiveMatch() {
    global $userId;
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("
            SELECT id, room_code, entry_fee, prize_pool, status,
                   player1_name, player2_name, current_turn_id,
                   dice_value, turn_number, created_at
            FROM matches 
            WHERE (player1_id = :uid OR player2_id = :uid)
            AND status IN ('waiting', 'ready', 'playing')
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([':uid' => $userId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$match) {
            jsonResponse(true, 'No active match', ['has_active_match' => false]);
            return;
        }
        
        jsonResponse(true, 'Active match found', [
            'has_active_match' => true,
            'match' => $match
        ]);
        
    } catch (PDOException $e) {
        error_log('Match get_active error: ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// GET MATCH HISTORY (FIXED - PROPER AUTH)
// ==============================================
function handleGetMatchHistory() {
    global $userId;
    
    $limit = intval($_GET['limit'] ?? 20);
    $offset = intval($_GET['offset'] ?? 0);
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM matches 
            WHERE (player1_id = :uid OR player2_id = :uid)
            AND status IN ('completed', 'cancelled')
        ");
        $stmt->execute([':uid' => $userId]);
        $total = intval($stmt->fetchColumn());
        
        $stmt = $conn->prepare("
            SELECT id, room_code, entry_fee, prize_pool, status,
                   player1_name, player2_name, winner_id, winner_name,
                   winning_amount, turn_number, created_at, completed_at
            FROM matches 
            WHERE (player1_id = :uid OR player2_id = :uid)
            AND status IN ('completed', 'cancelled')
            ORDER BY created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->execute([':uid' => $userId, ':limit' => $limit, ':offset' => $offset]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Convert numeric fields
        foreach ($matches as &$m) {
            $m['entry_fee'] = floatval($m['entry_fee'] ?? 0);
            $m['prize_pool'] = floatval($m['prize_pool'] ?? 0);
            $m['winning_amount'] = floatval($m['winning_amount'] ?? 0);
            $m['winner_id'] = $m['winner_id'] ? intval($m['winner_id']) : null;
        }
        unset($m);
        
        jsonResponse(true, 'Match history retrieved', [
            'matches' => $matches ?: [],
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ]);
        
    } catch (PDOException $e) {
        error_log('Match get_history error: ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// GET ROOM DETAILS
// ==============================================
function handleGetRoomDetails() {
    global $userId;
    
    $roomCode = trim($_GET['room_code'] ?? '');
    
    if (empty($roomCode)) {
        jsonResponse(false, 'Room code required', [], 400);
    }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("
            SELECT id, room_code, entry_fee, prize_pool, status,
                   player1_name, player2_name, player1_id, player2_id,
                   current_turn_id, dice_value, turn_number, created_at
            FROM matches WHERE room_code = :rc
        ");
        $stmt->execute([':rc' => $roomCode]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$match) {
            jsonResponse(false, 'Room not found', [], 404);
        }
        
        $match['entry_fee'] = floatval($match['entry_fee'] ?? 0);
        $match['prize_pool'] = floatval($match['prize_pool'] ?? 0);
        
        $isParticipant = ($match['player1_id'] == $userId || $match['player2_id'] == $userId);
        
        jsonResponse(true, 'Room details retrieved', [
            'match' => $match,
            'is_participant' => $isParticipant
        ]);
        
    } catch (PDOException $e) {
        error_log('Match get_room error: ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// SEARCH MATCH
// ==============================================
function handleSearchMatch() {
    global $userId;
    
    $entryFee = floatval($_GET['entry_fee'] ?? 0);
    
    if ($entryFee <= 0) {
        jsonResponse(false, 'Invalid entry fee', [], 400);
    }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("
            SELECT id, room_code, player1_name, entry_fee, prize_pool, created_at
            FROM matches 
            WHERE entry_fee = :fee AND status = 'waiting'
            AND player2_id IS NULL AND player1_id != :uid
            ORDER BY created_at ASC LIMIT 5
        ");
        $stmt->execute([':fee' => $entryFee, ':uid' => $userId]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse(true, 'Matches found', [
            'matches' => $matches ?: [],
            'count' => count($matches ?: [])
        ]);
        
    } catch (PDOException $e) {
        error_log('Match search error: ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}
