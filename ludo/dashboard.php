<?php
/**
 * ======================================================
 * DASHBOARD.PHP - User Dashboard
 * Ludo Tournament Platform - Complete Dashboard
 * Version: 3.2.0 - PATHS FIXED
 * ======================================================
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

require_once __DIR__ . '/config/db.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$userId = getCurrentUserId();

$db = Database::getInstance();
$conn = $db->getConnection();

// ✅ FIXED: User query with proper error handling
try {
    $stmt = $conn->prepare("
        SELECT
            id, username, mobile, email, wallet_balance,
            total_matches_played, total_matches_won, total_earnings,
            elo_rating, is_verified, kyc_status, is_active,
            refer_code, referral_earnings, created_at
        FROM users
        WHERE id = ?
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

if (!$user || $user['is_active'] != 1) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// ✅ FIXED: Active match query with positional placeholders
try {
    $stmt = $conn->prepare("
        SELECT
            id, room_code, entry_fee, prize_pool, status,
            player1_name, player2_name, current_turn_id,
            turn_number, created_at
        FROM matches
        WHERE (player1_id = ? OR player2_id = ?)
        AND status IN ('waiting', 'ready', 'playing')
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$userId, $userId]);
    $activeMatch = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $activeMatch = null;
}

// ✅ FIXED: Recent matches query
try {
    $stmt = $conn->prepare("
        SELECT
            id, room_code, entry_fee, prize_pool, status,
            player1_name, player2_name, winner_name,
            winning_amount, created_at, completed_at,
            CASE WHEN winner_id = ? THEN 'won' ELSE 'lost' END as result
        FROM matches
        WHERE (player1_id = ? OR player2_id = ?)
        AND status IN ('completed', 'cancelled')
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$userId, $userId, $userId]);
    $recentMatches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recentMatches = [];
}

// ✅ FIXED: Transactions query
try {
    $stmt = $conn->prepare("
        SELECT
            id, amount, type, source, description,
            order_id, status, created_at
        FROM transactions
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    $recentTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recentTransactions = [];
}

// ✅ FIXED: CSRF token generation with fallback
if (class_exists('CSRFToken')) {
    $csrf_token = CSRFToken::generate();
} else {
    // Fallback CSRF token
    $csrf_token = bin2hex(random_bytes(32));
}

// Dynamic base path detection
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
if ($basePath === '') {
    $basePath = '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#0a0e1a">
    <title>Dashboard - Ludo Tournament Pro</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="<?php echo $basePath; ?>/assets/css/style.css">
    <style>
        .dashboard-wrapper {
            max-width: 480px;
            margin: 0 auto;
            padding: 0 16px 80px;
            background: #0a0e1a;
            min-height: 100vh;
        }

        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 0;
            border-bottom: 1px solid rgba(255,255,255,0.06);
            margin-bottom: 20px;
        }

        .dashboard-header .user-info { display: flex; align-items: center; gap: 12px; }

        .dashboard-header .avatar {
            width: 44px; height: 44px; border-radius: 50%;
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; font-weight: 700; color: #1a1a2e;
            text-transform: uppercase;
        }

        .dashboard-header .user-name { font-size: 16px; font-weight: 700; color: #f1f5f9; }
        .dashboard-header .user-id { font-size: 12px; color: #94a3b8; }

        .dashboard-header .logout-btn {
            padding: 6px 16px; border: 1px solid rgba(239,68,68,0.2);
            border-radius: 8px; background: transparent; color: #ef4444;
            font-size: 13px; font-weight: 600; cursor: pointer;
            transition: background 0.2s; font-family: inherit; text-decoration: none;
        }

        .dashboard-header .logout-btn:hover { background: rgba(239,68,68,0.1); }

        .wallet-card {
            background: linear-gradient(135deg, rgba(251,191,36,0.1), rgba(124,58,237,0.1));
            border: 1px solid rgba(251,191,36,0.15);
            border-radius: 16px; padding: 20px; text-align: center; margin-bottom: 20px;
        }

        .wallet-card .balance { font-size: 36px; font-weight: 900; color: #fbbf24; }
        .wallet-card .label { font-size: 12px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; }

        .wallet-card .actions { display: flex; gap: 12px; margin-top: 12px; justify-content: center; }

        .wallet-card .actions .btn {
            padding: 8px 24px; border: none; border-radius: 8px;
            font-weight: 600; font-size: 13px; cursor: pointer;
            transition: transform 0.2s; font-family: inherit; text-decoration: none;
        }

        .wallet-card .actions .btn-primary {
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            color: #1a1a2e;
        }

        .wallet-card .actions .btn-secondary {
            background: rgba(255,255,255,0.06);
            color: #f1f5f9; border: 1px solid rgba(255,255,255,0.08);
        }

        .wallet-card .actions .btn:hover { transform: scale(1.04); }

        .stats-grid {
            display: grid; grid-template-columns: repeat(4, 1fr);
            gap: 8px; margin-bottom: 20px;
        }

        .stats-grid .stat {
            background: #1a1a2e; padding: 12px 8px; border-radius: 12px;
            text-align: center; border: 1px solid rgba(255,255,255,0.04);
        }

        .stats-grid .stat .value { font-size: 18px; font-weight: 800; color: #f1f5f9; }
        .stats-grid .stat .label { font-size: 10px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.3px; }
        .stats-grid .stat .value.gold { color: #fbbf24; }
        .stats-grid .stat .value.green { color: #10b981; }
        .stats-grid .stat .value.blue { color: #3b82f6; }
        .stats-grid .stat .value.purple { color: #8b5cf6; }

        .section { margin-bottom: 20px; }

        .section-header {
            display: flex; justify-content: space-between;
            align-items: center; margin-bottom: 12px;
        }

        .section-header h3 { font-size: 16px; font-weight: 700; color: #f1f5f9; }
        .section-header a { font-size: 12px; color: #8b5cf6; text-decoration: none; font-weight: 600; }

        .active-match {
            background: #1a1a2e; border-radius: 12px; padding: 16px;
            border: 1px solid rgba(251,191,36,0.15);
            border-left: 4px solid #fbbf24;
        }

        .active-match .room-code { font-size: 14px; font-weight: 700; color: #fbbf24; }

        .active-match .details {
            display: flex; gap: 16px; margin-top: 8px;
            font-size: 13px; color: #94a3b8;
        }

        .active-match .btn-play {
            display: inline-block; margin-top: 10px; padding: 8px 20px;
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            color: #1a1a2e; border: none; border-radius: 8px;
            font-weight: 700; font-size: 13px; cursor: pointer;
            text-decoration: none; font-family: inherit;
        }

        .active-match .btn-play:hover { transform: scale(1.02); }

        .recent-list { display: flex; flex-direction: column; gap: 8px; }

        .recent-item {
            display: flex; justify-content: space-between; align-items: center;
            background: #1a1a2e; padding: 12px 14px; border-radius: 10px;
            border: 1px solid rgba(255,255,255,0.04);
        }

        .recent-item .left .title { font-size: 13px; font-weight: 600; color: #f1f5f9; }
        .recent-item .left .sub { font-size: 11px; color: #94a3b8; }
        .recent-item .right { font-size: 13px; font-weight: 700; }
        .recent-item .right.positive { color: #10b981; }
        .recent-item .right.negative { color: #ef4444; }
        .recent-item .right.pending { color: #f59e0b; }

        .status-badge-sm {
            font-size: 10px; padding: 2px 10px; border-radius: 12px; font-weight: 600;
        }

        .status-badge-sm.won { background: rgba(16,185,129,0.15); color: #10b981; }
        .status-badge-sm.lost { background: rgba(239,68,68,0.15); color: #ef4444; }
        .status-badge-sm.pending { background: rgba(245,158,11,0.15); color: #f59e0b; }

        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }

        #bottom-nav {
            max-width: 480px; margin: 0 auto;
            position: fixed; bottom: 0; left: 0; right: 0;
            background: rgba(10,14,26,0.95);
            backdrop-filter: blur(20px);
            border-top: 1px solid rgba(255,255,255,0.06);
            display: flex; justify-content: space-around;
            padding: 8px 4px 12px; z-index: 100;
        }

        .nav-item {
            display: flex; flex-direction: column; align-items: center;
            gap: 2px; padding: 4px 12px; border: none; background: none;
            color: #94a3b8; font-size: 10px; font-weight: 500;
            cursor: pointer; transition: all 0.3s ease;
            font-family: inherit; min-width: 48px;
            text-decoration: none;
        }

        .nav-item svg { width: 24px; height: 24px; stroke: currentColor; }

        .nav-item.active { color: #06b6d4; }

        .nav-item.active svg {
            stroke: #06b6d4;
            filter: drop-shadow(0 0 8px rgba(6,182,212,0.3));
        }

        .nav-center { position: relative; }

        .nav-center-btn {
            width: 52px; height: 52px; border-radius: 50%;
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            display: flex; align-items: center; justify-content: center;
            margin-top: -24px; box-shadow: 0 4px 20px rgba(251,191,36,0.3);
            transition: all 0.3s ease; cursor: pointer;
        }

        .nav-center-btn:hover { transform: scale(1.08); box-shadow: 0 6px 30px rgba(251,191,36,0.4); }
        .nav-center-btn svg { stroke: #1a1a2e; width: 28px; height: 28px; }
    </style>
</head>
<body>
    <div class="dashboard-wrapper">

        <div class="dashboard-header">
            <div class="user-info">
                <div class="avatar"><?php echo strtoupper(substr($user['username'], 0, 1)); ?></div>
                <div>
                    <div class="user-name"><?php echo htmlspecialchars($user['username']); ?></div>
                    <div class="user-id">ID: #<?php echo $user['id']; ?></div>
                </div>
            </div>
            <a href="index.php" class="logout-btn">🚪 Logout</a>
        </div>

        <div class="wallet-card">
            <div class="label">Available Balance</div>
            <div class="balance">₹<?php echo number_format($user['wallet_balance'], 2); ?></div>
            <div class="actions">
                <a href="index.php#page-wallet" class="btn btn-primary">💰 Add Money</a>
                <a href="index.php#page-wallet" class="btn btn-secondary">🏦 Withdraw</a>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat">
                <div class="value blue"><?php echo $user['total_matches_played'] ?? 0; ?></div>
                <div class="label">Matches</div>
            </div>
            <div class="stat">
                <div class="value green"><?php echo $user['total_matches_won'] ?? 0; ?></div>
                <div class="label">Wins</div>
            </div>
            <div class="stat">
                <div class="value gold">₹<?php echo number_format($user['total_earnings'] ?? 0, 0); ?></div>
                <div class="label">Earnings</div>
            </div>
            <div class="stat">
                <div class="value purple"><?php echo $user['elo_rating'] ?? 1200; ?></div>
                <div class="label">ELO</div>
            </div>
        </div>

        <?php if ($activeMatch): ?>
        <div class="section">
            <div class="section-header"><h3>🎯 Active Match</h3></div>
            <div class="active-match">
                <div class="room-code">🔑 Room: <?php echo htmlspecialchars($activeMatch['room_code']); ?></div>
                <div class="details">
                    <span>Entry: ₹<?php echo number_format($activeMatch['entry_fee'], 2); ?></span>
                    <span>Prize: ₹<?php echo number_format($activeMatch['prize_pool'], 2); ?></span>
                    <span>Status: <?php echo ucfirst($activeMatch['status']); ?></span>
                </div>
                <a href="game.php?match_id=<?php echo $activeMatch['id']; ?>" class="btn-play">🎲 Play Now</a>
            </div>
        </div>
        <?php endif; ?>

        <div class="section">
            <div class="section-header">
                <h3>📋 Recent Matches</h3>
                <a href="index.php#page-history">View All →</a>
            </div>
            <div class="recent-list">
                <?php if (empty($recentMatches)): ?>
                    <div style="color: #94a3b8; text-align: center; padding: 20px;">No matches played yet.</div>
                <?php else: ?>
                    <?php foreach ($recentMatches as $match): ?>
                        <div class="recent-item">
                            <div class="left">
                                <div class="title"><?php echo htmlspecialchars($match['player1_name']); ?> vs <?php echo htmlspecialchars($match['player2_name']); ?></div>
                                <div class="sub">₹<?php echo number_format($match['entry_fee'], 2); ?> entry • <?php echo date('d M Y', strtotime($match['created_at'])); ?></div>
                            </div>
                            <div class="right">
                                <?php if ($match['status'] === 'completed'): ?>
                                    <span class="status-badge-sm <?php echo $match['result']; ?>">
                                        <?php echo $match['result'] === 'won' ? '🏆 Won' : 'Lost'; ?>
                                    </span>
                                    <?php if ($match['winning_amount'] > 0): ?>
                                        <div style="font-size: 12px; color: #10b981;">+₹<?php echo number_format($match['winning_amount'], 2); ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="status-badge-sm pending">⏳ Pending</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="section">
            <div class="section-header">
                <h3>💳 Recent Transactions</h3>
                <a href="index.php#page-wallet">View All →</a>
            </div>
            <div class="recent-list">
                <?php if (empty($recentTransactions)): ?>
                    <div style="color: #94a3b8; text-align: center; padding: 20px;">No transactions yet.</div>
                <?php else: ?>
                    <?php foreach ($recentTransactions as $tx): ?>
                        <div class="recent-item">
                            <div class="left">
                                <div class="title"><?php echo htmlspecialchars($tx['description']); ?></div>
                                <div class="sub"><?php echo date('d M Y, h:i A', strtotime($tx['created_at'])); ?></div>
                            </div>
                            <div class="right <?php echo $tx['type'] === 'credit' ? 'positive' : 'negative'; ?>">
                                <?php echo $tx['type'] === 'credit' ? '+' : '-'; ?>₹<?php echo number_format($tx['amount'], 2); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <nav id="bottom-nav">
            <a href="dashboard.php" class="nav-item active">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-4 0a1 1 0 01-1-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 01-1 1"/>
                </svg>
                <span>Home</span>
            </a>
            <a href="index.php#page-wallet" class="nav-item">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M20 12V8H6a2 2 0 01-2-2c0-1.1.9-2 2-2h12v4"/>
                    <path d="M4 6v12c0 1.1.9 2 2 2h14v-4"/>
                    <path d="M18 12a2 2 0 100 4 2 2 0 000-4z"/>
                </svg>
                <span>Wallet</span>
            </a>
            <a href="index.php#page-refer" class="nav-item nav-center">
                <div class="nav-center-btn">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
                        <polyline points="17 8 12 3 7 8"/>
                        <line x1="12" y1="3" x2="12" y2="15"/>
                    </svg>
                </div>
                <span>Refer</span>
            </a>
            <a href="index.php#page-history" class="nav-item">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <polyline points="12 6 12 12 16 14"/>
                </svg>
                <span>History</span>
            </a>
            <a href="index.php#page-profile" class="nav-item">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
                    <circle cx="12" cy="7" r="4"/>
                </svg>
                <span>Profile</span>
            </a>
        </nav>

    </div>
</body>
</html>
