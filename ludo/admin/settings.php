<?php
/**
 * ======================================================
 * ADMIN SETTINGS.PHP - System Settings UI
 * Ludo Tournament Platform - Admin Settings Dashboard
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
    <title>System Settings - Admin Dashboard</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #0a0e1a;
            color: #f1f5f9;
            min-height: 100vh;
        }
        
        .admin-container {
            max-width: 1200px;
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
        
        /* Settings Grid */
        .settings-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .settings-section {
            background: #1a1a2e;
            border-radius: 14px;
            padding: 24px;
            border: 1px solid rgba(255, 255, 255, 0.04);
        }
        
        .settings-section h2 {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 8px;
            color: #f1f5f9;
        }
        
        .settings-section .section-desc {
            font-size: 13px;
            color: #94a3b8;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 16px;
        }
        
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #94a3b8;
            margin-bottom: 4px;
        }
        
        .form-group .field-desc {
            font-size: 11px;
            color: #64748b;
            margin-top: 2px;
        }
        
        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group input[type="password"],
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.04);
            color: #f1f5f9;
            font-size: 14px;
            font-family: inherit;
            transition: border-color 0.2s;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #7c3aed;
        }
        
        .form-group input::placeholder,
        .form-group textarea::placeholder {
            color: #64748b;
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        
        .form-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #7c3aed;
            cursor: pointer;
            margin-right: 8px;
        }
        
        .form-group .checkbox-label {
            display: flex;
            align-items: center;
            cursor: pointer;
            font-size: 14px;
            color: #f1f5f9;
        }
        
        .btn-save {
            padding: 12px 32px;
            border: none;
            border-radius: 10px;
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            color: #1a1a2e;
            font-weight: 700;
            font-size: 16px;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            font-family: inherit;
            margin-top: 8px;
        }
        
        .btn-save:hover {
            transform: scale(1.02);
            box-shadow: 0 0 30px rgba(251, 191, 36, 0.2);
        }
        
        .btn-save:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        /* Maintenance Toggle */
        .maintenance-box {
            background: rgba(239, 68, 68, 0.05);
            border: 1px solid rgba(239, 68, 68, 0.1);
            border-radius: 12px;
            padding: 20px;
            margin-top: 16px;
        }
        
        .maintenance-box.active {
            border-color: rgba(239, 68, 68, 0.3);
        }
        
        .maintenance-status {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }
        
        .status-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
        }
        
        .status-indicator.on {
            background: #ef4444;
            box-shadow: 0 0 20px rgba(239, 68, 68, 0.3);
        }
        
        .status-indicator.off {
            background: #10b981;
            box-shadow: 0 0 20px rgba(16, 185, 129, 0.3);
        }
        
        .btn-toggle {
            padding: 10px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
            font-family: inherit;
        }
        
        .btn-toggle.active {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
        }
        
        .btn-toggle.active:hover {
            background: rgba(239, 68, 68, 0.3);
        }
        
        .btn-toggle.inactive {
            background: rgba(16, 185, 129, 0.2);
            color: #10b981;
        }
        
        .btn-toggle.inactive:hover {
            background: rgba(16, 185, 129, 0.3);
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
        
        /* Loading */
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
        
        @media (max-width: 768px) {
            .settings-grid {
                grid-template-columns: 1fr;
            }
            
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
            <h1>⚙️ System Settings</h1>
            <div class="admin-header-actions">
                <span>👋 <?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?></span>
                <a href="index.php">← Back to Dashboard</a>
                <a href="kyc.php">🛡️ KYC</a>
                <a href="withdrawals.php">🏦 Withdrawals</a>
                <a href="disputes.php">📋 Disputes</a>
                <a href="?logout=1" class="logout">🚪 Logout</a>
            </div>
        </div>
        
        <!-- Settings Grid -->
        <div class="settings-grid" id="settingsContainer">
            <div class="loading">
                <div class="loading-spinner"></div>
                <p>Loading settings...</p>
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
            csrfToken: '<?php echo $csrf_token; ?>'
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
        // MAIN SETTINGS APP
        // ==============================================
        const SettingsApp = {
            settings: {},
            csrfToken: '<?php echo $csrf_token; ?>',
            
            init() {
                this.loadSettings();
            },
            
            loadSettings() {
                fetch('/api/admin_settings.php?action=get_settings')
                    .then(handleApiResponse)
                    .then(data => {
                        if (data.success) {
                            this.settings = data.data.settings;
                            this.renderSettings();
                        } else {
                            this.showToast(data.message || 'Failed to load settings', 'error');
                        }
                    })
                    .catch(() => {
                        this.showToast('Network error loading settings', 'error');
                    });
            },
            
            renderSettings() {
                const container = document.getElementById('settingsContainer');
                let html = '';
                
                const groups = {
                    'financial': '💰 Financial Settings',
                    'gameplay': '🎮 Gameplay Settings',
                    'system': '🔧 System Settings',
                    'kyc': '🛡️ KYC & Verification',
                    'withdrawal': '🏦 Withdrawal Settings',
                    'referral': '🎁 Referral Settings'
                };
                
                for (const [groupKey, groupLabel] of Object.entries(groups)) {
                    if (this.settings[groupKey] && this.settings[groupKey].length > 0) {
                        html += this.renderGroup(groupKey, groupLabel, this.settings[groupKey]);
                    }
                }
                
                container.innerHTML = html;
                
                // Add event listeners
                this.bindEvents();
            },
            
            renderGroup(groupKey, groupLabel, settings) {
                let html = `
                    <div class="settings-section" data-group="${groupKey}">
                        <h2>${groupLabel}</h2>
                `;
                
                settings.forEach(setting => {
                    if (setting.is_editable) {
                        html += this.renderSettingField(setting);
                    }
                });
                
                // Add maintenance toggle for system group
                if (groupKey === 'system') {
                    html += this.renderMaintenanceToggle();
                }
                
                html += `
                        <button class="btn-save" onclick="SettingsApp.saveGroup('${groupKey}')">
                            💾 Save ${groupLabel}
                        </button>
                    </div>
                `;
                
                return html;
            },
            
            renderSettingField(setting) {
                const id = `setting_${setting.key}`;
                const value = setting.value;
                const desc = setting.description || '';
                
                let inputHtml = '';
                
                switch (setting.type) {
                    case 'boolean':
                        inputHtml = `
                            <div class="checkbox-label">
                                <input type="checkbox" id="${id}" ${value ? 'checked' : ''}>
                                <label for="${id}">Enabled</label>
                            </div>
                        `;
                        break;
                    case 'integer':
                    case 'decimal':
                        inputHtml = `
                            <input type="number" id="${id}" value="${value}" step="${setting.type === 'decimal' ? '0.01' : '1'}" min="0">
                        `;
                        break;
                    case 'text':
                        inputHtml = `
                            <textarea id="${id}" rows="3">${this.escapeHtml(String(value))}</textarea>
                        `;
                        break;
                    case 'string':
                    default:
                        inputHtml = `
                            <input type="text" id="${id}" value="${this.escapeHtml(String(value))}">
                        `;
                        break;
                }
                
                return `
                    <div class="form-group">
                        <label for="${id}">${this.formatLabel(setting.key)}</label>
                        ${inputHtml}
                        ${desc ? `<div class="field-desc">${desc}</div>` : ''}
                    </div>
                `;
            },
            
            renderMaintenanceToggle() {
                const maintenanceMode = this.getSettingValue('maintenance_mode') || false;
                const maintenanceMessage = this.getSettingValue('maintenance_message') || '';
                
                return `
                    <div class="maintenance-box ${maintenanceMode ? 'active' : ''}">
                        <div class="maintenance-status">
                            <span class="status-indicator ${maintenanceMode ? 'on' : 'off'}"></span>
                            <strong style="font-size: 16px;">
                                Maintenance Mode: ${maintenanceMode ? '🔴 ENABLED' : '🟢 DISABLED'}
                            </strong>
                        </div>
                        <div class="form-group">
                            <label>Maintenance Message</label>
                            <input type="text" id="maintenance_message_input" value="${this.escapeHtml(maintenanceMessage)}" placeholder="Enter maintenance message...">
                        </div>
                        <button class="btn-toggle ${maintenanceMode ? 'active' : 'inactive'}" onclick="SettingsApp.toggleMaintenance()">
                            ${maintenanceMode ? '🔴 Disable Maintenance' : '🟢 Enable Maintenance'}
                        </button>
                    </div>
                `;
            },
            
            getSettingValue(key) {
                for (const group of Object.values(this.settings)) {
                    for (const setting of group) {
                        if (setting.key === key) {
                            return setting.value;
                        }
                    }
                }
                return null;
            },
            
            saveGroup(groupKey) {
                const groupElement = document.querySelector(`[data-group="${groupKey}"]`);
                if (!groupElement) return;
                
                const settings = {};
                const inputs = groupElement.querySelectorAll('.form-group input, .form-group textarea, .form-group select');
                
                inputs.forEach(input => {
                    if (input.id && input.id.startsWith('setting_')) {
                        const key = input.id.replace('setting_', '');
                        let value = input.value;
                        
                        if (input.type === 'checkbox') {
                            value = input.checked;
                        } else if (input.type === 'number') {
                            value = parseFloat(value);
                        }
                        
                        settings[key] = value;
                    }
                });
                
                // Add maintenance message if it's the system group
                if (groupKey === 'system') {
                    const msgInput = document.getElementById('maintenance_message_input');
                    if (msgInput) {
                        settings['maintenance_message'] = msgInput.value;
                    }
                }
                
                this.updateSettings(settings);
            },
            
            updateSettings(settings) {
                const saveBtn = document.querySelector('.btn-save');
                saveBtn.disabled = true;
                saveBtn.textContent = 'Saving...';
                
                fetch('/api/admin_settings.php?action=update_settings', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': this.csrfToken
                    },
                    body: JSON.stringify({
                        settings: settings,
                        csrf_token: this.csrfToken
                    })
                })
                .then(handleApiResponse)
                .then(data => {
                    if (data.success) {
                        this.showToast('Settings saved successfully!', 'success');
                        this.loadSettings();
                    } else {
                        this.showToast(data.message || 'Failed to save settings', 'error');
                    }
                })
                .catch(() => {
                    this.showToast('Network error saving settings', 'error');
                })
                .finally(() => {
                    saveBtn.disabled = false;
                    saveBtn.textContent = '💾 Save Settings';
                });
            },
            
            toggleMaintenance() {
                const currentStatus = this.getSettingValue('maintenance_mode') || false;
                const message = document.getElementById('maintenance_message_input')?.value || 'We are currently performing scheduled maintenance. Please check back later.';
                
                if (!confirm(`Are you sure you want to ${currentStatus ? 'disable' : 'enable'} maintenance mode?`)) {
                    return;
                }
                
                fetch('/api/admin_settings.php?action=toggle_maintenance', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': this.csrfToken
                    },
                    body: JSON.stringify({
                        enable: !currentStatus,
                        message: message,
                        csrf_token: this.csrfToken
                    })
                })
                .then(handleApiResponse)
                .then(data => {
                    if (data.success) {
                        this.showToast(data.message, 'success');
                        this.loadSettings();
                    } else {
                        this.showToast(data.message || 'Failed to toggle maintenance', 'error');
                    }
                })
                .catch(() => {
                    this.showToast('Network error', 'error');
                });
            },
            
            bindEvents() {
                // Auto-save on checkbox change
                document.querySelectorAll('input[type="checkbox"]').forEach(input => {
                    input.addEventListener('change', function() {
                        const groupElement = this.closest('.settings-section');
                        if (groupElement) {
                            const groupKey = groupElement.dataset.group;
                            // Optional: auto-save for boolean settings
                        }
                    });
                });
            },
            
            formatLabel(key) {
                return key
                    .replace(/_/g, ' ')
                    .replace(/\b\w/g, l => l.toUpperCase());
            },
            
            escapeHtml(str) {
                if (!str) return '';
                const div = document.createElement('div');
                div.textContent = str;
                return div.innerHTML;
            },
            
            showToast(message, type = 'info') {
                const toast = document.getElementById('adminToast');
                toast.textContent = message;
                toast.className = 'toast ' + type + ' show';
                
                clearTimeout(toast._timeout);
                toast._timeout = setTimeout(() => {
                    toast.classList.remove('show');
                }, 4000);
            }
        };
        
        // ==============================================
        // INITIALIZE
        // ==============================================
        document.addEventListener('DOMContentLoaded', () => {
            SettingsApp.init();
        });
    </script>
</body>
</html>
