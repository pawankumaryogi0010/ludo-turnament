<?php
/**
 * ======================================================
 * ADMIN_SETTINGS.PHP - System Settings API (FIXED)
 * Ludo Tournament Platform - Admin Settings Management
 * Version: 3.0.0 - ALL FIXES APPLIED
 * ======================================================
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once dirname(__DIR__) . '/config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Access-Control-Allow-Origin: ' . BASE_URL);
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-CSRF-Token');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();

// AUTHENTICATION
if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_token'])) {
    jsonResponse(false, 'Unauthorized', [], 401);
}

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("
        SELECT u.id FROM users u
        JOIN sessions s ON u.id = s.user_id
        WHERE u.id = :aid AND u.is_admin = 1 AND u.is_active = 1
        AND s.session_token = :token AND s.is_active = 1 AND s.expires_at > NOW()
    ");
    $stmt->execute([':aid' => $_SESSION['admin_id'], ':token' => $_SESSION['admin_token']]);
    if (!$stmt->fetch()) {
        jsonResponse(false, 'Unauthorized', [], 401);
    }
} catch (Exception $e) {
    jsonResponse(false, 'Auth error', [], 500);
}

// ROUTING
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get_settings': handleGetSettings(); break;
    case 'update_settings': handleUpdateSettings(); break;
    case 'toggle_maintenance': handleToggleMaintenance(); break;
    case 'get_maintenance_status': handleGetMaintenanceStatus(); break;
    default: jsonResponse(false, 'Invalid action', [], 400);
}

// ==============================================
// GET ALL SETTINGS
// ==============================================
function handleGetSettings() {
    global $db, $conn;
    
    try {
        $stmt = $conn->prepare("
            SELECT setting_key, setting_value, setting_group, setting_type, description, is_editable
            FROM system_settings ORDER BY setting_group, setting_key
        ");
        $stmt->execute();
        $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Group settings
        $grouped = [];
        foreach ($settings as $setting) {
            $group = $setting['setting_group'];
            if (!isset($grouped[$group])) $grouped[$group] = [];
            
            $value = $setting['setting_value'];
            switch ($setting['setting_type']) {
                case 'boolean': $value = (bool)$value; break;
                case 'integer': $value = (int)$value; break;
                case 'decimal': $value = (float)$value; break;
                case 'json': $value = json_decode($value, true); break;
            }
            
            $grouped[$group][] = [
                'key' => $setting['setting_key'],
                'value' => $value,
                'type' => $setting['setting_type'],
                'description' => $setting['description'],
                'is_editable' => (bool)$setting['is_editable']
            ];
        }
        
        jsonResponse(true, 'Settings retrieved', ['settings' => $grouped]);
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// UPDATE SETTINGS
// ==============================================
function handleUpdateSettings() {
    global $db, $conn;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['settings']) || !is_array($input['settings'])) {
        jsonResponse(false, 'Invalid settings data', [], 400);
    }
    
    if (!CSRFToken::validate($input['csrf_token'] ?? '')) {
        jsonResponse(false, 'Invalid CSRF', [], 403);
    }
    
    try {
        $db->beginTransaction();
        
        $updatedCount = 0;
        
        foreach ($input['settings'] as $key => $value) {
            $stmt = $conn->prepare("SELECT setting_key, setting_type, is_editable FROM system_settings WHERE setting_key = :key");
            $stmt->execute([':key' => $key]);
            $setting = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$setting || !$setting['is_editable']) continue;
            
            $validatedValue = validateSettingValue($value, $setting['setting_type']);
            if ($validatedValue === false) continue;
            
            $stmt = $conn->prepare("UPDATE system_settings SET setting_value = :val, updated_at = CURRENT_TIMESTAMP WHERE setting_key = :key");
            $stmt->execute([':val' => (string)$validatedValue, ':key' => $key]);
            
            $updatedCount++;
        }
        
        // Log action
        $stmt = $conn->prepare("
            INSERT INTO maintenance_logs (action, details, admin_id, ip_address, created_at)
            VALUES ('settings_updated', :details, :admin_id, :ip, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            ':details' => json_encode(['updated_count' => $updatedCount]),
            ':admin_id' => $_SESSION['admin_id'],
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        
        $db->commit();
        jsonResponse(true, "{$updatedCount} settings updated");
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollback();
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// TOGGLE MAINTENANCE MODE
// ==============================================
function handleToggleMaintenance() {
    global $db, $conn;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['enable'])) {
        jsonResponse(false, 'Missing enable parameter', [], 400);
    }
    
    if (!CSRFToken::validate($input['csrf_token'] ?? '')) {
        jsonResponse(false, 'Invalid CSRF', [], 403);
    }
    
    $enable = (bool)$input['enable'];
    $message = $input['message'] ?? 'We are currently performing scheduled maintenance.';
    
    try {
        $db->beginTransaction();
        
        $stmt = $conn->prepare("UPDATE system_settings SET setting_value = :val WHERE setting_key = 'maintenance_mode'");
        $stmt->execute([':val' => $enable ? '1' : '0']);
        
        if ($enable) {
            $stmt = $conn->prepare("UPDATE system_settings SET setting_value = :msg WHERE setting_key = 'maintenance_message'");
            $stmt->execute([':msg' => $message]);
        }
        
        // Log
        $stmt = $conn->prepare("
            INSERT INTO maintenance_logs (action, details, admin_id, ip_address, created_at)
            VALUES (:action, :details, :admin_id, :ip, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            ':action' => $enable ? 'maintenance_enabled' : 'maintenance_disabled',
            ':details' => json_encode(['message' => $message]),
            ':admin_id' => $_SESSION['admin_id'],
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        
        $db->commit();
        jsonResponse(true, $enable ? 'Maintenance mode enabled' : 'Maintenance mode disabled', [
            'maintenance_mode' => $enable,
            'message' => $message
        ]);
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollback();
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// GET MAINTENANCE STATUS
// ==============================================
function handleGetMaintenanceStatus() {
    global $db, $conn;
    
    try {
        $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('maintenance_mode', 'maintenance_message')");
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $data = ['maintenance_mode' => false, 'maintenance_message' => ''];
        foreach ($results as $row) {
            if ($row['setting_key'] === 'maintenance_mode') $data['maintenance_mode'] = (bool)$row['setting_value'];
            elseif ($row['setting_key'] === 'maintenance_message') $data['maintenance_message'] = $row['setting_value'];
        }
        
        jsonResponse(true, 'Maintenance status', $data);
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// VALIDATE SETTING VALUE
// ==============================================
function validateSettingValue($value, $type) {
    switch ($type) {
        case 'boolean': return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        case 'integer': $int = filter_var($value, FILTER_VALIDATE_INT); return $int !== false ? $int : false;
        case 'decimal': $float = filter_var($value, FILTER_VALIDATE_FLOAT); return $float !== false ? $float : false;
        case 'json': $decoded = json_decode($value, true); return $decoded !== null ? $decoded : false;
        case 'string': case 'text': return (string)$value;
        default: return $value;
    }
}
?>
