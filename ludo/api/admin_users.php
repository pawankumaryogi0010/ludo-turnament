<?php
/**
 * ======================================================
 * ADMIN_USERS.PHP - User Management API (FIXED)
 * Ludo Tournament Platform - Admin User Management
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

// FIXED: Start session properly
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// AUTHENTICATION
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
        AND u.is_admin = 1 AND u.is_active = 1
        AND s.session_token = :token
        AND s.is_active = 1 AND s.expires_at > NOW()
    ");
    $stmt->execute([
        ':admin_id' => $_SESSION['admin_id'],
        ':token' => $_SESSION['admin_token']
    ]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$admin) {
        jsonResponse(false, 'Unauthorized - Invalid admin session', [], 401);
    }
    
} catch (Exception $e) {
    jsonResponse(false, 'Authentication error', [], 500);
}

// ROUTING
$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {
    case 'list':
        handleList();
        break;
    case 'get':
        handleGet();
        break;
    case 'toggle_status':
        handleToggleStatus();
        break;
    case 'update_balance':
        handleUpdateBalance();
        break;
    case 'get_transactions':
        handleGetTransactions();
        break;
    case 'get_matches':
        handleGetMatches();
        break;
    case 'get_stats':
        handleGetStats();
        break;
    default:
        jsonResponse(false, 'Invalid action specified', [], 400);
}

// ==============================================
// LIST USERS
// ==============================================
function handleList() {
    global $db, $conn;
    
    $limit = intval($_GET['limit'] ?? 50);
    $offset = intval($_GET['offset'] ?? 0);
    $search = isset($_GET['search']) ? '%' . $_GET['search'] . '%' : '';
    $status = $_GET['status'] ?? '';
    $sort = $_GET['sort'] ?? 'id_desc';
    
    try {
        $params = [];
        $where = "is_admin = 0";
        
        if (!empty($search)) {
            $where .= " AND (username LIKE :search OR mobile LIKE :search OR email LIKE :search)";
            $params[':search'] = $search;
        }
        
        if ($status === 'active') {
            $where .= " AND is_active = 1";
        } elseif ($status === 'inactive') {
            $where .= " AND is_active = 0";
        }
        
        $orderBy = "ORDER BY id DESC";
        switch ($sort) {
            case 'username_asc': $orderBy = "ORDER BY username ASC"; break;
            case 'username_desc': $orderBy = "ORDER BY username DESC"; break;
            case 'balance_asc': $orderBy = "ORDER BY wallet_balance ASC"; break;
            case 'balance_desc': $orderBy = "ORDER BY wallet_balance DESC"; break;
            case 'elo_asc': $orderBy = "ORDER BY elo_rating ASC"; break;
            case 'elo_desc': $orderBy = "ORDER BY elo_rating DESC"; break;
        }
        
        // Total count
        $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE {$where}");
        $stmt->execute($params);
        $total = intval($stmt->fetchColumn());
        
        // Fetch users
        $stmt = $conn->prepare("
            SELECT id, username, mobile, email, wallet_balance,
                   total_matches_played, total_matches_won, total_earnings,
                   total_withdrawn, elo_rating, is_verified, kyc_status,
                   is_active, created_at, last_login
            FROM users WHERE {$where} {$orderBy}
            LIMIT :limit OFFSET :offset
        ");
        $params[':limit'] = $limit;
        $params[':offset'] = $offset;
        $stmt->execute($params);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse(true, 'Users retrieved', [
            'users' => $users ?: [],
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ]);
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// GET SINGLE USER
// ==============================================
function handleGet() {
    global $db, $conn;
    
    $userId = intval($_GET['user_id'] ?? 0);
    if ($userId <= 0) {
        jsonResponse(false, 'Invalid user ID', [], 400);
    }
    
    try {
        $stmt = $conn->prepare("
            SELECT id, username, mobile, email, wallet_balance,
                   total_matches_played, total_matches_won, total_earnings,
                   total_withdrawn, elo_rating, is_verified, kyc_status,
                   is_active, created_at, last_login,
                   pan_number, aadhaar_number, referral_earnings, refer_code, referred_by
            FROM users WHERE id = :uid
        ");
        $stmt->execute([':uid' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            jsonResponse(false, 'User not found', [], 404);
        }
        
        jsonResponse(true, 'User retrieved', $user);
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// TOGGLE USER STATUS
// ==============================================
function handleToggleStatus() {
    global $db, $conn;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['user_id'])) {
        jsonResponse(false, 'Missing user ID', [], 400);
    }
    
    $userId = intval($input['user_id']);
    $reason = $input['reason'] ?? 'Admin action';
    
    // CSRF
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!CSRFToken::validate($csrfToken)) {
        jsonResponse(false, 'Invalid CSRF token', [], 403);
    }
    
    try {
        $db->beginTransaction();
        
        $stmt = $conn->prepare("
            SELECT is_active, username FROM users 
            WHERE id = :uid AND is_admin = 0 FOR UPDATE
        ");
        $stmt->execute([':uid' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            $db->rollback();
            jsonResponse(false, 'User not found', [], 404);
        }
        
        $newStatus = $user['is_active'] ? 0 : 1;
        
        $stmt = $conn->prepare("
            UPDATE users SET is_active = :status, updated_at = CURRENT_TIMESTAMP 
            WHERE id = :uid
        ");
        $stmt->execute([':status' => $newStatus, ':uid' => $userId]);
        
        // Log action
        $stmt = $conn->prepare("
            INSERT INTO maintenance_logs (action, details, admin_id, ip_address, created_at)
            VALUES (:action, :details, :admin_id, :ip, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            ':action' => $newStatus ? 'user_unblocked' : 'user_blocked',
            ':details' => json_encode(['user_id' => $userId, 'username' => $user['username'], 'reason' => $reason]),
            ':admin_id' => $_SESSION['admin_id'],
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        
        $db->commit();
        
        jsonResponse(true, $newStatus ? 'User unblocked' : 'User blocked', [
            'user_id' => $userId,
            'is_active' => $newStatus
        ]);
        
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollback();
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// UPDATE USER BALANCE
// ==============================================
function handleUpdateBalance() {
    global $db, $conn;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['user_id']) || !isset($input['amount'])) {
        jsonResponse(false, 'Missing required fields', [], 400);
    }
    
    $userId = intval($input['user_id']);
    $amount = floatval($input['amount']);
    $type = $input['type'] ?? 'credit';
    $reason = $input['reason'] ?? 'Admin adjustment';
    
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!CSRFToken::validate($csrfToken)) {
        jsonResponse(false, 'Invalid CSRF token', [], 403);
    }
    
    if ($amount <= 0) {
        jsonResponse(false, 'Amount must be positive', [], 400);
    }
    
    try {
        $db->beginTransaction();
        
        $stmt = $conn->prepare("
            SELECT id, username, wallet_balance FROM users 
            WHERE id = :uid FOR UPDATE
        ");
        $stmt->execute([':uid' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            $db->rollback();
            jsonResponse(false, 'User not found', [], 404);
        }
        
        $currentBalance = floatval($user['wallet_balance']);
        $newBalance = $type === 'credit' ? $currentBalance + $amount : $currentBalance - $amount;
        
        if ($type === 'debit' && $newBalance < 0) {
            $db->rollback();
            jsonResponse(false, 'Insufficient balance', [], 400);
        }
        
        $stmt = $conn->prepare("
            UPDATE users SET wallet_balance = :new_bal, updated_at = CURRENT_TIMESTAMP 
            WHERE id = :uid
        ");
        $stmt->execute([':new_bal' => $newBalance, ':uid' => $userId]);
        
        // Record transaction
        $orderId = 'ADMIN-' . strtoupper(uniqid());
        $stmt = $conn->prepare("
            INSERT INTO transactions (
                user_id, amount, type, source, description,
                order_id, status, balance_before, balance_after,
                metadata, created_at
            ) VALUES (
                :uid, :amount, :type, :source, :desc,
                :oid, 'success', :bal_before, :bal_after,
                :meta, CURRENT_TIMESTAMP
            )
        ");
        $stmt->execute([
            ':uid' => $userId, ':amount' => $amount,
            ':type' => $type === 'credit' ? 'credit' : 'debit',
            ':source' => $type === 'credit' ? 'bonus' : 'withdrawal',
            ':desc' => "Admin {$type}: {$reason}",
            ':oid' => $orderId,
            ':bal_before' => $currentBalance,
            ':bal_after' => $newBalance,
            ':meta' => json_encode(['admin_action' => true, 'admin_id' => $_SESSION['admin_id'], 'reason' => $reason])
        ]);
        
        $db->commit();
        
        jsonResponse(true, 'Balance updated', [
            'user_id' => $userId,
            'username' => $user['username'],
            'old_balance' => $currentBalance,
            'new_balance' => $newBalance,
            'amount' => $amount,
            'type' => $type
        ]);
        
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollback();
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// GET USER TRANSACTIONS
// ==============================================
function handleGetTransactions() {
    global $db, $conn;
    
    $userId = intval($_GET['user_id'] ?? 0);
    $limit = intval($_GET['limit'] ?? 50);
    
    if ($userId <= 0) {
        jsonResponse(false, 'Invalid user ID', [], 400);
    }
    
    try {
        $stmt = $conn->prepare("
            SELECT id, amount, type, source, description, order_id,
                   status, balance_before, balance_after, created_at, processed_at
            FROM transactions WHERE user_id = :uid
            ORDER BY created_at DESC LIMIT :limit
        ");
        $stmt->execute([':uid' => $userId, ':limit' => $limit]);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse(true, 'Transactions retrieved', [
            'transactions' => $transactions ?: []
        ]);
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// GET USER MATCHES
// ==============================================
function handleGetMatches() {
    global $db, $conn;
    
    $userId = intval($_GET['user_id'] ?? 0);
    $limit = intval($_GET['limit'] ?? 20);
    
    if ($userId <= 0) {
        jsonResponse(false, 'Invalid user ID', [], 400);
    }
    
    try {
        $stmt = $conn->prepare("
            SELECT id, room_code, entry_fee, prize_pool, status,
                   player1_name, player2_name, player3_name, player4_name,
                   winner_name, winning_amount, turn_number,
                   created_at, started_at, completed_at
            FROM matches 
            WHERE player1_id = :uid OR player2_id = :uid 
               OR player3_id = :uid OR player4_id = :uid
            ORDER BY created_at DESC LIMIT :limit
        ");
        $stmt->execute([':uid' => $userId, ':limit' => $limit]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse(true, 'Matches retrieved', $matches ?: []);
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// GET USER STATS
// ==============================================
function handleGetStats() {
    global $db, $conn;
    
    try {
        $stats = [];
        
        $stmt = $conn->query("SELECT COUNT(*) FROM users WHERE is_admin = 0");
        $stats['total_users'] = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("SELECT COUNT(*) FROM users WHERE is_admin = 0 AND is_active = 1");
        $stats['active_users'] = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE() AND is_admin = 0");
        $stats['new_users_today'] = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("SELECT COUNT(*) FROM users WHERE is_admin = 0 AND kyc_status = 'verified'");
        $stats['kyc_verified'] = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("SELECT SUM(wallet_balance) FROM users WHERE is_admin = 0");
        $stats['total_balance'] = floatval($stmt->fetchColumn());
        
        jsonResponse(true, 'Stats retrieved', $stats);
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error', [], 500);
    }
}
?>
