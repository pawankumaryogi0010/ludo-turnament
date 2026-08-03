<?php
/**
 * ======================================================
 * WALLET.PHP - Atomic Wallet Operations (FIXED)
 * Ludo Tournament Platform - Row Locking + Auth Fix
 * Version: 2.1.0 - SESSION AUTH FIX + 401 FIX
 * ======================================================
 */

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once dirname(__DIR__) . '/config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// CORS
$allowedOrigins = [
    rtrim(BASE_URL, '/'),
    'http://localhost',
    'http://localhost:3000',
    'http://127.0.0.1',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array(rtrim($origin, '/'), $allowedOrigins) || empty($origin)) {
    header('Access-Control-Allow-Origin: ' . ($origin ?: rtrim(BASE_URL, '/')));
} else {
    header('Access-Control-Allow-Origin: ' . rtrim(BASE_URL, '/'));
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
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
    jsonResponse(false, 'Please login first', [], 401);
}

$userId = getCurrentUserId();
if (!$userId || $userId <= 0) {
    jsonResponse(false, 'Invalid session', [], 401);
}

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
$input = json_decode(file_get_contents('php://input'), true) ?? [];

$csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

// Skip CSRF for balance check (GET request)
if (!CSRFToken::validate($csrfToken) && $action !== 'balance') {
    jsonResponse(false, 'Invalid CSRF token', [], 403);
}

switch ($action) {
    case 'balance':
        handleGetBalance($userId);
        break;
    case 'deposit':
        handleDeposit($userId, $input);
        break;
    case 'withdraw':
        handleWithdraw($userId, $input);
        break;
    case 'history':
        handleGetHistory($userId);
        break;
    default:
        jsonResponse(false, 'Invalid action', [], 400);
}

// ==============================================
// GET BALANCE
// ==============================================
function handleGetBalance(int $userId): void
{
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();

        $stmt = $conn->prepare("
            SELECT wallet_balance, total_earnings, total_withdrawn,
                   referral_earnings, is_active
            FROM users WHERE id = :uid
        ");
        $stmt->execute([':uid' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            jsonResponse(false, 'User not found', [], 404);
        }

        jsonResponse(true, 'Balance retrieved', [
            'balance' => floatval($user['wallet_balance'] ?? 0),
            'total_earnings' => floatval($user['total_earnings'] ?? 0),
            'total_withdrawn' => floatval($user['total_withdrawn'] ?? 0),
            'referral_earnings' => floatval($user['referral_earnings'] ?? 0),
            'is_active' => boolval($user['is_active']),
        ]);

    } catch (PDOException $e) {
        error_log('Wallet balance error: ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// DEPOSIT (ATOMIC)
// ==============================================
function handleDeposit(int $userId, array $input): void
{
    $amount = floatval($input['amount'] ?? 0);
    $paymentMethod = $input['payment_method'] ?? 'cashfree';

    if ($amount <= 0 || $amount > 100000) {
        jsonResponse(false, 'Amount must be between ₹1 and ₹100,000', [], 400);
    }

    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();

        $db->beginTransaction();

        // LOCK USER ROW
        $stmt = $conn->prepare("
            SELECT wallet_balance FROM users WHERE id = :uid FOR UPDATE
        ");
        $stmt->execute([':uid' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $db->rollback();
            jsonResponse(false, 'User not found', [], 404);
        }

        $currentBalance = floatval($user['wallet_balance'] ?? 0);
        $newBalance = $currentBalance + $amount;

        // Atomic update
        $stmt = $conn->prepare("
            UPDATE users SET 
                wallet_balance = wallet_balance + :amount,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :uid
        ");
        $stmt->execute([':amount' => $amount, ':uid' => $userId]);

        // Record transaction
        $orderId = 'DEP-' . strtoupper(uniqid());
        $stmt = $conn->prepare("
            INSERT INTO transactions (
                user_id, amount, type, source, description,
                order_id, status, balance_before, balance_after,
                payment_gateway, metadata, created_at
            ) VALUES (
                :uid, :amount, 'credit', 'deposit', :desc,
                :oid, 'success', :bal_before, :bal_after,
                :pg, :meta, CURRENT_TIMESTAMP
            )
        ");
        $stmt->execute([
            ':uid' => $userId,
            ':amount' => $amount,
            ':desc' => "Wallet deposit via {$paymentMethod}",
            ':oid' => $orderId,
            ':bal_before' => $currentBalance,
            ':bal_after' => $newBalance,
            ':pg' => $paymentMethod,
            ':meta' => json_encode(['payment_method' => $paymentMethod])
        ]);

        $db->commit();

        jsonResponse(true, 'Deposit successful', [
            'balance' => $newBalance,
            'amount' => $amount,
            'transaction_id' => $orderId,
        ]);

    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollback();
        error_log('Wallet deposit error: ' . $e->getMessage());
        jsonResponse(false, 'Deposit failed', [], 500);
    }
}

// ==============================================
// WITHDRAW (ATOMIC WITH LOCK)
// ==============================================
function handleWithdraw(int $userId, array $input): void
{
    $amount = floatval($input['amount'] ?? 0);
    $bankAccountNumber = trim($input['bank_account_number'] ?? '');
    $bankIfsc = trim($input['bank_ifsc'] ?? '');
    $bankAccountName = trim($input['bank_account_name'] ?? '');
    $upiId = trim($input['upi_id'] ?? '');

    if ($amount <= 0 || $amount > 50000) {
        jsonResponse(false, 'Amount must be between ₹1 and ₹50,000', [], 400);
    }

    if (empty($bankAccountNumber) || empty($bankIfsc) || empty($bankAccountName)) {
        jsonResponse(false, 'Bank account details are required', [], 400);
    }

    if (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $bankIfsc)) {
        jsonResponse(false, 'Invalid IFSC code format', [], 400);
    }

    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();

        $db->beginTransaction();

        // LOCK USER ROW
        $stmt = $conn->prepare("
            SELECT id, wallet_balance, kyc_status, is_active
            FROM users WHERE id = :uid FOR UPDATE
        ");
        $stmt->execute([':uid' => $userId]);
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

        $currentBalance = floatval($user['wallet_balance'] ?? 0);

        if ($currentBalance < $amount) {
            $db->rollback();
            jsonResponse(false, 'Insufficient balance', [], 400);
        }

        // Atomic withdraw
        $stmt = $conn->prepare("
            UPDATE users SET 
                wallet_balance = wallet_balance - :amount,
                total_withdrawn = total_withdrawn + :amount,
                last_withdrawal_date = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :uid AND wallet_balance >= :amount
        ");
        $stmt->execute([':amount' => $amount, ':uid' => $userId]);

        if ($stmt->rowCount() === 0) {
            $db->rollback();
            jsonResponse(false, 'Withdrawal failed - insufficient balance', [], 400);
        }

        $newBalance = $currentBalance - $amount;

        // Create withdrawal request
        $stmt = $conn->prepare("
            INSERT INTO withdrawals (
                user_id, amount, bank_account_number, bank_ifsc,
                bank_account_name, upi_id, status, created_at, updated_at
            ) VALUES (
                :uid, :amount, :acct, :ifsc, :name, :upi,
                'pending', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
        ");
        $stmt->execute([
            ':uid' => $userId,
            ':amount' => $amount,
            ':acct' => $bankAccountNumber,
            ':ifsc' => $bankIfsc,
            ':name' => $bankAccountName,
            ':upi' => $upiId
        ]);

        $withdrawalId = $conn->lastInsertId();

        // Record transaction
        $orderId = 'WD-' . strtoupper(uniqid());
        $stmt = $conn->prepare("
            INSERT INTO transactions (
                user_id, amount, type, source, description,
                order_id, status, balance_before, balance_after,
                metadata, created_at
            ) VALUES (
                :uid, :amount, 'debit', 'withdrawal', :desc,
                :oid, 'pending', :bal_before, :bal_after,
                :meta, CURRENT_TIMESTAMP
            )
        ");
        $stmt->execute([
            ':uid' => $userId,
            ':amount' => $amount,
            ':desc' => "Withdrawal to bank: {$bankAccountNumber}",
            ':oid' => $orderId,
            ':bal_before' => $currentBalance,
            ':bal_after' => $newBalance,
            ':meta' => json_encode(['withdrawal_id' => $withdrawalId])
        ]);

        $db->commit();

        jsonResponse(true, 'Withdrawal request submitted', [
            'withdrawal_id' => $withdrawalId,
            'amount' => $amount,
            'balance' => $newBalance,
            'status' => 'pending'
        ]);

    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollback();
        error_log('Wallet withdraw error: ' . $e->getMessage());
        jsonResponse(false, 'Withdrawal failed', [], 500);
    }
}

// ==============================================
// TRANSACTION HISTORY (FIXED - PROPER 401)
// ==============================================
function handleGetHistory(int $userId): void
{
    $limit = intval($_GET['limit'] ?? 20);
    $offset = intval($_GET['offset'] ?? 0);
    $type = $_GET['type'] ?? '';

    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();

        $where = "user_id = :uid";
        $params = [':uid' => $userId];

        if (!empty($type) && in_array($type, ['credit', 'debit'])) {
            $where .= " AND type = :type";
            $params[':type'] = $type;
        }

        // Total count
        $stmt = $conn->prepare("SELECT COUNT(*) FROM transactions WHERE {$where}");
        $stmt->execute($params);
        $total = intval($stmt->fetchColumn());

        // Fetch transactions
        $stmt = $conn->prepare("
            SELECT id, amount, type, source, description, order_id,
                   status, balance_before, balance_after, tds_deducted,
                   created_at, processed_at
            FROM transactions WHERE {$where}
            ORDER BY created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $params[':limit'] = $limit;
        $params[':offset'] = $offset;
        $stmt->execute($params);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Convert numeric fields
        foreach ($transactions as &$tx) {
            $tx['amount'] = floatval($tx['amount'] ?? 0);
            $tx['balance_before'] = floatval($tx['balance_before'] ?? 0);
            $tx['balance_after'] = floatval($tx['balance_after'] ?? 0);
            $tx['tds_deducted'] = floatval($tx['tds_deducted'] ?? 0);
        }
        unset($tx);

        jsonResponse(true, 'Transaction history retrieved', [
            'transactions' => $transactions ?: [],
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ]);

    } catch (PDOException $e) {
        error_log('Wallet history error: ' . $e->getMessage());
        jsonResponse(false, 'Error fetching history', [], 500);
    }
}
