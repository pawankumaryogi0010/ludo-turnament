<?php
/**
 * ======================================================
 * INDEX.PHP - MAIN ENTRY POINT
 * Ludo Tournament Platform - Complete SPA
 * Version: 6.0.0 - COMPLETE FIXED WITH VALIDATION
 * ======================================================
 */

require_once __DIR__ . '/config/db.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$csrf_token = CSRFToken::generate();

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
    <meta name="mobile-web-app-capable" content="yes">
    <title>Ludo Tournament Pro - Skill-Based Gaming</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="<?php echo $basePath; ?>/assets/css/style.css">
    <link rel="manifest" href="<?php echo $basePath; ?>/manifest.json">

    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23fbbf24'%3E%3Cpath d='M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5'/%3E%3C/svg%3E">
</head>
<body>
    <div id="app-wrapper">
        <div id="app-container">

            <header id="app-header">
                <div class="header-left">
                    <div class="user-avatar" id="userAvatar">
                        <span class="avatar-text">G</span>
                        <span class="online-dot"></span>
                    </div>
                    <div class="user-info">
                        <span class="user-greeting">Welcome,</span>
                        <span class="user-name" id="displayUsername">Guest</span>
                    </div>
                </div>
                <div class="header-right">
                    <div class="wallet-box" id="walletBox">
                        <span class="wallet-label">Wallet</span>
                        <span class="wallet-balance" id="walletBalance">₹0.00</span>
                        <button class="wallet-add-btn" id="walletAddBtn" aria-label="Add Money">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="12" y1="5" x2="12" y2="19"/>
                                <line x1="5" y1="12" x2="19" y2="12"/>
                            </svg>
                        </button>
                    </div>
                </div>
            </header>

            <main id="app-main">

                <section id="page-dashboard" class="page active">
                    <div class="page-content">
                        <div class="hero-banner">
                            <div class="hero-content">
                                <h1 class="hero-title">Play & Win <span class="highlight">Real</span> Rewards</h1>
                                <p class="hero-subtitle">Skill-based Ludo tournaments. 100% legal.</p>
                            </div>
                            <div class="hero-badge">
                                <span class="badge-pulse">⚡</span>
                                <span id="onlineCount">1,247 Online</span>
                            </div>
                        </div>

                        <div class="quick-stats">
                            <div class="stat-item">
                                <span class="stat-value" id="todayWinnings">₹2.4K</span>
                                <span class="stat-label">Today's Winnings</span>
                            </div>
                            <div class="stat-divider"></div>
                            <div class="stat-item">
                                <span class="stat-value" id="activePlayers">847</span>
                                <span class="stat-label">Active Players</span>
                            </div>
                            <div class="stat-divider"></div>
                            <div class="stat-item">
                                <span class="stat-value">4.8★</span>
                                <span class="stat-label">Rating</span>
                            </div>
                        </div>

                        <div class="section-header">
                            <h2 class="section-title">Tournament Tickets</h2>
                            <button class="section-view-all" id="viewAllTournaments">View All</button>
                        </div>

                        <div class="tournament-grid" id="tournamentGrid">
                            <div class="tournament-card" data-entry="10" data-win="17" data-tournament-id="1">
                                <div class="card-glow"></div>
                                <div class="card-header">
                                    <span class="card-badge">POPULAR</span>
                                    <span class="card-level">Beginner</span>
                                </div>
                                <div class="card-body">
                                    <div class="card-prize">
                                        <span class="prize-amount">₹17</span>
                                        <span class="prize-label">Prize Pool</span>
                                    </div>
                                    <div class="card-entry">
                                        <span class="entry-amount">₹10</span>
                                        <span class="entry-label">Entry Fee</span>
                                    </div>
                                    <div class="card-players">
                                        <div class="player-avatars">
                                            <span class="avatar-mini"></span>
                                            <span class="avatar-mini"></span>
                                            <span class="avatar-mini"></span>
                                            <span class="avatar-mini empty">+</span>
                                        </div>
                                        <span class="players-count">2/4 Players</span>
                                    </div>
                                </div>
                                <button class="card-join-btn" data-entry="10" data-tournament-id="1">
                                    Join Now <span class="btn-arrow">→</span>
                                </button>
                            </div>

                            <div class="tournament-card featured" data-entry="20" data-win="34" data-tournament-id="2">
                                <div class="card-glow"></div>
                                <div class="card-header">
                                    <span class="card-badge featured-badge">🔥 HOT</span>
                                    <span class="card-level">Intermediate</span>
                                </div>
                                <div class="card-body">
                                    <div class="card-prize">
                                        <span class="prize-amount">₹34</span>
                                        <span class="prize-label">Prize Pool</span>
                                    </div>
                                    <div class="card-entry">
                                        <span class="entry-amount">₹20</span>
                                        <span class="entry-label">Entry Fee</span>
                                    </div>
                                    <div class="card-players">
                                        <div class="player-avatars">
                                            <span class="avatar-mini"></span>
                                            <span class="avatar-mini"></span>
                                            <span class="avatar-mini"></span>
                                            <span class="avatar-mini"></span>
                                        </div>
                                        <span class="players-count">4/4 Players</span>
                                    </div>
                                </div>
                                <button class="card-join-btn" data-entry="20" data-tournament-id="2">
                                    Join Now <span class="btn-arrow">→</span>
                                </button>
                            </div>

                            <div class="tournament-card" data-entry="50" data-win="85" data-tournament-id="3">
                                <div class="card-glow"></div>
                                <div class="card-header">
                                    <span class="card-badge">PREMIUM</span>
                                    <span class="card-level">Advanced</span>
                                </div>
                                <div class="card-body">
                                    <div class="card-prize">
                                        <span class="prize-amount">₹85</span>
                                        <span class="prize-label">Prize Pool</span>
                                    </div>
                                    <div class="card-entry">
                                        <span class="entry-amount">₹50</span>
                                        <span class="entry-label">Entry Fee</span>
                                    </div>
                                    <div class="card-players">
                                        <div class="player-avatars">
                                            <span class="avatar-mini"></span>
                                            <span class="avatar-mini"></span>
                                            <span class="avatar-mini empty">+</span>
                                            <span class="avatar-mini empty">+</span>
                                        </div>
                                        <span class="players-count">1/4 Players</span>
                                    </div>
                                </div>
                                <button class="card-join-btn" data-entry="50" data-tournament-id="3">
                                    Join Now <span class="btn-arrow">→</span>
                                </button>
                            </div>

                            <div class="tournament-card" data-entry="100" data-win="170" data-tournament-id="4">
                                <div class="card-glow"></div>
                                <div class="card-header">
                                    <span class="card-badge">ELITE</span>
                                    <span class="card-level">Pro</span>
                                </div>
                                <div class="card-body">
                                    <div class="card-prize">
                                        <span class="prize-amount">₹170</span>
                                        <span class="prize-label">Prize Pool</span>
                                    </div>
                                    <div class="card-entry">
                                        <span class="entry-amount">₹100</span>
                                        <span class="entry-label">Entry Fee</span>
                                    </div>
                                    <div class="card-players">
                                        <div class="player-avatars">
                                            <span class="avatar-mini"></span>
                                            <span class="avatar-mini empty">+</span>
                                            <span class="avatar-mini empty">+</span>
                                            <span class="avatar-mini empty">+</span>
                                        </div>
                                        <span class="players-count">0/4 Players</span>
                                    </div>
                                </div>
                                <button class="card-join-btn" data-entry="100" data-tournament-id="4">
                                    Join Now <span class="btn-arrow">→</span>
                                </button>
                            </div>
                        </div>

                        <div class="referral-section">
                            <div class="referral-content">
                                <div class="referral-icon">🎁</div>
                                <div class="referral-text">
                                    <h3>Invite Friends & Earn ₹50</h3>
                                    <p>Share your referral code and earn bonus when they play</p>
                                </div>
                                <button class="referral-btn" id="referralBtn">Invite</button>
                            </div>
                        </div>
                    </div>
                </section>

                <section id="page-wallet" class="page">
                    <div class="page-content">
                        <div class="wallet-header">
                            <h2 class="page-title">My Wallet</h2>
                            <span class="wallet-amount-large" id="walletLarge">₹0.00</span>
                            <span class="wallet-subtitle">Available Balance</span>
                        </div>

                        <div class="wallet-actions">
                            <button class="wallet-action-btn primary" id="addMoneyBtn">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M12 5v14M5 12h14"/>
                                </svg>
                                Add Money
                            </button>
                            <button class="wallet-action-btn secondary" id="withdrawBtn">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M19 12H5M12 19l-7-7 7-7"/>
                                </svg>
                                Withdraw
                            </button>
                        </div>

                        <div class="transaction-section">
                            <div class="section-header">
                                <h3 class="section-title">Recent Transactions</h3>
                                <button class="section-view-all">See All</button>
                            </div>
                            <div class="transaction-list" id="transactionList"></div>
                        </div>
                    </div>
                </section>

                <section id="page-refer" class="page">
                    <div class="page-content refer-page">
                        <div class="refer-hero">
                            <div class="refer-hero-icon">🎊</div>
                            <h2 class="refer-hero-title">Refer & Earn</h2>
                            <p class="refer-hero-sub">Invite your friends to play and earn ₹50 for each referral</p>
                        </div>

                        <div class="refer-code-box">
                            <span class="refer-code-label">Your Referral Code</span>
                            <div class="refer-code-display" id="referCodeDisplay">
                                <span id="referCodeText">REF123456</span>
                                <button class="copy-code-btn" id="copyCodeBtn">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="9" y="9" width="13" height="13" rx="2"/>
                                        <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                                    </svg>
                                    Copy
                                </button>
                            </div>
                        </div>

                        <div class="refer-stats">
                            <div class="refer-stat-item">
                                <span class="refer-stat-value" id="totalReferrals">0</span>
                                <span class="refer-stat-label">Total Referrals</span>
                            </div>
                            <div class="refer-stat-item">
                                <span class="refer-stat-value" id="referralBonus">₹0</span>
                                <span class="refer-stat-label">Bonus Earned</span>
                            </div>
                            <div class="refer-stat-item">
                                <span class="refer-stat-value" id="activeReferrals">0</span>
                                <span class="refer-stat-label">Active Referrals</span>
                            </div>
                        </div>

                        <div class="refer-steps">
                            <h3 class="steps-title">How It Works</h3>
                            <div class="step-item">
                                <span class="step-number">1</span>
                                <div class="step-content">
                                    <span class="step-title">Share Your Code</span>
                                    <p class="step-desc">Send your referral code to friends via WhatsApp, SMS, or social media</p>
                                </div>
                            </div>
                            <div class="step-item">
                                <span class="step-number">2</span>
                                <div class="step-content">
                                    <span class="step-title">Friend Signs Up</span>
                                    <p class="step-desc">Your friend registers using your referral code and deposits ₹10+</p>
                                </div>
                            </div>
                            <div class="step-item">
                                <span class="step-number">3</span>
                                <div class="step-content">
                                    <span class="step-title">Get Bonus</span>
                                    <p class="step-desc">You instantly receive ₹50 bonus in your wallet</p>
                                </div>
                            </div>
                        </div>

                        <button class="share-refer-btn" id="shareReferBtn">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="18" cy="5" r="3"/>
                                <circle cx="6" cy="12" r="3"/>
                                <circle cx="18" cy="19" r="3"/>
                                <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/>
                                <line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>
                            </svg>
                            Share Referral Link
                        </button>
                    </div>
                </section>

                <section id="page-history" class="page">
                    <div class="page-content">
                        <h2 class="page-title">Match History</h2>

                        <div class="history-filters">
                            <button class="filter-btn active" data-filter="all">All</button>
                            <button class="filter-btn" data-filter="won">Won</button>
                            <button class="filter-btn" data-filter="lost">Lost</button>
                            <button class="filter-btn" data-filter="pending">Pending</button>
                        </div>

                        <div class="history-list" id="historyList"></div>
                    </div>
                </section>

                <section id="page-profile" class="page">
                    <div class="page-content">
                        <div class="profile-header">
                            <div class="profile-avatar-large">
                                <span class="avatar-initials">G</span>
                            </div>
                            <h2 class="profile-name" id="profileName">Guest User</h2>
                            <span class="profile-id" id="profileId">ID: #GUEST001</span>
                            <div class="profile-rating">
                                <span class="rating-stars">★★★★★</span>
                                <span class="rating-score">4.8</span>
                            </div>
                        </div>

                        <div class="profile-stats">
                            <div class="profile-stat">
                                <span class="profile-stat-value" id="statMatches">0</span>
                                <span class="profile-stat-label">Matches</span>
                            </div>
                            <div class="profile-stat">
                                <span class="profile-stat-value" id="statWins">0</span>
                                <span class="profile-stat-label">Wins</span>
                            </div>
                            <div class="profile-stat">
                                <span class="profile-stat-value" id="statEarnings">₹0</span>
                                <span class="profile-stat-label">Earnings</span>
                            </div>
                            <div class="profile-stat">
                                <span class="profile-stat-value" id="statRating">1200</span>
                                <span class="profile-stat-label">ELO Rating</span>
                            </div>
                        </div>

                        <div class="profile-menu">
                            <div class="profile-menu-item" id="editProfileBtn">
                                <span class="menu-icon">👤</span>
                                <span class="menu-label">Edit Profile</span>
                                <span class="menu-arrow">›</span>
                            </div>
                            <div class="profile-menu-item" id="changePasswordBtn">
                                <span class="menu-icon">🔒</span>
                                <span class="menu-label">Change Password</span>
                                <span class="menu-arrow">›</span>
                            </div>
                            <div class="profile-menu-item" id="gameSettingsBtn">
                                <span class="menu-icon">⚙️</span>
                                <span class="menu-label">Game Settings</span>
                                <span class="menu-arrow">›</span>
                            </div>
                            <div class="profile-menu-item" id="responsibleGamingBtn">
                                <span class="menu-icon">🛡️</span>
                                <span class="menu-label">Responsible Gaming</span>
                                <span class="menu-arrow">›</span>
                            </div>
                            <div class="profile-menu-item" id="termsBtn">
                                <span class="menu-icon">📜</span>
                                <span class="menu-label">Terms & Conditions</span>
                                <span class="menu-arrow">›</span>
                            </div>
                            <div class="profile-menu-item" id="privacyBtn">
                                <span class="menu-icon">🔐</span>
                                <span class="menu-label">Privacy Policy</span>
                                <span class="menu-arrow">›</span>
                            </div>
                            <div class="profile-menu-item logout" id="logoutBtn">
                                <span class="menu-icon">🚪</span>
                                <span class="menu-label">Logout</span>
                                <span class="menu-arrow">›</span>
                            </div>
                        </div>

                        <div class="auth-section" id="authSection">
                            <button class="auth-btn login" id="loginBtn">Login</button>
                            <button class="auth-btn register" id="registerBtn">Register</button>
                        </div>
                    </div>
                </section>

            </main>

            <nav id="bottom-nav">
                <div class="nav-item active" data-page="dashboard">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-4 0a1 1 0 01-1-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 01-1 1"/>
                    </svg>
                    <span>Home</span>
                </div>
                <div class="nav-item" data-page="wallet">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 12V8H6a2 2 0 01-2-2c0-1.1.9-2 2-2h12v4"/>
                        <path d="M4 6v12c0 1.1.9 2 2 2h14v-4"/>
                        <path d="M18 12a2 2 0 100 4 2 2 0 000-4z"/>
                    </svg>
                    <span>Wallet</span>
                </div>
                <div class="nav-item nav-center" data-page="refer">
                    <div class="nav-center-btn">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
                            <polyline points="17 8 12 3 7 8"/>
                            <line x1="12" y1="3" x2="12" y2="15"/>
                        </svg>
                    </div>
                    <span>Refer</span>
                </div>
                <div class="nav-item" data-page="history">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                    <span>History</span>
                </div>
                <div class="nav-item" data-page="profile">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
                        <circle cx="12" cy="7" r="4"/>
                    </svg>
                    <span>Profile</span>
                </div>
            </nav>

            <div id="authModal" class="modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 id="authModalTitle">Login</h2>
                        <button class="modal-close" id="authModalClose">&times;</button>
                    </div>
                    <div class="modal-body">
                        <form id="loginForm" class="auth-form active">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <div class="form-group">
                                <label for="loginMobile">Mobile Number</label>
                                <input type="tel" id="loginMobile" name="mobile" placeholder="Enter 10-digit mobile number" required maxlength="10" pattern="[0-9]{10}">
                            </div>
                            <div class="form-group">
                                <label for="loginPassword">Password</label>
                                <input type="password" id="loginPassword" name="password" placeholder="Enter your password" required minlength="8">
                            </div>
                            <button type="submit" class="auth-submit-btn">Login</button>
                            <p class="auth-switch">Don't have an account? <a href="#" id="switchToRegister">Register</a></p>
                        </form>

                        <form id="registerForm" class="auth-form">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <div class="form-group">
                                <label for="regUsername">Username</label>
                                <input type="text" id="regUsername" name="username" placeholder="Choose a username" required minlength="3" maxlength="50">
                            </div>
                            <div class="form-group">
                                <label for="regMobile">Mobile Number</label>
                                <input type="tel" id="regMobile" name="mobile" placeholder="Enter 10-digit mobile number" required maxlength="10" pattern="[0-9]{10}">
                            </div>
                            <div class="form-group">
                                <label for="regPassword">Password</label>
                                <input type="password" id="regPassword" name="password" placeholder="Min 8 characters" required minlength="8">
                            </div>
                            <div class="form-group">
                                <label for="regReferral">Referral Code (Optional)</label>
                                <input type="text" id="regReferral" name="referral_code" placeholder="Enter referral code" maxlength="20">
                            </div>
                            <div class="form-group checkbox">
                                <input type="checkbox" id="regTerms" name="terms" required>
                                <label for="regTerms">I agree to the <a href="<?php echo $basePath; ?>/terms.php" target="_blank">Terms & Conditions</a> and <a href="<?php echo $basePath; ?>/privacy.php" target="_blank">Privacy Policy</a></label>
                            </div>
                            <button type="submit" class="auth-submit-btn">Register</button>
                            <p class="auth-switch">Already have an account? <a href="#" id="switchToLogin">Login</a></p>
                        </form>
                    </div>
                </div>
            </div>

            <div id="toast" class="toast hidden">
                <span id="toastMessage">Notification</span>
            </div>

        </div>
    </div>

    <!-- FIXED: Dynamic JS path -->
    <script src="<?php echo $basePath; ?>/assets/js/pwa-installer.js"></script>

    <script>
    class App {
        constructor() {
            this.currentPage = 'dashboard';
            this.isLoggedIn = false;
            this.walletBalance = 0;
            this.userData = null;
            this.basePath = '<?php echo $basePath; ?>';
            this.init();
        }

        init() {
            this.bindEvents();
            this.checkAuthStatus();
            this.showPage('dashboard');
        }

        bindEvents() {
            document.querySelectorAll('.nav-item').forEach(item => {
                item.addEventListener('click', () => {
                    const page = item.dataset.page;
                    this.showPage(page);
                    document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
                    item.classList.add('active');
                });
            });

            document.querySelectorAll('.card-join-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const entry = parseFloat(btn.dataset.entry);
                    const tournamentId = parseInt(btn.dataset.tournamentId) || 1;
                    this.handleJoinTournament(entry, tournamentId);
                });
            });

            document.getElementById('walletAddBtn')?.addEventListener('click', () => {
                this.showPage('wallet');
                document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
                document.querySelector('.nav-item[data-page="wallet"]')?.classList.add('active');
            });

            document.getElementById('referralBtn')?.addEventListener('click', () => {
                this.showPage('refer');
                document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
                document.querySelector('.nav-item[data-page="refer"]')?.classList.add('active');
            });

            document.getElementById('copyCodeBtn')?.addEventListener('click', () => {
                const code = document.getElementById('referCodeText')?.textContent || 'REF123456';
                navigator.clipboard?.writeText(code).then(() => {
                    this.showToast('Referral code copied!');
                }).catch(() => {
                    const input = document.createElement('input');
                    input.value = code;
                    document.body.appendChild(input);
                    input.select();
                    document.execCommand('copy');
                    document.body.removeChild(input);
                    this.showToast('Referral code copied!');
                });
            });

            document.getElementById('shareReferBtn')?.addEventListener('click', () => {
                const code = document.getElementById('referCodeText')?.textContent || 'REF123456';
                const text = `🎲 Join Ludo Tournament Pro! Use my referral code: ${code}`;
                if (navigator.share) {
                    navigator.share({ title: 'Ludo Tournament Pro', text: text, url: window.location.href });
                } else {
                    navigator.clipboard.writeText(text).then(() => {
                        this.showToast('Link copied to clipboard!');
                    });
                }
            });

            document.getElementById('loginBtn')?.addEventListener('click', () => this.openAuthModal('login'));
            document.getElementById('registerBtn')?.addEventListener('click', () => this.openAuthModal('register'));
            document.getElementById('authModalClose')?.addEventListener('click', () => this.closeAuthModal());

            document.getElementById('switchToRegister')?.addEventListener('click', (e) => {
                e.preventDefault();
                this.openAuthModal('register');
            });

            document.getElementById('switchToLogin')?.addEventListener('click', (e) => {
                e.preventDefault();
                this.openAuthModal('login');
            });

            document.getElementById('loginForm')?.addEventListener('submit', (e) => {
                e.preventDefault();
                this.handleLogin();
            });

            document.getElementById('registerForm')?.addEventListener('submit', (e) => {
                e.preventDefault();
                this.handleRegister();
            });

            document.getElementById('logoutBtn')?.addEventListener('click', () => this.handleLogout());

            document.getElementById('addMoneyBtn')?.addEventListener('click', () => {
                this.showToast('Add money feature coming soon');
            });

            document.getElementById('withdrawBtn')?.addEventListener('click', () => {
                this.showToast('Withdraw feature coming soon');
            });

            document.querySelectorAll('.filter-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    this.filterHistory(btn.dataset.filter);
                });
            });

            document.getElementById('authModal')?.addEventListener('click', (e) => {
                if (e.target === e.currentTarget) this.closeAuthModal();
            });

            document.getElementById('termsBtn')?.addEventListener('click', () => window.location.href = this.basePath + '/terms.php');
            document.getElementById('privacyBtn')?.addEventListener('click', () => window.location.href = this.basePath + '/privacy.php');
        }

        showPage(pageId) {
            document.querySelectorAll('.page').forEach(page => page.classList.remove('active'));
            const target = document.getElementById(`page-${pageId}`);
            if (target) {
                target.classList.add('active');
                this.currentPage = pageId;
            }
        }

        checkAuthStatus() {
            fetch(this.basePath + '/api/auth.php?action=check', { credentials: 'same-origin' })
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.data.logged_in) {
                        this.isLoggedIn = true;
                        this.userData = data.data.user;
                        this.updateAuthUI(true);
                        this.updateUserInfo(data.data.user);
                    } else {
                        this.isLoggedIn = false;
                        this.updateAuthUI(false);
                    }
                })
                .catch(() => {
                    this.isLoggedIn = false;
                    this.updateAuthUI(false);
                });
        }

        updateAuthUI(loggedIn) {
            const authSection = document.getElementById('authSection');
            const displayUsername = document.getElementById('displayUsername');
            const profileName = document.getElementById('profileName');
            const profileId = document.getElementById('profileId');

            if (loggedIn) {
                if (authSection) authSection.style.display = 'none';
                if (displayUsername) displayUsername.textContent = this.userData?.username || 'Player';
                if (profileName) profileName.textContent = this.userData?.username || 'Guest User';
                if (profileId) profileId.textContent = `ID: #${this.userData?.id || 'GUEST001'}`;
                document.querySelector('.avatar-text').textContent = (this.userData?.username || 'G')[0].toUpperCase();
            } else {
                if (authSection) authSection.style.display = 'flex';
                if (displayUsername) displayUsername.textContent = 'Guest';
                if (profileName) profileName.textContent = 'Guest User';
                if (profileId) profileId.textContent = 'ID: #GUEST001';
                document.querySelector('.avatar-text').textContent = 'G';
            }
        }

        updateUserInfo(user) {
            if (!user) return;

            if (user.wallet_balance !== undefined) {
                this.walletBalance = parseFloat(user.wallet_balance) || 0;
                this.updateWalletUI();
            }

            document.getElementById('statMatches').textContent = user.total_matches_played || 0;
            document.getElementById('statWins').textContent = user.total_matches_won || 0;
            document.getElementById('statEarnings').textContent = `₹${(user.total_earnings || 0).toFixed(2)}`;
            document.getElementById('statRating').textContent = user.elo_rating || 1200;

            if (user.refer_code) {
                document.getElementById('referCodeText').textContent = user.refer_code;
            }
            if (user.referral_earnings !== undefined) {
                document.getElementById('referralBonus').textContent = `₹${(user.referral_earnings || 0).toFixed(0)}`;
            }
        }

        updateWalletUI() {
            const walletBalance = document.getElementById('walletBalance');
            const walletLarge = document.getElementById('walletLarge');
            if (walletBalance) walletBalance.textContent = `₹${this.walletBalance.toFixed(2)}`;
            if (walletLarge) walletLarge.textContent = `₹${this.walletBalance.toFixed(2)}`;
            localStorage.setItem('walletBalance', this.walletBalance.toString());
        }

        openAuthModal(type) {
            const modal = document.getElementById('authModal');
            const title = document.getElementById('authModalTitle');
            const loginForm = document.getElementById('loginForm');
            const registerForm = document.getElementById('registerForm');

            if (type === 'login') {
                title.textContent = 'Login';
                loginForm.classList.add('active');
                registerForm.classList.remove('active');
            } else {
                title.textContent = 'Register';
                loginForm.classList.remove('active');
                registerForm.classList.add('active');
            }

            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        closeAuthModal() {
            const modal = document.getElementById('authModal');
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }

        // ==============================================
        // FIXED: handleLogin() with validation
        // ==============================================
        handleLogin() {
            const form = document.getElementById('loginForm');
            const formData = new FormData(form);

            const data = {
                mobile: formData.get('mobile'),
                password: formData.get('password'),
                csrf_token: document.querySelector('input[name="csrf_token"]')?.value || ''
            };

            // ✅ Client-side validation
            if (!data.mobile || data.mobile.length !== 10) {
                this.showToast('Please enter a valid 10-digit mobile number');
                return;
            }
            if (!data.password || data.password.length < 8) {
                this.showToast('Password must be at least 8 characters');
                return;
            }

            const btn = form.querySelector('.auth-submit-btn');
            const originalText = btn.textContent;
            btn.textContent = 'Logging in...';
            btn.disabled = true;

            fetch(this.basePath + '/api/auth.php?action=login', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': data.csrf_token
                },
                body: JSON.stringify(data)
            })
            .then(res => res.json())
            .then(result => {
                if (result.success) {
                    this.isLoggedIn = true;
                    this.userData = result.data.user;
                    this.updateAuthUI(true);
                    this.updateUserInfo(result.data.user);
                    this.closeAuthModal();
                    this.showToast('Login successful! Welcome back!');
                } else {
                    this.showToast(result.message || 'Login failed');
                }
            })
            .catch(() => {
                this.showToast('Network error. Please try again.');
            })
            .finally(() => {
                btn.textContent = originalText;
                btn.disabled = false;
            });
        }

        // ==============================================
        // FIXED: handleRegister() with validation
        // ==============================================
        handleRegister() {
            const form = document.getElementById('registerForm');
            const termsCheckbox = document.getElementById('regTerms');

            if (!termsCheckbox.checked) {
                this.showToast('Please accept Terms & Conditions');
                return;
            }

            const formData = new FormData(form);

            const data = {
                username: formData.get('username'),
                mobile: formData.get('mobile'),
                password: formData.get('password'),
                referral_code: formData.get('referral_code') || '',
                csrf_token: document.querySelector('input[name="csrf_token"]')?.value || ''
            };

            // ✅ Client-side validation
            if (!data.username || data.username.length < 3) {
                this.showToast('Username must be at least 3 characters');
                return;
            }
            if (!data.mobile || data.mobile.length !== 10) {
                this.showToast('Please enter a valid 10-digit mobile number');
                return;
            }
            if (!data.password || data.password.length < 8) {
                this.showToast('Password must be at least 8 characters');
                return;
            }
            // ✅ Password strength check
            if (!/[A-Z]/.test(data.password) || !/[a-z]/.test(data.password) || !/[0-9]/.test(data.password)) {
                this.showToast('Password must contain at least one uppercase, one lowercase, and one number');
                return;
            }

            const btn = form.querySelector('.auth-submit-btn');
            const originalText = btn.textContent;
            btn.textContent = 'Registering...';
            btn.disabled = true;

            fetch(this.basePath + '/api/auth.php?action=register', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': data.csrf_token
                },
                body: JSON.stringify(data)
            })
            .then(res => res.json())
            .then(result => {
                if (result.success) {
                    this.isLoggedIn = true;
                    this.userData = result.data.user;
                    this.updateAuthUI(true);
                    this.updateUserInfo(result.data.user);
                    this.closeAuthModal();
                    this.showToast('Registration successful! Welcome to Ludo Pro!');
                } else {
                    this.showToast(result.message || 'Registration failed');
                }
            })
            .catch(() => {
                this.showToast('Network error. Please try again.');
            })
            .finally(() => {
                btn.textContent = originalText;
                btn.disabled = false;
            });
        }

        // ==============================================
        // FIXED: handleLogout() with CSRF
        // ==============================================
        handleLogout() {
            if (!confirm('Are you sure you want to logout?')) return;

            const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';

            fetch(this.basePath + '/api/auth.php?action=logout', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({ csrf_token: csrfToken })
            })
            .then(res => res.json())
            .then(() => {
                this.isLoggedIn = false;
                this.userData = null;
                this.updateAuthUI(false);
                this.showToast('Logged out successfully');
                localStorage.removeItem('userData');
            })
            .catch(() => {
                this.showToast('Logout failed');
            });
        }

        // ==============================================
        // FIXED: handleJoinTournament() with CSRF
        // ==============================================
        handleJoinTournament(entryFee, tournamentId) {
            if (!this.isLoggedIn) {
                this.showToast('Please login to join tournaments');
                this.openAuthModal('login');
                return;
            }

            const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';

            if (this.walletBalance < entryFee) {
                this.showToast(`Insufficient balance. Need ₹${entryFee.toFixed(2)}`);
                this.showPage('wallet');
                return;
            }

            this.showToast('Joining tournament...');

            fetch(this.basePath + '/api/match.php?action=join', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    entry_fee: entryFee,
                    tournament_id: tournamentId,
                    csrf_token: csrfToken
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    if (data.data.balance_after !== undefined) {
                        this.walletBalance = data.data.balance_after;
                        this.updateWalletUI();
                    }
                    this.showToast(data.message || 'Successfully joined!');
                    if (data.data.redirect_url) {
                        setTimeout(() => window.location.href = data.data.redirect_url, 1500);
                    } else if (data.data.match_id) {
                        setTimeout(() => window.location.href = this.basePath + '/game.php?match_id=' + data.data.match_id, 1500);
                    }
                } else {
                    this.showToast(data.message || 'Failed to join tournament');
                }
            })
            .catch(() => {
                this.showToast('Network error. Please try again.');
            });
        }

        filterHistory(filter) {
            document.querySelectorAll('.history-item').forEach(item => {
                if (filter === 'all' || item.dataset.status === filter) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
        }

        showToast(message, duration = 3000) {
            const toast = document.getElementById('toast');
            const toastMessage = document.getElementById('toastMessage');

            toastMessage.textContent = message;
            toast.classList.remove('hidden');
            toast.classList.add('visible');

            if (this.toastTimeout) clearTimeout(this.toastTimeout);
            this.toastTimeout = setTimeout(() => {
                toast.classList.remove('visible');
                toast.classList.add('hidden');
            }, duration);
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        window.app = new App();
    });
    </script>
</body>
</html>
