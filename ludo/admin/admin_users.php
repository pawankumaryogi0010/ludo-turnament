<?php
/**
 * ======================================================
 * ADMIN KYC.PHP - KYC Management UI (FIXED)
 * Ludo Tournament Platform - Admin KYC Dashboard
 * Version: 3.0.0 - API PATHS FIXED
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
$statusFilter = $_GET['status'] ?? 'pending';
$basePath = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KYC Management - Admin</title>
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
        .stats-bar{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:24px}
        .stat-card{background:#1a1a2e;padding:16px 20px;border-radius:12px;border:1px solid rgba(255,255,255,0.04);text-align:center}
        .stat-card .stat-number{font-size:24px;font-weight:800}
        .stat-card .stat-label{font-size:12px;color:#94a3b8;margin-top:2px}
        .filter-bar{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap}
        .filter-btn{padding:8px 20px;border:1px solid rgba(255,255,255,0.06);border-radius:8px;background:transparent;color:#94a3b8;font-weight:600;font-size:13px;cursor:pointer;font-family:inherit}
        .filter-btn:hover{background:rgba(255,255,255,0.04);color:#f1f5f9}
        .filter-btn.active{background:rgba(124,58,237,0.2);color:#8b5cf6;border-color:rgba(124,58,237,0.2)}
        .search-bar{display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap}
        .search-bar input{flex:1;min-width:200px;padding:10px 14px;border:1px solid rgba(255,255,255,0.06);border-radius:10px;background:rgba(255,255,255,0.04);color:#f1f5f9;font-size:14px;font-family:inherit}
        .search-bar input:focus{outline:none;border-color:#7c3aed}
        .kyc-list{display:grid;gap:16px}
        .kyc-card{background:#1a1a2e;border-radius:14px;padding:20px;border:1px solid rgba(255,255,255,0.04)}
        .kyc-card .kyc-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;flex-wrap:wrap;gap:8px}
        .kyc-card .user-name{font-size:18px;font-weight:700;color:#f1f5f9}
        .kyc-card .user-detail{font-size:13px;color:#94a3b8}
        .status-badge{padding:4px 14px;border-radius:20px;font-size:12px;font-weight:600}
        .status-badge.pending{background:rgba(245,158,11,0.15);color:#f59e0b}
        .status-badge.verified{background:rgba(16,185,129,0.15);color:#10b981}
        .status-badge.rejected{background:rgba(239,68,68,0.15);color:#ef4444}
        .kyc-card .kyc-details{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:12px 0}
        .kyc-card .detail-item{background:rgba(255,255,255,0.02);padding:8px 12px;border-radius:8px}
        .kyc-card .detail-item .label{font-size:11px;color:#64748b;text-transform:uppercase}
        .kyc-card .detail-item .value{font-size:14px;font-weight:600;color:#f1f5f9}
        .action-buttons{display:flex;gap:10px;margin-top:12px;flex-wrap:wrap}
        .btn-action{padding:8px 20px;border:none;border-radius:8px;font-weight:600;font-size:13px;cursor:pointer;font-family:inherit}
        .btn-action.verify{background:rgba(16,185,129,0.15);color:#10b981}
        .btn-action.verify:hover{background:rgba(16,185,129,0.25)}
        .btn-action.reject{background:rgba(239,68,68,0.15);color:#ef4444}
        .btn-action.reject:hover{background:rgba(239,68,68,0.25)}
        .modal-overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);backdrop-filter:blur(8px);z-index:1000;justify-content:center;align-items:center;padding:20px}
        .modal-overlay.active{display:flex}
        .modal-box{background:#1a1a2e;padding:32px;border-radius:16px;max-width:500px;width:100%;border:1px solid rgba(255,255,255,0.06)}
        .modal-box h2{font-size:20px;font-weight:700;margin-bottom:16px}
        .form-group{margin-bottom:14px}
        .form-group label{display:block;font-size:13px;font-weight:600;color:#94a3b8;margin-bottom:4px}
        .form-group textarea{width:100%;padding:10px 14px;border:1px solid rgba(255,255,255,0.06);border-radius:8px;background:rgba(255,255,255,0.04);color:#f1f5f9;font-size:14px;font-family:inherit;min-height:100px;resize:vertical}
        .form-group textarea:focus{outline:none;border-color:#7c3aed}
        .modal-actions{display:flex;gap:12px;margin-top:20px}
        .modal-actions button{flex:1;padding:12px;border:none;border-radius:8px;font-weight:600;font-size:14px;cursor:pointer;font-family:inherit}
        .modal-actions .btn-danger{background:rgba(239,68,68,0.2);color:#ef4444}
        .modal-actions .btn-cancel{background:rgba(255,255,255,0.06);color:#94a3b8}
        .toast{position:fixed;bottom:24px;right:24px;padding:14px 24px;border-radius:12px;font-weight:600;font-size:14px;z-index:2000;transform:translateY(100px);opacity:0;transition:all 0.4s ease}
        .toast.show{transform:translateY(0);opacity:1}
        .toast.success{background:rgba(16,185,129,0.2);color:#10b981}
        .toast.error{background:rgba(239,68,68,0.2);color:#ef4444}
        @media(max-width:768px){.kyc-card .kyc-details{grid-template-columns:1fr}}
    </style>
</head>
<body>
    <div class="admin-container">
        <div class="admin-header">
            <h1>🛡️ KYC Management</h1>
            <div class="admin-header-actions">
                <span>👋 <?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?></span>
                <a href="index.php">← Dashboard</a>
                <a href="settings.php">⚙️ Settings</a>
                <a href="withdrawals.php">🏦 Withdrawals</a>
                <a href="disputes.php">📋 Disputes</a>
                <a href="?logout=1" class="logout">🚪 Logout</a>
            </div>
        </div>
        
        <div class="stats-bar" id="statsBar">
            <div class="stat-card"><div class="stat-number" style="color:#f59e0b" id="statPending">...</div><div class="stat-label">Pending</div></div>
            <div class="stat-card"><div class="stat-number" style="color:#10b981" id="statVerified">...</div><div class="stat-label">Verified</div></div>
            <div class="stat-card"><div class="stat-number" style="color:#ef4444" id="statRejected">...</div><div class="stat-label">Rejected</div></div>
            <div class="stat-card"><div class="stat-number" style="color:#3b82f6" id="statTotal">...</div><div class="stat-label">Total</div></div>
        </div>
        
        <div class="filter-bar">
            <button class="filter-btn <?php echo $statusFilter==='pending'?'active':''; ?>" data-status="pending">⏳ Pending</button>
            <button class="filter-btn <?php echo $statusFilter==='verified'?'active':''; ?>" data-status="verified">✅ Verified</button>
            <button class="filter-btn <?php echo $statusFilter==='rejected'?'active':''; ?>" data-status="rejected">❌ Rejected</button>
            <button class="filter-btn <?php echo $statusFilter==='all'?'active':''; ?>" data-status="all">📋 All</button>
        </div>
        
        <div class="search-bar">
            <input type="text" id="kycSearch" placeholder="Search by username, mobile, or document..." onkeyup="debounceSearch()">
            <button class="btn-action verify" onclick="loadKycList()">🔄 Refresh</button>
        </div>
        
        <div class="kyc-list" id="kycList"><div style="text-align:center;padding:40px;color:#94a3b8">Loading...</div></div>
    </div>
    
    <div class="modal-overlay" id="rejectModal">
        <div class="modal-box">
            <h2>❌ Reject KYC</h2>
            <input type="hidden" id="rejectKycId">
            <div class="form-group"><label>Rejection Reason</label><textarea id="rejectReason" placeholder="Detailed reason..."></textarea></div>
            <div class="modal-actions"><button class="btn-danger" onclick="confirmReject()">❌ Reject</button><button class="btn-cancel" onclick="closeModal('rejectModal')">Cancel</button></div>
        </div>
    </div>
    
    <div class="toast" id="adminToast"></div>
    
    <script>
        let state = {currentPage:0,limit:50,total:0,status:'<?php echo $statusFilter; ?>',search:'',csrfToken:'<?php echo $csrf_token; ?>',searchTimeout:null};
        
        function handleApiResponse(r){if(r.status===401){showToast('Session expired','error');setTimeout(()=>location.href='index.php',1500);throw new Error('Session expired')}return r.json()}
        function showToast(m,t='info'){const toast=document.getElementById('adminToast');toast.textContent=m;toast.className='toast '+t+' show';clearTimeout(toast._timeout);toast._timeout=setTimeout(()=>toast.classList.remove('show'),4000)}
        function closeModal(id){document.getElementById(id).classList.remove('active')}
        
        document.addEventListener('DOMContentLoaded',function(){
            loadStats();loadKycList();
            document.querySelectorAll('.filter-btn').forEach(btn=>{btn.addEventListener('click',function(){document.querySelectorAll('.filter-btn').forEach(b=>b.classList.remove('active'));this.classList.add('active');state.status=this.dataset.status;state.currentPage=0;loadKycList()})});
        });
        
        function loadStats(){
            fetch('<?php echo $basePath; ?>/api/admin_kyc.php?action=get_stats').then(handleApiResponse).then(d=>{
                if(d.success){document.getElementById('statPending').textContent=d.data.pending||0;document.getElementById('statVerified').textContent=d.data.verified||0;document.getElementById('statRejected').textContent=d.data.rejected||0;document.getElementById('statTotal').textContent=d.data.total||0}
            }).catch(()=>{});
        }
        
        function loadKycList(){
            document.getElementById('kycList').innerHTML='<div style="text-align:center;padding:40px;color:#94a3b8">Loading...</div>';
            const offset=state.currentPage*state.limit;
            const status=state.status==='all'?'':state.status;
            fetch(`<?php echo $basePath; ?>/api/admin_kyc.php?action=list&status=${status}&offset=${offset}&limit=${state.limit}&search=${encodeURIComponent(state.search)}`).then(handleApiResponse).then(d=>{
                if(d.success){
                    state.total=d.data.total;
                    document.getElementById('kycList').innerHTML=(d.data.documents||[]).map(doc=>`<div class="kyc-card">
                        <div class="kyc-header"><div><span class="user-name">${escapeHtml(doc.username||'Unknown')}</span><br><span class="user-detail">📱 ${escapeHtml(doc.mobile||'N/A')} • User #${doc.user_id}</span></div><span class="status-badge ${doc.status}">${doc.status.toUpperCase()}</span></div>
                        <div class="kyc-details"><div class="detail-item"><div class="label">Document Type</div><div class="value">${doc.document_type.toUpperCase()}</div></div><div class="detail-item"><div class="label">Document Number</div><div class="value">${escapeHtml(doc.document_number)}</div></div>${doc.rejection_reason?`<div class="detail-item"><div class="label">Rejection Reason</div><div class="value" style="color:#ef4444">${escapeHtml(doc.rejection_reason)}</div></div>`:''}</div>
                        <div class="action-buttons">${doc.status==='pending'?`<button class="btn-action verify" onclick="verifyKyc(${doc.id})">✅ Verify</button><button class="btn-action reject" onclick="openRejectModal(${doc.id})">❌ Reject</button>`:`<button class="btn-action verify" onclick="verifyKyc(${doc.id})">🔄 Re-verify</button>`}</div></div>`).join('')||'<div style="text-align:center;padding:40px;color:#94a3b8">No documents found</div>';
                }
            }).catch(()=>{document.getElementById('kycList').innerHTML='<div style="text-align:center;padding:40px;color:#ef4444">Error loading</div>'});
        }
        
        function verifyKyc(id){
            if(!confirm('Verify this KYC document?'))return;
            fetch('<?php echo $basePath; ?>/api/admin_kyc.php?action=verify',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':state.csrfToken},body:JSON.stringify({id:id,csrf_token:state.csrfToken})}).then(handleApiResponse).then(d=>{if(d.success){showToast('KYC verified!','success');loadStats();loadKycList()}else showToast(d.message||'Failed','error')}).catch(()=>showToast('Error','error'));
        }
        
        function openRejectModal(id){document.getElementById('rejectKycId').value=id;document.getElementById('rejectReason').value='';document.getElementById('rejectModal').classList.add('active')}
        
        function confirmReject(){
            const id=document.getElementById('rejectKycId').value;
            const reason=document.getElementById('rejectReason').value.trim();
            if(!reason||reason.length<10){showToast('Reason must be 10+ characters','error');return}
            fetch('<?php echo $basePath; ?>/api/admin_kyc.php?action=reject',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':state.csrfToken},body:JSON.stringify({id:parseInt(id),reason:reason,csrf_token:state.csrfToken})}).then(handleApiResponse).then(d=>{if(d.success){showToast('KYC rejected','success');closeModal('rejectModal');loadStats();loadKycList()}else showToast(d.message||'Failed','error')}).catch(()=>showToast('Error','error'));
        }
        
        function debounceSearch(){clearTimeout(state.searchTimeout);state.searchTimeout=setTimeout(()=>{state.search=document.getElementById('kycSearch').value;state.currentPage=0;loadKycList()},400)}
        function escapeHtml(s){if(!s)return'';const d=document.createElement('div');d.textContent=s;return d.innerHTML}
    </script>
</body>
</html>
