<?php
/**
 * ======================================================
 * ADMIN_WITHDRAWALS.PHP - Withdrawal Management API
 * Ludo Tournament Platform - Admin Withdrawal System
 * Version: 2.0.0 - COMPLETE
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
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-CSRF-Token');
header('Access-Control-Allow-Credentials: true');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ==============================================
// AUTHENTICATION & AUTHORIZATION
// ==============================================
session_start();

if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_token'])) {
    jsonResponse(false, 'Unauthorized - Admin access required', [], 401);
}

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    // Verify admin with database session token
    $stmt = $conn->prepare("
        SELECT u.id, u.username, u.is_admin, u.is_active 
        FROM users u
        JOIN sessions s ON u.id = s.user_id
        WHERE u.id = :admin_id 
        AND u.is_admin = 1 
        AND u.is_active = 1
        AND s.session_token = :token
        AND s.is_active = 1
        AND s.expires_at > NOW()
    ");
    $stmt->execute([
        ':admin_id' => $_SESSION['admin_id'],
        ':token' => $_SESSION['admin_token']
    ]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$admin) {
        http_response_code(401);
        jsonResponse(false, 'Unauthorized - Invalid admin session', [], 401);
    }
    
} catch (Exception $e) {
    jsonResponse(false, 'Authentication error: ' . $e->getMessage(), [], 500);
}

// ==============================================
// ROUTING
// ==============================================
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'list':
        handleList();
        break;
    case 'get':
        handleGet();
        break;
    case 'approve':
        handleApprove();
        break;
    case 'reject':
        handleReject();
        break;
    case 'process':
        handleProcess();
        break;
    case 'complete':
        handleComplete();
        break;
    case 'get_stats':
        handleStats();
        break;
    case 'get_user_withdrawals':
        handleUserWithdrawals();
        break;
    default:
        jsonResponse(false, 'Invalid action specified', [], 400);
        break;
}

// ==============================================
// HANDLER: List Withdrawals
// ==============================================
function handleList() {
    global $db, $conn;
    
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    $limit = intval($_GET['limit'] ?? 50);
    $offset = intval($_GET['offset'] ?? 0);
    $userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
    
    try {
        $where = "1=1";
        $params = [];
        
        if (!empty($status)) {
            $where .= " AND w.status = :status";
            $params[':status'] = $status;
        }
        
        if ($userId > 0) {
            $where .= " AND w.user_id = :user_id";
            $params[':user_id'] = $userId;
        }
        
        // Get total count
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM withdrawals w WHERE {$where}");
        $stmt->execute($params);
        $total = intval($stmt->fetchColumn());
        
        // Get withdrawals with user details
        $stmt = $conn->prepare("
            SELECT 
                w.id,
                w.user_id,
                w.amount,
                w.bank_account_number,
                w.bank_ifsc,
                w.bank_account_name,
                w.upi_id,
                w.transaction_id,
                w.status,
                w.admin_notes,
                w.rejection_reason,
                w.processed_by,
                w.processed_at,
                w.completed_at,
                w.created_at,
                w.updated_at,
                w.metadata,
                u.username,
                u.mobile,
                u.email,
                u.wallet_balance,
                u.kyc_status,
                u.is_active,
                u.total_earnings,
                u.total_withdrawn,
                u.pan_number
            FROM withdrawals w
            LEFT JOIN users u ON w.user_id = u.id
            WHERE {$where}
            ORDER BY 
                CASE w.status 
                    WHEN 'pending' THEN 1 
                    WHEN 'processing' THEN 2 
                    WHEN 'approved' THEN 3 
                    ELSE 4 
                END,
                w.created_at ASC
            LIMIT :limit OFFSET :offset
        ");
        
        $params[':limit'] = $limit;
        $params[':offset'] = $offset;
        $stmt->execute($params);
        $withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse(true, 'Withdrawals retrieved', [
            'withdrawals' => $withdrawals,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ]);
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Get Single Withdrawal
// ==============================================
function handleGet() {
    global $db, $conn;
    
    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) {
        jsonResponse(false, 'Invalid withdrawal ID', [], 400);
    }
    
    try {
        $stmt = $conn->prepare("
            SELECT 
                w.*,
                u.username,
                u.mobile,
                u.email,
                u.wallet_balance,
                u.kyc_status,
                u.is_active,
                u.total_earnings,
                u.total_withdrawn,
                u.pan_number,
                u.aadhaar_number,
                admin.username as processed_by_name
            FROM withdrawals w
            LEFT JOIN users u ON w.user_id = u.id
            LEFT JOIN users admin ON w.processed_by = admin.id
            WHERE w.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $withdrawal = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$withdrawal) {
            jsonResponse(false, 'Withdrawal not found', [], 404);
        }
        
        jsonResponse(true, 'Withdrawal retrieved', $withdrawal);
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Approve Withdrawal
// ==============================================
function handleApprove() {
    global $db, $conn;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['id'])) {
        jsonResponse(false, 'Missing withdrawal ID', [], 400);
    }
    
    $id = intval($input['id']);
    $notes = $input['notes'] ?? '';
    
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!CSRFToken::validate($csrfToken)) {
        jsonResponse(false, 'Invalid CSRF token', [], 403);
    }
    
    try {
        $db->beginTransaction();
        
        // Get withdrawal details with lock
        $stmt = $conn->prepare("
            SELECT user_id, amount, status, bank_account_number, bank_ifsc, bank_account_name
            FROM withdrawals 
            WHERE id = :id 
            FOR UPDATE
        ");
        $stmt->execute([':id' => $id]);
        $withdrawal = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$withdrawal) {
            $db->rollback();
            jsonResponse(false, 'Withdrawal not found', [], 404);
        }
        
        if ($withdrawal['status'] !== 'pending') {
            $db->rollback();
            jsonResponse(false, 'Withdrawal is already ' . $withdrawal['status'], [], 400);
        }
        
        // Check user balance
        $stmt = $conn->prepare("
            SELECT id, wallet_balance, is_active, kyc_status
            FROM users 
            WHERE id = :user_id 
            FOR UPDATE
        ");
        $stmt->execute([':user_id' => $withdrawal['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user || $user['is_active'] != 1) {
            $db->rollback();
            jsonResponse(false, 'User not found or inactive', [], 400);
        }
        
        if ($user['wallet_balance'] < $withdrawal['amount']) {
            $db->rollback();
            jsonResponse(false, 'Insufficient balance in user wallet', [], 400);
        }
        
        // Check KYC
        if ($user['kyc_status'] !== 'verified') {
            $db->rollback();
            jsonResponse(false, 'User KYC is not verified. Withdrawal requires verified KYC.', [], 400);
        }
        
        // Generate transaction ID
        $transactionId = 'WD-' . strtoupper(uniqid() . bin2hex(random_bytes(4)));
        
        // Update withdrawal status to approved
        $stmt = $conn->prepare("
            UPDATE withdrawals 
            SET 
                status = 'approved',
                processed_by = :admin_id,
                processed_at = CURRENT_TIMESTAMP,
                transaction_id = :transaction_id,
                admin_notes = CONCAT(COALESCE(admin_notes, ''), '\nApproved by admin: ', :notes),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute([
            ':admin_id' => $_SESSION['admin_id'],
            ':transaction_id' => $transactionId,
            ':notes' => $notes,
            ':id' => $id
        ]);
        
        // Deduct from user wallet
        $stmt = $conn->prepare("
            UPDATE users 
            SET 
                wallet_balance = wallet_balance - :amount,
                total_withdrawn = total_withdrawn + :amount,
                last_withdrawal_date = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :user_id
        ");
        $stmt->execute([
            ':amount' => $withdrawal['amount'],
            ':user_id' => $withdrawal['user_id']
        ]);
        
        // Record transaction
        $orderId = 'WITHDRAW-' . strtoupper(uniqid());
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
                metadata,
                created_at
            ) VALUES (
                :user_id,
                :amount,
                'debit',
                'withdrawal',
                :description,
                :order_id,
                'processing',
                :balance_before,
                :balance_after,
                :metadata,
                CURRENT_TIMESTAMP
            )
        ");
        $stmt->execute([
            ':user_id' => $withdrawal['user_id'],
            ':amount' => $withdrawal['amount'],
            ':description' => "Withdrawal approved - Bank: {$withdrawal['bank_account_number']}",
            ':order_id' => $orderId,
            ':balance_before' => $user['wallet_balance'],
            ':balance_after' => $user['wallet_balance'] - $withdrawal['amount'],
            ':metadata' => json_encode([
                'withdrawal_id' => $id,
                'transaction_id' => $transactionId,
                'admin_id' => $_SESSION['admin_id']
            ])
        ]);
        
        $db->commit();
        
        jsonResponse(true, 'Withdrawal approved successfully', [
            'transaction_id' => $transactionId,
            'amount' => $withdrawal['amount']
        ]);
        
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        jsonResponse(false, 'Error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Reject Withdrawal
// ==============================================
function handleReject() {
    global $db, $conn;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['id'])) {
        jsonResponse(false, 'Missing withdrawal ID', [], 400);
    }
    
    $id = intval($input['id']);
    $reason = $input['reason'] ?? 'Withdrawal rejected by admin';
    
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!CSRFToken::validate($csrfToken)) {
        jsonResponse(false, 'Invalid CSRF token', [], 403);
    }
    
    if (empty($reason) || strlen($reason) < 10) {
        jsonResponse(false, 'Please provide a detailed rejection reason (minimum 10 characters)', [], 400);
    }
    
    try {
        $db->beginTransaction();
        
        // Get withdrawal with lock
        $stmt = $conn->prepare("
            SELECT user_id, amount, status 
            FROM withdrawals 
            WHERE id = :id 
            FOR UPDATE
        ");
        $stmt->execute([':id' => $id]);
        $withdrawal = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$withdrawal) {
            $db->rollback();
            jsonResponse(false, 'Withdrawal not found', [], 404);
        }
        
        if ($withdrawal['status'] !== 'pending') {
            $db->rollback();
            jsonResponse(false, 'Only pending withdrawals can be rejected', [], 400);
        }
        
        // Get current user balance
        $stmt = $conn->prepare("
            SELECT wallet_balance 
            FROM users 
            WHERE id = :user_id 
            FOR UPDATE
        ");
        $stmt->execute([':user_id' => $withdrawal['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Refund amount to user wallet
        $stmt = $conn->prepare("
            UPDATE users 
            SET wallet_balance = wallet_balance + :amount, updated_at = CURRENT_TIMESTAMP 
            WHERE id = :user_id
        ");
        $stmt->execute([
            ':amount' => $withdrawal['amount'],
            ':user_id' => $withdrawal['user_id']
        ]);
        
        // Update withdrawal status
        $stmt = $conn->prepare("
            UPDATE withdrawals 
            SET 
                status = 'rejected',
                processed_by = :admin_id,
                processed_at = CURRENT_TIMESTAMP,
                rejection_reason = :reason,
                admin_notes = CONCAT(COALESCE(admin_notes, ''), '\nRejected: ', :reason),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute([
            ':admin_id' => $_SESSION['admin_id'],
            ':reason' => $reason,
            ':id' => $id
        ]);
        
        // Record refund transaction
        $orderId = 'REFUND-WD-' . strtoupper(uniqid());
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
                metadata,
                created_at
            ) VALUES (
                :user_id,
                :amount,
                'credit',
                'refund',
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
            ':user_id' => $withdrawal['user_id'],
            ':amount' => $withdrawal['amount'],
            ':description' => "Refund for rejected withdrawal #{$id}",
            ':order_id' => $orderId,
            ':balance_before' => $user['wallet_balance'],
            ':balance_after' => $user['wallet_balance'] + $withdrawal['amount'],
            ':metadata' => json_encode([
                'withdrawal_id' => $id,
                'rejection_reason' => $reason
            ])
        ]);
        
        $db->commit();
        
        jsonResponse(true, 'Withdrawal rejected and amount refunded', [
            'withdrawal_id' => $id,
            'refunded_amount' => $withdrawal['amount']
        ]);
        
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Process Withdrawal
// ==============================================
function handleProcess() {
    global $db, $conn;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['id'])) {
        jsonResponse(false, 'Missing withdrawal ID', [], 400);
    }
    
    $id = intval($input['id']);
    $notes = $input['notes'] ?? '';
    
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!CSRFToken::validate($csrfToken)) {
        jsonResponse(false, 'Invalid CSRF token', [], 403);
    }
    
    try {
        $db->beginTransaction();
        
        $stmt = $conn->prepare("
            SELECT status 
            FROM withdrawals 
            WHERE id = :id 
            FOR UPDATE
        ");
        $stmt->execute([':id' => $id]);
        $withdrawal = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$withdrawal) {
            $db->rollback();
            jsonResponse(false, 'Withdrawal not found', [], 404);
        }
        
        if ($withdrawal['status'] !== 'approved') {
            $db->rollback();
            jsonResponse(false, 'Withdrawal must be approved first', [], 400);
        }
        
        $stmt = $conn->prepare("
            UPDATE withdrawals 
            SET 
                status = 'processing',
                processed_by = :admin_id,
                processed_at = CURRENT_TIMESTAMP,
                admin_notes = CONCAT(COALESCE(admin_notes, ''), '\nProcessing started: ', :notes),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute([
            ':admin_id' => $_SESSION['admin_id'],
            ':notes' => $notes,
            ':id' => $id
        ]);
        
        $db->commit();
        
        jsonResponse(true, 'Withdrawal marked as processing', [
            'withdrawal_id' => $id
        ]);
        
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Complete Withdrawal
// ==============================================
function handleComplete() {
    global $db, $conn;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['id'])) {
        jsonResponse(false, 'Missing withdrawal ID', [], 400);
    }
    
    $id = intval($input['id']);
    $txnId = $input['transaction_id'] ?? '';
    $notes = $input['notes'] ?? '';
    
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!CSRFToken::validate($csrfToken)) {
        jsonResponse(false, 'Invalid CSRF token', [], 403);
    }
    
    try {
        $db->beginTransaction();
        
        $stmt = $conn->prepare("
            SELECT user_id, amount, status, transaction_id 
            FROM withdrawals 
            WHERE id = :id 
            FOR UPDATE
        ");
        $stmt->execute([':id' => $id]);
        $withdrawal = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$withdrawal) {
            $db->rollback();
            jsonResponse(false, 'Withdrawal not found', [], 404);
        }
        
        if (!in_array($withdrawal['status'], ['processing', 'approved'])) {
            $db->rollback();
            jsonResponse(false, 'Withdrawal must be in processing or approved state', [], 400);
        }
        
        $stmt = $conn->prepare("
            UPDATE withdrawals 
            SET 
                status = 'completed',
                completed_at = CURRENT_TIMESTAMP,
                transaction_id = COALESCE(:txn_id, transaction_id),
                admin_notes = CONCAT(COALESCE(admin_notes, ''), '\nCompleted: ', :notes),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute([
            ':txn_id' => $txnId ?: $withdrawal['transaction_id'],
            ':notes' => $notes,
            ':id' => $id
        ]);
        
        // Update transaction status
        $stmt = $conn->prepare("
            UPDATE transactions 
            SET status = 'success', processed_at = CURRENT_TIMESTAMP 
            WHERE order_id LIKE CONCAT('WITHDRAW-%', :id, '%')
            OR description LIKE CONCAT('%', :id, '%')
        ");
        $stmt->execute([':id' => $id]);
        
        $db->commit();
        
        jsonResponse(true, 'Withdrawal completed successfully', [
            'withdrawal_id' => $id
        ]);
        
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Withdrawal Statistics
// ==============================================
function handleStats() {
    global $db, $conn;
    
    try {
        $stats = [];
        
        $stmt = $conn->query("SELECT COUNT(*) as pending FROM withdrawals WHERE status = 'pending'");
        $stats['pending'] = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("SELECT COUNT(*) as processing FROM withdrawals WHERE status = 'processing'");
        $stats['processing'] = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("SELECT COUNT(*) as approved FROM withdrawals WHERE status = 'approved'");
        $stats['approved'] = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("SELECT COUNT(*) as completed FROM withdrawals WHERE status = 'completed'");
        $stats['completed'] = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("SELECT COUNT(*) as rejected FROM withdrawals WHERE status = 'rejected'");
        $stats['rejected'] = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("
            SELECT SUM(amount) as total_pending 
            FROM withdrawals 
            WHERE status = 'pending'
        ");
        $stats['total_pending_amount'] = floatval($stmt->fetchColumn());
        
        $stmt = $conn->query("
            SELECT SUM(amount) as total_processed 
            FROM withdrawals 
            WHERE status IN ('approved', 'completed')
        ");
        $stats['total_processed_amount'] = floatval($stmt->fetchColumn());
        
        $stmt = $conn->query("
            SELECT SUM(amount) as total_amount 
            FROM withdrawals
        ");
        $stats['total_amount'] = floatval($stmt->fetchColumn());
        
        $stmt = $conn->query("
            SELECT COUNT(*) as today 
            FROM withdrawals 
            WHERE DATE(created_at) = CURDATE()
        ");
        $stats['today'] = intval($stmt->fetchColumn());
        
        jsonResponse(true, 'Withdrawal statistics retrieved', $stats);
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Get User Withdrawals
// ==============================================
function handleUserWithdrawals() {
    global $db, $conn;
    
    $userId = intval($_GET['user_id'] ?? 0);
    $limit = intval($_GET['limit'] ?? 20);
    
    if ($userId <= 0) {
        jsonResponse(false, 'Invalid user ID', [], 400);
    }
    
    try {
        $stmt = $conn->prepare("
            SELECT 
                id,
                amount,
                bank_account_number,
                bank_ifsc,
                bank_account_name,
                status,
                rejection_reason,
                created_at,
                processed_at,
                completed_at
            FROM withdrawals 
            WHERE user_id = :user_id
            ORDER BY created_at DESC
            LIMIT :limit
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':limit' => $limit
        ]);
        $withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse(true, 'User withdrawals retrieved', $withdrawals);
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}
?>
