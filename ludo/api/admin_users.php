<?php
/**
 * ======================================================
 * ADMIN_USERS.PHP - User Management API
 * Ludo Tournament Platform - Admin User Management
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
        break;
}

// ==============================================
// HANDLER: List Users
// ==============================================
function handleList() {
    global $db, $conn;
    
    $limit = intval($_GET['limit'] ?? 50);
    $offset = intval($_GET['offset'] ?? 0);
    $search = isset($_GET['search']) ? '%' . $_GET['search'] . '%' : '';
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    $sort = isset($_GET['sort']) ? $_GET['sort'] : 'id_desc';
    
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
        
        // Order by
        $orderBy = "ORDER BY id DESC";
        switch ($sort) {
            case 'username_asc':
                $orderBy = "ORDER BY username ASC";
                break;
            case 'username_desc':
                $orderBy = "ORDER BY username DESC";
                break;
            case 'balance_asc':
                $orderBy = "ORDER BY wallet_balance ASC";
                break;
            case 'balance_desc':
                $orderBy = "ORDER BY wallet_balance DESC";
                break;
            case 'elo_asc':
                $orderBy = "ORDER BY elo_rating ASC";
                break;
            case 'elo_desc':
                $orderBy = "ORDER BY elo_rating DESC";
                break;
            default:
                $orderBy = "ORDER BY id DESC";
        }
        
        // Get total count
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM users WHERE {$where}");
        $stmt->execute($params);
        $total = intval($stmt->fetchColumn());
        
        // Get users
        $stmt = $conn->prepare("
            SELECT 
                id,
                username,
                mobile,
                email,
                wallet_balance,
                total_matches_played,
                total_matches_won,
                total_earnings,
                total_withdrawn,
                elo_rating,
                is_verified,
                kyc_status,
                is_active,
                created_at,
                last_login
            FROM users 
            WHERE {$where}
            {$orderBy}
            LIMIT :limit OFFSET :offset
        ");
        
        $params[':limit'] = $limit;
        $params[':offset'] = $offset;
        $stmt->execute($params);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse(true, 'Users retrieved', [
            'users' => $users,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ]);
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Get Single User
// ==============================================
function handleGet() {
    global $db, $conn;
    
    $userId = intval($_GET['user_id'] ?? 0);
    if ($userId <= 0) {
        jsonResponse(false, 'Invalid user ID', [], 400);
    }
    
    try {
        $stmt = $conn->prepare("
            SELECT 
                id,
                username,
                mobile,
                email,
                wallet_balance,
                total_matches_played,
                total_matches_won,
                total_earnings,
                total_withdrawn,
                elo_rating,
                is_verified,
                kyc_status,
                is_active,
                created_at,
                last_login,
                pan_number,
                aadhaar_number,
                referral_earnings,
                refer_code,
                referred_by
            FROM users 
            WHERE id = :user_id
        ");
        $stmt->execute([':user_id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            jsonResponse(false, 'User not found', [], 404);
        }
        
        jsonResponse(true, 'User retrieved', $user);
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Toggle User Status (Block/Unblock)
// ==============================================
function handleToggleStatus() {
    global $db, $conn;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['user_id'])) {
        jsonResponse(false, 'Missing user ID', [], 400);
    }
    
    $userId = intval($input['user_id']);
    $status = isset($input['status']) ? intval($input['status']) : null;
    $reason = $input['reason'] ?? 'Admin action';
    
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!CSRFToken::validate($csrfToken)) {
        jsonResponse(false, 'Invalid CSRF token', [], 403);
    }
    
    if ($userId <= 0) {
        jsonResponse(false, 'Invalid user ID', [], 400);
    }
    
    try {
        $db->beginTransaction();
        
        // Get current status
        $stmt = $conn->prepare("
            SELECT is_active, username 
            FROM users 
            WHERE id = :user_id AND is_admin = 0 
            FOR UPDATE
        ");
        $stmt->execute([':user_id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            $db->rollback();
            jsonResponse(false, 'User not found', [], 404);
        }
        
        $newStatus = $status !== null ? $status : ($user['is_active'] ? 0 : 1);
        
        $stmt = $conn->prepare("
            UPDATE users 
            SET is_active = :status, updated_at = CURRENT_TIMESTAMP 
            WHERE id = :user_id
        ");
        $stmt->execute([
            ':status' => $newStatus,
            ':user_id' => $userId
        ]);
        
        // Log the action
        $logEntry = [
            'action' => $newStatus ? 'user_unblocked' : 'user_blocked',
            'admin_id' => $_SESSION['admin_id'],
            'user_id' => $userId,
            'username' => $user['username'],
            'reason' => $reason
        ];
        
        $stmt = $conn->prepare("
            INSERT INTO maintenance_logs (action, details, admin_id, ip_address, created_at)
            VALUES (:action, :details, :admin_id, :ip, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            ':action' => $logEntry['action'],
            ':details' => json_encode($logEntry),
            ':admin_id' => $_SESSION['admin_id'],
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        
        $db->commit();
        
        jsonResponse(true, $newStatus ? 'User unblocked successfully' : 'User blocked successfully', [
            'user_id' => $userId,
            'is_active' => $newStatus
        ]);
        
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Update User Balance
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
    
    if ($userId <= 0 || $amount <= 0) {
        jsonResponse(false, 'Invalid user ID or amount', [], 400);
    }
    
    try {
        $db->beginTransaction();
        
        // Lock user
        $stmt = $conn->prepare("
            SELECT id, username, wallet_balance 
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
        $newBalance = $type === 'credit' ? $currentBalance + $amount : $currentBalance - $amount;
        
        if ($type === 'debit' && $newBalance < 0) {
            $db->rollback();
            jsonResponse(false, 'Insufficient balance for debit', [], 400);
        }
        
        // Update wallet
        $stmt = $conn->prepare("
            UPDATE users 
            SET wallet_balance = :new_balance, updated_at = CURRENT_TIMESTAMP 
            WHERE id = :user_id
        ");
        $stmt->execute([
            ':new_balance' => $newBalance,
            ':user_id' => $userId
        ]);
        
        // Record transaction
        $orderId = 'ADMIN-' . strtoupper(uniqid());
        $txType = $type === 'credit' ? 'credit' : 'debit';
        $source = $type === 'credit' ? 'bonus' : 'withdrawal';
        
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
                :type,
                :source,
                :description,
                :order_id,
                'success',
                :balance_before,
                :balance_after,
                :metadata,
                CURRENT_TIMESTAMP
            )
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':amount' => $amount,
            ':type' => $txType,
            ':source' => $source,
            ':description' => "Admin {$type}: {$reason}",
            ':order_id' => $orderId,
            ':balance_before' => $currentBalance,
            ':balance_after' => $newBalance,
            ':metadata' => json_encode([
                'admin_action' => true,
                'admin_id' => $_SESSION['admin_id'],
                'reason' => $reason,
                'type' => $type
            ])
        ]);
        
        // Log the action
        $logEntry = [
            'action' => 'balance_updated',
            'admin_id' => $_SESSION['admin_id'],
            'user_id' => $userId,
            'username' => $user['username'],
            'type' => $type,
            'amount' => $amount,
            'reason' => $reason
        ];
        
        $stmt = $conn->prepare("
            INSERT INTO maintenance_logs (action, details, admin_id, ip_address, created_at)
            VALUES (:action, :details, :admin_id, :ip, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            ':action' => 'balance_updated',
            ':details' => json_encode($logEntry),
            ':admin_id' => $_SESSION['admin_id'],
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        
        $db->commit();
        
        jsonResponse(true, 'Balance updated successfully', [
            'user_id' => $userId,
            'username' => $user['username'],
            'old_balance' => $currentBalance,
            'new_balance' => $newBalance,
            'amount' => $amount,
            'type' => $type
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
// HANDLER: Get User Transactions
// ==============================================
function handleGetTransactions() {
    global $db, $conn;
    
    $userId = intval($_GET['user_id'] ?? 0);
    $limit = intval($_GET['limit'] ?? 50);
    $offset = intval($_GET['offset'] ?? 0);
    
    if ($userId <= 0) {
        jsonResponse(false, 'Invalid user ID', [], 400);
    }
    
    try {
        // Get total
        $stmt = $conn->prepare("
            SELECT COUNT(*) as total 
            FROM transactions 
            WHERE user_id = :user_id
        ");
        $stmt->execute([':user_id' => $userId]);
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
            WHERE user_id = :user_id
            ORDER BY created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':limit' => $limit,
            ':offset' => $offset
        ]);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse(true, 'User transactions retrieved', [
            'transactions' => $transactions,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ]);
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Get User Matches
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
            SELECT 
                id,
                room_code,
                entry_fee,
                prize_pool,
                status,
                player1_name,
                player2_name,
                player3_name,
                player4_name,
                winner_name,
                winning_amount,
                turn_number,
                created_at,
                started_at,
                completed_at,
                CASE 
                    WHEN player1_id = :user_id THEN 'player1'
                    WHEN player2_id = :user_id THEN 'player2'
                    WHEN player3_id = :user_id THEN 'player3'
                    WHEN player4_id = :user_id THEN 'player4'
                END as player_role
            FROM matches 
            WHERE player1_id = :user_id 
               OR player2_id = :user_id 
               OR player3_id = :user_id 
               OR player4_id = :user_id
            ORDER BY created_at DESC
            LIMIT :limit
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':limit' => $limit
        ]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse(true, 'User matches retrieved', $matches);
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Get User Stats
// ==============================================
function handleGetStats() {
    global $db, $conn;
    
    try {
        $stats = [];
        
        // Total Users
        $stmt = $conn->query("SELECT COUNT(*) as total FROM users WHERE is_admin = 0");
        $stats['total_users'] = intval($stmt->fetchColumn());
        
        // Active Users (is_active = 1)
        $stmt = $conn->query("SELECT COUNT(*) as active FROM users WHERE is_admin = 0 AND is_active = 1");
        $stats['active_users'] = intval($stmt->fetchColumn());
        
        // Inactive Users
        $stats['inactive_users'] = $stats['total_users'] - $stats['active_users'];
        
        // Total Wallet Balance
        $stmt = $conn->query("SELECT SUM(wallet_balance) as total FROM users WHERE is_admin = 0");
        $stats['total_balance'] = floatval($stmt->fetchColumn());
        
        // Users with KYC
        $stmt = $conn->query("
            SELECT COUNT(*) as kyc_verified 
            FROM users 
            WHERE is_admin = 0 
            AND kyc_status = 'verified'
        ");
        $stats['kyc_verified'] = intval($stmt->fetchColumn());
        
        // Users without KYC
        $stmt = $conn->query("
            SELECT COUNT(*) as kyc_not_submitted 
            FROM users 
            WHERE is_admin = 0 
            AND kyc_status = 'not_submitted'
        ");
        $stats['kyc_not_submitted'] = intval($stmt->fetchColumn());
        
        // New Users Today
        $stmt = $conn->query("
            SELECT COUNT(*) as today 
            FROM users 
            WHERE DATE(created_at) = CURDATE() 
            AND is_admin = 0
        ");
        $stats['new_users_today'] = intval($stmt->fetchColumn());
        
        // New Users This Week
        $stmt = $conn->query("
            SELECT COUNT(*) as week 
            FROM users 
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY) 
            AND is_admin = 0
        ");
        $stats['new_users_week'] = intval($stmt->fetchColumn());
        
        jsonResponse(true, 'User statistics retrieved', $stats);
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}
?>
