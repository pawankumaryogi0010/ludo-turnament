<?php
/**
 * ======================================================
 * DASHBOARD.PHP - User Dashboard (ZUPPEE UI + FIXED)
 * Ludo Tournament Platform - Complete Dashboard
 * Version: 5.0.0 - ZUPPEE STYLE + ALL FIXES
 * ======================================================
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

require_once __DIR__ . '/config/db.php';

// Check login
if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$userId = getCurrentUserId();

if (!$userId || $userId <= 0) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// Initialize database
try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
} catch (Exception $e) {
    error_log('Dashboard DB error: ' . $e->getMessage());
    die("Database connection failed. Please try again.");
}

// Fetch user data
$user = null;
try {
    $stmt = $conn->prepare("
        SELECT id, username, mobile, email, wallet_balance,
               total_matches_played, total_matches_won, total_earnings,
               elo_rating, is_verified, kyc_status, is_active,
               refer_code, referral_earnings, created_at, last_login
        FROM users WHERE id = :user_id LIMIT 1
    ");
    $stmt->execute([':user_id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user || $user['is_active'] != 1) {
        session_destroy();
        header('Location: index.php');
        exit;
    }
} catch (Exception $e) {
    error_log('User fetch error: ' . $e->getMessage());
    die("Error loading profile.");
}

// Fetch active match
$activeMatch = null;
try {
    $stmt = $conn->prepare("
        SELECT id, room_code, entry_fee, prize_pool, status,
               player1_id, player2_id, player1_name, player2_name,
               current_turn_id, turn_number, created_at, updated_at
        FROM matches
        WHERE (player1_id = :uid OR player2_id = :uid)
        AND status IN ('waiting', 'ready', 'playing')
        ORDER BY created_at DESC LIMIT 1
    ");
    $stmt->execute([':uid' => $userId]);
    $activeMatch = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $activeMatch = null;
}

// Fetch recent matches
$recentMatches = [];
try {
    $stmt = $conn->prepare("
        SELECT id, room_code, entry_fee, prize_pool, status,
               player1_name, player2_name, winner_id, winner_name,
               winning_amount, created_at, completed_at,
               CASE WHEN winner_id = :uid THEN 'won' ELSE 'lost' END as result
        FROM matches
        WHERE (player1_id = :uid OR player2_id = :uid)
        AND status IN ('completed', 'cancelled')
        ORDER BY created_at DESC LIMIT 5
    ");
    $stmt->execute([':uid' => $userId]);
    $recentMatches = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $recentMatches = [];
}

// Fetch recent transactions
$recentTransactions = [];
try {
    $stmt = $conn->prepare("
        SELECT id, amount, type, source, description, status, created_at
        FROM transactions WHERE user_id = :uid
        ORDER BY created_at DESC LIMIT 5
    ");
    $stmt->execute([':uid' => $userId]);
    $recentTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $recentTransactions = [];
}

// CSRF token
if (!isset($_SESSION['csrf_token']) || empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
if ($basePath === '') $basePath = '';

$walletBalance = floatval($user['wallet_balance'] ?? 0);
$totalMatches = intval($user['total_matches_played'] ?? 0);
$totalWins = intval($user['total_matches_won'] ?? 0);
$totalEarnings = floatval($user['total_earnings'] ?? 0);
$eloRating = intval($user['elo_rating'] ?? 1200);
$username = htmlspecialchars($user['username'] ?? 'Player');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#5B2D8E">
    <title>Dashboard - Ludo Pro</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Poppins', sans-serif;
            background: #F5F0FF;
            color: #1A1A2E;
            min-height: 100vh;
        }
        .dashboard-wrapper {
            max-width: 480px;
            margin: 0 auto;
            padding: 0 16px 80px;
            min-height: 100vh;
        }
        
        /* Header */
        .dash-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 0;
            border-bottom: 1px solid #F0EBFF;
            margin-bottom: 16px;
        }
        .dash-header .user-row {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .dash-avatar {
            width: 44px; height: 44px;
            border-radius: 50%;
            background: linear-gradient(135deg, #5B2D8E, #8B5CF6);
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; font-weight: 700; color: white;
        }
        .dash-username { font-size: 16px; font-weight: 700; }
        .dash-id { font-size: 12px; color: #6B7280; }
        .btn-logout {
            padding: 8px 16px;
            background: #FEE2E2; color: #EF4444;
            border: none; border-radius: 20px;
            font-weight: 600; font-size: 13px;
            cursor: pointer; font-family: inherit;
            text-decoration: none;
        }
        
        /* Wallet Card */
        .wallet-card-dash {
            background: linear-gradient(135deg, #5B2D8E, #8B5CF6);
            border-radius: 16px; padding: 24px;
            text-align: center; color: white;
            margin-bottom: 16px;
        }
        .wallet-card-dash .w-label {
            font-size: 11px; opacity: 0.8;
            text-transform: uppercase; letter-spacing: 1px;
        }
        .wallet-card-dash .w-balance {
            font-size: 40px; font-weight: 900; margin: 8px 0;
        }
        .wallet-card-dash .w-actions {
            display: flex; gap: 10px; margin-top: 12px;
        }
        .w-actions .btn {
            flex: 1; padding: 10px;
            border: none; border-radius: 12px;
            font-weight: 700; font-size: 14px;
            cursor: pointer; font-family: inherit;
            text-decoration: none; text-align: center;
        }
        .btn-add { background: white; color: #5B2D8E; }
        .btn-with { background: rgba(255,255,255,0.2); color: white; }
        
        /* Stats Grid */
        .stats-grid-dash {
            display: grid; grid-template-columns: repeat(4, 1fr);
            gap: 8px; margin-bottom: 16px;
        }
        .stat-box {
            background: white; padding: 12px 8px;
            border-radius: 12px; text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .stat-box .s-val { font-size: 18px; font-weight: 800; display: block; }
        .stat-box .s-lbl { font-size: 10px; color: #6B7280; text-transform: uppercase; }
        .s-val.gold { color: #F59E0B; }
        .s-val.green { color: #00A859; }
        .s-val.blue { color: #3B82F6; }
        .s-val.purple { color: #5B2D8E; }
        
        /* Active Match */
        .active-match-card {
            background: white; border-radius: 12px;
            padding: 16px; margin-bottom: 16px;
            border-left: 4px solid #00A859;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .active-match-card .room { font-weight: 700; color: #5B2D8E; }
        .active-match-card .details {
            display: flex; gap: 12px; margin: 6px 0;
            font-size: 13px; color: #6B7280;
        }
        .btn-play {
            display: inline-block; padding: 8px 20px;
            background: #00A859; color: white;
            border: none; border-radius: 8px;
            font-weight: 700; font-size: 13px;
            text-decoration: none; font-family: inherit;
            cursor: pointer;
        }
        
        /* Section Headers */
        .section-head {
            display: flex; justify-content: space-between;
            align-items: center; margin-bottom: 10px;
        }
        .section-head h3 { font-size: 16px; font-weight: 700; }
        .section-head a { font-size: 12px; color: #5B2D8E; text-decoration: none; font-weight: 600; }
        
        /* Lists */
        .recent-list { display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px; }
        .recent-item {
            display: flex; justify-content: space-between; align-items: center;
            background: white; padding: 12px 14px; border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        .recent-item .title { font-size: 13px; font-weight: 600; }
        .recent-item .sub { font-size: 11px; color: #6B7280; }
        .recent-item .amt { font-size: 13px; font-weight: 700; }
        .amt.positive { color: #00A859; }
        .amt.negative { color: #EF4444; }
        
        .empty-state { text-align: center; padding: 30px; color: #9CA3AF; font-size: 14px; }
        
        /* Bottom Nav */
        .bottom-nav {
            max-width: 480px; margin: 0 auto;
            position: fixed; bottom: 0; left: 0; right: 0;
            background: white; border-top: 1px solid #F0EBFF;
            display: flex; justify-content: space-around;
            padding: 8px 4px 12px; z-index: 100;
            box-shadow: 0 -2px 12px rgba(0,0,0,0.05);
        }
        .nav-item {
            display: flex; flex-direction: column; align-items: center;
            gap: 2px; padding: 4px 12px;
            border: none; background: none;
            color: #6B7280; font-size: 10px; font-weight: 500;
            cursor: pointer; font-family: inherit;
            text-decoration: none;
        }
        .nav-item.active { color: #5B2D8E; font-weight: 700; }
        
        @media (max-width: 480px) {
            .stats-grid-dash { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <div class="dashboard-wrapper">

        <!-- Header -->
        <div class="dash-header">
            <div class="user-row">
                <div class="dash-avatar"><?php echo strtoupper(substr($username, 0, 1)); ?></div>
                <div>
                    <div class="dash-username"><?php echo $username; ?></div>
                    <div class="dash-id">ID: #<?php echo $userId; ?></div>
                </div>
            </div>
            <a href="?logout=1" class="btn-logout">🚪 Logout</a>
        </div>

        <!-- Wallet Card -->
        <div class="wallet-card-dash">
            <div class="w-label">Available Balance</div>
            <div class="w-balance">₹<?php echo number_format($walletBalance, 2); ?></div>
            <div class="w-actions">
                <a href="index.php#page-wallet" class="btn btn-add">+ Add Cash</a>
                <a href="index.php#page-wallet" class="btn btn-with">Withdraw</a>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats-grid-dash">
            <div class="stat-box"><span class="s-val blue"><?php echo $totalMatches; ?></span><span class="s-lbl">Matches</span></div>
            <div class="stat-box"><span class="s-val green"><?php echo $totalWins; ?></span><span class="s-lbl">Wins</span></div>
            <div class="stat-box"><span class="s-val gold">₹<?php echo number_format($totalEarnings, 0); ?></span><span class="s-lbl">Earnings</span></div>
            <div class="stat-box"><span class="s-val purple"><?php echo $eloRating; ?></span><span class="s-lbl">ELO</span></div>
        </div>

        <!-- Active Match -->
        <?php if ($activeMatch): ?>
        <div class="active-match-card">
            <div class="room">🔑 Room: <?php echo htmlspecialchars($activeMatch['room_code']); ?></div>
            <div class="details">
                <span>Entry: ₹<?php echo number_format($activeMatch['entry_fee'], 2); ?></span>
                <span>Prize: ₹<?php echo number_format($activeMatch['prize_pool'], 2); ?></span>
                <span><?php echo ucfirst($activeMatch['status']); ?></span>
            </div>
            <a href="game.php?match_id=<?php echo $activeMatch['id']; ?>" class="btn-play">🎲 Play Now</a>
        </div>
        <?php endif; ?>

        <!-- Recent Matches -->
        <div class="section-head"><h3>📋 Recent Matches</h3><a href="index.php#page-history">View All →</a></div>
        <div class="recent-list">
            <?php if (empty($recentMatches)): ?>
                <div class="empty-state">No matches played yet</div>
            <?php else: ?>
                <?php foreach ($recentMatches as $m): ?>
                <div class="recent-item">
                    <div>
                        <div class="title"><?php echo htmlspecialchars($m['player1_name']); ?> vs <?php echo htmlspecialchars($m['player2_name']); ?></div>
                        <div class="sub">₹<?php echo number_format($m['entry_fee'], 2); ?> • <?php echo date('d M', strtotime($m['created_at'])); ?></div>
                    </div>
                    <div class="amt <?php echo ($m['result'] === 'won') ? 'positive' : 'negative'; ?>">
                        <?php echo ($m['result'] === 'won') ? '🏆 Won' : '❌ Lost'; ?>
                        <?php if ($m['winning_amount'] > 0): ?>
                            <br><small>+₹<?php echo number_format($m['winning_amount'], 2); ?></small>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Recent Transactions -->
        <div class="section-head"><h3>💳 Transactions</h3><a href="index.php#page-wallet">View All →</a></div>
        <div class="recent-list">
            <?php if (empty($recentTransactions)): ?>
                <div class="empty-state">No transactions yet</div>
            <?php else: ?>
                <?php foreach ($recentTransactions as $tx): ?>
                <div class="recent-item">
                    <div>
                        <div class="title"><?php echo htmlspecialchars($tx['description']); ?></div>
                        <div class="sub"><?php echo date('d M, h:i A', strtotime($tx['created_at'])); ?></div>
                    </div>
                    <div class="amt <?php echo $tx['type'] === 'credit' ? 'positive' : 'negative'; ?>">
                        <?php echo $tx['type'] === 'credit' ? '+' : '-'; ?>₹<?php echo number_format($tx['amount'], 2); ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Bottom Nav -->
        <nav class="bottom-nav">
            <a href="dashboard.php" class="nav-item active">🏠<span>Home</span></a>
            <a href="index.php#page-wallet" class="nav-item">💳<span>Wallet</span></a>
            <a href="index.php#page-refer" class="nav-item">🎁<span>Refer</span></a>
            <a href="index.php#page-history" class="nav-item">📋<span>History</span></a>
            <a href="index.php#page-profile" class="nav-item">👤<span>Profile</span></a>
        </nav>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('logout')) {
            if (confirm('Are you sure you want to logout?')) {
                fetch('<?php echo $basePath; ?>/api/auth.php?action=logout', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': '<?php echo $csrf_token; ?>'
                    },
                    credentials: 'include'
                })
                .then(() => { window.location.href = 'index.php'; })
                .catch(() => { window.location.href = 'index.php'; });
            } else {
                window.history.back();
            }
        }
    });
    </script>
</body>
</html>
