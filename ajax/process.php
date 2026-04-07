<?php
require_once __DIR__ . '/../config/auth.php';
requireLogin();
header('Content-Type: application/json');
$action = $_GET['action'] ?? $_POST['action'] ?? '';
switch ($action) {
    case 'list':    listProcesses(); break;
    case 'kill':    killProcess(); break;
    default: jsonResponse(['error' => 'Unknown action'], 400);
}
function listProcesses(): void {
    $processes = [];
    if (function_exists('shell_exec')) {
        $raw = shell_exec("ps aux 2>/dev/null | head -60");
        if ($raw) {
            $lines = explode("\n", trim($raw));
            array_shift($lines);
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                $parts = preg_split('/\s+/', $line, 11);
                if (count($parts) < 11) continue;
                $processes[] = [
                    'user'=>$parts[0],'pid'=>(int)$parts[1],'cpu'=>(float)$parts[2],
                    'mem'=>(float)$parts[3],'stat'=>$parts[7],'time'=>$parts[9],
                    'command'=>$parts[10],'cmd_short'=>substr(basename(explode(' ',$parts[10])[0]),0,30)
                ];
            }
        }
    }
    jsonResponse(['success' => true, 'processes' => $processes]);
}
function killProcess(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['error' => 'POST required'], 405); return; }
    $pid = (int)($_POST['pid'] ?? 0);
    $signal = (int)($_POST['signal'] ?? 15);
    if ($pid <= 1) { jsonResponse(['error' => 'Invalid PID'], 400); return; }
    if (function_exists('posix_kill')) {
        $r = posix_kill($pid, $signal);
        if ($r) jsonResponse(['success' => true, 'message' => "Signal $signal sent to PID $pid"]);
        else jsonResponse(['error' => posix_strerror(posix_get_last_error())], 500);
    } else {
        exec("kill -$signal $pid 2>&1", $out, $code);
        if ($code === 0) jsonResponse(['success' => true]);
        else jsonResponse(['error' => implode("\n", $out)], 500);
    }
}
