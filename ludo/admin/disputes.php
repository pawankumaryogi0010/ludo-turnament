<?php
/**
 * ======================================================
 * ADMIN DISPUTES.PHP - Dispute Management UI (FIXED)
 * Ludo Tournament Platform - Admin Dispute Dashboard
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
$statusFilter = $_GET['status'] ?? 'open';
$basePath = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dispute Management - Admin</title>
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
        .ticket-list{display:grid;gap:16px}
        .ticket-card{background:#1a1a2e;border-radius:14px;padding:20px;border:1px solid rgba(255,255,255,0.04);border-left:4px solid #64748b}
        .ticket-card.priority-urgent{border-left-color:#ef4444}
        .ticket-card.priority-high{border-left-color:#f59e0b}
        .ticket-card .ticket-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;flex-wrap:wrap;gap:8px}
        .ticket-card .ticket-subject{font-size:16px;font-weight:700;color:#f1f5f9}
        .ticket-card .ticket-meta{font-size:13px;color:#94a3b8}
        .status-badge{padding:4px 14px;border-radius:20px;font-size:12px;font-weight:600}
        .status-badge.open{background:rgba(239,68,68,0.15);color:#ef4444}
        .status-badge.investigating{background:rgba(245,158,11,0.15);color:#f59e0b}
        .status-badge.resolved{background:rgba(16,185,129,0.15);color:#10b981}
        .priority-badge{padding:2px 10px;border-radius:12px;font-size:11px;font-weight:600}
        .priority-badge.urgent{background:rgba(239,68,68,0.2);color:#ef4444}
        .priority-badge.high{background:rgba(245,158,11,0.2);color:#f59e0b}
        .action-buttons{display:flex;gap:10px;margin-top:12px;flex-wrap:wrap}
        .btn-action{padding:8px 20px;border:none;border-radius:8px;font-weight:600;font-size:13px;cursor:pointer;font-family:inherit}
        .btn-action.investigate{background:rgba(245,158,11,0.15);color:#f59e0b}
        .btn-action.resolve{background:rgba(16,185,129,0.15);color:#10b981}
        .btn-action.close{background:rgba(148,163,184,0.15);color:#94a3b8}
        .btn-action.view{background:rgba(139,92,246,0.15);color:#8b5cf6}
        .modal-overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);backdrop-filter:blur(8px);z-index:1000;justify-content:center;align-items:center;padding:20px}
        .modal-overlay.active{display:flex}
        .modal-box{background:#1a1a2e;padding:32px;border-radius:16px;max-width:600px;width:100%;border:1px solid rgba(255,255,255,0.06);max-height:90vh;overflow-y:auto}
        .modal-box h2{font-size:20px;font-weight:700;margin-bottom:16px}
        .form-group{margin-bottom:14px}
        .form-group label{display:block;font-size:13px;font-weight:600;color:#94a3b8;margin-bottom:4px}
        .form-group input,.form-group select,.form-group textarea{width:100%;padding:10px 14px;border:1px solid rgba(255,255,255,0.06);border-radius:8px;background:rgba(255,255,255,0.04);color:#f1f5f9;font-size:14px;font-family:inherit}
        .form-group select option{background:#1a1a2e}
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
            <h1>📋 Dispute Management</h1>
            <div class="admin-header-actions">
                <span>👋 <?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?></span>
                <a href="index.php">← Dashboard</a>
                <a href="settings.php">⚙️ Settings</a>
                <a href="kyc.php">🛡️ KYC</a>
                <a href="withdrawals.php">🏦 Withdrawals</a>
                <a href="?logout=1" class="logout">🚪 Logout</a>
            </div>
        </div>
        
        <div class="stats-bar" id="statsBar">
            <div class="stat-card"><div class="stat-number" style="color:#ef4444" id="statOpen">...</div><div class="stat-label">Open</div></div>
            <div class="stat-card"><div class="stat-number" style="color:#f59e0b" id="statInvestigating">...</div><div class="stat-label">Investigating</div></div>
            <div class="stat-card"><div class="stat-number" style="color:#10b981" id="statResolved">...</div><div class="stat-label">Resolved</div></div>
            <div class="stat-card"><div class="stat-number" id="statClosed">...</div><div class="stat-label">Closed</div></div>
        </div>
        
        <div class="filter-bar">
            <button class="filter-btn <?php echo $statusFilter==='open'?'active':''; ?>" data-status="open">🟡 Open</button>
            <button class="filter-btn <?php echo $statusFilter==='investigating'?'active':''; ?>" data-status="investigating">🔍 Investigating</button>
            <button class="filter-btn <?php echo $statusFilter==='resolved'?'active':''; ?>" data-status="resolved">✅ Resolved</button>
            <button class="filter-btn <?php echo $statusFilter==='closed'?'active':''; ?>" data-status="closed">🔒 Closed</button>
            <button class="filter-btn <?php echo $statusFilter==='all'?'active':''; ?>" data-status="all">📋 All</button>
        </div>
        
        <div class="ticket-list" id="ticketList"><div style="text-align:center;padding:40px;color:#94a3b8">Loading...</div></div>
    </div>
    
    <div class="modal-overlay" id="resolveModal">
        <div class="modal-box">
            <h2>✅ Resolve Ticket</h2>
            <input type="hidden" id="resolveTicketId">
            <div class="form-group"><label>Resolution Type</label><select id="resolutionType"><option value="winner_declared">🏆 Declare Winner</option><option value="refund">💰 Refund</option><option value="cancelled">❌ Cancel</option><option value="no_action">⏭️ No Action</option></select></div>
            <div class="form-group" id="winnerField"><label>Winner User ID</label><input type="number" id="winnerId"></div>
            <div class="form-group" id="refundField" style="display:none"><label>Refund Amount (₹)</label><input type="number" id="refundAmount" step="0.01"></div>
            <div class="form-group"><label>Notes</label><textarea id="resolutionNotes"></textarea></div>
            <div class="modal-actions"><button class="btn-confirm" onclick="confirmResolve()">✅ Resolve</button><button class="btn-cancel" onclick="closeModal('resolveModal')">Cancel</button></div>
        </div>
    </div>
    
    <div class="toast" id="adminToast"></div>
    
    <script>
        let state = {currentPage:0,limit:50,total:0,status:'<?php echo $statusFilter; ?>',csrfToken:'<?php echo $csrf_token; ?>'};
        
        function handleApiResponse(r){if(r.status===401){showToast('Session expired','error');setTimeout(()=>location.href='index.php',1500);throw new Error('Session expired')}return r.json()}
        function showToast(m,t='info'){const toast=document.getElementById('adminToast');toast.textContent=m;toast.className='toast '+t+' show';clearTimeout(toast._timeout);toast._timeout=setTimeout(()=>toast.classList.remove('show'),4000)}
        function closeModal(id){document.getElementById(id).classList.remove('active')}
        
        document.addEventListener('DOMContentLoaded',function(){
            loadStats();loadTickets();
            document.querySelectorAll('.filter-btn').forEach(btn=>{btn.addEventListener('click',function(){document.querySelectorAll('.filter-btn').forEach(b=>b.classList.remove('active'));this.classList.add('active');state.status=this.dataset.status;state.currentPage=0;loadTickets()})});
            document.getElementById('resolutionType').addEventListener('change',function(){document.getElementById('winnerField').style.display=this.value==='winner_declared'?'block':'none';document.getElementById('refundField').style.display=this.value==='refund'?'block':'none'});
        });
        
        function loadStats(){
            fetch('<?php echo $basePath; ?>/api/admin_disputes.php?action=get_stats').then(handleApiResponse).then(d=>{
                if(d.success){document.getElementById('statOpen').textContent=d.data.open||0;document.getElementById('statInvestigating').textContent=d.data.investigating||0;document.getElementById('statResolved').textContent=d.data.resolved||0;document.getElementById('statClosed').textContent=d.data.closed||0}
            }).catch(()=>{});
        }
        
        function loadTickets(){
            document.getElementById('ticketList').innerHTML='<div style="text-align:center;padding:40px;color:#94a3b8">Loading...</div>';
            const status=state.status==='all'?'':state.status;
            fetch(`<?php echo $basePath; ?>/api/admin_disputes.php?action=list&status=${status}&offset=0&limit=${state.limit}`).then(handleApiResponse).then(d=>{
                if(d.success){
                    document.getElementById('ticketList').innerHTML=(d.data.tickets||[]).map(t=>`<div class="ticket-card priority-${t.priority}">
                        <div class="ticket-header"><div><span class="ticket-subject">#${escapeHtml(t.ticket_number)} - ${escapeHtml(t.subject)}</span><br><span class="ticket-meta">👤 ${escapeHtml(t.user_name||'Unknown')} • Room: ${escapeHtml(t.room_code||'N/A')} • ₹${parseFloat(t.entry_fee||0).toFixed(2)}</span></div><div style="display:flex;gap:8px"><span class="priority-badge ${t.priority}">${t.priority.toUpperCase()}</span><span class="status-badge ${t.status}">${t.status.toUpperCase()}</span></div></div>
                        <div class="action-buttons">${t.status==='open'?`<button class="btn-action investigate" onclick="investigateTicket(${t.id})">🔍 Investigate</button>`:''}${['open','investigating'].includes(t.status)?`<button class="btn-action resolve" onclick="openResolveModal(${t.id})">✅ Resolve</button>`:''}${t.status==='resolved'?`<button class="btn-action close" onclick="closeTicket(${t.id})">🔒 Close</button>`:''}</div></div>`).join('')||'<div style="text-align:center;padding:40px;color:#94a3b8">No tickets</div>';
                }
            }).catch(()=>{document.getElementById('ticketList').innerHTML='<div style="text-align:center;padding:40px;color:#ef4444">Error</div>'});
        }
        
        function investigateTicket(id){
            if(!confirm('Mark as investigating?'))return;
            fetch('<?php echo $basePath; ?>/api/admin_disputes.php?action=investigate',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':state.csrfToken},body:JSON.stringify({id:id,csrf_token:state.csrfToken})}).then(handleApiResponse).then(d=>{if(d.success){showToast('Investigating','success');loadTickets();loadStats()}else showToast(d.message||'Failed','error')}).catch(()=>showToast('Error','error'));
        }
        
        function openResolveModal(id){document.getElementById('resolveTicketId').value=id;document.getElementById('resolutionType').value='no_action';document.getElementById('winnerId').value='';document.getElementById('refundAmount').value='';document.getElementById('resolutionNotes').value='';document.getElementById('winnerField').style.display='none';document.getElementById('refundField').style.display='none';document.getElementById('resolveModal').classList.add('active')}
        
        function confirmResolve(){
            const id=document.getElementById('resolveTicketId').value;
            const type=document.getElementById('resolutionType').value;
            const notes=document.getElementById('resolutionNotes').value.trim();
            const payload={id:parseInt(id),resolution_type:type,resolution_notes:notes,csrf_token:state.csrfToken};
            if(type==='winner_declared')payload.winner_id=parseInt(document.getElementById('winnerId').value);
            if(type==='refund')payload.refund_amount=parseFloat(document.getElementById('refundAmount').value);
            fetch('<?php echo $basePath; ?>/api/admin_disputes.php?action=resolve',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':state.csrfToken},body:JSON.stringify(payload)}).then(handleApiResponse).then(d=>{if(d.success){showToast('Resolved!','success');closeModal('resolveModal');loadTickets();loadStats()}else showToast(d.message||'Failed','error')}).catch(()=>showToast('Error','error'));
        }
        
        function closeTicket(id){
            if(!confirm('Close this ticket?'))return;
            fetch('<?php echo $basePath; ?>/api/admin_disputes.php?action=close',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':state.csrfToken},body:JSON.stringify({id:id,csrf_token:state.csrfToken})}).then(handleApiResponse).then(d=>{if(d.success){showToast('Closed','success');loadTickets();loadStats()}else showToast(d.message||'Failed','error')}).catch(()=>showToast('Error','error'));
        }
        
        function escapeHtml(s){if(!s)return'';const d=document.createElement('div');d.textContent=s;return d.innerHTML}
    </script>
</body>
</html>
