<?php
/**
 * ======================================================
 * AUTH.PHP - Secure Authentication API (SESSION FIXATION FIXED)
 * Ludo Tournament Platform - Complete Auth
 * Version: 3.0.1 - SESSION REGENERATION ON LOGIN
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
    'http://localhost', 'http://localhost:3000', 'http://127.0.0.1',
    'http://127.0.0.1/ludo', 'http://localhost/ludo',
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

try {
    switch ($action) {
        case 'get_csrf': handleGetCsrf(); break;
        case 'login': handleLogin($input); break;
        case 'register': handleRegister($input); break;
        case 'logout': handleLogout($input); break;
        case 'check': handleCheckAuth(); break;
        default: jsonResponse(false, 'Invalid action', [], 400);
    }
} catch (Exception $e) {
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
    
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
    
    jsonResponse(true, 'CSRF token generated', [
        'csrf_token' => $_SESSION['csrf_token']
    ]);
}

function validateCsrfToken(string $token): bool
{
    if (empty($token)) return true;
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); return true; }
    return hash_equals($_SESSION['csrf_token'], $token);
}

// ==============================================
// LOGIN HANDLER (FIXED: Session regeneration)
// ==============================================
function handleLogin(array $input): void
{
    $username = trim($input['username'] ?? $input['mobile'] ?? '');
    $password = $input['password'] ?? '';
    $csrfToken = $input['csrf_token'] ?? '';

    if (!empty($csrfToken) && !validateCsrfToken($csrfToken)) {
        jsonResponse(false, 'Invalid CSRF token. Please refresh the page.', [], 403);
    }

    if (empty($username)) { jsonResponse(false, 'Username, mobile or email is required', [], 400); }
    if (strlen($password) < 6) { jsonResponse(false, 'Password must be at least 6 characters', [], 400); }

    try {
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

        if (!$user) { jsonResponse(false, 'Invalid credentials', [], 401); }
        if ($user['is_active'] != 1) { jsonResponse(false, 'Account deactivated', [], 403); }
        if (intval($user['failed_login_attempts'] ?? 0) >= MAX_LOGIN_ATTEMPTS) {
            jsonResponse(false, 'Account locked. Contact support.', [], 403);
        }

        if (!password_verify($password, $user['password_hash'])) {
            $stmt = $conn->prepare("UPDATE users SET failed_login_attempts = COALESCE(failed_login_attempts, 0) + 1 WHERE id = :uid");
            $stmt->execute([':uid' => $user['id']]);
            $remaining = MAX_LOGIN_ATTEMPTS - intval($user['failed_login_attempts'] ?? 0) - 1;
            $msg = 'Invalid credentials';
            if ($remaining > 0) $msg .= ". {$remaining} attempt(s) remaining.";
            jsonResponse(false, $msg, [], 401);
        }

        // Reset failed attempts
        $stmt = $conn->prepare("UPDATE users SET failed_login_attempts = 0, last_login = CURRENT_TIMESTAMP WHERE id = :uid");
        $stmt->execute([':uid' => $user['id']]);

        // FIXED: Session regeneration on login (prevents session fixation)
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Regenerate session ID after successful login
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

        // Generate new CSRF token
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        jsonResponse(true, 'Login successful', [
            'user' => $user,
            'csrf_token' => $_SESSION['csrf_token']
        ]);

    } catch (PDOException $e) {
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// REGISTER HANDLER (FIXED: Session regeneration)
// ==============================================
function handleRegister(array $input): void
{
    $username = trim($input['username'] ?? '');
    $mobile = trim($input['mobile'] ?? '');
    $password = $input['password'] ?? '';
    $referralCode = trim($input['referral_code'] ?? '');
    $email = trim($input['email'] ?? '');
    $csrfToken = $input['csrf_token'] ?? '';

    if (!empty($csrfToken) && !validateCsrfToken($csrfToken)) {
        jsonResponse(false, 'Invalid CSRF token', [], 403);
    }

    if (strlen($username) < 3 || strlen($username) > 50) { jsonResponse(false, 'Username must be 3-50 characters', [], 400); }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) { jsonResponse(false, 'Username: letters, numbers, underscores only', [], 400); }
    if (!preg_match('/^[0-9]{10}$/', $mobile)) { jsonResponse(false, 'Invalid mobile number', [], 400); }
    if (strlen($password) < 6) { jsonResponse(false, 'Password must be at least 6 characters', [], 400); }
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) { jsonResponse(false, 'Invalid email', [], 400); }

    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        $conn->beginTransaction();

        $stmt = $conn->prepare("SELECT id FROM users WHERE mobile = :mobile");
        $stmt->execute([':mobile' => $mobile]);
        if ($stmt->fetch()) { $conn->rollBack(); jsonResponse(false, 'Mobile already registered', [], 409); }

        $stmt = $conn->prepare("SELECT id FROM users WHERE username = :username");
        $stmt->execute([':username' => $username]);
        if ($stmt->fetch()) { $conn->rollBack(); jsonResponse(false, 'Username taken', [], 409); }

        if (!empty($email)) {
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = :email");
            $stmt->execute([':email' => $email]);
            if ($stmt->fetch()) { $conn->rollBack(); jsonResponse(false, 'Email already registered', [], 409); }
        }

        $referCode = generateReferralCode();
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $referredBy = null;
        if (!empty($referralCode)) {
            $stmt = $conn->prepare("SELECT id FROM users WHERE refer_code = :code");
            $stmt->execute([':code' => $referralCode]);
            $referrer = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($referrer) $referredBy = $referrer['id'];
        }

        $stmt = $conn->prepare("
            INSERT INTO users (username, mobile, email, password_hash, refer_code, referred_by, wallet_balance, is_verified, is_active, kyc_status, created_at, updated_at)
            VALUES (:username, :mobile, :email, :password_hash, :refer_code, :referred_by, 0, 0, 1, 'not_submitted', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            ':username' => $username, ':mobile' => $mobile, ':email' => $email,
            ':password_hash' => $passwordHash, ':refer_code' => $referCode, ':referred_by' => $referredBy
        ]);
        $userId = $conn->lastInsertId();

        if ($referredBy) {
            $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance + 50, referral_earnings = COALESCE(referral_earnings, 0) + 50, updated_at = CURRENT_TIMESTAMP WHERE id = :rid");
            $stmt->execute([':rid' => $referredBy]);
            $stmt = $conn->prepare("INSERT INTO referral_bonuses (referrer_id, referred_id, bonus_amount, status, credited_at) VALUES (:rid, :uid, 50, 'credited', CURRENT_TIMESTAMP)");
            $stmt->execute([':rid' => $referredBy, ':uid' => $userId]);
        }

        $conn->commit();

        // FIXED: Session regeneration on registration
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        session_regenerate_id(true);
        
        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = $username;
        $_SESSION['logged_in'] = true;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        $stmt = $conn->prepare("SELECT id, username, mobile, email, wallet_balance, total_matches_played, total_matches_won, total_earnings, elo_rating, is_verified, kyc_status, refer_code, referral_earnings FROM users WHERE id = :uid");
        $stmt->execute([':uid' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $user['wallet_balance'] = floatval($user['wallet_balance'] ?? 0);
        $user['id'] = intval($user['id']);

        jsonResponse(true, 'Registration successful', [
            'user' => $user,
            'csrf_token' => $_SESSION['csrf_token']
        ]);

    } catch (PDOException $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        jsonResponse(false, 'Registration failed', [], 500);
    }
}

// ==============================================
// LOGOUT HANDLER
// ==============================================
function handleLogout(array $input): void
{
    try {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        jsonResponse(true, 'Logged out successfully');
    } catch (Exception $e) {
        jsonResponse(false, 'Error during logout', [], 500);
    }
}

// ==============================================
// CHECK AUTH HANDLER
// ==============================================
function handleCheckAuth(): void
{
    try {
        if (session_status() === PHP_SESSION_NONE) session_start();
        
        $userId = $_SESSION['user_id'] ?? SessionManager::get('user_id') ?? null;
        $loggedIn = $_SESSION['logged_in'] ?? false;
        
        if (!$userId || !$loggedIn) {
            jsonResponse(false, 'Not authenticated', ['logged_in' => false], 401);
        }

        $db = Database::getInstance();
        $conn = $db->getConnection();

        $stmt = $conn->prepare("SELECT id, username, mobile, email, wallet_balance, total_matches_played, total_matches_won, total_earnings, elo_rating, is_verified, kyc_status, is_active, refer_code, referral_earnings FROM users WHERE id = :uid");
        $stmt->execute([':uid' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || $user['is_active'] != 1) {
            $_SESSION = []; session_destroy();
            jsonResponse(false, 'User not found or inactive', ['logged_in' => false], 401);
        }

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        $user['wallet_balance'] = floatval($user['wallet_balance'] ?? 0);
        $user['id'] = intval($user['id']);

        jsonResponse(true, 'Authenticated', [
            'logged_in' => true, 'user' => $user, 'csrf_token' => $_SESSION['csrf_token']
        ]);
    } catch (Exception $e) {
        jsonResponse(false, 'Error checking authentication', [], 500);
    }
}
?>
