<?php
/**
 * ======================================================
 * MATCHMAKE.PHP - Tournament Matchmaking & Pairing
 * Ludo Tournament Platform - Production Ready
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
header('Access-Control-Allow-Methods: POST, OPTIONS');
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
    jsonResponse(false, 'Invalid JSON payload', [], 400);
}

// Validate required fields
$required = ['entry_fee', 'tournament_id'];
foreach ($required as $field) {
    if (!isset($input[$field]) || empty($input[$field])) {
        jsonResponse(false, "Missing required field: {$field}", [], 400);
    }
}

// Sanitize and validate entry fee
$entryFee = floatval($input['entry_fee']);
if ($entryFee <= 0 || $entryFee > 10000) {
    jsonResponse(false, 'Invalid entry fee amount. Must be between 1 and 10,000.', [], 400);
}

$tournamentId = intval($input['tournament_id']);
if ($tournamentId <= 0) {
    jsonResponse(false, 'Invalid tournament ID', [], 400);
}

// Validate user authentication
if (!isLoggedIn()) {
    jsonResponse(false, 'User not authenticated', [], 401);
}

$userId = getCurrentUserId();
if (!$userId) {
    jsonResponse(false, 'Invalid user session', [], 401);
}

// Get CSRF token from request
$csrfToken = $input['csrf_token'] ?? $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!CSRFToken::validate($csrfToken)) {
    jsonResponse(false, 'Invalid CSRF token', [], 403);
}

// ==============================================
// MAIN MATCHMAKING LOGIC
// ==============================================

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    // Begin transaction for atomic operations
    $db->beginTransaction();
    
    // ==============================================
    // 1. VERIFY USER WALLET BALANCE (FOR UPDATE LOCK)
    // ==============================================
    $stmt = $conn->prepare("
        SELECT id, username, wallet_balance, is_active, is_verified
        FROM users 
        WHERE id = :user_id 
        FOR UPDATE
    ");
    $stmt->execute([':user_id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        $db->rollback();
        jsonResponse(false, 'User not found', [], 404);
    }
    
    if ($user['is_active'] != 1) {
        $db->rollback();
        jsonResponse(false, 'User account is inactive. Please contact support.', [], 403);
    }
    
    if ($user['is_verified'] != 1) {
        $db->rollback();
        jsonResponse(false, 'User account is not verified. Please complete verification.', [], 403);
    }
    
    $currentBalance = floatval($user['wallet_balance']);
    if ($currentBalance < $entryFee) {
        $db->rollback();
        jsonResponse(false, 'Insufficient wallet balance', [], 400, [
            'balance' => $currentBalance,
            'required' => $entryFee,
            'shortfall' => round($entryFee - $currentBalance, 2)
        ]);
    }
    
    // ==============================================
    // 2. CHECK IF USER IS ALREADY IN AN ACTIVE MATCH
    // ==============================================
    $stmt = $conn->prepare("
        SELECT id, room_code, status 
        FROM matches 
        WHERE (player1_id = :user_id OR player2_id = :user_id) 
        AND status IN ('waiting', 'ready', 'playing', 'paused')
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([':user_id' => $userId]);
    $existingMatch = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingMatch) {
        $db->rollback();
        jsonResponse(false, 'You are already in an active match', [], 409, [
            'match_id' => $existingMatch['id'],
            'room_code' => $existingMatch['room_code'],
            'status' => $existingMatch['status']
        ]);
    }
    
    // ==============================================
    // 3. SEARCH FOR WAITING MATCH WITH SAME ENTRY FEE
    // ==============================================
    $stmt = $conn->prepare("
        SELECT id, room_code, player1_id, status, entry_fee, tournament_id
        FROM matches 
        WHERE status = 'waiting' 
        AND entry_fee = :entry_fee
        AND tournament_id = :tournament_id
        AND player1_id != :user_id
        AND player2_id IS NULL
        ORDER BY created_at ASC
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([
        ':entry_fee' => $entryFee,
        ':tournament_id' => $tournamentId,
        ':user_id' => $userId
    ]);
    $waitingMatch = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // ==============================================
    // 4. DEDUCT ENTRY FEE FROM WALLET
    // ==============================================
    $newBalance = $currentBalance - $entryFee;
    
    // Get user details for transaction record
    $stmt = $conn->prepare("
        SELECT username, mobile, email 
        FROM users 
        WHERE id = :user_id
    ");
    $stmt->execute([':user_id' => $userId]);
    $userDetails = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Generate unique order ID
    $orderId = 'LUDO-' . strtoupper(uniqid() . bin2hex(random_bytes(4)));
    
    // Deduct wallet balance
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
    
    if ($stmt->rowCount() === 0) {
        $db->rollback();
        jsonResponse(false, 'Failed to deduct wallet balance', [], 500);
    }
    
    // Record transaction
    $stmt = $conn->prepare("
        INSERT INTO transactions (
            user_id, 
            tournament_id, 
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
            :amount,
            'debit',
            'match_fee',
            :description,
            :order_id,
            'processing',
            :balance_before,
            :balance_after,
            CURRENT_TIMESTAMP
        )
    ");
    $stmt->execute([
        ':user_id' => $userId,
        ':tournament_id' => $tournamentId,
        ':amount' => $entryFee,
        ':description' => "Match entry fee for tournament #{$tournamentId}",
        ':order_id' => $orderId,
        ':balance_before' => $currentBalance,
        ':balance_after' => $newBalance
    ]);
    
    $transactionId = $conn->lastInsertId();
    
    // ==============================================
    // 5. HANDLE MATCHMAKING RESULT
    // ==============================================
    $roomCode = strtoupper(substr(md5(uniqid() . rand()), 0, 6));
    $matchId = null;
    $matched = false;
    $data = [];
    
    if ($waitingMatch) {
        // ==============================================
        // 5a. JOIN EXISTING MATCH
        // ==============================================
        $matchId = $waitingMatch['id'];
        
        // Randomize who goes first
        $player1Id = intval($waitingMatch['player1_id']);
        $firstTurn = rand(1, 2) === 1 ? $player1Id : $userId;
        
        // Get player names
        $stmt = $conn->prepare("
            SELECT id, username 
            FROM users 
            WHERE id IN (:player1, :player2)
        ");
        $stmt->execute([
            ':player1' => $player1Id,
            ':player2' => $userId
        ]);
        $players = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $playerNames = [];
        foreach ($players as $p) {
            $playerNames[$p['id']] = $p['username'];
        }
        
        // Update match with player2
        $stmt = $conn->prepare("
            UPDATE matches 
            SET 
                player2_id = :player2_id,
                player2_name = :player2_name,
                status = 'ready',
                current_turn_id = :current_turn,
                started_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :match_id
            AND status = 'waiting'
        ");
        $stmt->execute([
            ':player2_id' => $userId,
            ':player2_name' => $userDetails['username'],
            ':current_turn' => $firstTurn,
            ':match_id' => $matchId
        ]);
        
        if ($stmt->rowCount() === 0) {
            // Race condition - match was taken
            $db->rollback();
            
            // Refund the user
            refundUser($db, $conn, $userId, $entryFee, $transactionId, $orderId);
            
            jsonResponse(false, 'Match was already taken. Please try again.', [], 409);
        }
        
        $matched = true;
        $data = [
            'match_id' => $matchId,
            'room_code' => $waitingMatch['room_code'],
            'status' => 'ready',
            'current_turn' => $firstTurn,
            'player1_id' => $player1Id,
            'player1_name' => $playerNames[$player1Id] ?? 'Player 1',
            'player2_id' => $userId,
            'player2_name' => $userDetails['username'],
            'entry_fee' => $entryFee,
            'tournament_id' => $tournamentId,
            'is_creator' => false,
            'message' => 'Successfully joined match!'
        ];
        
    } else {
        // ==============================================
        // 5b. CREATE NEW MATCH
        // ==============================================
        // Get player name
        $playerName = $userDetails['username'];
        
        // Calculate platform fee and prize pool
        $platformFee = calculatePlatformFee($entryFee);
        $prizePool = calculatePrizePool($entryFee, 2);
        
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
            ':player1_name' => $playerName
        ]);
        
        $matchId = $conn->lastInsertId();
        
        $data = [
            'match_id' => $matchId,
            'room_code' => $roomCode,
            'status' => 'waiting',
            'current_turn' => $userId,
            'player1_id' => $userId,
            'player1_name' => $playerName,
            'player2_id' => null,
            'player2_name' => null,
            'entry_fee' => $entryFee,
            'tournament_id' => $tournamentId,
            'is_creator' => true,
            'message' => 'Match created. Waiting for opponent...',
            'poll_interval' => 1200
        ];
    }
    
    // ==============================================
    // 6. UPDATE TOURNAMENT PLAYER COUNT
    // ==============================================
    if ($matched) {
        $stmt = $conn->prepare("
            UPDATE tournaments 
            SET current_players = current_players + 1 
            WHERE id = :tournament_id
        ");
        $stmt->execute([':tournament_id' => $tournamentId]);
    }
    
    // ==============================================
    // 7. COMMIT TRANSACTION
    // ==============================================
    $db->commit();
    
    // ==============================================
    // 8. LOG MATCHMAKING EVENT
    // ==============================================
    $logEntry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'event' => $matched ? 'match_joined' : 'match_created',
        'user_id' => $userId,
        'match_id' => $matchId,
        'entry_fee' => $entryFee,
        'room_code' => $roomCode,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ];
    
    $logFile = dirname(__DIR__) . '/logs/matchmaking.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    file_put_contents($logFile, json_encode($logEntry) . PHP_EOL, FILE_APPEND | LOCK_EX);
    
    // ==============================================
    // 9. RETURN SUCCESS RESPONSE
    // ==============================================
    jsonResponse(true, $data['message'], $data);
    
} catch (PDOException $e) {
    // Rollback transaction on error
    if (isset($db) && $db->inTransaction()) {
        $db->rollback();
    }
    
    // Log error
    $errorLog = [
        'timestamp' => date('Y-m-d H:i:s'),
        'error' => $e->getMessage(),
        'code' => $e->getCode(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'user_id' => $userId ?? null,
        'entry_fee' => $entryFee ?? null
    ];
    $logFile = dirname(__DIR__) . '/logs/matchmaking_errors.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    file_put_contents($logFile, json_encode($errorLog) . PHP_EOL, FILE_APPEND | LOCK_EX);
    
    jsonResponse(false, 'Database error occurred. Please try again.', [], 500);
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollback();
    }
    
    jsonResponse(false, $e->getMessage(), [], 500);
}

/**
 * ==============================================
 * HELPER: Refund user on failure
 * ==============================================
 */
function refundUser($db, $conn, $userId, $amount, $transactionId, $orderId) {
    try {
        // Refund the wallet
        $stmt = $conn->prepare("
            UPDATE users 
            SET wallet_balance = wallet_balance + :amount 
            WHERE id = :user_id
        ");
        $stmt->execute([
            ':amount' => $amount,
            ':user_id' => $userId
        ]);
        
        // Record refund transaction
        $stmt = $conn->prepare("
            INSERT INTO transactions (
                user_id, 
                amount, 
                type, 
                source, 
                description, 
                order_id, 
                status, 
                balance_before, 
                balance_after,
                created_at
            ) 
            SELECT 
                :user_id,
                :amount,
                'credit',
                'refund',
                'Matchmaking refund for order: ' || :order_id,
                :order_id || '-REFUND',
                'success',
                wallet_balance - :amount,
                wallet_balance,
                CURRENT_TIMESTAMP
            FROM users 
            WHERE id = :user_id
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':amount' => $amount,
            ':order_id' => $orderId
        ]);
        
        // Update original transaction to failed
        $stmt = $conn->prepare("
            UPDATE transactions 
            SET status = 'failed', 
                description = CONCAT(description, ' (Matchmaking failed - refunded)') 
            WHERE id = :transaction_id
        ");
        $stmt->execute([':transaction_id' => $transactionId]);
        
        return true;
    } catch (Exception $e) {
        // Log refund failure - this needs manual intervention
        $errorLog = [
            'timestamp' => date('Y-m-d H:i:s'),
            'error' => 'REFUND FAILED: ' . $e->getMessage(),
            'user_id' => $userId,
            'amount' => $amount,
            'transaction_id' => $transactionId
        ];
        $logFile = dirname(__DIR__) . '/logs/refund_errors.log';
        file_put_contents($logFile, json_encode($errorLog) . PHP_EOL, FILE_APPEND | LOCK_EX);
        return false;
    }
}
?>
