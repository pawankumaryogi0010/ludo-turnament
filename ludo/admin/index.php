<?php
/**
 * ======================================================
 * ADMIN INDEX.PHP - Pro Command Center (FIXED)
 * Ludo Tournament Platform - Admin Dashboard
 * Version: 5.0.0 - ADMIN LOGIN FULLY FIXED
 * ======================================================
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once dirname(__DIR__) . '/config/db.php';

SessionManager::init();

// Check admin login
$isAdminLoggedIn = false;
$adminData = null;

if (isset($_SESSION['admin_id']) && isset($_SESSION['admin_token'])) {
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("
            SELECT u.id, u.username, u.is_admin, u.is_active, u.last_login,
                   s.session_token as db_token, s.expires_at
            FROM users u
            LEFT JOIN sessions s ON u.id = s.user_id AND s.is_active = 1
            WHERE u.id = :aid AND u.is_admin = 1 AND u.is_active = 1
        ");
        $stmt->execute([':aid' => $_SESSION['admin_id']]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($admin && $admin['db_token'] === $_SESSION['admin_token']) {
            if (strtotime($admin['expires_at']) > time()) {
                $isAdminLoggedIn = true;
                $adminData = $admin;
                
                // Update last activity
                $stmt = $conn->prepare("UPDATE sessions SET last_activity = CURRENT_TIMESTAMP WHERE user_id = :aid AND is_active = 1");
                $stmt->execute([':aid' => $_SESSION['admin_id']]);
            }
        }
    } catch (Exception $e) {
        $isAdminLoggedIn = false;
    }
}

// Handle login - FIXED
if (!$isAdminLoggedIn && isset($_POST['admin_login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (!empty($username) && !empty($password)) {
        try {
            $db = Database::getInstance();
            $conn = $db->getConnection();
            
            // FIXED: Debug - agar login fail ho raha hai toh comment hatao
            /*
            $testStmt = $conn->prepare("SELECT id, username, password_hash, is_admin, is_active FROM users WHERE username = :uname");
            $testStmt->execute([':uname' => $username]);
            $testUser = $testStmt->fetch(PDO::FETCH_ASSOC);
            error_log('Login attempt: ' . $username . ' - User found: ' . ($testUser ? 'YES' : 'NO'));
            if ($testUser) {
                error_log('Password verify: ' . (password_verify($password, $testUser['password_hash']) ? 'YES' : 'NO'));
            }
            */
            
            $stmt = $conn->prepare("SELECT id, username, password_hash, is_admin, is_active FROM users WHERE username = :uname AND is_admin = 1");
            $stmt->execute([':uname' => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && $user['is_active'] == 1 && password_verify($password, $user['password_hash'])) {
                $db->beginTransaction();
                
                $adminToken = bin2hex(random_bytes(64));
                $expiresAt = date('Y-m-d H:i:s', strtotime('+8 hours'));
                
                $stmt = $conn->prepare("INSERT INTO sessions (user_id, session_token, ip_address, user_agent, device_type, expires_at, is_active, created_at) VALUES (:uid, :token, :ip, :ua, :dev, :exp, 1, CURRENT_TIMESTAMP)");
                $stmt->execute([
                    ':uid' => $user['id'], ':token' => $adminToken,
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                    ':dev' => 'Admin Panel', ':exp' => $expiresAt
                ]);
                
                $stmt = $conn->prepare("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = :uid");
                $stmt->execute([':uid' => $user['id']]);
                
                $db->commit();
                
                $_SESSION['admin_id'] = $user['id'];
                $_SESSION['admin_token'] = $adminToken;
                $_SESSION['admin_username'] = $user['username'];
                $_SESSION['admin_logged_in'] = true;
                
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
            } else {
                $loginError = 'Invalid username or password';
            }
        } catch (Exception $e) {
            if (isset($db) && $db->inTransaction()) $db->rollback();
            $loginError = 'Database error occurred: ' . $e->getMessage();
        }
    } else {
        $loginError = 'Please enter username and password';
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    if (isset($_SESSION['admin_id'])) {
        try {
            $db = Database::getInstance();
            $conn = $db->getConnection();
            $stmt = $conn->prepare("UPDATE sessions SET is_active = 0 WHERE user_id = :uid AND session_token = :token");
            $stmt->execute([':uid' => $_SESSION['admin_id'], ':token' => $_SESSION['admin_token'] ?? '']);
        } catch (Exception $e) {}
    }
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Handle AJAX
if ($isAdminLoggedIn && isset($_GET['ajax'])) {
    handleAdminAjax();
    exit;
}

function handleAdminAjax() {
    $action = $_GET['action'] ?? '';
    $response = ['success' => false, 'message' => 'Invalid action'];
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        // Verify session
        $stmt = $conn->prepare("SELECT id FROM sessions WHERE user_id = :aid AND session_token = :token AND is_active = 1 AND expires_at > NOW()");
        $stmt->execute([':aid' => $_SESSION['admin_id'], ':token' => $_SESSION['admin_token']]);
        if (!$stmt->fetch()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Session expired', 'redirect' => true]);
            exit;
        }
        
        switch ($action) {
            case 'get_stats': $response = getAdminStats($conn); break;
            case 'get_users': $response = getUsersList($conn); break;
            case 'update_balance': $response = updateUserBalance($db, $conn); break;
            case 'get_transactions': $response = getUserTransactions($conn); break;
            case 'toggle_user': $response = toggleUserStatus($conn); break;
            case 'get_matches': $response = getMatchesList($conn); break;
            case 'get_kyc_stats': $response = getKycStats($conn); break;
            case 'get_withdrawal_stats': $response = getWithdrawalStats($conn); break;
            case 'get_dispute_stats': $response = getDisputeStats($conn); break;
            case 'get_financial_metrics': $response = getFinancialMetrics($conn); break;
            case 'get_tournaments': $response = getTournamentsList($conn); break;
        }
    } catch (Exception $e) {
        $response['message'] = 'Error: ' . $e->getMessage();
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

function getAdminStats($conn) {
    $stats = [];
    $stats['total_users'] = intval($conn->query("SELECT COUNT(*) FROM users WHERE is_admin = 0")->fetchColumn());
    $stats['active_users'] = intval($conn->query("SELECT COUNT(DISTINCT user_id) FROM transactions WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn());
    $stats['new_users_today'] = intval($conn->query("SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE() AND is_admin = 0")->fetchColumn());
    $stats['total_matches'] = intval($conn->query("SELECT COUNT(*) FROM matches")->fetchColumn());
    $stats['active_tournaments'] = intval($conn->query("SELECT COUNT(*) FROM matches WHERE status IN ('playing','ready')")->fetchColumn());
    $stats['matches_today'] = intval($conn->query("SELECT COUNT(*) FROM matches WHERE DATE(completed_at) = CURDATE() AND status = 'completed'")->fetchColumn());
    $stats['total_platform_revenue'] = floatval($conn->query("SELECT SUM(amount) FROM transactions WHERE source = 'deposit' AND description LIKE '%commission%' AND status = 'success'")->fetchColumn());
    $stats['net_platform_profit'] = floatval($conn->query("SELECT SUM(platform_fee) FROM matches WHERE status = 'completed'")->fetchColumn());
    $stats['today_revenue'] = floatval($conn->query("SELECT SUM(amount) FROM transactions WHERE source = 'deposit' AND description LIKE '%commission%' AND status = 'success' AND DATE(created_at) = CURDATE()")->fetchColumn());
    $stats['pending_withdrawals'] = intval($conn->query("SELECT COUNT(*) FROM withdrawals WHERE status = 'pending'")->fetchColumn());
    $stats['pending_kyc'] = intval($conn->query("SELECT COUNT(*) FROM kyc_documents WHERE status = 'pending'")->fetchColumn());
    $stats['open_disputes'] = intval($conn->query("SELECT COUNT(*) FROM dispute_tickets WHERE status IN ('open','investigating')")->fetchColumn());
    $stats['total_tds'] = floatval($conn->query("SELECT SUM(tds_amount) FROM tds_transactions")->fetchColumn());
    $stats['total_user_balance'] = floatval($conn->query("SELECT SUM(wallet_balance) FROM users WHERE is_admin = 0")->fetchColumn());
    $stats['total_withdrawn'] = floatval($conn->query("SELECT SUM(total_withdrawn) FROM users WHERE is_admin = 0")->fetchColumn());
    $stats['platform_liability'] = $stats['total_user_balance'] - $stats['total_withdrawn'];
    return ['success' => true, 'data' => $stats];
}

function getUsersList($conn) {
    $limit = intval($_GET['limit'] ?? 50);
    $offset = intval($_GET['offset'] ?? 0);
    $search = isset($_GET['search']) ? '%' . $_GET['search'] . '%' : '';
    
    $where = "is_admin = 0";
    $params = [];
    if (!empty($search)) {
        $where .= " AND (username LIKE :search OR mobile LIKE :search OR email LIKE :search)";
        $params[':search'] = $search;
    }
    
    $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE {$where}");
    $stmt->execute($params);
    $total = intval($stmt->fetchColumn());
    
    $stmt = $conn->prepare("SELECT id, username, mobile, email, wallet_balance, total_matches_played, total_matches_won, total_earnings, total_withdrawn, elo_rating, is_verified, kyc_status, is_active, created_at, last_login FROM users WHERE {$where} ORDER BY id DESC LIMIT :limit OFFSET :offset");
    $params[':limit'] = $limit;
    $params[':offset'] = $offset;
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return ['success' => true, 'data' => ['users' => $users ?: [], 'total' => $total, 'limit' => $limit, 'offset' => $offset]];
}

function updateUserBalance($db, $conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['user_id']) || !isset($input['amount'])) return ['success' => false, 'message' => 'Missing fields'];
    
    $userId = intval($input['user_id']);
    $amount = floatval($input['amount']);
    $type = $input['type'] ?? 'credit';
    $reason = $input['reason'] ?? 'Admin adjustment';
    
    try {
        $db->beginTransaction();
        $stmt = $conn->prepare("SELECT id, username, wallet_balance FROM users WHERE id = :uid FOR UPDATE");
        $stmt->execute([':uid' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) { $db->rollback(); return ['success' => false, 'message' => 'User not found']; }
        
        $currentBalance = floatval($user['wallet_balance']);
        $newBalance = $type === 'credit' ? $currentBalance + $amount : $currentBalance - $amount;
        if ($type === 'debit' && $newBalance < 0) { $db->rollback(); return ['success' => false, 'message' => 'Insufficient balance']; }
        
        $stmt = $conn->prepare("UPDATE users SET wallet_balance = :new_bal, updated_at = CURRENT_TIMESTAMP WHERE id = :uid");
        $stmt->execute([':new_bal' => $newBalance, ':uid' => $userId]);
        
        $orderId = 'ADMIN-' . strtoupper(uniqid());
        $stmt = $conn->prepare("INSERT INTO transactions (user_id, amount, type, source, description, order_id, status, balance_before, balance_after, metadata, created_at) VALUES (:uid, :amt, :type, :src, :desc, :oid, 'success', :bb, :ba, :meta, CURRENT_TIMESTAMP)");
        $stmt->execute([':uid' => $userId, ':amt' => $amount, ':type' => $type === 'credit' ? 'credit' : 'debit', ':src' => $type === 'credit' ? 'bonus' : 'withdrawal', ':desc' => "Admin {$type}: {$reason}", ':oid' => $orderId, ':bb' => $currentBalance, ':ba' => $newBalance, ':meta' => json_encode(['admin_action' => true, 'admin_id' => $_SESSION['admin_id'], 'reason' => $reason])]);
        
        $db->commit();
        return ['success' => true, 'data' => ['user_id' => $userId, 'username' => $user['username'], 'old_balance' => $currentBalance, 'new_balance' => $newBalance, 'amount' => $amount, 'type' => $type]];
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollback();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function getUserTransactions($conn) {
    $userId = intval($_GET['user_id'] ?? 0);
    $limit = intval($_GET['limit'] ?? 50);
    if ($userId <= 0) return ['success' => false, 'message' => 'Invalid user ID'];
    $stmt = $conn->prepare("SELECT id, amount, type, source, description, order_id, status, balance_before, balance_after, created_at, processed_at FROM transactions WHERE user_id = :uid ORDER BY created_at DESC LIMIT :limit");
    $stmt->execute([':uid' => $userId, ':limit' => $limit]);
    return ['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
}

function toggleUserStatus($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['user_id'])) return ['success' => false, 'message' => 'Missing user ID'];
    $userId = intval($input['user_id']);
    $stmt = $conn->prepare("UPDATE users SET is_active = NOT is_active, updated_at = CURRENT_TIMESTAMP WHERE id = :uid AND is_admin = 0");
    $stmt->execute([':uid' => $userId]);
    if ($stmt->rowCount() === 0) return ['success' => false, 'message' => 'User not found or is admin'];
    return ['success' => true, 'message' => 'User status updated'];
}

function getMatchesList($conn) {
    $status = $_GET['status'] ?? '';
    $limit = intval($_GET['limit'] ?? 50);
    $offset = intval($_GET['offset'] ?? 0);
    $where = "1=1";
    $params = [];
    if (!empty($status)) { $where .= " AND m.status = :status"; $params[':status'] = $status; }
    $stmt = $conn->prepare("SELECT COUNT(*) FROM matches m WHERE {$where}");
    $stmt->execute($params);
    $total = intval($stmt->fetchColumn());
    $stmt = $conn->prepare("SELECT m.id, m.room_code, m.entry_fee, m.prize_pool, m.platform_fee, m.status, m.player1_name, m.player2_name, m.winner_name, m.winning_amount, m.tds_deducted, m.turn_number, m.created_at, m.started_at, m.completed_at FROM matches m WHERE {$where} ORDER BY m.id DESC LIMIT :limit OFFSET :offset");
    $params[':limit'] = $limit;
    $params[':offset'] = $offset;
    $stmt->execute($params);
    return ['success' => true, 'data' => ['matches' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total, 'limit' => $limit, 'offset' => $offset]];
}

function getKycStats($conn) {
    $stats = [];
    foreach (['pending', 'verified', 'rejected'] as $s) { $stats[$s] = intval($conn->query("SELECT COUNT(*) FROM kyc_documents WHERE status = '{$s}'")->fetchColumn()); }
    $stats['total'] = array_sum($stats);
    return ['success' => true, 'data' => $stats];
}

function getWithdrawalStats($conn) {
    $stats = [];
    foreach (['pending', 'processing', 'approved', 'completed', 'rejected'] as $s) { $stats[$s] = intval($conn->query("SELECT COUNT(*) FROM withdrawals WHERE status = '{$s}'")->fetchColumn()); }
    $stats['total_pending_amount'] = floatval($conn->query("SELECT SUM(amount) FROM withdrawals WHERE status = 'pending'")->fetchColumn());
    $stats['total_processed_amount'] = floatval($conn->query("SELECT SUM(amount) FROM withdrawals WHERE status IN ('approved','completed')")->fetchColumn());
    $stats['total_amount'] = floatval($conn->query("SELECT SUM(amount) FROM withdrawals")->fetchColumn());
    $stats['today'] = intval($conn->query("SELECT COUNT(*) FROM withdrawals WHERE DATE(created_at) = CURDATE()")->fetchColumn());
    return ['success' => true, 'data' => $stats];
}

function getDisputeStats($conn) {
    $stats = [];
    foreach (['open', 'investigating', 'resolved', 'closed'] as $s) { $stats[$s] = intval($conn->query("SELECT COUNT(*) FROM dispute_tickets WHERE status = '{$s}'")->fetchColumn()); }
    $stats['total'] = array_sum($stats);
    $stats['high_priority'] = intval($conn->query("SELECT COUNT(*) FROM dispute_tickets WHERE priority IN ('high','urgent') AND status IN ('open','investigating')")->fetchColumn());
    $stats['total_refunds'] = floatval($conn->query("SELECT SUM(refund_amount) FROM dispute_tickets WHERE status = 'resolved' AND resolution_type = 'refund'")->fetchColumn());
    return ['success' => true, 'data' => $stats];
}

function getFinancialMetrics($conn) {
    $days = intval($_GET['days'] ?? 30);
    $stmt = $conn->prepare("SELECT metric_date, daily_deposits, daily_withdrawals, daily_platform_revenue, daily_matches_played, daily_new_users, total_platform_liability, total_user_balance, total_tds_deducted FROM financial_metrics WHERE metric_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY) ORDER BY metric_date ASC");
    $stmt->execute([':days' => $days]);
    $metrics = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totals = $conn->query("SELECT SUM(wallet_balance) as total_balance, SUM(total_earnings) as total_earnings, SUM(total_withdrawn) as total_withdrawn FROM users WHERE is_admin = 0")->fetch(PDO::FETCH_ASSOC);
    return ['success' => true, 'data' => ['history' => $metrics ?: [], 'totals' => ['total_user_balance' => floatval($totals['total_balance'] ?? 0), 'total_earnings' => floatval($totals['total_earnings'] ?? 0), 'total_withdrawn' => floatval($totals['total_withdrawn'] ?? 0)]]];
}

function getTournamentsList($conn) {
    $limit = intval($_GET['limit'] ?? 10);
    $status = $_GET['status'] ?? '';
    $where = "1=1";
    $params = [];
    if (!empty($status)) { $where .= " AND status = :status"; $params[':status'] = $status; }
    $stmt = $conn->prepare("SELECT t.*, u.username as created_by_name, (SELECT COUNT(*) FROM matches WHERE tournament_id = t.id) as match_count FROM tournaments t LEFT JOIN users u ON t.created_by = u.id WHERE {$where} ORDER BY CASE t.status WHEN 'active' THEN 1 WHEN 'in_progress' THEN 2 WHEN 'scheduled' THEN 3 ELSE 4 END, t.created_at DESC LIMIT :limit");
    $stmt->execute([':limit' => $limit]);
    return ['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Command Center - Ludo Pro</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Inter',-apple-system,sans-serif;background:#0a0e1a;color:#f1f5f9;min-height:100vh}
        .login-container{display:flex;justify-content:center;align-items:center;min-height:100vh;padding:20px}
        .login-box{background:#1a1a2e;padding:40px;border-radius:20px;max-width:400px;width:100%;border:1px solid rgba(255,255,255,0.06);box-shadow:0 20px 60px rgba(0,0,0,0.5)}
        .login-box h1{font-size:28px;font-weight:800;margin-bottom:8px;background:linear-gradient(135deg,#fbbf24,#f59e0b);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
        .login-box p{color:#94a3b8;margin-bottom:24px}
        .login-box .form-group{margin-bottom:16px}
        .login-box .form-group label{display:block;font-size:13px;font-weight:600;color:#94a3b8;margin-bottom:4px}
        .login-box .form-group input{width:100%;padding:12px 14px;border:1px solid rgba(255,255,255,0.08);border-radius:10px;background:rgba(255,255,255,0.04);color:#f1f5f9;font-size:14px;font-family:inherit}
        .login-box .form-group input:focus{outline:none;border-color:#7c3aed}
        .login-btn{width:100%;padding:14px;border:none;border-radius:10px;background:linear-gradient(135deg,#fbbf24,#f59e0b);color:#1a1a2e;font-weight:700;font-size:16px;cursor:pointer;font-family:inherit}
        .login-btn:hover{transform:scale(1.02);box-shadow:0 0 30px rgba(251,191,36,0.2)}
        .login-error{color:#ef4444;font-size:14px;margin-bottom:16px;padding:10px;background:rgba(239,68,68,0.1);border-radius:8px}
        .admin-container{max-width:1400px;margin:0 auto;padding:20px}
        .admin-header{display:flex;justify-content:space-between;align-items:center;padding:16px 0;border-bottom:1px solid rgba(255,255,255,0.06);margin-bottom:24px;flex-wrap:wrap;gap:12px}
        .admin-header h1{font-size:24px;font-weight:700;background:linear-gradient(135deg,#fbbf24,#f59e0b);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
        .admin-header-actions{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
        .admin-header-actions a{color:#94a3b8;text-decoration:none;font-weight:600;font-size:14px;padding:8px 16px;border:1px solid rgba(255,255,255,0.06);border-radius:8px;transition:background 0.2s}
        .admin-header-actions a:hover{background:rgba(255,255,255,0.04)}
        .admin-header-actions a.logout{color:#ef4444;border-color:rgba(239,68,68,0.2)}
        .quick-actions{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:24px}
        .quick-action{background:#1a1a2e;padding:16px;border-radius:12px;border:1px solid rgba(255,255,255,0.04);text-align:center;cursor:pointer;transition:all 0.2s;text-decoration:none;color:#f1f5f9}
        .quick-action:hover{border-color:rgba(124,58,237,0.3);transform:translateY(-2px)}
        .quick-action .icon{font-size:28px;display:block;margin-bottom:6px}
        .quick-action .count{font-size:18px;font-weight:700;display:block;margin-top:2px}
        .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:24px}
        .stat-card{background:#1a1a2e;padding:16px 20px;border-radius:12px;border:1px solid rgba(255,255,255,0.04);cursor:pointer;transition:border-color 0.2s}
        .stat-card:hover{border-color:rgba(251,191,36,0.15)}
        .stat-card .stat-label{font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px;font-weight:600}
        .stat-card .stat-value{font-size:24px;font-weight:800;margin-top:4px}
        .admin-tabs{display:flex;gap:4px;background:#1a1a2e;padding:4px;border-radius:12px;margin-bottom:24px;flex-wrap:wrap;border:1px solid rgba(255,255,255,0.04)}
        .admin-tab{padding:10px 20px;border:none;background:transparent;color:#94a3b8;font-weight:600;font-size:14px;cursor:pointer;border-radius:8px;font-family:inherit}
        .admin-tab.active{color:#f1f5f9;background:rgba(124,58,237,0.2)}
        .tab-content{display:none}.tab-content.active{display:block}
        .table-container{background:#1a1a2e;border-radius:14px;border:1px solid rgba(255,255,255,0.04);overflow-x:auto}
        table{width:100%;border-collapse:collapse;font-size:14px}
        table th{padding:12px 16px;text-align:left;color:#94a3b8;font-weight:600;font-size:12px;text-transform:uppercase}
        table td{padding:12px 16px;border-bottom:1px solid rgba(255,255,255,0.02)}
        .status-badge{display:inline-block;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:600}
        .status-badge.success{background:rgba(16,185,129,0.15);color:#10b981}
        .status-badge.pending{background:rgba(245,158,11,0.15);color:#f59e0b}
        .status-badge.failed{background:rgba(239,68,68,0.15);color:#ef4444}
        .btn-action{padding:4px 12px;border:none;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;margin:0 2px}
        .btn-action.primary{background:rgba(59,130,246,0.2);color:#3b82f6}
        .btn-action.danger{background:rgba(239,68,68,0.2);color:#ef4444}
        .modal-overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);backdrop-filter:blur(8px);z-index:1000;justify-content:center;align-items:center;padding:20px}
        .modal-overlay.active{display:flex}
        .modal-box{background:#1a1a2e;padding:32px;border-radius:16px;max-width:500px;width:100%;border:1px solid rgba(255,255,255,0.06)}
        .modal-box h2{font-size:20px;font-weight:700;margin-bottom:16px}
        .form-group{margin-bottom:14px}
        .form-group label{display:block;font-size:13px;font-weight:600;color:#94a3b8;margin-bottom:4px}
        .form-group input,.form-group select{width:100%;padding:10px 14px;border:1px solid rgba(255,255,255,0.06);border-radius:8px;background:rgba(255,255,255,0.04);color:#f1f5f9;font-size:14px;font-family:inherit}
        .modal-actions{display:flex;gap:12px;margin-top:20px}
        .modal-actions button{flex:1;padding:12px;border:none;border-radius:8px;font-weight:600;font-size:14px;cursor:pointer;font-family:inherit}
        .modal-actions .btn-confirm{background:linear-gradient(135deg,#fbbf24,#f59e0b);color:#1a1a2e}
        .modal-actions .btn-cancel{background:rgba(255,255,255,0.06);color:#94a3b8}
        .toast{position:fixed;bottom:24px;right:24px;padding:14px 24px;border-radius:12px;font-weight:600;font-size:14px;z-index:2000;transform:translateY(100px);opacity:0;transition:all 0.4s ease}
        .toast.show{transform:translateY(0);opacity:1}
        .toast.success{background:rgba(16,185,129,0.2);color:#10b981}
        .toast.error{background:rgba(239,68,68,0.2);color:#ef4444}
        @media(max-width:768px){.stats-grid{grid-template-columns:repeat(2,1fr)}.admin-header{flex-direction:column;align-items:flex-start}}
    </style>
</head>
<body>
    <?php if (!$isAdminLoggedIn): ?>
    <div class="login-container">
        <div class="login-box">
            <h1>🔐 Admin Access</h1>
            <p>Ludo Tournament Pro Command Center</p>
            <?php if (isset($loginError)): ?><div class="login-error"><?php echo htmlspecialchars($loginError); ?></div><?php endif; ?>
            <form method="POST">
                <div class="form-group"><label>Username</label><input type="text" name="username" required></div>
                <div class="form-group"><label>Password</label><input type="password" name="password" required></div>
                <button type="submit" name="admin_login" class="login-btn">Login</button>
            </form>
        </div>
    </div>
    <?php else: ?>
    <div class="admin-container">
        <div class="admin-header">
            <h1>⚡ Admin Command Center</h1>
            <div class="admin-header-actions">
                <span>👋 <?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?></span>
                <a href="settings.php">⚙️ Settings</a>
                <a href="kyc.php">🛡️ KYC</a>
                <a href="withdrawals.php">🏦 Withdrawals</a>
                <a href="disputes.php">📋 Disputes</a>
                <a href="tournaments.php">🏆 Tournaments</a>
                <a href="admin_users.php">👥 Users</a>
                <a href="?logout=1" class="logout">🚪 Logout</a>
            </div>
        </div>
        
        <div class="quick-actions" id="quickActions"></div>
        <div class="stats-grid" id="statsGrid"></div>
        
        <div class="admin-tabs">
            <button class="admin-tab active" data-tab="users">👥 Users</button>
            <button class="admin-tab" data-tab="matches">🎯 Matches</button>
            <button class="admin-tab" data-tab="transactions">💳 Transactions</button>
        </div>
        
        <div class="tab-content active" id="tab-users">
            <div class="table-container"><table><thead><tr><th>ID</th><th>Username</th><th>Mobile</th><th>Balance</th><th>Status</th><th>Actions</th></tr></thead><tbody id="usersBody"><tr><td colspan="6">Loading...</td></tr></tbody></table></div>
        </div>
        <div class="tab-content" id="tab-matches">
            <div class="table-container"><table><thead><tr><th>ID</th><th>Room</th><th>Entry</th><th>Prize</th><th>Status</th><th>Players</th></tr></thead><tbody id="matchesBody"><tr><td colspan="6">Loading...</td></tr></tbody></table></div>
        </div>
        <div class="tab-content" id="tab-transactions">
            <div class="table-container"><table><thead><tr><th>ID</th><th>User</th><th>Amount</th><th>Type</th><th>Source</th><th>Status</th></tr></thead><tbody id="transactionsBody"><tr><td colspan="6">Loading...</td></tr></tbody></table></div>
        </div>
    </div>
    
    <div class="modal-overlay" id="balanceModal"><div class="modal-box"><h2>💰 Adjust Balance</h2><input type="hidden" id="balUserId"><div class="form-group"><label>Amount</label><input type="number" id="balAmount" step="0.01"></div><div class="form-group"><label>Type</label><select id="balType"><option value="credit">Credit</option><option value="debit">Debit</option></select></div><div class="modal-actions"><button class="btn-confirm" onclick="submitBalance()">✅ Confirm</button><button class="btn-cancel" onclick="closeModal('balanceModal')">Cancel</button></div></div></div>
    <div class="toast" id="adminToast"></div>
    
    <script>
        let state = {usersPage:0,usersLimit:50,usersTotal:0};
        
        function handleApiResponse(r) {
            if(r.status===401){showToast('Session expired','error');setTimeout(()=>location.href='index.php',1500);throw new Error('Session expired')}
            return r.json();
        }
        
        function showToast(m,t='info'){const toast=document.getElementById('adminToast');toast.textContent=m;toast.className='toast '+t+' show';clearTimeout(toast._timeout);toast._timeout=setTimeout(()=>toast.classList.remove('show'),4000)}
        function closeModal(id){document.getElementById(id).classList.remove('active')}
        
        document.addEventListener('DOMContentLoaded',function(){
            loadStats();loadUsers();
            document.querySelectorAll('.admin-tab').forEach(tab=>{tab.addEventListener('click',function(){document.querySelectorAll('.admin-tab').forEach(t=>t.classList.remove('active'));this.classList.add('active');const tid=this.dataset.tab;document.querySelectorAll('.tab-content').forEach(c=>c.classList.remove('active'));document.getElementById('tab-'+tid).classList.add('active');if(tid==='users')loadUsers();else if(tid==='matches')loadMatches();else if(tid==='transactions')loadTransactions()})});
            document.querySelectorAll('.modal-overlay').forEach(m=>{m.addEventListener('click',function(e){if(e.target===this)this.classList.remove('active')})});
        });
        
        function loadStats(){
            fetch('?ajax=1&action=get_stats').then(handleApiResponse).then(d=>{if(d.success){const s=d.data;
                document.getElementById('statsGrid').innerHTML=`
                    <div class="stat-card"><div class="stat-label">Total Users</div><div class="stat-value" style="color:#3b82f6">${s.total_users||0}</div></div>
                    <div class="stat-card"><div class="stat-label">Active Users</div><div class="stat-value" style="color:#10b981">${s.active_users||0}</div></div>
                    <div class="stat-card"><div class="stat-label">New Today</div><div class="stat-value" style="color:#06b6d4">${s.new_users_today||0}</div></div>
                    <div class="stat-card"><div class="stat-label">Total Matches</div><div class="stat-value" style="color:#8b5cf6">${s.total_matches||0}</div></div>
                    <div class="stat-card"><div class="stat-label">Platform Revenue</div><div class="stat-value" style="color:#fbbf24">₹${(s.total_platform_revenue||0).toFixed(2)}</div></div>
                    <div class="stat-card"><div class="stat-label">Pending KYC</div><div class="stat-value" style="color:#f59e0b">${s.pending_kyc||0}</div></div>
                    <div class="stat-card"><div class="stat-label">Pending Withdrawals</div><div class="stat-value" style="color:#ef4444">${s.pending_withdrawals||0}</div></div>
                    <div class="stat-card"><div class="stat-label">Open Disputes</div><div class="stat-value" style="color:#ef4444">${s.open_disputes||0}</div></div>
                `;
                document.getElementById('quickActions').innerHTML=`
                    <a href="kyc.php" class="quick-action"><span class="icon">🛡️</span><span class="label">KYC Pending</span><span class="count">${s.pending_kyc||0}</span></a>
                    <a href="withdrawals.php" class="quick-action"><span class="icon">🏦</span><span class="label">Withdrawals</span><span class="count">${s.pending_withdrawals||0}</span></a>
                    <a href="disputes.php" class="quick-action"><span class="icon">📋</span><span class="label">Disputes</span><span class="count">${s.open_disputes||0}</span></a>
                `;
            }}).catch(()=>{});
        }
        
        function loadUsers(){
            document.getElementById('usersBody').innerHTML='<tr><td colspan="6">Loading...</td></tr>';
            fetch(`?ajax=1&action=get_users&offset=${state.usersPage*state.usersLimit}&limit=${state.usersLimit}`).then(handleApiResponse).then(d=>{
                if(d.success){state.usersTotal=d.data.total;document.getElementById('usersBody').innerHTML=(d.data.users||[]).map(u=>`<tr><td>#${u.id}</td><td>${u.username}</td><td>${u.mobile}</td><td style="color:#fbbf24">₹${parseFloat(u.wallet_balance).toFixed(2)}</td><td><span class="status-badge ${u.is_active?'success':'failed'}">${u.is_active?'Active':'Inactive'}</span></td><td><button class="btn-action primary" onclick="editBalance(${u.id},'${u.username}',${u.wallet_balance})">💰</button><button class="btn-action danger" onclick="toggleUser(${u.id})">🔒</button></td></tr>`).join('');}
            }).catch(()=>{document.getElementById('usersBody').innerHTML='<tr><td colspan="6">Error loading users</td></tr>'});
        }
        
        function loadMatches(){
            document.getElementById('matchesBody').innerHTML='<tr><td colspan="6">Loading...</td></tr>';
            fetch('?ajax=1&action=get_matches').then(handleApiResponse).then(d=>{if(d.success){document.getElementById('matchesBody').innerHTML=(d.data.matches||[]).map(m=>`<tr><td>#${m.id}</td><td>${m.room_code}</td><td>₹${parseFloat(m.entry_fee).toFixed(2)}</td><td>₹${parseFloat(m.prize_pool).toFixed(2)}</td><td><span class="status-badge ${m.status}">${m.status}</span></td><td>${m.player1_name} vs ${m.player2_name||'-'}</td></tr>`).join('');}}).catch(()=>{document.getElementById('matchesBody').innerHTML='<tr><td colspan="6">Error</td></tr>'});
        }
        
        function loadTransactions(){
            document.getElementById('transactionsBody').innerHTML='<tr><td colspan="6">Loading...</td></tr>';
            fetch('?ajax=1&action=get_transactions&user_id=0').then(handleApiResponse).then(d=>{if(d.success){document.getElementById('transactionsBody').innerHTML=(d.data||[]).map(t=>`<tr><td>#${t.id}</td><td>User #${t.user_id}</td><td style="color:${t.type==='credit'?'#10b981':'#ef4444'}">${t.type==='credit'?'+':'-'}₹${parseFloat(t.amount).toFixed(2)}</td><td>${t.type}</td><td>${t.source}</td><td><span class="status-badge ${t.status}">${t.status}</span></td></tr>`).join('');}}).catch(()=>{});
        }
        
        function editBalance(uid,uname,bal){document.getElementById('balUserId').value=uid;document.getElementById('balAmount').value='';document.getElementById('balanceModal').classList.add('active')}
        
        function submitBalance(){
            const uid=document.getElementById('balUserId').value;const amt=parseFloat(document.getElementById('balAmount').value);const type=document.getElementById('balType').value;
            if(!uid||!amt||amt<=0){showToast('Enter valid amount','error');return}
            fetch('?ajax=1&action=update_balance',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({user_id:parseInt(uid),amount:amt,type:type,reason:'Admin adjustment'})}).then(handleApiResponse).then(d=>{if(d.success){showToast('Balance updated!','success');closeModal('balanceModal');loadUsers()}else showToast(d.message||'Failed','error')}).catch(()=>showToast('Error','error'));
        }
        
        function toggleUser(uid){if(!confirm('Toggle user status?'))return;fetch('?ajax=1&action=toggle_user',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({user_id:uid})}).then(handleApiResponse).then(d=>{if(d.success){showToast('Status toggled','success');loadUsers()}else showToast(d.message||'Failed','error')}).catch(()=>showToast('Error','error'));}
    </script>
    <?php endif; ?>
</body>
</html>
