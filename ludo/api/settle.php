<?php
/**
 * ======================================================
 * SETTLE.PHP - Automated 15% Commission Processor
 * Ludo Tournament Platform - Match Settlement Engine
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
// CORS Headers
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
$required = ['match_id', 'winner_id'];
foreach ($required as $field) {
    if (!isset($input[$field]) || empty($input[$field])) {
        jsonResponse(false, "Missing required field: {$field}", [], 400);
    }
}

$matchId = intval($input['match_id']);
$winnerId = intval($input['winner_id']);

if ($matchId <= 0) {
    jsonResponse(false, 'Invalid match ID', [], 400);
}

if ($winnerId <= 0) {
    jsonResponse(false, 'Invalid winner ID', [], 400);
}

// Validate CSRF token
$csrfToken = $input['csrf_token'] ?? $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!CSRFToken::validate($csrfToken)) {
    jsonResponse(false, 'Invalid CSRF token', [], 403);
}

// Validate user authentication
if (!isLoggedIn()) {
    jsonResponse(false, 'User not authenticated', [], 401);
}

$userId = getCurrentUserId();
if (!$userId) {
    jsonResponse(false, 'Invalid user session', [], 401);
}

// Verify user is authorized (must be match participant or admin)
$isAuthorized = false;

// ==============================================
// MAIN SETTLEMENT LOGIC
// ==============================================

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    // Begin transaction for atomic operations
    $db->beginTransaction();
    
    // ==============================================
    // 1. FETCH MATCH DETAILS WITH LOCK
    // ==============================================
    $stmt = $conn->prepare("
        SELECT 
            id,
            tournament_id,
            room_code,
            entry_fee,
            prize_pool,
            platform_fee,
            player1_id,
            player2_id,
            player1_name,
            player2_name,
            status,
            winner_id,
            winning_amount,
            completed_at,
            created_at
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
    
    // ==============================================
    // 2. VALIDATE MATCH STATE
    // ==============================================
    if ($match['status'] === 'completed') {
        $db->rollback();
        jsonResponse(false, 'Match already settled', [], 409, [
            'match_id' => $matchId,
            'winner_id' => $match['winner_id'],
            'winning_amount' => floatval($match['winning_amount'])
        ]);
    }
    
    if ($match['status'] !== 'playing' && $match['status'] !== 'ready') {
        $db->rollback();
        jsonResponse(false, 'Match is not in playable state', [], 400, [
            'current_status' => $match['status']
        ]);
    }
    
    // ==============================================
    // 3. VERIFY WINNER IS IN THE MATCH
    // ==============================================
    $playerIds = [
        intval($match['player1_id']),
        intval($match['player2_id'])
    ];
    
    // Handle 4-player matches if applicable
    if (isset($match['player3_id']) && $match['player3_id']) {
        $playerIds[] = intval($match['player3_id']);
    }
    if (isset($match['player4_id']) && $match['player4_id']) {
        $playerIds[] = intval($match['player4_id']);
    }
    
    if (!in_array($winnerId, $playerIds)) {
        $db->rollback();
        jsonResponse(false, 'Winner is not a participant in this match', [], 400);
    }
    
    // ==============================================
    // 4. CHECK IF USER IS AUTHORIZED TO SETTLE
    // ==============================================
    // Allow if user is winner, or admin (to be checked)
    if ($userId != $winnerId) {
        // Check if user is admin
        $stmt = $conn->prepare("
            SELECT is_admin 
            FROM users 
            WHERE id = :user_id 
            AND is_admin = 1
        ");
        $stmt->execute([':user_id' => $userId]);
        $isAdmin = $stmt->fetchColumn();
        
        if (!$isAdmin) {
            $db->rollback();
            jsonResponse(false, 'Unauthorized to settle this match', [], 403);
        }
    }
    
    // ==============================================
    // 5. CALCULATE AMOUNTS
    // ==============================================
    $entryFee = floatval($match['entry_fee']);
    $totalPlayers = count($playerIds);
    
    // Gross Pool = Entry Fee × Number of Players
    $grossPool = $entryFee * $totalPlayers;
    
    // Admin Fee = Gross Pool × 0.15 (15%)
    $adminFee = round($grossPool * 0.15, 2);
    
    // Net Payout = Gross Pool - Admin Fee
    $netPayout = round($grossPool - $adminFee, 2);
    
    // ==============================================
    // 6. FETCH WINNER DETAILS WITH LOCK
    // ==============================================
    $stmt = $conn->prepare("
        SELECT 
            id,
            username,
            wallet_balance,
            total_matches_played,
            total_matches_won,
            total_earnings,
            elo_rating
        FROM users 
        WHERE id = :winner_id
        FOR UPDATE
    ");
    $stmt->execute([':winner_id' => $winnerId]);
    $winner = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$winner) {
        $db->rollback();
        jsonResponse(false, 'Winner user not found', [], 404);
    }
    
    // ==============================================
    // 7. CREDIT WINNER'S WALLET
    // ==============================================
    $currentBalance = floatval($winner['wallet_balance']);
    $newBalance = $currentBalance + $netPayout;
    
    $stmt = $conn->prepare("
        UPDATE users 
        SET 
            wallet_balance = wallet_balance + :amount,
            total_matches_played = total_matches_played + 1,
            total_matches_won = total_matches_won + 1,
            total_earnings = total_earnings + :amount,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :winner_id
    ");
    $stmt->execute([
        ':amount' => $netPayout,
        ':winner_id' => $winnerId
    ]);
    
    if ($stmt->rowCount() === 0) {
        $db->rollback();
        jsonResponse(false, 'Failed to credit winner wallet', [], 500);
    }
    
    // ==============================================
    // 8. GENERATE ORDER ID FOR TRANSACTION
    // ==============================================
    $orderId = 'WIN-' . strtoupper(uniqid() . bin2hex(random_bytes(4)));
    
    // ==============================================
    // 9. RECORD WINNER TRANSACTION
    // ==============================================
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
            metadata,
            created_at
        ) VALUES (
            :user_id,
            :tournament_id,
            :match_id,
            :amount,
            'credit',
            'match_win',
            :description,
            :order_id,
            'success',
            :balance_before,
            :balance_after,
            :metadata,
            CURRENT_TIMESTAMP
        )
    ");
    $stmt->execute([
        ':user_id' => $winnerId,
        ':tournament_id' => $match['tournament_id'],
        ':match_id' => $matchId,
        ':amount' => $netPayout,
        ':description' => "Match win settlement - Room: {$match['room_code']}",
        ':order_id' => $orderId,
        ':balance_before' => $currentBalance,
        ':balance_after' => $newBalance,
        ':metadata' => json_encode([
            'gross_pool' => $grossPool,
            'admin_fee' => $adminFee,
            'entry_fee' => $entryFee,
            'players' => $totalPlayers,
            'room_code' => $match['room_code']
        ])
    ]);
    
    $winnerTxId = $conn->lastInsertId();
    
    // ==============================================
    // 10. RECORD ADMIN FEE TRANSACTION (Platform Revenue)
    // ==============================================
    $adminOrderId = 'ADMIN-' . strtoupper(uniqid() . bin2hex(random_bytes(4)));
    
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
            metadata,
            created_at
        ) VALUES (
            :user_id,
            :tournament_id,
            :match_id,
            :amount,
            'credit',
            'deposit',
            :description,
            :order_id,
            'success',
            0,
            :amount,
            :metadata,
            CURRENT_TIMESTAMP
        )
    ");
    $stmt->execute([
        ':user_id' => 1, // Admin user ID (assuming ID 1 is admin)
        ':tournament_id' => $match['tournament_id'],
        ':match_id' => $matchId,
        ':amount' => $adminFee,
        ':description' => "Platform commission (15%) - Room: {$match['room_code']}",
        ':order_id' => $adminOrderId,
        ':metadata' => json_encode([
            'gross_pool' => $grossPool,
            'admin_fee' => $adminFee,
            'match_id' => $matchId,
            'winner_id' => $winnerId
        ])
    ]);
    
    $adminTxId = $conn->lastInsertId();
    
    // ==============================================
    // 11. UPDATE MATCH STATUS
    // ==============================================
    $stmt = $conn->prepare("
        UPDATE matches 
        SET 
            status = 'completed',
            winner_id = :winner_id,
            winner_name = :winner_name,
            winning_amount = :winning_amount,
            completed_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :match_id
    ");
    $stmt->execute([
        ':winner_id' => $winnerId,
        ':winner_name' => $winner['username'],
        ':winning_amount' => $netPayout,
        ':match_id' => $matchId
    ]);
    
    // ==============================================
    // 12. UPDATE TOURNAMENT STATISTICS
    // ==============================================
    if ($match['tournament_id']) {
        $stmt = $conn->prepare("
            UPDATE tournaments 
            SET 
                status = 'completed',
                winner_id = :winner_id,
                winner_amount = :winner_amount,
                end_time = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :tournament_id
            AND status != 'completed'
        ");
        $stmt->execute([
            ':winner_id' => $winnerId,
            ':winner_amount' => $netPayout,
            ':tournament_id' => $match['tournament_id']
        ]);
    }
    
    // ==============================================
    // 13. UPDATE LEADERBOARD
    // ==============================================
    $stmt = $conn->prepare("
        INSERT INTO leaderboard (
            user_id,
            username,
            elo_rating,
            matches_played,
            matches_won,
            total_earnings,
            last_updated
        ) VALUES (
            :user_id,
            :username,
            :elo_rating,
            1,
            1,
            :earnings,
            CURRENT_TIMESTAMP
        )
        ON DUPLICATE KEY UPDATE
            elo_rating = elo_rating + 10,
            matches_played = matches_played + 1,
            matches_won = matches_won + 1,
            total_earnings = total_earnings + :earnings,
            last_updated = CURRENT_TIMESTAMP
    ");
    $stmt->execute([
        ':user_id' => $winnerId,
        ':username' => $winner['username'],
        ':elo_rating' => $winner['elo_rating'] + 10,
        ':earnings' => $netPayout
    ]);
    
    // ==============================================
    // 14. LOG SETTLEMENT EVENT
    // ==============================================
    $logEntry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'event' => 'match_settled',
        'match_id' => $matchId,
        'room_code' => $match['room_code'],
        'winner_id' => $winnerId,
        'winner_name' => $winner['username'],
        'gross_pool' => $grossPool,
        'admin_fee' => $adminFee,
        'net_payout' => $netPayout,
        'winner_tx_id' => $winnerTxId,
        'admin_tx_id' => $adminTxId,
        'settled_by' => $userId,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ];
    
    $logFile = dirname(__DIR__) . '/logs/settlements.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    file_put_contents($logFile, json_encode($logEntry) . PHP_EOL, FILE_APPEND | LOCK_EX);
    
    // ==============================================
    // 15. COMMIT TRANSACTION
    // ==============================================
    $db->commit();
    
    // ==============================================
    // 16. RETURN SUCCESS RESPONSE
    // ==============================================
    jsonResponse(true, 'Match settled successfully', [
        'match_id' => $matchId,
        'room_code' => $match['room_code'],
        'winner_id' => $winnerId,
        'winner_name' => $winner['username'],
        'gross_pool' => $grossPool,
        'admin_fee' => $adminFee,
        'net_payout' => $netPayout,
        'new_balance' => $newBalance,
        'settled_at' => date('Y-m-d H:i:s')
    ]);
    
} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollback();
    }
    
    $errorLog = [
        'timestamp' => date('Y-m-d H:i:s'),
        'error' => $e->getMessage(),
        'code' => $e->getCode(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'match_id' => $matchId ?? null,
        'winner_id' => $winnerId ?? null
    ];
    $logFile = dirname(__DIR__) . '/logs/settlement_errors.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    file_put_contents($logFile, json_encode($errorLog) . PHP_EOL, FILE_APPEND | LOCK_EX);
    
    jsonResponse(false, 'Database error occurred during settlement', [], 500);
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollback();
    }
    
    jsonResponse(false, $e->getMessage(), [], 500);
}
?>
