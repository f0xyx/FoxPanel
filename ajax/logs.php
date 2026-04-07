<?php
require_once __DIR__ . '/../config/auth.php';
requireLogin();
header('Content-Type: application/json');
$action = $_GET['action'] ?? $_POST['action'] ?? '';
switch ($action) {
    case 'list':  listLogs(); break;
    case 'read':  readLog(); break;
    case 'clear': clearLog(); break;
    default: jsonResponse(['error' => 'Unknown action'], 400);
}
function findLogs(): array {
    $common = [
        HOME_PATH.'/logs/error.log', HOME_PATH.'/logs/access.log',
        HOME_PATH.'/logs/php_error.log',
        '/var/log/apache2/error.log', '/var/log/apache2/access.log',
        '/var/log/nginx/error.log', '/var/log/nginx/access.log',
        '/var/log/php/error.log', ini_get('error_log'),
    ];
    $logs = [];
    foreach ($common as $path) {
        if ($path && is_readable($path)) {
            $logs[] = ['name'=>basename($path),'path'=>$path,'size'=>filesize($path),'size_fmt'=>formatBytes(filesize($path)),'mtime'=>filemtime($path),'mtime_fmt'=>date('Y-m-d H:i',filemtime($path))];
        }
    }
    // Also search home/logs dir
    $logDir = HOME_PATH.'/logs';
    if (is_dir($logDir)) {
        foreach (glob($logDir.'/*.log') as $f) {
            if (!array_filter($logs, fn($l)=>$l['path']===$f) && is_readable($f)) {
                $logs[] = ['name'=>basename($f),'path'=>$f,'size'=>filesize($f),'size_fmt'=>formatBytes(filesize($f)),'mtime'=>filemtime($f),'mtime_fmt'=>date('Y-m-d H:i',filemtime($f))];
            }
        }
    }
    return $logs;
}
function listLogs(): void { jsonResponse(['success'=>true,'logs'=>findLogs()]); }
function readLog(): void {
    $path  = $_GET['path'] ?? '';
    $lines = (int)($_GET['lines'] ?? 200);
    if (!$path || !is_readable($path)) { jsonResponse(['error'=>'Log not readable'],404); return; }
    if (function_exists('shell_exec')) {
        $content = shell_exec('tail -'.abs($lines).' '.escapeshellarg($path).' 2>/dev/null');
    } else {
        $all = file($path);
        $content = implode('', array_slice($all, -abs($lines)));
    }
    jsonResponse(['success'=>true,'content'=>$content,'path'=>$path,'lines'=>$lines]);
}
function clearLog(): void {
    if ($_SERVER['REQUEST_METHOD']!=='POST'){jsonResponse(['error'=>'POST required'],405);return;}
    $path=$_POST['path']??'';
    if (!$path||!is_writable($path)){jsonResponse(['error'=>'Not writable'],403);return;}
    file_put_contents($path,'');
    jsonResponse(['success'=>true,'message'=>'Log cleared']);
}
