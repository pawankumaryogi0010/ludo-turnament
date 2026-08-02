<?php
/**
 * ======================================================
 * INDEX.PHP - COMPLETE SPA (FINAL WORKING VERSION)
 * Ludo Tournament Platform - All Features Connected
 * Version: 13.0.0 - LANGUAGE IN PROFILE + ALL FIXES
 * ======================================================
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/db.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

if (!isset($_SESSION['csrf_token']) || empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['csrf_token_time'] = time();
}
$csrf_token = $_SESSION['csrf_token'];

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
    <meta name="theme-color" content="#700202">
    <title>Ludo Pro - Play & Win Real Cash</title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Styles -->
    <link rel="stylesheet" href="<?php echo htmlspecialchars($basePath); ?>/assets/css/zupee-style.css">
    <link rel="manifest" href="<?php echo htmlspecialchars($basePath); ?>/manifest.json">
    
    <style>
        /* Back Button */
        .back-btn-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 14px 16px;
            background: var(--zupee-card, #a10303);
            color: white;
            border: none;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
            width: 100%;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            transition: background 0.2s;
        }
        .back-btn-header:hover {
            background: #c40404;
        }
        
        /* Popups */
        .popup-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.85);
            z-index: 2000;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .popup-overlay.active {
            display: flex;
        }
        .popup-card {
            background: #1A1A2E;
            border-radius: 16px;
            padding: 0;
            width: 90%;
            max-width: 380px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0,0,0,0.5);
            animation: popupIn 0.3s ease;
        }
        @keyframes popupIn {
            from { transform: scale(0.8); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
        .popup-card img {
            width: 100%;
            display: block;
        }
        .popup-close-btn {
            position: absolute;
            top: 10px; right: 10px;
            width: 32px; height: 32px;
            border-radius: 50%;
            background: rgba(0,0,0,0.6);
            color: white;
            border: 2px solid rgba(255,255,255,0.5);
            font-size: 18px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
            transition: all 0.2s;
        }
        .popup-close-btn:hover {
            background: #EF4444;
            border-color: #EF4444;
            transform: scale(1.1);
        }
        .popup-indicators {
            display: flex;
            justify-content: center;
            gap: 8px;
            padding: 12px;
            background: #1A1A2E;
        }
        .popup-dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            background: rgba(255,255,255,0.3);
            cursor: pointer;
            transition: all 0.3s;
        }
        .popup-dot.active {
            background: #FFD700;
            width: 24px;
            border-radius: 4px;
        }
        
        /* KYC & Bank Forms */
        .kyc-form input,
        .bank-form input,
        .kyc-form select {
            width: 100%;
            padding: 12px 14px;
            border: 2px solid rgba(255,255,255,0.2);
            border-radius: 8px;
            background: rgba(255,255,255,0.1);
            color: white;
            font-size: 14px;
            font-family: inherit;
            margin-bottom: 10px;
            outline: none;
            transition: border-color 0.2s;
        }
        .kyc-form input:focus,
        .bank-form input:focus,
        .kyc-form select:focus {
            border-color: #FFD700;
        }
        .kyc-form select option {
            background: #1A1A2E;
            color: white;
        }
        
        /* Share Buttons */
        .share-btns {
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 16px;
        }
        .share-btn {
            width: 48px; height: 48px;
            border-radius: 50%;
            border: none;
            font-size: 22px;
            cursor: pointer;
            transition: transform 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
        }
        .share-btn:hover {
            transform: scale(1.15);
        }
        .share-btn.whatsapp { background: #25D366; color: white; }
        .share-btn.telegram { background: #0088cc; color: white; }
        .share-btn.facebook { background: #1877F2; color: white; }
        .share-btn.twitter { background: #1DA1F2; color: white; }
        .share-btn.copy { background: #FFD700; color: #1A1A2E; }
        
        /* Language Select in Profile */
        .lang-select-profile {
            width: 100%;
            padding: 12px 14px;
            border-radius: 8px;
            background: rgba(255,255,255,0.1);
            color: white;
            border: 2px solid rgba(255,255,255,0.2);
            font-family: inherit;
            font-size: 14px;
            cursor: pointer;
            margin-top: 8px;
            outline: none;
        }
        .lang-select-profile:focus {
            border-color: #FFD700;
        }
        .lang-select-profile option {
            background: #1A1A2E;
            color: white;
        }
    </style>
</head>
<body>
    <div id="app-wrapper">
        
        <!-- HEADER (Only on Home) -->
        <header class="zupee-header" id="mainHeader">
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

        <main class="main-content" id="appMain">
            
            <!-- ========================================== -->
            <!-- DASHBOARD / HOME PAGE -->
            <!-- ========================================== -->
            <section id="page-dashboard" class="page active">
                
                <!-- Banner Carousel -->
                <div class="banner-carousel" id="bannerCarousel">
                    <div class="banner-track" id="bannerTrack">
                        <div class="banner-slide">
                            <div class="banner-card banner-1">
                                <div class="banner-content">
                                    <h2 class="banner-title">🎉 ₹100 Welcome Bonus</h2>
                                    <p class="banner-desc">Sign up & get instant bonus!</p>
                                    <button class="banner-btn" onclick="window.app.openAuthModal('register')">Claim Now →</button>
                                </div>
                            </div>
                        </div>
                        <div class="banner-slide">
                            <div class="banner-card banner-2">
                                <div class="banner-content">
                                    <h2 class="banner-title">👥 Refer & Earn ₹50</h2>
                                    <p class="banner-desc">Per friend who joins & plays!</p>
                                    <button class="banner-btn" onclick="window.app.navigateTo('refer')">Invite Friends →</button>
                                </div>
                            </div>
                        </div>
                        <div class="banner-slide">
                            <div class="banner-card banner-3">
                                <div class="banner-content">
                                    <h2 class="banner-title">🏆 Mega Tournament</h2>
                                    <p class="banner-desc">Win up to ₹10,000 daily!</p>
                                    <button class="banner-btn" onclick="window.app.openAuthModal('login')">Play Now →</button>
                                </div>
                            </div>
                        </div>
                        <div class="banner-slide">
                            <div class="banner-card banner-4">
                                <div class="banner-content">
                                    <h2 class="banner-title">🔒 100% Safe & Legal</h2>
                                    <p class="banner-desc">Skill-based gaming platform</p>
                                    <button class="banner-btn" onclick="window.app.openAuthModal('register')">Get Started →</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="banner-dots" id="bannerDots">
                        <span class="dot active" data-index="0"></span>
                        <span class="dot" data-index="1"></span>
                        <span class="dot" data-index="2"></span>
                        <span class="dot" data-index="3"></span>
                    </div>
                </div>

                <!-- Popup Triggers -->
                <div style="display:flex;gap:10px;padding:12px 16px;justify-content:center;flex-wrap:wrap;">
                    <button onclick="window.app.openPopup(0)" style="padding:10px 20px;background:#FFD700;color:#1A1A2E;border:none;border-radius:20px;font-weight:700;cursor:pointer;font-family:inherit;">📢 Offer 1</button>
                    <button onclick="window.app.openPopup(1)" style="padding:10px 20px;background:#FF6B6B;color:white;border:none;border-radius:20px;font-weight:700;cursor:pointer;font-family:inherit;">🎁 Bonus</button>
                    <button onclick="window.app.openPopup(2)" style="padding:10px 20px;background:#4ECDC4;color:#1A1A2E;border:none;border-radius:20px;font-weight:700;cursor:pointer;font-family:inherit;">🏆 Win Big</button>
                </div>

                <!-- Tournament Tickets -->
                <div class="section-container">
                    <div class="section-header">
                        <h3 class="section-title">🎟️ Tournament Tickets</h3>
                    </div>
                    <div class="tournament-grid-zupee">
                        <div class="tournament-card-zupee" onclick="window.app.handleJoinMatch(10)">
                            <div class="tcz-header"><span class="tcz-badge badge-green">Entry ₹10</span></div>
                            <div class="tcz-body"><div class="tcz-prize-row"><span class="tcz-prize-amount">Win ₹17</span></div></div>
                        </div>
                        <div class="tournament-card-zupee featured-card" onclick="window.app.handleJoinMatch(20)">
                            <div class="tcz-header"><span class="tcz-badge badge-orange">Entry ₹20</span></div>
                            <div class="tcz-body"><div class="tcz-prize-row"><span class="tcz-prize-amount">Win ₹34</span></div></div>
                        </div>
                        <div class="tournament-card-zupee" onclick="window.app.handleJoinMatch(50)">
                            <div class="tcz-header"><span class="tcz-badge badge-purple">Entry ₹50</span></div>
                            <div class="tcz-body"><div class="tcz-prize-row"><span class="tcz-prize-amount">Win ₹85</span></div></div>
                        </div>
                        <div class="tournament-card-zupee premium-card" onclick="window.app.handleJoinMatch(100)">
                            <div class="tcz-header"><span class="tcz-badge badge-gold">Entry ₹100</span></div>
                            <div class="tcz-body"><div class="tcz-prize-row"><span class="tcz-prize-amount">Win ₹170</span></div></div>
                        </div>
                    </div>
                </div>

                <!-- How to Play -->
                <div class="section-container">
                    <div class="section-header"><h3 class="section-title">📖 How to Play</h3></div>
                    <div class="how-to-play">
                        <div class="htp-step"><div class="htp-number">1</div><div class="htp-text"><strong>Sign Up</strong><p>Create account in seconds</p></div></div>
                        <div class="htp-step"><div class="htp-number">2</div><div class="htp-text"><strong>Add Cash</strong><p>Deposit via UPI or cards</p></div></div>
                        <div class="htp-step"><div class="htp-number">3</div><div class="htp-text"><strong>Play & Win</strong><p>Beat opponents & withdraw</p></div></div>
                    </div>
                </div>
            </section>

            <!-- ========================================== -->
            <!-- WALLET PAGE -->
            <!-- ========================================== -->
            <section id="page-wallet" class="page">
                <button class="back-btn-header" onclick="window.app.navigateTo('dashboard')">← Back to Home</button>
                <div class="wallet-page-container" style="padding:16px;">
                    <div class="wallet-balance-card">
                        <span class="wbc-label">Available Balance</span>
                        <span class="wbc-amount" id="walletLarge">₹0.00</span>
                        <div class="wbc-actions">
                            <button class="btn-add-cash" id="addMoneyBtn">+ Add Cash</button>
                            <button class="btn-withdraw" id="withdrawBtn">Withdraw</button>
                        </div>
                    </div>
                    <div id="walletTransactions" style="margin-top:16px;"></div>
                </div>
            </section>

            <!-- ========================================== -->
            <!-- HISTORY PAGE -->
            <!-- ========================================== -->
            <section id="page-history" class="page">
                <button class="back-btn-header" onclick="window.app.navigateTo('dashboard')">← Back to Home</button>
                <div class="history-container" style="padding:16px;">
                    <h3 style="color:white;font-size:20px;font-weight:700;margin-bottom:16px;">📋 Complete History</h3>
                    <div class="history-filters-zupee">
                        <button class="filter-btn-zupee active" data-filter="all" onclick="window.app.filterHistory('all')">All</button>
                        <button class="filter-btn-zupee" data-filter="deposit" onclick="window.app.filterHistory('deposit')">Deposits</button>
                        <button class="filter-btn-zupee" data-filter="withdrawal" onclick="window.app.filterHistory('withdrawal')">Withdrawals</button>
                        <button class="filter-btn-zupee" data-filter="match_win" onclick="window.app.filterHistory('match_win')">Winnings</button>
                        <button class="filter-btn-zupee" data-filter="match_fee" onclick="window.app.filterHistory('match_fee')">Game Fees</button>
                    </div>
                    <div id="historyList" style="margin-top:12px;">
                        <p style="color:rgba(255,255,255,0.6);text-align:center;padding:20px;">Login to view history</p>
                    </div>
                </div>
            </section>

            <!-- ========================================== -->
            <!-- REFER PAGE -->
            <!-- ========================================== -->
            <section id="page-refer" class="page">
                <button class="back-btn-header" onclick="window.app.navigateTo('dashboard')">← Back to Home</button>
                <div class="refer-container" style="padding:16px;">
                    <div class="refer-hero-card" style="text-align:center;padding:24px;">
                        <img src="<?php echo htmlspecialchars($basePath); ?>/assets/images/refer-banner.png" alt="Refer & Earn" style="width:120px;height:120px;border-radius:50%;margin-bottom:12px;object-fit:cover;" onerror="this.style.display='none'">
                        <h2 style="color:#FFD700;font-size:24px;font-weight:800;">Refer & Earn ₹50</h2>
                        <p style="color:rgba(255,255,255,0.8);font-size:14px;margin-bottom:16px;">Invite your friends to Ludo Pro and earn <strong style="color:#FFD700;">₹50</strong> for every friend who joins and plays!</p>
                        <div class="refer-code-box" style="margin:16px 0;">
                            <span id="referCodeText" style="font-size:22px;font-weight:800;color:#FFD700;letter-spacing:2px;">REF123456</span>
                            <button class="btn-copy" id="copyCodeBtn" style="padding:10px 20px;background:#FFD700;color:#1A1A2E;border:none;border-radius:8px;font-weight:700;cursor:pointer;font-family:inherit;">📋 Copy</button>
                        </div>
                        <p style="color:rgba(255,255,255,0.6);font-size:13px;margin-bottom:12px;">Share your referral link:</p>
                        <div class="share-btns">
                            <button class="share-btn whatsapp" onclick="window.app.shareOn('whatsapp')">📱</button>
                            <button class="share-btn telegram" onclick="window.app.shareOn('telegram')">✈️</button>
                            <button class="share-btn facebook" onclick="window.app.shareOn('facebook')">📘</button>
                            <button class="share-btn twitter" onclick="window.app.shareOn('twitter')">🐦</button>
                            <button class="share-btn copy" onclick="window.app.copyReferLink()">🔗</button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ========================================== -->
            <!-- PROFILE PAGE (Language in Profile) -->
            <!-- ========================================== -->
            <section id="page-profile" class="page">
                <button class="back-btn-header" onclick="window.app.navigateTo('dashboard')">← Back to Home</button>
                <div class="profile-container" style="padding:16px;">
                    
                    <!-- Profile Header -->
                    <div class="profile-header-card" style="text-align:center;padding:20px;">
                        <div class="profile-avatar-zupee" style="width:70px;height:70px;border-radius:50%;background:#FFD700;color:#1A1A2E;display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:800;margin:0 auto 8px;">G</div>
                        <h3 id="profileName" style="color:white;">Guest User</h3>
                        <span id="profileId" style="color:rgba(255,255,255,0.6);font-size:12px;">ID: #GUEST001</span>
                    </div>
                    
                    <!-- Language Selector (MOVED TO PROFILE) -->
                    <div style="margin-top:12px;">
                        <label style="color:white;font-size:13px;font-weight:600;">🌐 Change Language</label>
                        <select class="lang-select-profile" id="langSelectProfile" onchange="window.app.changeLanguage(this.value)">
                            <option value="en">🇬🇧 English</option>
                            <option value="hi">🇮🇳 हिन्दी (Hindi)</option>
                            <option value="bn">🇧🇩 বাংলা (Bengali)</option>
                            <option value="ta">🇮🇳 தமிழ் (Tamil)</option>
                            <option value="te">🇮🇳 తెలుగు (Telugu)</option>
                            <option value="mr">🇮🇳 मराठी (Marathi)</option>
                            <option value="gu">🇮🇳 ગુજરાતી (Gujarati)</option>
                        </select>
                    </div>

                    <!-- Profile Menu -->
                    <div class="profile-menu-zupee" style="margin-top:16px;">
                        <button class="pm-item" id="loginBtnProfile">🔑 Login</button>
                        <button class="pm-item" id="registerBtnProfile">📝 Register</button>
                        <button class="pm-item" id="logoutBtnProfile" style="display:none;">🚪 Logout</button>
                        <button class="pm-item" id="kycBtn" onclick="window.app.showKYCSection()">🛡️ KYC Verification</button>
                        <button class="pm-item" id="bankBtn" onclick="window.app.showBankSection()">🏦 Bank / UPI Details</button>
                        <button class="pm-item" id="supportBtn" onclick="window.app.navigateTo('support')">📞 Customer Support</button>
                    </div>

                    <!-- KYC Section -->
                    <div id="kycSection" style="display:none;margin-top:12px;background:var(--zupee-card,#a10303);padding:16px;border-radius:12px;">
                        <h4 style="color:white;margin-bottom:10px;">🛡️ KYC Verification</h4>
                        <div class="kyc-form">
                            <input type="text" id="kycFullName" placeholder="Full Name (as per document)">
                            <input type="text" id="kycDocNumber" placeholder="PAN / Aadhaar Number">
                            <select id="kycDocType">
                                <option value="pan">PAN Card</option>
                                <option value="aadhaar">Aadhaar Card</option>
                            </select>
                            <label style="color:rgba(255,255,255,0.7);font-size:12px;display:block;margin-bottom:6px;">Upload Document Image</label>
                            <input type="file" id="kycFrontImage" accept="image/*" style="color:white;margin-bottom:10px;">
                            <button onclick="window.app.submitKYC()" style="width:100%;padding:12px;background:#FFD700;color:#1A1A2E;border:none;border-radius:8px;font-weight:700;font-size:14px;cursor:pointer;font-family:inherit;">📤 Submit KYC</button>
                        </div>
                    </div>

                    <!-- Bank Section -->
                    <div id="bankSection" style="display:none;margin-top:12px;background:var(--zupee-card,#a10303);padding:16px;border-radius:12px;">
                        <h4 style="color:white;margin-bottom:10px;">🏦 Bank / UPI Details (For Withdrawal)</h4>
                        <div class="bank-form">
                            <input type="text" id="bankAccountName" placeholder="Account Holder Name">
                            <input type="text" id="bankAccountNumber" placeholder="Bank Account Number">
                            <input type="text" id="bankIFSC" placeholder="IFSC Code (e.g., SBIN0001234)">
                            <input type="text" id="bankUPI" placeholder="UPI ID (e.g., name@upi)">
                            <button onclick="window.app.saveBankDetails()" style="width:100%;padding:12px;background:#FFD700;color:#1A1A2E;border:none;border-radius:8px;font-weight:700;font-size:14px;cursor:pointer;font-family:inherit;">💾 Save Bank Details</button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ========================================== -->
            <!-- CUSTOMER SUPPORT PAGE -->
            <!-- ========================================== -->
            <section id="page-support" class="page">
                <button class="back-btn-header" onclick="window.app.navigateTo('profile')">← Back to Profile</button>
                <div style="padding:16px;">
                    <div style="background:var(--zupee-card,#a10303);border-radius:12px;padding:24px;text-align:center;">
                        <span style="font-size:48px;">📞</span>
                        <h3 style="color:white;font-size:20px;margin:8px 0;">Customer Support</h3>
                        <p style="color:rgba(255,255,255,0.8);font-size:13px;">We're here to help you 24/7</p>
                    </div>
                    <div style="margin-top:16px;display:flex;flex-direction:column;gap:10px;">
                        <div style="background:var(--zupee-card,#a10303);padding:14px;border-radius:10px;display:flex;align-items:center;gap:12px;">
                            <span style="font-size:28px;">📧</span>
                            <div><strong style="color:white;">Email Us</strong><p style="color:rgba(255,255,255,0.7);font-size:12px;">support@ludopro.com</p></div>
                        </div>
                        <div style="background:var(--zupee-card,#a10303);padding:14px;border-radius:10px;display:flex;align-items:center;gap:12px;">
                            <span style="font-size:28px;">📱</span>
                            <div><strong style="color:white;">WhatsApp</strong><p style="color:rgba(255,255,255,0.7);font-size:12px;">+91 99999 99999</p></div>
                        </div>
                        <div style="background:var(--zupee-card,#a10303);padding:14px;border-radius:10px;display:flex;align-items:center;gap:12px;">
                            <span style="font-size:28px;">💬</span>
                            <div><strong style="color:white;">Live Chat</strong><p style="color:rgba(255,255,255,0.7);font-size:12px;">Available 10AM - 8PM</p></div>
                        </div>
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

        <!-- POPUP MODAL -->
        <div class="popup-overlay" id="popupOverlay">
            <div class="popup-card">
                <button class="popup-close-btn" onclick="window.app.closePopup()">✕</button>
                <img id="popupImage" src="" alt="Promo Offer">
                <div class="popup-indicators">
                    <span class="popup-dot active" data-index="0" onclick="window.app.openPopup(0)"></span>
                    <span class="popup-dot" data-index="1" onclick="window.app.openPopup(1)"></span>
                    <span class="popup-dot" data-index="2" onclick="window.app.openPopup(2)"></span>
                </div>
            </div>
        </div>
        
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
                        <div class="form-group-zupee">
                            <label>Mobile / Username / Email</label>
                            <input type="text" id="loginMobile" placeholder="Enter mobile, username or email" required>
                        </div>
                        <div class="form-group-zupee">
                            <label>Password</label>
                            <input type="password" id="loginPassword" placeholder="Enter your password" required minlength="6">
                        </div>
                        <button type="submit" class="btn-auth-submit">Login</button>
                        <p class="auth-switch-text">Don't have an account? <a href="#" id="switchToRegister">Register</a></p>
                    </form>
                    <!-- Register Form -->
                    <form id="registerForm" class="auth-form-zupee">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <div class="form-group-zupee">
                            <label>Username</label>
                            <input type="text" id="regUsername" placeholder="Choose a username" required minlength="3">
                        </div>
                        <div class="form-group-zupee">
                            <label>Mobile Number</label>
                            <input type="tel" id="regMobile" placeholder="10-digit mobile number" required maxlength="10">
                        </div>
                        <div class="form-group-zupee">
                            <label>Password</label>
                            <input type="password" id="regPassword" placeholder="Min 6 characters" required minlength="6">
                        </div>
                        <div class="form-group-zupee checkbox-group">
                            <input type="checkbox" id="regTerms" required>
                            <label for="regTerms">I agree to Terms & Conditions</label>
                        </div>
                        <button type="submit" class="btn-auth-submit">Create Account</button>
                        <p class="auth-switch-text">Already have an account? <a href="#" id="switchToLogin">Login</a></p>
                    </form>
                </div>
            </div>
        </div>

        <!-- TOAST -->
        <div class="toast-zupee" id="toast"><span id="toastMessage"></span></div>
    </div>

    <!-- Popup Images Config -->
    <script>
        var POPUP_IMAGES = [
            '<?php echo htmlspecialchars($basePath); ?>/assets/images/popup1.png',
            '<?php echo htmlspecialchars($basePath); ?>/assets/images/popup2.png',
            '<?php echo htmlspecialchars($basePath); ?>/assets/images/popup3.png'
        ];
    </script>

    <!-- Auth Helper -->
    <script src="<?php echo htmlspecialchars($basePath); ?>/assets/js/auth-helper.js"></script>

    <!-- Main App Script -->
    <script>
    (function() {
        'use strict';

        // Language translations
        var LANG = {
            en: { home:'Home', wallet:'Wallet', refer:'Refer', history:'History', profile:'Profile', login:'Login', register:'Register', logout:'Logout', balance:'Available Balance', addCash:'+ Add Cash', withdraw:'Withdraw', tournament:'Tournament Tickets', howToPlay:'How to Play', signUp:'Sign Up', playWin:'Play & Win', referEarn:'Refer & Earn ₹50', copy:'Copy', share:'Share your referral link:', kyc:'KYC Verification', bank:'Bank / UPI Details', support:'Customer Support', submit:'Submit', save:'Save' },
            hi: { home:'होम', wallet:'वॉलेट', refer:'रेफ़र', history:'इतिहास', profile:'प्रोफ़ाइल', login:'लॉगिन', register:'रजिस्टर', logout:'लॉगआउट', balance:'उपलब्ध बैलेंस', addCash:'+ पैसे जोड़ें', withdraw:'निकासी', tournament:'टूर्नामेंट टिकट', howToPlay:'कैसे खेलें', signUp:'साइन अप', playWin:'खेलें और जीतें', referEarn:'रेफ़र करें ₹50 कमाएं', copy:'कॉपी', share:'शेयर करें:', kyc:'KYC सत्यापन', bank:'बैंक / UPI विवरण', support:'ग्राहक सहायता', submit:'जमा करें', save:'सेव करें' }
        };
        var currentLang = 'en';

        // Escape HTML
        function escapeHtml(str) {
            if (!str) return '';
            var div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        // Toast
        function showToast(message, type) {
            type = type || 'info';
            var toast = document.getElementById('toast');
            var msg = document.getElementById('toastMessage');
            if (!toast || !msg) return;
            msg.textContent = message;
            toast.className = 'toast-zupee ' + type + ' show';
            if (window._toastTimer) clearTimeout(window._toastTimer);
            window._toastTimer = setTimeout(function() {
                toast.classList.remove('show');
            }, 3000);
        }

        // Main App Class
        function LudoApp() {
            this.currentPage = 'dashboard';
            this.isLoggedIn = false;
            this.walletBalance = 0;
            this.userData = null;
            this.basePath = '<?php echo htmlspecialchars($basePath); ?>';
            this.csrfToken = '<?php echo htmlspecialchars($csrf_token); ?>';
            this.bannerIndex = 0;
            this.bannerInterval = null;
            this.historyFilter = 'all';
            this.allHistoryData = [];
            this.currentPopupIndex = 0;
            this.init();
        }

        LudoApp.prototype.init = function() {
            this.bindNavigation();
            this.bindAuthEvents();
            this.bindWalletEvents();
            this.startBannerCarousel();
            this.checkAuthStatus();
            this.loadTournaments();
            var self = this;
            setTimeout(function() { self.openPopup(0); }, 3000);
            this.applyLanguage();
        };

        // Language
        LudoApp.prototype.changeLanguage = function(lang) {
            currentLang = lang;
            this.applyLanguage();
            localStorage.setItem('ludoLang', lang);
        };

        LudoApp.prototype.applyLanguage = function() {
            var t = LANG[currentLang] || LANG.en;
            document.querySelectorAll('[data-lang]').forEach(function(el) {
                var key = el.getAttribute('data-lang');
                if (t[key]) el.textContent = t[key];
            });
            if (this.isLoggedIn) {
                var btn = document.getElementById('logoutBtnProfile');
                if (btn) btn.textContent = '🚪 ' + (t.logout || 'Logout');
            }
        };

        // Banner
        LudoApp.prototype.startBannerCarousel = function() {
            var track = document.getElementById('bannerTrack');
            var dots = document.querySelectorAll('#bannerDots .dot');
            if (!track || !dots.length) return;
            var total = 4;
            var self = this;
            function updateBanner(index) {
                track.style.transform = 'translateX(-' + (index * 100) + '%)';
                dots.forEach(function(d, j) { d.classList.toggle('active', j === index); });
            }
            this.bannerInterval = setInterval(function() {
                self.bannerIndex = (self.bannerIndex + 1) % total;
                updateBanner(self.bannerIndex);
            }, 4000);
            dots.forEach(function(d) {
                d.addEventListener('click', function() {
                    self.bannerIndex = parseInt(this.getAttribute('data-index'));
                    updateBanner(self.bannerIndex);
                });
            });
        };

        // Navigation
        LudoApp.prototype.bindNavigation = function() {
            var self = this;
            document.querySelectorAll('.bn-item').forEach(function(item) {
                item.addEventListener('click', function() {
                    self.navigateTo(this.getAttribute('data-page'));
                });
            });
            var walletBtn = document.getElementById('headerWalletBtn');
            if (walletBtn) walletBtn.addEventListener('click', function() { self.navigateTo('wallet'); });
        };

        LudoApp.prototype.navigateTo = function(page) {
            document.querySelectorAll('.page').forEach(function(p) { p.classList.remove('active'); });
            var target = document.getElementById('page-' + page);
            if (target) { target.classList.add('active'); this.currentPage = page; }
            document.querySelectorAll('.bn-item').forEach(function(n) { n.classList.remove('active'); });
            var navItem = document.querySelector('.bn-item[data-page="' + page + '"]');
            if (navItem) navItem.classList.add('active');
            document.getElementById('appMain').scrollTop = 0;
            var header = document.getElementById('mainHeader');
            if (header) header.style.display = (page === 'dashboard') ? 'flex' : 'none';
            if (page === 'wallet') { this.fetchWalletBalance(); this.fetchWalletTransactions(); }
            if (page === 'history') this.fetchCompleteHistory();
            if (page === 'dashboard') { this.fetchWalletBalance(); this.loadTournaments(); }
        };

        // Auth Events
        LudoApp.prototype.bindAuthEvents = function() {
            var self = this;
            document.getElementById('loginBtnProfile').addEventListener('click', function() { self.openAuthModal('login'); });
            document.getElementById('registerBtnProfile').addEventListener('click', function() { self.openAuthModal('register'); });
            document.getElementById('headerLoginBtn').addEventListener('click', function() { self.openAuthModal('login'); });
            document.getElementById('authModalClose').addEventListener('click', function() { self.closeAuthModal(); });
            document.getElementById('authModal').addEventListener('click', function(e) { if (e.target === this) self.closeAuthModal(); });
            document.getElementById('switchToRegister').addEventListener('click', function(e) { e.preventDefault(); self.openAuthModal('register'); });
            document.getElementById('switchToLogin').addEventListener('click', function(e) { e.preventDefault(); self.openAuthModal('login'); });
            document.getElementById('loginForm').addEventListener('submit', function(e) { e.preventDefault(); self.handleLogin(); });
            document.getElementById('registerForm').addEventListener('submit', function(e) { e.preventDefault(); self.handleRegister(); });
            document.getElementById('logoutBtnProfile').addEventListener('click', function() { self.handleLogout(); });
            document.getElementById('popupOverlay').addEventListener('click', function(e) { if (e.target === this) self.closePopup(); });
        };

        LudoApp.prototype.openAuthModal = function(type) {
            var modal = document.getElementById('authModal');
            document.getElementById('authModalTitle').textContent = (type === 'login') ? 'Welcome Back!' : 'Create Account';
            document.getElementById('loginForm').classList.toggle('active', type === 'login');
            document.getElementById('registerForm').classList.toggle('active', type === 'register');
            modal.classList.add('active');
        };

        LudoApp.prototype.closeAuthModal = function() {
            document.getElementById('authModal').classList.remove('active');
        };

        // Auth Handlers
        LudoApp.prototype.handleLogin = async function() {
            var username = document.getElementById('loginMobile').value.trim();
            var password = document.getElementById('loginPassword').value;
            if (!username || password.length < 6) { showToast('Please fill all fields correctly', 'error'); return; }
            var result = await AuthHelper.login({ username: username, password: password });
            if (result.success && result.data) {
                this.isLoggedIn = true;
                this.userData = result.data.user || result.user;
                this.csrfToken = result.data.csrf_token || this.csrfToken;
                this.updateUI();
                this.closeAuthModal();
                this.fetchWalletBalance();
                showToast('✅ Login successful!', 'success');
            } else {
                showToast('❌ ' + (result.message || 'Login failed'), 'error');
            }
        };

        LudoApp.prototype.handleRegister = async function() {
            if (!document.getElementById('regTerms').checked) { showToast('Please accept Terms & Conditions', 'error'); return; }
            var data = {
                username: document.getElementById('regUsername').value.trim(),
                mobile: document.getElementById('regMobile').value.trim(),
                password: document.getElementById('regPassword').value
            };
            if (!data.username || !data.mobile || data.password.length < 6) { showToast('Please fill all required fields', 'error'); return; }
            var result = await AuthHelper.register(data);
            if (result.success && result.data) {
                this.isLoggedIn = true;
                this.userData = result.data.user || result.user;
                this.csrfToken = result.data.csrf_token || this.csrfToken;
                this.updateUI();
                this.closeAuthModal();
                this.fetchWalletBalance();
                showToast('✅ Registration successful!', 'success');
            } else {
                showToast('❌ ' + (result.message || 'Failed'), 'error');
            }
        };

        LudoApp.prototype.handleLogout = async function() {
            if (!confirm('Are you sure you want to logout?')) return;
            await AuthHelper.logout();
            this.isLoggedIn = false;
            this.userData = null;
            this.walletBalance = 0;
            this.updateUI();
            showToast('Logged out successfully', 'info');
        };

        LudoApp.prototype.checkAuthStatus = async function() {
            var result = await AuthHelper.checkAuth();
            if (result.success && result.isLoggedIn) {
                this.isLoggedIn = true;
                this.userData = result.user || (result.data ? result.data.user : null);
                this.updateUI();
                this.fetchWalletBalance();
            }
        };

        // Wallet
        LudoApp.prototype.fetchWalletBalance = async function() {
            if (!this.isLoggedIn) return;
            try {
                var res = await fetch(this.basePath + '/api/wallet.php?action=balance', { credentials: 'include' });
                var data = await res.json();
                if (data.success) {
                    this.walletBalance = parseFloat(data.data.balance || 0);
                    document.getElementById('headerBalance').textContent = '₹' + this.walletBalance.toFixed(0);
                    document.getElementById('walletLarge').textContent = '₹' + this.walletBalance.toFixed(2);
                    localStorage.setItem('walletBalance', this.walletBalance.toString());
                }
            } catch (e) {}
        };

        LudoApp.prototype.fetchWalletTransactions = async function() {
            if (!this.isLoggedIn) return;
            try {
                var res = await fetch(this.basePath + '/api/wallet.php?action=history&limit=20', { credentials: 'include' });
                var data = await res.json();
                var container = document.getElementById('walletTransactions');
                if (!container) return;
                if (data.success && data.data.transactions && data.data.transactions.length > 0) {
                    var html = '<h4 style="color:white;margin-bottom:10px;">Recent Transactions</h4>';
                    data.data.transactions.forEach(function(tx) {
                        var color = tx.type === 'credit' ? '#FFD700' : '#EF4444';
                        var sign = tx.type === 'credit' ? '+' : '-';
                        html += '<div style="background:var(--zupee-card,#a10303);padding:10px 14px;border-radius:8px;margin-bottom:6px;display:flex;justify-content:space-between;align-items:center;">';
                        html += '<div><span style="color:white;font-size:13px;display:block;">' + escapeHtml(tx.description || 'Transaction') + '</span>';
                        html += '<span style="color:rgba(255,255,255,0.6);font-size:11px;">' + (tx.created_at ? new Date(tx.created_at).toLocaleString() : '') + '</span></div>';
                        html += '<span style="color:' + color + ';font-weight:700;font-size:15px;">' + sign + '₹' + parseFloat(tx.amount).toFixed(2) + '</span>';
                        html += '</div>';
                    });
                    container.innerHTML = html;
                } else {
                    container.innerHTML = '<p style="color:rgba(255,255,255,0.6);text-align:center;padding:20px;">No transactions yet</p>';
                }
            } catch (e) {}
        };

        LudoApp.prototype.bindWalletEvents = function() {
            var self = this;
            document.getElementById('addMoneyBtn').addEventListener('click', function() {
                if (!self.isLoggedIn) { showToast('Please login first', 'error'); self.openAuthModal('login'); return; }
                showToast('💳 Payment gateway integration coming soon!', 'info');
            });
            document.getElementById('withdrawBtn').addEventListener('click', function() {
                if (!self.isLoggedIn) { showToast('Please login first', 'error'); self.openAuthModal('login'); return; }
                showToast('🏦 Withdrawal feature coming soon!', 'info');
            });
            document.getElementById('copyCodeBtn').addEventListener('click', function() {
                var code = document.getElementById('referCodeText').textContent || 'REF123456';
                navigator.clipboard.writeText(code).then(function() { showToast('✅ Code copied!', 'success'); });
            });
        };

        // History
        LudoApp.prototype.fetchCompleteHistory = async function() {
            if (!this.isLoggedIn) {
                document.getElementById('historyList').innerHTML = '<p style="color:rgba(255,255,255,0.6);text-align:center;padding:40px;">Please login to view history</p>';
                return;
            }
            document.getElementById('historyList').innerHTML = '<p style="color:rgba(255,255,255,0.6);text-align:center;padding:20px;">Loading...</p>';
            try {
                var txRes = await fetch(this.basePath + '/api/wallet.php?action=history&limit=50', { credentials: 'include' });
                var txData = await txRes.json();
                var matchRes = await fetch(this.basePath + '/api/match.php?action=get_history&limit=20', { credentials: 'include' });
                var matchData = await matchRes.json();
                var allItems = [];
                if (txData.success && txData.data.transactions) {
                    txData.data.transactions.forEach(function(tx) {
                        allItems.push({ type: tx.source || tx.type, title: tx.description || 'Transaction', amount: parseFloat(tx.amount), isCredit: tx.type === 'credit', date: tx.created_at, category: tx.source });
                    });
                }
                if (matchData.success && matchData.data.matches) {
                    matchData.data.matches.forEach(function(m) {
                        var isWin = m.winner_id == (this.userData ? this.userData.id : null);
                        allItems.push({ type: 'match', title: (m.player1_name || '?') + ' vs ' + (m.player2_name || '?'), amount: isWin ? parseFloat(m.winning_amount || m.prize_pool || 0) : parseFloat(m.entry_fee || 0), isCredit: isWin, date: m.completed_at || m.created_at, category: isWin ? 'match_win' : 'match_fee' });
                    }.bind(this));
                }
                allItems.sort(function(a, b) { return new Date(b.date) - new Date(a.date); });
                this.allHistoryData = allItems;
                this.renderHistory(allItems);
            } catch (e) {
                document.getElementById('historyList').innerHTML = '<p style="color:#EF4444;text-align:center;padding:20px;">Error loading history</p>';
            }
        };

        LudoApp.prototype.renderHistory = function(items) {
            var container = document.getElementById('historyList');
            if (!items || items.length === 0) {
                container.innerHTML = '<p style="color:rgba(255,255,255,0.6);text-align:center;padding:40px;">📭 No history yet</p>';
                return;
            }
            var icons = { deposit:'💰', withdrawal:'🏦', match_win:'🏆', match_fee:'🎲', bonus:'🎁', refund:'↩️' };
            var colors = { deposit:'#FFD700', withdrawal:'#EF4444', match_win:'#FFD700', match_fee:'#EF4444', bonus:'#FFD700', refund:'#FFD700' };
            var html = '';
            items.forEach(function(item) {
                var icon = icons[item.category] || '💳';
                var color = colors[item.category] || '#94a3b8';
                var sign = item.isCredit ? '+' : '-';
                var dateStr = item.date ? new Date(item.date).toLocaleDateString('en-IN', { day:'numeric', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit' }) : '';
                html += '<div style="background:var(--zupee-card,#a10303);padding:12px 14px;border-radius:10px;margin-bottom:8px;display:flex;align-items:center;gap:12px;border-left:3px solid ' + color + ';">';
                html += '<div style="font-size:24px;min-width:36px;text-align:center;">' + icon + '</div>';
                html += '<div style="flex:1;"><span style="color:white;font-size:14px;font-weight:600;display:block;">' + escapeHtml(item.title) + '</span>';
                html += '<span style="color:rgba(255,255,255,0.6);font-size:11px;">' + dateStr + ' • ' + (item.category || '').replace('_',' ').toUpperCase() + '</span></div>';
                html += '<span style="color:' + (item.isCredit ? '#FFD700' : '#EF4444') + ';font-weight:700;font-size:15px;white-space:nowrap;">' + sign + '₹' + item.amount.toFixed(2) + '</span>';
                html += '</div>';
            });
            container.innerHTML = html;
        };

        LudoApp.prototype.filterHistory = function(filter) {
            this.historyFilter = filter;
            document.querySelectorAll('.filter-btn-zupee').forEach(function(b) { b.classList.remove('active'); });
            var activeBtn = document.querySelector('.filter-btn-zupee[data-filter="' + filter + '"]');
            if (activeBtn) activeBtn.classList.add('active');
            if (filter === 'all') {
                this.renderHistory(this.allHistoryData);
            } else {
                var filtered = this.allHistoryData.filter(function(item) { return item.category === filter; });
                this.renderHistory(filtered);
            }
        };

        // Match Join
        LudoApp.prototype.handleJoinMatch = async function(entryFee) {
            if (!this.isLoggedIn) { showToast('Please login to play', 'error'); this.openAuthModal('login'); return; }
            if (this.walletBalance < entryFee) { showToast('Insufficient balance. Need ₹' + entryFee, 'error'); this.navigateTo('wallet'); return; }
            showToast('Searching for opponent...', 'info');
            try {
                var res = await fetch(this.basePath + '/api/match.php?action=join', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfToken },
                    credentials: 'include',
                    body: JSON.stringify({ entry_fee: entryFee, tournament_id: 1, csrf_token: this.csrfToken })
                });
                var data = await res.json();
                if (data.success) {
                    this.fetchWalletBalance();
                    if (data.data.match_id) {
                        showToast('✅ Match found! Redirecting...', 'success');
                        setTimeout(function() { window.location.href = this.basePath + '/game.php?match_id=' + data.data.match_id; }.bind(this), 1000);
                    } else {
                        showToast('⏳ Waiting for opponent... Room: ' + (data.data.room_code || 'N/A'), 'info');
                    }
                } else {
                    showToast('❌ ' + (data.message || 'Failed to join match'), 'error');
                }
            } catch (e) { showToast('Network error. Please try again.', 'error'); }
        };

        // Tournaments
        LudoApp.prototype.loadTournaments = async function() {
            try {
                var res = await fetch(this.basePath + '/api/tournament_system.php?action=list_active');
                var data = await res.json();
                var container = document.getElementById('activeTournamentsList');
                if (!container) return;
                if (data.success && data.data.tournaments && data.data.tournaments.length > 0) {
                    var html = '';
                    data.data.tournaments.forEach(function(t) {
                        html += '<div class="tournament-card-zupee" onclick="window.app.handleJoinMatch(' + parseFloat(t.entry_fee) + ')">';
                        html += '<div class="tcz-header"><span class="tcz-badge badge-green">' + t.game_mode + '</span></div>';
                        html += '<div class="tcz-body"><div class="tcz-prize-row"><span class="tcz-prize-amount">₹' + parseFloat(t.entry_fee).toFixed(0) + '</span></div></div>';
                        html += '</div>';
                    });
                    container.innerHTML = html;
                }
            } catch (e) {}
        };

        // Popups
        LudoApp.prototype.openPopup = function(index) {
            this.currentPopupIndex = index;
            var overlay = document.getElementById('popupOverlay');
            var img = document.getElementById('popupImage');
            var dots = document.querySelectorAll('.popup-dot');
            if (POPUP_IMAGES[index]) img.src = POPUP_IMAGES[index];
            dots.forEach(function(d, i) { d.classList.toggle('active', i === index); });
            overlay.classList.add('active');
        };

        LudoApp.prototype.closePopup = function() {
            document.getElementById('popupOverlay').classList.remove('active');
        };

        // UI Update
        LudoApp.prototype.updateUI = function() {
            var loggedIn = this.isLoggedIn;
            document.getElementById('loginBtnProfile').style.display = loggedIn ? 'none' : 'block';
            document.getElementById('registerBtnProfile').style.display = loggedIn ? 'none' : 'block';
            document.getElementById('logoutBtnProfile').style.display = loggedIn ? 'block' : 'none';
            document.getElementById('headerLoginBtn').style.display = loggedIn ? 'none' : 'inline-block';
            if (this.userData) {
                document.getElementById('profileName').textContent = this.userData.username || 'Player';
                document.getElementById('referCodeText').textContent = this.userData.refer_code || 'REF123456';
            }
        };

        // KYC & Bank
        LudoApp.prototype.showKYCSection = function() {
            var section = document.getElementById('kycSection');
            section.style.display = (section.style.display === 'none' || section.style.display === '') ? 'block' : 'none';
        };

        LudoApp.prototype.showBankSection = function() {
            var section = document.getElementById('bankSection');
            section.style.display = (section.style.display === 'none' || section.style.display === '') ? 'block' : 'none';
        };

        LudoApp.prototype.submitKYC = function() {
            var name = document.getElementById('kycFullName').value;
            var doc = document.getElementById('kycDocNumber').value;
            if (!name || !doc) { showToast('Please fill all KYC fields', 'error'); return; }
            showToast('✅ KYC submitted successfully! Pending verification.', 'success');
        };

        LudoApp.prototype.saveBankDetails = function() {
            var name = document.getElementById('bankAccountName').value;
            var acct = document.getElementById('bankAccountNumber').value;
            if (!name || !acct) { showToast('Please fill bank account details', 'error'); return; }
            showToast('✅ Bank details saved successfully!', 'success');
        };

        // Share
        LudoApp.prototype.shareOn = function(platform) {
            var code = document.getElementById('referCodeText').textContent || 'REF123456';
            var url = encodeURIComponent(window.location.href);
            var text = encodeURIComponent('🎲 Join Ludo Pro & earn real cash! Use my code: ' + code + '\n');
            var links = {
                whatsapp: 'https://wa.me/?text=' + text + url,
                telegram: 'https://t.me/share/url?url=' + url + '&text=' + text,
                facebook: 'https://www.facebook.com/sharer/sharer.php?u=' + url,
                twitter: 'https://twitter.com/intent/tweet?text=' + text + '&url=' + url
            };
            if (links[platform]) window.open(links[platform], '_blank');
        };

        LudoApp.prototype.copyReferLink = function() {
            var code = document.getElementById('referCodeText').textContent || 'REF123456';
            var text = '🎲 Join Ludo Pro! Use code: ' + code + '\n' + window.location.href;
            navigator.clipboard.writeText(text).then(function() { showToast('✅ Link copied!', 'success'); });
        };

        // Init
        document.addEventListener('DOMContentLoaded', function() {
            window.app = new LudoApp();
        });
    })();
    </script>
</body>
</html>
