<?php
/**
 * ======================================================
 * ADMIN DISPUTES.PHP - Dispute Management UI
 * Ludo Tournament Platform - Admin Dispute Dashboard
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
$statusFilter = isset($_GET['status']) ? $_GET['status'] : 'open';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dispute Management - Admin Dashboard</title>
    <style>
        /* ==============================================
           DISPUTE ADMIN STYLES
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
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
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
        
        .stat-card .stat-number.open { color: #ef4444; }
        .stat-card .stat-number.investigating { color: #f59e0b; }
        .stat-card .stat-number.resolved { color: #10b981; }
        .stat-card .stat-number.high { color: #ef4444; }
        
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
        
        .filter-btn.priority {
            font-size: 12px;
            padding: 6px 14px;
        }
        
        /* Ticket List */
        .ticket-list {
            display: grid;
            gap: 16px;
        }
        
        .ticket-card {
            background: #1a1a2e;
            border-radius: 14px;
            padding: 20px;
            border: 1px solid rgba(255, 255, 255, 0.04);
            transition: border-color 0.2s;
            border-left: 4px solid #64748b;
        }
        
        .ticket-card.priority-urgent { border-left-color: #ef4444; }
        .ticket-card.priority-high { border-left-color: #f59e0b; }
        .ticket-card.priority-medium { border-left-color: #3b82f6; }
        .ticket-card.priority-low { border-left-color: #10b981; }
        
        .ticket-card:hover {
            border-color: rgba(255, 255, 255, 0.08);
        }
        
        .ticket-card .ticket-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 8px;
        }
        
        .ticket-card .ticket-info {
            display: flex;
            flex-direction: column;
        }
        
        .ticket-card .ticket-subject {
            font-size: 16px;
            font-weight: 700;
            color: #f1f5f9;
        }
        
        .ticket-card .ticket-meta {
            font-size: 13px;
            color: #94a3b8;
        }
        
        .ticket-card .ticket-meta strong {
            color: #f1f5f9;
        }
        
        .ticket-card .status-badge {
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-badge.open { background: rgba(239, 68, 68, 0.15); color: #ef4444; }
        .status-badge.investigating { background: rgba(245, 158, 11, 0.15); color: #f59e0b; }
        .status-badge.resolved { background: rgba(16, 185, 129, 0.15); color: #10b981; }
        .status-badge.closed { background: rgba(148, 163, 184, 0.15); color: #94a3b8; }
        
        .ticket-card .priority-badge {
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .priority-badge.urgent { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
        .priority-badge.high { background: rgba(245, 158, 11, 0.2); color: #f59e0b; }
        .priority-badge.medium { background: rgba(59, 130, 246, 0.2); color: #3b82f6; }
        .priority-badge.low { background: rgba(16, 185, 129, 0.2); color: #10b981; }
        
        .ticket-card .ticket-details {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 12px;
            margin: 12px 0;
        }
        
        .ticket-card .detail-item {
            background: rgba(255, 255, 255, 0.02);
            padding: 8px 12px;
            border-radius: 8px;
        }
        
        .ticket-card .detail-item .label {
            font-size: 11px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .ticket-card .detail-item .value {
            font-size: 14px;
            font-weight: 600;
            color: #f1f5f9;
        }
        
        .ticket-card .ticket-description {
            background: rgba(255, 255, 255, 0.02);
            padding: 12px;
            border-radius: 8px;
            margin: 8px 0;
            color: #94a3b8;
            font-size: 14px;
            line-height: 1.6;
        }
        
        .ticket-card .action-buttons {
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
        
        .btn-action.investigate {
            background: rgba(245, 158, 11, 0.15);
            color: #f59e0b;
        }
        
        .btn-action.investigate:hover {
            background: rgba(245, 158, 11, 0.25);
        }
        
        .btn-action.resolve {
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
        }
        
        .btn-action.resolve:hover {
            background: rgba(16, 185, 129, 0.25);
        }
        
        .btn-action.close {
            background: rgba(148, 163, 184, 0.15);
            color: #94a3b8;
        }
        
        .btn-action.close:hover {
            background: rgba(148, 163, 184, 0.25);
        }
        
        .btn-action.view {
            background: rgba(139, 92, 246, 0.15);
            color: #8b5cf6;
        }
        
        .btn-action.view:hover {
            background: rgba(139, 92, 246, 0.25);
        }
        
        .btn-action.reply {
            background: rgba(59, 130, 246, 0.15);
            color: #3b82f6;
        }
        
        .btn-action.reply:hover {
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
            max-width: 650px;
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
        
        .modal-box .form-group select option {
            background: #1a1a2e;
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
        
        .modal-actions .btn-success {
            background: rgba(16, 185, 129, 0.2);
            color: #10b981;
        }
        
        .modal-actions .btn-success:hover {
            background: rgba(16, 185, 129, 0.3);
        }
        
        /* Message Thread */
        .message-thread {
            max-height: 300px;
            overflow-y: auto;
            margin: 12px 0;
            padding: 12px;
            background: rgba(255, 255, 255, 0.02);
            border-radius: 8px;
        }
        
        .message-item {
            padding: 10px 12px;
            margin-bottom: 8px;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.02);
        }
        
        .message-item.admin {
            background: rgba(124, 58, 237, 0.08);
            border-left: 3px solid #7c3aed;
        }
        
        .message-item .msg-header {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            color: #94a3b8;
            margin-bottom: 4px;
        }
        
        .message-item .msg-header strong {
            color: #f1f5f9;
        }
        
        .message-item .msg-body {
            font-size: 14px;
            color: #f1f5f9;
            line-height: 1.5;
        }
        
        .message-item .msg-body .screenshot-link {
            display: inline-block;
            margin-top: 6px;
            padding: 4px 12px;
            background: rgba(255, 255, 255, 0.04);
            border-radius: 4px;
            font-size: 12px;
            color: #3b82f6;
            text-decoration: none;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .message-item .msg-body .screenshot-link:hover {
            background: rgba(255, 255, 255, 0.08);
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
        
        .zoom-modal .modal-actions {
            justify-content: center;
            margin-top: 16px;
        }
        
        .zoom-modal .modal-actions button {
            flex: none;
            padding: 10px 30px;
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
            .ticket-card .ticket-details {
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
            <h1>📋 Dispute & Ticket Management</h1>
            <div class="admin-header-actions">
                <span>👋 <?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?></span>
                <a href="index.php">← Back to Dashboard</a>
                <a href="settings.php">⚙️ Settings</a>
                <a href="kyc.php">🛡️ KYC</a>
                <a href="withdrawals.php">🏦 Withdrawals</a>
                <a href="?logout=1" class="logout">🚪 Logout</a>
            </div>
        </div>
        
        <!-- Stats Bar -->
        <div class="stats-bar" id="statsBar">
            <div class="stat-card">
                <div class="stat-number open" id="statOpen">...</div>
                <div class="stat-label">Open</div>
            </div>
            <div class="stat-card">
                <div class="stat-number investigating" id="statInvestigating">...</div>
                <div class="stat-label">Investigating</div>
            </div>
            <div class="stat-card">
                <div class="stat-number resolved" id="statResolved">...</div>
                <div class="stat-label">Resolved</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" id="statClosed">...</div>
                <div class="stat-label">Closed</div>
            </div>
            <div class="stat-card">
                <div class="stat-number high" id="statHighPriority">...</div>
                <div class="stat-label">High Priority</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" id="statTotalRefunds" style="color: #fbbf24;">...</div>
                <div class="stat-label">Total Refunds</div>
            </div>
        </div>
        
        <!-- Filter Bar -->
        <div class="filter-bar">
            <button class="filter-btn <?php echo $statusFilter === 'open' ? 'active' : ''; ?>" data-status="open">🟡 Open</button>
            <button class="filter-btn <?php echo $statusFilter === 'investigating' ? 'active' : ''; ?>" data-status="investigating">🔍 Investigating</button>
            <button class="filter-btn <?php echo $statusFilter === 'resolved' ? 'active' : ''; ?>" data-status="resolved">✅ Resolved</button>
            <button class="filter-btn <?php echo $statusFilter === 'closed' ? 'active' : ''; ?>" data-status="closed">🔒 Closed</button>
            <button class="filter-btn <?php echo $statusFilter === 'all' ? 'active' : ''; ?>" data-status="all">📋 All</button>
        </div>
        
        <!-- Priority Filter -->
        <div class="filter-bar" style="margin-top: -8px;">
            <button class="filter-btn priority" data-priority="">All Priorities</button>
            <button class="filter-btn priority" data-priority="urgent">🔴 Urgent</button>
            <button class="filter-btn priority" data-priority="high">🟠 High</button>
            <button class="filter-btn priority" data-priority="medium">🔵 Medium</button>
            <button class="filter-btn priority" data-priority="low">🟢 Low</button>
        </div>
        
        <!-- Ticket List -->
        <div class="ticket-list" id="ticketList">
            <div class="loading">
                <div class="loading-spinner"></div>
                <p>Loading tickets...</p>
            </div>
        </div>
        
        <!-- Updated Pagination with Records Per Page Dropdown -->
        <div class="pagination-controls">
            <div class="pagination-left">
                <span id="paginationInfo" style="color: #94a3b8; font-size: 14px;"></span>
                <select id="ticketLimit" onchange="state.limit = parseInt(this.value); state.currentPage = 0; loadTickets();">
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
    MODAL: View & Reply
    ============================================== -->
    <div class="modal-overlay" id="viewModal">
        <div class="modal-box" style="max-width: 700px;">
            <h2>📄 Ticket Details</h2>
            <div id="viewContent">
                <div class="loading">Loading...</div>
            </div>
            <div style="margin-top: 16px; border-top: 1px solid rgba(255,255,255,0.04); padding-top: 16px;">
                <h4>💬 Reply to Ticket</h4>
                <div class="form-group">
                    <textarea id="replyMessage" placeholder="Type your reply..." rows="3"></textarea>
                </div>
                <button class="btn-action reply" onclick="sendReply()" style="width: 100%; padding: 10px;">📤 Send Reply</button>
            </div>
            <div class="modal-actions">
                <button class="btn-cancel" onclick="closeModal('viewModal')">Close</button>
            </div>
        </div>
    </div>
    
    <!-- ==============================================
    MODAL: Resolve Ticket
    ============================================== -->
    <div class="modal-overlay" id="resolveModal">
        <div class="modal-box">
            <h2>✅ Resolve Ticket</h2>
            <input type="hidden" id="resolveTicketId" value="">
            <div class="form-group">
                <label>Resolution Type</label>
                <select id="resolutionType">
                    <option value="winner_declared">🏆 Declare Winner</option>
                    <option value="refund">💰 Refund User</option>
                    <option value="cancelled">❌ Cancel Match</option>
                    <option value="replay">🔄 Replay Match</option>
                    <option value="no_action">⏭️ No Action</option>
                </select>
            </div>
            <div class="form-group" id="winnerField">
                <label>Winner User ID</label>
                <input type="number" id="winnerId" placeholder="Enter winner user ID">
            </div>
            <div class="form-group" id="refundField" style="display: none;">
                <label>Refund Amount (₹)</label>
                <input type="number" id="refundAmount" placeholder="Enter refund amount" step="0.01">
            </div>
            <div class="form-group">
                <label>Resolution Notes</label>
                <textarea id="resolutionNotes" placeholder="Explain the resolution..."></textarea>
            </div>
            <div class="modal-actions">
                <button class="btn-confirm" onclick="confirmResolve()">✅ Resolve</button>
                <button class="btn-cancel" onclick="closeModal('resolveModal')">Cancel</button>
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
    JAVASCRIPT - UPDATED WITH 401 HANDLER, PAGINATION & IMAGE ZOOM
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
            priority: '',
            csrfToken: '<?php echo $csrf_token; ?>',
            currentTicketId: null
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
            loadTickets();
            
            document.querySelectorAll('.filter-btn[data-status]').forEach(btn => {
                btn.addEventListener('click', function() {
                    document.querySelectorAll('.filter-btn[data-status]').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    state.status = this.dataset.status;
                    state.currentPage = 0;
                    loadTickets();
                });
            });
            
            document.querySelectorAll('.filter-btn[data-priority]').forEach(btn => {
                btn.addEventListener('click', function() {
                    document.querySelectorAll('.filter-btn[data-priority]').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    state.priority = this.dataset.priority;
                    state.currentPage = 0;
                    loadTickets();
                });
            });
            
            // Toggle fields based on resolution type
            document.getElementById('resolutionType').addEventListener('change', function() {
                document.getElementById('winnerField').style.display = this.value === 'winner_declared' ? 'block' : 'none';
                document.getElementById('refundField').style.display = this.value === 'refund' ? 'block' : 'none';
            });
        });
        
        // ==============================================
        // LOAD STATS
        // ==============================================
        function loadStats() {
            fetch('/api/admin_disputes.php?action=get_stats')
                .then(handleApiResponse)
                .then(data => {
                    if (data.success) {
                        document.getElementById('statOpen').textContent = data.data.open || 0;
                        document.getElementById('statInvestigating').textContent = data.data.investigating || 0;
                        document.getElementById('statResolved').textContent = data.data.resolved || 0;
                        document.getElementById('statClosed').textContent = data.data.closed || 0;
                        document.getElementById('statHighPriority').textContent = data.data.high_priority || 0;
                        document.getElementById('statTotalRefunds').textContent = '₹' + (data.data.total_refunds || 0).toFixed(2);
                    }
                })
                .catch(() => {});
        }
        
        // ==============================================
        // LOAD TICKETS - UPDATED WITH state.limit
        // ==============================================
        function loadTickets() {
            const listEl = document.getElementById('ticketList');
            listEl.innerHTML = '<div class="loading"><div class="loading-spinner"></div><p>Loading tickets...</p></div>';
            
            const offset = state.currentPage * state.limit;
            const status = state.status === 'all' ? '' : state.status;
            
            let url = `/api/admin_disputes.php?action=list&status=${status}&offset=${offset}&limit=${state.limit}`;
            if (state.priority) {
                url += `&priority=${state.priority}`;
            }
            
            fetch(url)
                .then(handleApiResponse)
                .then(data => {
                    if (data.success) {
                        state.total = data.data.total;
                        renderTickets(data.data.tickets);
                        updatePagination();
                    } else {
                        listEl.innerHTML = `<div class="no-results"><div class="icon">❌</div><h3>Error</h3><p>${data.message}</p></div>`;
                    }
                })
                .catch(() => {
                    listEl.innerHTML = '<div class="no-results"><div class="icon">⚠️</div><h3>Network Error</h3><p>Failed to load tickets</p></div>';
                });
        }
        
        function renderTickets(tickets) {
            const listEl = document.getElementById('ticketList');
            
            if (!tickets || tickets.length === 0) {
                listEl.innerHTML = `
                    <div class="no-results">
                        <div class="icon">📭</div>
                        <h3>No Tickets</h3>
                        <p>${state.status === 'open' ? 'No open tickets.' : 'No tickets found.'}</p>
                    </div>
                `;
                return;
            }
            
            listEl.innerHTML = tickets.map(t => `
                <div class="ticket-card priority-${t.priority}">
                    <div class="ticket-header">
                        <div class="ticket-info">
                            <span class="ticket-subject">#${escapeHtml(t.ticket_number)} - ${escapeHtml(t.subject)}</span>
                            <span class="ticket-meta">
                                👤 <strong>${escapeHtml(t.user_name || 'Unknown')}</strong> 
                                ${t.opponent_name ? `vs <strong>${escapeHtml(t.opponent_name)}</strong>` : ''}
                                • 📱 ${escapeHtml(t.user_mobile)}
                                • 🎯 Room: ${escapeHtml(t.room_code || 'N/A')}
                            </span>
                            <span class="ticket-meta">
                                💰 Entry: ₹${parseFloat(t.entry_fee || 0).toFixed(2)} 
                                • 🏆 Prize: ₹${parseFloat(t.prize_pool || 0).toFixed(2)}
                                • 📝 Messages: ${t.message_count || 0}
                            </span>
                        </div>
                        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                            <span class="priority-badge ${t.priority}">${t.priority.toUpperCase()}</span>
                            <span class="status-badge ${t.status}">${t.status.toUpperCase()}</span>
                        </div>
                    </div>
                    
                    <div class="ticket-description">
                        ${escapeHtml(t.description)}
                    </div>
                    
                    <div class="ticket-details">
                        <div class="detail-item">
                            <div class="label">Created</div>
                            <div class="value">${t.created_at ? new Date(t.created_at).toLocaleString() : 'N/A'}</div>
                        </div>
                        <div class="detail-item">
                            <div class="label">Match Status</div>
                            <div class="value">${t.match_status || 'N/A'}</div>
                        </div>
                        <div class="detail-item">
                            <div class="label">Resolution</div>
                            <div class="value">${t.resolution_type ? t.resolution_type.toUpperCase() : 'Pending'}</div>
                        </div>
                    </div>
                    
                    <div class="action-buttons">
                        <button class="btn-action view" onclick="viewTicket(${t.id})">👁️ View & Reply</button>
                        ${t.status === 'open' ? `
                            <button class="btn-action investigate" onclick="investigateTicket(${t.id})">🔍 Investigate</button>
                        ` : ''}
                        ${['open', 'investigating'].includes(t.status) ? `
                            <button class="btn-action resolve" onclick="openResolveModal(${t.id})">✅ Resolve</button>
                        ` : ''}
                        ${t.status === 'resolved' ? `
                            <button class="btn-action close" onclick="closeTicket(${t.id})">🔒 Close</button>
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
                `Showing ${offset + 1} - ${Math.min(offset + state.limit, state.total)} of ${state.total} tickets`;
            
            document.getElementById('prevBtn').disabled = state.currentPage === 0;
            document.getElementById('nextBtn').disabled = state.currentPage >= totalPages - 1;
        }
        
        function prevPage() {
            if (state.currentPage > 0) {
                state.currentPage--;
                loadTickets();
            }
        }
        
        function nextPage() {
            const totalPages = Math.ceil(state.total / state.limit);
            if (state.currentPage < totalPages - 1) {
                state.currentPage++;
                loadTickets();
            }
        }
        
        // ==============================================
        // VIEW TICKET - UPDATED WITH 401 HANDLER
        // ==============================================
        function viewTicket(id) {
            state.currentTicketId = id;
            const modal = document.getElementById('viewModal');
            const content = document.getElementById('viewContent');
            content.innerHTML = '<div class="loading"><div class="loading-spinner"></div><p>Loading...</p></div>';
            modal.classList.add('active');
            
            // Load ticket details
            fetch(`/api/admin_disputes.php?action=get&id=${id}`)
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
            
            // Load messages
            loadMessages(id);
        }
        
        function renderViewDetails(t) {
            const content = document.getElementById('viewContent');
            
            content.innerHTML = `
                <div style="display: grid; gap: 10px;">
                    <div><strong>Ticket:</strong> #${escapeHtml(t.ticket_number)}</div>
                    <div><strong>Subject:</strong> ${escapeHtml(t.subject)}</div>
                    <div><strong>User:</strong> ${escapeHtml(t.user_name)} (#${t.user_id})</div>
                    ${t.opponent_name ? `<div><strong>Opponent:</strong> ${escapeHtml(t.opponent_name)}</div>` : ''}
                    <div><strong>Priority:</strong> <span class="priority-badge ${t.priority}">${t.priority.toUpperCase()}</span></div>
                    <div><strong>Status:</strong> <span class="status-badge ${t.status}">${t.status.toUpperCase()}</span></div>
                    <div><strong>Match Room:</strong> ${escapeHtml(t.room_code || 'N/A')}</div>
                    <div><strong>Entry Fee:</strong> ₹${parseFloat(t.entry_fee || 0).toFixed(2)}</div>
                    <div><strong>Prize Pool:</strong> ₹${parseFloat(t.prize_pool || 0).toFixed(2)}</div>
                    <div><strong>Match Status:</strong> ${t.match_status || 'N/A'}</div>
                    ${t.winner_name ? `<div><strong>Match Winner:</strong> ${escapeHtml(t.winner_name)} (₹${parseFloat(t.winning_amount || 0).toFixed(2)})</div>` : ''}
                    <div><strong>Created:</strong> ${t.created_at ? new Date(t.created_at).toLocaleString() : 'N/A'}</div>
                    ${t.resolved_at ? `<div><strong>Resolved At:</strong> ${new Date(t.resolved_at).toLocaleString()}</div>` : ''}
                    ${t.resolution_type ? `<div><strong>Resolution Type:</strong> ${t.resolution_type.toUpperCase()}</div>` : ''}
                    ${t.resolution_notes ? `<div><strong>Resolution Notes:</strong> ${escapeHtml(t.resolution_notes)}</div>` : ''}
                    ${t.refund_amount ? `<div><strong>Refund Amount:</strong> ₹${parseFloat(t.refund_amount).toFixed(2)}</div>` : ''}
                    ${t.admin_notes ? `<div><strong>Admin Notes:</strong> ${escapeHtml(t.admin_notes)}</div>` : ''}
                </div>
                <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid rgba(255,255,255,0.04);">
                    <h4>💬 Message Thread</h4>
                    <div class="message-thread" id="messageThread">
                        <div class="loading" style="padding: 20px;">Loading messages...</div>
                    </div>
                </div>
            `;
        }
        
        // ==============================================
        // LOAD MESSAGES - UPDATED WITH 401 HANDLER & IMAGE ZOOM
        // ==============================================
        function loadMessages(ticketId) {
            fetch(`/api/admin_disputes.php?action=get_messages&ticket_id=${ticketId}`)
                .then(handleApiResponse)
                .then(data => {
                    const thread = document.getElementById('messageThread');
                    if (!thread) return;
                    
                    if (data.success && data.data.length > 0) {
                        thread.innerHTML = data.data.map(m => `
                            <div class="message-item ${m.is_admin ? 'admin' : ''}">
                                <div class="msg-header">
                                    <strong>${m.is_admin ? '👑 Admin' : escapeHtml(m.username)}</strong>
                                    <span>${m.created_at ? new Date(m.created_at).toLocaleString() : 'N/A'}</span>
                                </div>
                                <div class="msg-body">
                                    ${escapeHtml(m.message)}
                                    ${m.screenshot_url ? `<br><span onclick="zoomImage('${escapeHtml(m.screenshot_url)}')" class="screenshot-link">📎 View Screenshot (Click to Zoom)</span>` : ''}
                                </div>
                            </div>
                        `).join('');
                    } else {
                        thread.innerHTML = '<p style="color: #94a3b8; text-align: center; padding: 20px;">No messages yet.</p>';
                    }
                })
                .catch(() => {
                    const thread = document.getElementById('messageThread');
                    if (thread) {
                        thread.innerHTML = '<p style="color: #ef4444; text-align: center; padding: 20px;">Failed to load messages</p>';
                    }
                });
        }
        
        // ==============================================
        // SEND REPLY - UPDATED WITH 401 HANDLER
        // ==============================================
        function sendReply() {
            const message = document.getElementById('replyMessage').value.trim();
            if (!message) {
                showToast('Please enter a reply message', 'error');
                return;
            }
            
            if (!state.currentTicketId) {
                showToast('No ticket selected', 'error');
                return;
            }
            
            const btn = document.querySelector('.btn-action.reply');
            btn.disabled = true;
            btn.textContent = 'Sending...';
            
            fetch('/api/admin_disputes.php?action=add_message', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': state.csrfToken
                },
                body: JSON.stringify({
                    ticket_id: state.currentTicketId,
                    message: message,
                    csrf_token: state.csrfToken
                })
            })
            .then(handleApiResponse)
            .then(data => {
                if (data.success) {
                    showToast('Reply sent successfully', 'success');
                    document.getElementById('replyMessage').value = '';
                    loadMessages(state.currentTicketId);
                    loadTickets();
                } else {
                    showToast(data.message || 'Failed to send reply', 'error');
                }
            })
            .catch(() => {
                showToast('Network error', 'error');
            })
            .finally(() => {
                btn.disabled = false;
                btn.textContent = '📤 Send Reply';
            });
        }
        
        // ==============================================
        // INVESTIGATE TICKET - UPDATED WITH 401 HANDLER
        // ==============================================
        function investigateTicket(id) {
            if (!confirm('Mark this ticket as under investigation?')) return;
            
            const btn = event ? event.target : document.querySelector(`.btn-action.investigate`);
            if (btn) {
                btn.disabled = true;
                btn.textContent = 'Processing...';
            }
            
            fetch('/api/admin_disputes.php?action=investigate', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': state.csrfToken
                },
                body: JSON.stringify({
                    id: id,
                    notes: 'Investigation started by admin',
                    csrf_token: state.csrfToken
                })
            })
            .then(handleApiResponse)
            .then(data => {
                if (data.success) {
                    showToast('Ticket is now under investigation', 'success');
                    loadTickets();
                    loadStats();
                } else {
                    showToast(data.message || 'Failed to investigate', 'error');
                }
            })
            .catch(() => {
                showToast('Network error', 'error');
            })
            .finally(() => {
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = '🔍 Investigate';
                }
            });
        }
        
        // ==============================================
        // RESOLVE TICKET - UPDATED WITH 401 HANDLER
        // ==============================================
        function openResolveModal(id) {
            document.getElementById('resolveTicketId').value = id;
            document.getElementById('resolutionType').value = 'no_action';
            document.getElementById('winnerId').value = '';
            document.getElementById('refundAmount').value = '';
            document.getElementById('resolutionNotes').value = '';
            document.getElementById('winnerField').style.display = 'none';
            document.getElementById('refundField').style.display = 'none';
            document.getElementById('resolveModal').classList.add('active');
        }
        
        function confirmResolve() {
            const id = document.getElementById('resolveTicketId').value;
            const resolutionType = document.getElementById('resolutionType').value;
            const resolutionNotes = document.getElementById('resolutionNotes').value.trim();
            const winnerId = document.getElementById('winnerId').value;
            const refundAmount = document.getElementById('refundAmount').value;
            
            if (resolutionType === 'winner_declared' && !winnerId) {
                showToast('Please enter winner user ID', 'error');
                return;
            }
            
            if (resolutionType === 'refund' && (!refundAmount || parseFloat(refundAmount) <= 0)) {
                showToast('Please enter a valid refund amount', 'error');
                return;
            }
            
            const payload = {
                id: parseInt(id),
                resolution_type: resolutionType,
                resolution_notes: resolutionNotes,
                csrf_token: state.csrfToken
            };
            
            if (resolutionType === 'winner_declared') {
                payload.winner_id = parseInt(winnerId);
            }
            
            if (resolutionType === 'refund') {
                payload.refund_amount = parseFloat(refundAmount);
            }
            
            const btn = document.querySelector('#resolveModal .btn-confirm');
            btn.disabled = true;
            btn.textContent = 'Processing...';
            
            fetch('/api/admin_disputes.php?action=resolve', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': state.csrfToken
                },
                body: JSON.stringify(payload)
            })
            .then(handleApiResponse)
            .then(data => {
                if (data.success) {
                    showToast('Ticket resolved successfully', 'success');
                    closeModal('resolveModal');
                    loadTickets();
                    loadStats();
                } else {
                    showToast(data.message || 'Failed to resolve ticket', 'error');
                }
            })
            .catch(() => {
                showToast('Network error', 'error');
            })
            .finally(() => {
                btn.disabled = false;
                btn.textContent = '✅ Resolve';
            });
        }
        
        // ==============================================
        // CLOSE TICKET - UPDATED WITH 401 HANDLER
        // ==============================================
        function closeTicket(id) {
            if (!confirm('Close this resolved ticket?')) return;
            
            const btn = event ? event.target : document.querySelector(`.btn-action.close`);
            if (btn) {
                btn.disabled = true;
                btn.textContent = 'Processing...';
            }
            
            fetch('/api/admin_disputes.php?action=close', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': state.csrfToken
                },
                body: JSON.stringify({
                    id: id,
                    notes: 'Ticket closed by admin',
                    csrf_token: state.csrfToken
                })
            })
            .then(handleApiResponse)
            .then(data => {
                if (data.success) {
                    showToast('Ticket closed successfully', 'success');
                    loadTickets();
                    loadStats();
                } else {
                    showToast(data.message || 'Failed to close ticket', 'error');
                }
            })
            .catch(() => {
                showToast('Network error', 'error');
            })
            .finally(() => {
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = '🔒 Close';
                }
            });
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
