<?php
/**
 * ======================================================
 * ADMIN_USERS.PHP - User Management UI (FIXED)
 * Ludo Tournament Platform - Admin User Management
 * Version: 3.0.0 - API PATHS FIXED + ALL BUGS FIXED
 * ======================================================
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once dirname(__DIR__) . '/config/db.php';

SessionManager::init();

function validateAdminSession() {
    if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_token'])) return false;
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        $stmt = $conn->prepare("SELECT id FROM sessions WHERE user_id = :aid AND session_token = :token AND is_active = 1 AND expires_at > NOW()");
        $stmt->execute([':aid' => $_SESSION['admin_id'], ':token' => $_SESSION['admin_token']]);
        return $stmt->fetch() !== false;
    } catch (Exception $e) { return false; }
}

if (!validateAdminSession()) { session_destroy(); header('Location: index.php'); exit; }

$csrf_token = CSRFToken::generate();
$basePath = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Admin</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Inter',sans-serif;background:#0a0e1a;color:#f1f5f9;min-height:100vh}
        .admin-container{max-width:1400px;margin:0 auto;padding:20px}
        .admin-header{display:flex;justify-content:space-between;align-items:center;padding:16px 0;border-bottom:1px solid rgba(255,255,255,0.06);margin-bottom:24px;flex-wrap:wrap;gap:12px}
        .admin-header h1{font-size:24px;font-weight:700;background:linear-gradient(135deg,#fbbf24,#f59e0b);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
        .admin-header-actions{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
        .admin-header-actions a{color:#94a3b8;text-decoration:none;font-weight:600;font-size:14px;padding:8px 16px;border:1px solid rgba(255,255,255,0.06);border-radius:8px}
        .admin-header-actions a:hover{background:rgba(255,255,255,0.04)}
        .admin-header-actions a.logout{color:#ef4444;border-color:rgba(239,68,68,0.2)}
        .stats-bar{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:24px}
        .stat-card{background:#1a1a2e;padding:16px 20px;border-radius:12px;border:1px solid rgba(255,255,255,0.04);text-align:center}
        .stat-card .stat-number{font-size:24px;font-weight:800}
        .stat-card .stat-label{font-size:12px;color:#94a3b8;margin-top:2px}
        .search-bar{display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap}
        .search-bar input,.search-bar select{flex:1;min-width:150px;padding:10px 14px;border:1px solid rgba(255,255,255,0.06);border-radius:10px;background:rgba(255,255,255,0.04);color:#f1f5f9;font-size:14px;font-family:inherit}
        .search-bar input:focus,.search-bar select:focus{outline:none;border-color:#7c3aed}
        .search-bar select option{background:#1a1a2e}
        .btn-action{padding:10px 20px;border:none;border-radius:10px;font-weight:600;font-size:13px;cursor:pointer;font-family:inherit}
        .btn-action.primary{background:rgba(59,130,246,0.2);color:#3b82f6}
        .btn-action.primary:hover{background:rgba(59,130,246,0.3)}
        .table-container{background:#1a1a2e;border-radius:14px;border:1px solid rgba(255,255,255,0.04);overflow-x:auto}
        table{width:100%;border-collapse:collapse;font-size:14px}
        table th{padding:12px 16px;text-align:left;color:#94a3b8;font-weight:600;font-size:12px;text-transform:uppercase}
        table td{padding:12px 16px;border-bottom:1px solid rgba(255,255,255,0.02)}
        .status-badge{display:inline-block;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:600}
        .status-badge.active{background:rgba(16,185,129,0.15);color:#10b981}
        .status-badge.inactive{background:rgba(239,68,68,0.15);color:#ef4444}
        .status-badge.verified{background:rgba(16,185,129,0.15);color:#10b981}
        .status-badge.pending{background:rgba(245,158,11,0.15);color:#f59e0b}
        .btn-action-sm{padding:4px 10px;border:none;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;margin:0 2px}
        .btn-action-sm.primary{background:rgba(59,130,246,0.2);color:#3b82f6}
        .btn-action-sm.success{background:rgba(16,185,129,0.2);color:#10b981}
        .btn-action-sm.danger{background:rgba(239,68,68,0.2);color:#ef4444}
        .modal-overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);backdrop-filter:blur(8px);z-index:1000;justify-content:center;align-items:center;padding:20px}
        .modal-overlay.active{display:flex}
        .modal-box{background:#1a1a2e;padding:32px;border-radius:16px;max-width:500px;width:100%;border:1px solid rgba(255,255,255,0.06)}
        .modal-box h2{font-size:20px;font-weight:700;margin-bottom:16px}
        .form-group{margin-bottom:14px}
        .form-group label{display:block;font-size:13px;font-weight:600;color:#94a3b8;margin-bottom:4px}
        .form-group input,.form-group select{width:100%;padding:10px 14px;border:1px solid rgba(255,255,255,0.06);border-radius:8px;background:rgba(255,255,255,0.04);color:#f1f5f9;font-size:14px;font-family:inherit}
        .modal-actions{display:flex;gap:12px;margin-top:20px}
        .modal-actions button{flex:1;padding:12px;border:none;border-radius:8px;font-weight:600;font-size:14px;cursor:pointer;font-family:inherit}
        .modal-actions .btn-confirm{background:linear-gradient(135deg,#fbbf24,#f59e0b);color:#1a1a2e}
        .modal-actions .btn-cancel{background:rgba(255,255,255,0.06);color:#94a3b8}
        .toast{position:fixed;bottom:24px;right:24px;padding:14px 24px;border-radius:12px;font-weight:600;font-size:14px;z-index:2000;transform:translateY(100px);opacity:0;transition:all 0.4s ease}
        .toast.show{transform:translateY(0);opacity:1}
        .toast.success{background:rgba(16,185,129,0.2);color:#10b981}
        .toast.error{background:rgba(239,68,68,0.2);color:#ef4444}
        @media(max-width:768px){.stats-bar{grid-template-columns:repeat(2,1fr)}}
    </style>
</head>
<body>
    <div class="admin-container">
        <div class="admin-header">
            <h1>👥 User Management</h1>
            <div class="admin-header-actions">
                <span>👋 <?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?></span>
                <a href="index.php">← Dashboard</a>
                <a href="settings.php">⚙️ Settings</a>
                <a href="kyc.php">🛡️ KYC</a>
                <a href="withdrawals.php">🏦 Withdrawals</a>
                <a href="disputes.php">📋 Disputes</a>
                <a href="?logout=1" class="logout">🚪 Logout</a>
            </div>
        </div>
        
        <div class="stats-bar" id="statsBar">
            <div class="stat-card"><div class="stat-number" id="statTotal" style="color:#3b82f6">...</div><div class="stat-label">Total Users</div></div>
            <div class="stat-card"><div class="stat-number" id="statActive" style="color:#10b981">...</div><div class="stat-label">Active</div></div>
            <div class="stat-card"><div class="stat-number" id="statNew" style="color:#fbbf24">...</div><div class="stat-label">New Today</div></div>
            <div class="stat-card"><div class="stat-number" id="statKyc" style="color:#8b5cf6">...</div><div class="stat-label">KYC Verified</div></div>
            <div class="stat-card"><div class="stat-number" id="statBalance" style="color:#fbbf24">...</div><div class="stat-label">Total Balance</div></div>
        </div>
        
        <div class="search-bar">
            <input type="text" id="userSearch" placeholder="Search by username, mobile, or email..." onkeyup="debounceSearch()">
            <select id="statusFilter" onchange="state.currentPage=0;loadUsers()"><option value="">All</option><option value="active">Active</option><option value="inactive">Inactive</option></select>
            <button class="btn-action primary" onclick="loadUsers()">🔄 Refresh</button>
        </div>
        
        <div class="table-container">
            <table><thead><tr><th>ID</th><th>Username</th><th>Mobile</th><th>Balance</th><th>Matches</th><th>Wins</th><th>KYC</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody id="usersBody"><tr><td colspan="9">Loading...</td></tr></tbody></table>
        </div>
    </div>
    
    <div class="modal-overlay" id="balanceModal">
        <div class="modal-box">
            <h2>💰 Adjust Balance</h2>
            <input type="hidden" id="balUserId">
            <div class="form-group"><label>Current Balance</label><input type="text" id="balCurrent" disabled></div>
            <div class="form-group"><label>Type</label><select id="balType"><option value="credit">Credit (+)</option><option value="debit">Debit (-)</option></select></div>
            <div class="form-group"><label>Amount (₹)</label><input type="number" id="balAmount" step="0.01" min="0.01"></div>
            <div class="modal-actions"><button class="btn-confirm" onclick="submitBalance()">✅ Confirm</button><button class="btn-cancel" onclick="closeModal('balanceModal')">Cancel</button></div>
        </div>
    </div>
    
    <div class="toast" id="adminToast"></div>
    
    <script>
        let state = {currentPage:0,limit:50,total:0,search:'',status:'',sort:'id_desc',csrfToken:'<?php echo $csrf_token; ?>',searchTimeout:null};
        
        function handleApiResponse(r){if(r.status===401){showToast('Session expired','error');setTimeout(()=>location.href='index.php',1500);throw new Error('Session expired')}return r.json()}
        function showToast(m,t='info'){const toast=document.getElementById('adminToast');toast.textContent=m;toast.className='toast '+t+' show';clearTimeout(toast._timeout);toast._timeout=setTimeout(()=>toast.classList.remove('show'),4000)}
        function closeModal(id){document.getElementById(id).classList.remove('active')}
        
        document.addEventListener('DOMContentLoaded',function(){loadStats();loadUsers()});
        
        function loadStats(){
            fetch('<?php echo $basePath; ?>/api/admin_users.php?action=get_stats').then(handleApiResponse).then(d=>{
                if(d.success){
                    document.getElementById('statTotal').textContent=d.data.total_users||0;
                    document.getElementById('statActive').textContent=d.data.active_users||0;
                    document.getElementById('statNew').textContent=d.data.new_users_today||0;
                    document.getElementById('statKyc').textContent=d.data.kyc_verified||0;
                    document.getElementById('statBalance').textContent='₹'+(d.data.total_balance||0).toFixed(2);
                }
            }).catch(()=>{});
        }
        
        function loadUsers(){
            document.getElementById('usersBody').innerHTML='<tr><td colspan="9">Loading...</td></tr>';
            const offset=state.currentPage*state.limit;
            fetch(`<?php echo $basePath; ?>/api/admin_users.php?action=list&offset=${offset}&limit=${state.limit}&search=${encodeURIComponent(state.search)}&status=${state.status}&sort=${state.sort}`).then(handleApiResponse).then(d=>{
                if(d.success){
                    state.total=d.data.total;
                    document.getElementById('usersBody').innerHTML=(d.data.users||[]).map(u=>`<tr>
                        <td>#${u.id}</td><td>${escapeHtml(u.username)}</td><td>${escapeHtml(u.mobile)}</td>
                        <td style="color:#fbbf24">₹${parseFloat(u.wallet_balance).toFixed(2)}</td>
                        <td>${u.total_matches_played||0}</td><td>${u.total_matches_won||0}</td>
                        <td><span class="status-badge ${u.kyc_status||'not_submitted'}">${u.kyc_status||'N/A'}</span></td>
                        <td><span class="status-badge ${u.is_active?'active':'inactive'}">${u.is_active?'Active':'Inactive'}</span></td>
                        <td>
                            <button class="btn-action-sm success" onclick="openBalanceModal(${u.id},'${escapeHtml(u.username)}',${u.wallet_balance})">💰</button>
                            <button class="btn-action-sm ${u.is_active?'danger':'primary'}" onclick="toggleUser(${u.id})">${u.is_active?'🔒':'🔓'}</button>
                        </td></tr>`).join('');
                }
            }).catch(()=>{document.getElementById('usersBody').innerHTML='<tr><td colspan="9">Error</td></tr>'});
        }
        
        function debounceSearch(){clearTimeout(state.searchTimeout);state.searchTimeout=setTimeout(()=>{state.search=document.getElementById('userSearch').value;state.status=document.getElementById('statusFilter').value;state.currentPage=0;loadUsers()},400)}
        
        function openBalanceModal(uid,uname,bal){
            document.getElementById('balUserId').value=uid;
            document.getElementById('balCurrent').value='₹'+bal.toFixed(2);
            document.getElementById('balAmount').value='';
            document.getElementById('balanceModal').classList.add('active');
        }
        
        function submitBalance(){
            const uid=document.getElementById('balUserId').value;
            const amt=parseFloat(document.getElementById('balAmount').value);
            const type=document.getElementById('balType').value;
            if(!uid||!amt||amt<=0){showToast('Enter valid amount','error');return}
            const btn=document.querySelector('#balanceModal .btn-confirm');btn.disabled=true;btn.textContent='Processing...';
            fetch('<?php echo $basePath; ?>/api/admin_users.php?action=update_balance',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':state.csrfToken},body:JSON.stringify({user_id:parseInt(uid),amount:amt,type:type,reason:'Admin adjustment',csrf_token:state.csrfToken})}).then(handleApiResponse).then(d=>{if(d.success){showToast('Balance updated!','success');closeModal('balanceModal');loadUsers();loadStats()}else showToast(d.message||'Failed','error')}).catch(()=>showToast('Error','error')).finally(()=>{btn.disabled=false;btn.textContent='✅ Confirm'});
        }
        
        function toggleUser(uid){
            if(!confirm('Toggle user status?'))return;
            fetch('<?php echo $basePath; ?>/api/admin_users.php?action=toggle_status',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':state.csrfToken},body:JSON.stringify({user_id:uid,csrf_token:state.csrfToken})}).then(handleApiResponse).then(d=>{if(d.success){showToast('Status toggled','success');loadUsers()}else showToast(d.message||'Failed','error')}).catch(()=>showToast('Error','error'));
        }
        
        function escapeHtml(s){if(!s)return'';const d=document.createElement('div');d.textContent=s;return d.innerHTML}
    </script>
</body>
</html>
