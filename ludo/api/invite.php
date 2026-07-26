<?php
/**
 * ======================================================
 * INVITE.PHP - Friend Invite API
 * Ludo Tournament Platform - Complete Invite System
 * Version: 2.0.0 - COMPLETE
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

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

switch ($action) {
    case 'create':
        handleCreateInvite();
        break;
    case 'check_room':
        handleCheckRoom();
        break;
    case 'join':
        handleJoinRoom();
        break;
    case 'get_room':
        handleGetRoom();
        break;
    default:
        jsonResponse(false, 'Invalid action specified', [], 400);
        break;
}

function handleCreateInvite() {
    global $userId;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $roomCode = isset($input['room_code']) ? trim($input['room_code']) : '';
    $matchId = isset($input['match_id']) ? intval($input['match_id']) : 0;
    
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!CSRFToken::validate($csrfToken)) {
        jsonResponse(false, 'Invalid CSRF token', [], 403);
    }
    
    if (empty($roomCode)) {
        jsonResponse(false, 'Room code required', [], 400);
    }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("
            SELECT id, room_code, status, player1_id, player2_id, entry_fee, prize_pool
            FROM matches 
            WHERE room_code = :room_code
        ");
        $stmt->execute([':room_code' => $roomCode]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$match) {
            jsonResponse(false, 'Room not found', [], 404);
        }
        
        $inviteCode = 'INV-' . strtoupper(uniqid() . bin2hex(random_bytes(3)));
        
        jsonResponse(true, 'Invite created successfully', [
            'room_code' => $roomCode,
            'match_id' => $match['id'],
            'invite_code' => $inviteCode,
            'invite_url' => BASE_URL . '/join.php?room=' . $roomCode,
            'status' => $match['status'],
            'player_count' => ($match['player1_id'] ? 1 : 0) + ($match['player2_id'] ? 1 : 0)
        ]);
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    } catch (Exception $e) {
        jsonResponse(false, 'Error: ' . $e->getMessage(), [], 500);
    }
}

function handleCheckRoom() {
    $roomCode = isset($_GET['room']) ? trim($_GET['room']) : '';
    
    if (empty($roomCode)) {
        jsonResponse(false, 'Room code required', [], 400);
    }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("
            SELECT 
                id, room_code, status, player1_id, player2_id,
                player1_name, player2_name, entry_fee, prize_pool, created_at
            FROM matches 
            WHERE room_code = :room_code
        ");
        $stmt->execute([':room_code' => $roomCode]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$match) {
            jsonResponse(false, 'Room not found', [], 404);
        }
        
        $isFull = ($match['player1_id'] && $match['player2_id']);
        $playerCount = ($match['player1_id'] ? 1 : 0) + ($match['player2_id'] ? 1 : 0);
        
        jsonResponse(true, 'Room found', [
            'room' => [
                'id' => $match['id'],
                'room_code' => $match['room_code'],
                'status' => $match['status'],
                'player1_name' => $match['player1_name'],
                'player2_name' => $match['player2_name'],
                'entry_fee' => floatval($match['entry_fee']),
                'prize_pool' => floatval($match['prize_pool']),
                'player_count' => $playerCount,
                'is_full' => $isFull,
                'created_at' => $match['created_at']
            ]
        ]);
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

function handleJoinRoom() {
    global $userId;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $roomCode = isset($input['room_code']) ? trim($input['room_code']) : '';
    
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!CSRFToken::validate($csrfToken)) {
        jsonResponse(false, 'Invalid CSRF token', [], 403);
    }
    
    if (empty($roomCode)) {
        jsonResponse(false, 'Room code required', [], 400);
    }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $db->beginTransaction();
        
        $stmt = $conn->prepare("
            SELECT id, room_code, status, player1_id, player2_id, entry_fee, prize_pool
            FROM matches 
            WHERE room_code = :room_code
            FOR UPDATE
        ");
        $stmt->execute([':room_code' => $roomCode]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$match) {
            $db->rollback();
            jsonResponse(false, 'Room not found', [], 404);
        }
        
        if ($match['status'] === 'playing' || $match['status'] === 'completed') {
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
        
        $stmt = $conn->prepare("
            SELECT id, username, wallet_balance 
            FROM users 
            WHERE id = :user_id
        ");
        $stmt->execute([':user_id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            $db->rollback();
            jsonResponse(false, 'User not found', [], 404);
        }
        
        if ($user['wallet_balance'] < $match['entry_fee']) {
            $db->rollback();
            jsonResponse(false, 'Insufficient balance to join', [], 400);
        }
        
        $newBalance = $user['wallet_balance'] - $match['entry_fee'];
        $stmt = $conn->prepare("
            UPDATE users 
            SET wallet_balance = wallet_balance - :amount 
            WHERE id = :user_id
        ");
        $stmt->execute([
            ':amount' => $match['entry_fee'],
            ':user_id' => $userId
        ]);
        
        $stmt = $conn->prepare("
            UPDATE matches 
            SET 
                player2_id = :user_id,
                player2_name = :username,
                status = 'ready',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :match_id
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':username' => $user['username'],
            ':match_id' => $match['id']
        ]);
        
        $orderId = 'JOIN-' . strtoupper(uniqid() . bin2hex(random_bytes(4)));
        $stmt = $conn->prepare("
            INSERT INTO transactions (
                user_id, match_id, amount, type, source, description,
                order_id, status, balance_before, balance_after, created_at
            ) VALUES (
                :user_id, :match_id, :amount, 'debit', 'match_fee', :description,
                :order_id, 'success', :balance_before, :balance_after, CURRENT_TIMESTAMP
            )
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':match_id' => $match['id'],
            ':amount' => $match['entry_fee'],
            ':description' => "Joined via invite link",
            ':order_id' => $orderId,
            ':balance_before' => $user['wallet_balance'],
            ':balance_after' => $newBalance
        ]);
        
        $db->commit();
        
        jsonResponse(true, 'Successfully joined room!', [
            'match_id' => $match['id'],
            'room_code' => $match['room_code'],
            'player1_name' => $match['player1_name'],
            'player2_name' => $user['username'],
            'entry_fee' => floatval($match['entry_fee']),
            'prize_pool' => floatval($match['prize_pool']),
            'redirect_url' => BASE_URL . '/game.php?match_id=' . $match['id']
        ]);
        
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

function handleGetRoom() {
    $roomCode = isset($_GET['room_code']) ? trim($_GET['room_code']) : '';
    
    if (empty($roomCode)) {
        jsonResponse(false, 'Room code required', [], 400);
    }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("
            SELECT id, room_code, entry_fee, prize_pool, status,
                   player1_id, player2_id, player1_name, player2_name, created_at
            FROM matches 
            WHERE room_code = :room_code
        ");
        $stmt->execute([':room_code' => $roomCode]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$match) {
            jsonResponse(false, 'Room not found', [], 404);
        }
        
        jsonResponse(true, 'Room details retrieved', [
            'room' => $match
        ]);
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}
