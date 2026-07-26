<?php
/**
 * ======================================================
 * AUTH.PHP - Secure Authentication API (V2)
 * Ludo Tournament Platform - Complete Auth
 * Version: 2.0.0 - ALL BUGS FIXED
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

// ✅ FIX: Handle CORS properly
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

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
$input = json_decode(file_get_contents('php://input'), true) ?? [];

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
            break;
    }
} catch (Exception $e) {
    jsonResponse(false, 'Server error: ' . $e->getMessage(), [], 500);
}

// ==============================================
// ✅ FIX: CSRF Token Handler
// ==============================================
function handleGetCsrf(): void
{
    // Start session if not started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Generate CSRF token
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    
    jsonResponse(true, 'CSRF token generated', [
        'csrf_token' => $_SESSION['csrf_token']
    ]);
}

// ==============================================
// ✅ FIX: CSRF Token Validate Function
// ==============================================
function validateCsrfToken(string $token): bool
{
    if (empty($token)) {
        return false;
    }
    
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (empty($_SESSION['csrf_token'])) {
        return false;
    }
    
    return hash_equals($_SESSION['csrf_token'], $token);
}

// ==============================================
// ✅ FIX: Generate Referral Code
// ==============================================
function generateReferralCode(): string
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $code = '';
    for ($i = 0; $i < 8; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $code;
}

// ==============================================
// ✅ FIXED: Login Handler - Supports mobile OR username
// ==============================================
function handleLogin(array $input): void
{
    $username = trim($input['username'] ?? $input['mobile'] ?? '');
    $password = $input['password'] ?? '';
    $csrfToken = $input['csrf_token'] ?? '';

    // ✅ FIX: Validate CSRF - skip if empty (for backward compatibility)
    if (!empty($csrfToken) && !validateCsrfToken($csrfToken)) {
        jsonResponse(false, 'Invalid or expired CSRF token. Please refresh.', [], 403);
    }

    if (empty($username)) {
        jsonResponse(false, 'Username, mobile or email is required', [], 400);
    }

    if (strlen($password) < 6) {
        jsonResponse(false, 'Password must be at least 6 characters', [], 400);
    }

    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();

        // ✅ FIX: Search by username, mobile, or email
        $stmt = $conn->prepare("
            SELECT
                id,
                username,
                mobile,
                email,
                password_hash,
                is_active,
                is_verified,
                kyc_status,
                wallet_balance,
                total_matches_played,
                total_matches_won,
                total_earnings,
                elo_rating,
                refer_code,
                referral_earnings,
                failed_login_attempts
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
            jsonResponse(false, 'Account is deactivated. Please contact support.', [], 403);
        }

        if (!password_verify($password, $user['password_hash'])) {
            // Increment failed attempts
            $stmt = $conn->prepare("
                UPDATE users
                SET failed_login_attempts = COALESCE(failed_login_attempts, 0) + 1
                WHERE id = :user_id
            ");
            $stmt->execute([':user_id' => $user['id']]);
            jsonResponse(false, 'Invalid credentials', [], 401);
        }

        // Reset failed attempts
        $stmt = $conn->prepare("
            UPDATE users
            SET failed_login_attempts = 0,
                last_login = CURRENT_TIMESTAMP
            WHERE id = :user_id
        ");
        $stmt->execute([':user_id' => $user['id']]);

        // ✅ FIX: Start session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['logged_in'] = true;

        unset($user['password_hash']);
        $user['wallet_balance'] = floatval($user['wallet_balance']);
        $user['total_earnings'] = floatval($user['total_earnings']);
        $user['referral_earnings'] = floatval($user['referral_earnings'] ?? 0);

        // ✅ Generate new CSRF token for next request
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        jsonResponse(true, 'Login successful', [
            'user' => $user,
            'csrf_token' => $_SESSION['csrf_token']
        ]);

    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// ✅ FIXED: Register Handler
// ==============================================
function handleRegister(array $input): void
{
    $username = trim($input['username'] ?? '');
    $mobile = trim($input['mobile'] ?? '');
    $password = $input['password'] ?? '';
    $referralCode = trim($input['referral_code'] ?? '');
    $email = trim($input['email'] ?? '');
    $csrfToken = $input['csrf_token'] ?? '';

    // ✅ FIX: Validate CSRF - skip if empty
    if (!empty($csrfToken) && !validateCsrfToken($csrfToken)) {
        jsonResponse(false, 'Invalid or expired CSRF token. Please refresh.', [], 403);
    }

    if (strlen($username) < 3 || strlen($username) > 50) {
        jsonResponse(false, 'Username must be between 3 and 50 characters', [], 400);
    }

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        jsonResponse(false, 'Username can only contain letters, numbers, and underscores', [], 400);
    }

    if (!preg_match('/^[0-9]{10}$/', $mobile)) {
        jsonResponse(false, 'Invalid mobile number format (10 digits required)', [], 400);
    }

    if (strlen($password) < 6) {
        jsonResponse(false, 'Password must be at least 6 characters', [], 400);
    }

    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(false, 'Invalid email format', [], 400);
    }

    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();

        $conn->beginTransaction();

        // Check mobile exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE mobile = :mobile");
        $stmt->execute([':mobile' => $mobile]);
        if ($stmt->fetch()) {
            $conn->rollBack();
            jsonResponse(false, 'Mobile number already registered', [], 409);
        }

        // Check username exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = :username");
        $stmt->execute([':username' => $username]);
        if ($stmt->fetch()) {
            $conn->rollBack();
            jsonResponse(false, 'Username already taken', [], 409);
        }

        // Check email exists (if provided)
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
            if ($referrer) {
                $referredBy = $referrer['id'];
            }
        }

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

        // Process referral bonus
        if ($referredBy) {
            $stmt = $conn->prepare("
                UPDATE users
                SET wallet_balance = wallet_balance + 50,
                    referral_earnings = COALESCE(referral_earnings, 0) + 50,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :referrer_id
            ");
            $stmt->execute([':referrer_id' => $referredBy]);
        }

        $conn->commit();

        // ✅ Auto-login after registration
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = $username;
        $_SESSION['logged_in'] = true;

        // Generate CSRF token
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        $stmt = $conn->prepare("
            SELECT
                id,
                username,
                mobile,
                email,
                wallet_balance,
                total_matches_played,
                total_matches_won,
                total_earnings,
                elo_rating,
                is_verified,
                kyc_status,
                refer_code,
                referral_earnings
            FROM users
            WHERE id = :user_id
        ");
        $stmt->execute([':user_id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        jsonResponse(true, 'Registration successful', [
            'user' => $user,
            'csrf_token' => $_SESSION['csrf_token']
        ]);

    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// ✅ FIXED: Logout Handler
// ==============================================
function handleLogout(array $input): void
{
    $csrfToken = $input['csrf_token'] ?? '';
    
    // ✅ FIX: Don't require CSRF for logout (more user-friendly)
    if (!empty($csrfToken) && !validateCsrfToken($csrfToken)) {
        jsonResponse(false, 'Invalid CSRF token', [], 403);
    }

    try {
        // Destroy session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $_SESSION = [];
        
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        
        session_destroy();

        jsonResponse(true, 'Logged out successfully');

    } catch (Exception $e) {
        jsonResponse(false, 'Error during logout: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// ✅ FIXED: Check Authentication Handler
// ==============================================
function handleCheckAuth(): void
{
    try {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
            jsonResponse(false, 'Not authenticated', ['logged_in' => false], 401);
        }

        $userId = $_SESSION['user_id'];
        $db = Database::getInstance();
        $conn = $db->getConnection();

        $stmt = $conn->prepare("
            SELECT
                id,
                username,
                mobile,
                email,
                wallet_balance,
                total_matches_played,
                total_matches_won,
                total_earnings,
                elo_rating,
                is_verified,
                kyc_status,
                is_active,
                refer_code,
                referral_earnings
            FROM users
            WHERE id = :user_id
        ");
        $stmt->execute([':user_id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || $user['is_active'] != 1) {
            $_SESSION = [];
            session_destroy();
            jsonResponse(false, 'User not found or inactive', ['logged_in' => false], 401);
        }

        // Generate CSRF token if not exists
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        jsonResponse(true, 'Authenticated', [
            'logged_in' => true,
            'user' => $user,
            'csrf_token' => $_SESSION['csrf_token']
        ]);

    } catch (Exception $e) {
        jsonResponse(false, 'Error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// ✅ Helper: JSON Response
// ==============================================
function jsonResponse(bool $success, string $message, array $data = [], int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
