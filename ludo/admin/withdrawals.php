<?php
/**
 * ======================================================
 * ADMIN WITHDRAWALS.PHP - Withdrawal Management (FIXED)
 * Ludo Tournament Platform - Admin Withdrawal Dashboard
 * Version: 3.0.0 - API PATHS FIXED
 * ======================================================
 */

if (!defined('BASE_PATH')) { define('BASE_PATH', dirname(__DIR__)); }
require_once dirname(__DIR__) . '/config/db.php';
SessionManager::init();

function validateAdminSession() {
    if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_token'])) return false;
    try {
        $db = Database::getInstance(); $conn = $db->getConnection();
        $stmt = $conn->prepare("SELECT id FROM sessions WHERE user_id = :aid AND session_token = :token AND is_active = 1 AND expires_at > NOW()");
        $stmt->execute([':aid' => $_SESSION['admin_id'], ':token' => $_SESSION['admin_token']]);
        return $stmt->fetch() !== false;
    } catch (Exception $e) { return false; }
}
if (!validateAdminSession()) { session_destroy(); header('Location: index.php'); exit; }

$csrf_token = CSRFToken::generate();
$statusFilter = $_GET['status'] ?? 'pending';
$basePath = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Withdrawals - Admin</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}body{font-family:'Inter',sans-serif;background:#0a0e1a;color:#f1f5f9;min-height:100vh}
        .admin-container{max-width:1400px;margin:0 auto;padding:20px}
        .admin-header{display:flex;justify-content:space-between;align-items:center;padding:16px 0;border-bottom:1px solid rgba(255,255,255,0.06);margin-bottom:24px;flex-wrap:wrap;gap:12px}
        .admin-header h1{font-size:24px;font-weight:700;background:linear-gradient(135deg,#fbbf24,#f59e0b);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
        .admin-header-actions{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
        .admin-header-actions a{color:#94a3b8;text-decoration:none;font-weight:600;font-size:14px;padding:8px 16px;border:1px solid rgba(255,255,255,0.06);border-radius:8px}
        .admin-header-actions a.logout{color:#ef4444;border-color:rgba(239,68,68,0.2)}
        .stats-bar{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:24px}
        .stat-card{background:#1a1a2e;padding:16px 20px;border-radius:12px;border:1px solid rgba(255,255,255,0.04);text-align:center}
        .stat-card .stat-number{font-size:24px;font-weight:800}.stat-card .stat-label{font-size:12px;color:#94a3b8;margin-top:2px}
        .filter-bar{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap}
        .filter-btn{padding:8px 20px;border:1px solid rgba(255,255,255,0.06);border-radius:8px;background:transparent;color:#94a3b8;font-weight:600;font-size:13px;cursor:pointer;font-family:inherit}
        .filter-btn.active{background:rgba(124,58,237,0.2);color:#8b5cf6;border-color:rgba(124,58,237,0.2)}
        .withdrawal-list{display:grid;gap:16px}
        .withdrawal-card{background:#1a1a2e;border-radius:14px;padding:20px;border:1px solid rgba(255,255,255,0.04)}
        .withdrawal-card .wd-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;flex-wrap:wrap;gap:8px}
        .withdrawal-card .user-name{font-size:18px;font-weight:700;color:#f1f5f9}
        .withdrawal-card .user-detail{font-size:13px;color:#94a3b8}
        .status-badge{padding:4px 14px;border-radius:20px;font-size:12px;font-weight:600}
        .status-badge.pending{background:rgba(245,158,11,0.15);color:#f59e0b}
        .status-badge.processing{background:rgba(59,130,246,0.15);color:#3b82f6}
        .status-badge.approved{background:rgba(139,92,246,0.15);color:#8b5cf6}
        .status-badge.completed{background:rgba(16,185,129,0.15);color:#10b981}
        .status-badge.rejected{background:rgba(239,68,68,0.15);color:#ef4444}
        .wd-details{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:12px 0}
        .detail-item{background:rgba(255,255,255,0.02);padding:8px 12px;border-radius:8px}
        .detail-item .label{font-size:11px;color:#64748b;text-transform:uppercase}
        .detail-item .value{font-size:14px;font-weight:600;color:#f1f5f9}
        .detail-item .value.amount{color:#fbbf24;font-size:18px}
        .action-buttons{display:flex;gap:10px;margin-top:12px;flex-wrap:wrap}
        .btn-action{padding:8px 20px;border:none;border-radius:8px;font-weight:600;font-size:13px;cursor:pointer;font-family:inherit}
        .btn-action.approve{background:rgba(16,185,129,0.15);color:#10b981}
        .btn-action.reject{background:rgba(239,68,68,0.15);color:#ef4444}
        .btn-action.process{background:rgba(59,130,246,0.15);color:#3b82f6}
        .btn-action.complete{background:rgba(16,185,129,0.15);color:#10b981}
        .modal-overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);backdrop-filter:blur(8px);z-index:1000;justify-content:center;align-items:center;padding:20px}
        .modal-overlay.active{display:flex}
        .modal-box{background:#1a1a2e;padding:32px;border-radius:16px;max-width:500px;width:100%;border:1px solid rgba(255,255,255,0.06)}
        .modal-box h2{font-size:20px;font-weight:700;margin-bottom:16px}
        .form-group{margin-bottom:14px}.form-group label{display:block;font-size:13px;font-weight:600;color:#94a3b8;margin-bottom:4px}
        .form-group textarea{width:100%;padding:10px 14px;border:1px solid rgba(255,255,255,0.06);border-radius:8px;background:rgba(255,255,255,0.04);color:#f1f5f9;font-size:14px;font-family:inherit;min-height:100px;resize:vertical}
        .modal-actions{display:flex;gap:12px;margin-top:20px}
        .modal-actions button{flex:1;padding:12px;border:none;border-radius:8px;font-weight:600;font-size:14px;cursor:pointer;font-family:inherit}
        .modal-actions .btn-danger{background:rgba(239,68,68,0.2);color:#ef4444}
        .modal-actions .btn-cancel{background:rgba(255,255,255,0.06);color:#94a3b8}
        .toast{position:fixed;bottom:24px;right:24px;padding:14px 24px;border-radius:12px;font-weight:600;font-size:14px;z-index:2000;transform:translateY(100px);opacity:0;transition:all 0.4s ease}
        .toast.show{transform:translateY(0);opacity:1}.toast.success{background:rgba(16,185,129,0.2);color:#10b981}.toast.error{background:rgba(239,68,68,0.2);color:#ef4444}
    </style>
</head>
<body>
    <div class="admin-container">
        <div class="admin-header">
            <h1>🏦 Withdrawal Management</h1>
            <div class="admin-header-actions">
                <span>👋 <?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?></span>
                <a href="index.php">← Dashboard</a><a href="settings.php">⚙️ Settings</a><a href="kyc.php">🛡️ KYC</a><a href="disputes.php">📋 Disputes</a><a href="?logout=1" class="logout">🚪 Logout</a>
            </div>
        </div>
        <div class="stats-bar" id="statsBar">
            <div class="stat-card"><div class="stat-number" style="color:#f59e0b" id="statPending">...</div><div class="stat-label">Pending</div></div>
            <div class="stat-card"><div class="stat-number" style="color:#3b82f6" id="statProcessing">...</div><div class="stat-label">Processing</div></div>
            <div class="stat-card"><div class="stat-number" style="color:#10b981" id="statCompleted">...</div><div class="stat-label">Completed</div></div>
            <div class="stat-card"><div class="stat-number" style="color:#ef4444" id="statRejected">...</div><div class="stat-label">Rejected</div></div>
        </div>
        <div class="filter-bar">
            <button class="filter-btn <?php echo $statusFilter==='pending'?'active':''; ?>" data-status="pending">⏳ Pending</button>
            <button class="filter-btn <?php echo $statusFilter==='processing'?'active':''; ?>" data-status="processing">🔄 Processing</button>
            <button class="filter-btn <?php echo $statusFilter==='approved'?'active':''; ?>" data-status="approved">✅ Approved</button>
            <button class="filter-btn <?php echo $statusFilter==='completed'?'active':''; ?>" data-status="completed">✔️ Completed</button>
            <button class="filter-btn <?php echo $statusFilter==='rejected'?'active':''; ?>" data-status="rejected">❌ Rejected</button>
            <button class="filter-btn <?php echo $statusFilter==='all'?'active':''; ?>" data-status="all">📋 All</button>
        </div>
        <div class="withdrawal-list" id="withdrawalList"><div style="text-align:center;padding:40px;color:#94a3b8">Loading...</div></div>
    </div>
    <div class="modal-overlay" id="rejectModal"><div class="modal-box"><h2>❌ Reject Withdrawal</h2><input type="hidden" id="rejectWdId"><div class="form-group"><label>Reason</label><textarea id="rejectReason"></textarea></div><div class="modal-actions"><button class="btn-danger" onclick="confirmReject()">❌ Reject</button><button class="btn-cancel" onclick="closeModal('rejectModal')">Cancel</button></div></div></div>
    <div class="toast" id="adminToast"></div>
    <script>
        let state={currentPage:0,limit:50,total:0,status:'<?php echo $statusFilter; ?>',csrfToken:'<?php echo $csrf_token; ?>'};
        function handleApiResponse(r){if(r.status===401){showToast('Session expired','error');setTimeout(()=>location.href='index.php',1500);throw new Error('Session expired')}return r.json()}
        function showToast(m,t='info'){const toast=document.getElementById('adminToast');toast.textContent=m;toast.className='toast '+t+' show';clearTimeout(toast._timeout);toast._timeout=setTimeout(()=>toast.classList.remove('show'),4000)}
        function closeModal(id){document.getElementById(id).classList.remove('active')}
        document.addEventListener('DOMContentLoaded',function(){loadStats();loadWithdrawals();document.querySelectorAll('.filter-btn').forEach(btn=>{btn.addEventListener('click',function(){document.querySelectorAll('.filter-btn').forEach(b=>b.classList.remove('active'));this.classList.add('active');state.status=this.dataset.status;state.currentPage=0;loadWithdrawals()})})});
        function loadStats(){fetch('<?php echo $basePath; ?>/api/admin_withdrawals.php?action=get_stats').then(handleApiResponse).then(d=>{if(d.success){document.getElementById('statPending').textContent=d.data.pending||0;document.getElementById('statProcessing').textContent=d.data.processing||0;document.getElementById('statCompleted').textContent=d.data.completed||0;document.getElementById('statRejected').textContent=d.data.rejected||0}}).catch(()=>{})}
        function loadWithdrawals(){document.getElementById('withdrawalList').innerHTML='<div style="text-align:center;padding:40px;color:#94a3b8">Loading...</div>';const status=state.status==='all'?'':state.status;fetch(`<?php echo $basePath; ?>/api/admin_withdrawals.php?action=list&status=${status}&offset=0&limit=${state.limit}`).then(handleApiResponse).then(d=>{if(d.success){document.getElementById('withdrawalList').innerHTML=(d.data.withdrawals||[]).map(w=>`<div class="withdrawal-card"><div class="wd-header"><div><span class="user-name">${escapeHtml(w.username||'Unknown')}</span><br><span class="user-detail">📱 ${escapeHtml(w.mobile||'N/A')} • User #${w.user_id} • 🏦 ${escapeHtml(w.bank_account_number)}</span></div><span class="status-badge ${w.status}">${w.status.toUpperCase()}</span></div><div class="wd-details"><div class="detail-item"><div class="label">Amount</div><div class="value amount">₹${parseFloat(w.amount).toFixed(2)}</div></div><div class="detail-item"><div class="label">Transaction ID</div><div class="value">${escapeHtml(w.transaction_id||'N/A')}</div></div></div><div class="action-buttons">${w.status==='pending'?`<button class="btn-action approve" onclick="approveWithdrawal(${w.id})">✅ Approve</button><button class="btn-action reject" onclick="openRejectModal(${w.id})">❌ Reject</button>`:''}${w.status==='approved'?`<button class="btn-action process" onclick="processWithdrawal(${w.id})">🔄 Process</button>`:''}${w.status==='processing'?`<button class="btn-action complete" onclick="completeWithdrawal(${w.id})">✅ Complete</button>`:''}</div></div>`).join('')||'<div style="text-align:center;padding:40px;color:#94a3b8">No withdrawals</div>'}}).catch(()=>{document.getElementById('withdrawalList').innerHTML='<div style="text-align:center;padding:40px;color:#ef4444">Error</div>'})}
        function approveWithdrawal(id){if(!confirm('Approve this withdrawal?'))return;fetch('<?php echo $basePath; ?>/api/admin_withdrawals.php?action=approve',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':state.csrfToken},body:JSON.stringify({id:id,csrf_token:state.csrfToken})}).then(handleApiResponse).then(d=>{if(d.success){showToast('Approved!','success');loadStats();loadWithdrawals()}else showToast(d.message||'Failed','error')}).catch(()=>showToast('Error','error'))}
        function openRejectModal(id){document.getElementById('rejectWdId').value=id;document.getElementById('rejectReason').value='';document.getElementById('rejectModal').classList.add('active')}
        function confirmReject(){const id=document.getElementById('rejectWdId').value;const reason=document.getElementById('rejectReason').value.trim();if(!reason||reason.length<10){showToast('Reason 10+ chars','error');return}fetch('<?php echo $basePath; ?>/api/admin_withdrawals.php?action=reject',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':state.csrfToken},body:JSON.stringify({id:parseInt(id),reason:reason,csrf_token:state.csrfToken})}).then(handleApiResponse).then(d=>{if(d.success){showToast('Rejected & refunded','success');closeModal('rejectModal');loadStats();loadWithdrawals()}else showToast(d.message||'Failed','error')}).catch(()=>showToast('Error','error'))}
        function processWithdrawal(id){if(!confirm('Mark as processing?'))return;fetch('<?php echo $basePath; ?>/api/admin_withdrawals.php?action=process',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':state.csrfToken},body:JSON.stringify({id:id,csrf_token:state.csrfToken})}).then(handleApiResponse).then(d=>{if(d.success){showToast('Processing','success');loadWithdrawals()}else showToast(d.message||'Failed','error')}).catch(()=>showToast('Error','error'))}
        function completeWithdrawal(id){if(!confirm('Mark as completed?'))return;fetch('<?php echo $basePath; ?>/api/admin_withdrawals.php?action=complete',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':state.csrfToken},body:JSON.stringify({id:id,csrf_token:state.csrfToken})}).then(handleApiResponse).then(d=>{if(d.success){showToast('Completed!','success');loadWithdrawals()}else showToast(d.message||'Failed','error')}).catch(()=>showToast('Error','error'))}
        function escapeHtml(s){if(!s)return'';const d=document.createElement('div');d.textContent=s;return d.innerHTML}
    </script>
</body>
</html>
