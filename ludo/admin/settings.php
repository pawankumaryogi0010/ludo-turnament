<?php
/**
 * ======================================================
 * ADMIN SETTINGS.PHP - System Settings (FIXED)
 * Ludo Tournament Platform - Admin Settings Dashboard
 * Version: 3.0.1 - API PATHS FIXED
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
$basePath = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Admin</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}body{font-family:'Inter',sans-serif;background:#0a0e1a;color:#f1f5f9;min-height:100vh}
        .admin-container{max-width:1200px;margin:0 auto;padding:20px}
        .admin-header{display:flex;justify-content:space-between;align-items:center;padding:16px 0;border-bottom:1px solid rgba(255,255,255,0.06);margin-bottom:24px;flex-wrap:wrap;gap:12px}
        .admin-header h1{font-size:24px;font-weight:700;background:linear-gradient(135deg,#fbbf24,#f59e0b);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
        .admin-header-actions{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
        .admin-header-actions a{color:#94a3b8;text-decoration:none;font-weight:600;font-size:14px;padding:8px 16px;border:1px solid rgba(255,255,255,0.06);border-radius:8px}
        .admin-header-actions a.logout{color:#ef4444;border-color:rgba(239,68,68,0.2)}
        .settings-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}
        .settings-section{background:#1a1a2e;border-radius:14px;padding:24px;border:1px solid rgba(255,255,255,0.04)}
        .settings-section h2{font-size:18px;font-weight:700;margin-bottom:20px;color:#f1f5f9}
        .form-group{margin-bottom:16px}.form-group label{display:block;font-size:13px;font-weight:600;color:#94a3b8;margin-bottom:4px}
        .form-group input,.form-group select,.form-group textarea{width:100%;padding:10px 14px;border:1px solid rgba(255,255,255,0.06);border-radius:8px;background:rgba(255,255,255,0.04);color:#f1f5f9;font-size:14px;font-family:inherit}
        .form-group input:focus,.form-group textarea:focus{outline:none;border-color:#7c3aed}
        .form-group textarea{resize:vertical;min-height:80px}
        .checkbox-label{display:flex;align-items:center;gap:8px;cursor:pointer}
        .checkbox-label input[type="checkbox"]{width:18px;height:18px;accent-color:#7c3aed}
        .btn-save{padding:12px 32px;border:none;border-radius:10px;background:linear-gradient(135deg,#fbbf24,#f59e0b);color:#1a1a2e;font-weight:700;font-size:16px;cursor:pointer;font-family:inherit;margin-top:8px}
        .btn-save:hover{transform:scale(1.02)}.btn-save:disabled{opacity:0.6}
        .maintenance-box{background:rgba(239,68,68,0.05);border:1px solid rgba(239,68,68,0.1);border-radius:12px;padding:20px;margin-top:16px}
        .maintenance-box.active{border-color:rgba(239,68,68,0.3)}
        .status-indicator{width:12px;height:12px;border-radius:50%;display:inline-block}
        .status-indicator.on{background:#ef4444;box-shadow:0 0 20px rgba(239,68,68,0.3)}
        .status-indicator.off{background:#10b981;box-shadow:0 0 20px rgba(16,185,129,0.3)}
        .btn-toggle{padding:10px 24px;border:none;border-radius:8px;font-weight:600;font-size:14px;cursor:pointer;font-family:inherit}
        .btn-toggle.active{background:rgba(239,68,68,0.2);color:#ef4444}
        .btn-toggle.inactive{background:rgba(16,185,129,0.2);color:#10b981}
        .toast{position:fixed;bottom:24px;right:24px;padding:14px 24px;border-radius:12px;font-weight:600;font-size:14px;z-index:2000;transform:translateY(100px);opacity:0;transition:all 0.4s ease}
        .toast.show{transform:translateY(0);opacity:1}.toast.success{background:rgba(16,185,129,0.2);color:#10b981}.toast.error{background:rgba(239,68,68,0.2);color:#ef4444}
        @media(max-width:768px){.settings-grid{grid-template-columns:1fr}}
    </style>
</head>
<body>
    <div class="admin-container">
        <div class="admin-header">
            <h1>⚙️ System Settings</h1>
            <div class="admin-header-actions">
                <span>👋 <?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?></span>
                <a href="index.php">← Dashboard</a><a href="kyc.php">🛡️ KYC</a><a href="withdrawals.php">🏦 Withdrawals</a><a href="disputes.php">📋 Disputes</a><a href="?logout=1" class="logout">🚪 Logout</a>
            </div>
        </div>
        <div class="settings-grid" id="settingsContainer"><div style="text-align:center;padding:40px;color:#94a3b8">Loading...</div></div>
    </div>
    <div class="toast" id="adminToast"></div>
    <script>
        // FIXED: Dynamic base path
        const BASE_PATH = '<?php echo $basePath; ?>';
        
        const SettingsApp = {
            settings: {}, csrfToken: '<?php echo $csrf_token; ?>',
            init(){this.loadSettings()},
            loadSettings(){
                fetch(BASE_PATH + '/api/admin_settings.php?action=get_settings').then(r=>r.json()).then(d=>{if(d.success){this.settings=d.data.settings;this.render()}}).catch(()=>this.showToast('Error loading settings','error'))
            },
            render(){
                const groups={financial:'💰 Financial',gameplay:'🎮 Gameplay',system:'🔧 System',kyc:'🛡️ KYC',withdrawal:'🏦 Withdrawal',referral:'🎁 Referral'};
                let html='';
                for(const[gk,gl]of Object.entries(groups)){
                    if(this.settings[gk]&&this.settings[gk].length>0){
                        html+=`<div class="settings-section" data-group="${gk}"><h2>${gl}</h2>`;
                        this.settings[gk].forEach(s=>{if(s.is_editable){html+=this.renderField(s)}});
                        if(gk==='system')html+=this.renderMaintenance();
                        html+=`<button class="btn-save" onclick="SettingsApp.saveGroup('${gk}')">💾 Save ${gl}</button></div>`;
                    }
                }
                document.getElementById('settingsContainer').innerHTML=html;
            },
            renderField(s){
                const id='setting_'+s.key;let input='';
                switch(s.type){
                    case'boolean':input=`<div class="checkbox-label"><input type="checkbox" id="${id}" ${s.value?'checked':''}><label for="${id}">Enabled</label></div>`;break;
                    case'integer':case'decimal':input=`<input type="number" id="${id}" value="${s.value}" step="${s.type==='decimal'?'0.01':'1'}">`;break;
                    case'text':input=`<textarea id="${id}" rows="3">${this.escapeHtml(String(s.value))}</textarea>`;break;
                    default:input=`<input type="text" id="${id}" value="${this.escapeHtml(String(s.value))}">`;
                }
                return `<div class="form-group"><label>${this.formatLabel(s.key)}</label>${input}</div>`;
            },
            renderMaintenance(){
                const mm=this.getSettingValue('maintenance_mode')||false;
                const msg=this.getSettingValue('maintenance_message')||'';
                return `<div class="maintenance-box ${mm?'active':''}"><div style="margin-bottom:12px"><span class="status-indicator ${mm?'on':'off'}"></span> <strong>Maintenance: ${mm?'🔴 ENABLED':'🟢 DISABLED'}</strong></div><div class="form-group"><label>Message</label><input type="text" id="maintenance_message_input" value="${this.escapeHtml(msg)}"></div><button class="btn-toggle ${mm?'active':'inactive'}" onclick="SettingsApp.toggleMaintenance()">${mm?'🔴 Disable':'🟢 Enable'}</button></div>`;
            },
            getSettingValue(key){for(const g of Object.values(this.settings)){for(const s of g){if(s.key===key)return s.value}}return null},
            saveGroup(gk){
                const el=document.querySelector(`[data-group="${gk}"]`);if(!el)return;
                const settings={};
                el.querySelectorAll('.form-group input,.form-group textarea').forEach(inp=>{
                    if(inp.id&&inp.id.startsWith('setting_')){
                        const key=inp.id.replace('setting_','');
                        let val=inp.value;
                        if(inp.type==='checkbox')val=inp.checked;
                        else if(inp.type==='number')val=parseFloat(val);
                        settings[key]=val;
                    }
                });
                if(gk==='system'){const mi=document.getElementById('maintenance_message_input');if(mi)settings['maintenance_message']=mi.value}
                this.updateSettings(settings);
            },
            updateSettings(settings){
                const btn=document.querySelector('.btn-save');btn.disabled=true;btn.textContent='Saving...';
                fetch(BASE_PATH + '/api/admin_settings.php?action=update_settings',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':this.csrfToken},body:JSON.stringify({settings:settings,csrf_token:this.csrfToken})}).then(r=>r.json()).then(d=>{if(d.success){this.showToast('Saved!','success');this.loadSettings()}else this.showToast(d.message||'Failed','error')}).catch(()=>this.showToast('Error','error')).finally(()=>{btn.disabled=false;btn.textContent='💾 Save'});
            },
            toggleMaintenance(){
                const current=this.getSettingValue('maintenance_mode')||false;
                const msg=document.getElementById('maintenance_message_input')?.value||'Maintenance in progress';
                if(!confirm(`${current?'Disable':'Enable'} maintenance?`))return;
                fetch(BASE_PATH + '/api/admin_settings.php?action=toggle_maintenance',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':this.csrfToken},body:JSON.stringify({enable:!current,message:msg,csrf_token:this.csrfToken})}).then(r=>r.json()).then(d=>{if(d.success){this.showToast(d.message,'success');this.loadSettings()}else this.showToast(d.message||'Failed','error')}).catch(()=>this.showToast('Error','error'));
            },
            formatLabel(key){return key.replace(/_/g,' ').replace(/\b\w/g,l=>l.toUpperCase())},
            escapeHtml(s){if(!s)return'';const d=document.createElement('div');d.textContent=s;return d.innerHTML},
            showToast(m,t='info'){const toast=document.getElementById('adminToast');toast.textContent=m;toast.className='toast '+t+' show';clearTimeout(toast._timeout);toast._timeout=setTimeout(()=>toast.classList.remove('show'),4000)}
        };
        document.addEventListener('DOMContentLoaded',()=>SettingsApp.init());
    </script>
</body>
</html>
