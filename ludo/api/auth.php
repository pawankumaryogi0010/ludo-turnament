<?php
/**
 * Full auth API
 * Path: api/auth.php
 *
 * Supports actions:
 *  - get_csrf  (GET)
 *  - register  (POST JSON)
 *  - login     (POST JSON)
 *  - check     (GET)
 *  - logout    (POST JSON)
 *
 * Relies on config/db.php which must provide Database::getInstance()->getConnection()
 */

if (!defined('BASE_PATH')) define('BASE_PATH', dirname(__DIR__));
require_once __DIR__ . '/../config/db.php'; // adjust if your config path differs

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Access-Control-Allow-Origin: ' . (defined('BASE_URL') ? BASE_URL : (isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*')));
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-CSRF-Token');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    // secure cookie params recommended in production
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => $_SERVER['HTTP_HOST'] ?? '',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Helper: uniform JSON responses
function jsonResponse(bool $success, string $message = '', $data = [], int $httpCode = 200, array $extra = []) {
    http_response_code($httpCode);
    $payload = ['success' => $success, 'message' => $message];
    if (!empty($data)) $payload['data'] = $data;
    // merge extras (e.g., isLoggedIn)
    foreach ($extra as $k => $v) $payload[$k] = $v;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// Helper: read JSON input safely
function getJsonInput() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) return $_POST ?: [];
    return $data ?: $_POST ?: [];
}

// Validate CSRF (session)
function validateCsrfToken($token = '') {
    if (empty($token)) {
        // try header
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    }
    if (empty($token)) return false;
    if (empty($_SESSION['csrf_token'])) return false;
    return hash_equals($_SESSION['csrf_token'], $token);
}

// Get action param
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$action = strtolower(trim($action));

// Constants (if not already defined elsewhere)
if (!defined('MAX_LOGIN_ATTEMPTS')) define('MAX_LOGIN_ATTEMPTS', 5);

// Action handlers
switch ($action) {
    case 'get_csrf':
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        jsonResponse(true, 'CSRF token', ['csrf_token' => $_SESSION['csrf_token']]);
        break;

    case 'register':
        $input = getJsonInput();
        $username = trim($input['username'] ?? '');
        $mobile = trim($input['mobile'] ?? '');
        $password = $input['password'] ?? '';
        $email = trim($input['email'] ?? '');
        $referralCode = trim($input['referral_code'] ?? '');
        $csrf = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

        if (!validateCsrfToken($csrf)) jsonResponse(false, 'Invalid CSRF token', [], 403);
        if (strlen($username) < 3 || strlen($username) > 50) jsonResponse(false, 'Username must be 3-50 chars', [], 400);
        if (!preg_match('/^[0-9]{10}$/', $mobile)) jsonResponse(false, 'Invalid mobile number', [], 400);
        if (strlen($password) < 6) jsonResponse(false, 'Password must be at least 6 characters', [], 400);
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) jsonResponse(false, 'Invalid email', [], 400);

        try {
            $db = Database::getInstance();
            $conn = $db->getConnection();
            $conn->beginTransaction();

            // mobile unique
            $stmt = $conn->prepare("SELECT id FROM users WHERE mobile = :mobile LIMIT 1");
            $stmt->execute([':mobile' => $mobile]);
            if ($stmt->fetch()) { $conn->rollBack(); jsonResponse(false, 'Mobile already registered', [], 409); }

            // username unique
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = :username LIMIT 1");
            $stmt->execute([':username' => $username]);
            if ($stmt->fetch()) { $conn->rollBack(); jsonResponse(false, 'Username taken', [], 409); }

            // email unique if provided
            if (!empty($email)) {
                $stmt = $conn->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
                $stmt->execute([':email' => $email]);
                if ($stmt->fetch()) { $conn->rollBack(); jsonResponse(false, 'Email already registered', [], 409); }
            }

            // create user
            $referCode = strtoupper(substr(bin2hex(random_bytes(4)),0,8));
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            $referredBy = null;
            if (!empty($referralCode)) {
                $stmt = $conn->prepare("SELECT id FROM users WHERE refer_code = :code LIMIT 1");
                $stmt->execute([':code' => $referralCode]);
                $ref = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($ref) $referredBy = $ref['id'];
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

            $stmt = $conn->prepare("SELECT id, username, mobile, email, wallet_balance, is_verified, is_active, refer_code FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // regenerate CSRF token
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            $conn->commit();

            // Set session as logged in
            session_regenerate_id(true);
            $_SESSION['user_id'] = $userId;
            $_SESSION['username'] = $user['username'];
            $_SESSION['logged_in'] = true;

            jsonResponse(true, 'Registered successfully', ['user' => $user, 'csrf_token' => $_SESSION['csrf_token']]);
        } catch (PDOException $e) {
            if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
            jsonResponse(false, 'Database error', [], 500);
        }
        break;

    case 'login':
        $input = getJsonInput();
        $username = trim($input['username'] ?? $input['mobile'] ?? '');
        $password = $input['password'] ?? '';
        $csrf = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

        if (!validateCsrfToken($csrf)) jsonResponse(false, 'Invalid CSRF token. Please refresh the page.', [], 403);
        if (empty($username)) jsonResponse(false, 'Username, mobile or email is required', [], 400);
        if (strlen($password) < 6) jsonResponse(false, 'Password must be at least 6 characters', [], 400);

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

            if (!$user) jsonResponse(false, 'Invalid credentials', [], 401);
            if ($user['is_active'] != 1) jsonResponse(false, 'Account deactivated', [], 403);
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

            // Reset failed attempts & update last_login
            $stmt = $conn->prepare("UPDATE users SET failed_login_attempts = 0, last_login = CURRENT_TIMESTAMP WHERE id = :uid");
            $stmt->execute([':uid' => $user['id']]);

            // Successful login: regenerate session
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['logged_in'] = true;

            // remove sensitive fields
            unset($user['password_hash'], $user['failed_login_attempts']);

            // regenerate CSRF token
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            jsonResponse(true, 'Login successful', ['user' => $user, 'csrf_token' => $_SESSION['csrf_token']]);
        } catch (PDOException $e) {
            jsonResponse(false, 'Database error', [], 500);
        }
        break;

    case 'check':
        if (!empty($_SESSION['logged_in']) && !empty($_SESSION['user_id'])) {
            try {
                $db = Database::getInstance();
                $conn = $db->getConnection();
                $stmt = $conn->prepare("SELECT id, username, mobile, email, wallet_balance, is_verified, is_active, refer_code FROM users WHERE id = :uid LIMIT 1");
                $stmt->execute([':uid' => intval($_SESSION['user_id'])]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($user) {
                    jsonResponse(true, 'Authenticated', ['user' => $user], 200, ['isLoggedIn' => true]);
                } else {
                    jsonResponse(true, 'Not logged in', [], 200, ['isLoggedIn' => false]);
                }
            } catch (PDOException $e) {
                jsonResponse(false, 'Database error', [], 500);
            }
        } else {
            jsonResponse(true, 'Not logged in', [], 200, ['isLoggedIn' => false]);
        }
        break;

    case 'logout':
        $input = getJsonInput();
        $csrf = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!empty($csrf) && !validateCsrfToken($csrf)) jsonResponse(false, 'Invalid CSRF token', [], 403);

        // server-side session cleanup
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
        jsonResponse(true, 'Logged out', [], 200);
        break;

    default:
        jsonResponse(false, 'Invalid action', [], 400);
        break;
}
