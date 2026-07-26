<?php
/**
 * ======================================================
 * WALLET.PHP - Wallet Management API
 * Ludo Tournament Platform - Complete Wallet System
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
    jsonResponse(false, 'Please login first', [], 401);
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
    case 'balance':
        handleGetBalance();
        break;
    case 'deposit':
        handleDeposit();
        break;
    case 'withdraw':
        handleWithdraw();
        break;
    case 'history':
        handleGetHistory();
        break;
    default:
        jsonResponse(false, 'Invalid action specified', [], 400);
        break;
}

// ==============================================
// HANDLER: Get Balance
// ==============================================
function handleGetBalance() {
    global $userId;
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("
            SELECT wallet_balance, total_earnings, total_withdrawn, referral_earnings
            FROM users 
            WHERE id = :user_id
        ");
        $stmt->execute([':user_id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        jsonResponse(true, 'Balance retrieved', [
            'balance' => floatval($user['wallet_balance']),
            'total_earnings' => floatval($user['total_earnings']),
            'total_withdrawn' => floatval($user['total_withdrawn']),
            'referral_earnings' => floatval($user['referral_earnings'] ?? 0)
        ]);
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Deposit
// ==============================================
function handleDeposit() {
    global $userId;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    if (!isset($input['amount'])) {
        jsonResponse(false, 'Amount required', [], 400);
    }
    
    $amount = floatval($input['amount']);
    
    // Validate CSRF token
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!CSRFToken::validate($csrfToken)) {
        jsonResponse(false, 'Invalid CSRF token', [], 403);
    }
    
    if ($amount <= 0 || $amount > 100000) {
        jsonResponse(false, 'Invalid amount. Must be between 1 and 100,000', [], 400);
    }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $db->beginTransaction();
        
        // Get user balance with lock
        $stmt = $conn->prepare("
            SELECT wallet_balance 
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
        
        // Update wallet
        $newBalance = $user['wallet_balance'] + $amount;
        $stmt = $conn->prepare("
            UPDATE users 
            SET wallet_balance = wallet_balance + :amount, 
                updated_at = CURRENT_TIMESTAMP 
            WHERE id = :user_id
        ");
        $stmt->execute([
            ':amount' => $amount,
            ':user_id' => $userId
        ]);
        
        // Record transaction
        $orderId = 'DEP-' . strtoupper(uniqid() . bin2hex(random_bytes(4)));
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
            ) VALUES (
                :user_id,
                :amount,
                'credit',
                'deposit',
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
            ':amount' => $amount,
            ':description' => "Wallet deposit of ₹" . number_format($amount, 2),
            ':order_id' => $orderId,
            ':balance_before' => $user['wallet_balance'],
            ':balance_after' => $newBalance
        ]);
        
        $db->commit();
        
        jsonResponse(true, 'Deposit successful', [
            'balance' => $newBalance,
            'amount' => $amount,
            'transaction_id' => $orderId
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

// ==============================================
// HANDLER: Withdraw
// ==============================================
function handleWithdraw() {
    global $userId;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    // Validate required fields
    $required = ['amount', 'bank_account_number', 'bank_ifsc', 'bank_account_name'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || empty($input[$field])) {
            jsonResponse(false, "Missing required field: {$field}", [], 400);
        }
    }
    
    $amount = floatval($input['amount']);
    $bankAccountNumber = trim($input['bank_account_number']);
    $bankIfsc = trim($input['bank_ifsc']);
    $bankAccountName = trim($input['bank_account_name']);
    $upiId = trim($input['upi_id'] ?? '');
    
    // Validate CSRF token
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!CSRFToken::validate($csrfToken)) {
        jsonResponse(false, 'Invalid CSRF token', [], 403);
    }
    
    if ($amount <= 0 || $amount > 50000) {
        jsonResponse(false, 'Invalid amount. Must be between 1 and 50,000', [], 400);
    }
    
    if (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $bankIfsc)) {
        jsonResponse(false, 'Invalid IFSC code format', [], 400);
    }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $db->beginTransaction();
        
        // Get user with lock
        $stmt = $conn->prepare("
            SELECT id, wallet_balance, kyc_status, is_active 
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
        
        // Check KYC
        if ($user['kyc_status'] !== 'verified') {
            $db->rollback();
            jsonResponse(false, 'KYC verification required for withdrawal', [], 403);
        }
        
        // Check balance
        if ($user['wallet_balance'] < $amount) {
            $db->rollback();
            jsonResponse(false, 'Insufficient balance', [], 400);
        }
        
        // Create withdrawal request
        $stmt = $conn->prepare("
            INSERT INTO withdrawals (
                user_id,
                amount,
                bank_account_number,
                bank_ifsc,
                bank_account_name,
                upi_id,
                status,
                created_at,
                updated_at
            ) VALUES (
                :user_id,
                :amount,
                :bank_account_number,
                :bank_ifsc,
                :bank_account_name,
                :upi_id,
                'pending',
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            )
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':amount' => $amount,
            ':bank_account_number' => $bankAccountNumber,
            ':bank_ifsc' => $bankIfsc,
            ':bank_account_name' => $bankAccountName,
            ':upi_id' => $upiId
        ]);
        
        $withdrawalId = $conn->lastInsertId();
        
        // Record transaction
        $orderId = 'WD-' . strtoupper(uniqid() . bin2hex(random_bytes(4)));
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
                'pending',
                :balance_before,
                :balance_after,
                :metadata,
                CURRENT_TIMESTAMP
            )
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':amount' => $amount,
            ':description' => "Withdrawal request to bank: {$bankAccountNumber}",
            ':order_id' => $orderId,
            ':balance_before' => $user['wallet_balance'],
            ':balance_after' => $user['wallet_balance'] - $amount,
            ':metadata' => json_encode([
                'withdrawal_id' => $withdrawalId,
                'bank_account' => $bankAccountNumber
            ])
        ]);
        
        // Deduct wallet balance immediately (pending approval)
        $stmt = $conn->prepare("
            UPDATE users 
            SET wallet_balance = wallet_balance - :amount, 
                updated_at = CURRENT_TIMESTAMP 
            WHERE id = :user_id
        ");
        $stmt->execute([
            ':amount' => $amount,
            ':user_id' => $userId
        ]);
        
        $db->commit();
        
        jsonResponse(true, 'Withdrawal request submitted successfully', [
            'withdrawal_id' => $withdrawalId,
            'amount' => $amount,
            'balance' => $user['wallet_balance'] - $amount,
            'status' => 'pending'
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

// ==============================================
// HANDLER: Get Transaction History
// ==============================================
function handleGetHistory() {
    global $userId;
    
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
    $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
    $type = isset($_GET['type']) ? $_GET['type'] : '';
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $where = "user_id = :user_id";
        $params = [':user_id' => $userId];
        
        if (!empty($type) && in_array($type, ['credit', 'debit'])) {
            $where .= " AND type = :type";
            $params[':type'] = $type;
        }
        
        // Get total count
        $stmt = $conn->prepare("
            SELECT COUNT(*) as total 
            FROM transactions 
            WHERE {$where}
        ");
        $stmt->execute($params);
        $total = intval($stmt->fetchColumn());
        
        // Get transactions
        $stmt = $conn->prepare("
            SELECT 
                id,
                amount,
                type,
                source,
                description,
                order_id,
                status,
                balance_before,
                balance_after,
                created_at,
                processed_at
            FROM transactions 
            WHERE {$where}
            ORDER BY created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $params[':limit'] = $limit;
        $params[':offset'] = $offset;
        $stmt->execute($params);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse(true, 'Transaction history retrieved', [
            'transactions' => $transactions,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ]);
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}
?>
