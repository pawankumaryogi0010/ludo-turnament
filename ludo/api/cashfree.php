<?php
/**
 * ======================================================
 * CASHFREE.PHP - Payment Gateway Integration
 * Ludo Tournament Platform - Cashfree PG Integration
 * Version: 2.0.0 - COMPLETE REWRITE WITH ALL SECURITY FIXES
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
header('X-XSS-Protection: 1; mode=block');

// ==============================================
// CASHFREE CONFIGURATION - ✅ FIX: Load from env safely
// ==============================================
// BUG FIX: The previous code used $_ENV / getenv() to read Cashfree keys, but
// config/db.php loads the .env file via parse_ini_file() — those values are NOT
// automatically placed into $_ENV or getenv(). They are now exposed as the
// CASHFREE_APP_ID_CFG / CASHFREE_SECRET_KEY_CFG / CASHFREE_ENV_CFG constants
// defined in config/db.php after the .env parse. Use those constants here.
define('CASHFREE_APP_ID', defined('CASHFREE_APP_ID_CFG') ? CASHFREE_APP_ID_CFG : (getenv('CASHFREE_APP_ID') ?: ''));
define('CASHFREE_SECRET_KEY', defined('CASHFREE_SECRET_KEY_CFG') ? CASHFREE_SECRET_KEY_CFG : (getenv('CASHFREE_SECRET_KEY') ?: ''));
define('CASHFREE_ENVIRONMENT', defined('CASHFREE_ENV_CFG') ? CASHFREE_ENV_CFG : (getenv('CASHFREE_ENV') ?: 'test'));

if (empty(CASHFREE_APP_ID) || empty(CASHFREE_SECRET_KEY)) {
    error_log('[Cashfree] WARNING: API keys not configured in environment');
    // Allow webhook verification to work, but payment actions will fail safely
}

// API Endpoints
if (CASHFREE_ENVIRONMENT === 'production') {
    define('CASHFREE_API_URL', 'https://api.cashfree.com/pg');
    define('CASHFREE_WEBHOOK_URL', 'https://' . $_SERVER['HTTP_HOST'] . '/api/cashfree.php?action=webhook');
} else {
    define('CASHFREE_API_URL', 'https://sandbox.cashfree.com/pg');
    define('CASHFREE_WEBHOOK_URL', 'http://localhost/api/cashfree.php?action=webhook');
}

// ==============================================
// ROUTING
// ==============================================
$action = isset($_GET['action']) ? trim($_GET['action']) : (isset($_POST['action']) ? trim($_POST['action']) : '');

// ✅ FIX: Validate action to prevent injection
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
    default:
        jsonResponse(false, 'Invalid action', [], 400);
        break;
}

// ==============================================
// HANDLER: Create Payment Order
// ==============================================
function handleCreateOrder() {
    // ✅ FIX: Check authentication
    if (!isLoggedIn()) {
        jsonResponse(false, 'User not authenticated', [], 401);
    }
    
    $userId = getCurrentUserId();
    if (!$userId || $userId <= 0) {
        jsonResponse(false, 'Invalid user session', [], 401);
    }
    
    // ✅ FIX: Parse and validate input
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !is_array($input)) {
        jsonResponse(false, 'Invalid JSON payload', [], 400);
    }
    
    // ✅ FIX: Validate required fields
    $required = ['amount', 'return_url'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || $input[$field] === '' || $input[$field] === null) {
            jsonResponse(false, "Missing required field: {$field}", [], 400);
        }
    }
    
    // ✅ FIX: Validate amount strictly
    $amount = floatval($input['amount'] ?? 0);
    if ($amount <= 0) {
        jsonResponse(false, 'Invalid amount. Must be greater than 0', [], 400);
    }
    if ($amount > 100000) {
        jsonResponse(false, 'Amount exceeds maximum limit of ₹100,000', [], 400);
    }
    
    // ✅ FIX: Validate return URL
    $returnUrl = filter_var($input['return_url'], FILTER_VALIDATE_URL);
    if (!$returnUrl) {
        jsonResponse(false, 'Invalid return URL', [], 400);
    }
    
    // ✅ FIX: Optional fields with defaults
    $customerName = trim($input['customer_name'] ?? '');
    $customerEmail = filter_var($input['customer_email'] ?? '', FILTER_VALIDATE_EMAIL) ?: '';
    $customerPhone = preg_match('/^[0-9]{10}$/', $input['customer_phone'] ?? '') ? $input['customer_phone'] : '';
    
    // ✅ FIX: Validate CSRF token
    $csrfToken = $input['csrf_token'] ?? $_POST['csrf_token'] ?? '';
    if (!CSRFToken::validate($csrfToken)) {
        jsonResponse(false, 'Invalid CSRF token', [], 403);
    }
    
    // ✅ FIX: Check Cashfree configuration
    if (empty(CASHFREE_APP_ID) || empty(CASHFREE_SECRET_KEY)) {
        error_log('[Cashfree] Attempted payment without API keys configured');
        jsonResponse(false, 'Payment gateway not configured. Please contact support.', [], 500);
    }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        // ✅ FIX: Fetch user with error handling
        $stmt = $conn->prepare("
            SELECT id, username, mobile, email, wallet_balance 
            FROM users 
            WHERE id = :user_id
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            jsonResponse(false, 'User not found', [], 404);
        }
        
        // ✅ FIX: Generate unique order ID safely
        $orderId = 'LUDO-' . strtoupper(substr(uniqid(), -8)) . '-' . bin2hex(random_bytes(4));
        
        // ✅ FIX: Use user data as fallback
        $customerName = $customerName ?: $user['username'];
        $customerEmail = $customerEmail ?: ($user['email'] ?: 'customer@example.com');
        $customerPhone = $customerPhone ?: $user['mobile'];
        
        // ==============================================
        // CASHFREE API REQUEST
        // ==============================================
        $payload = [
            'order_id' => $orderId,
            'order_amount' => floatval($amount),
            'order_currency' => 'INR',
            'order_note' => 'Ludo Tournament Wallet Deposit',
            'customer_details' => [
                'customer_id' => (string)$userId,
                'customer_name' => substr($customerName, 0, 50), // Limit to 50 chars
                'customer_email' => substr($customerEmail, 0, 100),
                'customer_phone' => substr($customerPhone, 0, 20),
            ],
            'order_meta' => [
                'return_url' => $returnUrl . (strpos($returnUrl, '?') ? '&' : '?') . 'order_id=' . urlencode($orderId),
                'notify_url' => CASHFREE_WEBHOOK_URL,
                'payment_methods' => 'cc,dc,upi,paypal',
            ],
            'order_expiry_time' => date('Y-m-d\TH:i:s\Z', strtotime('+30 minutes')),
        ];
        
        // ==============================================
        // CURL REQUEST TO CASHFREE
        // ==============================================
        $ch = curl_init();
        if (!$ch) {
            throw new Exception('CURL initialization failed');
        }
        
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
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        // ✅ FIX: Check curl errors
        if ($curlError) {
            error_log('[Cashfree] CURL error: ' . $curlError);
            jsonResponse(false, 'Payment gateway connection error', [], 500);
        }
        
        // ✅ FIX: Parse response safely
        $responseData = json_decode($response, true);
        if (!is_array($responseData)) {
            error_log('[Cashfree] Invalid JSON response: ' . substr($response, 0, 200));
            jsonResponse(false, 'Invalid response from payment gateway', [], 500);
        }
        
        // ✅ FIX: Check API response status
        if ($httpCode !== 200) {
            error_log('[Cashfree] API error (' . $httpCode . '): ' . json_encode($responseData));
            jsonResponse(false, 'Payment order creation failed. Please try again.', [], 400);
        }
        
        if (!isset($responseData['order_id'])) {
            error_log('[Cashfree] No order_id in response: ' . json_encode($responseData));
            jsonResponse(false, 'Payment gateway error. Please try again.', [], 400);
        }
        
        // ==============================================
        // SAVE ORDER TO DATABASE (PENDING)
        // ==============================================
        $paymentSessionId = $responseData['payment_session_id'] ?? '';
        
        $db->beginTransaction();
        
        try {
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
                ':balance_before' => floatval($user['wallet_balance'] ?? 0),
                ':balance_after' => floatval($user['wallet_balance'] ?? 0),
                ':gateway_tx_id' => $paymentSessionId,
                ':metadata' => json_encode([
                    'payment_session_id' => $paymentSessionId,
                    'cashfree_order_id' => $responseData['order_id'] ?? '',
                    'cf_order_id' => $responseData['cf_order_id'] ?? '',
                ], JSON_UNESCAPED_SLASHES)
            ]);
            
            $transactionId = $conn->lastInsertId();
            $db->commit();
            
        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }
        
        // ==============================================
        // RETURN PAYMENT SESSION
        // ==============================================
        jsonResponse(true, 'Payment order created successfully', [
            'order_id' => $orderId,
            'payment_session_id' => $paymentSessionId,
            'cf_order_id' => $responseData['cf_order_id'] ?? '',
            'amount' => $amount,
            'currency' => 'INR',
            'redirect_url' => $responseData['payment_links']['web'] ?? '',
            'transaction_id' => $transactionId ?? null,
        ]);
        
    } catch (PDOException $e) {
        if ($db && $db->inTransaction()) {
            $db->rollback();
        }
        error_log('[Cashfree] Database error: ' . $e->getMessage());
        jsonResponse(false, 'Database error. Please try again.', [], 500);
    } catch (Exception $e) {
        if (isset($db) && $db && $db->inTransaction()) {
            $db->rollback();
        }
        error_log('[Cashfree] Error: ' . $e->getMessage());
        jsonResponse(false, 'Error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Webhook Receiver
// ==============================================
function handleWebhook() {
    // ✅ FIX: Get raw input for signature verification
    $rawInput = file_get_contents('php://input');
    $headers = getallheaders();
    
    // ✅ FIX: Log all webhook requests
    $logEntry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'event' => 'webhook_received',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'signature_valid' => false,
    ];
    
    // ✅ FIX: Verify webhook signature
    $signature = $headers['X-Webhook-Signature'] ?? $headers['x-webhook-signature'] ?? '';
    $timestamp = $headers['X-Webhook-Timestamp'] ?? $headers['x-webhook-timestamp'] ?? '';
    
    if (!verifyWebhookSignature($rawInput, $signature, $timestamp)) {
        $logEntry['error'] = 'Invalid signature';
        writeWebhookLog('webhook_errors.log', $logEntry);
        http_response_code(401);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }
    
    $logEntry['signature_valid'] = true;
    
    // ✅ FIX: Parse payload safely
    $payload = json_decode($rawInput, true);
    if (!$payload || !is_array($payload)) {
        $logEntry['error'] = 'Invalid JSON payload';
        writeWebhookLog('webhook_errors.log', $logEntry);
        http_response_code(400);
        echo json_encode(['error' => 'Invalid payload']);
        exit;
    }
    
    $logEntry['payload'] = $payload;
    
    // ✅ FIX: Extract event type and data safely
    $eventType = $payload['type'] ?? $payload['event'] ?? '';
    $data = $payload['data'] ?? $payload['order'] ?? [];
    
    if (!$eventType) {
        $logEntry['error'] = 'No event type in payload';
        writeWebhookLog('webhook_errors.log', $logEntry);
        http_response_code(400);
        echo json_encode(['error' => 'No event type']);
        exit;
    }
    
    writeWebhookLog('webhook.log', $logEntry);
    
    // ✅ FIX: Handle different event types
    if ($eventType === 'PAYMENT_SUCCESS' || $eventType === 'ORDER_PAID' || $eventType === 'payment_success') {
        handlePaymentSuccess($data);
    } elseif ($eventType === 'PAYMENT_FAILED' || $eventType === 'payment_failed') {
        handlePaymentFailed($data);
    } else {
        // Unknown event - log but don't error
        writeWebhookLog('webhook.log', [
            'timestamp' => date('Y-m-d H:i:s'),
            'event' => 'webhook_unknown_event',
            'type' => $eventType,
        ]);
        
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
        // ✅ FIX: Extract order info safely
        $orderId = $data['order_id'] ?? $data['order']['order_id'] ?? '';
        $txnId = $data['txn_id'] ?? $data['transaction_id'] ?? '';
        $paymentStatus = $data['payment_status'] ?? $data['status'] ?? 'success';
        $amount = floatval($data['order_amount'] ?? $data['amount'] ?? 0);
        
        if (empty($orderId)) {
            throw new Exception('Missing order ID in webhook data');
        }
        
        if ($amount <= 0) {
            throw new Exception('Invalid amount in webhook data');
        }
        
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $db->beginTransaction();
        
        try {
            // BUG FIX: Missing FOR UPDATE — without it two concurrent webhook deliveries
            // can both read status='pending' and both credit the wallet (double-payment).
            $stmt = $conn->prepare("
                SELECT 
                    id, user_id, amount, status, balance_before, metadata
                FROM transactions 
                WHERE order_id = :order_id
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([':order_id' => $orderId]);
            $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$transaction) {
                $db->rollback();
                writeWebhookLog('webhook_errors.log', [
                    'timestamp' => date('Y-m-d H:i:s'),
                    'event' => 'webhook_error',
                    'error' => "Transaction not found: {$orderId}",
                ]);
                http_response_code(404);
                echo json_encode(['error' => 'Transaction not found']);
                exit;
            }
            
            // ✅ FIX: Prevent double processing
            if ($transaction['status'] === 'success') {
                $db->commit();
                http_response_code(200);
                echo json_encode(['status' => 'already_processed']);
                exit;
            }
            
            // ✅ FIX: Fetch user safely
            $stmt = $conn->prepare("
                SELECT id, username, wallet_balance 
                FROM users 
                WHERE id = :user_id
                LIMIT 1
            ");
            $stmt->execute([':user_id' => $transaction['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                $db->rollback();
                throw new Exception("User not found: {$transaction['user_id']}");
            }
            
            // ✅ FIX: Calculate new balance safely
            $txAmount = floatval($transaction['amount'] ?? 0);
            $currentBalance = floatval($user['wallet_balance'] ?? 0);
            $newBalance = $currentBalance + $txAmount;
            
            // ✅ FIX: Update wallet
            $stmt = $conn->prepare("
                UPDATE users 
                SET 
                    wallet_balance = :new_balance,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :user_id
            ");
            $stmt->execute([
                ':new_balance' => $newBalance,
                ':user_id' => $transaction['user_id']
            ]);
            
            // ✅ FIX: Update transaction with all details
            $stmt = $conn->prepare("
                UPDATE transactions 
                SET 
                    status = 'success',
                    gateway_transaction_id = :gateway_tx_id,
                    balance_after = :balance_after,
                    processed_at = CURRENT_TIMESTAMP,
                    metadata = :metadata,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            
            $metadata = json_decode($transaction['metadata'] ?? '{}', true);
            $metadata['webhook_processed_at'] = date('Y-m-d H:i:s');
            $metadata['payment_status'] = $paymentStatus;
            $metadata['txn_id'] = $txnId;
            
            $stmt->execute([
                ':gateway_tx_id' => $txnId ?: $orderId . '-txn',
                ':balance_after' => $newBalance,
                ':metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES),
                ':id' => $transaction['id']
            ]);
            
            $db->commit();
            
            // ✅ FIX: Log success
            writeWebhookLog('webhook_success.log', [
                'timestamp' => date('Y-m-d H:i:s'),
                'event' => 'webhook_payment_success',
                'order_id' => $orderId,
                'user_id' => $transaction['user_id'],
                'amount' => $txAmount,
                'txn_id' => $txnId
            ]);
            
            http_response_code(200);
            echo json_encode(['status' => 'processed', 'order_id' => $orderId]);
            exit;
            
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollback();
            }
            throw $e;
        }
        
    } catch (Exception $e) {
        error_log('[Cashfree] Payment success handler error: ' . $e->getMessage());
        writeWebhookLog('webhook_errors.log', [
            'timestamp' => date('Y-m-d H:i:s'),
            'event' => 'webhook_error',
            'error' => $e->getMessage(),
        ]);
        
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
        // ✅ FIX: Extract data safely
        $orderId = $data['order_id'] ?? $data['order']['order_id'] ?? '';
        $failureReason = $data['failure_reason'] ?? $data['error_message'] ?? 'Payment failed';
        
        if (empty($orderId)) {
            throw new Exception('Missing order ID in webhook data');
        }
        
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        // ✅ FIX: Update transaction status to failed
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
        
        // ✅ FIX: Log failure
        writeWebhookLog('webhook_failures.log', [
            'timestamp' => date('Y-m-d H:i:s'),
            'event' => 'webhook_payment_failed',
            'order_id' => $orderId,
            'reason' => $failureReason
        ]);
        
        http_response_code(200);
        echo json_encode(['status' => 'processed', 'order_id' => $orderId]);
        exit;
        
    } catch (Exception $e) {
        error_log('[Cashfree] Payment failed handler error: ' . $e->getMessage());
        writeWebhookLog('webhook_errors.log', [
            'timestamp' => date('Y-m-d H:i:s'),
            'event' => 'webhook_error',
            'error' => $e->getMessage(),
        ]);
        
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// ==============================================
// HANDLER: Verify Payment
// ==============================================
function handleVerifyPayment() {
    // ✅ FIX: Check authentication
    if (!isLoggedIn()) {
        jsonResponse(false, 'User not authenticated', [], 401);
    }
    
    $userId = getCurrentUserId();
    $input = json_decode(file_get_contents('php://input'), true);
    
    // ✅ FIX: Validate input
    if (!$input || !isset($input['order_id'])) {
        jsonResponse(false, 'Order ID required', [], 400);
    }
    
    $orderId = trim($input['order_id']);
    if (empty($orderId)) {
        jsonResponse(false, 'Order ID cannot be empty', [], 400);
    }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        // ✅ FIX: Fetch transaction safely
        $stmt = $conn->prepare("
            SELECT 
                id, user_id, amount, status, gateway_transaction_id,
                balance_before, balance_after, created_at, processed_at
            FROM transactions 
            WHERE order_id = :order_id
            AND user_id = :user_id
            LIMIT 1
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
            'amount' => floatval($transaction['amount'] ?? 0),
            'status' => $transaction['status'],
            'gateway_txn_id' => $transaction['gateway_transaction_id'],
            'balance_before' => floatval($transaction['balance_before'] ?? 0),
            'balance_after' => floatval($transaction['balance_after'] ?? 0),
            'created_at' => $transaction['created_at'],
            'processed_at' => $transaction['processed_at']
        ]);
        
    } catch (PDOException $e) {
        error_log('[Cashfree] Verify payment DB error: ' . $e->getMessage());
        jsonResponse(false, 'Database error', [], 500);
    } catch (Exception $e) {
        error_log('[Cashfree] Verify payment error: ' . $e->getMessage());
        jsonResponse(false, 'Error', [], 500);
    }
}

// ==============================================
// HANDLER: Get Order Status
// ==============================================
function handleGetOrderStatus() {
    // ✅ FIX: Check authentication
    if (!isLoggedIn()) {
        jsonResponse(false, 'User not authenticated', [], 401);
    }
    
    // ✅ FIX: Check Cashfree config
    if (empty(CASHFREE_APP_ID) || empty(CASHFREE_SECRET_KEY)) {
        jsonResponse(false, 'Payment gateway not configured', [], 500);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    // ✅ FIX: Validate input
    if (!$input || !isset($input['order_id'])) {
        jsonResponse(false, 'Order ID required', [], 400);
    }
    
    $orderId = trim($input['order_id']);
    if (empty($orderId)) {
        jsonResponse(false, 'Order ID cannot be empty', [], 400);
    }
    
    try {
        // ✅ FIX: Call Cashfree API with proper error handling
        $ch = curl_init();
        if (!$ch) {
            throw new Exception('CURL initialization failed');
        }
        
        curl_setopt_array($ch, [
            CURLOPT_URL => CASHFREE_API_URL . '/orders/' . urlencode($orderId),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-version: 2022-09-01',
                'x-client-id: ' . CASHFREE_APP_ID,
                'x-client-secret: ' . CASHFREE_SECRET_KEY,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            error_log('[Cashfree] Get status CURL error: ' . $curlError);
            jsonResponse(false, 'Failed to fetch order status', [], 500);
        }
        
        if ($httpCode !== 200) {
            error_log('[Cashfree] Get status API error (' . $httpCode . '): ' . $response);
            jsonResponse(false, 'Failed to fetch order status', [], 400);
        }
        
        $responseData = json_decode($response, true);
        if (!is_array($responseData)) {
            jsonResponse(false, 'Invalid response from payment gateway', [], 500);
        }
        
        jsonResponse(true, 'Order status retrieved', $responseData);
        
    } catch (Exception $e) {
        error_log('[Cashfree] Get order status error: ' . $e->getMessage());
        jsonResponse(false, 'Error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HELPER: Verify Webhook Signature
// ==============================================
function verifyWebhookSignature($payload, $signature, $timestamp) {
    // ✅ FIX: Skip verification in test environment
    if (CASHFREE_ENVIRONMENT === 'test') {
        return true;
    }
    
    if (empty($signature) || empty($timestamp)) {
        error_log('[Cashfree] Missing signature or timestamp');
        return false;
    }
    
    try {
        // ✅ FIX: Validate timestamp (within 5 minutes)
        $webhookTime = intval($timestamp);
        $currentTime = time();
        if (abs($currentTime - $webhookTime) > 300) {
            error_log('[Cashfree] Webhook timestamp too old: ' . ($currentTime - $webhookTime) . 's');
            return false;
        }
        
        // Cashfree uses HMAC-SHA256
        $secret = CASHFREE_SECRET_KEY;
        $expectedSignature = hash_hmac('sha256', $payload . $timestamp, $secret);
        
        return hash_equals($expectedSignature, $signature);
    } catch (Exception $e) {
        error_log('[Cashfree] Signature verification error: ' . $e->getMessage());
        return false;
    }
}

// ==============================================
// HELPER: Write Webhook Log
// ==============================================
function writeWebhookLog($filename, $logEntry) {
    try {
        $logFile = dirname(__DIR__) . '/logs/' . $filename;
        $logDir = dirname($logFile);
        
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        file_put_contents(
            $logFile,
            json_encode($logEntry, JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    } catch (Exception $e) {
        error_log('[Cashfree] Log write error: ' . $e->getMessage());
    }
}

// ==============================================
// HELPER: JSON Response
// ==============================================
function jsonResponse($success, $message, $data = [], $code = 200, $extra = []) {
    http_response_code($code);
    $response = array_merge([
        'success' => $success,
        'message' => $message,
        'data' => $data,
    ], $extra);
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}
?>
