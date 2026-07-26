<?php
/**
 * ======================================================
 * WALLET.PHP - Atomic Wallet Operations (V1)
 * Ludo Tournament Platform - Row Locking
 * Version: 1.0.0 - COMPLETE REWRITE
 * ======================================================
 */

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

require_once dirname(__DIR__, 2) . '/config/db.php';

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

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
$input = json_decode(file_get_contents('php://input'), true) ?? [];

$csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
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
        break;
}

// ==============================================
// HANDLER: Get Balance
// ==============================================
function handleGetBalance(int $userId): void
{
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();

        $stmt = $conn->prepare("
            SELECT
                wallet_balance,
                total_earnings,
                total_withdrawn,
                referral_earnings,
                is_active
            FROM users
            WHERE id = :user_id
        ");
        $stmt->execute([':user_id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            jsonResponse(false, 'User not found', [], 404);
        }

        jsonResponse(true, 'Balance retrieved', [
            'balance' => floatval($user['wallet_balance']),
            'total_earnings' => floatval($user['total_earnings']),
            'total_withdrawn' => floatval($user['total_withdrawn']),
            'referral_earnings' => floatval($user['referral_earnings'] ?? 0),
            'is_active' => boolval($user['is_active']),
        ]);

    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Deposit (ATOMIC)
// ==============================================
function handleDeposit(int $userId, array $input): void
{
    $amount = floatval($input['amount'] ?? 0);
    $paymentMethod = $input['payment_method'] ?? 'cashfree';

    if ($amount <= 0 || $amount > 100000) {
        jsonResponse(false, 'Invalid amount. Must be between 1 and 100,000', [], 400);
    }

    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();

        $db->beginTransaction();

        // LOCK USER ROW FOR UPDATE
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

        $currentBalance = floatval($user['wallet_balance']);
        $newBalance = $currentBalance + $amount;

        // Update balance with atomic operation
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

        if ($stmt->rowCount() === 0) {
            $db->rollback();
            jsonResponse(false, 'Failed to update balance', [], 500);
        }

        // Record transaction
        $orderId = 'DEP-' . strtoupper(uniqid());
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
                payment_gateway,
                metadata,
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
                :payment_gateway,
                :metadata,
                CURRENT_TIMESTAMP
            )
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':amount' => $amount,
            ':description' => "Wallet deposit via {$paymentMethod}",
            ':order_id' => $orderId,
            ':balance_before' => $currentBalance,
            ':balance_after' => $newBalance,
            ':payment_gateway' => $paymentMethod,
            ':metadata' => json_encode([
                'payment_method' => $paymentMethod,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ])
        ]);

        $db->commit();

        jsonResponse(true, 'Deposit successful', [
            'balance' => $newBalance,
            'amount' => $amount,
            'transaction_id' => $orderId,
        ]);

    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Withdraw (ATOMIC WITH LOCK)
// ==============================================
function handleWithdraw(int $userId, array $input): void
{
    $amount = floatval($input['amount'] ?? 0);
    $bankAccountNumber = trim($input['bank_account_number'] ?? '');
    $bankIfsc = trim($input['bank_ifsc'] ?? '');
    $bankAccountName = trim($input['bank_account_name'] ?? '');
    $upiId = trim($input['upi_id'] ?? '');

    if ($amount <= 0 || $amount > 50000) {
        jsonResponse(false, 'Invalid amount. Must be between 1 and 50,000', [], 400);
    }

    if (empty($bankAccountNumber) || empty($bankIfsc) || empty($bankAccountName)) {
        jsonResponse(false, 'Bank account details required', [], 400);
    }

    if (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $bankIfsc)) {
        jsonResponse(false, 'Invalid IFSC code format', [], 400);
    }

    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();

        $db->beginTransaction();

        // LOCK USER ROW FOR UPDATE
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

        if ($user['kyc_status'] !== 'verified') {
            $db->rollback();
            jsonResponse(false, 'KYC verification required for withdrawal', [], 403);
        }

        $currentBalance = floatval($user['wallet_balance']);

        if ($currentBalance < $amount) {
            $db->rollback();
            jsonResponse(false, 'Insufficient balance', [], 400);
        }

        // Atomic withdraw - check balance in WHERE clause
        $stmt = $conn->prepare("
            UPDATE users
            SET wallet_balance = wallet_balance - :amount,
                total_withdrawn = total_withdrawn + :amount,
                last_withdrawal_date = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :user_id
            AND wallet_balance >= :amount
        ");
        $stmt->execute([
            ':amount' => $amount,
            ':user_id' => $userId
        ]);

        if ($stmt->rowCount() === 0) {
            $db->rollback();
            jsonResponse(false, 'Insufficient balance or update failed', [], 400);
        }

        $newBalance = $currentBalance - $amount;

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
        $orderId = 'WD-' . strtoupper(uniqid());
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
            ':balance_before' => $currentBalance,
            ':balance_after' => $newBalance,
            ':metadata' => json_encode([
                'withdrawal_id' => $withdrawalId,
                'bank_account' => $bankAccountNumber
            ])
        ]);

        $db->commit();

        jsonResponse(true, 'Withdrawal request submitted successfully', [
            'withdrawal_id' => $withdrawalId,
            'amount' => $amount,
            'balance' => $newBalance,
            'status' => 'pending'
        ]);

    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Get Transaction History
// ==============================================
function handleGetHistory(int $userId): void
{
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

        $stmt = $conn->prepare("
            SELECT COUNT(*) as total
            FROM transactions
            WHERE {$where}
        ");
        $stmt->execute($params);
        $total = intval($stmt->fetchColumn());

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
                tds_deducted,
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
