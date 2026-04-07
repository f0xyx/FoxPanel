<?php
// ============================================================
//  WebPanel Pro — Authentication Middleware
// ============================================================

require_once __DIR__ . '/config.php';

if (session_name() !== SESSION_NAME) {
    session_name(SESSION_NAME);
}

$cookieParams = session_get_cookie_params();
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => isset($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Strict',
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Helper: check if logged in ───────────────────────────────
function isLoggedIn(): bool {
    if (empty($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) return false;
    if (empty($_SESSION['last_activity'])) return false;
    if (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        return false;
    }
    $_SESSION['last_activity'] = time();
    return true;
}

// ── Helper: require login ────────────────────────────────────
function requireLogin(): void {
    if (!isLoggedIn()) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            http_response_code(401);
            echo json_encode(['error' => 'Session expired. Please login again.']);
            exit;
        }
        header('Location: ' . BASE_URL . '/cpanel.php?expired=1');
        exit;
    }
}

// ── Helper: attempt login ────────────────────────────────────
function attemptLogin(string $username, string $password): bool {
    if ($username !== ADMIN_USERNAME) return false;
    if (!password_verify($password, ADMIN_PASSWORD)) return false;
    session_regenerate_id(true);
    $_SESSION['logged_in']     = true;
    $_SESSION['username']      = $username;
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf_token']    = bin2hex(random_bytes(32));
    return true;
}

// ── Helper: CSRF ──────────────────────────────────────────────
function getCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(string $token): bool {
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// ── Helper: JSON response ─────────────────────────────────────
function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// ── Helper: sanitize path ─────────────────────────────────────
function sanitizePath(string $path, string $baseDir): string {
    $resolved = realpath($baseDir . '/' . ltrim($path, '/'));
    if ($resolved === false) {
        $resolved = $baseDir . '/' . ltrim($path, '/');
    }
    // Prevent path traversal
    if (strpos($resolved, realpath($baseDir)) !== 0) {
        throw new RuntimeException('Access denied: path traversal detected');
    }
    return $resolved;
}

// ── Helper: format bytes ──────────────────────────────────────
function formatBytes(int $bytes, int $precision = 2): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// ── Helper: safe exec ─────────────────────────────────────────
function safeExec(string $cmd): array {
    $blacklist = TERMINAL_BLACKLIST;
    foreach ($blacklist as $blocked) {
        if (strpos($cmd, $blocked) !== false) {
            return ['output' => 'Command blocked for security reasons.', 'code' => 1];
        }
    }
    $output = [];
    $code   = 0;
    exec($cmd . ' 2>&1', $output, $code);
    return ['output' => implode("\n", $output), 'code' => $code];
}

// ── Base URL ──────────────────────────────────────────────────
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
$script   = dirname($_SERVER['SCRIPT_NAME']);
define('BASE_URL', rtrim($protocol . '://' . $host . $script, '/'));
