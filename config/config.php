<?php
// ============================================================
//  WebPanel Pro — Main Configuration
// ============================================================

define('PANEL_VERSION', '1.0.0');
define('PANEL_NAME', 'WebPanel Pro');
define('PANEL_BRAND', 'WP');

// ── Authentication ──────────────────────────────────────────
// Change these credentials after first login!
define('ADMIN_USERNAME', 'admin');
define('ADMIN_PASSWORD', '$2y$12$A7Ow5URF4.kjd8cgRtIb5OxrLRJepjopXxH4Yp64dRa1yJBwIqPhe'); // default: admin123
// To generate a new hash: echo password_hash('yourpassword', PASSWORD_BCRYPT);

define('SESSION_TIMEOUT', 1800); // 30 minutes
define('SESSION_NAME', 'webpanel_session');
define('CSRF_TOKEN_NAME', 'webpanel_csrf');

// ── Paths ───────────────────────────────────────────────────
define('ROOT_PATH', realpath(__DIR__ . '/..'));
define('HOME_PATH', isset($_SERVER['HOME']) ? $_SERVER['HOME'] : ROOT_PATH);
define('WEBROOT_PATH', HOME_PATH . '/public_html');
define('LOG_PATH', HOME_PATH . '/logs');
define('BACKUP_PATH', HOME_PATH . '/backups');

// ── Database (optional) ─────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_USER', ''); // Fill with your MySQL username
define('DB_PASS', ''); // Fill with your MySQL password
define('DB_CHARSET', 'utf8mb4');

// ── Email SMTP (optional) ───────────────────────────────────
define('MAIL_HOST', 'localhost');
define('MAIL_PORT', 25);

// ── GSocket ─────────────────────────────────────────────────
define('GSOCKET_INSTALL_DIR', HOME_PATH . '/bin');
define('GSOCKET_REPO', 'https://github.com/hackerschoice/gsocket/releases/latest/download');

// ── Security ─────────────────────────────────────────────────
// Commands blacklisted in terminal
define('TERMINAL_BLACKLIST', [
    'rm -rf /',
    'rm -rf /*',
    'mkfs',
    ':(){:|:&};:',
    'dd if=/dev/zero',
    'chmod -R 777 /',
    '> /dev/sda',
]);

// ── Feature Flags ────────────────────────────────────────────
define('FEATURE_TERMINAL', true);
define('FEATURE_FILE_MANAGER', true);
define('FEATURE_DATABASE', true);
define('FEATURE_EMAIL', true);
define('FEATURE_FTP', true);
define('FEATURE_DNS', true);
define('FEATURE_CRON', true);
define('FEATURE_GSOCKET', true);
define('FEATURE_INSTALLER', true);
define('FEATURE_BACKUP', true);
define('FEATURE_LOGS', true);
define('FEATURE_PROCESS', true);
define('FEATURE_SECURITY', true);
define('FEATURE_SSL', true);

// ── Timezone ─────────────────────────────────────────────────
define('PANEL_TIMEZONE', 'Asia/Jakarta');
date_default_timezone_set(PANEL_TIMEZONE);
