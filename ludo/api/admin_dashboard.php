<?php
/**
 * ======================================================
 * ADMIN_DASHBOARD.PHP - Dashboard Statistics API
 * Ludo Tournament Platform - Admin Dashboard Stats
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
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
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
    
    // Verify admin with database session token
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
    case 'get_stats':
        handleGetStats();
        break;
    case 'get_chart_data':
        handleGetChartData();
        break;
    case 'get_recent_activity':
        handleGetRecentActivity();
        break;
    case 'get_all':
        handleGetAll();
        break;
    default:
        jsonResponse(false, 'Invalid action specified', [], 400);
        break;
}

// ==============================================
// HANDLER: Get All Dashboard Data
// ==============================================
function handleGetAll() {
    $stats = getStats();
    $chartData = getChartData();
    $recentActivity = getRecentActivity();
    
    jsonResponse(true, 'Dashboard data retrieved', [
        'stats' => $stats,
        'chart' => $chartData,
        'recent' => $recentActivity
    ]);
}

// ==============================================
// HANDLER: Get Stats
// ==============================================
function handleGetStats() {
    $stats = getStats();
    jsonResponse(true, 'Stats retrieved', $stats);
}

// ==============================================
// HANDLER: Get Chart Data
// ==============================================
function handleGetChartData() {
    $days = isset($_GET['days']) ? intval($_GET['days']) : 7;
    $data = getChartData($days);
    jsonResponse(true, 'Chart data retrieved', $data);
}

// ==============================================
// HANDLER: Get Recent Activity
// ==============================================
function handleGetRecentActivity() {
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
    $data = getRecentActivity($limit);
    jsonResponse(true, 'Recent activity retrieved', $data);
}

// ==============================================
// FUNCTION: Get Stats
// ==============================================
function getStats() {
    global $db, $conn;
    
    $stats = [];
    
    // Total Users
    $stmt = $conn->query("SELECT COUNT(*) as total FROM users WHERE is_admin = 0");
    $stats['total_users'] = intval($stmt->fetchColumn());
    
    // Today's Active Users
    $stmt = $conn->query("
        SELECT COUNT(DISTINCT user_id) as active 
        FROM transactions 
        WHERE DATE(created_at) = CURDATE()
    ");
    $stats['today_active_users'] = intval($stmt->fetchColumn());
    
    // Active Users (last 24 hours)
    $stmt = $conn->query("
        SELECT COUNT(DISTINCT user_id) as active 
        FROM transactions 
        WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $stats['active_users_24h'] = intval($stmt->fetchColumn());
    
    // Total Matches
    $stmt = $conn->query("SELECT COUNT(*) as total FROM matches");
    $stats['total_matches'] = intval($stmt->fetchColumn());
    
    // Pending Matches
    $stmt = $conn->query("
        SELECT COUNT(*) as pending 
        FROM matches 
        WHERE status IN ('waiting', 'ready', 'playing')
    ");
    $stats['pending_matches'] = intval($stmt->fetchColumn());
    
    // Completed Matches
    $stmt = $conn->query("SELECT COUNT(*) as completed FROM matches WHERE status = 'completed'");
    $stats['completed_matches'] = intval($stmt->fetchColumn());
    
    // Today's Matches
    $stmt = $conn->query("
        SELECT COUNT(*) as today 
        FROM matches 
        WHERE DATE(created_at) = CURDATE()
    ");
    $stats['matches_today'] = intval($stmt->fetchColumn());
    
    // Total Deposits
    $stmt = $conn->query("
        SELECT SUM(amount) as total 
        FROM transactions 
        WHERE source = 'deposit' 
        AND status = 'success'
    ");
    $stats['total_deposits'] = floatval($stmt->fetchColumn());
    
    // Total Withdrawals
    $stmt = $conn->query("
        SELECT SUM(amount) as total 
        FROM transactions 
        WHERE source = 'withdrawal' 
        AND status IN ('success', 'processing')
    ");
    $stats['total_withdrawals'] = floatval($stmt->fetchColumn());
    
    // Today's Deposits
    $stmt = $conn->query("
        SELECT SUM(amount) as total 
        FROM transactions 
        WHERE source = 'deposit' 
        AND status = 'success'
        AND DATE(created_at) = CURDATE()
    ");
    $stats['today_deposits'] = floatval($stmt->fetchColumn());
    
    // Today's Withdrawals
    $stmt = $conn->query("
        SELECT SUM(amount) as total 
        FROM transactions 
        WHERE source = 'withdrawal' 
        AND status IN ('success', 'processing')
        AND DATE(created_at) = CURDATE()
    ");
    $stats['today_withdrawals'] = floatval($stmt->fetchColumn());
    
    // Platform Revenue
    $stmt = $conn->query("
        SELECT SUM(platform_fee) as total 
        FROM matches 
        WHERE status = 'completed'
    ");
    $stats['platform_revenue'] = floatval($stmt->fetchColumn());
    
    // Today's Revenue
    $stmt = $conn->query("
        SELECT SUM(platform_fee) as total 
        FROM matches 
        WHERE status = 'completed' 
        AND DATE(completed_at) = CURDATE()
    ");
    $stats['today_revenue'] = floatval($stmt->fetchColumn());
    
    // Pending KYC
    $stmt = $conn->query("
        SELECT COUNT(*) as pending 
        FROM kyc_documents 
        WHERE status = 'pending'
    ");
    $stats['pending_kyc'] = intval($stmt->fetchColumn());
    
    // Pending Withdrawals
    $stmt = $conn->query("
        SELECT COUNT(*) as pending 
        FROM withdrawals 
        WHERE status = 'pending'
    ");
    $stats['pending_withdrawals'] = intval($stmt->fetchColumn());
    
    // Open Disputes
    $stmt = $conn->query("
        SELECT COUNT(*) as open 
        FROM dispute_tickets 
        WHERE status IN ('open', 'investigating')
    ");
    $stats['open_disputes'] = intval($stmt->fetchColumn());
    
    // Total TDS
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
    
    // Platform Liability
    $stmt = $conn->query("
        SELECT SUM(total_withdrawn) as total 
        FROM users 
        WHERE is_admin = 0
    ");
    $stats['total_withdrawn'] = floatval($stmt->fetchColumn());
    $stats['platform_liability'] = $stats['total_user_balance'] - $stats['total_withdrawn'];
    
    // New Users Today
    $stmt = $conn->query("
        SELECT COUNT(*) as today 
        FROM users 
        WHERE DATE(created_at) = CURDATE() 
        AND is_admin = 0
    ");
    $stats['new_users_today'] = intval($stmt->fetchColumn());
    
    // Growth percentages (compared to last 30 days)
    $stmt = $conn->query("
        SELECT COUNT(*) as last_month 
        FROM users 
        WHERE is_admin = 0 
        AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
        AND created_at < DATE_SUB(NOW(), INTERVAL 60 DAY)
    ");
    $lastMonthUsers = intval($stmt->fetchColumn());
    $stats['user_growth'] = $lastMonthUsers > 0 ? round((($stats['new_users_today'] - $lastMonthUsers) / $lastMonthUsers) * 100, 1) : 0;
    
    return $stats;
}

// ==============================================
// FUNCTION: Get Chart Data
// ==============================================
function getChartData($days = 7) {
    global $db, $conn;
    
    $data = [
        'labels' => [],
        'deposits' => [],
        'withdrawals' => [],
        'revenue' => [],
        'matches' => [],
        'users' => []
    ];
    
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $data['labels'][] = date('d M', strtotime($date));
        
        // Deposits
        $stmt = $conn->prepare("
            SELECT SUM(amount) as total 
            FROM transactions 
            WHERE source = 'deposit' 
            AND status = 'success' 
            AND DATE(created_at) = :date
        ");
        $stmt->execute([':date' => $date]);
        $data['deposits'][] = floatval($stmt->fetchColumn());
        
        // Withdrawals
        $stmt = $conn->prepare("
            SELECT SUM(amount) as total 
            FROM transactions 
            WHERE source = 'withdrawal' 
            AND status IN ('success', 'processing') 
            AND DATE(created_at) = :date
        ");
        $stmt->execute([':date' => $date]);
        $data['withdrawals'][] = floatval($stmt->fetchColumn());
        
        // Revenue (Platform Fee)
        $stmt = $conn->prepare("
            SELECT SUM(platform_fee) as total 
            FROM matches 
            WHERE status = 'completed' 
            AND DATE(completed_at) = :date
        ");
        $stmt->execute([':date' => $date]);
        $data['revenue'][] = floatval($stmt->fetchColumn());
        
        // Matches
        $stmt = $conn->prepare("
            SELECT COUNT(*) as total 
            FROM matches 
            WHERE DATE(created_at) = :date
        ");
        $stmt->execute([':date' => $date]);
        $data['matches'][] = intval($stmt->fetchColumn());
        
        // New Users
        $stmt = $conn->prepare("
            SELECT COUNT(*) as total 
            FROM users 
            WHERE DATE(created_at) = :date 
            AND is_admin = 0
        ");
        $stmt->execute([':date' => $date]);
        $data['users'][] = intval($stmt->fetchColumn());
    }
    
    return $data;
}

// ==============================================
// FUNCTION: Get Recent Activity
// ==============================================
function getRecentActivity($limit = 10) {
    global $db, $conn;
    
    $activity = [];
    
    // Recent Deposits
    $stmt = $conn->prepare("
        SELECT 
            'deposit' as type,
            t.id,
            t.user_id,
            t.amount,
            t.created_at,
            u.username
        FROM transactions t
        LEFT JOIN users u ON t.user_id = u.id
        WHERE t.source = 'deposit' 
        AND t.status = 'success'
        ORDER BY t.created_at DESC
        LIMIT :limit
    ");
    $stmt->execute([':limit' => $limit]);
    $deposits = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent Withdrawals
    $stmt = $conn->prepare("
        SELECT 
            'withdrawal' as type,
            w.id,
            w.user_id,
            w.amount,
            w.status,
            w.created_at,
            u.username
        FROM withdrawals w
        LEFT JOIN users u ON w.user_id = u.id
        WHERE w.status IN ('pending', 'approved', 'completed')
        ORDER BY w.created_at DESC
        LIMIT :limit
    ");
    $stmt->execute([':limit' => $limit]);
    $withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent Matches
    $stmt = $conn->prepare("
        SELECT 
            'match' as type,
            m.id,
            m.room_code,
            m.entry_fee,
            m.status,
            m.created_at,
            m.player1_name,
            m.player2_name
        FROM matches m
        WHERE m.status IN ('playing', 'completed')
        ORDER BY m.created_at DESC
        LIMIT :limit
    ");
    $stmt->execute([':limit' => $limit]);
    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent Disputes
    $stmt = $conn->prepare("
        SELECT 
            'dispute' as type,
            dt.id,
            dt.ticket_number,
            dt.subject,
            dt.priority,
            dt.status,
            dt.created_at,
            u.username
        FROM dispute_tickets dt
        LEFT JOIN users u ON dt.user_id = u.id
        WHERE dt.status IN ('open', 'investigating')
        ORDER BY dt.created_at DESC
        LIMIT :limit
    ");
    $stmt->execute([':limit' => $limit]);
    $disputes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Merge and sort all activities
    $allActivities = array_merge($deposits, $withdrawals, $matches, $disputes);
    
    usort($allActivities, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    
    // Return only top $limit
    return array_slice($allActivities, 0, $limit);
}
?>
