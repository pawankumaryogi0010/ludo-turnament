<?php
/**
 * ======================================================
 * ADMIN_WITHDRAWALS.PHP - Withdrawal Management API (FIXED)
 * Ludo Tournament Platform - Admin Withdrawal System
 * Version: 3.0.0 - DOUBLE DEDUCTION FIX + JSON METADATA FIX
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
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-CSRF-Token');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();

// AUTHENTICATION
if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_token'])) {
    jsonResponse(false, 'Unauthorized', [], 401);
}

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("
        SELECT u.id FROM users u
        JOIN sessions s ON u.id = s.user_id
        WHERE u.id = :aid AND u.is_admin = 1 AND u.is_active = 1
        AND s.session_token = :token AND s.is_active = 1 AND s.expires_at > NOW()
    ");
    $stmt->execute([':aid' => $_SESSION['admin_id'], ':token' => $_SESSION['admin_token']]);
    if (!$stmt->fetch()) {
        jsonResponse(false, 'Unauthorized', [], 401);
    }
} catch (Exception $e) {
    jsonResponse(false, 'Auth error', [], 500);
}

// ROUTING
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'list': handleList(); break;
    case 'get': handleGet(); break;
    case 'approve': handleApprove(); break;
    case 'reject': handleReject(); break;
    case 'process': handleProcess(); break;
    case 'complete': handleComplete(); break;
    case 'get_stats': handleStats(); break;
    default: jsonResponse(false, 'Invalid action', [], 400);
}

// ==============================================
// LIST WITHDRAWALS
// ==============================================
function handleList() {
    global $db, $conn;
    
    $status = $_GET['status'] ?? '';
    $limit = intval($_GET['limit'] ?? 50);
    $offset = intval($_GET['offset'] ?? 0);
    
    try {
        $where = "1=1";
        $params = [];
        
        if (!empty($status)) {
            $where .= " AND w.status = :status";
            $params[':status'] = $status;
        }
        
        $stmt = $conn->prepare("SELECT COUNT(*) FROM withdrawals w WHERE {$where}");
        $stmt->execute($params);
        $total = intval($stmt->fetchColumn());
        
        $stmt = $conn->prepare("
            SELECT w.*, u.username, u.mobile, u.email, u.wallet_balance,
                   u.kyc_status, u.total_earnings, u.total_withdrawn
            FROM withdrawals w
            LEFT JOIN users u ON w.user_id = u.id
            WHERE {$where}
            ORDER BY CASE w.status WHEN 'pending' THEN 1 WHEN 'processing' THEN 2 ELSE 3 END,
                     w.created_at ASC
            LIMIT :limit OFFSET :offset
        ");
        $params[':limit'] = $limit;
        $params[':offset'] = $offset;
        $stmt->execute($params);
        $withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse(true, 'Withdrawals retrieved', [
            'withdrawals' => $withdrawals ?: [],
            'total' => $total, 'limit' => $limit, 'offset' => $offset
        ]);
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// GET SINGLE WITHDRAWAL
// ==============================================
function handleGet() {
    global $db, $conn;
    
    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) { jsonResponse(false, 'Invalid ID', [], 400); }
    
    try {
        $stmt = $conn->prepare("
            SELECT w.*, u.username, u.mobile, u.email, u.wallet_balance,
                   u.kyc_status, u.total_earnings, u.total_withdrawn,
                   a.username as processed_by_name
            FROM withdrawals w
            LEFT JOIN users u ON w.user_id = u.id
            LEFT JOIN users a ON w.processed_by = a.id
            WHERE w.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $wd = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$wd) { jsonResponse(false, 'Not found', [], 404); }
        
        jsonResponse(true, 'Withdrawal retrieved', $wd);
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// APPROVE WITHDRAWAL (FIXED - No double deduction)
// ==============================================
function handleApprove() {
    global $db, $conn;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['id'])) { jsonResponse(false, 'Missing ID', [], 400); }
    
    $id = intval($input['id']);
    $notes = $input['notes'] ?? '';
    
    if (!CSRFToken::validate($input['csrf_token'] ?? '')) {
        jsonResponse(false, 'Invalid CSRF', [], 403);
    }
    
    try {
        $db->beginTransaction();
        
        $stmt = $conn->prepare("SELECT user_id, amount, status FROM withdrawals WHERE id = :id FOR UPDATE");
        $stmt->execute([':id' => $id]);
        $wd = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$wd || $wd['status'] !== 'pending') {
            $db->rollback();
            jsonResponse(false, 'Withdrawal not found or not pending', [], 400);
        }
        
        // FIXED: wallet.php already deducted balance at request time
        // Only update total_withdrawn stats, do NOT deduct again
        $stmt = $conn->prepare("
            UPDATE users SET 
                total_withdrawn = total_withdrawn + :amount,
                last_withdrawal_date = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :uid
        ");
        $stmt->execute([':amount' => $wd['amount'], ':uid' => $wd['user_id']]);
        
        // Update withdrawal status
        $txnId = 'WD-' . strtoupper(uniqid() . bin2hex(random_bytes(4)));
        $stmt = $conn->prepare("
            UPDATE withdrawals SET 
                status = 'approved', processed_by = :admin_id,
                processed_at = CURRENT_TIMESTAMP, transaction_id = :txn,
                admin_notes = CONCAT(COALESCE(admin_notes,''), '\nApproved: ', :notes),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute([':admin_id' => $_SESSION['admin_id'], ':txn' => $txnId, ':notes' => $notes, ':id' => $id]);
        
        $db->commit();
        jsonResponse(true, 'Withdrawal approved', ['transaction_id' => $txnId]);
        
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollback();
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// REJECT WITHDRAWAL (FIXED - Refund properly)
// ==============================================
function handleReject() {
    global $db, $conn;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['id'])) { jsonResponse(false, 'Missing ID', [], 400); }
    
    $id = intval($input['id']);
    $reason = $input['reason'] ?? 'Withdrawal rejected';
    
    if (!CSRFToken::validate($input['csrf_token'] ?? '')) {
        jsonResponse(false, 'Invalid CSRF', [], 403);
    }
    
    try {
        $db->beginTransaction();
        
        $stmt = $conn->prepare("SELECT user_id, amount, status FROM withdrawals WHERE id = :id FOR UPDATE");
        $stmt->execute([':id' => $id]);
        $wd = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$wd || $wd['status'] !== 'pending') {
            $db->rollback();
            jsonResponse(false, 'Only pending withdrawals can be rejected', [], 400);
        }
        
        // Refund to wallet
        $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance + :amt WHERE id = :uid");
        $stmt->execute([':amt' => $wd['amount'], ':uid' => $wd['user_id']]);
        
        // Update withdrawal
        $stmt = $conn->prepare("
            UPDATE withdrawals SET status = 'rejected', processed_by = :admin_id,
            processed_at = CURRENT_TIMESTAMP, rejection_reason = :reason,
            updated_at = CURRENT_TIMESTAMP WHERE id = :id
        ");
        $stmt->execute([':admin_id' => $_SESSION['admin_id'], ':reason' => $reason, ':id' => $id]);
        
        $db->commit();
        jsonResponse(true, 'Withdrawal rejected and refunded');
        
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollback();
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// PROCESS WITHDRAWAL
// ==============================================
function handleProcess() {
    global $db, $conn;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['id'])) { jsonResponse(false, 'Missing ID', [], 400); }
    
    $id = intval($input['id']);
    
    if (!CSRFToken::validate($input['csrf_token'] ?? '')) {
        jsonResponse(false, 'Invalid CSRF', [], 403);
    }
    
    try {
        $stmt = $conn->prepare("UPDATE withdrawals SET status = 'processing', updated_at = CURRENT_TIMESTAMP WHERE id = :id AND status = 'approved'");
        $stmt->execute([':id' => $id]);
        
        if ($stmt->rowCount() === 0) {
            jsonResponse(false, 'Withdrawal must be approved first', [], 400);
        }
        
        jsonResponse(true, 'Marked as processing');
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// COMPLETE WITHDRAWAL (FIXED - JSON metadata match)
// ==============================================
function handleComplete() {
    global $db, $conn;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['id'])) { jsonResponse(false, 'Missing ID', [], 400); }
    
    $id = intval($input['id']);
    
    if (!CSRFToken::validate($input['csrf_token'] ?? '')) {
        jsonResponse(false, 'Invalid CSRF', [], 403);
    }
    
    try {
        $db->beginTransaction();
        
        $stmt = $conn->prepare("SELECT status FROM withdrawals WHERE id = :id FOR UPDATE");
        $stmt->execute([':id' => $id]);
        $wd = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$wd || !in_array($wd['status'], ['processing', 'approved'])) {
            $db->rollback();
            jsonResponse(false, 'Invalid status for completion', [], 400);
        }
        
        $stmt = $conn->prepare("
            UPDATE withdrawals SET status = 'completed', completed_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);
        
        // FIXED: Use JSON_EXTRACT for precise metadata matching
        $stmt = $conn->prepare("
            UPDATE transactions SET status = 'success', processed_at = CURRENT_TIMESTAMP 
            WHERE JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.withdrawal_id')) = :wid
            AND type = 'debit' AND source = 'withdrawal'
        ");
        $stmt->execute([':wid' => (string)$id]);
        
        $db->commit();
        jsonResponse(true, 'Withdrawal completed');
        
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollback();
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// WITHDRAWAL STATS
// ==============================================
function handleStats() {
    global $db, $conn;
    
    try {
        $stats = [];
        
        $statuses = ['pending', 'processing', 'approved', 'completed', 'rejected'];
        foreach ($statuses as $s) {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM withdrawals WHERE status = :s");
            $stmt->execute([':s' => $s]);
            $stats[$s] = intval($stmt->fetchColumn());
        }
        
        $stmt = $conn->query("SELECT SUM(amount) FROM withdrawals WHERE status = 'pending'");
        $stats['total_pending_amount'] = floatval($stmt->fetchColumn());
        
        $stmt = $conn->query("SELECT SUM(amount) FROM withdrawals WHERE status IN ('approved','completed')");
        $stats['total_processed_amount'] = floatval($stmt->fetchColumn());
        
        $stmt = $conn->query("SELECT SUM(amount) FROM withdrawals");
        $stats['total_amount'] = floatval($stmt->fetchColumn());
        
        $stmt = $conn->query("SELECT COUNT(*) FROM withdrawals WHERE DATE(created_at) = CURDATE()");
        $stats['today'] = intval($stmt->fetchColumn());
        
        jsonResponse(true, 'Stats retrieved', $stats);
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error', [], 500);
    }
}
?>
