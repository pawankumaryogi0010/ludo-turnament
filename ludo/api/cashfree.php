<?php
/**
 * ======================================================
 * CASHFREE.PHP - Payment Gateway Integration
 * Ludo Tournament Platform - Cashfree PG Integration
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
// CASHFREE CONFIGURATION
// ==============================================
define('CASHFREE_APP_ID', 'YOUR_CASHFREE_APP_ID'); // Replace with actual
define('CASHFREE_SECRET_KEY', 'YOUR_CASHFREE_SECRET_KEY'); // Replace with actual
define('CASHFREE_ENVIRONMENT', 'test'); // 'test' or 'production'

// API Endpoints
if (CASHFREE_ENVIRONMENT === 'production') {
    define('CASHFREE_API_URL', 'https://api.cashfree.com/pg');
    define('CASHFREE_WEBHOOK_URL', BASE_URL . '/api/cashfree.php?action=webhook');
} else {
    define('CASHFREE_API_URL', 'https://sandbox.cashfree.com/pg');
    define('CASHFREE_WEBHOOK_URL', BASE_URL . '/api/cashfree.php?action=webhook');
}

// ==============================================
// ROUTING
// ==============================================
$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

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
    default:
        jsonResponse(false, 'Invalid action specified', [], 400);
        break;
}

// ==============================================
// HANDLER: Create Payment Order
// ==============================================
function handleCreateOrder() {
    // Validate authentication
    if (!isLoggedIn()) {
        jsonResponse(false, 'User not authenticated', [], 401);
    }
    
    $userId = getCurrentUserId();
    if (!$userId) {
        jsonResponse(false, 'Invalid user session', [], 401);
    }
    
    // Validate input
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        jsonResponse(false, 'Invalid JSON payload', [], 400);
    }
    
    $required = ['amount', 'return_url'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || empty($input[$field])) {
            jsonResponse(false, "Missing required field: {$field}", [], 400);
        }
    }
    
    $amount = floatval($input['amount']);
    if ($amount <= 0 || $amount > 100000) {
        jsonResponse(false, 'Invalid amount. Must be between 1 and 100,000', [], 400);
    }
    
    $returnUrl = $input['return_url'];
    $customerName = $input['customer_name'] ?? '';
    $customerEmail = $input['customer_email'] ?? '';
    $customerPhone = $input['customer_phone'] ?? '';
    
    // Validate CSRF token
    $csrfToken = $input['csrf_token'] ?? $_POST['csrf_token'] ?? '';
    if (!CSRFToken::validate($csrfToken)) {
        jsonResponse(false, 'Invalid CSRF token', [], 403);
    }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        // Fetch user details
        $stmt = $conn->prepare("
            SELECT id, username, mobile, email, wallet_balance 
            FROM users 
            WHERE id = :user_id
        ");
        $stmt->execute([':user_id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            jsonResponse(false, 'User not found', [], 404);
        }
        
        // Generate unique order ID
        $orderId = 'LUDO-' . strtoupper(uniqid() . bin2hex(random_bytes(6)));
        
        // Create customer details
        $customerName = $customerName ?: $user['username'];
        $customerEmail = $customerEmail ?: ($user['email'] ?: 'customer@example.com');
        $customerPhone = $customerPhone ?: $user['mobile'];
        
        // ==============================================
        // CASHFREE API REQUEST
        // ==============================================
        $payload = [
            'order_id' => $orderId,
            'order_amount' => $amount,
            'order_currency' => 'INR',
            'order_note' => 'Ludo Tournament Wallet Deposit',
            'customer_details' => [
                'customer_id' => (string)$userId,
                'customer_name' => $customerName,
                'customer_email' => $customerEmail,
                'customer_phone' => $customerPhone,
            ],
            'order_meta' => [
                'return_url' => $returnUrl . '?order_id=' . $orderId,
                'notify_url' => CASHFREE_WEBHOOK_URL,
                'payment_methods' => 'cc,dc,upi,paypal',
            ],
            'order_expiry_time' => date('Y-m-d\TH:i:s\Z', strtotime('+30 minutes')),
        ];
        
        // ==============================================
        // CURL REQUEST TO CASHFREE
        // ==============================================
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, CASHFREE_API_URL . '/orders');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'x-api-version: 2022-09-01',
            'x-client-id: ' . CASHFREE_APP_ID,
            'x-client-secret: ' . CASHFREE_SECRET_KEY,
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            jsonResponse(false, 'Payment gateway connection error: ' . $curlError, [], 500);
        }
        
        $responseData = json_decode($response, true);
        
        if ($httpCode !== 200 || !isset($responseData['order_id'])) {
            jsonResponse(false, 'Payment order creation failed', [], 400, [
                'cashfree_response' => $responseData
            ]);
        }
        
        // ==============================================
        // SAVE ORDER TO DATABASE (PENDING)
        // ==============================================
        $paymentSessionId = $responseData['payment_session_id'] ?? '';
        
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
                gateway_transaction_id,
                metadata,
                created_at
            ) VALUES (
                :user_id,
                :amount,
                'credit',
                'deposit',
                :description,
                :order_id,
                'pending',
                :balance_before,
                :balance_after,
                'cashfree',
                :gateway_tx_id,
                :metadata,
                CURRENT_TIMESTAMP
            )
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':amount' => $amount,
            ':description' => 'Wallet deposit via Cashfree',
            ':order_id' => $orderId,
            ':balance_before' => floatval($user['wallet_balance']),
            ':balance_after' => floatval($user['wallet_balance']), // Not credited yet
            ':gateway_tx_id' => $paymentSessionId,
            ':metadata' => json_encode([
                'payment_session_id' => $paymentSessionId,
                'cashfree_order_id' => $responseData['order_id'] ?? '',
                'cf_order_id' => $responseData['cf_order_id'] ?? '',
            ])
        ]);
        
        $transactionId = $conn->lastInsertId();
        
        // ==============================================
        // RETURN PAYMENT SESSION
        // ==============================================
        jsonResponse(true, 'Payment order created', [
            'order_id' => $orderId,
            'payment_session_id' => $paymentSessionId,
            'cf_order_id' => $responseData['cf_order_id'] ?? '',
            'amount' => $amount,
            'currency' => 'INR',
            'redirect_url' => $responseData['payment_links']['web'] ?? '',
            'transaction_id' => $transactionId,
        ]);
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    } catch (Exception $e) {
        jsonResponse(false, 'Error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Webhook Receiver
// ==============================================
function handleWebhook() {
    // Get raw input
    $rawInput = file_get_contents('php://input');
    $headers = getallheaders();
    
    // Log webhook request
    $logEntry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'event' => 'webhook_received',
        'headers' => $headers,
        'payload' => json_decode($rawInput, true),
        'raw' => $rawInput,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ];
    
    $logFile = dirname(__DIR__) . '/logs/webhook.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    file_put_contents($logFile, json_encode($logEntry) . PHP_EOL, FILE_APPEND | LOCK_EX);
    
    // Verify signature
    $signature = $headers['X-Webhook-Signature'] ?? $headers['x-webhook-signature'] ?? '';
    $timestamp = $headers['X-Webhook-Timestamp'] ?? $headers['x-webhook-timestamp'] ?? '';
    
    if (!verifyWebhookSignature($rawInput, $signature, $timestamp)) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }
    
    // Parse payload
    $payload = json_decode($rawInput, true);
    if (!$payload) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid payload']);
        exit;
    }
    
    // Handle different event types
    $eventType = $payload['type'] ?? $payload['event'] ?? '';
    $data = $payload['data'] ?? $payload['order'] ?? [];
    
    if ($eventType === 'PAYMENT_SUCCESS' || $eventType === 'ORDER_PAID' || $eventType === 'payment_success') {
        handlePaymentSuccess($data);
    } elseif ($eventType === 'PAYMENT_FAILED' || $eventType === 'payment_failed') {
        handlePaymentFailed($data);
    } else {
        // Unknown event - log but don't error
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'event' => 'webhook_unknown_event',
            'type' => $eventType,
            'data' => $data
        ];
        file_put_contents($logFile, json_encode($logEntry) . PHP_EOL, FILE_APPEND | LOCK_EX);
        
        http_response_code(200);
        echo json_encode(['status' => 'ignored']);
        exit;
    }
}

// ==============================================
// HANDLER: Payment Success
// ==============================================
function handlePaymentSuccess($data) {
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $orderId = $data['order_id'] ?? $data['order']['order_id'] ?? '';
        $txnId = $data['txn_id'] ?? $data['transaction_id'] ?? $data['txn_id'] ?? '';
        $paymentStatus = $data['payment_status'] ?? $data['status'] ?? 'success';
        $amount = floatval($data['order_amount'] ?? $data['amount'] ?? 0);
        
        if (empty($orderId)) {
            throw new Exception('Missing order ID in webhook data');
        }
        
        // Begin transaction
        $db->beginTransaction();
        
        // Find the transaction
        $stmt = $conn->prepare("
            SELECT 
                id,
                user_id,
                amount,
                status,
                balance_before,
                metadata
            FROM transactions 
            WHERE order_id = :order_id
            FOR UPDATE
        ");
        $stmt->execute([':order_id' => $orderId]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$transaction) {
            $db->rollback();
            throw new Exception("Transaction not found: {$orderId}");
        }
        
        if ($transaction['status'] === 'success') {
            // Already processed
            $db->commit();
            http_response_code(200);
            echo json_encode(['status' => 'already_processed']);
            exit;
        }
        
        // Fetch user with lock
        $stmt = $conn->prepare("
            SELECT id, username, wallet_balance 
            FROM users 
            WHERE id = :user_id
            FOR UPDATE
        ");
        $stmt->execute([':user_id' => $transaction['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            $db->rollback();
            throw new Exception("User not found: {$transaction['user_id']}");
        }
        
        // Credit wallet
        $amount = floatval($transaction['amount']);
        $currentBalance = floatval($user['wallet_balance']);
        $newBalance = $currentBalance + $amount;
        
        $stmt = $conn->prepare("
            UPDATE users 
            SET 
                wallet_balance = wallet_balance + :amount,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :user_id
        ");
        $stmt->execute([
            ':amount' => $amount,
            ':user_id' => $transaction['user_id']
        ]);
        
        // Update transaction
        $stmt = $conn->prepare("
            UPDATE transactions 
            SET 
                status = 'success',
                gateway_transaction_id = :gateway_tx_id,
                balance_after = :balance_after,
                processed_at = CURRENT_TIMESTAMP,
                metadata = JSON_SET(
                    COALESCE(metadata, '{}'),
                    '$.webhook_processed_at',
                    CURRENT_TIMESTAMP,
                    '$.payment_status',
                    :payment_status,
                    '$.txn_id',
                    :txn_id
                ),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute([
            ':gateway_tx_id' => $txnId ?: $orderId . '-txn',
            ':balance_after' => $newBalance,
            ':payment_status' => $paymentStatus,
            ':txn_id' => $txnId,
            ':id' => $transaction['id']
        ]);
        
        // Commit transaction
        $db->commit();
        
        // Log success
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'event' => 'webhook_payment_success',
            'order_id' => $orderId,
            'user_id' => $transaction['user_id'],
            'amount' => $amount,
            'txn_id' => $txnId
        ];
        $logFile = dirname(__DIR__) . '/logs/webhook_success.log';
        file_put_contents($logFile, json_encode($logEntry) . PHP_EOL, FILE_APPEND | LOCK_EX);
        
        http_response_code(200);
        echo json_encode(['status' => 'processed', 'order_id' => $orderId]);
        exit;
        
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollback();
        }
        
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'event' => 'webhook_error',
            'error' => $e->getMessage(),
            'data' => $data
        ];
        $logFile = dirname(__DIR__) . '/logs/webhook_errors.log';
        file_put_contents($logFile, json_encode($logEntry) . PHP_EOL, FILE_APPEND | LOCK_EX);
        
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// ==============================================
// HANDLER: Payment Failed
// ==============================================
function handlePaymentFailed($data) {
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $orderId = $data['order_id'] ?? $data['order']['order_id'] ?? '';
        $failureReason = $data['failure_reason'] ?? $data['error_message'] ?? 'Payment failed';
        
        if (empty($orderId)) {
            throw new Exception('Missing order ID in webhook data');
        }
        
        // Update transaction status to failed
        $stmt = $conn->prepare("
            UPDATE transactions 
            SET 
                status = 'failed',
                metadata = JSON_SET(
                    COALESCE(metadata, '{}'),
                    '$.webhook_processed_at',
                    CURRENT_TIMESTAMP,
                    '$.failure_reason',
                    :failure_reason
                ),
                updated_at = CURRENT_TIMESTAMP
            WHERE order_id = :order_id
            AND status = 'pending'
        ");
        $stmt->execute([
            ':failure_reason' => $failureReason,
            ':order_id' => $orderId
        ]);
        
        // Log failure
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'event' => 'webhook_payment_failed',
            'order_id' => $orderId,
            'reason' => $failureReason
        ];
        $logFile = dirname(__DIR__) . '/logs/webhook_failures.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        file_put_contents($logFile, json_encode($logEntry) . PHP_EOL, FILE_APPEND | LOCK_EX);
        
        http_response_code(200);
        echo json_encode(['status' => 'processed', 'order_id' => $orderId]);
        exit;
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// ==============================================
// HANDLER: Verify Payment
// ==============================================
function handleVerifyPayment() {
    if (!isLoggedIn()) {
        jsonResponse(false, 'User not authenticated', [], 401);
    }
    
    $userId = getCurrentUserId();
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['order_id'])) {
        jsonResponse(false, 'Order ID required', [], 400);
    }
    
    $orderId = $input['order_id'];
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        // Fetch transaction
        $stmt = $conn->prepare("
            SELECT 
                id,
                user_id,
                amount,
                status,
                gateway_transaction_id,
                balance_before,
                balance_after,
                created_at,
                processed_at
            FROM transactions 
            WHERE order_id = :order_id
            AND user_id = :user_id
        ");
        $stmt->execute([
            ':order_id' => $orderId,
            ':user_id' => $userId
        ]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$transaction) {
            jsonResponse(false, 'Transaction not found', [], 404);
        }
        
        jsonResponse(true, 'Transaction status retrieved', [
            'order_id' => $orderId,
            'amount' => floatval($transaction['amount']),
            'status' => $transaction['status'],
            'gateway_txn_id' => $transaction['gateway_transaction_id'],
            'balance_before' => floatval($transaction['balance_before']),
            'balance_after' => floatval($transaction['balance_after']),
            'created_at' => $transaction['created_at'],
            'processed_at' => $transaction['processed_at']
        ]);
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Get Order Status
// ==============================================
function handleGetOrderStatus() {
    if (!isLoggedIn()) {
        jsonResponse(false, 'User not authenticated', [], 401);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['order_id'])) {
        jsonResponse(false, 'Order ID required', [], 400);
    }
    
    $orderId = $input['order_id'];
    
    try {
        // Call Cashfree API to get order status
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, CASHFREE_API_URL . '/orders/' . $orderId);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'x-api-version: 2022-09-01',
            'x-client-id: ' . CASHFREE_APP_ID,
            'x-client-secret: ' . CASHFREE_SECRET_KEY,
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            jsonResponse(false, 'Failed to fetch order status', [], 400);
        }
        
        $responseData = json_decode($response, true);
        jsonResponse(true, 'Order status retrieved', $responseData);
        
    } catch (Exception $e) {
        jsonResponse(false, 'Error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HELPER: Verify Webhook Signature
// ==============================================
function verifyWebhookSignature($payload, $signature, $timestamp) {
    // Skip verification in development
    if (CASHFREE_ENVIRONMENT === 'test') {
        return true;
    }
    
    if (empty($signature) || empty($timestamp)) {
        return false;
    }
    
    try {
        // Construct the signature string
        $body = $payload;
        $secret = CASHFREE_SECRET_KEY;
        
        // Cashfree uses HMAC-SHA256
        $expectedSignature = hash_hmac('sha256', $body, $secret);
        
        return hash_equals($expectedSignature, $signature);
    } catch (Exception $e) {
        error_log('Webhook signature verification failed: ' . $e->getMessage());
        return false;
    }
}
?>
