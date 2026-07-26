<?php
/**
 * ======================================================
 * ADMIN_KYC.PHP - KYC Management API
 * Ludo Tournament Platform - KYC Verification System
 * Version: 2.0.0
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
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-CSRF-Token');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

session_start();

// ==============================================
// AUTHENTICATION & AUTHORIZATION
// ==============================================
if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_token'])) {
    jsonResponse(false, 'Unauthorized - Admin access required', [], 401);
}

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
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
$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {
    case 'list':
        handleList();
        break;
    case 'get':
        handleGet();
        break;
    case 'verify':
        handleVerify();
        break;
    case 'reject':
        handleReject();
        break;
    case 'get_stats':
        handleStats();
        break;
    default:
        jsonResponse(false, 'Invalid action specified', [], 400);
        break;
}

// ==============================================
// HANDLER: List KYC Documents
// ==============================================
function handleList() {
    global $db, $conn;
    
    $status = isset($_GET['status']) ? $_GET['status'] : '';
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
            $where .= " AND (u.username LIKE :search OR u.mobile LIKE :search OR u.email LIKE :search OR k.document_number LIKE :search)";
            $params[':search'] = $search;
        }
        
        // Get total count
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM kyc_documents k LEFT JOIN users u ON k.user_id = u.id WHERE {$where}");
        $stmt->execute($params);
        $total = intval($stmt->fetchColumn());
        
        // Get KYC documents with user details
        $stmt = $conn->prepare("
            SELECT 
                k.id,
                k.user_id,
                k.document_type,
                k.document_number,
                k.document_image_front,
                k.document_image_back,
                k.selfie_image,
                k.bank_account_number,
                k.bank_ifsc,
                k.bank_account_name,
                k.status,
                k.rejection_reason,
                k.verified_by,
                k.verified_at,
                k.created_at,
                k.updated_at,
                u.username,
                u.mobile,
                u.email,
                u.pan_number,
                u.aadhaar_number,
                u.kyc_status as user_kyc_status,
                u.is_verified,
                u.wallet_balance,
                u.total_earnings,
                u.total_matches_played
            FROM kyc_documents k
            LEFT JOIN users u ON k.user_id = u.id
            WHERE {$where}
            ORDER BY 
                CASE k.status 
                    WHEN 'pending' THEN 1 
                    ELSE 2 
                END,
                k.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        
        $params[':limit'] = $limit;
        $params[':offset'] = $offset;
        $stmt->execute($params);
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse(true, 'KYC documents retrieved', [
            'documents' => $documents,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ]);
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Get Single KYC Document
// ==============================================
function handleGet() {
    global $db, $conn;
    
    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) {
        jsonResponse(false, 'Invalid KYC ID', [], 400);
    }
    
    try {
        $stmt = $conn->prepare("
            SELECT 
                k.*,
                u.username,
                u.mobile,
                u.email,
                u.pan_number,
                u.aadhaar_number,
                u.kyc_status as user_kyc_status,
                u.wallet_balance,
                u.total_earnings,
                u.total_matches_played,
                u.total_matches_won,
                u.created_at as user_joined_at
            FROM kyc_documents k
            LEFT JOIN users u ON k.user_id = u.id
            WHERE k.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$document) {
            jsonResponse(false, 'KYC document not found', [], 404);
        }
        
        jsonResponse(true, 'KYC document retrieved', $document);
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Verify KYC
// ==============================================
function handleVerify() {
    global $db, $conn;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['id'])) {
        jsonResponse(false, 'Missing KYC ID', [], 400);
    }
    
    $id = intval($input['id']);
    $notes = $input['notes'] ?? '';
    
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!CSRFToken::validate($csrfToken)) {
        jsonResponse(false, 'Invalid CSRF token', [], 403);
    }
    
    try {
        $db->beginTransaction();
        
        // Get KYC document
        $stmt = $conn->prepare("
            SELECT user_id, status, document_type, document_number 
            FROM kyc_documents 
            WHERE id = :id 
            FOR UPDATE
        ");
        $stmt->execute([':id' => $id]);
        $kyc = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$kyc) {
            $db->rollback();
            jsonResponse(false, 'KYC document not found', [], 404);
        }
        
        if ($kyc['status'] !== 'pending') {
            $db->rollback();
            jsonResponse(false, 'KYC document is already ' . $kyc['status'], [], 400);
        }
        
        // Update KYC document
        $stmt = $conn->prepare("
            UPDATE kyc_documents 
            SET 
                status = 'verified',
                verified_by = :admin_id,
                verified_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute([
            ':admin_id' => $_SESSION['admin_id'],
            ':id' => $id
        ]);
        
        // Update user KYC status
        $stmt = $conn->prepare("
            UPDATE users 
            SET 
                kyc_status = 'verified',
                is_verified = 1,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :user_id
        ");
        $stmt->execute([':user_id' => $kyc['user_id']]);
        
        // Update specific document fields in users table
        if ($kyc['document_type'] === 'pan') {
            $stmt = $conn->prepare("
                UPDATE users 
                SET pan_number = :document_number 
                WHERE id = :user_id
            ");
            $stmt->execute([
                ':document_number' => $kyc['document_number'],
                ':user_id' => $kyc['user_id']
            ]);
        } elseif ($kyc['document_type'] === 'aadhaar') {
            $stmt = $conn->prepare("
                UPDATE users 
                SET aadhaar_number = :document_number 
                WHERE id = :user_id
            ");
            $stmt->execute([
                ':document_number' => $kyc['document_number'],
                ':user_id' => $kyc['user_id']
            ]);
        }
        
        // Log the action
        $logEntry = [
            'action' => 'kyc_verified',
            'admin_id' => $_SESSION['admin_id'],
            'user_id' => $kyc['user_id'],
            'document_type' => $kyc['document_type'],
            'document_id' => $id,
            'notes' => $notes
        ];
        
        $stmt = $conn->prepare("
            INSERT INTO maintenance_logs (action, details, admin_id, ip_address, created_at)
            VALUES (:action, :details, :admin_id, :ip, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            ':action' => 'kyc_verified',
            ':details' => json_encode($logEntry),
            ':admin_id' => $_SESSION['admin_id'],
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        
        $db->commit();
        
        jsonResponse(true, 'KYC verified successfully', [
            'user_id' => $kyc['user_id'],
            'document_type' => $kyc['document_type']
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
// HANDLER: Reject KYC
// ==============================================
function handleReject() {
    global $db, $conn;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['id'])) {
        jsonResponse(false, 'Missing KYC ID', [], 400);
    }
    
    $id = intval($input['id']);
    $reason = $input['reason'] ?? 'Document verification failed';
    
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!CSRFToken::validate($csrfToken)) {
        jsonResponse(false, 'Invalid CSRF token', [], 403);
    }
    
    if (empty($reason) || strlen($reason) < 10) {
        jsonResponse(false, 'Please provide a detailed rejection reason (minimum 10 characters)', [], 400);
    }
    
    try {
        $db->beginTransaction();
        
        // Get KYC document
        $stmt = $conn->prepare("
            SELECT user_id, status, document_type 
            FROM kyc_documents 
            WHERE id = :id 
            FOR UPDATE
        ");
        $stmt->execute([':id' => $id]);
        $kyc = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$kyc) {
            $db->rollback();
            jsonResponse(false, 'KYC document not found', [], 404);
        }
        
        if ($kyc['status'] !== 'pending') {
            $db->rollback();
            jsonResponse(false, 'KYC document is already ' . $kyc['status'], [], 400);
        }
        
        // Update KYC document
        $stmt = $conn->prepare("
            UPDATE kyc_documents 
            SET 
                status = 'rejected',
                rejection_reason = :reason,
                verified_by = :admin_id,
                verified_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute([
            ':reason' => $reason,
            ':admin_id' => $_SESSION['admin_id'],
            ':id' => $id
        ]);
        
        // Update user KYC status
        $stmt = $conn->prepare("
            UPDATE users 
            SET 
                kyc_status = 'rejected',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :user_id
        ");
        $stmt->execute([':user_id' => $kyc['user_id']]);
        
        // Log the action
        $logEntry = [
            'action' => 'kyc_rejected',
            'admin_id' => $_SESSION['admin_id'],
            'user_id' => $kyc['user_id'],
            'document_type' => $kyc['document_type'],
            'document_id' => $id,
            'reason' => $reason
        ];
        
        $stmt = $conn->prepare("
            INSERT INTO maintenance_logs (action, details, admin_id, ip_address, created_at)
            VALUES (:action, :details, :admin_id, :ip, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            ':action' => 'kyc_rejected',
            ':details' => json_encode($logEntry),
            ':admin_id' => $_SESSION['admin_id'],
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        
        $db->commit();
        
        jsonResponse(true, 'KYC rejected successfully', [
            'user_id' => $kyc['user_id'],
            'document_type' => $kyc['document_type']
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
// HANDLER: KYC Statistics
// ==============================================
function handleStats() {
    global $db, $conn;
    
    try {
        $stats = [];
        
        $stmt = $conn->query("SELECT COUNT(*) as pending FROM kyc_documents WHERE status = 'pending'");
        $stats['pending'] = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("SELECT COUNT(*) as verified FROM kyc_documents WHERE status = 'verified'");
        $stats['verified'] = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("SELECT COUNT(*) as rejected FROM kyc_documents WHERE status = 'rejected'");
        $stats['rejected'] = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("SELECT COUNT(*) as total FROM kyc_documents");
        $stats['total'] = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("
            SELECT COUNT(DISTINCT user_id) as total_submitted 
            FROM kyc_documents 
            WHERE status IN ('pending', 'verified', 'rejected')
        ");
        $stats['total_submitted'] = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("
            SELECT COUNT(*) as no_kyc 
            FROM users 
            WHERE is_admin = 0 
            AND kyc_status = 'not_submitted'
        ");
        $stats['no_kyc'] = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("
            SELECT COUNT(*) as today 
            FROM kyc_documents 
            WHERE DATE(created_at) = CURDATE()
        ");
        $stats['today_submissions'] = intval($stmt->fetchColumn());
        
        jsonResponse(true, 'KYC statistics retrieved', $stats);
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}
?>
