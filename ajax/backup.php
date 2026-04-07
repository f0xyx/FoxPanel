<?php
require_once __DIR__ . '/../config/auth.php';
requireLogin();
header('Content-Type: application/json');
$action = $_GET['action'] ?? $_POST['action'] ?? '';
switch ($action) {
    case 'list':    listBackups(); break;
    case 'create':  createBackup(); break;
    case 'delete':  deleteBackup(); break;
    case 'download': downloadBackup(); break;
    default: jsonResponse(['error' => 'Unknown action'], 400);
}
function getBackupDir(): string {
    $dir = HOME_PATH . '/webpanel_backups';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    return $dir;
}
function listBackups(): void {
    $dir = getBackupDir();
    $files = glob($dir.'/*.{zip,tar.gz,tgz}', GLOB_BRACE) ?: [];
    $backups = [];
    foreach ($files as $f) {
        $backups[] = ['name'=>basename($f),'size'=>filesize($f),'size_fmt'=>formatBytes(filesize($f)),'mtime'=>filemtime($f),'mtime_fmt'=>date('Y-m-d H:i',filemtime($f))];
    }
    usort($backups, fn($a,$b)=>$b['mtime']-$a['mtime']);
    jsonResponse(['success'=>true,'backups'=>$backups,'dir'=>$dir]);
}
function createBackup(): void {
    if ($_SERVER['REQUEST_METHOD']!=='POST'){jsonResponse(['error'=>'POST required'],405);return;}
    $type   = $_POST['type'] ?? 'home'; // home, public_html, database
    $dir    = getBackupDir();
    $stamp  = date('Ymd_His');
    $name   = "backup_{$type}_{$stamp}.tar.gz";
    $dest   = $dir.'/'.$name;
    switch ($type) {
        case 'public_html': $src = HOME_PATH.'/public_html'; break;
        case 'home':        $src = HOME_PATH; break;
        default:            $src = HOME_PATH;
    }
    if (function_exists('shell_exec')) {
        $out = shell_exec("tar --exclude=".escapeshellarg($dir)." -czf ".escapeshellarg($dest)." -C ".escapeshellarg(dirname($src))." ".escapeshellarg(basename($src))." 2>&1");
        if (file_exists($dest)) {
            jsonResponse(['success'=>true,'name'=>$name,'size_fmt'=>formatBytes(filesize($dest)),'message'=>'Backup created']);
        } else {
            jsonResponse(['error'=>'Backup failed: '.($out??'unknown')],500);
        }
    } else {
        // PHP ZIP fallback
        if (!class_exists('ZipArchive')) { jsonResponse(['error'=>'No backup method available'],503); return; }
        $zipName = str_replace('.tar.gz','.zip',$dest);
        $zip = new ZipArchive();
        if ($zip->open($zipName, ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true){jsonResponse(['error'=>'Cannot create zip'],500);return;}
        $iter = new RecursiveIteratorMode(new RecursiveDirectoryIterator($src,RecursiveDirectoryIterator::SKIP_DOTS),RecursiveIteratorMode::SELF_FIRST);
        foreach ($iter as $f) { $local=substr($f->getPathname(),strlen($src)+1); if($f->isDir())$zip->addEmptyDir($local); else $zip->addFile($f->getPathname(),$local); }
        $zip->close();
        jsonResponse(['success'=>true,'name'=>basename($zipName),'message'=>'Backup created (ZIP)']);
    }
}
function deleteBackup(): void {
    if ($_SERVER['REQUEST_METHOD']!=='POST'){jsonResponse(['error'=>'POST required'],405);return;}
    $name = basename($_POST['name']??'');
    $file = getBackupDir().'/'.$name;
    if (!file_exists($file)){jsonResponse(['error'=>'File not found'],404);return;}
    unlink($file);
    jsonResponse(['success'=>true,'message'=>'Backup deleted']);
}
function downloadBackup(): void {
    $name = basename($_GET['name']??'');
    $file = getBackupDir().'/'.$name;
    if (!file_exists($file)){http_response_code(404);echo'Not found';exit;}
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="'.$name.'"');
    header('Content-Length: '.filesize($file));
    readfile($file); exit;
}
