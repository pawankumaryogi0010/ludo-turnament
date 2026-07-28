<?php
/**
 * ======================================================
 * ADMIN_DASHBOARD.PHP - Dashboard Statistics API (FIXED)
 * Ludo Tournament Platform - Admin Dashboard Stats
 * Version: 3.0.0 - jsonResponse FIX + USER GROWTH FIX
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
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
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
    case 'get_stats': handleGetStats(); break;
    case 'get_chart_data': handleGetChartData(); break;
    case 'get_recent_activity': handleGetRecentActivity(); break;
    case 'get_all': handleGetAll(); break;
    default: jsonResponse(false, 'Invalid action', [], 400);
}

// ==============================================
// GET ALL DASHBOARD DATA
// ==============================================
function handleGetAll() {
    jsonResponse(true, 'Dashboard data', [
        'stats' => getStats(),
        'chart' => getChartData(),
        'recent' => getRecentActivity()
    ]);
}

// ==============================================
// GET STATS
// ==============================================
function handleGetStats() {
    jsonResponse(true, 'Stats retrieved', getStats());
}

function getStats() {
    global $conn;
    $stats = [];
    
    // Total Users
    $stats['total_users'] = intval($conn->query("SELECT COUNT(*) FROM users WHERE is_admin = 0")->fetchColumn());
    
    // Today's Active Users
    $stats['today_active_users'] = intval($conn->query("SELECT COUNT(DISTINCT user_id) FROM transactions WHERE DATE(created_at) = CURDATE()")->fetchColumn());
    
    // Active Users 24h
    $stats['active_users_24h'] = intval($conn->query("SELECT COUNT(DISTINCT user_id) FROM transactions WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn());
    
    // Total Matches
    $stats['total_matches'] = intval($conn->query("SELECT COUNT(*) FROM matches")->fetchColumn());
    
    // Pending Matches
    $stats['pending_matches'] = intval($conn->query("SELECT COUNT(*) FROM matches WHERE status IN ('waiting','ready','playing')")->fetchColumn());
    
    // Completed Matches
    $stats['completed_matches'] = intval($conn->query("SELECT COUNT(*) FROM matches WHERE status = 'completed'")->fetchColumn());
    
    // Today's Matches
    $stats['matches_today'] = intval($conn->query("SELECT COUNT(*) FROM matches WHERE DATE(created_at) = CURDATE()")->fetchColumn());
    
    // Total Deposits
    $stats['total_deposits'] = floatval($conn->query("SELECT SUM(amount) FROM transactions WHERE source = 'deposit' AND status = 'success'")->fetchColumn());
    
    // Total Withdrawals
    $stats['total_withdrawals'] = floatval($conn->query("SELECT SUM(amount) FROM transactions WHERE source = 'withdrawal' AND status IN ('success','processing')")->fetchColumn());
    
    // Platform Revenue
    $stats['platform_revenue'] = floatval($conn->query("SELECT SUM(platform_fee) FROM matches WHERE status = 'completed'")->fetchColumn());
    
    // Today's Revenue
    $stats['today_revenue'] = floatval($conn->query("SELECT SUM(platform_fee) FROM matches WHERE status = 'completed' AND DATE(completed_at) = CURDATE()")->fetchColumn());
    
    // Pending KYC
    $stats['pending_kyc'] = intval($conn->query("SELECT COUNT(*) FROM kyc_documents WHERE status = 'pending'")->fetchColumn());
    
    // Pending Withdrawals
    $stats['pending_withdrawals'] = intval($conn->query("SELECT COUNT(*) FROM withdrawals WHERE status = 'pending'")->fetchColumn());
    
    // Open Disputes
    $stats['open_disputes'] = intval($conn->query("SELECT COUNT(*) FROM dispute_tickets WHERE status IN ('open','investigating')")->fetchColumn());
    
    // Total TDS
    $stats['total_tds'] = floatval($conn->query("SELECT SUM(tds_amount) FROM tds_transactions")->fetchColumn());
    
    // Total User Balance
    $stats['total_user_balance'] = floatval($conn->query("SELECT SUM(wallet_balance) FROM users WHERE is_admin = 0")->fetchColumn());
    
    // Platform Liability
    $stats['total_withdrawn'] = floatval($conn->query("SELECT SUM(total_withdrawn) FROM users WHERE is_admin = 0")->fetchColumn());
    $stats['platform_liability'] = $stats['total_user_balance'] - $stats['total_withdrawn'];
    
    // New Users Today
    $stats['new_users_today'] = intval($conn->query("SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE() AND is_admin = 0")->fetchColumn());
    
    // FIXED: User growth - compare this month vs last month
    $thisMonth = intval($conn->query("SELECT COUNT(*) FROM users WHERE is_admin = 0 AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')")->fetchColumn());
    $lastMonth = intval($conn->query("SELECT COUNT(*) FROM users WHERE is_admin = 0 AND created_at >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1 MONTH), '%Y-%m-01') AND created_at < DATE_FORMAT(NOW(), '%Y-%m-01')")->fetchColumn());
    $stats['user_growth'] = $lastMonth > 0 ? round(($thisMonth - $lastMonth) / $lastMonth * 100, 1) : ($thisMonth > 0 ? 100.0 : 0.0);
    
    return $stats;
}

// ==============================================
// GET CHART DATA
// ==============================================
function handleGetChartData() {
    $days = intval($_GET['days'] ?? 7);
    jsonResponse(true, 'Chart data', getChartData($days));
}

function getChartData($days = 7) {
    global $conn;
    
    $data = ['labels' => [], 'deposits' => [], 'withdrawals' => [], 'revenue' => [], 'matches' => [], 'users' => []];
    
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $data['labels'][] = date('d M', strtotime($date));
        
        $stmt = $conn->prepare("SELECT SUM(amount) FROM transactions WHERE source = 'deposit' AND status = 'success' AND DATE(created_at) = :d");
        $stmt->execute([':d' => $date]);
        $data['deposits'][] = floatval($stmt->fetchColumn());
        
        $stmt = $conn->prepare("SELECT SUM(amount) FROM transactions WHERE source = 'withdrawal' AND status IN ('success','processing') AND DATE(created_at) = :d");
        $stmt->execute([':d' => $date]);
        $data['withdrawals'][] = floatval($stmt->fetchColumn());
        
        $stmt = $conn->prepare("SELECT SUM(platform_fee) FROM matches WHERE status = 'completed' AND DATE(completed_at) = :d");
        $stmt->execute([':d' => $date]);
        $data['revenue'][] = floatval($stmt->fetchColumn());
        
        $stmt = $conn->prepare("SELECT COUNT(*) FROM matches WHERE DATE(created_at) = :d");
        $stmt->execute([':d' => $date]);
        $data['matches'][] = intval($stmt->fetchColumn());
        
        $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE DATE(created_at) = :d AND is_admin = 0");
        $stmt->execute([':d' => $date]);
        $data['users'][] = intval($stmt->fetchColumn());
    }
    
    return $data;
}

// ==============================================
// GET RECENT ACTIVITY
// ==============================================
function handleGetRecentActivity() {
    $limit = intval($_GET['limit'] ?? 10);
    jsonResponse(true, 'Recent activity', getRecentActivity($limit));
}

function getRecentActivity($limit = 10) {
    global $conn;
    
    $activity = [];
    
    // Recent Deposits
    $stmt = $conn->prepare("SELECT 'deposit' as type, t.id, t.user_id, t.amount, t.created_at, u.username FROM transactions t LEFT JOIN users u ON t.user_id = u.id WHERE t.source = 'deposit' AND t.status = 'success' ORDER BY t.created_at DESC LIMIT :l");
    $stmt->execute([':l' => $limit]);
    $deposits = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent Withdrawals
    $stmt = $conn->prepare("SELECT 'withdrawal' as type, w.id, w.user_id, w.amount, w.status, w.created_at, u.username FROM withdrawals w LEFT JOIN users u ON w.user_id = u.id WHERE w.status IN ('pending','approved','completed') ORDER BY w.created_at DESC LIMIT :l");
    $stmt->execute([':l' => $limit]);
    $withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent Matches
    $stmt = $conn->prepare("SELECT 'match' as type, m.id, m.room_code, m.entry_fee, m.status, m.created_at, m.player1_name, m.player2_name FROM matches m WHERE m.status IN ('playing','completed') ORDER BY m.created_at DESC LIMIT :l");
    $stmt->execute([':l' => $limit]);
    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Merge and sort
    $all = array_merge($deposits, $withdrawals, $matches);
    usort($all, function($a, $b) { return strtotime($b['created_at']) - strtotime($a['created_at']); });
    
    return array_slice($all, 0, $limit);
}
?>
