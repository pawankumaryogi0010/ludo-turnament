<?php
/**
 * ======================================================
 * AUTH.PHP - Secure Authentication API (V1)
 * Ludo Tournament Platform - Complete Auth
 * Version: 1.0.0 - COMPLETE REWRITE
 * ======================================================
 */

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

require_once dirname(__DIR__, 2) . '/config/db.php';

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

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
$input = json_decode(file_get_contents('php://input'), true) ?? [];

$csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

switch ($action) {
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

// ==============================================
// HANDLER: Login
// ==============================================
function handleLogin(array $input): void
{
    $mobile = trim($input['mobile'] ?? '');
    $password = $input['password'] ?? '';
    $csrfToken = $input['csrf_token'] ?? '';

    if (!CSRFToken::validate($csrfToken)) {
        jsonResponse(false, 'Invalid CSRF token', [], 403);
    }

    if (!preg_match('/^[0-9]{10}$/', $mobile)) {
        jsonResponse(false, 'Invalid mobile number format', [], 400);
    }

    if (strlen($password) < 8) {
        jsonResponse(false, 'Password must be at least 8 characters', [], 400);
    }

    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();

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
            // Increment failed attempts
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
            SET failed_login_attempts = 0,
                last_login = CURRENT_TIMESTAMP
            WHERE id = :user_id
        ");
        $stmt->execute([':user_id' => $user['id']]);

        // Generate secure session token
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
        SessionManager::set('user_id', $user['id']);
        SessionManager::set('user_token', $sessionToken);
        SessionManager::set('session_init_time', time());

        unset($user['password_hash']);
        $user['wallet_balance'] = floatval($user['wallet_balance']);
        $user['total_earnings'] = floatval($user['total_earnings']);
        $user['referral_earnings'] = floatval($user['referral_earnings'] ?? 0);

        jsonResponse(true, 'Login successful', [
            'user' => $user,
            'csrf_token' => CSRFToken::generate(),
            'session_expires' => $expiresAt
        ]);

    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Register
// ==============================================
function handleRegister(array $input): void
{
    $username = trim($input['username'] ?? '');
    $mobile = trim($input['mobile'] ?? '');
    $password = $input['password'] ?? '';
    $referralCode = trim($input['referral_code'] ?? '');
    $email = trim($input['email'] ?? '');
    $csrfToken = $input['csrf_token'] ?? '';

    if (!CSRFToken::validate($csrfToken)) {
        jsonResponse(false, 'Invalid CSRF token', [], 403);
    }

    if (strlen($username) < 3 || strlen($username) > 50) {
        jsonResponse(false, 'Username must be between 3 and 50 characters', [], 400);
    }

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        jsonResponse(false, 'Username can only contain letters, numbers, and underscores', [], 400);
    }

    if (!preg_match('/^[0-9]{10}$/', $mobile)) {
        jsonResponse(false, 'Invalid mobile number format', [], 400);
    }

    if (strlen($password) < 8) {
        jsonResponse(false, 'Password must be at least 8 characters', [], 400);
    }

    if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        jsonResponse(false, 'Password must contain at least one uppercase, one lowercase, and one number', [], 400);
    }

    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(false, 'Invalid email format', [], 400);
    }

    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();

        $db->beginTransaction();

        // Check mobile exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE mobile = :mobile");
        $stmt->execute([':mobile' => $mobile]);
        if ($stmt->fetch()) {
            $db->rollback();
            jsonResponse(false, 'Mobile number already registered', [], 409);
        }

        // Check username exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = :username");
        $stmt->execute([':username' => $username]);
        if ($stmt->fetch()) {
            $db->rollback();
            jsonResponse(false, 'Username already taken', [], 409);
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

        // Auto-login after registration
        $sessionToken = bin2hex(random_bytes(64));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . SESSION_TIMEOUT . ' seconds'));

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

        SessionManager::set('user_id', $userId);
        SessionManager::set('user_token', $sessionToken);
        SessionManager::set('session_init_time', time());

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
            'csrf_token' => CSRFToken::generate(),
            'session_expires' => $expiresAt
        ]);

    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Logout
// ==============================================
function handleLogout(array $input): void
{
    $csrfToken = $input['csrf_token'] ?? '';
    if (!CSRFToken::validate($csrfToken)) {
        jsonResponse(false, 'Invalid CSRF token', [], 403);
    }

    try {
        if (SessionManager::has('user_id') && SessionManager::has('user_token')) {
            $db = Database::getInstance();
            $conn = $db->getConnection();

            $stmt = $conn->prepare("
                UPDATE sessions
                SET is_active = 0
                WHERE user_id = :user_id AND session_token = :token
            ");
            $stmt->execute([
                ':user_id' => SessionManager::get('user_id'),
                ':token' => SessionManager::get('user_token')
            ]);
        }

        SessionManager::destroy();

        jsonResponse(true, 'Logged out successfully');

    } catch (Exception $e) {
        jsonResponse(false, 'Error during logout: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Check Authentication
// ==============================================
function handleCheckAuth(): void
{
    try {
        if (!isLoggedIn()) {
            jsonResponse(false, 'Not authenticated', ['logged_in' => false], 401);
        }

        $userId = getCurrentUserId();
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
            SessionManager::destroy();
            jsonResponse(false, 'User not found or inactive', ['logged_in' => false], 401);
        }

        SessionManager::refresh();

        jsonResponse(true, 'Authenticated', [
            'logged_in' => true,
            'user' => $user,
            'csrf_token' => CSRFToken::generate()
        ]);

    } catch (Exception $e) {
        jsonResponse(false, 'Error: ' . $e->getMessage(), [], 500);
    }
}
