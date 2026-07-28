<?php
/**
 * ======================================================
 * INDEX.PHP - MAIN ENTRY POINT (ZUPPEE LUDO UI CLONE)
 * Ludo Tournament Platform - Complete SPA
 * Version: 10.0.2 - BANNER FIX + 0 BUGS
 * ======================================================
 */

// Initialize session first
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/db.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token']) || empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['csrf_token_time'] = time();
}
$csrf_token = $_SESSION['csrf_token'];

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
    <meta name="theme-color" content="#5B2D8E">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Ludo Pro - Play & Win Real Cash</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="<?php echo htmlspecialchars($basePath); ?>/assets/css/zupee-style.css">
    <link rel="manifest" href="<?php echo htmlspecialchars($basePath); ?>/manifest.json">

    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%235B2D8E'%3E%3Cpath d='M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5'/%3E%3C/svg%3E">
</head>
<body>
    <div id="app-wrapper">
        
        <!-- HEADER -->
        <header class="zupee-header">
            <div class="header-left">
                <div class="logo-icon">🎲</div>
                <div class="logo-text">
                    <span class="logo-title">Ludo Pro</span>
                    <span class="logo-subtitle">Skill Gaming</span>
                </div>
            </div>
            <div class="header-right">
                <button class="btn-wallet-badge" id="headerWalletBtn">
                    <span class="wallet-icon">💰</span>
                    <span class="wallet-amount" id="headerBalance">₹0</span>
                    <span class="add-icon">+</span>
                </button>
                <button class="btn-login-sm" id="headerLoginBtn">Login</button>
            </div>
        </header>

        <!-- MAIN CONTENT -->
        <main class="main-content" id="appMain">
            
            <!-- ==========================================
            DASHBOARD / HOME PAGE
            ========================================== -->
            <section id="page-dashboard" class="page active">
                
                <!-- BANNER CAROUSEL - सिर्फ Dashboard में -->
                <div class="banner-carousel" id="bannerCarousel">
                    <div class="banner-track" id="bannerTrack">
                        <!-- Banner 1: Welcome Bonus -->
                        <div class="banner-slide">
                            <div class="banner-card banner-1">
                                <div class="banner-content">
                                    <h2 class="banner-title">🎉 ₹100 Welcome Bonus</h2>
                                    <p class="banner-desc">Sign up & get instant bonus!</p>
                                    <button class="banner-btn" onclick="window.app.openAuthModal('register')">Claim Now →</button>
                                </div>
                                <div class="banner-image">
                                    <img src="<?php echo htmlspecialchars($basePath); ?>/assets/images/banner-welcome.svg" alt="Welcome Bonus" onerror="this.style.display='none'" style="width:100px;height:100px;">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Banner 2: Referral -->
                        <div class="banner-slide">
                            <div class="banner-card banner-2">
                                <div class="banner-content">
                                    <h2 class="banner-title">👥 Refer & Earn ₹50</h2>
                                    <p class="banner-desc">Per friend who joins & plays!</p>
                                    <button class="banner-btn" onclick="window.app.navigateTo('refer')">Invite Friends →</button>
                                </div>
                                <div class="banner-image">
                                    <img src="<?php echo htmlspecialchars($basePath); ?>/assets/images/banner-refer.svg" alt="Refer & Earn" onerror="this.style.display='none'" style="width:100px;height:100px;">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Banner 3: Tournament -->
                        <div class="banner-slide">
                            <div class="banner-card banner-3">
                                <div class="banner-content">
                                    <h2 class="banner-title">🏆 Mega Tournament</h2>
                                    <p class="banner-desc">Win up to ₹10,000 daily!</p>
                                    <button class="banner-btn" onclick="window.app.openAuthModal('login')">Play Now →</button>
                                </div>
                                <div class="banner-image">
                                    <img src="<?php echo htmlspecialchars($basePath); ?>/assets/images/banner-tournament.svg" alt="Tournament" onerror="this.style.display='none'" style="width:100px;height:100px;">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Banner 4: Safe & Secure -->
                        <div class="banner-slide">
                            <div class="banner-card banner-4">
                                <div class="banner-content">
                                    <h2 class="banner-title">🔒 100% Safe & Legal</h2>
                                    <p class="banner-desc">Skill-based gaming platform</p>
                                    <button class="banner-btn" onclick="window.app.openAuthModal('register')">Get Started →</button>
                                </div>
                                <div class="banner-image">
                                    <img src="<?php echo htmlspecialchars($basePath); ?>/assets/images/banner-secure.svg" alt="Secure" onerror="this.style.display='none'" style="width:100px;height:100px;">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Banner Dots -->
                    <div class="banner-dots" id="bannerDots">
                        <span class="dot active" data-index="0"></span>
                        <span class="dot" data-index="1"></span>
                        <span class="dot" data-index="2"></span>
                        <span class="dot" data-index="3"></span>
                    </div>
                </div>

                <!-- Quick Stats Row -->
                <div class="quick-stats-row">
                    <div class="stat-item"><span class="stat-icon">👥</span><span class="stat-value" id="onlineCount">2,847</span><span class="stat-label">Online</span></div>
                    <div class="stat-item"><span class="stat-icon">🏆</span><span class="stat-value">₹2.4L</span><span class="stat-label">Won Today</span></div>
                    <div class="stat-item"><span class="stat-icon">⭐</span><span class="stat-value">4.8</span><span class="stat-label">Rating</span></div>
                </div>

                <!-- Quick Play Section -->
                <div class="section-container">
                    <div class="section-header"><h3 class="section-title">🎮 Quick Play</h3></div>
                    <div class="quick-play-grid">
                        <div class="quick-play-card" onclick="window.app.handleQuickPlay(10, 1)">
                            <div class="qp-icon">🎲</div><div class="qp-info"><span class="qp-name">Beginner</span><span class="qp-entry">Entry ₹10</span></div><div class="qp-prize">Win ₹17</div>
                        </div>
                        <div class="quick-play-card featured" onclick="window.app.handleQuickPlay(20, 2)">
                            <div class="qp-icon">🔥</div><div class="qp-info"><span class="qp-name">Popular</span><span class="qp-entry">Entry ₹20</span></div><div class="qp-prize">Win ₹34</div>
                        </div>
                        <div class="quick-play-card" onclick="window.app.handleQuickPlay(50, 3)">
                            <div class="qp-icon">💎</div><div class="qp-info"><span class="qp-name">Premium</span><span class="qp-entry">Entry ₹50</span></div><div class="qp-prize">Win ₹85</div>
                        </div>
                        <div class="quick-play-card premium" onclick="window.app.handleQuickPlay(100, 4)">
                            <div class="qp-icon">👑</div><div class="qp-info"><span class="qp-name">Pro</span><span class="qp-entry">Entry ₹100</span></div><div class="qp-prize">Win ₹170</div>
                        </div>
                    </div>
                </div>

                <!-- Tournament Tickets Section -->
                <div class="section-container">
                    <div class="section-header"><h3 class="section-title">🎟️ Tournament Tickets</h3></div>
                    <div class="tournament-grid-zupee">
                        <div class="tournament-card-zupee" onclick="window.app.handleJoinTournament(10, 1)">
                            <div class="tcz-header"><span class="tcz-badge badge-green">Entry ₹10</span><span class="tcz-players">2/4</span></div>
                            <div class="tcz-body"><div class="tcz-prize-row"><span class="tcz-prize-label">Prize Pool</span><span class="tcz-prize-amount">₹17</span></div><div class="tcz-progress"><div class="tcz-progress-bar" style="width:50%"></div></div></div>
                        </div>
                        <div class="tournament-card-zupee featured-card" onclick="window.app.handleJoinTournament(20, 2)">
                            <div class="tcz-header"><span class="tcz-badge badge-orange">Entry ₹20</span><span class="tcz-players">3/4</span></div>
                            <div class="tcz-body"><div class="tcz-prize-row"><span class="tcz-prize-label">Prize Pool</span><span class="tcz-prize-amount">₹34</span></div><div class="tcz-progress"><div class="tcz-progress-bar" style="width:75%"></div></div></div>
                        </div>
                        <div class="tournament-card-zupee" onclick="window.app.handleJoinTournament(50, 3)">
                            <div class="tcz-header"><span class="tcz-badge badge-purple">Entry ₹50</span><span class="tcz-players">1/4</span></div>
                            <div class="tcz-body"><div class="tcz-prize-row"><span class="tcz-prize-label">Prize Pool</span><span class="tcz-prize-amount">₹85</span></div><div class="tcz-progress"><div class="tcz-progress-bar" style="width:25%"></div></div></div>
                        </div>
                        <div class="tournament-card-zupee premium-card" onclick="window.app.handleJoinTournament(100, 4)">
                            <div class="tcz-header"><span class="tcz-badge badge-gold">Entry ₹100</span><span class="tcz-players">1/4</span></div>
                            <div class="tcz-body"><div class="tcz-prize-row"><span class="tcz-prize-label">Prize Pool</span><span class="tcz-prize-amount">₹170</span></div><div class="tcz-progress"><div class="tcz-progress-bar" style="width:25%"></div></div></div>
                        </div>
                    </div>
                </div>

                <!-- How to Play Section -->
                <div class="section-container">
                    <div class="section-header"><h3 class="section-title">📖 How to Play</h3></div>
                    <div class="how-to-play">
                        <div class="htp-step"><div class="htp-number">1</div><div class="htp-text"><strong>Sign Up</strong><p>Create account in seconds</p></div></div>
                        <div class="htp-step"><div class="htp-number">2</div><div class="htp-text"><strong>Add Cash</strong><p>Deposit via UPI or cards</p></div></div>
                        <div class="htp-step"><div class="htp-number">3</div><div class="htp-text"><strong>Play & Win</strong><p>Beat opponents & withdraw</p></div></div>
                    </div>
                </div>
            </section>

            <!-- ==========================================
            WALLET PAGE
            ========================================== -->
            <section id="page-wallet" class="page">
                <div class="wallet-page-container">
                    <div class="wallet-balance-card">
                        <span class="wbc-label">Available Balance</span>
                        <span class="wbc-amount" id="walletLarge">₹0.00</span>
                        <span class="wbc-sub">+ ₹50 bonus on first deposit</span>
                        <div class="wbc-actions">
                            <button class="btn-add-cash" id="addMoneyBtn">+ Add Cash</button>
                            <button class="btn-withdraw" id="withdrawBtn">Withdraw</button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ==========================================
            REFER PAGE
            ========================================== -->
            <section id="page-refer" class="page">
                <div class="refer-container">
                    <div class="refer-hero-card">
                        <span class="refer-icon">🎁</span>
                        <h2>Refer & Earn ₹50</h2>
                        <p>Share your code with friends</p>
                        <div class="refer-code-box">
                            <span id="referCodeText">REF123456</span>
                            <button class="btn-copy" id="copyCodeBtn">Copy</button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ==========================================
            HISTORY PAGE
            ========================================== -->
            <section id="page-history" class="page">
                <div class="history-container">
                    <div class="history-filters-zupee">
                        <button class="filter-btn-zupee active" data-filter="all">All</button>
                        <button class="filter-btn-zupee" data-filter="won">Won</button>
                        <button class="filter-btn-zupee" data-filter="lost">Lost</button>
                    </div>
                    <div class="history-list-zupee" id="historyList">
                        <div class="history-empty">No matches played yet</div>
                    </div>
                </div>
            </section>

            <!-- ==========================================
            PROFILE PAGE
            ========================================== -->
            <section id="page-profile" class="page">
                <div class="profile-container">
                    <div class="profile-header-card">
                        <div class="profile-avatar-zupee">G</div>
                        <h3 id="profileName">Guest User</h3>
                        <span id="profileId">ID: #GUEST001</span>
                    </div>
                    <div class="profile-stats-zupee">
                        <div class="ps-item"><span id="statMatches">0</span><label>Matches</label></div>
                        <div class="ps-item"><span id="statWins">0</span><label>Wins</label></div>
                        <div class="ps-item"><span id="statEarnings">₹0</span><label>Earnings</label></div>
                        <div class="ps-item"><span id="statRating">1200</span><label>ELO</label></div>
                    </div>
                    <div class="profile-menu-zupee">
                        <button class="pm-item" id="loginBtnProfile">🔑 Login</button>
                        <button class="pm-item" id="registerBtnProfile">📝 Register</button>
                        <button class="pm-item" id="logoutBtnProfile" style="display:none;">🚪 Logout</button>
                    </div>
                </div>
            </section>

        </main>

        <!-- BOTTOM NAVIGATION -->
        <nav class="bottom-nav-zupee" id="bottomNav">
            <button class="bn-item active" data-page="dashboard"><span class="bn-icon">🏠</span><span class="bn-label">Home</span></button>
            <button class="bn-item" data-page="wallet"><span class="bn-icon">💳</span><span class="bn-label">Wallet</span></button>
            <button class="bn-item bn-center" data-page="refer"><div class="bn-center-btn"><span>🎁</span></div><span class="bn-label">Refer</span></button>
            <button class="bn-item" data-page="history"><span class="bn-icon">📋</span><span class="bn-label">History</span></button>
            <button class="bn-item" data-page="profile"><span class="bn-icon">👤</span><span class="bn-label">Profile</span></button>
        </nav>

        <!-- AUTH MODAL -->
        <div class="modal-overlay-zupee" id="authModal">
            <div class="modal-card-zupee">
                <div class="modal-header-zupee">
                    <h2 id="authModalTitle">Welcome Back!</h2>
                    <button class="modal-close-zupee" id="authModalClose">✕</button>
                </div>
                <div class="modal-body-zupee">
                    <!-- Login Form -->
                    <form id="loginForm" class="auth-form-zupee active">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <div class="form-group-zupee"><label>Mobile / Username / Email</label><input type="text" id="loginMobile" placeholder="Enter mobile, username or email" required></div>
                        <div class="form-group-zupee"><label>Password</label><input type="password" id="loginPassword" placeholder="Enter password" required minlength="6"></div>
                        <button type="submit" class="btn-auth-submit">Login</button>
                        <p class="auth-switch-text">Don't have an account? <a href="#" id="switchToRegister">Register</a></p>
                    </form>
                    <!-- Register Form -->
                    <form id="registerForm" class="auth-form-zupee">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <div class="form-group-zupee"><label>Username</label><input type="text" id="regUsername" placeholder="Choose username" required minlength="3"></div>
                        <div class="form-group-zupee"><label>Mobile Number</label><input type="tel" id="regMobile" placeholder="10-digit mobile" required maxlength="10"></div>
                        <div class="form-group-zupee"><label>Email (Optional)</label><input type="email" id="regEmail" placeholder="your@email.com"></div>
                        <div class="form-group-zupee"><label>Password</label><input type="password" id="regPassword" placeholder="Min 6 characters" required minlength="6"></div>
                        <div class="form-group-zupee"><label>Referral Code (Optional)</label><input type="text" id="regReferral" placeholder="Enter code"></div>
                        <div class="form-group-zupee checkbox-group"><input type="checkbox" id="regTerms" required><label for="regTerms">I agree to Terms & Conditions</label></div>
                        <button type="submit" class="btn-auth-submit">Create Account</button>
                        <p class="auth-switch-text">Already have an account? <a href="#" id="switchToLogin">Login</a></p>
                    </form>
                </div>
            </div>
        </div>

        <!-- Toast -->
        <div class="toast-zupee" id="toast"><span id="toastMessage"></span></div>

    </div>

    <!-- Scripts -->
    <script src="<?php echo htmlspecialchars($basePath); ?>/assets/js/auth-helper.js"></script>
    <script>
        class ZupeeApp {
            constructor() {
                this.currentPage = 'dashboard';
                this.isLoggedIn = false;
                this.walletBalance = 0;
                this.userData = null;
                this.basePath = '<?php echo htmlspecialchars($basePath); ?>';
                this.csrfToken = '<?php echo htmlspecialchars($csrf_token); ?>';
                this.bannerIndex = 0;
                this.bannerInterval = null;
                this.init();
            }

            init() {
                this.bindNavigation();
                this.bindAuthEvents();
                this.bindWalletEvents();
                this.startBannerCarousel();
                this.checkAuthStatus();
            }

            // ==========================================
            // BANNER CAROUSEL
            // ==========================================
            startBannerCarousel() {
                const track = document.getElementById('bannerTrack');
                const dots = document.querySelectorAll('#bannerDots .dot');
                if (!track || !dots.length) return;
                
                const totalSlides = 4;
                const updateBanner = (index) => {
                    track.style.transform = `translateX(-${index * 100}%)`;
                    dots.forEach(d => d.classList.remove('active'));
                    if (dots[index]) dots[index].classList.add('active');
                };

                this.bannerInterval = setInterval(() => {
                    this.bannerIndex = (this.bannerIndex + 1) % totalSlides;
                    updateBanner(this.bannerIndex);
                }, 4000);

                dots.forEach(dot => {
                    dot.addEventListener('click', () => {
                        this.bannerIndex = parseInt(dot.dataset.index);
                        updateBanner(this.bannerIndex);
                        clearInterval(this.bannerInterval);
                        this.bannerInterval = setInterval(() => {
                            this.bannerIndex = (this.bannerIndex + 1) % totalSlides;
                            updateBanner(this.bannerIndex);
                        }, 4000);
                    });
                });

                // Touch swipe
                let touchStartX = 0;
                const carousel = document.getElementById('bannerCarousel');
                if (carousel) {
                    carousel.addEventListener('touchstart', (e) => { touchStartX = e.changedTouches[0].screenX; });
                    carousel.addEventListener('touchend', (e) => {
                        const diff = touchStartX - e.changedTouches[0].screenX;
                        if (diff > 50) this.bannerIndex = (this.bannerIndex + 1) % totalSlides;
                        else if (diff < -50) this.bannerIndex = (this.bannerIndex - 1 + totalSlides) % totalSlides;
                        updateBanner(this.bannerIndex);
                    });
                }
            }

            // ==========================================
            // NAVIGATION
            // ==========================================
            bindNavigation() {
                document.querySelectorAll('.bn-item').forEach(item => {
                    item.addEventListener('click', () => this.navigateTo(item.dataset.page));
                });
                document.getElementById('headerWalletBtn')?.addEventListener('click', () => this.navigateTo('wallet'));
            }

            navigateTo(page) {
                document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
                const target = document.getElementById('page-' + page);
                if (target) { target.classList.add('active'); this.currentPage = page; }
                document.querySelectorAll('.bn-item').forEach(n => n.classList.remove('active'));
                const navItem = document.querySelector(`.bn-item[data-page="${page}"]`);
                if (navItem) navItem.classList.add('active');
                document.getElementById('appMain').scrollTop = 0;
            }

            // ==========================================
            // AUTH EVENTS
            // ==========================================
            bindAuthEvents() {
                document.getElementById('loginBtnProfile')?.addEventListener('click', () => this.openAuthModal('login'));
                document.getElementById('registerBtnProfile')?.addEventListener('click', () => this.openAuthModal('register'));
                document.getElementById('headerLoginBtn')?.addEventListener('click', () => this.openAuthModal('login'));
                document.getElementById('authModalClose')?.addEventListener('click', () => this.closeAuthModal());
                document.getElementById('authModal')?.addEventListener('click', (e) => { if (e.target === e.currentTarget) this.closeAuthModal(); });
                document.getElementById('switchToRegister')?.addEventListener('click', (e) => { e.preventDefault(); this.openAuthModal('register'); });
                document.getElementById('switchToLogin')?.addEventListener('click', (e) => { e.preventDefault(); this.openAuthModal('login'); });
                document.getElementById('loginForm')?.addEventListener('submit', (e) => { e.preventDefault(); this.handleLogin(); });
                document.getElementById('registerForm')?.addEventListener('submit', (e) => { e.preventDefault(); this.handleRegister(); });
                document.getElementById('logoutBtnProfile')?.addEventListener('click', () => this.handleLogout());
            }

            bindWalletEvents() {
                document.getElementById('addMoneyBtn')?.addEventListener('click', () => this.showToast('💳 Add money feature coming soon', 'info'));
                document.getElementById('withdrawBtn')?.addEventListener('click', () => this.showToast('🏦 Withdraw feature coming soon', 'info'));
                document.getElementById('copyCodeBtn')?.addEventListener('click', () => {
                    const code = document.getElementById('referCodeText')?.textContent || 'REF123456';
                    navigator.clipboard?.writeText(code).then(() => this.showToast('✅ Copied!', 'success'));
                });
            }

            openAuthModal(type) {
                const modal = document.getElementById('authModal');
                document.getElementById('authModalTitle').textContent = type === 'login' ? 'Welcome Back!' : 'Create Account';
                document.getElementById('loginForm').classList.toggle('active', type === 'login');
                document.getElementById('registerForm').classList.toggle('active', type === 'register');
                modal.classList.add('active');
            }

            closeAuthModal() { document.getElementById('authModal').classList.remove('active'); }

            async handleLogin() {
                const username = document.getElementById('loginMobile').value.trim();
                const password = document.getElementById('loginPassword').value;
                if (!username || password.length < 6) { this.showToast('Please fill all fields', 'error'); return; }
                const result = await AuthHelper.login({ username, password });
                if (result.success) {
                    this.isLoggedIn = true; this.userData = result.data.user;
                    this.updateUI(); this.closeAuthModal();
                    this.showToast('✅ Login successful!', 'success');
                } else {
                    this.showToast('❌ ' + (result.message || 'Login failed'), 'error');
                }
            }

            async handleRegister() {
                if (!document.getElementById('regTerms').checked) { this.showToast('Accept Terms', 'error'); return; }
                const data = {
                    username: document.getElementById('regUsername').value.trim(),
                    mobile: document.getElementById('regMobile').value.trim(),
                    email: document.getElementById('regEmail').value.trim(),
                    password: document.getElementById('regPassword').value,
                    referral_code: document.getElementById('regReferral').value.trim()
                };
                if (!data.username || !data.mobile || data.password.length < 6) { this.showToast('Fill required fields', 'error'); return; }
                const result = await AuthHelper.register(data);
                if (result.success) {
                    this.isLoggedIn = true; this.userData = result.data.user;
                    this.updateUI(); this.closeAuthModal();
                    this.showToast('✅ Registered!', 'success');
                } else {
                    this.showToast('❌ ' + (result.message || 'Failed'), 'error');
                }
            }

            async handleLogout() {
                if (!confirm('Logout?')) return;
                await AuthHelper.logout();
                this.isLoggedIn = false; this.userData = null; this.updateUI();
                this.showToast('Logged out', 'info');
            }

            async checkAuthStatus() {
                const result = await AuthHelper.checkAuth();
                if (result.success && result.isLoggedIn) {
                    this.isLoggedIn = true; this.userData = result.user; this.updateUI();
                }
            }

            updateUI() {
                const loggedIn = this.isLoggedIn;
                document.getElementById('loginBtnProfile').style.display = loggedIn ? 'none' : 'block';
                document.getElementById('registerBtnProfile').style.display = loggedIn ? 'none' : 'block';
                document.getElementById('logoutBtnProfile').style.display = loggedIn ? 'block' : 'none';
                document.getElementById('headerLoginBtn').style.display = loggedIn ? 'none' : 'inline-block';
                if (this.userData) {
                    document.getElementById('profileName').textContent = this.userData.username || 'Player';
                    document.getElementById('referCodeText').textContent = this.userData.refer_code || 'REF123456';
                    this.walletBalance = parseFloat(this.userData.wallet_balance || 0);
                    document.getElementById('headerBalance').textContent = '₹' + this.walletBalance.toFixed(0);
                    document.getElementById('walletLarge').textContent = '₹' + this.walletBalance.toFixed(2);
                }
            }

            // ==========================================
            // TOURNAMENT ACTIONS
            // ==========================================
            handleQuickPlay(entry, tournamentId) { this.handleJoinTournament(entry, tournamentId); }

            async handleJoinTournament(entryFee, tournamentId) {
                if (!this.isLoggedIn) { this.showToast('Please login to play', 'error'); this.openAuthModal('login'); return; }
                if (this.walletBalance < entryFee) { this.showToast(`Need ₹${entryFee} to join`, 'error'); this.navigateTo('wallet'); return; }
                this.showToast('Joining tournament...', 'info');
                try {
                    const res = await fetch(`${this.basePath}/api/match.php?action=join`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfToken },
                        credentials: 'include',
                        body: JSON.stringify({ entry_fee: entryFee, tournament_id: tournamentId, csrf_token: this.csrfToken })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.walletBalance = data.data.balance_after || (this.walletBalance - entryFee);
                        document.getElementById('headerBalance').textContent = '₹' + this.walletBalance.toFixed(0);
                        this.showToast('✅ Match found! Redirecting...', 'success');
                        if (data.data.match_id) setTimeout(() => { window.location.href = `${this.basePath}/game.php?match_id=${data.data.match_id}`; }, 1000);
                    } else {
                        this.showToast('❌ ' + (data.message || 'Failed'), 'error');
                    }
                } catch (error) { this.showToast('Network error', 'error'); }
            }

            // ==========================================
            // TOAST
            // ==========================================
            showToast(message, type = 'info') {
                const toast = document.getElementById('toast');
                const msg = document.getElementById('toastMessage');
                if (!toast || !msg) return;
                msg.textContent = message;
                toast.className = `toast-zupee ${type} show`;
                if (this.toastTimer) clearTimeout(this.toastTimer);
                this.toastTimer = setTimeout(() => { toast.classList.remove('show'); }, 3000);
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            window.app = new ZupeeApp();
        });
    </script>
</body>
</html>
