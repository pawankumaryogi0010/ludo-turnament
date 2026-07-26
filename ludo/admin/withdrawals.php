<?php
/**
 * ======================================================
 * ADMIN WITHDRAWALS.PHP - Withdrawals Management UI
 * Ludo Tournament Platform - Admin Withdrawal Dashboard
 * Version: 2.1.0 - SECURE & ENHANCED
 * ======================================================
 */

// Prevent direct access
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once dirname(__DIR__) . '/config/db.php';

SessionManager::init();

// ==============================================
// SECURE SESSION VALIDATION FUNCTION
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

// Validate session - redirect if invalid
if (!validateAdminSession()) {
    session_destroy();
    header('Location: index.php');
    exit;
}

$isAdminLoggedIn = true;

if (isset($_SESSION['admin_id']) && isset($_SESSION['admin_token'])) {
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("
            SELECT id, username, is_admin, is_active 
            FROM users 
            WHERE id = :admin_id 
            AND is_admin = 1 
            AND is_active = 1
        ");
        $stmt->execute([':admin_id' => $_SESSION['admin_id']]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($admin) {
            $isAdminLoggedIn = true;
        }
    } catch (Exception $e) {
        $isAdminLoggedIn = false;
    }
}

if (!$isAdminLoggedIn) {
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
    <title>Withdrawal Management - Admin Dashboard</title>
    <style>
        /* ==============================================
           WITHDRAWAL ADMIN STYLES
           ============================================== */
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
        .stat-card .stat-number.processing { color: #3b82f6; }
        .stat-card .stat-number.approved { color: #8b5cf6; }
        .stat-card .stat-number.completed { color: #10b981; }
        .stat-card .stat-number.rejected { color: #ef4444; }
        
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
        
        /* Withdrawal List */
        .withdrawal-list {
            display: grid;
            gap: 16px;
        }
        
        .withdrawal-card {
            background: #1a1a2e;
            border-radius: 14px;
            padding: 20px;
            border: 1px solid rgba(255, 255, 255, 0.04);
            transition: border-color 0.2s;
        }
        
        .withdrawal-card:hover {
            border-color: rgba(255, 255, 255, 0.08);
        }
        
        .withdrawal-card .wd-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 8px;
        }
        
        .withdrawal-card .user-info {
            display: flex;
            flex-direction: column;
        }
        
        .withdrawal-card .user-name {
            font-size: 18px;
            font-weight: 700;
            color: #f1f5f9;
        }
        
        .withdrawal-card .user-detail {
            font-size: 13px;
            color: #94a3b8;
        }
        
        .withdrawal-card .status-badge {
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-badge.pending { background: rgba(245, 158, 11, 0.15); color: #f59e0b; }
        .status-badge.processing { background: rgba(59, 130, 246, 0.15); color: #3b82f6; }
        .status-badge.approved { background: rgba(139, 92, 246, 0.15); color: #8b5cf6; }
        .status-badge.completed { background: rgba(16, 185, 129, 0.15); color: #10b981; }
        .status-badge.rejected { background: rgba(239, 68, 68, 0.15); color: #ef4444; }
        
        .withdrawal-card .wd-details {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 12px;
            margin: 12px 0;
        }
        
        .withdrawal-card .detail-item {
            background: rgba(255, 255, 255, 0.02);
            padding: 8px 12px;
            border-radius: 8px;
        }
        
        .withdrawal-card .detail-item .label {
            font-size: 11px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .withdrawal-card .detail-item .value {
            font-size: 14px;
            font-weight: 600;
            color: #f1f5f9;
        }
        
        .withdrawal-card .detail-item .value.amount {
            color: #fbbf24;
            font-size: 18px;
        }
        
        .withdrawal-card .action-buttons {
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
        
        .btn-action.approve {
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
        }
        
        .btn-action.approve:hover {
            background: rgba(16, 185, 129, 0.25);
        }
        
        .btn-action.reject {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
        }
        
        .btn-action.reject:hover {
            background: rgba(239, 68, 68, 0.25);
        }
        
        .btn-action.process {
            background: rgba(59, 130, 246, 0.15);
            color: #3b82f6;
        }
        
        .btn-action.process:hover {
            background: rgba(59, 130, 246, 0.25);
        }
        
        .btn-action.complete {
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
        }
        
        .btn-action.complete:hover {
            background: rgba(16, 185, 129, 0.25);
        }
        
        .btn-action.view {
            background: rgba(139, 92, 246, 0.15);
            color: #8b5cf6;
        }
        
        .btn-action.view:hover {
            background: rgba(139, 92, 246, 0.25);
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
            max-width: 550px;
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
        
        .modal-actions .btn-danger {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
        }
        
        .modal-actions .btn-danger:hover {
            background: rgba(239, 68, 68, 0.3);
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
        
        /* Pagination Dropdown */
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
            .withdrawal-card .wd-details {
                grid-template-columns: 1fr;
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
            <h1>🏦 Withdrawal Management</h1>
            <div class="admin-header-actions">
                <span>👋 <?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?></span>
                <a href="index.php">← Back to Dashboard</a>
                <a href="settings.php">⚙️ Settings</a>
                <a href="kyc.php">🛡️ KYC</a>
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
                <div class="stat-number processing" id="statProcessing">...</div>
                <div class="stat-label">Processing</div>
            </div>
            <div class="stat-card">
                <div class="stat-number approved" id="statApproved">...</div>
                <div class="stat-label">Approved</div>
            </div>
            <div class="stat-card">
                <div class="stat-number completed" id="statCompleted">...</div>
                <div class="stat-label">Completed</div>
            </div>
            <div class="stat-card">
                <div class="stat-number rejected" id="statRejected">...</div>
                <div class="stat-label">Rejected</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" id="statTotalAmount" style="color: #fbbf24;">...</div>
                <div class="stat-label">Total Pending Amount</div>
            </div>
        </div>
        
        <!-- Filter Bar -->
        <div class="filter-bar">
            <button class="filter-btn <?php echo $statusFilter === 'pending' ? 'active' : ''; ?>" data-status="pending">⏳ Pending</button>
            <button class="filter-btn <?php echo $statusFilter === 'processing' ? 'active' : ''; ?>" data-status="processing">🔄 Processing</button>
            <button class="filter-btn <?php echo $statusFilter === 'approved' ? 'active' : ''; ?>" data-status="approved">✅ Approved</button>
            <button class="filter-btn <?php echo $statusFilter === 'completed' ? 'active' : ''; ?>" data-status="completed">✔️ Completed</button>
            <button class="filter-btn <?php echo $statusFilter === 'rejected' ? 'active' : ''; ?>" data-status="rejected">❌ Rejected</button>
            <button class="filter-btn <?php echo $statusFilter === 'all' ? 'active' : ''; ?>" data-status="all">📋 All</button>
        </div>
        
        <!-- Withdrawal List -->
        <div class="withdrawal-list" id="withdrawalList">
            <div class="loading">
                <div class="loading-spinner"></div>
                <p>Loading withdrawals...</p>
            </div>
        </div>
        
        <!-- Updated Pagination with Records Per Page Dropdown -->
        <div class="pagination-controls">
            <div class="pagination-left">
                <span id="paginationInfo" style="color: #94a3b8; font-size: 14px;"></span>
                <select id="withdrawalLimit" onchange="state.limit = parseInt(this.value); state.currentPage = 0; loadWithdrawals();">
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
    MODAL: Reject Withdrawal
    ============================================== -->
    <div class="modal-overlay" id="rejectModal">
        <div class="modal-box">
            <h2>❌ Reject Withdrawal</h2>
            <p style="color: #94a3b8; margin-bottom: 16px;">Please provide a reason for rejecting this withdrawal.</p>
            <input type="hidden" id="rejectWdId" value="">
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
    MODAL: View Details
    ============================================== -->
    <div class="modal-overlay" id="viewModal">
        <div class="modal-box" style="max-width: 600px;">
            <h2>📄 Withdrawal Details</h2>
            <div id="viewContent">
                <div class="loading">Loading...</div>
            </div>
            <div class="modal-actions">
                <button class="btn-cancel" onclick="closeModal('viewModal')">Close</button>
            </div>
        </div>
    </div>
    
    <!-- ==============================================
    MODAL: Process / Complete
    ============================================== -->
    <div class="modal-overlay" id="actionModal">
        <div class="modal-box">
            <h2 id="actionModalTitle">Process Withdrawal</h2>
            <input type="hidden" id="actionWdId" value="">
            <input type="hidden" id="actionType" value="">
            <div class="form-group">
                <label for="actionNotes">Notes</label>
                <textarea id="actionNotes" placeholder="Add any notes..."></textarea>
            </div>
            <div class="form-group">
                <label for="actionTxnId">Transaction ID (Optional)</label>
                <input type="text" id="actionTxnId" placeholder="Enter transaction reference">
            </div>
            <div class="modal-actions">
                <button class="btn-confirm" onclick="confirmAction()">✅ Confirm</button>
                <button class="btn-cancel" onclick="closeModal('actionModal')">Cancel</button>
            </div>
        </div>
    </div>
    
    <!-- ==============================================
    TOAST NOTIFICATION
    ============================================== -->
    <div class="toast" id="adminToast"></div>
    
    <!-- ==============================================
    JAVASCRIPT - UPDATED WITH 401 HANDLER & PAGINATION
    ============================================== -->
    <script>
        // ==============================================
        // STATE
        // ==============================================
        let state = {
            currentPage: 0,
            limit: 50, // Changed from 20 to 50
            total: 0,
            status: '<?php echo $statusFilter; ?>',
            csrfToken: '<?php echo $csrf_token; ?>'
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
            loadStats();
            loadWithdrawals();
            
            document.querySelectorAll('.filter-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    state.status = this.dataset.status;
                    state.currentPage = 0;
                    loadWithdrawals();
                });
            });
        });
        
        // ==============================================
        // LOAD STATS
        // ==============================================
        function loadStats() {
            fetch('/api/admin_withdrawals.php?action=get_stats')
                .then(handleApiResponse)
                .then(data => {
                    if (data.success) {
                        document.getElementById('statPending').textContent = data.data.pending || 0;
                        document.getElementById('statProcessing').textContent = data.data.processing || 0;
                        document.getElementById('statApproved').textContent = data.data.approved || 0;
                        document.getElementById('statCompleted').textContent = data.data.completed || 0;
                        document.getElementById('statRejected').textContent = data.data.rejected || 0;
                        document.getElementById('statTotalAmount').textContent = '₹' + (data.data.total_pending_amount || 0).toFixed(2);
                    }
                })
                .catch(() => {});
        }
        
        // ==============================================
        // LOAD WITHDRAWALS - UPDATED WITH state.limit
        // ==============================================
        function loadWithdrawals() {
            const listEl = document.getElementById('withdrawalList');
            listEl.innerHTML = '<div class="loading"><div class="loading-spinner"></div><p>Loading withdrawals...</p></div>';
            
            const offset = state.currentPage * state.limit;
            const status = state.status === 'all' ? '' : state.status;
            
            fetch(`/api/admin_withdrawals.php?action=list&status=${status}&offset=${offset}&limit=${state.limit}`)
                .then(handleApiResponse)
                .then(data => {
                    if (data.success) {
                        state.total = data.data.total;
                        renderWithdrawals(data.data.withdrawals);
                        updatePagination();
                    } else {
                        listEl.innerHTML = `<div class="no-results"><div class="icon">❌</div><h3>Error</h3><p>${data.message}</p></div>`;
                    }
                })
                .catch(() => {
                    listEl.innerHTML = '<div class="no-results"><div class="icon">⚠️</div><h3>Network Error</h3><p>Failed to load withdrawals</p></div>';
                });
        }
        
        function renderWithdrawals(withdrawals) {
            const listEl = document.getElementById('withdrawalList');
            
            if (!withdrawals || withdrawals.length === 0) {
                listEl.innerHTML = `
                    <div class="no-results">
                        <div class="icon">📭</div>
                        <h3>No Withdrawals</h3>
                        <p>${state.status === 'pending' ? 'No pending withdrawal requests.' : 'No withdrawals found.'}</p>
                    </div>
                `;
                return;
            }
            
            listEl.innerHTML = withdrawals.map(w => `
                <div class="withdrawal-card">
                    <div class="wd-header">
                        <div class="user-info">
                            <span class="user-name">${escapeHtml(w.username || 'Unknown User')}</span>
                            <span class="user-detail">📱 ${escapeHtml(w.mobile || 'N/A')} • 📧 ${escapeHtml(w.email || 'N/A')}</span>
                            <span class="user-detail">🆔 User #${w.user_id} • 💰 Wallet: ₹${parseFloat(w.wallet_balance || 0).toFixed(2)}</span>
                            <span class="user-detail">🏦 ${escapeHtml(w.bank_account_name)} • ${escapeHtml(w.bank_account_number)} • ${escapeHtml(w.bank_ifsc)}</span>
                            ${w.upi_id ? `<span class="user-detail">📱 UPI: ${escapeHtml(w.upi_id)}</span>` : ''}
                        </div>
                        <span class="status-badge ${w.status}">${w.status.toUpperCase()}</span>
                    </div>
                    
                    <div class="wd-details">
                        <div class="detail-item">
                            <div class="label">Amount</div>
                            <div class="value amount">₹${parseFloat(w.amount).toFixed(2)}</div>
                        </div>
                        <div class="detail-item">
                            <div class="label">Transaction ID</div>
                            <div class="value">${escapeHtml(w.transaction_id || 'N/A')}</div>
                        </div>
                        <div class="detail-item">
                            <div class="label">Requested</div>
                            <div class="value">${w.created_at ? new Date(w.created_at).toLocaleString() : 'N/A'}</div>
                        </div>
                        ${w.rejection_reason ? `
                        <div class="detail-item" style="grid-column: 1 / -1;">
                            <div class="label">Rejection Reason</div>
                            <div class="value" style="color: #ef4444;">${escapeHtml(w.rejection_reason)}</div>
                        </div>
                        ` : ''}
                        ${w.admin_notes ? `
                        <div class="detail-item" style="grid-column: 1 / -1;">
                            <div class="label">Admin Notes</div>
                            <div class="value" style="font-size: 13px; color: #94a3b8;">${escapeHtml(w.admin_notes)}</div>
                        </div>
                        ` : ''}
                    </div>
                    
                    <div class="action-buttons">
                        ${w.status === 'pending' ? `
                            <button class="btn-action approve" onclick="approveWithdrawal(${w.id})">✅ Approve</button>
                            <button class="btn-action reject" onclick="openRejectModal(${w.id})">❌ Reject</button>
                            <button class="btn-action view" onclick="viewWithdrawal(${w.id})">👁️ View</button>
                        ` : ''}
                        ${w.status === 'approved' ? `
                            <button class="btn-action process" onclick="openActionModal(${w.id}, 'process')">🔄 Mark Processing</button>
                            <button class="btn-action reject" onclick="openRejectModal(${w.id})">❌ Reject</button>
                            <button class="btn-action view" onclick="viewWithdrawal(${w.id})">👁️ View</button>
                        ` : ''}
                        ${w.status === 'processing' ? `
                            <button class="btn-action complete" onclick="openActionModal(${w.id}, 'complete')">✅ Complete</button>
                            <button class="btn-action view" onclick="viewWithdrawal(${w.id})">👁️ View</button>
                        ` : ''}
                        ${['completed', 'rejected', 'approved'].includes(w.status) && w.status !== 'pending' && w.status !== 'processing' ? `
                            <button class="btn-action view" onclick="viewWithdrawal(${w.id})">👁️ View</button>
                        ` : ''}
                    </div>
                </div>
            `).join('');
        }
        
        // ==============================================
        // PAGINATION - UPDATED WITH state.limit
        // ==============================================
        function updatePagination() {
            const totalPages = Math.ceil(state.total / state.limit);
            const offset = state.currentPage * state.limit;
            
            document.getElementById('paginationInfo').textContent = 
                `Showing ${offset + 1} - ${Math.min(offset + state.limit, state.total)} of ${state.total} withdrawals`;
            
            document.getElementById('prevBtn').disabled = state.currentPage === 0;
            document.getElementById('nextBtn').disabled = state.currentPage >= totalPages - 1;
        }
        
        function prevPage() {
            if (state.currentPage > 0) {
                state.currentPage--;
                loadWithdrawals();
            }
        }
        
        function nextPage() {
            const totalPages = Math.ceil(state.total / state.limit);
            if (state.currentPage < totalPages - 1) {
                state.currentPage++;
                loadWithdrawals();
            }
        }
        
        // ==============================================
        // APPROVE WITHDRAWAL - UPDATED WITH 401 HANDLER
        // ==============================================
        function approveWithdrawal(id) {
            if (!confirm('Are you sure you want to approve this withdrawal? This will deduct from user wallet.')) return;
            
            const btn = event ? event.target : document.querySelector(`.btn-action.approve`);
            if (btn) {
                btn.disabled = true;
                btn.textContent = 'Processing...';
            }
            
            fetch('/api/admin_withdrawals.php?action=approve', {
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
                    showToast('Withdrawal approved successfully!', 'success');
                    loadStats();
                    loadWithdrawals();
                } else {
                    showToast(data.message || 'Approval failed', 'error');
                }
            })
            .catch(() => {
                showToast('Network error', 'error');
            })
            .finally(() => {
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = '✅ Approve';
                }
            });
        }
        
        // ==============================================
        // REJECT WITHDRAWAL - UPDATED WITH 401 HANDLER
        // ==============================================
        function openRejectModal(id) {
            document.getElementById('rejectWdId').value = id;
            document.getElementById('rejectReason').value = '';
            document.getElementById('rejectModal').classList.add('active');
        }
        
        function confirmReject() {
            const id = document.getElementById('rejectWdId').value;
            const reason = document.getElementById('rejectReason').value.trim();
            
            if (!reason || reason.length < 10) {
                showToast('Please provide a detailed rejection reason (minimum 10 characters)', 'error');
                return;
            }
            
            const btn = document.querySelector('.modal-actions .btn-danger');
            btn.disabled = true;
            btn.textContent = 'Processing...';
            
            fetch('/api/admin_withdrawals.php?action=reject', {
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
                    showToast('Withdrawal rejected and amount refunded', 'success');
                    closeModal('rejectModal');
                    loadStats();
                    loadWithdrawals();
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
        // ACTION MODAL (Process / Complete) - UPDATED WITH 401 HANDLER
        // ==============================================
        function openActionModal(id, action) {
            document.getElementById('actionWdId').value = id;
            document.getElementById('actionType').value = action;
            document.getElementById('actionTxnId').value = '';
            document.getElementById('actionNotes').value = '';
            
            const title = action === 'process' ? '🔄 Mark as Processing' : '✅ Complete Withdrawal';
            document.getElementById('actionModalTitle').textContent = title;
            document.getElementById('actionModal').classList.add('active');
        }
        
        function confirmAction() {
            const id = document.getElementById('actionWdId').value;
            const action = document.getElementById('actionType').value;
            const notes = document.getElementById('actionNotes').value.trim();
            const txnId = document.getElementById('actionTxnId').value.trim();
            
            const endpoint = action === 'process' ? 'process' : 'complete';
            
            const btn = document.querySelector('#actionModal .btn-confirm');
            btn.disabled = true;
            btn.textContent = 'Processing...';
            
            fetch(`/api/admin_withdrawals.php?action=${endpoint}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': state.csrfToken
                },
                body: JSON.stringify({
                    id: parseInt(id),
                    notes: notes,
                    transaction_id: txnId,
                    csrf_token: state.csrfToken
                })
            })
            .then(handleApiResponse)
            .then(data => {
                if (data.success) {
                    showToast(data.message || 'Action completed successfully', 'success');
                    closeModal('actionModal');
                    loadStats();
                    loadWithdrawals();
                } else {
                    showToast(data.message || 'Action failed', 'error');
                }
            })
            .catch(() => {
                showToast('Network error', 'error');
            })
            .finally(() => {
                btn.disabled = false;
                btn.textContent = '✅ Confirm';
            });
        }
        
        // ==============================================
        // VIEW WITHDRAWAL DETAILS - UPDATED WITH 401 HANDLER
        // ==============================================
        function viewWithdrawal(id) {
            const modal = document.getElementById('viewModal');
            const content = document.getElementById('viewContent');
            content.innerHTML = '<div class="loading"><div class="loading-spinner"></div><p>Loading...</p></div>';
            modal.classList.add('active');
            
            fetch(`/api/admin_withdrawals.php?action=get&id=${id}`)
                .then(handleApiResponse)
                .then(data => {
                    if (data.success) {
                        renderViewDetails(data.data);
                    } else {
                        content.innerHTML = `<p style="color: #ef4444;">${data.message}</p>`;
                    }
                })
                .catch(() => {
                    content.innerHTML = '<p style="color: #ef4444;">Failed to load details</p>';
                });
        }
        
        function renderViewDetails(w) {
            const content = document.getElementById('viewContent');
            
            content.innerHTML = `
                <div style="display: grid; gap: 12px;">
                    <div><strong>Withdrawal ID:</strong> #${w.id}</div>
                    <div><strong>User:</strong> ${escapeHtml(w.username)} (#${w.user_id})</div>
                    <div><strong>Mobile:</strong> ${escapeHtml(w.mobile)}</div>
                    <div><strong>Email:</strong> ${escapeHtml(w.email)}</div>
                    <div><strong>Amount:</strong> <span style="color: #fbbf24; font-size: 20px; font-weight: 700;">₹${parseFloat(w.amount).toFixed(2)}</span></div>
                    <div><strong>Bank Account:</strong> ${escapeHtml(w.bank_account_number)}</div>
                    <div><strong>IFSC Code:</strong> ${escapeHtml(w.bank_ifsc)}</div>
                    <div><strong>Account Name:</strong> ${escapeHtml(w.bank_account_name)}</div>
                    ${w.upi_id ? `<div><strong>UPI ID:</strong> ${escapeHtml(w.upi_id)}</div>` : ''}
                    <div><strong>Status:</strong> <span class="status-badge ${w.status}">${w.status.toUpperCase()}</span></div>
                    <div><strong>Transaction ID:</strong> ${escapeHtml(w.transaction_id || 'N/A')}</div>
                    <div><strong>Requested:</strong> ${w.created_at ? new Date(w.created_at).toLocaleString() : 'N/A'}</div>
                    ${w.processed_at ? `<div><strong>Processed At:</strong> ${new Date(w.processed_at).toLocaleString()}</div>` : ''}
                    ${w.completed_at ? `<div><strong>Completed At:</strong> ${new Date(w.completed_at).toLocaleString()}</div>` : ''}
                    ${w.rejection_reason ? `<div><strong>Rejection Reason:</strong> <span style="color: #ef4444;">${escapeHtml(w.rejection_reason)}</span></div>` : ''}
                    ${w.admin_notes ? `<div><strong>Admin Notes:</strong> ${escapeHtml(w.admin_notes)}</div>` : ''}
                    <div><strong>User Wallet Balance:</strong> ₹${parseFloat(w.wallet_balance || 0).toFixed(2)}</div>
                    <div><strong>User Total Earnings:</strong> ₹${parseFloat(w.total_earnings || 0).toFixed(2)}</div>
                    <div><strong>User Total Withdrawn:</strong> ₹${parseFloat(w.total_withdrawn || 0).toFixed(2)}</div>
                    <div><strong>KYC Status:</strong> ${w.kyc_status || 'N/A'}</div>
                    ${w.pan_number ? `<div><strong>PAN:</strong> ${escapeHtml(w.pan_number)}</div>` : ''}
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
