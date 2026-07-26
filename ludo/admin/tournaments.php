<?php
/**
 * ======================================================
 * ADMIN TOURNAMENTS.PHP - Tournament/Room Management
 * Ludo Tournament Platform - Admin Tournament Dashboard
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

// ==============================================
// HANDLE FORM SUBMISSIONS
// ==============================================
$db = Database::getInstance();
$conn = $db->getConnection();

// Add Tournament
if (isset($_POST['add_tournament'])) {
    $name = trim($_POST['name']);
    $entryFee = floatval($_POST['entry_fee']);
    $maxPlayers = intval($_POST['max_players']);
    $platformFee = floatval($_POST['platform_fee']) / 100;
    
    // Calculate prize pool
    $prizePool = ($entryFee * $maxPlayers) - ($entryFee * $maxPlayers * $platformFee);
    $tournamentCode = 'T' . strtoupper(uniqid() . bin2hex(random_bytes(3)));
    
    try {
        $stmt = $conn->prepare("
            INSERT INTO tournaments (
                tournament_code,
                name,
                entry_fee,
                prize_pool,
                platform_fee,
                max_players,
                min_players,
                status,
                created_by,
                created_at,
                updated_at
            ) VALUES (
                :code,
                :name,
                :entry_fee,
                :prize_pool,
                :platform_fee,
                :max_players,
                2,
                'scheduled',
                :admin_id,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            )
        ");
        $stmt->execute([
            ':code' => $tournamentCode,
            ':name' => $name,
            ':entry_fee' => $entryFee,
            ':prize_pool' => $prizePool,
            ':platform_fee' => $entryFee * $maxPlayers * $platformFee,
            ':max_players' => $maxPlayers,
            ':admin_id' => $_SESSION['admin_id']
        ]);
        
        $success = "Tournament '{$name}' created successfully!";
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Delete Tournament
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    try {
        $stmt = $conn->prepare("DELETE FROM tournaments WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $success = "Tournament deleted successfully!";
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Toggle Status
if (isset($_GET['toggle']) && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $status = $_GET['status'] ?? 'active';
    try {
        $stmt = $conn->prepare("
            UPDATE tournaments 
            SET status = :status, updated_at = CURRENT_TIMESTAMP 
            WHERE id = :id
        ");
        $stmt->execute([
            ':status' => $status,
            ':id' => $id
        ]);
        $success = "Tournament status updated!";
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Get all tournaments
$stmt = $conn->query("
    SELECT 
        t.*,
        u.username as created_by_name,
        (SELECT COUNT(*) FROM matches WHERE tournament_id = t.id) as match_count
    FROM tournaments t
    LEFT JOIN users u ON t.created_by = u.id
    ORDER BY t.created_at DESC
");
$tournaments = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tournament Management - Admin Dashboard</title>
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
        
        .btn-primary {
            padding: 10px 24px;
            border: none;
            border-radius: 10px;
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            color: #1a1a2e;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            font-family: inherit;
        }
        
        .btn-primary:hover {
            transform: scale(1.02);
            box-shadow: 0 0 30px rgba(251, 191, 36, 0.2);
        }
        
        .btn-danger {
            padding: 6px 14px;
            border: none;
            border-radius: 6px;
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
            font-weight: 600;
            font-size: 12px;
            cursor: pointer;
            transition: background 0.2s;
            font-family: inherit;
        }
        
        .btn-danger:hover {
            background: rgba(239, 68, 68, 0.3);
        }
        
        .btn-success {
            padding: 6px 14px;
            border: none;
            border-radius: 6px;
            background: rgba(16, 185, 129, 0.2);
            color: #10b981;
            font-weight: 600;
            font-size: 12px;
            cursor: pointer;
            transition: background 0.2s;
            font-family: inherit;
        }
        
        .btn-success:hover {
            background: rgba(16, 185, 129, 0.3);
        }
        
        .btn-warning {
            padding: 6px 14px;
            border: none;
            border-radius: 6px;
            background: rgba(245, 158, 11, 0.2);
            color: #f59e0b;
            font-weight: 600;
            font-size: 12px;
            cursor: pointer;
            transition: background 0.2s;
            font-family: inherit;
        }
        
        .btn-warning:hover {
            background: rgba(245, 158, 11, 0.3);
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
        .modal-box .form-group select {
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
        .modal-box .form-group select:focus {
            outline: none;
            border-color: #7c3aed;
        }
        
        .modal-box .form-group .hint {
            font-size: 11px;
            color: #64748b;
            margin-top: 2px;
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
        
        .status-badge.scheduled { background: rgba(148, 163, 184, 0.15); color: #94a3b8; }
        .status-badge.active { background: rgba(16, 185, 129, 0.15); color: #10b981; }
        .status-badge.in_progress { background: rgba(59, 130, 246, 0.15); color: #3b82f6; }
        .status-badge.completed { background: rgba(16, 185, 129, 0.15); color: #10b981; }
        .status-badge.cancelled { background: rgba(239, 68, 68, 0.15); color: #ef4444; }
        
        .quick-add-btns {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        
        .quick-add-btn {
            padding: 8px 16px;
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.04);
            color: #94a3b8;
            cursor: pointer;
            transition: all 0.2s;
            font-family: inherit;
            font-size: 13px;
        }
        
        .quick-add-btn:hover {
            background: rgba(124, 58, 237, 0.1);
            border-color: rgba(124, 58, 237, 0.2);
            color: #f1f5f9;
        }
        
        @media (max-width: 768px) {
            .admin-header {
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
            <h1>🏆 Tournament Management</h1>
            <div class="admin-header-actions">
                <span>👋 <?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?></span>
                <a href="index.php">← Back to Dashboard</a>
                <a href="settings.php">⚙️ Settings</a>
                <a href="kyc.php">🛡️ KYC</a>
                <a href="withdrawals.php">🏦 Withdrawals</a>
                <a href="disputes.php">📋 Disputes</a>
                <button class="btn-primary" onclick="openAddModal()">➕ New Tournament</button>
                <a href="?logout=1" class="logout">🚪 Logout</a>
            </div>
        </div>
        
        <!-- Quick Add Buttons -->
        <div class="quick-add-btns">
            <button class="quick-add-btn" onclick="quickAdd(10, 4)">₹10 Entry (4 Players)</button>
            <button class="quick-add-btn" onclick="quickAdd(20, 4)">₹20 Entry (4 Players)</button>
            <button class="quick-add-btn" onclick="quickAdd(30, 4)">₹30 Entry (4 Players)</button>
            <button class="quick-add-btn" onclick="quickAdd(50, 4)">₹50 Entry (4 Players)</button>
            <button class="quick-add-btn" onclick="quickAdd(100, 4)">₹100 Entry (4 Players)</button>
            <button class="quick-add-btn" onclick="quickAdd(200, 4)">₹200 Entry (4 Players)</button>
            <button class="quick-add-btn" onclick="quickAdd(500, 4)">₹500 Entry (4 Players)</button>
            <button class="quick-add-btn" onclick="quickAdd(1000, 4)">₹1000 Entry (4 Players)</button>
            <button class="quick-add-btn" onclick="quickAdd(10, 2)">₹10 Entry (2 Players)</button>
            <button class="quick-add-btn" onclick="quickAdd(20, 2)">₹20 Entry (2 Players)</button>
            <button class="quick-add-btn" onclick="quickAdd(50, 2)">₹50 Entry (2 Players)</button>
        </div>
        
        <!-- Tournament Table -->
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Entry Fee</th>
                        <th>Prize Pool</th>
                        <th>Platform Fee</th>
                        <th>Players</th>
                        <th>Matches</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tournaments)): ?>
                        <tr><td colspan="11" style="text-align: center; padding: 40px; color: #94a3b8;">No tournaments created yet. Create your first tournament!</td></tr>
                    <?php else: ?>
                        <?php foreach ($tournaments as $t): ?>
                            <tr>
                                <td>#<?php echo $t['id']; ?></td>
                                <td><code style="background: rgba(255,255,255,0.04); padding: 2px 8px; border-radius: 4px;"><?php echo htmlspecialchars($t['tournament_code']); ?></code></td>
                                <td><?php echo htmlspecialchars($t['name']); ?></td>
                                <td><strong style="color: #fbbf24;">₹<?php echo number_format($t['entry_fee'], 2); ?></strong></td>
                                <td><strong style="color: #10b981;">₹<?php echo number_format($t['prize_pool'], 2); ?></strong></td>
                                <td>₹<?php echo number_format($t['platform_fee'], 2); ?></td>
                                <td><?php echo $t['current_players']; ?>/<?php echo $t['max_players']; ?></td>
                                <td><?php echo $t['match_count']; ?></td>
                                <td><span class="status-badge <?php echo $t['status']; ?>"><?php echo str_replace('_', ' ', $t['status']); ?></span></td>
                                <td style="font-size: 12px; color: #94a3b8;"><?php echo date('d M Y', strtotime($t['created_at'])); ?></td>
                                <td>
                                    <?php if ($t['status'] === 'scheduled'): ?>
                                        <a href="?toggle=1&id=<?php echo $t['id']; ?>&status=active" class="btn-success">Activate</a>
                                    <?php elseif ($t['status'] === 'active'): ?>
                                        <a href="?toggle=1&id=<?php echo $t['id']; ?>&status=in_progress" class="btn-warning">Start</a>
                                    <?php elseif ($t['status'] === 'in_progress'): ?>
                                        <a href="?toggle=1&id=<?php echo $t['id']; ?>&status=completed" class="btn-success">Complete</a>
                                    <?php endif; ?>
                                    <a href="?delete=1&id=<?php echo $t['id']; ?>" class="btn-danger" onclick="return confirm('Delete this tournament?')">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
    </div>
    
    <!-- ==============================================
    MODAL: Add Tournament
    ============================================== -->
    <div class="modal-overlay" id="addModal">
        <div class="modal-box">
            <h2>➕ Create New Tournament</h2>
            <form method="POST" action="">
                <div class="form-group">
                    <label>Tournament Name</label>
                    <input type="text" name="name" placeholder="e.g., ₹10 Ludo Championship" required>
                </div>
                <div class="form-group">
                    <label>Entry Fee (₹)</label>
                    <input type="number" name="entry_fee" id="entryFee" placeholder="10" step="1" min="1" required onchange="calculatePrize()">
                    <div class="hint">Minimum: ₹1</div>
                </div>
                <div class="form-group">
                    <label>Max Players</label>
                    <select name="max_players" id="maxPlayers" onchange="calculatePrize()">
                        <option value="2">2 Players</option>
                        <option value="4" selected>4 Players</option>
                        <option value="6">6 Players</option>
                        <option value="8">8 Players</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Platform Fee (%)</label>
                    <input type="number" name="platform_fee" id="platformFee" value="15" step="1" min="0" max="100" onchange="calculatePrize()">
                    <div class="hint">Default: 15%</div>
                </div>
                <div class="form-group" style="background: rgba(255,255,255,0.02); padding: 12px; border-radius: 8px;">
                    <label>Prize Pool (Auto Calculated)</label>
                    <input type="text" id="prizePoolDisplay" value="₹0.00" disabled style="opacity: 0.8; font-size: 18px; color: #fbbf24;">
                    <div class="hint">Formula: Entry Fee × Players - Platform Fee</div>
                </div>
                <input type="hidden" name="add_tournament" value="1">
                <div class="modal-actions">
                    <button type="submit" class="btn-confirm">✅ Create Tournament</button>
                    <button type="button" class="btn-cancel" onclick="closeModal('addModal')">Cancel</button>
                </div>
            </form>
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
        // MODAL FUNCTIONS
        // ==============================================
        function openAddModal() {
            document.getElementById('addModal').classList.add('active');
            calculatePrize();
        }
        
        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }
        
        // Close modal on overlay click
        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                }
            });
        });
        
        // ==============================================
        // CALCULATE PRIZE POOL
        // ==============================================
        function calculatePrize() {
            const entryFee = parseFloat(document.getElementById('entryFee').value) || 0;
            const maxPlayers = parseInt(document.getElementById('maxPlayers').value) || 4;
            const platformFee = parseFloat(document.getElementById('platformFee').value) || 15;
            
            const totalPool = entryFee * maxPlayers;
            const feeAmount = totalPool * (platformFee / 100);
            const prizePool = totalPool - feeAmount;
            
            document.getElementById('prizePoolDisplay').value = '₹' + prizePool.toFixed(2);
        }
        
        // ==============================================
        // QUICK ADD TOURNAMENT
        // ==============================================
        function quickAdd(entryFee, maxPlayers) {
            // Fill form
            document.getElementById('entryFee').value = entryFee;
            document.getElementById('maxPlayers').value = maxPlayers;
            document.getElementById('platformFee').value = 15;
            
            // Set name
            const nameInput = document.querySelector('input[name="name"]');
            const playerLabel = maxPlayers === 2 ? 'Duo' : maxPlayers + ' Players';
            nameInput.value = '₹' + entryFee + ' ' + playerLabel + ' Tournament';
            
            // Calculate prize
            calculatePrize();
            
            // Open modal
            openAddModal();
        }
        
        // ==============================================
        // TOAST NOTIFICATION
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
        
        // Check for success/error messages from PHP
        <?php if (isset($success)): ?>
            document.addEventListener('DOMContentLoaded', function() {
                showToast('<?php echo addslashes($success); ?>', 'success');
            });
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            document.addEventListener('DOMContentLoaded', function() {
                showToast('<?php echo addslashes($error); ?>', 'error');
            });
        <?php endif; ?>
    </script>
</body>
</html>
