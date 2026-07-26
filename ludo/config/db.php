<?php
/**
 * ======================================================
 * DATABASE CONFIGURATION & CORE SECURITY - FIXED
 * Ludo Tournament Platform - Production Ready
 * Version: 5.0.0 - COMPLETE REWRITE
 * ======================================================
 */

declare(strict_types=1);

// ======================================================
// ENVIRONMENT CONFIGURATION
// ======================================================
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $env = parse_ini_file($envFile);
} else {
    $env = [];
}

define('ENVIRONMENT', $env['ENVIRONMENT'] ?? 'production');
define('DB_HOST', $env['DB_HOST'] ?? 'localhost');
define('DB_NAME', $env['ludo_tournament'] ?? 'ludo_tournament_db');
define('DB_USER', $env['DB_USER'] ?? 'ludo_user');
define('DB_PASS', $env['DB_PASS'] ?? '');
define('DB_CHARSET', 'utf8mb4');

define('BASE_URL', $env['BASE_URL'] ?? 'https://yourdomain.com');
define('SITE_NAME', 'Ludo Tournament Pro');
define('ADMIN_EMAIL', $env['ADMIN_EMAIL'] ?? 'support@yourdomain.com');
define('TIMEZONE', 'Asia/Kolkata');
define('SESSION_TIMEOUT', (int)($env['SESSION_TIMEOUT'] ?? 1800));
define('MAX_LOGIN_ATTEMPTS', 5);
define('CSRF_TOKEN_LENGTH', 32);
define('PLATFORM_FEE', (float)($env['PLATFORM_FEE'] ?? 15));
define('TDS_RATE', (float)($env['TDS_RATE'] ?? 30));
define('TDS_THRESHOLD', (float)($env['TDS_THRESHOLD'] ?? 10000));

date_default_timezone_set(TIMEZONE);

// ======================================================
// ERROR REPORTING
// ======================================================
if (ENVIRONMENT === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
}

// ======================================================
// DATABASE CLASS - COMPLETE REWRITE
// ======================================================
class Database
{
    private static ?Database $instance = null;
    private PDO $connection;

    private function __construct()
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_PERSISTENT => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES '" . DB_CHARSET . "' COLLATE 'utf8mb4_unicode_ci'",
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ];

        try {
            $dsn = sprintf(
                "mysql:host=%s;dbname=%s;charset=%s",
                DB_HOST,
                DB_NAME,
                DB_CHARSET
            );
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            $this->handleError("Database connection failed", $e);
        }
    }

    private function __clone() {}
    public function __wakeup() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): PDO
    {
        return $this->connection;
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        try {
            $stmt = $this->connection->prepare($sql);
            if (!$stmt) {
                throw new PDOException($this->connection->errorInfo()[2]);
            }

            foreach ($params as $key => $value) {
                $type = $this->getParamType($value);
                $stmt->bindValue($key, $value, $type);
            }

            $stmt->execute();
            return $stmt;

        } catch (PDOException $e) {
            $this->handleError("Query failed", $e, $sql, $params);
            throw $e;
        }
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $result = $this->query($sql, $params)->fetch();
        return $result ?: null;
    }

    public function fetchColumn(string $sql, array $params = [], int $column = 0)
    {
        return $this->query($sql, $params)->fetchColumn($column);
    }

    public function insert(string $table, array $data): int
    {
        $fields = array_keys($data);
        $placeholders = ':' . implode(', :', $fields);
        $sql = sprintf(
            "INSERT INTO `%s` (`%s`) VALUES (%s)",
            $table,
            implode('`, `', $fields),
            $placeholders
        );
        $this->query($sql, $data);
        return (int)$this->connection->lastInsertId();
    }

    public function update(string $table, array $data, array $where): int
    {
        $setParts = [];
        foreach ($data as $field => $value) {
            $setParts[] = "`{$field}` = :set_{$field}";
        }

        $whereParts = [];
        foreach ($where as $field => $value) {
            $whereParts[] = "`{$field}` = :where_{$field}";
        }

        $params = [];
        foreach ($data as $field => $value) {
            $params["set_{$field}"] = $value;
        }
        foreach ($where as $field => $value) {
            $params["where_{$field}"] = $value;
        }

        $sql = sprintf(
            "UPDATE `%s` SET %s WHERE %s",
            $table,
            implode(', ', $setParts),
            implode(' AND ', $whereParts)
        );

        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    public function beginTransaction(): bool
    {
        return $this->connection->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->connection->commit();
    }

    public function rollback(): bool
    {
        return $this->connection->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->connection->inTransaction();
    }

    public function lastInsertId(): string
    {
        return $this->connection->lastInsertId();
    }

    private function getParamType($value): int
    {
        if (is_int($value)) return PDO::PARAM_INT;
        if (is_bool($value)) return PDO::PARAM_BOOL;
        if (is_null($value)) return PDO::PARAM_NULL;
        return PDO::PARAM_STR;
    }

    private function handleError(string $message, PDOException $e, ?string $sql = null, ?array $params = null): void
    {
        if (ENVIRONMENT === 'development') {
            return;
        }

        $errorLog = [
            'timestamp' => date('Y-m-d H:i:s'),
            'message' => $message,
            'error' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'sql' => $sql,
            'params' => $params,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ];

        $logFile = __DIR__ . '/../logs/db_errors.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        file_put_contents($logFile, json_encode($errorLog) . PHP_EOL, FILE_APPEND | LOCK_EX);

        throw new PDOException("Database error occurred. Please try again later.");
    }
}

// ======================================================
// SESSION MANAGEMENT
// ======================================================
class SessionManager
{
    public static function init(): bool
    {
        $cookieParams = session_get_cookie_params();

        $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
        $domain = $cookieParams['domain'] ?? '';

        session_set_cookie_params([
            'lifetime' => SESSION_TIMEOUT,
            'path' => '/',
            'domain' => $domain,
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        session_name('LUDO_SESS_ID');

        if (session_status() === PHP_SESSION_NONE) {
            $result = session_start();
            if (!isset($_SESSION['session_init_time'])) {
                $_SESSION['session_init_time'] = time();
                session_regenerate_id(true);
            }
            return $result;
        }
        return true;
    }

    public static function get(string $key, $default = null)
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }
        session_destroy();
    }

    public static function refresh(): void
    {
        if (isset($_SESSION['session_init_time'])) {
            $_SESSION['session_init_time'] = time();
            if (rand(1, 10) === 1) {
                session_regenerate_id(true);
            }
        }
    }
}

// ======================================================
// CSRF TOKEN
// ======================================================
class CSRFToken
{
    public static function generate(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(CSRF_TOKEN_LENGTH));
            $_SESSION['csrf_token_time'] = time();
        }
        return $_SESSION['csrf_token'];
    }

    public static function validate(?string $token): bool
    {
        if (empty($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }

        if (isset($_SESSION['csrf_token_time'])) {
            if ((time() - $_SESSION['csrf_token_time']) > 3600) {
                unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
                return false;
            }
        }

        return hash_equals($_SESSION['csrf_token'], $token);
    }

    public static function getHTMLField(): string
    {
        return '<input type="hidden" name="csrf_token" value="' .
            htmlspecialchars(self::generate(), ENT_QUOTES, 'UTF-8') .
            '">';
    }
}

// ======================================================
// UTILITY FUNCTIONS
// ======================================================

function sanitizeInput($data)
{
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function generateRandomString(int $length = 10): string
{
    return bin2hex(random_bytes($length));
}

function generateRoomCode(): string
{
    return strtoupper(substr(generateRandomString(4), 0, 6));
}

function generateReferralCode(): string
{
    return 'REF' . strtoupper(substr(generateRandomString(4), 0, 8));
}

function formatCurrency(float $amount): string
{
    return '₹' . number_format($amount, 2);
}

function calculatePlatformFee(float $amount): float
{
    return round($amount * (PLATFORM_FEE / 100), 2);
}

function calculatePrizePool(float $entryFee, int $players): float
{
    $total = $entryFee * $players;
    $fee = calculatePlatformFee($total);
    return round($total - $fee, 2);
}

function jsonResponse(bool $success, string $message, array $data = [], int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');

    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'timestamp' => time()
    ]);
    exit;
}

function isLoggedIn(): bool
{
    return SessionManager::has('user_id') && !empty(SessionManager::get('user_id'));
}

function getCurrentUserId(): ?int
{
    return SessionManager::get('user_id');
}

function isAdminLoggedIn(): bool
{
    if (!SessionManager::has('admin_id') || !SessionManager::has('admin_token')) {
        return false;
    }

    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();

        $stmt = $conn->prepare("
            SELECT s.id
            FROM sessions s
            WHERE s.user_id = :admin_id
            AND s.session_token = :token
            AND s.is_active = 1
            AND s.expires_at > NOW()
        ");
        $stmt->execute([
            ':admin_id' => SessionManager::get('admin_id'),
            ':token' => SessionManager::get('admin_token')
        ]);

        return $stmt->fetch() !== false;
    } catch (Exception $e) {
        return false;
    }
}

// ======================================================
// INITIALIZE SESSION
// ======================================================
SessionManager::init();

// ======================================================
// END OF CONFIGURATION FILE
// ======================================================
