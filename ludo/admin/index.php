<?php
/**
 * ======================================================
 * ADMIN INDEX.PHP - Pro Command Center (SECURE VERSION)
 * Ludo Tournament Platform - Admin Dashboard
 * Version: 3.1.0 - WITH TOURNAMENT MANAGEMENT
 * ======================================================
 */

// Prevent direct access
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

// Include configuration
require_once dirname(__DIR__) . '/config/db.php';

// ==============================================
// SECURE SESSION & ADMIN AUTHENTICATION
// ==============================================
SessionManager::init();

// Check if admin is logged in with database-stored token
$isAdminLoggedIn = false;
$adminData = null;

if (isset($_SESSION['admin_id']) && isset($_SESSION['admin_token'])) {
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        // Fetch admin with session token verification from database
        $stmt = $conn->prepare("
            SELECT 
                u.id, 
                u.username, 
                u.is_admin, 
                u.is_active,
                u.last_login,
                s.session_token as db_token,
                s.expires_at
            FROM users u
            LEFT JOIN sessions s ON u.id = s.user_id AND s.is_active = 1
            WHERE u.id = :admin_id 
            AND u.is_admin = 1 
            AND u.is_active = 1
        ");
        $stmt->execute([':admin_id' => $_SESSION['admin_id']]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Verify admin exists and session token matches
        if ($admin) {
            // Check if session token is valid and not expired
            if ($admin['db_token'] && $admin['db_token'] === $_SESSION['admin_token']) {
                $expiresAt = strtotime($admin['expires_at']);
                if ($expiresAt > time()) {
                    $isAdminLoggedIn = true;
                    $adminData = $admin;
                    
                    // Update last activity
                    $stmt = $conn->prepare("
                        UPDATE sessions 
                        SET last_activity = CURRENT_TIMESTAMP 
                        WHERE user_id = :admin_id AND is_active = 1
                    ");
                    $stmt->execute([':admin_id' => $_SESSION['admin_id']]);
                }
            }
        }
    } catch (Exception $e) {
        $isAdminLoggedIn = false;
    }
}

// If not logged in, handle login
if (!$isAdminLoggedIn && isset($_POST['admin_login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (!empty($username) && !empty($password)) {
        try {
            $db = Database::getInstance();
            $conn = $db->getConnection();
            
            // Start transaction for secure login
            $db->beginTransaction();
            
            $stmt = $conn->prepare("
                SELECT id, username, password_hash, is_admin, is_active 
                FROM users 
                WHERE username = :username 
                AND is_admin = 1
                FOR UPDATE
            ");
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && $user['is_active'] == 1 && password_verify($password, $user['password_hash'])) {
                // Generate secure admin token (stored in database)
                $adminToken = bin2hex(random_bytes(64));
                $expiresAt = date('Y-m-d H:i:s', strtotime('+8 hours'));
                
                // Store session in database
                $stmt = $conn->prepare("
                    INSERT INTO sessions (
                        user_id,
                        session_token,
                        ip_address,
                        user_agent,
                        device_type,
                        expires_at,
                        is_active,
                        created_at
                    ) VALUES (
                        :user_id,
                        :token,
                        :ip,
                        :user_agent,
                        :device,
                        :expires_at,
                        1,
                        CURRENT_TIMESTAMP
                    )
                ");
                $stmt->execute([
                    ':user_id' => $user['id'],
                    ':token' => $adminToken,
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                    ':device' => 'Admin Panel',
                    ':expires_at' => $expiresAt
                ]);
                
                // Update last login
                $stmt = $conn->prepare("
                    UPDATE users 
                    SET last_login = CURRENT_TIMESTAMP 
                    WHERE id = :user_id
                ");
                $stmt->execute([':user_id' => $user['id']]);
                
                $db->commit();
                
                // Set session variables
                $_SESSION['admin_id'] = $user['id'];
                $_SESSION['admin_token'] = $adminToken;
                $_SESSION['admin_username'] = $user['username'];
                $_SESSION['admin_logged_in'] = true;
                
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
            } else {
                $db->rollback();
                $loginError = 'Invalid username or password';
            }
        } catch (Exception $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollback();
            }
            $loginError = 'Database error occurred: ' . $e->getMessage();
        }
    } else {
        $loginError = 'Please enter username and password';
    }
}

// Handle logout - secure session destruction
if (isset($_GET['logout'])) {
    if (isset($_SESSION['admin_id']) && isset($_SESSION['admin_token'])) {
        try {
            $db = Database::getInstance();
            $conn = $db->getConnection();
            
            // Invalidate session in database
            $stmt = $conn->prepare("
                UPDATE sessions 
                SET is_active = 0 
                WHERE user_id = :user_id AND session_token = :token
            ");
            $stmt->execute([
                ':user_id' => $_SESSION['admin_id'],
                ':token' => $_SESSION['admin_token']
            ]);
        } catch (Exception $e) {
            // Silent fail - still destroy session
        }
    }
    
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Handle AJAX requests
if ($isAdminLoggedIn && isset($_GET['ajax'])) {
    handleAdminAjax();
    exit;
}

// ==============================================
// ADMIN AJAX HANDLER (SECURE)
// ==============================================
function handleAdminAjax() {
    $action = $_GET['action'] ?? '';
    $response = ['success' => false, 'message' => 'Invalid action'];
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        // Verify session is still valid
        $stmt = $conn->prepare("
            SELECT id 
            FROM sessions 
            WHERE user_id = :admin_id 
            AND session_token = :token 
            AND is_active = 1 
            AND expires_at > NOW()
        ");
        $stmt->execute([
            ':admin_id' => $_SESSION['admin_id'],
            ':token' => $_SESSION['admin_token']
        ]);
        
        if (!$stmt->fetch()) {
            // Session expired or invalid
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Session expired', 'redirect' => true]);
            exit;
        }
        
        switch ($action) {
            case 'get_stats':
                $response = getAdminStats($db, $conn);
                break;
            case 'get_users':
                $response = getUsersList($db, $conn);
                break;
            case 'update_balance':
                $response = updateUserBalance($db, $conn);
                break;
            case 'get_transactions':
                $response = getUserTransactions($db, $conn);
                break;
            case 'toggle_user':
                $response = toggleUserStatus($db, $conn);
                break;
            case 'get_matches':
                $response = getMatchesList($db, $conn);
                break;
            case 'get_kyc_stats':
                $response = getKycStats($db, $conn);
                break;
            case 'get_withdrawal_stats':
                $response = getWithdrawalStats($db, $conn);
                break;
            case 'get_dispute_stats':
                $response = getDisputeStats($db, $conn);
                break;
            case 'get_financial_metrics':
                $response = getFinancialMetrics($db, $conn);
                break;
            case 'get_tournaments':
                $response = getTournamentsList($db, $conn);
                break;
            default:
                $response['message'] = 'Unknown action';
        }
    } catch (Exception $e) {
        $response['message'] = 'Error: ' . $e->getMessage();
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// ==============================================
// ADMIN STATS (COMPLETE)
// ==============================================
function getAdminStats($db, $conn) {
    $stats = [];
    
    // Total Users
    $stmt = $conn->query("SELECT COUNT(*) as total FROM users WHERE is_admin = 0");
    $stats['total_users'] = intval($stmt->fetchColumn());
    
    // Active Users (last 30 days)
    $stmt = $conn->query("
        SELECT COUNT(DISTINCT user_id) as active 
        FROM transactions 
        WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stats['active_users'] = intval($stmt->fetchColumn());
    
    // New Users Today
    $stmt = $conn->query("
        SELECT COUNT(*) as today 
        FROM users 
        WHERE DATE(created_at) = CURDATE() 
        AND is_admin = 0
    ");
    $stats['new_users_today'] = intval($stmt->fetchColumn());
    
    // Total Matches
    $stmt = $conn->query("SELECT COUNT(*) as total FROM matches");
    $stats['total_matches'] = intval($stmt->fetchColumn());
    
    // Active Live Tournaments
    $stmt = $conn->query("
        SELECT COUNT(*) as active 
        FROM matches 
        WHERE status IN ('playing', 'ready')
    ");
    $stats['active_tournaments'] = intval($stmt->fetchColumn());
    
    // Completed Matches Today
    $stmt = $conn->query("
        SELECT COUNT(*) as today 
        FROM matches 
        WHERE DATE(completed_at) = CURDATE() 
        AND status = 'completed'
    ");
    $stats['matches_today'] = intval($stmt->fetchColumn());
    
    // Total Revenue (Admin Commission)
    $stmt = $conn->query("
        SELECT SUM(amount) as total 
        FROM transactions 
        WHERE source = 'deposit' 
        AND description LIKE '%platform commission%'
        AND status = 'success'
    ");
    $stats['total_platform_revenue'] = floatval($stmt->fetchColumn());
    
    // Cashfree Collections
    $stmt = $conn->query("
        SELECT SUM(amount) as total 
        FROM transactions 
        WHERE payment_gateway = 'cashfree' 
        AND status = 'success'
    ");
    $stats['cashfree_collections'] = floatval($stmt->fetchColumn());
    
    // Net Platform Profit (15% commission)
    $stmt = $conn->query("
        SELECT SUM(platform_fee) as total 
        FROM matches 
        WHERE status = 'completed'
    ");
    $stats['net_platform_profit'] = floatval($stmt->fetchColumn());
    
    // Today's Revenue
    $stmt = $conn->query("
        SELECT SUM(amount) as total 
        FROM transactions 
        WHERE source = 'deposit' 
        AND description LIKE '%platform commission%'
        AND status = 'success'
        AND DATE(created_at) = CURDATE()
    ");
    $stats['today_revenue'] = floatval($stmt->fetchColumn());
    
    // Pending Withdrawals
    $stmt = $conn->query("
        SELECT COUNT(*) as pending 
        FROM withdrawals 
        WHERE status = 'pending'
    ");
    $stats['pending_withdrawals'] = intval($stmt->fetchColumn());
    
    // Pending KYC
    $stmt = $conn->query("
        SELECT COUNT(*) as pending 
        FROM kyc_documents 
        WHERE status = 'pending'
    ");
    $stats['pending_kyc'] = intval($stmt->fetchColumn());
    
    // Open Disputes
    $stmt = $conn->query("
        SELECT COUNT(*) as open 
        FROM dispute_tickets 
        WHERE status IN ('open', 'investigating')
    ");
    $stats['open_disputes'] = intval($stmt->fetchColumn());
    
    // Total TDS Deducted
    $stmt = $conn->query("
        SELECT SUM(tds_amount) as total 
        FROM tds_transactions
    ");
    $stats['total_tds'] = floatval($stmt->fetchColumn());
    
    // Total User Balance
    $stmt = $conn->query("
        SELECT SUM(wallet_balance) as total 
        FROM users 
        WHERE is_admin = 0
    ");
    $stats['total_user_balance'] = floatval($stmt->fetchColumn());
    
    // Platform Liability (Total User Balance - Total Withdrawn)
    $stmt = $conn->query("
        SELECT SUM(total_withdrawn) as total 
        FROM users 
        WHERE is_admin = 0
    ");
    $stats['total_withdrawn'] = floatval($stmt->fetchColumn());
    $stats['platform_liability'] = $stats['total_user_balance'] - $stats['total_withdrawn'];
    
    // Total Tournaments
    $stmt = $conn->query("SELECT COUNT(*) as total FROM tournaments");
    $stats['total_tournaments'] = intval($stmt->fetchColumn());
    
    // Active Tournaments
    $stmt = $conn->query("
        SELECT COUNT(*) as active 
        FROM tournaments 
        WHERE status IN ('active', 'in_progress')
    ");
    $stats['active_tournaments_count'] = intval($stmt->fetchColumn());
    
    // Growth percentages (compared to last month)
    $stmt = $conn->query("
        SELECT COUNT(*) as last_month 
        FROM users 
        WHERE is_admin = 0 
        AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
        AND created_at < DATE_SUB(NOW(), INTERVAL 60 DAY)
    ");
    $lastMonthUsers = intval($stmt->fetchColumn());
    $stats['user_growth'] = $lastMonthUsers > 0 ? round(($stats['new_users_today'] / $lastMonthUsers) * 100, 1) : 0;
    
    $stmt = $conn->query("
        SELECT SUM(amount) as last_month 
        FROM transactions 
        WHERE source = 'deposit' 
        AND description LIKE '%platform commission%'
        AND status = 'success'
        AND DATE(created_at) > DATE_SUB(NOW(), INTERVAL 30 DAY)
        AND DATE(created_at) < DATE_SUB(NOW(), INTERVAL 60 DAY)
    ");
    $lastMonthRevenue = floatval($stmt->fetchColumn());
    $stats['revenue_growth'] = $lastMonthRevenue > 0 ? round((($stats['today_revenue'] - $lastMonthRevenue) / $lastMonthRevenue) * 100, 1) : 0;
    
    return ['success' => true, 'data' => $stats];
}

// ==============================================
// GET KYC STATS
// ==============================================
function getKycStats($db, $conn) {
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
        
        return ['success' => true, 'data' => $stats];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ==============================================
// GET WITHDRAWAL STATS
// ==============================================
function getWithdrawalStats($db, $conn) {
    try {
        $stats = [];
        
        $stmt = $conn->query("SELECT COUNT(*) as pending FROM withdrawals WHERE status = 'pending'");
        $stats['pending'] = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("SELECT COUNT(*) as processing FROM withdrawals WHERE status = 'processing'");
        $stats['processing'] = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("SELECT COUNT(*) as approved FROM withdrawals WHERE status = 'approved'");
        $stats['approved'] = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("SELECT COUNT(*) as completed FROM withdrawals WHERE status = 'completed'");
        $stats['completed'] = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("SELECT COUNT(*) as rejected FROM withdrawals WHERE status = 'rejected'");
        $stats['rejected'] = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("
            SELECT SUM(amount) as total_pending 
            FROM withdrawals 
            WHERE status = 'pending'
        ");
        $stats['total_pending_amount'] = floatval($stmt->fetchColumn());
        
        $stmt = $conn->query("
            SELECT SUM(amount) as total_processed 
            FROM withdrawals 
            WHERE status IN ('approved', 'completed')
        ");
        $stats['total_processed_amount'] = floatval($stmt->fetchColumn());
        
        $stmt = $conn->query("
            SELECT SUM(amount) as total_amount 
            FROM withdrawals
        ");
        $stats['total_amount'] = floatval($stmt->fetchColumn());
        
        $stmt = $conn->query("
            SELECT COUNT(*) as today 
            FROM withdrawals 
            WHERE DATE(created_at) = CURDATE()
        ");
        $stats['today'] = intval($stmt->fetchColumn());
        
        return ['success' => true, 'data' => $stats];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ==============================================
// GET DISPUTE STATS
// ==============================================
function getDisputeStats($db, $conn) {
    try {
        $stats = [];
        
        $stmt = $conn->query("SELECT COUNT(*) as open FROM dispute_tickets WHERE status = 'open'");
        $stats['open'] = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("SELECT COUNT(*) as investigating FROM dispute_tickets WHERE status = 'investigating'");
        $stats['investigating'] = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("SELECT COUNT(*) as resolved FROM dispute_tickets WHERE status = 'resolved'");
        $stats['resolved'] = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("SELECT COUNT(*) as closed FROM dispute_tickets WHERE status = 'closed'");
        $stats['closed'] = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("SELECT COUNT(*) as total FROM dispute_tickets");
        $stats['total'] = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("
            SELECT COUNT(*) as high_priority 
            FROM dispute_tickets 
            WHERE priority IN ('high', 'urgent') 
            AND status IN ('open', 'investigating')
        ");
        $stats['high_priority'] = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("
            SELECT SUM(refund_amount) as total_refunds 
            FROM dispute_tickets 
            WHERE status = 'resolved' 
            AND resolution_type = 'refund'
        ");
        $stats['total_refunds'] = floatval($stmt->fetchColumn());
        
        return ['success' => true, 'data' => $stats];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ==============================================
// GET FINANCIAL METRICS
// ==============================================
function getFinancialMetrics($db, $conn) {
    try {
        $days = intval($_GET['days'] ?? 30);
        
        $stmt = $conn->prepare("
            SELECT 
                metric_date,
                daily_deposits,
                daily_withdrawals,
                daily_platform_revenue,
                daily_matches_played,
                daily_new_users,
                total_platform_liability,
                total_user_balance,
                total_tds_deducted
            FROM financial_metrics
            WHERE metric_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
            ORDER BY metric_date ASC
        ");
        $stmt->execute([':days' => $days]);
        $metrics = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get current totals
        $stmt = $conn->query("
            SELECT 
                SUM(wallet_balance) as total_balance,
                SUM(total_earnings) as total_earnings,
                SUM(total_withdrawn) as total_withdrawn
            FROM users 
            WHERE is_admin = 0
        ");
        $totals = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'success' => true,
            'data' => [
                'history' => $metrics,
                'totals' => [
                    'total_user_balance' => floatval($totals['total_balance'] ?? 0),
                    'total_earnings' => floatval($totals['total_earnings'] ?? 0),
                    'total_withdrawn' => floatval($totals['total_withdrawn'] ?? 0)
                ]
            ]
        ];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ==============================================
// GET USERS LIST
// ==============================================
function getUsersList($db, $conn) {
    $limit = intval($_GET['limit'] ?? 50);
    $offset = intval($_GET['offset'] ?? 0);
    $search = isset($_GET['search']) ? '%' . $_GET['search'] . '%' : '';
    
    $params = [];
    $where = "is_admin = 0";
    
    if (!empty($search)) {
        $where .= " AND (username LIKE :search OR mobile LIKE :search OR email LIKE :search)";
        $params[':search'] = $search;
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
        ORDER BY id DESC
        LIMIT :limit OFFSET :offset
    ");
    
    $params[':limit'] = $limit;
    $params[':offset'] = $offset;
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'success' => true,
        'data' => [
            'users' => $users,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ]
    ];
}

// ==============================================
// UPDATE USER BALANCE
// ==============================================
function updateUserBalance($db, $conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['user_id']) || !isset($input['amount'])) {
        return ['success' => false, 'message' => 'Missing required fields'];
    }
    
    $userId = intval($input['user_id']);
    $amount = floatval($input['amount']);
    $type = $input['type'] ?? 'credit';
    $reason = $input['reason'] ?? 'Admin adjustment';
    
    if ($userId <= 0 || $amount <= 0) {
        return ['success' => false, 'message' => 'Invalid user ID or amount'];
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
            return ['success' => false, 'message' => 'User not found'];
        }
        
        $currentBalance = floatval($user['wallet_balance']);
        $newBalance = $type === 'credit' ? $currentBalance + $amount : $currentBalance - $amount;
        
        if ($type === 'debit' && $newBalance < 0) {
            $db->rollback();
            return ['success' => false, 'message' => 'Insufficient balance for debit'];
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
        
        $db->commit();
        
        return [
            'success' => true,
            'data' => [
                'user_id' => $userId,
                'username' => $user['username'],
                'old_balance' => $currentBalance,
                'new_balance' => $newBalance,
                'amount' => $amount,
                'type' => $type
            ]
        ];
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ==============================================
// GET USER TRANSACTIONS
// ==============================================
function getUserTransactions($db, $conn) {
    $userId = intval($_GET['user_id'] ?? 0);
    $limit = intval($_GET['limit'] ?? 50);
    
    if ($userId <= 0) {
        return ['success' => false, 'message' => 'Invalid user ID'];
    }
    
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
        LIMIT :limit
    ");
    $stmt->execute([
        ':user_id' => $userId,
        ':limit' => $limit
    ]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'success' => true,
        'data' => $transactions
    ];
}

// ==============================================
// TOGGLE USER STATUS
// ==============================================
function toggleUserStatus($db, $conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['user_id'])) {
        return ['success' => false, 'message' => 'Missing user ID'];
    }
    
    $userId = intval($input['user_id']);
    $status = isset($input['status']) ? intval($input['status']) : null;
    
    if ($userId <= 0) {
        return ['success' => false, 'message' => 'Invalid user ID'];
    }
    
    if ($status !== null && $status !== 0 && $status !== 1) {
        return ['success' => false, 'message' => 'Invalid status value'];
    }
    
    try {
        if ($status === null) {
            // Toggle
            $stmt = $conn->prepare("
                UPDATE users 
                SET is_active = NOT is_active, updated_at = CURRENT_TIMESTAMP 
                WHERE id = :user_id AND is_admin = 0
            ");
        } else {
            $stmt = $conn->prepare("
                UPDATE users 
                SET is_active = :status, updated_at = CURRENT_TIMESTAMP 
                WHERE id = :user_id AND is_admin = 0
            ");
        }
        $stmt->execute([':user_id' => $userId, ':status' => $status]);
        
        if ($stmt->rowCount() === 0) {
            return ['success' => false, 'message' => 'User not found or is admin'];
        }
        
        return ['success' => true, 'message' => 'User status updated'];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ==============================================
// GET MATCHES LIST
// ==============================================
function getMatchesList($db, $conn) {
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    $limit = intval($_GET['limit'] ?? 50);
    $offset = intval($_GET['offset'] ?? 0);
    
    $where = "1=1";
    $params = [];
    
    if (!empty($status)) {
        $where .= " AND m.status = :status";
        $params[':status'] = $status;
    }
    
    // Get total
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM matches m WHERE {$where}");
    $stmt->execute($params);
    $total = intval($stmt->fetchColumn());
    
    // Get matches
    $stmt = $conn->prepare("
        SELECT 
            m.id,
            m.room_code,
            m.entry_fee,
            m.prize_pool,
            m.platform_fee,
            m.status,
            m.player1_name,
            m.player2_name,
            m.winner_name,
            m.winning_amount,
            m.tds_deducted,
            m.turn_number,
            m.created_at,
            m.started_at,
            m.completed_at
        FROM matches m
        WHERE {$where}
        ORDER BY m.id DESC
        LIMIT :limit OFFSET :offset
    ");
    
    $params[':limit'] = $limit;
    $params[':offset'] = $offset;
    $stmt->execute($params);
    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'success' => true,
        'data' => [
            'matches' => $matches,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ]
    ];
}

// ==============================================
// GET TOURNAMENTS LIST (NEW)
// ==============================================
function getTournamentsList($db, $conn) {
    $limit = intval($_GET['limit'] ?? 10);
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    
    $where = "1=1";
    $params = [];
    
    if (!empty($status)) {
        $where .= " AND status = :status";
        $params[':status'] = $status;
    }
    
    try {
        $stmt = $conn->prepare("
            SELECT 
                t.*,
                u.username as created_by_name,
                (SELECT COUNT(*) FROM matches WHERE tournament_id = t.id) as match_count
            FROM tournaments t
            LEFT JOIN users u ON t.created_by = u.id
            WHERE {$where}
            ORDER BY 
                CASE t.status 
                    WHEN 'active' THEN 1 
                    WHEN 'in_progress' THEN 2 
                    WHEN 'scheduled' THEN 3 
                    ELSE 4 
                END,
                t.created_at DESC
            LIMIT :limit
        ");
        $stmt->execute([':limit' => $limit]);
        $tournaments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return ['success' => true, 'data' => $tournaments];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ==============================================
// PAGE RENDER
// ==============================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Command Center - Ludo Tournament Pro</title>
    <!-- Chart.js for Analytics -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        /* ==============================================
           ADMIN STYLES (FULLY ENHANCED)
           ============================================== */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #0a0e1a;
            color: #f1f5f9;
            min-height: 100vh;
        }
        
        /* Login Page */
        .login-container {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        
        .login-box {
            background: #1a1a2e;
            padding: 40px;
            border-radius: 20px;
            max-width: 400px;
            width: 100%;
            border: 1px solid rgba(255, 255, 255, 0.06);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
        }
        
        .login-box h1 {
            font-size: 28px;
            font-weight: 800;
            margin-bottom: 8px;
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .login-box p {
            color: #94a3b8;
            margin-bottom: 24px;
        }
        
        .login-box .form-group {
            margin-bottom: 16px;
        }
        
        .login-box .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #94a3b8;
            margin-bottom: 4px;
        }
        
        .login-box .form-group input {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.04);
            color: #f1f5f9;
            font-size: 14px;
            font-family: inherit;
            transition: border-color 0.2s;
        }
        
        .login-box .form-group input:focus {
            outline: none;
            border-color: #7c3aed;
        }
        
        .login-box .form-group input::placeholder {
            color: #64748b;
        }
        
        .login-btn {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 10px;
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            color: #1a1a2e;
            font-weight: 700;
            font-size: 16px;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            font-family: inherit;
        }
        
        .login-btn:hover {
            transform: scale(1.02);
            box-shadow: 0 0 30px rgba(251, 191, 36, 0.2);
        }
        
        .login-error {
            color: #ef4444;
            font-size: 14px;
            margin-bottom: 16px;
            padding: 10px;
            background: rgba(239, 68, 68, 0.1);
            border-radius: 8px;
        }
        
        /* Admin Dashboard */
        .admin-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .admin-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 12px;
        }
        
        .admin-header h1 {
            font-size: 24px;
            font-weight: 700;
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .admin-header-actions {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        
        .admin-header-actions span {
            color: #94a3b8;
            font-size: 14px;
        }
        
        .admin-header-actions a {
            color: #94a3b8;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            padding: 8px 16px;
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 8px;
            transition: background 0.2s;
        }
        
        .admin-header-actions a:hover {
            background: rgba(255, 255, 255, 0.04);
        }
        
        .admin-header-actions a.logout {
            color: #ef4444;
            border-color: rgba(239, 68, 68, 0.2);
        }
        
        .admin-header-actions a.logout:hover {
            background: rgba(239, 68, 68, 0.1);
        }
        
        .admin-header-actions .nav-link {
            background: rgba(124, 58, 237, 0.1);
            border-color: rgba(124, 58, 237, 0.15);
            color: #8b5cf6;
        }
        
        .admin-header-actions .nav-link:hover {
            background: rgba(124, 58, 237, 0.2);
        }
        
        /* Growth Indicators */
        .growth-indicator {
            font-size: 12px;
            font-weight: 600;
            margin-left: 6px;
        }
        
        .growth-indicator.positive {
            color: #10b981;
        }
        
        .growth-indicator.negative {
            color: #ef4444;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: #1a1a2e;
            padding: 16px 20px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.04);
            transition: border-color 0.2s;
            cursor: pointer;
        }
        
        .stat-card:hover {
            border-color: rgba(251, 191, 36, 0.15);
        }
        
        .stat-card .stat-label {
            font-size: 11px;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        
        .stat-card .stat-value {
            font-size: 24px;
            font-weight: 800;
            margin-top: 4px;
        }
        
        .stat-card .stat-value.gold { color: #fbbf24; }
        .stat-card .stat-value.green { color: #10b981; }
        .stat-card .stat-value.blue { color: #3b82f6; }
        .stat-card .stat-value.purple { color: #8b5cf6; }
        .stat-card .stat-value.red { color: #ef4444; }
        .stat-card .stat-value.orange { color: #f59e0b; }
        .stat-card .stat-value.cyan { color: #06b6d4; }
        
        /* Quick Action Cards */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 12px;
            margin-bottom: 24px;
        }
        
        .quick-action {
            background: #1a1a2e;
            padding: 16px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.04);
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            color: #f1f5f9;
        }
        
        .quick-action:hover {
            border-color: rgba(124, 58, 237, 0.3);
            transform: translateY(-2px);
        }
        
        .quick-action .icon {
            font-size: 28px;
            display: block;
            margin-bottom: 6px;
        }
        
        .quick-action .label {
            font-size: 12px;
            font-weight: 600;
            color: #94a3b8;
        }
        
        .quick-action .count {
            font-size: 18px;
            font-weight: 700;
            color: #f1f5f9;
            display: block;
            margin-top: 2px;
        }
        
        /* Tabs */
        .admin-tabs {
            display: flex;
            gap: 4px;
            background: #1a1a2e;
            padding: 4px;
            border-radius: 12px;
            margin-bottom: 24px;
            flex-wrap: wrap;
            border: 1px solid rgba(255, 255, 255, 0.04);
        }
        
        .admin-tab {
            padding: 10px 20px;
            border: none;
            background: transparent;
            color: #94a3b8;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            border-radius: 8px;
            transition: all 0.2s;
            font-family: inherit;
        }
        
        .admin-tab:hover {
            color: #f1f5f9;
            background: rgba(255, 255, 255, 0.04);
        }
        
        .admin-tab.active {
            color: #f1f5f9;
            background: rgba(124, 58, 237, 0.2);
        }
        
        /* Tab Content */
        .tab-content {
            display: none;
            animation: fadeIn 0.3s ease;
        }
        
        .tab-content.active {
            display: block;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Tables */
        .table-container {
            background: #1a1a2e;
            border-radius: 14px;
            border: 1px solid rgba(255, 255, 255, 0.04);
            overflow: hidden;
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        
        table thead {
            background: rgba(255, 255, 255, 0.02);
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
        }
        
        table th {
            padding: 12px 16px;
            text-align: left;
            color: #94a3b8;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        table td {
            padding: 12px 16px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.02);
        }
        
        table tr:hover td {
            background: rgba(255, 255, 255, 0.02);
        }
        
        .status-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .status-badge.success { background: rgba(16, 185, 129, 0.15); color: #10b981; }
        .status-badge.pending { background: rgba(245, 158, 11, 0.15); color: #f59e0b; }
        .status-badge.failed { background: rgba(239, 68, 68, 0.15); color: #ef4444; }
        .status-badge.playing { background: rgba(59, 130, 246, 0.15); color: #3b82f6; }
        .status-badge.waiting { background: rgba(148, 163, 184, 0.15); color: #94a3b8; }
        .status-badge.completed { background: rgba(16, 185, 129, 0.15); color: #10b981; }
        .status-badge.active { background: rgba(16, 185, 129, 0.15); color: #10b981; }
        .status-badge.inactive { background: rgba(239, 68, 68, 0.15); color: #ef4444; }
        .status-badge.verified { background: rgba(16, 185, 129, 0.15); color: #10b981; }
        .status-badge.rejected { background: rgba(239, 68, 68, 0.15); color: #ef4444; }
        .status-badge.open { background: rgba(239, 68, 68, 0.15); color: #ef4444; }
        .status-badge.resolved { background: rgba(16, 185, 129, 0.15); color: #10b981; }
        
        .btn-action {
            padding: 4px 12px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            font-family: inherit;
            margin: 0 2px;
        }
        
        .btn-action.primary { background: rgba(59, 130, 246, 0.2); color: #3b82f6; }
        .btn-action.primary:hover { background: rgba(59, 130, 246, 0.3); }
        .btn-action.success { background: rgba(16, 185, 129, 0.2); color: #10b981; }
        .btn-action.success:hover { background: rgba(16, 185, 129, 0.3); }
        .btn-action.danger { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
        .btn-action.danger:hover { background: rgba(239, 68, 68, 0.3); }
        .btn-action.warning { background: rgba(245, 158, 11, 0.2); color: #f59e0b; }
        .btn-action.warning:hover { background: rgba(245, 158, 11, 0.3); }
        
        /* Search Bar */
        .search-bar {
            display: flex;
            gap: 12px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }
        
        .search-bar input, .search-bar select {
            flex: 1;
            min-width: 150px;
            padding: 10px 14px;
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.04);
            color: #f1f5f9;
            font-size: 14px;
            font-family: inherit;
        }
        
        .search-bar input:focus, .search-bar select:focus {
            outline: none;
            border-color: #7c3aed;
        }
        
        .search-bar input::placeholder {
            color: #64748b;
        }
        
        .search-bar select option {
            background: #1a1a2e;
        }
        
        /* Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(8px);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        .modal-overlay.active {
            display: flex;
        }
        
        .modal-box {
            background: #1a1a2e;
            padding: 32px;
            border-radius: 16px;
            max-width: 500px;
            width: 100%;
            border: 1px solid rgba(255, 255, 255, 0.06);
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-box h2 {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 16px;
        }
        
        .modal-box .form-group {
            margin-bottom: 14px;
        }
        
        .modal-box .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #94a3b8;
            margin-bottom: 4px;
        }
        
        .modal-box .form-group input,
        .modal-box .form-group select,
        .modal-box .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.04);
            color: #f1f5f9;
            font-size: 14px;
            font-family: inherit;
        }
        
        .modal-box .form-group input:focus,
        .modal-box .form-group select:focus,
        .modal-box .form-group textarea:focus {
            outline: none;
            border-color: #7c3aed;
        }
        
        .modal-actions {
            display: flex;
            gap: 12px;
            margin-top: 20px;
        }
        
        .modal-actions button {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
            font-family: inherit;
        }
        
        .modal-actions .btn-confirm {
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            color: #1a1a2e;
        }
        
        .modal-actions .btn-confirm:hover {
            transform: scale(1.02);
        }
        
        .modal-actions .btn-cancel {
            background: rgba(255, 255, 255, 0.06);
            color: #94a3b8;
        }
        
        .modal-actions .btn-cancel:hover {
            background: rgba(255, 255, 255, 0.1);
        }
        
        /* Toast */
        .toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            padding: 14px 24px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 14px;
            z-index: 2000;
            transform: translateY(100px);
            opacity: 0;
            transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
            max-width: 400px;
        }
        
        .toast.show {
            transform: translateY(0);
            opacity: 1;
        }
        
        .toast.success { background: rgba(16, 185, 129, 0.2); border: 1px solid rgba(16, 185, 129, 0.2); color: #10b981; }
        .toast.error { background: rgba(239, 68, 68, 0.2); border: 1px solid rgba(239, 68, 68, 0.2); color: #ef4444; }
        .toast.info { background: rgba(59, 130, 246, 0.2); border: 1px solid rgba(59, 130, 246, 0.2); color: #3b82f6; }
        
        /* Loading */
        .loading {
            text-align: center;
            padding: 40px;
            color: #94a3b8;
        }
        
        .loading-spinner {
            display: inline-block;
            width: 40px;
            height: 40px;
            border: 3px solid rgba(255, 255, 255, 0.04);
            border-top-color: #fbbf24;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Analytics Chart */
        .chart-container {
            background: #1a1a2e;
            border-radius: 14px;
            padding: 20px;
            border: 1px solid rgba(255, 255, 255, 0.04);
            margin-bottom: 20px;
        }
        
        .chart-container h3 {
            margin-bottom: 16px;
            color: #f1f5f9;
        }
        
        .chart-wrapper {
            position: relative;
            height: 250px;
        }
        
        /* Tournament Card */
        .tournament-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 12px;
        }
        
        .tournament-card {
            background: #1a1a2e;
            padding: 16px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.04);
            transition: all 0.2s;
        }
        
        .tournament-card:hover {
            border-color: rgba(251, 191, 36, 0.15);
            transform: translateY(-2px);
        }
        
        .tournament-card .t-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        
        .tournament-card .t-title strong {
            color: #fbbf24;
            font-size: 15px;
        }
        
        .tournament-card .t-status {
            font-size: 11px;
            padding: 2px 10px;
            border-radius: 12px;
        }
        
        .tournament-card .t-status.scheduled { background: rgba(148, 163, 184, 0.15); color: #94a3b8; }
        .tournament-card .t-status.active { background: rgba(16, 185, 129, 0.15); color: #10b981; }
        .tournament-card .t-status.in_progress { background: rgba(59, 130, 246, 0.15); color: #3b82f6; }
        .tournament-card .t-status.completed { background: rgba(16, 185, 129, 0.15); color: #10b981; }
        .tournament-card .t-status.cancelled { background: rgba(239, 68, 68, 0.15); color: #ef4444; }
        
        .tournament-card .t-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4px;
            font-size: 13px;
            color: #94a3b8;
        }
        
        .tournament-card .t-details span {
            padding: 2px 0;
        }
        
        .tournament-card .t-details .t-amount {
            color: #fbbf24;
            font-weight: 600;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .quick-actions {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .admin-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .admin-tabs {
                flex-direction: column;
            }
            
            .admin-tab {
                text-align: left;
            }
            
            .login-box {
                padding: 24px;
            }
            
            .tournament-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .quick-actions {
                grid-template-columns: 1fr;
            }
            
            .admin-container {
                padding: 12px;
            }
        }
    </style>
</head>
<body>
    <?php if (!$isAdminLoggedIn): ?>
    
    <!-- ==============================================
    ADMIN LOGIN PAGE
    ============================================== -->
    <div class="login-container">
        <div class="login-box">
            <h1>🔐 Admin Access</h1>
            <p>Ludo Tournament Pro Command Center</p>
            
            <?php if (isset($loginError)): ?>
                <div class="login-error"><?php echo htmlspecialchars($loginError); ?></div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" placeholder="Enter admin username" required>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Enter admin password" required>
                </div>
                <button type="submit" name="admin_login" class="login-btn">Login to Dashboard</button>
            </form>
        </div>
    </div>
    
    <?php else: ?>
    
    <!-- ==============================================
    ADMIN DASHBOARD
    ============================================== -->
    <div class="admin-container">
        
        <!-- Header -->
        <div class="admin-header">
            <h1>⚡ Ludo Admin Command Center</h1>
            <div class="admin-header-actions">
                <span>👋 <?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?></span>
                <a href="settings.php" class="nav-link" title="System Settings">⚙️ Settings</a>
                <a href="kyc.php" class="nav-link" title="KYC Management">🛡️ KYC</a>
                <a href="withdrawals.php" class="nav-link" title="Withdrawals">🏦 Withdrawals</a>
                <a href="disputes.php" class="nav-link" title="Disputes">📋 Disputes</a>
                <a href="tournaments.php" class="nav-link" title="Tournament Management">🏆 Tournaments</a>
                <a href="admin_users.php" class="nav-link" title="User Management">👥 Users</a>
                <a href="?logout=1" class="logout">🚪 Logout</a>
            </div>
        </div>
        
        <!-- Quick Action Cards -->
        <div class="quick-actions">
            <a href="kyc.php" class="quick-action">
                <span class="icon">🛡️</span>
                <span class="label">KYC Pending</span>
                <span class="count" id="quickKyc">...</span>
            </a>
            <a href="withdrawals.php" class="quick-action">
                <span class="icon">🏦</span>
                <span class="label">Withdrawals Pending</span>
                <span class="count" id="quickWithdrawals">...</span>
            </a>
            <a href="disputes.php" class="quick-action">
                <span class="icon">📋</span>
                <span class="label">Open Disputes</span>
                <span class="count" id="quickDisputes">...</span>
            </a>
            <a href="tournaments.php" class="quick-action">
                <span class="icon">🏆</span>
                <span class="label">Tournaments</span>
                <span class="count" id="quickTournaments">...</span>
            </a>
            <a href="settings.php" class="quick-action">
                <span class="icon">⚙️</span>
                <span class="label">Maintenance</span>
                <span class="count" id="quickMaintenance">Off</span>
            </a>
        </div>
        
        <!-- Stats Grid -->
        <div class="stats-grid" id="statsGrid">
            <div class="stat-card">
                <div class="stat-label">Total Users <span class="growth-indicator positive" id="userGrowth">+0%</span></div>
                <div class="stat-value blue" id="statTotalUsers">...</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Active Users (30d)</div>
                <div class="stat-value green" id="statActiveUsers">...</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">New Users Today</div>
                <div class="stat-value cyan" id="statNewUsersToday">...</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Active Tournaments</div>
                <div class="stat-value purple" id="statActiveTournaments">...</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Matches</div>
                <div class="stat-value cyan" id="statTotalMatches">...</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Matches Today</div>
                <div class="stat-value blue" id="statMatchesToday">...</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Cashfree Collections</div>
                <div class="stat-value gold" id="statCashfree">...</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Platform Revenue</div>
                <div class="stat-value gold" id="statPlatformRevenue">...</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Net Platform Profit</div>
                <div class="stat-value green" id="statNetProfit">...</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Today's Revenue <span class="growth-indicator positive" id="revenueGrowth">+0%</span></div>
                <div class="stat-value gold" id="statTodayRevenue">...</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Pending KYC</div>
                <div class="stat-value orange" id="statPendingKyc">...</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Pending Withdrawals</div>
                <div class="stat-value red" id="statPendingWithdrawals">...</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Open Disputes</div>
                <div class="stat-value red" id="statOpenDisputes">...</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total TDS Deducted</div>
                <div class="stat-value purple" id="statTotalTDS">...</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Platform Liability</div>
                <div class="stat-value orange" id="statLiability">...</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total User Balance</div>
                <div class="stat-value gold" id="statUserBalance">...</div>
            </div>
        </div>
        
        <!-- Tabs -->
        <div class="admin-tabs">
            <button class="admin-tab active" data-tab="users">👥 Users</button>
            <button class="admin-tab" data-tab="matches">🎯 Matches</button>
            <button class="admin-tab" data-tab="transactions">💳 Transactions</button>
            <button class="admin-tab" data-tab="analytics">📊 Analytics</button>
            <button class="admin-tab" data-tab="tournaments">🏆 Tournaments</button>
        </div>
        
        <!-- Tab: Users -->
        <div class="tab-content active" id="tab-users">
            <div class="search-bar">
                <input type="text" id="userSearch" placeholder="Search users by name, mobile, or email..." onkeyup="debounceSearch()">
                <select id="userLimit" onchange="state.usersLimit = parseInt(this.value); state.usersPage = 0; loadUsers();">
                    <option value="20">20 per page</option>
                    <option value="50" selected>50 per page</option>
                    <option value="100">100 per page</option>
                </select>
                <button class="btn-action primary" onclick="loadUsers()">🔄 Refresh</button>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Mobile</th>
                            <th>Balance</th>
                            <th>Matches</th>
                            <th>Wins</th>
                            <th>ELO</th>
                            <th>KYC</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="usersTableBody">
                        <tr><td colspan="10" class="loading">Loading users...</td></tr>
                    </tbody>
                </table>
            </div>
            <div style="margin-top: 12px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                <span id="userPaginationInfo" style="color: #94a3b8; font-size: 14px;"></span>
                <div style="display: flex; gap: 8px;">
                    <button class="btn-action primary" onclick="previousUsers()">← Prev</button>
                    <button class="btn-action primary" onclick="nextUsers()">Next →</button>
                </div>
            </div>
        </div>
        
        <!-- Tab: Matches -->
        <div class="tab-content" id="tab-matches">
            <div class="search-bar">
                <select id="matchStatusFilter" onchange="loadMatches()">
                    <option value="">All Status</option>
                    <option value="waiting">Waiting</option>
                    <option value="ready">Ready</option>
                    <option value="playing">Playing</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
                <button class="btn-action primary" onclick="loadMatches()">🔄 Refresh</button>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Room</th>
                            <th>Entry Fee</th>
                            <th>Prize Pool</th>
                            <th>Platform Fee</th>
                            <th>TDS</th>
                            <th>Status</th>
                            <th>Players</th>
                            <th>Winner</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody id="matchesTableBody">
                        <tr><td colspan="10" class="loading">Loading matches...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Tab: Transactions -->
        <div class="tab-content" id="tab-transactions">
            <div class="search-bar">
                <input type="number" id="txUserSearch" placeholder="Enter User ID to filter..." style="min-width: 150px;">
                <button class="btn-action primary" onclick="loadTransactions()">🔍 Search</button>
                <button class="btn-action primary" onclick="loadTransactions(0)">📋 All</button>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>User</th>
                            <th>Amount</th>
                            <th>Type</th>
                            <th>Source</th>
                            <th>Status</th>
                            <th>Order ID</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody id="transactionsTableBody">
                        <tr><td colspan="8" class="loading">Loading transactions...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Tab: Analytics -->
        <div class="tab-content" id="tab-analytics">
            <!-- Revenue Chart -->
            <div class="chart-container">
                <h3>📈 Revenue & Growth (Last 30 Days)</h3>
                <div class="chart-wrapper">
                    <canvas id="revenueChart"></canvas>
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div class="stat-card">
                    <div class="stat-label">Total User Balance</div>
                    <div class="stat-value gold" id="analyticsTotalBalance">...</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Total Earnings (All Users)</div>
                    <div class="stat-value green" id="analyticsTotalEarnings">...</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Total Withdrawn</div>
                    <div class="stat-value red" id="analyticsTotalWithdrawn">...</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Platform Liability</div>
                    <div class="stat-value orange" id="analyticsLiability">...</div>
                </div>
            </div>
            
            <div style="background: #1a1a2e; border-radius: 14px; padding: 20px; border: 1px solid rgba(255,255,255,0.04);">
                <h3 style="margin-bottom: 12px;">📊 Financial History (Last 30 Days)</h3>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Deposits</th>
                                <th>Withdrawals</th>
                                <th>Platform Revenue</th>
                                <th>Matches</th>
                                <th>New Users</th>
                                <th>TDS Deducted</th>
                            </tr>
                        </thead>
                        <tbody id="analyticsTableBody">
                            <tr><td colspan="7" class="loading">Loading analytics...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Tab: Tournaments (NEW) -->
        <div class="tab-content" id="tab-tournaments">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 8px;">
                <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                    <span style="color: #94a3b8; font-size: 14px;">Filter:</span>
                    <select id="tournamentStatusFilter" onchange="state.tournamentStatus = this.value; loadTournaments();" style="padding: 6px 12px; border: 1px solid rgba(255,255,255,0.06); border-radius: 8px; background: rgba(255,255,255,0.04); color: #f1f5f9; font-size: 13px; font-family: inherit;">
                        <option value="">All</option>
                        <option value="scheduled">Scheduled</option>
                        <option value="active">Active</option>
                        <option value="in_progress">In Progress</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <a href="tournaments.php" class="btn-action primary" style="padding: 8px 20px; text-decoration: none; display: inline-block;">➕ Manage Tournaments</a>
            </div>
            <div id="tournamentList">
                <div class="loading">Loading tournaments...</div>
            </div>
        </div>
        
    </div>
    
    <!-- ==============================================
    MODAL: Edit User Balance
    ============================================== -->
    <div class="modal-overlay" id="balanceModal">
        <div class="modal-box">
            <h2>💰 Adjust User Balance</h2>
            <form id="balanceForm">
                <input type="hidden" id="balUserId" value="">
                <div class="form-group">
                    <label>User ID</label>
                    <input type="text" id="balUserDisplay" disabled style="opacity: 0.6;">
                </div>
                <div class="form-group">
                    <label>Current Balance</label>
                    <input type="text" id="balCurrent" disabled style="opacity: 0.6;">
                </div>
                <div class="form-group">
                    <label>Action</label>
                    <select id="balType" style="width: 100%; padding: 10px 14px; border: 1px solid rgba(255,255,255,0.06); border-radius: 8px; background: rgba(255,255,255,0.04); color: #f1f5f9; font-size: 14px; font-family: inherit;">
                        <option value="credit">Credit (+)</option>
                        <option value="debit">Debit (-)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Amount (₹)</label>
                    <input type="number" id="balAmount" placeholder="Enter amount" step="0.01" min="0.01" required>
                </div>
                <div class="form-group">
                    <label>Reason</label>
                    <input type="text" id="balReason" placeholder="Reason for adjustment">
                </div>
            </form>
            <div class="modal-actions">
                <button class="btn-confirm" onclick="submitBalance()">✅ Confirm</button>
                <button class="btn-cancel" onclick="closeModal('balanceModal')">Cancel</button>
            </div>
        </div>
    </div>
    
    <!-- ==============================================
    TOAST NOTIFICATION
    ============================================== -->
    <div class="toast" id="adminToast"></div>
    
    <!-- ==============================================
    ADMIN JAVASCRIPT (COMPLETE WITH CHART.JS & TOURNAMENTS)
    ============================================== -->
    <script>
        // ==============================================
        // STATE
        // ==============================================
        let state = {
            usersPage: 0,
            usersLimit: 50,
            usersTotal: 0,
            searchTimeout: null,
            chartInstance: null,
            revenueData: [],
            tournamentStatus: ''
        };
        
        // ==============================================
        // SESSION HANDLER - Check for 401 redirect
        // ==============================================
        function handleApiResponse(response) {
            if (response.status === 401) {
                // Session expired - redirect to login
                showToast('Session expired. Redirecting to login...', 'error');
                setTimeout(() => {
                    window.location.href = 'index.php';
                }, 1500);
                throw new Error('Session expired');
            }
            return response.json();
        }
        
        // ==============================================
        // DOM READY
        // ==============================================
        document.addEventListener('DOMContentLoaded', function() {
            // Tab switching
            document.querySelectorAll('.admin-tab').forEach(tab => {
                tab.addEventListener('click', function() {
                    document.querySelectorAll('.admin-tab').forEach(t => t.classList.remove('active'));
                    this.classList.add('active');
                    
                    const tabId = this.dataset.tab;
                    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                    document.getElementById('tab-' + tabId).classList.add('active');
                    
                    // Load data for tab
                    if (tabId === 'users') loadUsers();
                    else if (tabId === 'matches') loadMatches();
                    else if (tabId === 'transactions') loadTransactions();
                    else if (tabId === 'analytics') loadAnalytics();
                    else if (tabId === 'tournaments') loadTournaments();
                });
            });
            
            // Initial load
            loadStats();
            loadUsers();
            loadQuickStats();
            loadTournaments();
        });
        
        // ==============================================
        // LOAD QUICK STATS
        // ==============================================
        function loadQuickStats() {
            // Load KYC count
            fetch('?ajax=1&action=get_kyc_stats')
                .then(handleApiResponse)
                .then(data => {
                    if (data.success) {
                        document.getElementById('quickKyc').textContent = data.data.pending || 0;
                    }
                })
                .catch(() => {});
            
            // Load Withdrawal count
            fetch('?ajax=1&action=get_withdrawal_stats')
                .then(handleApiResponse)
                .then(data => {
                    if (data.success) {
                        document.getElementById('quickWithdrawals').textContent = data.data.pending || 0;
                    }
                })
                .catch(() => {});
            
            // Load Dispute count
            fetch('?ajax=1&action=get_dispute_stats')
                .then(handleApiResponse)
                .then(data => {
                    if (data.success) {
                        document.getElementById('quickDisputes').textContent = data.data.open || 0;
                    }
                })
                .catch(() => {});
            
            // Load Tournament count
            fetch('?ajax=1&action=get_tournaments&limit=1')
                .then(handleApiResponse)
                .then(data => {
                    if (data.success) {
                        const activeCount = data.data.filter(t => t.status === 'active' || t.status === 'in_progress').length;
                        document.getElementById('quickTournaments').textContent = activeCount || 0;
                    }
                })
                .catch(() => {});
            
            // Check maintenance mode
            fetch('/api/admin_settings.php?action=get_maintenance_status')
                .then(handleApiResponse)
                .then(data => {
                    if (data.success) {
                        document.getElementById('quickMaintenance').textContent = data.data.maintenance_mode ? '🔴 On' : '🟢 Off';
                        document.getElementById('quickMaintenance').style.color = data.data.maintenance_mode ? '#ef4444' : '#10b981';
                    }
                })
                .catch(() => {});
        }
        
        // ==============================================
        // LOAD STATS
        // ==============================================
        function loadStats() {
            fetch('?ajax=1&action=get_stats')
                .then(handleApiResponse)
                .then(data => {
                    if (data.success) {
                        const s = data.data;
                        document.getElementById('statTotalUsers').textContent = s.total_users || 0;
                        document.getElementById('statActiveUsers').textContent = s.active_users || 0;
                        document.getElementById('statNewUsersToday').textContent = s.new_users_today || 0;
                        document.getElementById('statActiveTournaments').textContent = s.active_tournaments || 0;
                        document.getElementById('statTotalMatches').textContent = s.total_matches || 0;
                        document.getElementById('statMatchesToday').textContent = s.matches_today || 0;
                        document.getElementById('statCashfree').textContent = '₹' + (s.cashfree_collections || 0).toFixed(2);
                        document.getElementById('statPlatformRevenue').textContent = '₹' + (s.total_platform_revenue || 0).toFixed(2);
                        document.getElementById('statNetProfit').textContent = '₹' + (s.net_platform_profit || 0).toFixed(2);
                        document.getElementById('statTodayRevenue').textContent = '₹' + (s.today_revenue || 0).toFixed(2);
                        document.getElementById('statPendingKyc').textContent = s.pending_kyc || 0;
                        document.getElementById('statPendingWithdrawals').textContent = s.pending_withdrawals || 0;
                        document.getElementById('statOpenDisputes').textContent = s.open_disputes || 0;
                        document.getElementById('statTotalTDS').textContent = '₹' + (s.total_tds || 0).toFixed(2);
                        document.getElementById('statLiability').textContent = '₹' + (s.platform_liability || 0).toFixed(2);
                        document.getElementById('statUserBalance').textContent = '₹' + (s.total_user_balance || 0).toFixed(2);
                        
                        // Update growth indicators
                        const userGrowth = document.getElementById('userGrowth');
                        userGrowth.textContent = (s.user_growth || 0) >= 0 ? '+' + s.user_growth + '%' : s.user_growth + '%';
                        userGrowth.className = 'growth-indicator ' + ((s.user_growth || 0) >= 0 ? 'positive' : 'negative');
                        
                        const revGrowth = document.getElementById('revenueGrowth');
                        revGrowth.textContent = (s.revenue_growth || 0) >= 0 ? '+' + s.revenue_growth + '%' : s.revenue_growth + '%';
                        revGrowth.className = 'growth-indicator ' + ((s.revenue_growth || 0) >= 0 ? 'positive' : 'negative');
                    }
                })
                .catch(() => {});
        }
        
        // ==============================================
        // LOAD USERS
        // ==============================================
        function loadUsers() {
            const search = document.getElementById('userSearch').value;
            const offset = state.usersPage * state.usersLimit;
            
            document.getElementById('usersTableBody').innerHTML = '<tr><td colspan="10" class="loading"><div class="loading-spinner"></div> Loading...</td></tr>';
            
            fetch(`?ajax=1&action=get_users&offset=${offset}&limit=${state.usersLimit}&search=${encodeURIComponent(search)}`)
                .then(handleApiResponse)
                .then(data => {
                    if (data.success) {
                        state.usersTotal = data.data.total;
                        renderUsers(data.data.users);
                        document.getElementById('userPaginationInfo').textContent = 
                            `Showing ${offset + 1} - ${Math.min(offset + state.usersLimit, state.usersTotal)} of ${state.usersTotal} users`;
                    } else {
                        document.getElementById('usersTableBody').innerHTML = `<tr><td colspan="10" style="color: #ef4444;">${data.message}</td></tr>`;
                    }
                })
                .catch(() => {
                    document.getElementById('usersTableBody').innerHTML = '<tr><td colspan="10" style="color: #ef4444;">Failed to load users</td></tr>';
                });
        }
        
        function renderUsers(users) {
            const tbody = document.getElementById('usersTableBody');
            if (!users || users.length === 0) {
                tbody.innerHTML = '<tr><td colspan="10" style="color: #94a3b8; text-align: center;">No users found</td></tr>';
                return;
            }
            
            tbody.innerHTML = users.map(u => `
                <tr>
                    <td>#${u.id}</td>
                    <td>${escapeHtml(u.username)}</td>
                    <td>${escapeHtml(u.mobile)}</td>
                    <td><strong style="color: #fbbf24;">₹${parseFloat(u.wallet_balance).toFixed(2)}</strong></td>
                    <td>${u.total_matches_played || 0}</td>
                    <td>${u.total_matches_won || 0}</td>
                    <td>${u.elo_rating || 1200}</td>
                    <td>
                        <span class="status-badge ${u.kyc_status === 'verified' ? 'verified' : u.kyc_status === 'rejected' ? 'rejected' : 'pending'}">
                            ${u.kyc_status || 'not_submitted'}
                        </span>
                    </td>
                    <td>
                        <span class="status-badge ${u.is_active ? 'active' : 'inactive'}">
                            ${u.is_active ? 'Active' : 'Inactive'}
                        </span>
                    </td>
                    <td>
                        <button class="btn-action primary" onclick="editBalance(${u.id}, '${escapeHtml(u.username)}', ${u.wallet_balance})">💰</button>
                        <button class="btn-action ${u.is_active ? 'danger' : 'success'}" onclick="toggleUser(${u.id})">
                            ${u.is_active ? '🔒' : '🔓'}
                        </button>
                    </td>
                </tr>
            `).join('');
        }
        
        function previousUsers() {
            if (state.usersPage > 0) {
                state.usersPage--;
                loadUsers();
            }
        }
        
        function nextUsers() {
            if ((state.usersPage + 1) * state.usersLimit < state.usersTotal) {
                state.usersPage++;
                loadUsers();
            }
        }
        
        function debounceSearch() {
            clearTimeout(state.searchTimeout);
            state.searchTimeout = setTimeout(() => {
                state.usersPage = 0;
                loadUsers();
            }, 400);
        }
        
        // ==============================================
        // LOAD MATCHES
        // ==============================================
        function loadMatches() {
            const status = document.getElementById('matchStatusFilter').value;
            
            document.getElementById('matchesTableBody').innerHTML = '<tr><td colspan="10" class="loading"><div class="loading-spinner"></div> Loading...</td></tr>';
            
            fetch(`?ajax=1&action=get_matches&status=${encodeURIComponent(status)}`)
                .then(handleApiResponse)
                .then(data => {
                    if (data.success) {
                        renderMatches(data.data.matches);
                    } else {
                        document.getElementById('matchesTableBody').innerHTML = `<tr><td colspan="10" style="color: #ef4444;">${data.message}</td></tr>`;
                    }
                })
                .catch(() => {
                    document.getElementById('matchesTableBody').innerHTML = '<tr><td colspan="10" style="color: #ef4444;">Failed to load matches</td></tr>';
                });
        }
        
        function renderMatches(matches) {
            const tbody = document.getElementById('matchesTableBody');
            if (!matches || matches.length === 0) {
                tbody.innerHTML = '<tr><td colspan="10" style="color: #94a3b8; text-align: center;">No matches found</td></tr>';
                return;
            }
            
            tbody.innerHTML = matches.map(m => `
                <tr>
                    <td>#${m.id}</td>
                    <td><code style="background: rgba(255,255,255,0.04); padding: 2px 8px; border-radius: 4px;">${escapeHtml(m.room_code)}</code></td>
                    <td>₹${parseFloat(m.entry_fee).toFixed(2)}</td>
                    <td>₹${parseFloat(m.prize_pool).toFixed(2)}</td>
                    <td>₹${parseFloat(m.platform_fee).toFixed(2)}</td>
                    <td>₹${parseFloat(m.tds_deducted || 0).toFixed(2)}</td>
                    <td><span class="status-badge ${m.status}">${m.status}</span></td>
                    <td>${escapeHtml(m.player1_name)} ${m.player2_name ? 'vs ' + escapeHtml(m.player2_name) : ''}</td>
                    <td>${m.winner_name ? escapeHtml(m.winner_name) + ' (₹' + parseFloat(m.winning_amount || 0).toFixed(2) + ')' : '-'}</td>
                    <td style="font-size: 12px; color: #94a3b8;">${m.created_at ? new Date(m.created_at).toLocaleDateString() : '-'}</td>
                </tr>
            `).join('');
        }
        
        // ==============================================
        // LOAD TRANSACTIONS
        // ==============================================
        function loadTransactions(userId) {
            const uid = userId || document.getElementById('txUserSearch').value || 0;
            
            document.getElementById('transactionsTableBody').innerHTML = '<tr><td colspan="8" class="loading"><div class="loading-spinner"></div> Loading...</td></tr>';
            
            const url = uid > 0 ? `?ajax=1&action=get_transactions&user_id=${uid}` : `?ajax=1&action=get_transactions`;
            
            fetch(url)
                .then(handleApiResponse)
                .then(data => {
                    if (data.success) {
                        renderTransactions(data.data);
                    } else {
                        document.getElementById('transactionsTableBody').innerHTML = `<tr><td colspan="8" style="color: #ef4444;">${data.message}</td></tr>`;
                    }
                })
                .catch(() => {
                    document.getElementById('transactionsTableBody').innerHTML = '<tr><td colspan="8" style="color: #ef4444;">Failed to load transactions</td></tr>';
                });
        }
        
        function renderTransactions(transactions) {
            const tbody = document.getElementById('transactionsTableBody');
            if (!transactions || transactions.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" style="color: #94a3b8; text-align: center;">No transactions found</td></tr>';
                return;
            }
            
            tbody.innerHTML = transactions.map(t => `
                <tr>
                    <td>#${t.id}</td>
                    <td>User #${t.user_id}</td>
                    <td style="color: ${t.type === 'credit' ? '#10b981' : '#ef4444'};">${t.type === 'credit' ? '+' : '-'}₹${parseFloat(t.amount).toFixed(2)}</td>
                    <td><span style="text-transform: capitalize;">${t.type}</span></td>
                    <td><span style="text-transform: capitalize;">${t.source}</span></td>
                    <td><span class="status-badge ${t.status}">${t.status}</span></td>
                    <td style="font-size: 11px; color: #94a3b8;">${escapeHtml(t.order_id)}</td>
                    <td style="font-size: 12px; color: #94a3b8;">${t.created_at ? new Date(t.created_at).toLocaleString() : '-'}</td>
                </tr>
            `).join('');
        }
        
        // ==============================================
        // LOAD ANALYTICS WITH CHART
        // ==============================================
        function loadAnalytics() {
            fetch('?ajax=1&action=get_financial_metrics&days=30')
                .then(handleApiResponse)
                .then(data => {
                    if (data.success) {
                        const totals = data.data.totals;
                        document.getElementById('analyticsTotalBalance').textContent = '₹' + (totals.total_user_balance || 0).toFixed(2);
                        document.getElementById('analyticsTotalEarnings').textContent = '₹' + (totals.total_earnings || 0).toFixed(2);
                        document.getElementById('analyticsTotalWithdrawn').textContent = '₹' + (totals.total_withdrawn || 0).toFixed(2);
                        document.getElementById('analyticsLiability').textContent = '₹' + ((totals.total_user_balance || 0) - (totals.total_withdrawn || 0)).toFixed(2);
                        
                        state.revenueData = data.data.history;
                        renderAnalytics(state.revenueData);
                        renderRevenueChart(state.revenueData);
                    } else {
                        document.getElementById('analyticsTableBody').innerHTML = `<tr><td colspan="7" style="color: #ef4444;">${data.message}</td></tr>`;
                    }
                })
                .catch(() => {
                    document.getElementById('analyticsTableBody').innerHTML = '<tr><td colspan="7" style="color: #ef4444;">Failed to load analytics</td></tr>';
                });
        }
        
        function renderAnalytics(history) {
            const tbody = document.getElementById('analyticsTableBody');
            if (!history || history.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" style="color: #94a3b8; text-align: center;">No analytics data available</td></tr>';
                return;
            }
            
            tbody.innerHTML = history.map(h => `
                <tr>
                    <td>${h.metric_date}</td>
                    <td style="color: #10b981;">₹${parseFloat(h.daily_deposits || 0).toFixed(2)}</td>
                    <td style="color: #ef4444;">₹${parseFloat(h.daily_withdrawals || 0).toFixed(2)}</td>
                    <td style="color: #fbbf24;">₹${parseFloat(h.daily_platform_revenue || 0).toFixed(2)}</td>
                    <td>${h.daily_matches_played || 0}</td>
                    <td>${h.daily_new_users || 0}</td>
                    <td style="color: #8b5cf6;">₹${parseFloat(h.total_tds_deducted || 0).toFixed(2)}</td>
                </tr>
            `).join('');
        }
        
        function renderRevenueChart(history) {
            const ctx = document.getElementById('revenueChart');
            if (!ctx) return;
            
            if (state.chartInstance) {
                state.chartInstance.destroy();
            }
            
            const labels = history.map(h => h.metric_date);
            const revenue = history.map(h => parseFloat(h.daily_platform_revenue || 0));
            const deposits = history.map(h => parseFloat(h.daily_deposits || 0));
            const withdrawals = history.map(h => parseFloat(h.daily_withdrawals || 0));
            
            state.chartInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Deposits',
                            data: deposits,
                            backgroundColor: 'rgba(16, 185, 129, 0.5)',
                            borderColor: '#10b981',
                            borderWidth: 1,
                            borderRadius: 4
                        },
                        {
                            label: 'Withdrawals',
                            data: withdrawals,
                            backgroundColor: 'rgba(239, 68, 68, 0.5)',
                            borderColor: '#ef4444',
                            borderWidth: 1,
                            borderRadius: 4
                        },
                        {
                            label: 'Platform Revenue',
                            data: revenue,
                            backgroundColor: 'rgba(251, 191, 36, 0.5)',
                            borderColor: '#fbbf24',
                            borderWidth: 1,
                            borderRadius: 4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            labels: {
                                color: '#94a3b8',
                                font: {
                                    size: 11,
                                    family: 'Inter'
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                color: 'rgba(255, 255, 255, 0.04)'
                            },
                            ticks: {
                                color: '#94a3b8',
                                font: {
                                    size: 10
                                }
                            }
                        },
                        y: {
                            grid: {
                                color: 'rgba(255, 255, 255, 0.04)'
                            },
                            ticks: {
                                color: '#94a3b8',
                                font: {
                                    size: 10
                                },
                                callback: function(value) {
                                    return '₹' + value;
                                }
                            }
                        }
                    }
                }
            });
        }
        
        // ==============================================
        // LOAD TOURNAMENTS (NEW)
        // ==============================================
        function loadTournaments() {
            const container = document.getElementById('tournamentList');
            const status = document.getElementById('tournamentStatusFilter')?.value || '';
            
            container.innerHTML = '<div class="loading"><div class="loading-spinner"></div> Loading tournaments...</div>';
            
            fetch(`?ajax=1&action=get_tournaments&limit=20&status=${status}`)
                .then(handleApiResponse)
                .then(data => {
                    if (data.success) {
                        renderTournaments(data.data);
                    } else {
                        container.innerHTML = `<p style="color: #ef4444;">${data.message}</p>`;
                    }
                })
                .catch(() => {
                    container.innerHTML = '<p style="color: #ef4444;">Failed to load tournaments</p>';
                });
        }
        
        function renderTournaments(tournaments) {
            const container = document.getElementById('tournamentList');
            
            if (!tournaments || tournaments.length === 0) {
                container.innerHTML = '<p style="color: #94a3b8; text-align: center; padding: 40px;">No tournaments available. <a href="tournaments.php" style="color: #8b5cf6; text-decoration: none;">Create one</a></p>';
                return;
            }
            
            const statusColors = {
                'scheduled': 'scheduled',
                'active': 'active',
                'in_progress': 'in_progress',
                'completed': 'completed',
                'cancelled': 'cancelled'
            };
            
            let html = '<div class="tournament-grid">';
            
            tournaments.forEach(t => {
                html += `
                    <div class="tournament-card">
                        <div class="t-title">
                            <strong>${escapeHtml(t.name)}</strong>
                            <span class="t-status ${statusColors[t.status] || 'scheduled'}">${t.status.replace('_', ' ').toUpperCase()}</span>
                        </div>
                        <div class="t-details">
                            <span>Entry: <span class="t-amount">₹${parseFloat(t.entry_fee).toFixed(2)}</span></span>
                            <span>Prize: <span class="t-amount">₹${parseFloat(t.prize_pool).toFixed(2)}</span></span>
                            <span>Players: ${t.current_players}/${t.max_players}</span>
                            <span>Matches: ${t.match_count || 0}</span>
                            <span style="grid-column: span 2; font-size: 12px; color: #64748b;">Code: ${escapeHtml(t.tournament_code)}</span>
                        </div>
                    </div>
                `;
            });
            
            html += '</div>';
            container.innerHTML = html;
        }
        
        // ==============================================
        // EDIT BALANCE MODAL
        // ==============================================
        function editBalance(userId, username, currentBalance) {
            document.getElementById('balUserId').value = userId;
            document.getElementById('balUserDisplay').value = `#${userId} - ${username}`;
            document.getElementById('balCurrent').value = `₹${currentBalance.toFixed(2)}`;
            document.getElementById('balAmount').value = '';
            document.getElementById('balReason').value = '';
            document.getElementById('balType').value = 'credit';
            
            document.getElementById('balanceModal').classList.add('active');
        }
        
        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }
        
        function submitBalance() {
            const userId = document.getElementById('balUserId').value;
            const amount = parseFloat(document.getElementById('balAmount').value);
            const type = document.getElementById('balType').value;
            const reason = document.getElementById('balReason').value || 'Admin adjustment';
            
            if (!userId || !amount || amount <= 0) {
                showToast('Please enter a valid amount', 'error');
                return;
            }
            
            fetch('?ajax=1&action=update_balance', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    user_id: parseInt(userId),
                    amount: amount,
                    type: type,
                    reason: reason
                })
            })
            .then(handleApiResponse)
            .then(data => {
                if (data.success) {
                    showToast('Balance updated successfully!', 'success');
                    closeModal('balanceModal');
                    loadUsers();
                    loadStats();
                } else {
                    showToast(data.message || 'Update failed', 'error');
                }
            })
            .catch(() => {
                showToast('Network error', 'error');
            });
        }
        
        // ==============================================
        // TOGGLE USER
        // ==============================================
        function toggleUser(userId) {
            if (!confirm('Toggle user status?')) return;
            
            fetch('?ajax=1&action=toggle_user', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ user_id: userId })
            })
            .then(handleApiResponse)
            .then(data => {
                if (data.success) {
                    showToast('User status toggled', 'success');
                    loadUsers();
                } else {
                    showToast(data.message || 'Toggle failed', 'error');
                }
            })
            .catch(() => {
                showToast('Network error', 'error');
            });
        }
        
        // ==============================================
        // TOAST NOTIFICATIONS
        // ==============================================
        function showToast(message, type = 'info') {
            const toast = document.getElementById('adminToast');
            toast.textContent = message;
            toast.className = 'toast ' + type + ' show';
            
            clearTimeout(toast._timeout);
            toast._timeout = setTimeout(() => {
                toast.classList.remove('show');
            }, 4000);
        }
        
        // ==============================================
        // UTILITY: Escape HTML
        // ==============================================
        function escapeHtml(str) {
            if (!str) return '';
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }
        
        // ==============================================
        // CLOSE MODAL ON OVERLAY CLICK
        // ==============================================
        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                }
            });
        });
        
        // ==============================================
        // KEYBOARD SHORTCUTS
        // ==============================================
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay.active').forEach(m => m.classList.remove('active'));
            }
            if (e.key === 'r' && e.ctrlKey) {
                e.preventDefault();
                loadStats();
                loadUsers();
                loadQuickStats();
                loadTournaments();
            }
        });
        
        // ==============================================
        // AUTO-REFRESH (Every 60 seconds)
        // ==============================================
        setInterval(function() {
            const activeTab = document.querySelector('.admin-tab.active');
            if (activeTab) {
                const tabId = activeTab.dataset.tab;
                if (tabId === 'users') loadUsers();
                else if (tabId === 'matches') loadMatches();
                else if (tabId === 'transactions') loadTransactions();
                else if (tabId === 'analytics') loadAnalytics();
                else if (tabId === 'tournaments') loadTournaments();
            }
            loadStats();
            loadQuickStats();
        }, 60000);
    </script>
    
    <?php endif; ?>
    
</body>
</html>
