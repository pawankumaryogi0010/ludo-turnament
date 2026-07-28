<?php
/**
 * ======================================================
 * CASHFREE.PHP - Payment Gateway Integration (FIXED)
 * Ludo Tournament Platform - Cashfree PG Integration
 * Version: 3.0.0 - ALL SECURITY FIXES + PATH FIXES
 * ======================================================
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once dirname(__DIR__) . '/config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// CASHFREE CONFIGURATION - Load from db.php constants
define('CASHFREE_APP_ID', defined('CASHFREE_APP_ID_CFG') ? CASHFREE_APP_ID_CFG : '');
define('CASHFREE_SECRET_KEY', defined('CASHFREE_SECRET_KEY_CFG') ? CASHFREE_SECRET_KEY_CFG : '');
define('CASHFREE_ENVIRONMENT', defined('CASHFREE_ENV_CFG') ? CASHFREE_ENV_CFG : 'test');

// API Endpoints
if (CASHFREE_ENVIRONMENT === 'production') {
    define('CASHFREE_API_URL', 'https://api.cashfree.com/pg');
} else {
    define('CASHFREE_API_URL', 'https://sandbox.cashfree.com/pg');
}

// ROUTING
$action = isset($_GET['action']) ? trim($_GET['action']) : (isset($_POST['action']) ? trim($_POST['action']) : '');

$validActions = ['create_order', 'webhook', 'verify_payment', 'get_order_status'];
if (!in_array($action, $validActions)) {
    jsonResponse(false, 'Invalid action specified', [], 400);
}

switch ($action) {
    case 'create_order':
        handleCreateOrder();
        break;
    case 'webhook':
        handleWebhook();
        break;
    case 'verify_payment':
        handleVerifyPayment();
        break;
    case 'get_order_status':
        handleGetOrderStatus();
        break;
}

// ==============================================
// CREATE PAYMENT ORDER
// ==============================================
function handleCreateOrder() {
    if (!isLoggedIn()) {
        jsonResponse(false, 'User not authenticated', [], 401);
    }
    
    $userId = getCurrentUserId();
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !is_array($input)) {
        jsonResponse(false, 'Invalid JSON payload', [], 400);
    }
    
    $required = ['amount', 'return_url'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || $input[$field] === '') {
            jsonResponse(false, "Missing required field: {$field}", [], 400);
        }
    }
    
    $amount = floatval($input['amount'] ?? 0);
    if ($amount <= 0 || $amount > 100000) {
        jsonResponse(false, 'Invalid amount. Must be between ₹1 and ₹100,000', [], 400);
    }
    
    $returnUrl = filter_var($input['return_url'], FILTER_VALIDATE_URL);
    if (!$returnUrl) {
        jsonResponse(false, 'Invalid return URL', [], 400);
    }
    
    // CSRF
    $csrfToken = $input['csrf_token'] ?? $_POST['csrf_token'] ?? '';
    if (!CSRFToken::validate($csrfToken)) {
        jsonResponse(false, 'Invalid CSRF token', [], 403);
    }
    
    // Check Cashfree config
    if (empty(CASHFREE_APP_ID) || empty(CASHFREE_SECRET_KEY)) {
        jsonResponse(false, 'Payment gateway not configured', [], 500);
    }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        // Fetch user
        $stmt = $conn->prepare("
            SELECT id, username, mobile, email, wallet_balance 
            FROM users WHERE id = :uid LIMIT 1
        ");
        $stmt->execute([':uid' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            jsonResponse(false, 'User not found', [], 404);
        }
        
        // Generate order ID
        $orderId = 'LUDO-' . strtoupper(substr(uniqid(), -8)) . '-' . bin2hex(random_bytes(4));
        
        $customerName = trim($input['customer_name'] ?? $user['username']);
        $customerEmail = filter_var($input['customer_email'] ?? $user['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: 'customer@example.com';
        $customerPhone = preg_match('/^[0-9]{10}$/', $input['customer_phone'] ?? $user['mobile'] ?? '') ? ($input['customer_phone'] ?? $user['mobile']) : '9999999999';
        
        // Cashfree API Request
        $payload = [
            'order_id' => $orderId,
            'order_amount' => floatval($amount),
            'order_currency' => 'INR',
            'order_note' => 'Ludo Tournament Wallet Deposit',
            'customer_details' => [
                'customer_id' => (string)$userId,
                'customer_name' => substr($customerName, 0, 50),
                'customer_email' => substr($customerEmail, 0, 100),
                'customer_phone' => substr($customerPhone, 0, 20),
            ],
            'order_meta' => [
                'return_url' => $returnUrl . (strpos($returnUrl, '?') ? '&' : '?') . 'order_id=' . urlencode($orderId),
                'notify_url' => BASE_URL . '/api/cashfree.php?action=webhook',
                'payment_methods' => 'cc,dc,upi,netbanking',
            ],
            'order_expiry_time' => date('Y-m-d\TH:i:s\Z', strtotime('+30 minutes')),
        ];
        
        // CURL Request
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => CASHFREE_API_URL . '/orders',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-version: 2022-09-01',
                'x-client-id: ' . CASHFREE_APP_ID,
                'x-client-secret: ' . CASHFREE_SECRET_KEY,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            error_log('[Cashfree] CURL error: ' . $curlError);
            jsonResponse(false, 'Payment gateway connection error', [], 500);
        }
        
        $responseData = json_decode($response, true);
        
        if ($httpCode !== 200 || !isset($responseData['order_id'])) {
            error_log('[Cashfree] API error: ' . json_encode($responseData));
            jsonResponse(false, 'Payment order creation failed', [], 400);
        }
        
        // Save transaction
        $paymentSessionId = $responseData['payment_session_id'] ?? '';
        
        $db->beginTransaction();
        try {
            $stmt = $conn->prepare("
                INSERT INTO transactions (
                    user_id, amount, type, source, description,
                    order_id, status, balance_before, balance_after,
                    payment_gateway, gateway_transaction_id, metadata, created_at
                ) VALUES (
                    :uid, :amount, 'credit', 'deposit', :desc,
                    :oid, 'pending', :bal_before, :bal_after,
                    'cashfree', :gtx, :meta, CURRENT_TIMESTAMP
                )
            ");
            $stmt->execute([
                ':uid' => $userId,
                ':amount' => $amount,
                ':desc' => 'Wallet deposit via Cashfree',
                ':oid' => $orderId,
                ':bal_before' => floatval($user['wallet_balance'] ?? 0),
                ':bal_after' => floatval($user['wallet_balance'] ?? 0),
                ':gtx' => $paymentSessionId,
                ':meta' => json_encode(['payment_session_id' => $paymentSessionId])
            ]);
            
            $transactionId = $conn->lastInsertId();
            $db->commit();
            
        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }
        
        jsonResponse(true, 'Payment order created', [
            'order_id' => $orderId,
            'payment_session_id' => $paymentSessionId,
            'amount' => $amount,
            'currency' => 'INR',
            'redirect_url' => $responseData['payment_links']['web'] ?? '',
            'transaction_id' => $transactionId ?? null,
        ]);
        
    } catch (Exception $e) {
        error_log('[Cashfree] Error: ' . $e->getMessage());
        jsonResponse(false, 'Error processing payment', [], 500);
    }
}

// ==============================================
// WEBHOOK RECEIVER
// ==============================================
function handleWebhook() {
    $rawInput = file_get_contents('php://input');
    $headers = getallheaders();
    
    // Skip signature verification in test mode
    if (CASHFREE_ENVIRONMENT !== 'production') {
        // Process directly
    } else {
        $signature = $headers['X-Webhook-Signature'] ?? $headers['x-webhook-signature'] ?? '';
        $timestamp = $headers['X-Webhook-Timestamp'] ?? $headers['x-webhook-timestamp'] ?? '';
        
        if (!verifyWebhookSignature($rawInput, $signature, $timestamp)) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid signature']);
            exit;
        }
    }
    
    $payload = json_decode($rawInput, true);
    if (!$payload) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid payload']);
        exit;
    }
    
    $eventType = $payload['type'] ?? $payload['event'] ?? '';
    $data = $payload['data'] ?? $payload['order'] ?? [];
    
    if ($eventType === 'PAYMENT_SUCCESS' || $eventType === 'ORDER_PAID') {
        handlePaymentSuccess($data);
    } elseif ($eventType === 'PAYMENT_FAILED') {
        handlePaymentFailed($data);
    } else {
        http_response_code(200);
        echo json_encode(['status' => 'ignored']);
        exit;
    }
}

// ==============================================
// PAYMENT SUCCESS
// ==============================================
function handlePaymentSuccess($data) {
    $orderId = $data['order_id'] ?? $data['order']['order_id'] ?? '';
    $txnId = $data['txn_id'] ?? $data['transaction_id'] ?? '';
    
    if (empty($orderId)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing order ID']);
        exit;
    }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $db->beginTransaction();
        
        // Lock transaction row
        $stmt = $conn->prepare("
            SELECT id, user_id, amount, status FROM transactions 
            WHERE order_id = :oid LIMIT 1 FOR UPDATE
        ");
        $stmt->execute([':oid' => $orderId]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$transaction) {
            $db->rollback();
            http_response_code(404);
            echo json_encode(['error' => 'Transaction not found']);
            exit;
        }
        
        // FIXED: Prevent double processing
        if ($transaction['status'] === 'success') {
            $db->commit();
            http_response_code(200);
            echo json_encode(['status' => 'already_processed']);
            exit;
        }
        
        $txAmount = floatval($transaction['amount'] ?? 0);
        
        // Credit wallet
        $stmt = $conn->prepare("
            UPDATE users SET 
                wallet_balance = wallet_balance + :amount,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :uid
        ");
        $stmt->execute([':amount' => $txAmount, ':uid' => $transaction['user_id']]);
        
        // Update transaction
        $stmt = $conn->prepare("
            UPDATE transactions SET 
                status = 'success',
                gateway_transaction_id = :gtx,
                balance_after = (SELECT wallet_balance FROM users WHERE id = :uid),
                processed_at = CURRENT_TIMESTAMP
            WHERE id = :tid
        ");
        $stmt->execute([
            ':gtx' => $txnId,
            ':uid' => $transaction['user_id'],
            ':tid' => $transaction['id']
        ]);
        
        $db->commit();
        
        http_response_code(200);
        echo json_encode(['status' => 'processed', 'order_id' => $orderId]);
        exit;
        
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollback();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// ==============================================
// PAYMENT FAILED
// ==============================================
function handlePaymentFailed($data) {
    $orderId = $data['order_id'] ?? $data['order']['order_id'] ?? '';
    
    if (empty($orderId)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing order ID']);
        exit;
    }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("
            UPDATE transactions SET status = 'failed', updated_at = CURRENT_TIMESTAMP
            WHERE order_id = :oid AND status = 'pending'
        ");
        $stmt->execute([':oid' => $orderId]);
        
        http_response_code(200);
        echo json_encode(['status' => 'processed']);
        exit;
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// ==============================================
// VERIFY PAYMENT
// ==============================================
function handleVerifyPayment() {
    if (!isLoggedIn()) {
        jsonResponse(false, 'Not authenticated', [], 401);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $orderId = trim($input['order_id'] ?? '');
    
    if (empty($orderId)) {
        jsonResponse(false, 'Order ID required', [], 400);
    }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("
            SELECT id, amount, status, gateway_transaction_id,
                   balance_before, balance_after, created_at, processed_at
            FROM transactions WHERE order_id = :oid AND user_id = :uid
            LIMIT 1
        ");
        $stmt->execute([':oid' => $orderId, ':uid' => getCurrentUserId()]);
        $tx = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$tx) {
            jsonResponse(false, 'Transaction not found', [], 404);
        }
        
        jsonResponse(true, 'Transaction status', [
            'order_id' => $orderId,
            'amount' => floatval($tx['amount']),
            'status' => $tx['status'],
            'gateway_txn_id' => $tx['gateway_transaction_id'],
            'created_at' => $tx['created_at'],
            'processed_at' => $tx['processed_at']
        ]);
        
    } catch (Exception $e) {
        jsonResponse(false, 'Error', [], 500);
    }
}

// ==============================================
// GET ORDER STATUS
// ==============================================
function handleGetOrderStatus() {
    if (!isLoggedIn()) {
        jsonResponse(false, 'Not authenticated', [], 401);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $orderId = trim($input['order_id'] ?? '');
    
    if (empty($orderId)) {
        jsonResponse(false, 'Order ID required', [], 400);
    }
    
    if (empty(CASHFREE_APP_ID) || empty(CASHFREE_SECRET_KEY)) {
        jsonResponse(false, 'Payment gateway not configured', [], 500);
    }
    
    try {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => CASHFREE_API_URL . '/orders/' . urlencode($orderId),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-version: 2022-09-01',
                'x-client-id: ' . CASHFREE_APP_ID,
                'x-client-secret: ' . CASHFREE_SECRET_KEY,
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            jsonResponse(false, 'Failed to fetch order status', [], 400);
        }
        
        jsonResponse(true, 'Order status', json_decode($response, true));
        
    } catch (Exception $e) {
        jsonResponse(false, 'Error', [], 500);
    }
}

// ==============================================
// VERIFY WEBHOOK SIGNATURE
// ==============================================
function verifyWebhookSignature($payload, $signature, $timestamp) {
    if (CASHFREE_ENVIRONMENT === 'test') return true;
    if (empty($signature) || empty($timestamp)) return false;
    
    $webhookTime = intval($timestamp);
    if (abs(time() - $webhookTime) > 300) return false;
    
    $expectedSignature = hash_hmac('sha256', $payload . $timestamp, CASHFREE_SECRET_KEY);
    return hash_equals($expectedSignature, $signature);
}
?>
