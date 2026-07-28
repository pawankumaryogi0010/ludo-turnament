<?php
/**
 * ======================================================
 * ADMIN_KYC.PHP - KYC Management API (FIXED)
 * Ludo Tournament Platform - KYC Verification System
 * Version: 3.0.0 - ALL FIXES APPLIED
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
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list': handleList(); break;
    case 'get': handleGet(); break;
    case 'verify': handleVerify(); break;
    case 'reject': handleReject(); break;
    case 'get_stats': handleStats(); break;
    default: jsonResponse(false, 'Invalid action', [], 400);
}

// ==============================================
// LIST KYC DOCUMENTS
// ==============================================
function handleList() {
    global $db, $conn;
    
    $status = $_GET['status'] ?? '';
    $limit = intval($_GET['limit'] ?? 50);
    $offset = intval($_GET['offset'] ?? 0);
    $search = isset($_GET['search']) ? '%' . $_GET['search'] . '%' : '';
    
    try {
        $where = "1=1";
        $params = [];
        
        if (!empty($status)) {
            $where .= " AND k.status = :status";
            $params[':status'] = $status;
        }
        
        if (!empty($search)) {
            $where .= " AND (u.username LIKE :search OR u.mobile LIKE :search OR k.document_number LIKE :search)";
            $params[':search'] = $search;
        }
        
        $stmt = $conn->prepare("SELECT COUNT(*) FROM kyc_documents k LEFT JOIN users u ON k.user_id = u.id WHERE {$where}");
        $stmt->execute($params);
        $total = intval($stmt->fetchColumn());
        
        $stmt = $conn->prepare("
            SELECT k.*, u.username, u.mobile, u.email, u.wallet_balance,
                   u.total_earnings, u.total_matches_played
            FROM kyc_documents k
            LEFT JOIN users u ON k.user_id = u.id
            WHERE {$where}
            ORDER BY CASE k.status WHEN 'pending' THEN 1 ELSE 2 END, k.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $params[':limit'] = $limit;
        $params[':offset'] = $offset;
        $stmt->execute($params);
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse(true, 'KYC documents retrieved', [
            'documents' => $documents ?: [],
            'total' => $total, 'limit' => $limit, 'offset' => $offset
        ]);
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// GET SINGLE KYC
// ==============================================
function handleGet() {
    global $db, $conn;
    
    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) { jsonResponse(false, 'Invalid ID', [], 400); }
    
    try {
        $stmt = $conn->prepare("
            SELECT k.*, u.username, u.mobile, u.email, u.wallet_balance,
                   u.total_earnings, u.total_matches_played, u.total_matches_won,
                   u.created_at as user_joined_at
            FROM kyc_documents k
            LEFT JOIN users u ON k.user_id = u.id
            WHERE k.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$doc) { jsonResponse(false, 'Not found', [], 404); }
        
        jsonResponse(true, 'KYC document retrieved', $doc);
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// VERIFY KYC
// ==============================================
function handleVerify() {
    global $db, $conn;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['id'])) { jsonResponse(false, 'Missing ID', [], 400); }
    
    $id = intval($input['id']);
    
    if (!CSRFToken::validate($input['csrf_token'] ?? '')) {
        jsonResponse(false, 'Invalid CSRF', [], 403);
    }
    
    try {
        $db->beginTransaction();
        
        $stmt = $conn->prepare("SELECT user_id, status, document_type, document_number FROM kyc_documents WHERE id = :id FOR UPDATE");
        $stmt->execute([':id' => $id]);
        $kyc = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$kyc || $kyc['status'] !== 'pending') {
            $db->rollback();
            jsonResponse(false, 'KYC not found or already processed', [], 400);
        }
        
        // Update KYC document
        $stmt = $conn->prepare("
            UPDATE kyc_documents SET status = 'verified', verified_by = :admin_id,
            verified_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute([':admin_id' => $_SESSION['admin_id'], ':id' => $id]);
        
        // Update user KYC status
        $stmt = $conn->prepare("UPDATE users SET kyc_status = 'verified', is_verified = 1, updated_at = CURRENT_TIMESTAMP WHERE id = :uid");
        $stmt->execute([':uid' => $kyc['user_id']]);
        
        // Update document number in users table
        if ($kyc['document_type'] === 'pan') {
            $stmt = $conn->prepare("UPDATE users SET pan_number = :num WHERE id = :uid");
            $stmt->execute([':num' => $kyc['document_number'], ':uid' => $kyc['user_id']]);
        } elseif ($kyc['document_type'] === 'aadhaar') {
            $stmt = $conn->prepare("UPDATE users SET aadhaar_number = :num WHERE id = :uid");
            $stmt->execute([':num' => $kyc['document_number'], ':uid' => $kyc['user_id']]);
        }
        
        // Log action
        $stmt = $conn->prepare("
            INSERT INTO maintenance_logs (action, details, admin_id, ip_address, created_at)
            VALUES ('kyc_verified', :details, :admin_id, :ip, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            ':details' => json_encode(['user_id' => $kyc['user_id'], 'document_type' => $kyc['document_type']]),
            ':admin_id' => $_SESSION['admin_id'],
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        
        $db->commit();
        jsonResponse(true, 'KYC verified successfully');
        
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollback();
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// REJECT KYC
// ==============================================
function handleReject() {
    global $db, $conn;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['id'])) { jsonResponse(false, 'Missing ID', [], 400); }
    
    $id = intval($input['id']);
    $reason = $input['reason'] ?? 'Document verification failed';
    
    if (!CSRFToken::validate($input['csrf_token'] ?? '')) {
        jsonResponse(false, 'Invalid CSRF', [], 403);
    }
    
    if (strlen($reason) < 10) {
        jsonResponse(false, 'Please provide detailed reason (min 10 chars)', [], 400);
    }
    
    try {
        $db->beginTransaction();
        
        $stmt = $conn->prepare("SELECT user_id, status FROM kyc_documents WHERE id = :id FOR UPDATE");
        $stmt->execute([':id' => $id]);
        $kyc = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$kyc || $kyc['status'] !== 'pending') {
            $db->rollback();
            jsonResponse(false, 'KYC not found or already processed', [], 400);
        }
        
        $stmt = $conn->prepare("
            UPDATE kyc_documents SET status = 'rejected', rejection_reason = :reason,
            verified_by = :admin_id, verified_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute([':reason' => $reason, ':admin_id' => $_SESSION['admin_id'], ':id' => $id]);
        
        $stmt = $conn->prepare("UPDATE users SET kyc_status = 'rejected', updated_at = CURRENT_TIMESTAMP WHERE id = :uid");
        $stmt->execute([':uid' => $kyc['user_id']]);
        
        // Log
        $stmt = $conn->prepare("
            INSERT INTO maintenance_logs (action, details, admin_id, ip_address, created_at)
            VALUES ('kyc_rejected', :details, :admin_id, :ip, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            ':details' => json_encode(['user_id' => $kyc['user_id'], 'reason' => $reason]),
            ':admin_id' => $_SESSION['admin_id'],
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        
        $db->commit();
        jsonResponse(true, 'KYC rejected');
        
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollback();
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// KYC STATS
// ==============================================
function handleStats() {
    global $db, $conn;
    
    try {
        $stats = [];
        
        $statuses = ['pending', 'verified', 'rejected'];
        foreach ($statuses as $s) {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM kyc_documents WHERE status = :s");
            $stmt->execute([':s' => $s]);
            $stats[$s] = intval($stmt->fetchColumn());
        }
        
        $stmt = $conn->query("SELECT COUNT(*) FROM kyc_documents");
        $stats['total'] = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("SELECT COUNT(DISTINCT user_id) FROM kyc_documents WHERE status IN ('pending','verified','rejected')");
        $stats['total_submitted'] = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("SELECT COUNT(*) FROM kyc_documents WHERE DATE(created_at) = CURDATE()");
        $stats['today_submissions'] = intval($stmt->fetchColumn());
        
        jsonResponse(true, 'KYC stats retrieved', $stats);
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error', [], 500);
    }
}
?>
