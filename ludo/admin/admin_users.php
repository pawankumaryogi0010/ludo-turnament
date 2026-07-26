<?php
/**
 * ======================================================
 * ADMIN_USERS.PHP - User Management UI
 * Ludo Tournament Platform - Admin User Management
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Admin Dashboard</title>
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
        
        /* Search & Filter */
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
        
        .search-bar select {
            padding: 10px 14px;
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.04);
            color: #f1f5f9;
            font-size: 14px;
            font-family: inherit;
            cursor: pointer;
        }
        
        .search-bar select:focus {
            outline: none;
            border-color: #7c3aed;
        }
        
        .search-bar select option {
            background: #1a1a2e;
        }
        
        .search-bar .btn-action {
            padding: 10px 20px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
            font-family: inherit;
        }
        
        .btn-action.primary {
            background: rgba(59, 130, 246, 0.2);
            color: #3b82f6;
        }
        
        .btn-action.primary:hover {
            background: rgba(59, 130, 246, 0.3);
        }
        
        /* Table */
        .table-container {
            background: #1a1a2e;
            border-radius: 14px;
            border: 1px solid rgba(255, 255, 255, 0.04);
            overflow: hidden;
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        
        table thead {
            background: rgba(255, 255, 255, 0.02);
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
        }
        
        table th {
            padding: 12px 16px;
            text-align: left;
            color: #94a3b8;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            cursor: pointer;
            user-select: none;
        }
        
        table th:hover {
            color: #f1f5f9;
        }
        
        table td {
            padding: 12px 16px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.02);
        }
        
        table tr:hover td {
            background: rgba(255, 255, 255, 0.02);
        }
        
        .status-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .status-badge.active { background: rgba(16, 185, 129, 0.15); color: #10b981; }
        .status-badge.inactive { background: rgba(239, 68, 68, 0.15); color: #ef4444; }
        .status-badge.verified { background: rgba(16, 185, 129, 0.15); color: #10b981; }
        .status-badge.pending { background: rgba(245, 158, 11, 0.15); color: #f59e0b; }
        .status-badge.rejected { background: rgba(239, 68, 68, 0.15); color: #ef4444; }
        .status-badge.not_submitted { background: rgba(148, 163, 184, 0.15); color: #94a3b8; }
        
        .btn-action-sm {
            padding: 4px 10px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            font-family: inherit;
            margin: 0 2px;
        }
        
        .btn-action-sm.primary { background: rgba(59, 130, 246, 0.2); color: #3b82f6; }
        .btn-action-sm.primary:hover { background: rgba(59, 130, 246, 0.3); }
        .btn-action-sm.success { background: rgba(16, 185, 129, 0.2); color: #10b981; }
        .btn-action-sm.success:hover { background: rgba(16, 185, 129, 0.3); }
        .btn-action-sm.danger { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
        .btn-action-sm.danger:hover { background: rgba(239, 68, 68, 0.3); }
        .btn-action-sm.warning { background: rgba(245, 158, 11, 0.2); color: #f59e0b; }
        .btn-action-sm.warning:hover { background: rgba(245, 158, 11, 0.3); }
        
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
            max-width: 700px;
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
        
        .modal-box .modal-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin: 12px 0;
        }
        
        .modal-box .detail-item {
            background: rgba(255, 255, 255, 0.02);
            padding: 8px 12px;
            border-radius: 8px;
        }
        
        .modal-box .detail-item .label {
            font-size: 11px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .modal-box .detail-item .value {
            font-size: 14px;
            font-weight: 600;
            color: #f1f5f9;
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
            .admin-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .modal-box .modal-details {
                grid-template-columns: 1fr;
            }
            
            .pagination-controls {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        
        <!-- Header -->
        <div class="admin-header">
            <h1>👥 User Management</h1>
            <div class="admin-header-actions">
                <span>👋 <?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?></span>
                <a href="index.php">← Back to Dashboard</a>
                <a href="settings.php">⚙️ Settings</a>
                <a href="kyc.php">🛡️ KYC</a>
                <a href="withdrawals.php">🏦 Withdrawals</a>
                <a href="disputes.php">📋 Disputes</a>
                <a href="?logout=1" class="logout">🚪 Logout</a>
            </div>
        </div>
        
        <!-- Stats Bar -->
        <div class="stats-bar" id="statsBar">
            <div class="stat-card">
                <div class="stat-number" id="statTotalUsers" style="color: #3b82f6;">...</div>
                <div class="stat-label">Total Users</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" id="statActiveUsers" style="color: #10b981;">...</div>
                <div class="stat-label">Active Users</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" id="statNewUsersToday" style="color: #fbbf24;">...</div>
                <div class="stat-label">New Today</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" id="statKycVerified" style="color: #8b5cf6;">...</div>
                <div class="stat-label">KYC Verified</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" id="statTotalBalance" style="color: #fbbf24;">...</div>
                <div class="stat-label">Total Wallet Balance</div>
            </div>
        </div>
        
        <!-- Search & Filter -->
        <div class="search-bar">
            <input type="text" id="userSearch" placeholder="Search by username, mobile, or email..." onkeyup="debounceSearch()">
            <select id="statusFilter" onchange="state.currentPage = 0; loadUsers();">
                <option value="">All Users</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
            <select id="sortFilter" onchange="state.sort = this.value; state.currentPage = 0; loadUsers();">
                <option value="id_desc">Newest First</option>
                <option value="id_asc">Oldest First</option>
                <option value="username_asc">Username (A-Z)</option>
                <option value="username_desc">Username (Z-A)</option>
                <option value="balance_desc">Highest Balance</option>
                <option value="balance_asc">Lowest Balance</option>
                <option value="elo_desc">Highest ELO</option>
                <option value="elo_asc">Lowest ELO</option>
            </select>
            <button class="btn-action primary" onclick="loadUsers()">🔄 Refresh</button>
        </div>
        
        <!-- User Table -->
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Mobile</th>
                        <th>Balance</th>
                        <th>Matches</th>
                        <th>Wins</th>
                        <th>ELO</th>
                        <th>KYC</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="usersTableBody">
                    <tr><td colspan="10" class="loading">Loading users...</td></tr>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <div class="pagination-controls">
            <div class="pagination-left">
                <span id="paginationInfo" style="color: #94a3b8; font-size: 14px;"></span>
                <select id="userLimit" onchange="state.limit = parseInt(this.value); state.currentPage = 0; loadUsers();">
                    <option value="20">20 per page</option>
                    <option value="50" selected>50 per page</option>
                    <option value="100">100 per page</option>
                </select>
            </div>
            <div class="pagination-right">
                <button class="btn-action primary" onclick="prevPage()" id="prevBtn">← Prev</button>
                <button class="btn-action primary" onclick="nextPage()" id="nextBtn">Next →</button>
            </div>
        </div>
        
    </div>
    
    <!-- ==============================================
    MODAL: User Details
    ============================================== -->
    <div class="modal-overlay" id="userModal">
        <div class="modal-box">
            <h2 id="userModalTitle">👤 User Details</h2>
            <div id="userModalContent">
                <div class="loading">Loading...</div>
            </div>
            <div class="modal-actions">
                <button class="btn-cancel" onclick="closeModal('userModal')">Close</button>
            </div>
        </div>
    </div>
    
    <!-- ==============================================
    MODAL: Edit Balance
    ============================================== -->
    <div class="modal-overlay" id="balanceModal">
        <div class="modal-box">
            <h2>💰 Adjust User Balance</h2>
            <input type="hidden" id="balUserId" value="">
            <div class="form-group">
                <label>User</label>
                <input type="text" id="balUserDisplay" disabled style="opacity: 0.6;">
            </div>
            <div class="form-group">
                <label>Current Balance</label>
                <input type="text" id="balCurrent" disabled style="opacity: 0.6;">
            </div>
            <div class="form-group">
                <label>Action</label>
                <select id="balType">
                    <option value="credit">Credit (+)</option>
                    <option value="debit">Debit (-)</option>
                </select>
            </div>
            <div class="form-group">
                <label>Amount (₹)</label>
                <input type="number" id="balAmount" placeholder="Enter amount" step="0.01" min="0.01" required>
            </div>
            <div class="form-group">
                <label>Reason</label>
                <input type="text" id="balReason" placeholder="Reason for adjustment">
            </div>
            <div class="modal-actions">
                <button class="btn-confirm" onclick="submitBalance()">✅ Confirm</button>
                <button class="btn-cancel" onclick="closeModal('balanceModal')">Cancel</button>
            </div>
        </div>
    </div>
    
    <!-- ==============================================
    MODAL: User Transactions
    ============================================== -->
    <div class="modal-overlay" id="transactionsModal">
        <div class="modal-box" style="max-width: 800px;">
            <h2>📊 User Transactions</h2>
            <div id="transactionsContent">
                <div class="loading">Loading...</div>
            </div>
            <div class="modal-actions">
                <button class="btn-cancel" onclick="closeModal('transactionsModal')">Close</button>
            </div>
        </div>
    </div>
    
    <!-- ==============================================
    MODAL: User Matches
    ============================================== -->
    <div class="modal-overlay" id="matchesModal">
        <div class="modal-box" style="max-width: 800px;">
            <h2>🎯 User Matches</h2>
            <div id="matchesContent">
                <div class="loading">Loading...</div>
            </div>
            <div class="modal-actions">
                <button class="btn-cancel" onclick="closeModal('matchesModal')">Close</button>
            </div>
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
            search: '',
            status: '',
            sort: 'id_desc',
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
        // DOM READY
        // ==============================================
        document.addEventListener('DOMContentLoaded', function() {
            loadStats();
            loadUsers();
        });
        
        // ==============================================
        // LOAD STATS
        // ==============================================
        function loadStats() {
            fetch('/api/admin_users.php?action=get_stats')
                .then(handleApiResponse)
                .then(data => {
                    if (data.success) {
                        document.getElementById('statTotalUsers').textContent = data.data.total_users || 0;
                        document.getElementById('statActiveUsers').textContent = data.data.active_users || 0;
                        document.getElementById('statNewUsersToday').textContent = data.data.new_users_today || 0;
                        document.getElementById('statKycVerified').textContent = data.data.kyc_verified || 0;
                        document.getElementById('statTotalBalance').textContent = '₹' + (data.data.total_balance || 0).toFixed(2);
                    }
                })
                .catch(() => {});
        }
        
        // ==============================================
        // LOAD USERS
        // ==============================================
        function loadUsers() {
            const tbody = document.getElementById('usersTableBody');
            tbody.innerHTML = '<tr><td colspan="10" class="loading"><div class="loading-spinner"></div> Loading...</td></tr>';
            
            const offset = state.currentPage * state.limit;
            
            fetch(`/api/admin_users.php?action=list&offset=${offset}&limit=${state.limit}&search=${encodeURIComponent(state.search)}&status=${state.status}&sort=${state.sort}`)
                .then(handleApiResponse)
                .then(data => {
                    if (data.success) {
                        state.total = data.data.total;
                        renderUsers(data.data.users);
                        document.getElementById('paginationInfo').textContent = 
                            `Showing ${offset + 1} - ${Math.min(offset + state.limit, state.total)} of ${state.total} users`;
                    } else {
                        tbody.innerHTML = `<tr><td colspan="10" style="color: #ef4444;">${data.message}</td></tr>`;
                    }
                })
                .catch(() => {
                    tbody.innerHTML = '<tr><td colspan="10" style="color: #ef4444;">Failed to load users</td></tr>';
                });
        }
        
        function renderUsers(users) {
            const tbody = document.getElementById('usersTableBody');
            
            if (!users || users.length === 0) {
                tbody.innerHTML = '<tr><td colspan="10" style="color: #94a3b8; text-align: center;">No users found</td></tr>';
                return;
            }
            
            tbody.innerHTML = users.map(u => `
                <tr>
                    <td>#${u.id}</td>
                    <td>${escapeHtml(u.username)}</td>
                    <td>${escapeHtml(u.mobile)}</td>
                    <td><strong style="color: #fbbf24;">₹${parseFloat(u.wallet_balance).toFixed(2)}</strong></td>
                    <td>${u.total_matches_played || 0}</td>
                    <td>${u.total_matches_won || 0}</td>
                    <td>${u.elo_rating || 1200}</td>
                    <td>
                        <span class="status-badge ${u.kyc_status || 'not_submitted'}">
                            ${u.kyc_status || 'not_submitted'}
                        </span>
                    </td>
                    <td>
                        <span class="status-badge ${u.is_active ? 'active' : 'inactive'}">
                            ${u.is_active ? 'Active' : 'Inactive'}
                        </span>
                    </td>
                    <td>
                        <button class="btn-action-sm primary" onclick="viewUser(${u.id})">👤</button>
                        <button class="btn-action-sm success" onclick="openBalanceModal(${u.id}, '${escapeHtml(u.username)}', ${u.wallet_balance})">💰</button>
                        <button class="btn-action-sm warning" onclick="viewTransactions(${u.id})">📊</button>
                        <button class="btn-action-sm primary" onclick="viewMatches(${u.id})">🎯</button>
                        <button class="btn-action-sm ${u.is_active ? 'danger' : 'success'}" onclick="toggleUser(${u.id})">
                            ${u.is_active ? '🔒' : '🔓'}
                        </button>
                    </td>
                </tr>
            `).join('');
        }
        
        // ==============================================
        // PAGINATION
        // ==============================================
        function updatePagination() {
            const totalPages = Math.ceil(state.total / state.limit);
            document.getElementById('prevBtn').disabled = state.currentPage === 0;
            document.getElementById('nextBtn').disabled = state.currentPage >= totalPages - 1;
        }
        
        function prevPage() {
            if (state.currentPage > 0) {
                state.currentPage--;
                loadUsers();
            }
        }
        
        function nextPage() {
            const totalPages = Math.ceil(state.total / state.limit);
            if (state.currentPage < totalPages - 1) {
                state.currentPage++;
                loadUsers();
            }
        }
        
        function debounceSearch() {
            clearTimeout(state.searchTimeout);
            state.searchTimeout = setTimeout(() => {
                state.search = document.getElementById('userSearch').value;
                state.status = document.getElementById('statusFilter').value;
                state.currentPage = 0;
                loadUsers();
            }, 400);
        }
        
        // ==============================================
        // VIEW USER
        // ==============================================
        function viewUser(userId) {
            const modal = document.getElementById('userModal');
            const content = document.getElementById('userModalContent');
            document.getElementById('userModalTitle').textContent = '👤 User Details';
            content.innerHTML = '<div class="loading"><div class="loading-spinner"></div><p>Loading...</p></div>';
            modal.classList.add('active');
            
            fetch(`/api/admin_users.php?action=get&user_id=${userId}`)
                .then(handleApiResponse)
                .then(data => {
                    if (data.success) {
                        renderUserDetails(data.data);
                    } else {
                        content.innerHTML = `<p style="color: #ef4444;">${data.message}</p>`;
                    }
                })
                .catch(() => {
                    content.innerHTML = '<p style="color: #ef4444;">Failed to load user details</p>';
                });
        }
        
        function renderUserDetails(user) {
            const content = document.getElementById('userModalContent');
            
            content.innerHTML = `
                <div class="modal-details">
                    <div class="detail-item">
                        <div class="label">User ID</div>
                        <div class="value">#${user.id}</div>
                    </div>
                    <div class="detail-item">
                        <div class="label">Username</div>
                        <div class="value">${escapeHtml(user.username)}</div>
                    </div>
                    <div class="detail-item">
                        <div class="label">Mobile</div>
                        <div class="value">${escapeHtml(user.mobile)}</div>
                    </div>
                    <div class="detail-item">
                        <div class="label">Email</div>
                        <div class="value">${escapeHtml(user.email || 'N/A')}</div>
                    </div>
                    <div class="detail-item">
                        <div class="label">Wallet Balance</div>
                        <div class="value" style="color: #fbbf24;">₹${parseFloat(user.wallet_balance).toFixed(2)}</div>
                    </div>
                    <div class="detail-item">
                        <div class="label">Total Earnings</div>
                        <div class="value" style="color: #10b981;">₹${parseFloat(user.total_earnings).toFixed(2)}</div>
                    </div>
                    <div class="detail-item">
                        <div class="label">Total Withdrawn</div>
                        <div class="value" style="color: #ef4444;">₹${parseFloat(user.total_withdrawn || 0).toFixed(2)}</div>
                    </div>
                    <div class="detail-item">
                        <div class="label">ELO Rating</div>
                        <div class="value">${user.elo_rating || 1200}</div>
                    </div>
                    <div class="detail-item">
                        <div class="label">Matches Played</div>
                        <div class="value">${user.total_matches_played || 0}</div>
                    </div>
                    <div class="detail-item">
                        <div class="label">Matches Won</div>
                        <div class="value">${user.total_matches_won || 0}</div>
                    </div>
                    <div class="detail-item">
                        <div class="label">KYC Status</div>
                        <div class="value"><span class="status-badge ${user.kyc_status || 'not_submitted'}">${user.kyc_status || 'not_submitted'}</span></div>
                    </div>
                    <div class="detail-item">
                        <div class="label">Status</div>
                        <div class="value"><span class="status-badge ${user.is_active ? 'active' : 'inactive'}">${user.is_active ? 'Active' : 'Inactive'}</span></div>
                    </div>
                    <div class="detail-item">
                        <div class="label">Joined</div>
                        <div class="value">${user.created_at ? new Date(user.created_at).toLocaleString() : 'N/A'}</div>
                    </div>
                    <div class="detail-item">
                        <div class="label">Last Login</div>
                        <div class="value">${user.last_login ? new Date(user.last_login).toLocaleString() : 'N/A'}</div>
                    </div>
                    ${user.pan_number ? `
                    <div class="detail-item">
                        <div class="label">PAN Number</div>
                        <div class="value">${escapeHtml(user.pan_number)}</div>
                    </div>
                    ` : ''}
                    ${user.aadhaar_number ? `
                    <div class="detail-item">
                        <div class="label">Aadhaar Number</div>
                        <div class="value">${escapeHtml(user.aadhaar_number)}</div>
                    </div>
                    ` : ''}
                    ${user.refer_code ? `
                    <div class="detail-item">
                        <div class="label">Referral Code</div>
                        <div class="value">${escapeHtml(user.refer_code)}</div>
                    </div>
                    ` : ''}
                    ${user.referral_earnings ? `
                    <div class="detail-item">
                        <div class="label">Referral Earnings</div>
                        <div class="value">₹${parseFloat(user.referral_earnings).toFixed(2)}</div>
                    </div>
                    ` : ''}
                </div>
            `;
        }
        
        // ==============================================
        // TOGGLE USER STATUS
        // ==============================================
        function toggleUser(userId) {
            if (!confirm('Toggle user status (Block/Unblock)?')) return;
            
            const btn = event ? event.target : document.querySelector(`.btn-action-sm[onclick*="toggleUser(${userId})"]`);
            if (btn) {
                btn.disabled = true;
                btn.textContent = '...';
            }
            
            fetch('/api/admin_users.php?action=toggle_status', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': state.csrfToken
                },
                body: JSON.stringify({
                    user_id: userId,
                    csrf_token: state.csrfToken
                })
            })
            .then(handleApiResponse)
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    loadUsers();
                    loadStats();
                } else {
                    showToast(data.message || 'Failed', 'error');
                }
            })
            .catch(() => {
                showToast('Network error', 'error');
            })
            .finally(() => {
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = btn.classList.contains('danger') ? '🔒' : '🔓';
                }
            });
        }
        
        // ==============================================
        // EDIT BALANCE
        // ==============================================
        function openBalanceModal(userId, username, currentBalance) {
            document.getElementById('balUserId').value = userId;
            document.getElementById('balUserDisplay').value = `#${userId} - ${username}`;
            document.getElementById('balCurrent').value = `₹${currentBalance.toFixed(2)}`;
            document.getElementById('balAmount').value = '';
            document.getElementById('balReason').value = '';
            document.getElementById('balType').value = 'credit';
            document.getElementById('balanceModal').classList.add('active');
        }
        
        function submitBalance() {
            const userId = document.getElementById('balUserId').value;
            const amount = parseFloat(document.getElementById('balAmount').value);
            const type = document.getElementById('balType').value;
            const reason = document.getElementById('balReason').value || 'Admin adjustment';
            
            if (!userId || !amount || amount <= 0) {
                showToast('Please enter a valid amount', 'error');
                return;
            }
            
            const btn = document.querySelector('#balanceModal .btn-confirm');
            btn.disabled = true;
            btn.textContent = 'Processing...';
            
            fetch('/api/admin_users.php?action=update_balance', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': state.csrfToken
                },
                body: JSON.stringify({
                    user_id: parseInt(userId),
                    amount: amount,
                    type: type,
                    reason: reason,
                    csrf_token: state.csrfToken
                })
            })
            .then(handleApiResponse)
            .then(data => {
                if (data.success) {
                    showToast('Balance updated successfully!', 'success');
                    closeModal('balanceModal');
                    loadUsers();
                    loadStats();
                } else {
                    showToast(data.message || 'Update failed', 'error');
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
        // VIEW TRANSACTIONS
        // ==============================================
        function viewTransactions(userId) {
            const modal = document.getElementById('transactionsModal');
            const content = document.getElementById('transactionsContent');
            content.innerHTML = '<div class="loading"><div class="loading-spinner"></div><p>Loading transactions...</p></div>';
            modal.classList.add('active');
            
            fetch(`/api/admin_users.php?action=get_transactions&user_id=${userId}&limit=50`)
                .then(handleApiResponse)
                .then(data => {
                    if (data.success) {
                        renderTransactions(data.data.transactions);
                    } else {
                        content.innerHTML = `<p style="color: #ef4444;">${data.message}</p>`;
                    }
                })
                .catch(() => {
                    content.innerHTML = '<p style="color: #ef4444;">Failed to load transactions</p>';
                });
        }
        
        function renderTransactions(transactions) {
            const content = document.getElementById('transactionsContent');
            
            if (!transactions || transactions.length === 0) {
                content.innerHTML = '<p style="color: #94a3b8; text-align: center; padding: 40px;">No transactions found</p>';
                return;
            }
            
            let html = `
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Amount</th>
                                <th>Type</th>
                                <th>Source</th>
                                <th>Status</th>
                                <th>Balance Before</th>
                                <th>Balance After</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            transactions.forEach(t => {
                html += `
                    <tr>
                        <td>#${t.id}</td>
                        <td style="color: ${t.type === 'credit' ? '#10b981' : '#ef4444'};">${t.type === 'credit' ? '+' : '-'}₹${parseFloat(t.amount).toFixed(2)}</td>
                        <td><span style="text-transform: capitalize;">${t.type}</span></td>
                        <td><span style="text-transform: capitalize;">${t.source}</span></td>
                        <td><span class="status-badge ${t.status}">${t.status}</span></td>
                        <td>₹${parseFloat(t.balance_before).toFixed(2)}</td>
                        <td>₹${parseFloat(t.balance_after).toFixed(2)}</td>
                        <td style="font-size: 12px; color: #94a3b8;">${t.created_at ? new Date(t.created_at).toLocaleString() : 'N/A'}</td>
                    </tr>
                `;
            });
            
            html += `
                        </tbody>
                    </table>
                </div>
            `;
            
            content.innerHTML = html;
        }
        
        // ==============================================
        // VIEW MATCHES
        // ==============================================
        function viewMatches(userId) {
            const modal = document.getElementById('matchesModal');
            const content = document.getElementById('matchesContent');
            content.innerHTML = '<div class="loading"><div class="loading-spinner"></div><p>Loading matches...</p></div>';
            modal.classList.add('active');
            
            fetch(`/api/admin_users.php?action=get_matches&user_id=${userId}&limit=20`)
                .then(handleApiResponse)
                .then(data => {
                    if (data.success) {
                        renderMatches(data.data);
                    } else {
                        content.innerHTML = `<p style="color: #ef4444;">${data.message}</p>`;
                    }
                })
                .catch(() => {
                    content.innerHTML = '<p style="color: #ef4444;">Failed to load matches</p>';
                });
        }
        
        function renderMatches(matches) {
            const content = document.getElementById('matchesContent');
            
            if (!matches || matches.length === 0) {
                content.innerHTML = '<p style="color: #94a3b8; text-align: center; padding: 40px;">No matches found</p>';
                return;
            }
            
            let html = `
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Room</th>
                                <th>Entry Fee</th>
                                <th>Prize Pool</th>
                                <th>Status</th>
                                <th>Players</th>
                                <th>Winner</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            matches.forEach(m => {
                const players = [m.player1_name, m.player2_name, m.player3_name, m.player4_name]
                    .filter(p => p)
                    .join(' vs ');
                
                html += `
                    <tr>
                        <td>#${m.id}</td>
                        <td><code style="background: rgba(255,255,255,0.04); padding: 2px 8px; border-radius: 4px;">${escapeHtml(m.room_code)}</code></td>
                        <td>₹${parseFloat(m.entry_fee).toFixed(2)}</td>
                        <td>₹${parseFloat(m.prize_pool).toFixed(2)}</td>
                        <td><span class="status-badge ${m.status}">${m.status}</span></td>
                        <td>${escapeHtml(players)}</td>
                        <td>${m.winner_name ? escapeHtml(m.winner_name) + ' (₹' + parseFloat(m.winning_amount || 0).toFixed(2) + ')' : '-'}</td>
                        <td style="font-size: 12px; color: #94a3b8;">${m.created_at ? new Date(m.created_at).toLocaleString() : 'N/A'}</td>
                    </tr>
                `;
            });
            
            html += `
                        </tbody>
                    </table>
                </div>
            `;
            
            content.innerHTML = html;
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
