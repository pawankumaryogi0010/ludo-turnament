<?php
/**
 * ======================================================
 * AUTH.PHP - Secure Authentication API (FULL FIXED)
 * Ludo Tournament Platform - Complete Auth
 * Version: 4.0.0 - ALL ISSUES RESOLVED
 * ======================================================
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once dirname(__DIR__) . '/config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// CORS
$allowedOrigins = [
    'http://localhost',
    'http://localhost:3000',
    'http://127.0.0.1',
    'http://127.0.0.1/ludo',
    'http://localhost/ludo',
    rtrim(BASE_URL, '/'),
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array(rtrim($origin, '/'), $allowedOrigins) || empty($origin)) {
    header('Access-Control-Allow-Origin: ' . ($origin ?: rtrim(BASE_URL, '/')));
} else {
    header('Access-Control-Allow-Origin: ' . rtrim(BASE_URL, '/'));
}
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-CSRF-Token');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ==============================================
// CSRF TOKEN GENERATION
// ==============================================
function generateAndStoreCsrf(): string
{
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
    $_SESSION['csrf_token_time'] = time();
    return $token;
}

// Initialize CSRF if not set
if (empty($_SESSION['csrf_token'])) {
    generateAndStoreCsrf();
}

// ==============================================
// ROUTING
// ==============================================
$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

// Parse input safely
$input = [];
$rawInput = file_get_contents('php://input');
if (!empty($rawInput)) {
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}

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
    error_log('Auth API error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    jsonResponse(false, 'Server error: ' . $e->getMessage(), [], 500);
}

// ==============================================
// CSRF TOKEN HANDLER
// ==============================================
function handleGetCsrf(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $token = generateAndStoreCsrf();
    
    jsonResponse(true, 'CSRF token generated', [
        'csrf_token' => $token
    ]);
}

// ==============================================
// LOGIN HANDLER
// ==============================================
function handleLogin(array $input): void
{
    try {
        $username = trim($input['username'] ?? $input['mobile'] ?? '');
        $password = $input['password'] ?? '';

        if (empty($username)) {
            jsonResponse(false, 'Username, mobile or email is required', [], 400);
        }

        if (strlen($password) < 6) {
            jsonResponse(false, 'Password must be at least 6 characters', [], 400);
        }

        $db = Database::getInstance();
        $conn = $db->getConnection();

        $stmt = $conn->prepare("
            SELECT id, username, mobile, email, password_hash,
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

        $maxAttempts = defined('MAX_LOGIN_ATTEMPTS') ? MAX_LOGIN_ATTEMPTS : 5;
        if (intval($user['failed_login_attempts'] ?? 0) >= $maxAttempts) {
            jsonResponse(false, 'Account locked. Contact support.', [], 403);
        }

        if (!password_verify($password, $user['password_hash'])) {
            $stmt = $conn->prepare("UPDATE users SET failed_login_attempts = COALESCE(failed_login_attempts, 0) + 1 WHERE id = :uid");
            $stmt->execute([':uid' => $user['id']]);
            $remaining = $maxAttempts - intval($user['failed_login_attempts'] ?? 0) - 1;
            $msg = 'Invalid credentials';
            if ($remaining > 0) $msg .= ". {$remaining} attempt(s) remaining.";
            jsonResponse(false, $msg, [], 401);
        }

        // Reset failed attempts
        $stmt = $conn->prepare("UPDATE users SET failed_login_attempts = 0, last_login = CURRENT_TIMESTAMP WHERE id = :uid");
        $stmt->execute([':uid' => $user['id']]);

        // Session regeneration on login
        session_regenerate_id(true);
        
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['logged_in'] = true;
        SessionManager::set('user_id', (int)$user['id']);
        SessionManager::set('username', $user['username']);
        SessionManager::set('logged_in', true);

        // Generate new CSRF token after login
        $newCsrf = generateAndStoreCsrf();

        // Build clean user object (no password)
        $userClean = [
            'id' => (int)$user['id'],
            'username' => $user['username'],
            'mobile' => $user['mobile'],
            'email' => $user['email'],
            'wallet_balance' => (float)($user['wallet_balance'] ?? 0),
            'total_matches_played' => (int)($user['total_matches_played'] ?? 0),
            'total_matches_won' => (int)($user['total_matches_won'] ?? 0),
            'total_earnings' => (float)($user['total_earnings'] ?? 0),
            'elo_rating' => (int)($user['elo_rating'] ?? 1200),
            'is_verified' => (bool)($user['is_verified'] ?? false),
            'kyc_status' => $user['kyc_status'] ?? 'not_submitted',
            'refer_code' => $user['refer_code'] ?? '',
            'referral_earnings' => (float)($user['referral_earnings'] ?? 0),
        ];

        jsonResponse(true, 'Login successful', [
            'user' => $userClean,
            'csrf_token' => $newCsrf
        ]);

    } catch (PDOException $e) {
        error_log('Auth Login PDO error: ' . $e->getMessage());
        jsonResponse(false, 'Database error. Please try again.', [], 500);
    } catch (Exception $e) {
        error_log('Auth Login error: ' . $e->getMessage());
        jsonResponse(false, 'Server error. Please try again.', [], 500);
    }
}

// ==============================================
// REGISTER HANDLER
// ==============================================
function handleRegister(array $input): void
{
    try {
        $username = trim($input['username'] ?? '');
        $mobile = trim($input['mobile'] ?? '');
        $password = $input['password'] ?? '';
        $referralCode = trim($input['referral_code'] ?? '');
        $email = trim($input['email'] ?? '');

        if (strlen($username) < 3 || strlen($username) > 50) {
            jsonResponse(false, 'Username must be 3-50 characters', [], 400);
        }

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            jsonResponse(false, 'Username: letters, numbers, underscores only', [], 400);
        }

        if (!preg_match('/^[0-9]{10}$/', $mobile)) {
            jsonResponse(false, 'Invalid mobile number', [], 400);
        }

        if (strlen($password) < 6) {
            jsonResponse(false, 'Password must be at least 6 characters', [], 400);
        }

        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(false, 'Invalid email', [], 400);
        }

        $db = Database::getInstance();
        $conn = $db->getConnection();
        $conn->beginTransaction();

        // Check mobile
        $stmt = $conn->prepare("SELECT id FROM users WHERE mobile = :mobile");
        $stmt->execute([':mobile' => $mobile]);
        if ($stmt->fetch()) {
            $conn->rollBack();
            jsonResponse(false, 'Mobile already registered', [], 409);
        }

        // Check username
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = :username");
        $stmt->execute([':username' => $username]);
        if ($stmt->fetch()) {
            $conn->rollBack();
            jsonResponse(false, 'Username taken', [], 409);
        }

        // Check email
        if (!empty($email)) {
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = :email");
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
            $stmt = $conn->prepare("SELECT id FROM users WHERE refer_code = :code");
            $stmt->execute([':code' => $referralCode]);
            $referrer = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($referrer) $referredBy = (int)$referrer['id'];
        }

        $stmt = $conn->prepare("
            INSERT INTO users (username, mobile, email, password_hash, refer_code, referred_by, wallet_balance, is_verified, is_active, kyc_status, created_at, updated_at)
            VALUES (:username, :mobile, :email, :password_hash, :refer_code, :referred_by, 0, 0, 1, 'not_submitted', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            ':username' => $username, ':mobile' => $mobile, ':email' => $email,
            ':password_hash' => $passwordHash, ':refer_code' => $referCode, ':referred_by' => $referredBy
        ]);
        $newUserId = (int)$conn->lastInsertId();

        // Referral bonus
        if ($referredBy) {
            $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance + 50, referral_earnings = COALESCE(referral_earnings, 0) + 50, updated_at = CURRENT_TIMESTAMP WHERE id = :rid");
            $stmt->execute([':rid' => $referredBy]);
            $stmt = $conn->prepare("INSERT INTO referral_bonuses (referrer_id, referred_id, bonus_amount, status, credited_at) VALUES (:rid, :uid, 50, 'credited', CURRENT_TIMESTAMP)");
            $stmt->execute([':rid' => $referredBy, ':uid' => $newUserId]);
        }

        $conn->commit();

        // Session regeneration on registration
        session_regenerate_id(true);
        
        $_SESSION['user_id'] = $newUserId;
        $_SESSION['username'] = $username;
        $_SESSION['logged_in'] = true;
        SessionManager::set('user_id', $newUserId);
        SessionManager::set('username', $username);
        SessionManager::set('logged_in', true);

        // Generate new CSRF token after registration
        $newCsrf = generateAndStoreCsrf();

        // Build user object
        $userClean = [
            'id' => $newUserId,
            'username' => $username,
            'mobile' => $mobile,
            'email' => $email,
            'wallet_balance' => 0.00,
            'total_matches_played' => 0,
            'total_matches_won' => 0,
            'total_earnings' => 0.00,
            'elo_rating' => 1200,
            'is_verified' => false,
            'kyc_status' => 'not_submitted',
            'refer_code' => $referCode,
            'referral_earnings' => 0.00,
        ];

        jsonResponse(true, 'Registration successful', [
            'user' => $userClean,
            'csrf_token' => $newCsrf
        ]);

    } catch (PDOException $e) {
        if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
        error_log('Auth Register PDO error: ' . $e->getMessage());
        jsonResponse(false, 'Registration failed. Please try again.', [], 500);
    } catch (Exception $e) {
        if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
        error_log('Auth Register error: ' . $e->getMessage());
        jsonResponse(false, 'Registration failed. Please try again.', [], 500);
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
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        
        session_destroy();
        jsonResponse(true, 'Logged out successfully');

    } catch (Exception $e) {
        error_log('Auth Logout error: ' . $e->getMessage());
        jsonResponse(false, 'Error during logout', [], 500);
    }
}

// ==============================================
// CHECK AUTH HANDLER
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

        $stmt = $conn->prepare("SELECT id, username, mobile, email, wallet_balance, total_matches_played, total_matches_won, total_earnings, elo_rating, is_verified, kyc_status, is_active, refer_code, referral_earnings FROM users WHERE id = :uid");
        $stmt->execute([':uid' => (int)$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || $user['is_active'] != 1) {
            $_SESSION = [];
            session_destroy();
            jsonResponse(true, 'User not found or inactive', ['logged_in' => false]);
            return;
        }

        // Generate fresh CSRF
        $newCsrf = generateAndStoreCsrf();

        $userClean = [
            'id' => (int)$user['id'],
            'username' => $user['username'],
            'mobile' => $user['mobile'],
            'email' => $user['email'],
            'wallet_balance' => (float)($user['wallet_balance'] ?? 0),
            'total_matches_played' => (int)($user['total_matches_played'] ?? 0),
            'total_matches_won' => (int)($user['total_matches_won'] ?? 0),
            'total_earnings' => (float)($user['total_earnings'] ?? 0),
            'elo_rating' => (int)($user['elo_rating'] ?? 1200),
            'is_verified' => (bool)($user['is_verified'] ?? false),
            'kyc_status' => $user['kyc_status'] ?? 'not_submitted',
            'refer_code' => $user['refer_code'] ?? '',
            'referral_earnings' => (float)($user['referral_earnings'] ?? 0),
        ];

        jsonResponse(true, 'Authenticated', [
            'logged_in' => true,
            'user' => $userClean,
            'csrf_token' => $newCsrf
        ]);

    } catch (PDOException $e) {
        error_log('Auth Check PDO error: ' . $e->getMessage());
        jsonResponse(false, 'Error checking authentication', [], 500);
    } catch (Exception $e) {
        error_log('Auth Check error: ' . $e->getMessage());
        jsonResponse(false, 'Error checking authentication', [], 500);
    }
}
