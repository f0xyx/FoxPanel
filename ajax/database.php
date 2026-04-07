<?php
require_once __DIR__ . '/../config/auth.php';
requireLogin();
header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'list':   listDatabases(); break;
    case 'create': createDatabase(); break;
    case 'drop':   dropDatabase(); break;
    case 'users':  listUsers(); break;
    case 'create_user': createUser(); break;
    case 'drop_user':   dropUser(); break;
    case 'grant':  grantPrivileges(); break;
    case 'tables': listTables(); break;
    case 'discover': discoverDatabases(); break;
    case 'set_db': setDatabaseCreds(); break;
    case 'clear_db': clearDatabaseCreds(); break;
    default: jsonResponse(['error' => 'Unknown action'], 400);
}

function getDB(): ?PDO {
    // 1. Session stored overriding creds (from auto discovery)
    if (!empty($_SESSION['db_cred'])) {
        $c = $_SESSION['db_cred'];
        $host = $c['host'] ?? 'localhost';
        $port = $c['port'] ?? '3306';
        $user = $c['user'] ?? '';
        $pass = $c['pass'] ?? '';
        $charset = DB_CHARSET;
    } else {
        // 2. Default fallback from config.php
        if (empty(DB_USER)) return null;
        $host = DB_HOST;
        $port = DB_PORT;
        $user = DB_USER;
        $pass = DB_PASS;
        $charset = DB_CHARSET;
    }

    try {
        $dsn = 'mysql:host=' . $host . ';port=' . $port . ';charset=' . $charset;
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        return null;
    }
}

function listDatabases(): void {
    $pdo = getDB();
    if (!$pdo) { jsonResponse(['error' => 'MySQL not configured. Use Auto-Discover to find your database config.', 'databases' => []]); return; }
    try {
        $dbs = $pdo->query("SELECT schema_name as name, default_character_set_name as charset FROM information_schema.schemata")->fetchAll();
        $system = ['information_schema','mysql','performance_schema','sys'];
        foreach ($dbs as &$db) {
            $db['system'] = in_array($db['name'], $system);
            try {
                $db['tables'] = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='{$db['name']}'")->fetchColumn();
                $sizeRes = $pdo->query("SELECT ROUND(SUM(data_length+index_length)/1024/1024,2) as mb FROM information_schema.tables WHERE table_schema='{$db['name']}'")->fetchColumn();
                $db['size_fmt'] = $sizeRes ? $sizeRes . ' MB' : '—';
            } catch (Exception $e) { $db['tables'] = 0; $db['size_fmt'] = '—'; }
        }
        jsonResponse(['success' => true, 'databases' => $dbs]);
    } catch (PDOException $e) {
        jsonResponse(['error' => $e->getMessage(), 'databases' => []], 500);
    }
}

function createDatabase(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['error' => 'POST required'], 405); return; }
    $pdo = getDB(); if (!$pdo) { jsonResponse(['error' => 'MySQL not configured']); return; }
    $name    = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['name'] ?? '');
    $charset = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['charset'] ?? 'utf8mb4');
    if (empty($name)) { jsonResponse(['error' => 'Name required'], 400); return; }
    try {
        $pdo->exec("CREATE DATABASE `$name` CHARACTER SET $charset COLLATE {$charset}_unicode_ci");
        jsonResponse(['success' => true, 'message' => "Database '$name' created"]);
    } catch (PDOException $e) {
        jsonResponse(['error' => $e->getMessage()], 500);
    }
}

function dropDatabase(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['error' => 'POST required'], 405); return; }
    $pdo = getDB(); if (!$pdo) { jsonResponse(['error' => 'MySQL not configured']); return; }
    $name = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['name'] ?? '');
    if (empty($name)) { jsonResponse(['error' => 'Name required'], 400); return; }
    try {
        $pdo->exec("DROP DATABASE `$name`");
        jsonResponse(['success' => true, 'message' => "Database '$name' dropped"]);
    } catch (PDOException $e) {
        jsonResponse(['error' => $e->getMessage()], 500);
    }
}

function listUsers(): void {
    $pdo = getDB();
    if (!$pdo) { jsonResponse(['error' => 'MySQL not configured', 'users' => []]); return; }
    try {
        $users = $pdo->query("SELECT user, host FROM mysql.user ORDER BY user")->fetchAll();
        jsonResponse(['success' => true, 'users' => $users]);
    } catch (PDOException $e) {
        jsonResponse(['error' => $e->getMessage(), 'users' => []], 500);
    }
}

function createUser(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['error' => 'POST required'], 405); return; }
    $pdo = getDB(); if (!$pdo) { jsonResponse(['error' => 'MySQL not configured']); return; }
    $user = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['user'] ?? '');
    $pass = $_POST['password'] ?? '';
    $host = preg_replace('/[^a-zA-Z0-9_.%]/', '', $_POST['host'] ?? 'localhost');
    if (empty($user) || empty($pass)) { jsonResponse(['error' => 'User and password required'], 400); return; }
    try {
        $pdo->exec("CREATE USER '$user'@'$host' IDENTIFIED BY " . $pdo->quote($pass));
        $pdo->exec("FLUSH PRIVILEGES");
        jsonResponse(['success' => true, 'message' => "User '$user'@'$host' created"]);
    } catch (PDOException $e) {
        jsonResponse(['error' => $e->getMessage()], 500);
    }
}

function dropUser(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['error' => 'POST required'], 405); return; }
    $pdo = getDB(); if (!$pdo) { jsonResponse(['error' => 'MySQL not configured']); return; }
    $user = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['user'] ?? '');
    $host = preg_replace('/[^a-zA-Z0-9_.%]/', '', $_POST['host'] ?? 'localhost');
    try {
        $pdo->exec("DROP USER '$user'@'$host'");
        $pdo->exec("FLUSH PRIVILEGES");
        jsonResponse(['success' => true, 'message' => "User '$user'@'$host' dropped"]);
    } catch (PDOException $e) {
        jsonResponse(['error' => $e->getMessage()], 500);
    }
}

function grantPrivileges(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['error' => 'POST required'], 405); return; }
    $pdo = getDB(); if (!$pdo) { jsonResponse(['error' => 'MySQL not configured']); return; }
    $user  = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['user'] ?? '');
    $host  = preg_replace('/[^a-zA-Z0-9_.%]/', '', $_POST['host'] ?? 'localhost');
    $db    = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['database'] ?? '');
    $privs = preg_replace('/[^A-Z_, ]/', '', strtoupper($_POST['privileges'] ?? 'ALL PRIVILEGES'));
    try {
        $pdo->exec("GRANT $privs ON `$db`.* TO '$user'@'$host'");
        $pdo->exec("FLUSH PRIVILEGES");
        jsonResponse(['success' => true, 'message' => "Granted $privs on $db to $user@$host"]);
    } catch (PDOException $e) {
        jsonResponse(['error' => $e->getMessage()], 500);
    }
}

function listTables(): void {
    $pdo = getDB(); $db = $_GET['db'] ?? '';
    if (!$pdo || empty($db)) { jsonResponse(['error' => 'MySQL not configured or no DB', 'tables' => []]); return; }
    $db = preg_replace('/[^a-zA-Z0-9_]/', '', $db);
    try {
        $tables = $pdo->query("SHOW TABLE STATUS FROM `$db`")->fetchAll();
        jsonResponse(['success' => true, 'tables' => $tables]);
    } catch (PDOException $e) {
        jsonResponse(['error' => $e->getMessage(), 'tables' => []], 500);
    }
}

// ── Database Discovery: scan all common paths for wp-config.php ───────────────

function discoverDatabases(): void {
    $found = [];
    $scannedDirs = 0;

    // Common web server root paths to search
    $searchRoots = array_unique(array_filter(array_map('realpath', [
        '/var/www',
        '/home',
        '/srv/www',
        '/srv',
        '/opt/lampp/htdocs',
        '/Applications/XAMPP/xamppfiles/htdocs',
        '/usr/local/var/www',
        HOME_PATH,
        WEBROOT_PATH,
        ROOT_PATH,
    ]), fn($p) => $p !== false && is_dir($p)));

    $skipDirs = [
        '.git', 'vendor', 'node_modules', '.cache', 'cache',
        'logs', 'tmp', 'temp', 'backups', '.sass-cache',
        '__pycache__', '.venv', 'venv', 'proc', 'sys', 'dev',
    ];

    $seen = [];

    foreach ($searchRoots as $base) {
        try {
            $dirItr = new RecursiveDirectoryIterator(
                $base,
                RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS
            );

            // Use RecursiveIteratorIterator (correct class name) with SELF_FIRST
            $iter = new RecursiveIteratorIterator($dirItr, RecursiveIteratorIterator::SELF_FIRST);

            foreach ($iter as $file) {
                $scannedDirs++;
                if ($scannedDirs > 100000) break 2;

                // Skip heavy directories by depth-based pruning
                try {
                    if ($file->isDir() && in_array($file->getFilename(), $skipDirs)) {
                        $iter->setMaxDepth($iter->getDepth() - 1);
                        continue;
                    }
                } catch (Throwable $e) { continue; }

                if (!$file->isFile() || $file->getFilename() !== 'wp-config.php') continue;

                // Skip unreadable files
                try {
                    if (!$file->isReadable()) continue;
                    $realPath = realpath($file->getPathname());
                } catch (Throwable $e) { continue; }

                if (!$realPath || isset($seen[$realPath])) continue;
                $seen[$realPath] = true;

                $content = @file_get_contents($realPath, false, null, 0, 16384);
                if (!$content) continue;

                $dbName = $dbUser = $dbPass = $dbHost = $tablePrefix = '';
                if (preg_match("/define\(\s*['\"]DB_NAME['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $content, $m))     $dbName = $m[1];
                if (preg_match("/define\(\s*['\"]DB_USER['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $content, $m))     $dbUser = $m[1];
                if (preg_match("/define\(\s*['\"]DB_PASSWORD['\"]\s*,\s*['\"]([^'\"]*)['\"]/" , $content, $m)) $dbPass = $m[1];
                if (preg_match("/define\(\s*['\"]DB_HOST['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $content, $m))     $dbHost = $m[1];
                if (preg_match("/\\\$table_prefix\s*=\s*['\"]([^'\"]+)['\"]/", $content, $m))                 $tablePrefix = $m[1];

                if (empty($dbName) || empty($dbUser)) continue;

                $found[] = [
                    'file'         => $realPath,
                    'framework'    => 'WordPress',
                    'name'         => $dbName,
                    'user'         => $dbUser,
                    'pass'         => $dbPass,
                    'host'         => empty($dbHost) ? 'localhost' : $dbHost,
                    'table_prefix' => $tablePrefix ?: 'wp_',
                ];
            }
        } catch (Exception $e) {
            // Permission denied or other error on this root — skip to next
        }
    }


    // Auto-connect silently if exactly 1 found and no active session cred
    $autoConnected = false;
    if (count($found) === 1 && empty($_SESSION['db_cred'])) {
        $c = $found[0];
        $_SESSION['db_cred'] = [
            'host' => $c['host'], 'user' => $c['user'],
            'pass' => $c['pass'], 'port' => '3306',
        ];
        $autoConnected = true;
    }

    $currentActive = null;
    if (!empty($_SESSION['db_cred'])) {
        $currentActive = $_SESSION['db_cred']['user'] . '@' . $_SESSION['db_cred']['host'];
    }

    jsonResponse([
        'success'        => true,
        'found'          => $found,
        'auto_connected' => $autoConnected,
        'current_active' => $currentActive,
        'scanned'        => $scannedDirs,
    ]);
}

function setDatabaseCreds(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['error' => 'POST required'], 405); return; }

    $_SESSION['db_cred'] = [
        'host' => $_POST['host'] ?? 'localhost',
        'user' => $_POST['user'] ?? '',
        'pass' => $_POST['pass'] ?? '',
        'port' => '3306',
    ];

    $pdo = getDB();
    if (!$pdo) {
        unset($_SESSION['db_cred']);
        jsonResponse(['error' => 'Could not connect with these credentials. Check host/user/password.'], 401);
        return;
    }

    jsonResponse(['success' => true, 'message' => 'Database connection switched successfully!']);
}

function clearDatabaseCreds(): void {
    unset($_SESSION['db_cred']);
    jsonResponse(['success' => true, 'message' => 'Reverted to default panel connection.']);
}
