<?php
/**
 * ======================================================
 * ADMIN TOURNAMENTS.PHP - Tournament Management (FIXED)
 * Ludo Tournament Platform - Admin Tournament Dashboard
 * Version: 3.0.0 - API PATHS FIXED + ALL BUGS FIXED
 * ======================================================
 */

if (!defined('BASE_PATH')) { define('BASE_PATH', dirname(__DIR__)); }
require_once dirname(__DIR__) . '/config/db.php';
SessionManager::init();

function validateAdminSession() {
    if (!SessionManager::has('admin_id') || !SessionManager::has('admin_token')) return false;
    try {
        $db = Database::getInstance(); $conn = $db->getConnection();
        $stmt = $conn->prepare("SELECT id FROM sessions WHERE user_id = :aid AND session_token = :token AND is_active = 1 AND expires_at > NOW()");
        $stmt->execute([':aid' => SessionManager::get('admin_id'), ':token' => SessionManager::get('admin_token')]);
        return $stmt->fetch() !== false;
    } catch (Exception $e) { return false; }
}
if (!validateAdminSession()) { SessionManager::destroy(); header('Location: index.php'); exit; }

$csrf_token = CSRFToken::generate();
$basePath = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');

try {
    $db = Database::getInstance(); $conn = $db->getConnection();
} catch (Exception $e) { die("Database connection failed"); }

$success = ''; $error = '';

// Add Tournament
if (isset($_POST['add_tournament'])) {
    try {
        $name = trim($_POST['name'] ?? '');
        $entryFee = floatval($_POST['entry_fee'] ?? 0);
        $maxPlayers = intval($_POST['max_players'] ?? 4);
        $platformFeePercent = floatval($_POST['platform_fee'] ?? 15);
        
        if (empty($name)) throw new Exception('Name required');
        if ($entryFee <= 0 || $entryFee > 100000) throw new Exception('Invalid entry fee');
        if ($maxPlayers < 2 || $maxPlayers > 8) throw new Exception('Invalid max players');
        
        $totalPool = $entryFee * $maxPlayers;
        $platformFeeAmount = $totalPool * ($platformFeePercent / 100);
        $prizePool = $totalPool - $platformFeeAmount;
        $tournamentCode = 'T' . strtoupper(bin2hex(random_bytes(6)));
        
        $db->beginTransaction();
        $stmt = $conn->prepare("INSERT INTO tournaments (tournament_code, name, entry_fee, prize_pool, platform_fee, max_players, min_players, status, created_by, created_at, updated_at) VALUES (:code, :name, :fee, :prize, :pf, :max, 2, 'scheduled', :admin, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $stmt->execute([':code' => $tournamentCode, ':name' => $name, ':fee' => $entryFee, ':prize' => $prizePool, ':pf' => $platformFeeAmount, ':max' => $maxPlayers, ':admin' => SessionManager::get('admin_id')]);
        $db->commit();
        $success = "✅ Tournament '{$name}' created!";
    } catch (Exception $e) { if ($db->inTransaction()) $db->rollback(); $error = "❌ " . $e->getMessage(); }
}

// Delete
if (isset($_GET['delete']) && isset($_GET['id'])) {
    try {
        $id = intval($_GET['id']);
        $db->beginTransaction();
        $stmt = $conn->prepare("SELECT COUNT(*) FROM matches WHERE tournament_id = :id AND status NOT IN ('completed','cancelled')");
        $stmt->execute([':id' => $id]);
        if (intval($stmt->fetchColumn()) > 0) throw new Exception('Cannot delete tournament with active matches');
        $stmt = $conn->prepare("DELETE FROM tournaments WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $db->commit();
        $success = "✅ Tournament deleted!";
    } catch (Exception $e) { if ($db->inTransaction()) $db->rollback(); $error = "❌ " . $e->getMessage(); }
}

// Toggle Status
if (isset($_GET['toggle']) && isset($_GET['id'])) {
    try {
        $id = intval($_GET['id']); $status = $_GET['status'] ?? null;
        $validStatuses = ['scheduled', 'active', 'in_progress', 'completed', 'cancelled'];
        if (!in_array($status, $validStatuses)) throw new Exception('Invalid status');
        $db->beginTransaction();
        $stmt = $conn->prepare("UPDATE tournaments SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([':status' => $status, ':id' => $id]);
        $db->commit();
        $success = "✅ Status updated!";
    } catch (Exception $e) { if ($db->inTransaction()) $db->rollback(); $error = "❌ " . $e->getMessage(); }
}

// Fetch tournaments
$tournaments = [];
try {
    $stmt = $conn->query("SELECT t.*, u.username as created_by_name, (SELECT COUNT(*) FROM matches WHERE tournament_id = t.id) as match_count, (SELECT COUNT(*) FROM matches WHERE tournament_id = t.id AND status IN ('playing','ready')) as active_matches FROM tournaments t LEFT JOIN users u ON t.created_by = u.id ORDER BY t.created_at DESC");
    $tournaments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $tournaments = []; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tournaments - Admin</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}body{font-family:'Inter',sans-serif;background:#0a0e1a;color:#f1f5f9;min-height:100vh}
        .admin-container{max-width:1400px;margin:0 auto;padding:20px}
        .admin-header{display:flex;justify-content:space-between;align-items:center;padding:16px 0;border-bottom:1px solid rgba(255,255,255,0.06);margin-bottom:24px;flex-wrap:wrap;gap:12px}
        .admin-header h1{font-size:24px;font-weight:700;background:linear-gradient(135deg,#fbbf24,#f59e0b);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
        .admin-header-actions{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
        .admin-header-actions a,.admin-header-actions button{color:#94a3b8;text-decoration:none;font-weight:600;font-size:14px;padding:8px 16px;border:1px solid rgba(255,255,255,0.06);border-radius:8px;background:transparent;cursor:pointer;font-family:inherit}
        .admin-header-actions a.logout{color:#ef4444;border-color:rgba(239,68,68,0.2)}
        .btn-primary{padding:10px 24px;border:none;border-radius:10px;background:linear-gradient(135deg,#fbbf24,#f59e0b);color:#1a1a2e;font-weight:700;font-size:14px;cursor:pointer;font-family:inherit}
        .btn-danger{padding:6px 14px;border:none;border-radius:6px;background:rgba(239,68,68,0.2);color:#ef4444;font-weight:600;font-size:12px;cursor:pointer;font-family:inherit}
        .btn-success{padding:6px 14px;border:none;border-radius:6px;background:rgba(16,185,129,0.2);color:#10b981;font-weight:600;font-size:12px;cursor:pointer;font-family:inherit}
        .btn-warning{padding:6px 14px;border:none;border-radius:6px;background:rgba(245,158,11,0.2);color:#f59e0b;font-weight:600;font-size:12px;cursor:pointer;font-family:inherit}
        .modal-overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);backdrop-filter:blur(8px);z-index:1000;justify-content:center;align-items:center;padding:20px}
        .modal-overlay.active{display:flex}
        .modal-box{background:#1a1a2e;padding:32px;border-radius:16px;max-width:550px;width:100%;border:1px solid rgba(255,255,255,0.06)}
        .modal-box h2{font-size:20px;font-weight:700;margin-bottom:16px}
        .form-group{margin-bottom:14px}.form-group label{display:block;font-size:13px;font-weight:600;color:#94a3b8;margin-bottom:4px}
        .form-group input,.form-group select{width:100%;padding:10px 14px;border:1px solid rgba(255,255,255,0.06);border-radius:8px;background:rgba(255,255,255,0.04);color:#f1f5f9;font-size:14px;font-family:inherit}
        .form-group input:focus,.form-group select:focus{outline:none;border-color:#7c3aed}
        .modal-actions{display:flex;gap:12px;margin-top:20px}
        .modal-actions button{flex:1;padding:12px;border:none;border-radius:8px;font-weight:600;font-size:14px;cursor:pointer;font-family:inherit}
        .modal-actions .btn-confirm{background:linear-gradient(135deg,#fbbf24,#f59e0b);color:#1a1a2e}
        .modal-actions .btn-cancel{background:rgba(255,255,255,0.06);color:#94a3b8}
        .table-container{background:#1a1a2e;border-radius:14px;border:1px solid rgba(255,255,255,0.04);overflow-x:auto}
        table{width:100%;border-collapse:collapse;font-size:14px}table th{padding:12px 16px;text-align:left;color:#94a3b8;font-weight:600;font-size:12px;text-transform:uppercase}table td{padding:12px 16px;border-bottom:1px solid rgba(255,255,255,0.02)}
        .status-badge{display:inline-block;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:600}
        .status-badge.scheduled{background:rgba(148,163,184,0.15);color:#94a3b8}
        .status-badge.active{background:rgba(16,185,129,0.15);color:#10b981}
        .status-badge.in_progress{background:rgba(59,130,246,0.15);color:#3b82f6}
        .status-badge.completed{background:rgba(16,185,129,0.15);color:#10b981}
        .status-badge.cancelled{background:rgba(239,68,68,0.15);color:#ef4444}
        .toast{position:fixed;bottom:24px;right:24px;padding:14px 24px;border-radius:12px;font-weight:600;font-size:14px;z-index:2000;transform:translateY(100px);opacity:0;transition:all 0.4s ease}
        .toast.show{transform:translateY(0);opacity:1}.toast.success{background:rgba(16,185,129,0.2);color:#10b981}.toast.error{background:rgba(239,68,68,0.2);color:#ef4444}
    </style>
</head>
<body>
    <div class="admin-container">
        <div class="admin-header">
            <h1>🏆 Tournament Management</h1>
            <div class="admin-header-actions">
                <span>👋 <?php echo htmlspecialchars(SessionManager::get('admin_username', 'Admin')); ?></span>
                <a href="index.php">← Dashboard</a>
                <button class="btn-primary" onclick="openAddModal()">➕ New Tournament</button>
                <a href="?logout=1" class="logout">🚪 Logout</a>
            </div>
        </div>
        
        <?php if($success): ?><div style="background:rgba(16,185,129,0.1);color:#10b981;padding:12px;border-radius:8px;margin-bottom:16px"><?php echo $success; ?></div><?php endif; ?>
        <?php if($error): ?><div style="background:rgba(239,68,68,0.1);color:#ef4444;padding:12px;border-radius:8px;margin-bottom:16px"><?php echo $error; ?></div><?php endif; ?>
        
        <div class="table-container">
            <table><thead><tr><th>ID</th><th>Code</th><th>Name</th><th>Entry</th><th>Prize</th><th>Max Players</th><th>Matches</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
            <tbody><?php if(empty($tournaments)): ?><tr><td colspan="10" style="text-align:center;padding:40px;color:#94a3b8">No tournaments</td></tr>
            <?php else: foreach($tournaments as $t): ?>
                <tr><td>#<?php echo $t['id']; ?></td><td><code><?php echo htmlspecialchars($t['tournament_code']); ?></code></td><td><?php echo htmlspecialchars($t['name']); ?></td><td style="color:#fbbf24">₹<?php echo number_format($t['entry_fee'],2); ?></td><td style="color:#10b981">₹<?php echo number_format($t['prize_pool'],2); ?></td><td><?php echo $t['max_players']; ?></td><td><?php echo $t['match_count']; ?> (<?php echo $t['active_matches']; ?> active)</td><td><span class="status-badge <?php echo $t['status']; ?>"><?php echo ucwords(str_replace('_',' ',$t['status'])); ?></span></td><td><?php echo date('d M Y',strtotime($t['created_at'])); ?></td>
                <td><?php if($t['status']==='scheduled'): ?><a href="?toggle=1&id=<?php echo $t['id']; ?>&status=active" class="btn-success">Activate</a><?php elseif($t['status']==='active'): ?><a href="?toggle=1&id=<?php echo $t['id']; ?>&status=in_progress" class="btn-warning">Start</a><?php endif; ?> <a href="?delete=1&id=<?php echo $t['id']; ?>" class="btn-danger" onclick="return confirm('Delete?')">Delete</a></td></tr>
            <?php endforeach; endif; ?></tbody></table>
        </div>
    </div>
    
    <div class="modal-overlay" id="addModal">
        <div class="modal-box">
            <h2>➕ New Tournament</h2>
            <form method="POST">
                <div class="form-group"><label>Name *</label><input type="text" name="name" required></div>
                <div class="form-group"><label>Entry Fee (₹) *</label><input type="number" name="entry_fee" id="entryFee" step="1" min="1" onchange="calcPrize()"></div>
                <div class="form-group"><label>Max Players *</label><select name="max_players" id="maxPlayers" onchange="calcPrize()"><option value="2">2</option><option value="4" selected>4</option><option value="6">6</option><option value="8">8</option></select></div>
                <div class="form-group"><label>Platform Fee (%)</label><input type="number" name="platform_fee" id="platformFee" value="15" onchange="calcPrize()"></div>
                <div class="form-group"><label>Prize Pool (Auto)</label><input type="text" id="prizePoolDisplay" value="₹0.00" disabled></div>
                <input type="hidden" name="add_tournament" value="1">
                <div class="modal-actions"><button type="submit" class="btn-confirm">✅ Create</button><button type="button" class="btn-cancel" onclick="closeModal('addModal')">Cancel</button></div>
            </form>
        </div>
    </div>
    
    <div class="toast" id="adminToast"></div>
    <script>
        function openAddModal(){document.getElementById('addModal').classList.add('active');calcPrize()}
        function closeModal(id){document.getElementById(id).classList.remove('active')}
        function calcPrize(){const f=parseFloat(document.getElementById('entryFee').value)||0;const p=parseInt(document.getElementById('maxPlayers').value)||4;const pf=parseFloat(document.getElementById('platformFee').value)||15;const t=f*p;document.getElementById('prizePoolDisplay').value='₹'+(t-t*(pf/100)).toFixed(2)}
        function showToast(m,t){const toast=document.getElementById('adminToast');toast.textContent=m;toast.className='toast '+t+' show';setTimeout(()=>toast.classList.remove('show'),4000)}
        <?php if($success): ?>document.addEventListener('DOMContentLoaded',()=>showToast('<?php echo addslashes($success); ?>','success'));<?php endif; ?>
        <?php if($error): ?>document.addEventListener('DOMContentLoaded',()=>showToast('<?php echo addslashes($error); ?>','error'));<?php endif; ?>
    </script>
</body>
</html>
