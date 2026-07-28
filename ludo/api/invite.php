<?php
/**
 * ======================================================
 * INVITE.PHP - Friend Invite API (FIXED)
 * Ludo Tournament Platform - Complete Invite System
 * Version: 3.0.0 - ALL FIXES APPLIED
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
    jsonResponse(false, 'Please login first', [], 401);
}

$userId = getCurrentUserId();
if (!$userId) {
    jsonResponse(false, 'Invalid session', [], 401);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'create': handleCreateInvite(); break;
    case 'check_room': handleCheckRoom(); break;
    case 'join': handleJoinRoom(); break;
    case 'get_room': handleGetRoom(); break;
    default: jsonResponse(false, 'Invalid action', [], 400);
}

// ==============================================
// CREATE INVITE
// ==============================================
function handleCreateInvite() {
    global $userId;
    
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    
    $roomCode = trim($input['room_code'] ?? '');
    
    if (!CSRFToken::validate($input['csrf_token'] ?? '')) {
        jsonResponse(false, 'Invalid CSRF', [], 403);
    }
    
    if (empty($roomCode)) { jsonResponse(false, 'Room code required', [], 400); }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("SELECT id, room_code, status, player1_id, player2_id, entry_fee, prize_pool FROM matches WHERE room_code = :rc");
        $stmt->execute([':rc' => $roomCode]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$match) { jsonResponse(false, 'Room not found', [], 404); }
        
        $inviteCode = 'INV-' . strtoupper(uniqid() . bin2hex(random_bytes(3)));
        
        jsonResponse(true, 'Invite created', [
            'room_code' => $roomCode, 'match_id' => $match['id'],
            'invite_code' => $inviteCode,
            'invite_url' => BASE_URL . '/join.php?room=' . $roomCode,
            'status' => $match['status'],
            'player_count' => ($match['player1_id'] ? 1 : 0) + ($match['player2_id'] ? 1 : 0)
        ]);
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// CHECK ROOM
// ==============================================
function handleCheckRoom() {
    $roomCode = trim($_GET['room'] ?? '');
    
    if (empty($roomCode)) { jsonResponse(false, 'Room code required', [], 400); }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("SELECT id, room_code, status, player1_id, player2_id, player1_name, player2_name, entry_fee, prize_pool, created_at FROM matches WHERE room_code = :rc");
        $stmt->execute([':rc' => $roomCode]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$match) { jsonResponse(false, 'Room not found', [], 404); }
        
        $isFull = ($match['player1_id'] && $match['player2_id']);
        $playerCount = ($match['player1_id'] ? 1 : 0) + ($match['player2_id'] ? 1 : 0);
        
        jsonResponse(true, 'Room found', [
            'room' => [
                'id' => $match['id'], 'room_code' => $match['room_code'],
                'status' => $match['status'], 'player1_name' => $match['player1_name'],
                'player2_name' => $match['player2_name'],
                'entry_fee' => floatval($match['entry_fee']),
                'prize_pool' => floatval($match['prize_pool']),
                'player_count' => $playerCount, 'is_full' => $isFull,
                'created_at' => $match['created_at']
            ]
        ]);
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// JOIN ROOM
// ==============================================
function handleJoinRoom() {
    global $userId;
    
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $roomCode = trim($input['room_code'] ?? '');
    
    if (!CSRFToken::validate($input['csrf_token'] ?? '')) {
        jsonResponse(false, 'Invalid CSRF', [], 403);
    }
    
    if (empty($roomCode)) { jsonResponse(false, 'Room code required', [], 400); }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $db->beginTransaction();
        
        $stmt = $conn->prepare("SELECT id, room_code, status, player1_id, player2_id, entry_fee, prize_pool FROM matches WHERE room_code = :rc FOR UPDATE");
        $stmt->execute([':rc' => $roomCode]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$match) { $db->rollback(); jsonResponse(false, 'Room not found', [], 404); }
        
        if (in_array($match['status'], ['playing', 'completed'])) {
            $db->rollback();
            jsonResponse(false, 'Game already started or completed', [], 400);
        }
        
        if ($match['player1_id'] && $match['player2_id']) {
            $db->rollback();
            jsonResponse(false, 'Room is full', [], 409);
        }
        
        if ($match['player1_id'] == $userId || $match['player2_id'] == $userId) {
            $db->rollback();
            jsonResponse(false, 'You are already in this room', [], 409);
        }
        
        // Fetch user
        $stmt = $conn->prepare("SELECT id, username, wallet_balance FROM users WHERE id = :uid");
        $stmt->execute([':uid' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) { $db->rollback(); jsonResponse(false, 'User not found', [], 404); }
        
        $entryFee = floatval($match['entry_fee']);
        if ($user['wallet_balance'] < $entryFee) {
            $db->rollback();
            jsonResponse(false, 'Insufficient balance', [], 400);
        }
        
        // Deduct balance
        $newBalance = $user['wallet_balance'] - $entryFee;
        $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance - :amt WHERE id = :uid");
        $stmt->execute([':amt' => $entryFee, ':uid' => $userId]);
        
        // Determine first turn
        $player1Id = intval($match['player1_id']);
        $firstTurn = rand(0, 1) === 0 ? $player1Id : $userId;
        
        // Update match
        $stmt = $conn->prepare("UPDATE matches SET player2_id = :p2id, player2_name = :p2name, status = 'ready', current_turn_id = :turn, updated_at = CURRENT_TIMESTAMP WHERE id = :mid");
        $stmt->execute([':p2id' => $userId, ':p2name' => $user['username'], ':turn' => $firstTurn, ':mid' => $match['id']]);
        
        // Record transaction
        $orderId = 'JOIN-' . strtoupper(uniqid() . bin2hex(random_bytes(4)));
        $stmt = $conn->prepare("
            INSERT INTO transactions (user_id, match_id, amount, type, source, description, order_id, status, balance_before, balance_after, created_at)
            VALUES (:uid, :mid, :amt, 'debit', 'match_fee', :desc, :oid, 'success', :bb, :ba, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([':uid' => $userId, ':mid' => $match['id'], ':amt' => $entryFee, ':desc' => "Joined via invite", ':oid' => $orderId, ':bb' => $user['wallet_balance'], ':ba' => $newBalance]);
        
        $db->commit();
        
        jsonResponse(true, 'Successfully joined room!', [
            'match_id' => $match['id'], 'room_code' => $match['room_code'],
            'player1_name' => $match['player1_name'], 'player2_name' => $user['username'],
            'entry_fee' => $entryFee, 'prize_pool' => floatval($match['prize_pool']),
            'redirect_url' => BASE_URL . '/game.php?match_id=' . $match['id']
        ]);
    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) $db->rollback();
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// GET ROOM
// ==============================================
function handleGetRoom() {
    $roomCode = trim($_GET['room_code'] ?? '');
    
    if (empty($roomCode)) { jsonResponse(false, 'Room code required', [], 400); }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("SELECT id, room_code, entry_fee, prize_pool, status, player1_id, player2_id, player1_name, player2_name, created_at FROM matches WHERE room_code = :rc");
        $stmt->execute([':rc' => $roomCode]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$match) { jsonResponse(false, 'Room not found', [], 404); }
        
        jsonResponse(true, 'Room details', ['room' => $match]);
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error', [], 500);
    }
}
?>
