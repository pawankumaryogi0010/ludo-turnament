<?php
/**
 * ======================================================
 * ADMIN KYC.PHP - KYC Management UI
 * Ludo Tournament Platform - Admin KYC Dashboard
 * Version: 2.0.0
 * ======================================================
 */

// Prevent direct access
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once dirname(__DIR__) . '/config/db.php';

SessionManager::init();

// ==============================================
// SECURE SESSION VALIDATION
// ==============================================
function validateAdminSession() {
    if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_token'])) {
        return false;
    }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
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
        
        return $stmt->fetch() !== false;
    } catch (Exception $e) {
        return false;
    }
}

if (!validateAdminSession()) {
    session_destroy();
    header('Location: index.php');
    exit;
}

$csrf_token = CSRFToken::generate();
$statusFilter = isset($_GET['status']) ? $_GET['status'] : 'pending';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KYC Management - Admin Dashboard</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #0a0e1a;
            color: #f1f5f9;
            min-height: 100vh;
        }
        
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
        
        /* Stats Bar */
        .stats-bar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: #1a1a2e;
            padding: 16px 20px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.04);
            text-align: center;
        }
        
        .stat-card .stat-number {
            font-size: 24px;
            font-weight: 800;
        }
        
        .stat-card .stat-label {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 2px;
        }
        
        .stat-card .stat-number.pending { color: #f59e0b; }
        .stat-card .stat-number.verified { color: #10b981; }
        .stat-card .stat-number.rejected { color: #ef4444; }
        .stat-card .stat-number.total { color: #3b82f6; }
        
        /* Filter Bar */
        .filter-bar {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .filter-btn {
            padding: 8px 20px;
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 8px;
            background: transparent;
            color: #94a3b8;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
            font-family: inherit;
        }
        
        .filter-btn:hover {
            background: rgba(255, 255, 255, 0.04);
            color: #f1f5f9;
        }
        
        .filter-btn.active {
            background: rgba(124, 58, 237, 0.2);
            color: #8b5cf6;
            border-color: rgba(124, 58, 237, 0.2);
        }
        
        /* Search Bar */
        .search-bar {
            display: flex;
            gap: 12px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }
        
        .search-bar input {
            flex: 1;
            min-width: 200px;
            padding: 10px 14px;
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.04);
            color: #f1f5f9;
            font-size: 14px;
            font-family: inherit;
        }
        
        .search-bar input:focus {
            outline: none;
            border-color: #7c3aed;
        }
        
        .search-bar input::placeholder {
            color: #64748b;
        }
        
        /* KYC List */
        .kyc-list {
            display: grid;
            gap: 16px;
        }
        
        .kyc-card {
            background: #1a1a2e;
            border-radius: 14px;
            padding: 20px;
            border: 1px solid rgba(255, 255, 255, 0.04);
            transition: border-color 0.2s;
        }
        
        .kyc-card:hover {
            border-color: rgba(255, 255, 255, 0.08);
        }
        
        .kyc-card .kyc-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 8px;
        }
        
        .kyc-card .user-info {
            display: flex;
            flex-direction: column;
        }
        
        .kyc-card .user-name {
            font-size: 18px;
            font-weight: 700;
            color: #f1f5f9;
        }
        
        .kyc-card .user-detail {
            font-size: 13px;
            color: #94a3b8;
        }
        
        .kyc-card .status-badge {
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-badge.pending { background: rgba(245, 158, 11, 0.15); color: #f59e0b; }
        .status-badge.verified { background: rgba(16, 185, 129, 0.15); color: #10b981; }
        .status-badge.rejected { background: rgba(239, 68, 68, 0.15); color: #ef4444; }
        
        .kyc-card .kyc-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin: 12px 0;
        }
        
        .kyc-card .detail-item {
            background: rgba(255, 255, 255, 0.02);
            padding: 8px 12px;
            border-radius: 8px;
        }
        
        .kyc-card .detail-item .label {
            font-size: 11px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .kyc-card .detail-item .value {
            font-size: 14px;
            font-weight: 600;
            color: #f1f5f9;
            word-break: break-all;
        }
        
        .kyc-card .document-images {
            display: flex;
            gap: 12px;
            margin: 12px 0;
            flex-wrap: wrap;
        }
        
        .kyc-card .document-image {
            background: rgba(255, 255, 255, 0.04);
            border-radius: 8px;
            padding: 12px;
            border: 1px solid rgba(255, 255, 255, 0.04);
            cursor: pointer;
            transition: all 0.2s;
            max-width: 200px;
            text-align: center;
        }
        
        .kyc-card .document-image:hover {
            border-color: rgba(124, 58, 237, 0.3);
            transform: scale(1.02);
        }
        
        .kyc-card .document-image .img-placeholder {
            width: 100%;
            height: 120px;
            background: rgba(255, 255, 255, 0.04);
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            color: #64748b;
        }
        
        .kyc-card .document-image .img-label {
            font-size: 11px;
            color: #94a3b8;
            margin-top: 6px;
        }
        
        .kyc-card .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 12px;
            flex-wrap: wrap;
        }
        
        .btn-action {
            padding: 8px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
            font-family: inherit;
        }
        
        .btn-action.verify {
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
        }
        
        .btn-action.verify:hover {
            background: rgba(16, 185, 129, 0.25);
        }
        
        .btn-action.reject {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
        }
        
        .btn-action.reject:hover {
            background: rgba(239, 68, 68, 0.25);
        }
        
        .btn-action.view {
            background: rgba(59, 130, 246, 0.15);
            color: #3b82f6;
        }
        
        .btn-action.view:hover {
            background: rgba(59, 130, 246, 0.25);
        }
        
        .btn-action:disabled {
            opacity: 0.5;
            cursor: not-allowed;
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
            max-width: 600px;
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
        
        .modal-box .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.04);
            color: #f1f5f9;
            font-size: 14px;
            font-family: inherit;
            resize: vertical;
            min-height: 100px;
        }
        
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
        
        .modal-actions .btn-danger {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
        }
        
        .modal-actions .btn-danger:hover {
            background: rgba(239, 68, 68, 0.3);
        }
        
        /* Image Zoom Modal */
        .zoom-modal .modal-box {
            max-width: 90vw;
            max-height: 90vh;
            background: rgba(0, 0, 0, 0.9);
            border: none;
            box-shadow: none;
            padding: 20px;
        }
        
        .zoom-modal .modal-box img {
            max-width: 100%;
            max-height: 80vh;
            border-radius: 8px;
            display: block;
            margin: 0 auto;
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
        
        .no-results {
            text-align: center;
            padding: 60px 20px;
            color: #94a3b8;
        }
        
        .no-results .icon {
            font-size: 48px;
            margin-bottom: 16px;
        }
        
        .no-results h3 {
            font-size: 20px;
            color: #f1f5f9;
            margin-bottom: 8px;
        }
        
        .pagination-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 20px;
        }
        
        .pagination-left {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .pagination-left select {
            padding: 6px 12px;
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.04);
            color: #f1f5f9;
            font-size: 13px;
            font-family: inherit;
            cursor: pointer;
        }
        
        .pagination-left select:focus {
            outline: none;
            border-color: #7c3aed;
        }
        
        .pagination-left select option {
            background: #1a1a2e;
        }
        
        .pagination-right {
            display: flex;
            gap: 8px;
        }
        
        @media (max-width: 768px) {
            .kyc-card .kyc-details {
                grid-template-columns: 1fr;
            }
            
            .kyc-card .document-images {
                flex-direction: column;
            }
            
            .stats-bar {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .pagination-controls {
                flex-direction: column;
                align-items: flex-start;
            }
        }
        
        @media (max-width: 480px) {
            .stats-bar {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        
        <!-- Header -->
        <div class="admin-header">
            <h1>🛡️ KYC Management</h1>
            <div class="admin-header-actions">
                <span>👋 <?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?></span>
                <a href="index.php">← Back to Dashboard</a>
                <a href="settings.php">⚙️ Settings</a>
                <a href="withdrawals.php">🏦 Withdrawals</a>
                <a href="disputes.php">📋 Disputes</a>
                <a href="?logout=1" class="logout">🚪 Logout</a>
            </div>
        </div>
        
        <!-- Stats Bar -->
        <div class="stats-bar" id="statsBar">
            <div class="stat-card">
                <div class="stat-number pending" id="statPending">...</div>
                <div class="stat-label">Pending</div>
            </div>
            <div class="stat-card">
                <div class="stat-number verified" id="statVerified">...</div>
                <div class="stat-label">Verified</div>
            </div>
            <div class="stat-card">
                <div class="stat-number rejected" id="statRejected">...</div>
                <div class="stat-label">Rejected</div>
            </div>
            <div class="stat-card">
                <div class="stat-number total" id="statTotal">...</div>
                <div class="stat-label">Total Submitted</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" id="statToday" style="color: #06b6d4;">...</div>
                <div class="stat-label">Today's Submissions</div>
            </div>
        </div>
        
        <!-- Filter & Search -->
        <div class="filter-bar">
            <button class="filter-btn <?php echo $statusFilter === 'pending' ? 'active' : ''; ?>" data-status="pending">⏳ Pending</button>
            <button class="filter-btn <?php echo $statusFilter === 'verified' ? 'active' : ''; ?>" data-status="verified">✅ Verified</button>
            <button class="filter-btn <?php echo $statusFilter === 'rejected' ? 'active' : ''; ?>" data-status="rejected">❌ Rejected</button>
            <button class="filter-btn <?php echo $statusFilter === 'all' ? 'active' : ''; ?>" data-status="all">📋 All</button>
        </div>
        
        <div class="search-bar">
            <input type="text" id="kycSearch" placeholder="Search by username, mobile, or document number..." onkeyup="debounceSearch()">
            <button class="btn-action view" onclick="loadKycList()">🔄 Refresh</button>
        </div>
        
        <!-- KYC List -->
        <div class="kyc-list" id="kycList">
            <div class="loading">
                <div class="loading-spinner"></div>
                <p>Loading KYC documents...</p>
            </div>
        </div>
        
        <!-- Pagination -->
        <div class="pagination-controls">
            <div class="pagination-left">
                <span id="paginationInfo" style="color: #94a3b8; font-size: 14px;"></span>
                <select id="kycLimit" onchange="state.limit = parseInt(this.value); state.currentPage = 0; loadKycList();">
                    <option value="20">20 per page</option>
                    <option value="50" selected>50 per page</option>
                    <option value="100">100 per page</option>
                </select>
            </div>
            <div class="pagination-right">
                <button class="btn-action view" onclick="prevPage()" id="prevBtn">← Prev</button>
                <button class="btn-action view" onclick="nextPage()" id="nextBtn">Next →</button>
            </div>
        </div>
        
    </div>
    
    <!-- ==============================================
    MODAL: Reject KYC
    ============================================== -->
    <div class="modal-overlay" id="rejectModal">
        <div class="modal-box">
            <h2>❌ Reject KYC</h2>
            <p style="color: #94a3b8; margin-bottom: 16px;">Please provide a reason for rejecting this KYC document.</p>
            <input type="hidden" id="rejectKycId" value="">
            <div class="form-group">
                <label for="rejectReason">Rejection Reason</label>
                <textarea id="rejectReason" placeholder="Enter detailed reason for rejection..."></textarea>
            </div>
            <div class="modal-actions">
                <button class="btn-danger" onclick="confirmReject()">❌ Reject</button>
                <button class="btn-cancel" onclick="closeModal('rejectModal')">Cancel</button>
            </div>
        </div>
    </div>
    
    <!-- ==============================================
    MODAL: View Document Details
    ============================================== -->
    <div class="modal-overlay" id="viewModal">
        <div class="modal-box" style="max-width: 700px;">
            <h2>📄 Document Details</h2>
            <div id="viewContent">
                <div class="loading">Loading...</div>
            </div>
            <div class="modal-actions">
                <button class="btn-cancel" onclick="closeModal('viewModal')">Close</button>
            </div>
        </div>
    </div>
    
    <!-- ==============================================
    MODAL: Image Zoom
    ============================================== -->
    <div class="modal-overlay zoom-modal" id="imageZoomModal">
        <div class="modal-box">
            <div style="text-align: right; margin-bottom: 12px;">
                <button onclick="closeModal('imageZoomModal')" style="background: rgba(255,255,255,0.1); color: white; border: none; padding: 8px 20px; border-radius: 8px; cursor: pointer; font-size: 14px;">✕ Close</button>
            </div>
            <img id="zoomImage" src="" alt="Zoomed Image">
        </div>
    </div>
    
    <!-- ==============================================
    TOAST NOTIFICATION
    ============================================== -->
    <div class="toast" id="adminToast"></div>
    
    <!-- ==============================================
    JAVASCRIPT
    ============================================== -->
    <script>
        // ==============================================
        // STATE
        // ==============================================
        let state = {
            currentPage: 0,
            limit: 50,
            total: 0,
            status: '<?php echo $statusFilter; ?>',
            search: '',
            csrfToken: '<?php echo $csrf_token; ?>',
            searchTimeout: null
        };
        
        // ==============================================
        // SESSION HANDLER - Check for 401 redirect
        // ==============================================
        function handleApiResponse(response) {
            if (response.status === 401) {
                showToast('Session expired. Redirecting to login...', 'error');
                setTimeout(() => {
                    window.location.href = 'index.php';
                }, 1500);
                throw new Error('Session expired');
            }
            return response.json();
        }
        
        // ==============================================
        // IMAGE ZOOM FUNCTION
        // ==============================================
        function zoomImage(src) {
            document.getElementById('zoomImage').src = src;
            document.getElementById('imageZoomModal').classList.add('active');
        }
        
        // ==============================================
        // DOM READY
        // ==============================================
        document.addEventListener('DOMContentLoaded', function() {
            loadStats();
            loadKycList();
            
            document.querySelectorAll('.filter-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    state.status = this.dataset.status;
                    state.currentPage = 0;
                    loadKycList();
                });
            });
        });
        
        // ==============================================
        // LOAD STATS
        // ==============================================
        function loadStats() {
            fetch('/api/admin_kyc.php?action=get_stats')
                .then(handleApiResponse)
                .then(data => {
                    if (data.success) {
                        document.getElementById('statPending').textContent = data.data.pending || 0;
                        document.getElementById('statVerified').textContent = data.data.verified || 0;
                        document.getElementById('statRejected').textContent = data.data.rejected || 0;
                        document.getElementById('statTotal').textContent = data.data.total_submitted || 0;
                        document.getElementById('statToday').textContent = data.data.today_submissions || 0;
                    }
                })
                .catch(() => {});
        }
        
        // ==============================================
        // LOAD KYC LIST
        // ==============================================
        function loadKycList() {
            const listEl = document.getElementById('kycList');
            listEl.innerHTML = '<div class="loading"><div class="loading-spinner"></div><p>Loading KYC documents...</p></div>';
            
            const offset = state.currentPage * state.limit;
            const status = state.status === 'all' ? '' : state.status;
            
            fetch(`/api/admin_kyc.php?action=list&status=${status}&offset=${offset}&limit=${state.limit}&search=${encodeURIComponent(state.search)}`)
                .then(handleApiResponse)
                .then(data => {
                    if (data.success) {
                        state.total = data.data.total;
                        renderKycList(data.data.documents);
                        updatePagination();
                    } else {
                        listEl.innerHTML = `<div class="no-results"><div class="icon">❌</div><h3>Error</h3><p>${data.message}</p></div>`;
                    }
                })
                .catch(() => {
                    listEl.innerHTML = '<div class="no-results"><div class="icon">⚠️</div><h3>Network Error</h3><p>Failed to load KYC documents</p></div>';
                });
        }
        
        function renderKycList(documents) {
            const listEl = document.getElementById('kycList');
            
            if (!documents || documents.length === 0) {
                listEl.innerHTML = `
                    <div class="no-results">
                        <div class="icon">📭</div>
                        <h3>No KYC Documents</h3>
                        <p>${state.status === 'pending' ? 'No pending KYC requests.' : 'No KYC documents found.'}</p>
                    </div>
                `;
                return;
            }
            
            listEl.innerHTML = documents.map(doc => `
                <div class="kyc-card">
                    <div class="kyc-header">
                        <div class="user-info">
                            <span class="user-name">${escapeHtml(doc.username || 'Unknown User')}</span>
                            <span class="user-detail">📱 ${escapeHtml(doc.mobile || 'N/A')} • 📧 ${escapeHtml(doc.email || 'N/A')}</span>
                            <span class="user-detail">🆔 User ID: #${doc.user_id}</span>
                            <span class="user-detail">💰 Wallet: ₹${parseFloat(doc.wallet_balance || 0).toFixed(2)}</span>
                        </div>
                        <span class="status-badge ${doc.status}">${doc.status.toUpperCase()}</span>
                    </div>
                    
                    <div class="kyc-details">
                        <div class="detail-item">
                            <div class="label">Document Type</div>
                            <div class="value">${doc.document_type.toUpperCase()}</div>
                        </div>
                        <div class="detail-item">
                            <div class="label">Document Number</div>
                            <div class="value">${escapeHtml(doc.document_number)}</div>
                        </div>
                        ${doc.bank_account_number ? `
                        <div class="detail-item">
                            <div class="label">Bank Account</div>
                            <div class="value">${escapeHtml(doc.bank_account_number)} (${escapeHtml(doc.bank_ifsc || 'N/A')})</div>
                        </div>
                        ` : ''}
                        ${doc.rejection_reason ? `
                        <div class="detail-item">
                            <div class="label">Rejection Reason</div>
                            <div class="value" style="color: #ef4444;">${escapeHtml(doc.rejection_reason)}</div>
                        </div>
                        ` : ''}
                        <div class="detail-item">
                            <div class="label">Submitted</div>
                            <div class="value">${doc.created_at ? new Date(doc.created_at).toLocaleString() : 'N/A'}</div>
                        </div>
                    </div>
                    
                    ${doc.document_image_front ? `
                    <div class="document-images">
                        <div class="document-image" onclick="viewDocument(${doc.id})">
                            <div class="img-placeholder">📄</div>
                            <div class="img-label">Front Image</div>
                        </div>
                        ${doc.document_image_back ? `
                        <div class="document-image" onclick="viewDocument(${doc.id})">
                            <div class="img-placeholder">📄</div>
                            <div class="img-label">Back Image</div>
                        </div>
                        ` : ''}
                        ${doc.selfie_image ? `
                        <div class="document-image" onclick="viewDocument(${doc.id})">
                            <div class="img-placeholder">🤳</div>
                            <div class="img-label">Selfie</div>
                        </div>
                        ` : ''}
                    </div>
                    ` : ''}
                    
                    <div class="action-buttons">
                        ${doc.status === 'pending' ? `
                            <button class="btn-action verify" onclick="verifyKyc(${doc.id})">✅ Verify</button>
                            <button class="btn-action reject" onclick="openRejectModal(${doc.id})">❌ Reject</button>
                            <button class="btn-action view" onclick="viewDocument(${doc.id})">👁️ View Details</button>
                        ` : `
                            <button class="btn-action view" onclick="viewDocument(${doc.id})">👁️ View Details</button>
                            ${doc.status === 'rejected' ? `
                            <button class="btn-action verify" onclick="verifyKyc(${doc.id})">🔄 Re-verify</button>
                            ` : ''}
                        `}
                    </div>
                </div>
            `).join('');
        }
        
        // ==============================================
        // PAGINATION
        // ==============================================
        function updatePagination() {
            const totalPages = Math.ceil(state.total / state.limit);
            const offset = state.currentPage * state.limit;
            
            document.getElementById('paginationInfo').textContent = 
                `Showing ${offset + 1} - ${Math.min(offset + state.limit, state.total)} of ${state.total} documents`;
            
            document.getElementById('prevBtn').disabled = state.currentPage === 0;
            document.getElementById('nextBtn').disabled = state.currentPage >= totalPages - 1;
        }
        
        function prevPage() {
            if (state.currentPage > 0) {
                state.currentPage--;
                loadKycList();
            }
        }
        
        function nextPage() {
            const totalPages = Math.ceil(state.total / state.limit);
            if (state.currentPage < totalPages - 1) {
                state.currentPage++;
                loadKycList();
            }
        }
        
        function debounceSearch() {
            clearTimeout(state.searchTimeout);
            state.searchTimeout = setTimeout(() => {
                state.search = document.getElementById('kycSearch').value;
                state.currentPage = 0;
                loadKycList();
            }, 400);
        }
        
        // ==============================================
        // VERIFY KYC
        // ==============================================
        function verifyKyc(id) {
            if (!confirm('Are you sure you want to verify this KYC document?')) return;
            
            const btn = event ? event.target : document.querySelector(`.btn-action.verify`);
            if (btn) {
                btn.disabled = true;
                btn.textContent = 'Processing...';
            }
            
            fetch('/api/admin_kyc.php?action=verify', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': state.csrfToken
                },
                body: JSON.stringify({
                    id: id,
                    csrf_token: state.csrfToken
                })
            })
            .then(handleApiResponse)
            .then(data => {
                if (data.success) {
                    showToast('KYC verified successfully!', 'success');
                    loadStats();
                    loadKycList();
                } else {
                    showToast(data.message || 'Verification failed', 'error');
                }
            })
            .catch(() => {
                showToast('Network error', 'error');
            })
            .finally(() => {
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = '✅ Verify';
                }
            });
        }
        
        // ==============================================
        // REJECT KYC
        // ==============================================
        function openRejectModal(id) {
            document.getElementById('rejectKycId').value = id;
            document.getElementById('rejectReason').value = '';
            document.getElementById('rejectModal').classList.add('active');
        }
        
        function confirmReject() {
            const id = document.getElementById('rejectKycId').value;
            const reason = document.getElementById('rejectReason').value.trim();
            
            if (!reason || reason.length < 10) {
                showToast('Please provide a detailed rejection reason (minimum 10 characters)', 'error');
                return;
            }
            
            const btn = document.querySelector('.modal-actions .btn-danger');
            btn.disabled = true;
            btn.textContent = 'Processing...';
            
            fetch('/api/admin_kyc.php?action=reject', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': state.csrfToken
                },
                body: JSON.stringify({
                    id: parseInt(id),
                    reason: reason,
                    csrf_token: state.csrfToken
                })
            })
            .then(handleApiResponse)
            .then(data => {
                if (data.success) {
                    showToast('KYC rejected successfully', 'success');
                    closeModal('rejectModal');
                    loadStats();
                    loadKycList();
                } else {
                    showToast(data.message || 'Rejection failed', 'error');
                }
            })
            .catch(() => {
                showToast('Network error', 'error');
            })
            .finally(() => {
                btn.disabled = false;
                btn.textContent = '❌ Reject';
            });
        }
        
        // ==============================================
        // VIEW DOCUMENT
        // ==============================================
        function viewDocument(id) {
            const modal = document.getElementById('viewModal');
            const content = document.getElementById('viewContent');
            content.innerHTML = '<div class="loading"><div class="loading-spinner"></div><p>Loading...</p></div>';
            modal.classList.add('active');
            
            fetch(`/api/admin_kyc.php?action=get&id=${id}`)
                .then(handleApiResponse)
                .then(data => {
                    if (data.success) {
                        renderDocumentDetails(data.data);
                    } else {
                        content.innerHTML = `<p style="color: #ef4444;">${data.message}</p>`;
                    }
                })
                .catch(() => {
                    content.innerHTML = '<p style="color: #ef4444;">Failed to load document details</p>';
                });
        }
        
        function renderDocumentDetails(doc) {
            const content = document.getElementById('viewContent');
            
            // Handle image zoom for document images
            const frontImage = doc.document_image_front ? 
                `<span onclick="zoomImage('${escapeHtml(doc.document_image_front)}')" style="cursor: pointer; color: #3b82f6; text-decoration: underline;">View Front Image</span>` : 
                'N/A';
            const backImage = doc.document_image_back ? 
                `<span onclick="zoomImage('${escapeHtml(doc.document_image_back)}')" style="cursor: pointer; color: #3b82f6; text-decoration: underline;">View Back Image</span>` : 
                'N/A';
            const selfieImage = doc.selfie_image ? 
                `<span onclick="zoomImage('${escapeHtml(doc.selfie_image)}')" style="cursor: pointer; color: #3b82f6; text-decoration: underline;">View Selfie</span>` : 
                'N/A';
            
            content.innerHTML = `
                <div style="display: grid; gap: 12px;">
                    <div><strong>User:</strong> ${escapeHtml(doc.username)} (#${doc.user_id})</div>
                    <div><strong>Mobile:</strong> ${escapeHtml(doc.mobile)}</div>
                    <div><strong>Email:</strong> ${escapeHtml(doc.email)}</div>
                    <div><strong>Document Type:</strong> ${doc.document_type.toUpperCase()}</div>
                    <div><strong>Document Number:</strong> ${escapeHtml(doc.document_number)}</div>
                    <div><strong>Status:</strong> <span class="status-badge ${doc.status}">${doc.status.toUpperCase()}</span></div>
                    <div><strong>Front Image:</strong> ${frontImage}</div>
                    <div><strong>Back Image:</strong> ${backImage}</div>
                    <div><strong>Selfie:</strong> ${selfieImage}</div>
                    ${doc.bank_account_number ? `
                        <div><strong>Bank Account:</strong> ${escapeHtml(doc.bank_account_number)}</div>
                        <div><strong>IFSC Code:</strong> ${escapeHtml(doc.bank_ifsc)}</div>
                        <div><strong>Account Name:</strong> ${escapeHtml(doc.bank_account_name)}</div>
                    ` : ''}
                    ${doc.rejection_reason ? `
                        <div><strong>Rejection Reason:</strong> <span style="color: #ef4444;">${escapeHtml(doc.rejection_reason)}</span></div>
                    ` : ''}
                    <div><strong>Submitted:</strong> ${doc.created_at ? new Date(doc.created_at).toLocaleString() : 'N/A'}</div>
                    ${doc.verified_at ? `<div><strong>Verified At:</strong> ${new Date(doc.verified_at).toLocaleString()}</div>` : ''}
                    <div><strong>Wallet Balance:</strong> ₹${parseFloat(doc.wallet_balance || 0).toFixed(2)}</div>
                    <div><strong>Total Earnings:</strong> ₹${parseFloat(doc.total_earnings || 0).toFixed(2)}</div>
                    <div><strong>Matches Played:</strong> ${doc.total_matches_played || 0}</div>
                    <div><strong>Matches Won:</strong> ${doc.total_matches_won || 0}</div>
                    <div><strong>User Joined:</strong> ${doc.user_joined_at ? new Date(doc.user_joined_at).toLocaleString() : 'N/A'}</div>
                </div>
            `;
        }
        
        // ==============================================
        // MODAL HELPERS
        // ==============================================
        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }
        
        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                }
            });
        });
        
        // ==============================================
        // TOAST & UTILITY
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
        
        function escapeHtml(str) {
            if (!str) return '';
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }
    </script>
</body>
</html>
