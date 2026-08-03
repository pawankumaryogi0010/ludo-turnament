<?php
/**
 * ======================================================
 * AUTH.PHP - Secure Authentication API (COMPLETE FIXED)
 * Ludo Tournament Platform - Complete Auth
 * Version: 4.0.0 - 500 ERROR FIXED + ALL AUTH FLOWS
 * ======================================================
 */

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once dirname(__DIR__) . '/config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

$allowedOrigins = [
    'http://localhost',
    'http://localhost:3000',
    'http://127.0.0.1',
    'http://127.0.0.1/ludo',
    'http://localhost/ludo',
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array(rtrim($origin, '/'), $allowedOrigins) || empty($origin)) {
    header('Access-Control-Allow-Origin: ' . ($origin ?: 'http://localhost'));
} else {
    header('Access-Control-Allow-Origin: http://localhost');
}
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-CSRF-Token');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    $input = $_POST ?: [];
}

$csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

try {
    switch ($action) {
        case 'get_csrf':
            handleGetCsrf();
            break;
        case 'login':
            handleLogin($input);
            break;
        case 'register':
            handleRegister($input);
            break;
        case 'logout':
            handleLogout($input);
            break;
        case 'check':
            handleCheckAuth();
            break;
        default:
            jsonResponse(false, 'Invalid action', [], 400);
    }
} catch (Exception $e) {
    error_log('[Auth API] Fatal Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    jsonResponse(false, 'Server error occurred. Please try again.', [], 500);
}

// ==============================================
// CSRF TOKEN HANDLER
// ==============================================
function handleGetCsrf(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
    
    jsonResponse(true, 'CSRF token generated', [
        'csrf_token' => $_SESSION['csrf_token']
    ]);
}

// ==============================================
// CSRF VALIDATION
// ==============================================
function validateCsrfToken(string $token): bool
{
    if (empty($token)) {
        return true;
    }
    
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
        return true;
    }
    
    if (isset($_SESSION['csrf_token_time'])) {
        if ((time() - $_SESSION['csrf_token_time']) > 3600) {
            unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
            return false;
        }
    }
    
    return hash_equals($_SESSION['csrf_token'], $token);
}

// ==============================================
// LOGIN HANDLER (FIXED - NO 500 ERROR)
// ==============================================
function handleLogin(array $input): void
{
    try {
        $username = trim($input['username'] ?? $input['mobile'] ?? '');
        $password = $input['password'] ?? '';
        $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

        if (!empty($csrfToken) && !validateCsrfToken($csrfToken)) {
            jsonResponse(false, 'Invalid CSRF token. Please refresh the page.', [], 403);
        }

        if (empty($username)) {
            jsonResponse(false, 'Username, mobile or email is required', [], 400);
        }

        if (strlen($password) < 6) {
            jsonResponse(false, 'Password must be at least 6 characters', [], 400);
        }

        $db = Database::getInstance();
        $conn = $db->getConnection();

        $stmt = $conn->prepare("
            SELECT 
                id, username, mobile, email, password_hash,
                is_active, is_verified, kyc_status,
                wallet_balance, total_matches_played, total_matches_won,
                total_earnings, elo_rating, refer_code,
                referral_earnings, failed_login_attempts
            FROM users
            WHERE username = :username OR mobile = :username OR email = :username
            LIMIT 1
        ");
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            jsonResponse(false, 'Invalid credentials', [], 401);
        }

        if ($user['is_active'] != 1) {
            jsonResponse(false, 'Account deactivated. Please contact support.', [], 403);
        }

        if (intval($user['failed_login_attempts'] ?? 0) >= MAX_LOGIN_ATTEMPTS) {
            jsonResponse(false, 'Account locked due to too many failed attempts. Contact support.', [], 403);
        }

        if (!password_verify($password, $user['password_hash'])) {
            $updateStmt = $conn->prepare("UPDATE users SET failed_login_attempts = COALESCE(failed_login_attempts, 0) + 1 WHERE id = :uid");
            $updateStmt->execute([':uid' => $user['id']]);
            
            $remaining = MAX_LOGIN_ATTEMPTS - intval($user['failed_login_attempts'] ?? 0) - 1;
            $msg = 'Invalid credentials';
            if ($remaining > 0) {
                $msg .= ". {$remaining} attempt(s) remaining.";
            } else {
                $msg .= '. Account locked.';
            }
            
            jsonResponse(false, $msg, [], 401);
        }

        $updateStmt = $conn->prepare("UPDATE users SET failed_login_attempts = 0, last_login = CURRENT_TIMESTAMP WHERE id = :uid");
        $updateStmt->execute([':uid' => $user['id']]);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        session_regenerate_id(true);
        
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['logged_in'] = true;

        unset($user['password_hash'], $user['failed_login_attempts']);
        
        $user['wallet_balance'] = floatval($user['wallet_balance'] ?? 0);
        $user['total_earnings'] = floatval($user['total_earnings'] ?? 0);
        $user['referral_earnings'] = floatval($user['referral_earnings'] ?? 0);
        $user['id'] = intval($user['id']);
        $user['elo_rating'] = intval($user['elo_rating'] ?? 1200);
        $user['total_matches_played'] = intval($user['total_matches_played'] ?? 0);
        $user['total_matches_won'] = intval($user['total_matches_won'] ?? 0);

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();

        jsonResponse(true, 'Login successful', [
            'user' => $user,
            'csrf_token' => $_SESSION['csrf_token']
        ]);

    } catch (PDOException $e) {
        error_log('[Auth API] Login PDO Error: ' . $e->getMessage());
        jsonResponse(false, 'Database error occurred. Please try again.', [], 500);
    } catch (Exception $e) {
        error_log('[Auth API] Login Error: ' . $e->getMessage());
        jsonResponse(false, 'An error occurred during login. Please try again.', [], 500);
    }
}

// ==============================================
// REGISTER HANDLER (FIXED)
// ==============================================
function handleRegister(array $input): void
{
    try {
        $username = trim($input['username'] ?? '');
        $mobile = trim($input['mobile'] ?? '');
        $password = $input['password'] ?? '';
        $referralCode = trim($input['referral_code'] ?? '');
        $email = trim($input['email'] ?? '');
        $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

        if (!empty($csrfToken) && !validateCsrfToken($csrfToken)) {
            jsonResponse(false, 'Invalid CSRF token', [], 403);
        }

        if (strlen($username) < 3 || strlen($username) > 50) {
            jsonResponse(false, 'Username must be 3-50 characters', [], 400);
        }

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            jsonResponse(false, 'Username: letters, numbers, underscores only', [], 400);
        }

        if (!preg_match('/^[0-9]{10}$/', $mobile)) {
            jsonResponse(false, 'Invalid mobile number (10 digits required)', [], 400);
        }

        if (strlen($password) < 6) {
            jsonResponse(false, 'Password must be at least 6 characters', [], 400);
        }

        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(false, 'Invalid email format', [], 400);
        }

        $db = Database::getInstance();
        $conn = $db->getConnection();
        $conn->beginTransaction();

        $stmt = $conn->prepare("SELECT id FROM users WHERE mobile = :mobile LIMIT 1");
        $stmt->execute([':mobile' => $mobile]);
        if ($stmt->fetch()) {
            $conn->rollBack();
            jsonResponse(false, 'Mobile number already registered', [], 409);
        }

        $stmt = $conn->prepare("SELECT id FROM users WHERE username = :username LIMIT 1");
        $stmt->execute([':username' => $username]);
        if ($stmt->fetch()) {
            $conn->rollBack();
            jsonResponse(false, 'Username already taken', [], 409);
        }

        if (!empty($email)) {
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $email]);
            if ($stmt->fetch()) {
                $conn->rollBack();
                jsonResponse(false, 'Email already registered', [], 409);
            }
        }

        $referCode = generateReferralCode();
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $referredBy = null;
        if (!empty($referralCode)) {
            $stmt = $conn->prepare("SELECT id FROM users WHERE refer_code = :code LIMIT 1");
            $stmt->execute([':code' => $referralCode]);
            $referrer = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($referrer) {
                $referredBy = $referrer['id'];
            }
        }

        $stmt = $conn->prepare("
            INSERT INTO users (
                username, mobile, email, password_hash,
                refer_code, referred_by, wallet_balance,
                is_verified, is_active, kyc_status,
                created_at, updated_at
            ) VALUES (
                :username, :mobile, :email, :password_hash,
                :refer_code, :referred_by, 0,
                0, 1, 'not_submitted',
                CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
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
        $userId = intval($conn->lastInsertId());

        if ($referredBy) {
            $stmt = $conn->prepare("
                UPDATE users SET 
                    wallet_balance = wallet_balance + 50,
                    referral_earnings = COALESCE(referral_earnings, 0) + 50,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :rid
            ");
            $stmt->execute([':rid' => $referredBy]);
            
            $stmt = $conn->prepare("
                INSERT INTO referral_bonuses (referrer_id, referred_id, bonus_amount, status, credited_at)
                VALUES (:rid, :uid, 50, 'credited', CURRENT_TIMESTAMP)
            ");
            $stmt->execute([':rid' => $referredBy, ':uid' => $userId]);
        }

        $conn->commit();

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        session_regenerate_id(true);
        
        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = $username;
        $_SESSION['logged_in'] = true;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();

        $stmt = $conn->prepare("
            SELECT id, username, mobile, email, wallet_balance,
                   total_matches_played, total_matches_won, total_earnings,
                   elo_rating, is_verified, kyc_status, refer_code, referral_earnings
            FROM users WHERE id = :uid
        ");
        $stmt->execute([':uid' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            $user['wallet_balance'] = floatval($user['wallet_balance'] ?? 0);
            $user['id'] = intval($user['id']);
        }

        jsonResponse(true, 'Registration successful', [
            'user' => $user,
            'csrf_token' => $_SESSION['csrf_token']
        ]);

    } catch (PDOException $e) {
        if (isset($conn) && $conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log('[Auth API] Register PDO Error: ' . $e->getMessage());
        jsonResponse(false, 'Registration failed. Please try again.', [], 500);
    } catch (Exception $e) {
        if (isset($conn) && $conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log('[Auth API] Register Error: ' . $e->getMessage());
        jsonResponse(false, 'An error occurred during registration.', [], 500);
    }
}

// ==============================================
// LOGOUT HANDLER
// ==============================================
function handleLogout(array $input): void
{
    try {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $_SESSION = [];
        
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        
        session_destroy();
        jsonResponse(true, 'Logged out successfully');

    } catch (Exception $e) {
        error_log('[Auth API] Logout Error: ' . $e->getMessage());
        jsonResponse(false, 'Error during logout', [], 500);
    }
}

// ==============================================
// CHECK AUTH HANDLER (FIXED - Always returns 200)
// ==============================================
function handleCheckAuth(): void
{
    try {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $userId = $_SESSION['user_id'] ?? SessionManager::get('user_id') ?? null;
        $loggedIn = $_SESSION['logged_in'] ?? false;
        
        if (!$userId || !$loggedIn) {
            jsonResponse(true, 'Not authenticated', ['logged_in' => false]);
            return;
        }

        $db = Database::getInstance();
        $conn = $db->getConnection();

        $stmt = $conn->prepare("
            SELECT id, username, mobile, email, wallet_balance,
                   total_matches_played, total_matches_won, total_earnings,
                   elo_rating, is_verified, kyc_status, is_active,
                   refer_code, referral_earnings
            FROM users WHERE id = :uid AND is_active = 1
        ");
        $stmt->execute([':uid' => intval($userId)]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $_SESSION = [];
            session_destroy();
            jsonResponse(true, 'User not found or inactive', ['logged_in' => false]);
            return;
        }

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['csrf_token_time'] = time();
        }

        $user['wallet_balance'] = floatval($user['wallet_balance'] ?? 0);
        $user['id'] = intval($user['id']);

        jsonResponse(true, 'Authenticated', [
            'logged_in' => true,
            'user' => $user,
            'csrf_token' => $_SESSION['csrf_token']
        ]);

    } catch (Exception $e) {
        error_log('[Auth API] Check Auth Error: ' . $e->getMessage());
        jsonResponse(false, 'Error checking authentication', [], 500);
    }
}
?>
