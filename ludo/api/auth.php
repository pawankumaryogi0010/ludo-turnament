<?php
/**
 * ======================================================
 * AUTH.PHP - Authentication API
 * Ludo Tournament Platform - Complete Auth System
 * Version: 3.0.0 - PRODUCTION READY
 * ======================================================
 */

// Prevent direct access
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once dirname(__DIR__) . '/config/db.php';

// Set headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

header('Access-Control-Allow-Origin: ' . BASE_URL);
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-CSRF-Token');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ==============================================
// ROUTING
// ==============================================
$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

switch ($action) {
    case 'login':
        handleLogin();
        break;
    case 'register':
        handleRegister();
        break;
    case 'logout':
        handleLogout();
        break;
    case 'check':
        handleCheckAuth();
        break;
    default:
        jsonResponse(false, 'Invalid action specified', [], 400);
        break;
}

// ==============================================
// HANDLER: Login
// ==============================================
function handleLogin() {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    // Validate required fields
    if (!isset($input['mobile']) || !isset($input['password'])) {
        jsonResponse(false, 'Mobile number and password required', [], 400);
    }
    
    $mobile = trim($input['mobile']);
    $password = $input['password'];
    
    // Validate CSRF token
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!CSRFToken::validate($csrfToken)) {
        jsonResponse(false, 'Invalid CSRF token', [], 403);
    }
    
    // Validate mobile number
    if (!preg_match('/^[0-9]{10}$/', $mobile)) {
        jsonResponse(false, 'Invalid mobile number format', [], 400);
    }
    
    if (strlen($password) < 8) {
        jsonResponse(false, 'Password must be at least 8 characters', [], 400);
    }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        // Get user with lock
        $stmt = $conn->prepare("
            SELECT id, username, mobile, password_hash, is_active, is_verified, kyc_status,
                   wallet_balance, total_matches_played, total_matches_won, total_earnings, elo_rating
            FROM users 
            WHERE mobile = :mobile
        ");
        $stmt->execute([':mobile' => $mobile]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            jsonResponse(false, 'Invalid mobile number or password', [], 401);
        }
        
        if ($user['is_active'] != 1) {
            jsonResponse(false, 'Account is deactivated. Please contact support.', [], 403);
        }
        
        if (!password_verify($password, $user['password_hash'])) {
            // Log failed attempt
            $stmt = $conn->prepare("
                UPDATE users 
                SET failed_login_attempts = COALESCE(failed_login_attempts, 0) + 1 
                WHERE id = :user_id
            ");
            $stmt->execute([':user_id' => $user['id']]);
            jsonResponse(false, 'Invalid mobile number or password', [], 401);
        }
        
        // Reset failed attempts
        $stmt = $conn->prepare("
            UPDATE users 
            SET failed_login_attempts = 0, last_login = CURRENT_TIMESTAMP 
            WHERE id = :user_id
        ");
        $stmt->execute([':user_id' => $user['id']]);
        
        // Generate session token
        $sessionToken = bin2hex(random_bytes(64));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . SESSION_TIMEOUT . ' seconds'));
        
        // Store session in database
        $stmt = $conn->prepare("
            INSERT INTO sessions (
                user_id,
                session_token,
                ip_address,
                user_agent,
                device_type,
                expires_at,
                is_active,
                created_at
            ) VALUES (
                :user_id,
                :token,
                :ip,
                :user_agent,
                :device,
                :expires_at,
                1,
                CURRENT_TIMESTAMP
            )
        ");
        $stmt->execute([
            ':user_id' => $user['id'],
            ':token' => $sessionToken,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            ':device' => 'Web',
            ':expires_at' => $expiresAt
        ]);
        
        // Set session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_token'] = $sessionToken;
        $_SESSION['session_init_time'] = time();
        
        // Generate new CSRF token
        $newCsrfToken = CSRFToken::generate();
        
        // Return user data
        unset($user['password_hash']);
        $user['wallet_balance'] = floatval($user['wallet_balance']);
        $user['total_earnings'] = floatval($user['total_earnings']);
        
        jsonResponse(true, 'Login successful', [
            'user' => $user,
            'csrf_token' => $newCsrfToken,
            'session_expires' => $expiresAt
        ]);
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    } catch (Exception $e) {
        jsonResponse(false, 'Error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Register
// ==============================================
function handleRegister() {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    // Validate required fields
    $required = ['username', 'mobile', 'password'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || empty($input[$field])) {
            jsonResponse(false, "Missing required field: {$field}", [], 400);
        }
    }
    
    $username = trim($input['username']);
    $mobile = trim($input['mobile']);
    $password = $input['password'];
    $referralCode = trim($input['referral_code'] ?? '');
    $email = trim($input['email'] ?? '');
    
    // Validate CSRF token
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!CSRFToken::validate($csrfToken)) {
        jsonResponse(false, 'Invalid CSRF token', [], 403);
    }
    
    // Validate username
    if (strlen($username) < 3 || strlen($username) > 50) {
        jsonResponse(false, 'Username must be between 3 and 50 characters', [], 400);
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        jsonResponse(false, 'Username can only contain letters, numbers, and underscores', [], 400);
    }
    
    // Validate mobile
    if (!preg_match('/^[0-9]{10}$/', $mobile)) {
        jsonResponse(false, 'Invalid mobile number format', [], 400);
    }
    
    // Validate password with strength check
    if (strlen($password) < 8) {
        jsonResponse(false, 'Password must be at least 8 characters', [], 400);
    }
    if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        jsonResponse(false, 'Password must contain at least one uppercase, one lowercase, and one number', [], 400);
    }
    
    // Validate email if provided
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(false, 'Invalid email format', [], 400);
    }
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $db->beginTransaction();
        
        // Check if mobile already exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE mobile = :mobile");
        $stmt->execute([':mobile' => $mobile]);
        if ($stmt->fetch()) {
            $db->rollback();
            jsonResponse(false, 'Mobile number already registered', [], 409);
        }
        
        // Check if username already exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = :username");
        $stmt->execute([':username' => $username]);
        if ($stmt->fetch()) {
            $db->rollback();
            jsonResponse(false, 'Username already taken', [], 409);
        }
        
        // Generate referral code
        $referCode = generateReferralCode();
        
        // Hash password
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        
        // Check referral code
        $referredBy = null;
        if (!empty($referralCode)) {
            $stmt = $conn->prepare("SELECT id FROM users WHERE refer_code = :code");
            $stmt->execute([':code' => $referralCode]);
            $referrer = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($referrer) {
                $referredBy = $referrer['id'];
            }
        }
        
        // Insert user
        $stmt = $conn->prepare("
            INSERT INTO users (
                username,
                mobile,
                email,
                password_hash,
                refer_code,
                referred_by,
                wallet_balance,
                is_verified,
                is_active,
                kyc_status,
                created_at,
                updated_at
            ) VALUES (
                :username,
                :mobile,
                :email,
                :password_hash,
                :refer_code,
                :referred_by,
                0,
                0,
                1,
                'not_submitted',
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            )
        ");
        $stmt->execute([
            ':username' => $username,
            ':mobile' => $mobile,
            ':email' => $email,
            ':password_hash' => $passwordHash,
            ':refer_code' => $referCode,
            ':referred_by' => $referredBy
        ]);
        
        $userId = $conn->lastInsertId();
        
        // If referred, add referral bonus
        if ($referredBy) {
            // Add referral bonus to referrer
            $stmt = $conn->prepare("
                UPDATE users 
                SET wallet_balance = wallet_balance + 50,
                    referral_earnings = COALESCE(referral_earnings, 0) + 50,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :referrer_id
            ");
            $stmt->execute([':referrer_id' => $referredBy]);
            
            // Record referral bonus
            $stmt = $conn->prepare("
                INSERT INTO referral_bonuses (
                    referrer_id,
                    referred_id,
                    bonus_amount,
                    status,
                    created_at
                ) VALUES (
                    :referrer_id,
                    :referred_id,
                    50,
                    'credited',
                    CURRENT_TIMESTAMP
                )
            ");
            $stmt->execute([
                ':referrer_id' => $referredBy,
                ':referred_id' => $userId
            ]);
        }
        
        $db->commit();
        
        // Generate session token
        $sessionToken = bin2hex(random_bytes(64));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . SESSION_TIMEOUT . ' seconds'));
        
        // Store session
        $stmt = $conn->prepare("
            INSERT INTO sessions (
                user_id,
                session_token,
                ip_address,
                user_agent,
                device_type,
                expires_at,
                is_active,
                created_at
            ) VALUES (
                :user_id,
                :token,
                :ip,
                :user_agent,
                :device,
                :expires_at,
                1,
                CURRENT_TIMESTAMP
            )
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':token' => $sessionToken,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            ':device' => 'Web',
            ':expires_at' => $expiresAt
        ]);
        
        // Set session
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_token'] = $sessionToken;
        $_SESSION['session_init_time'] = time();
        
        // Generate new CSRF token
        $newCsrfToken = CSRFToken::generate();
        
        // Get user data
        $stmt = $conn->prepare("
            SELECT id, username, mobile, email, wallet_balance, total_matches_played, 
                   total_matches_won, total_earnings, elo_rating, is_verified, kyc_status
            FROM users 
            WHERE id = :user_id
        ");
        $stmt->execute([':user_id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        jsonResponse(true, 'Registration successful', [
            'user' => $user,
            'csrf_token' => $newCsrfToken,
            'session_expires' => $expiresAt
        ]);
        
    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollback();
        }
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollback();
        }
        jsonResponse(false, 'Error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Logout
// ==============================================
function handleLogout() {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!CSRFToken::validate($csrfToken)) {
        jsonResponse(false, 'Invalid CSRF token', [], 403);
    }
    
    try {
        if (isset($_SESSION['user_id']) && isset($_SESSION['user_token'])) {
            $db = Database::getInstance();
            $conn = $db->getConnection();
            
            // Invalidate session in database
            $stmt = $conn->prepare("
                UPDATE sessions 
                SET is_active = 0 
                WHERE user_id = :user_id AND session_token = :token
            ");
            $stmt->execute([
                ':user_id' => $_SESSION['user_id'],
                ':token' => $_SESSION['user_token']
            ]);
        }
        
        session_destroy();
        
        jsonResponse(true, 'Logged out successfully');
        
    } catch (Exception $e) {
        jsonResponse(false, 'Error during logout: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Check Authentication
// ==============================================
function handleCheckAuth() {
    try {
        if (!isLoggedIn()) {
            jsonResponse(false, 'Not authenticated', [
                'logged_in' => false
            ], 401);
        }
        
        $userId = getCurrentUserId();
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        // Get user data
        $stmt = $conn->prepare("
            SELECT id, username, mobile, email, wallet_balance, total_matches_played, 
                   total_matches_won, total_earnings, elo_rating, is_verified, kyc_status,
                   is_active
            FROM users 
            WHERE id = :user_id
        ");
        $stmt->execute([':user_id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user || $user['is_active'] != 1) {
            session_destroy();
            jsonResponse(false, 'User not found or inactive', [
                'logged_in' => false
            ], 401);
        }
        
        // Refresh session
        SessionManager::refresh();
        
        // Generate new CSRF token
        $newCsrfToken = CSRFToken::generate();
        
        jsonResponse(true, 'Authenticated', [
            'logged_in' => true,
            'user' => $user,
            'csrf_token' => $newCsrfToken
        ]);
        
    } catch (Exception $e) {
        jsonResponse(false, 'Error: ' . $e->getMessage(), [], 500);
    }
}
?>
