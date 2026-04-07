<?php
require_once __DIR__ . '/../config/auth.php';
requireLogin();
header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
// File Manager accesses the full filesystem from root
// basePath is just used for security boundary — we allow full system access
$basePath = '/';

switch ($action) {
    case 'list':     listFiles(); break;
    case 'read':     readFile_(); break;
    case 'write':    writeFile_(); break;
    case 'delete':   deleteFile(); break;
    case 'rename':   renameFile(); break;
    case 'mkdir':    makeDir(); break;
    case 'upload':   uploadFile(); break;
    case 'chmod':    chmodFile(); break;
    case 'compress': compressFiles(); break;
    case 'extract':  extractFile(); break;
    case 'download': downloadFile(); break;
    case 'stat':     statFile(); break;
    case 'copy':     copyFile_(); break;
    case 'move':     moveFile(); break;
    case 'search':   searchFiles(); break;
    default: jsonResponse(['error' => 'Unknown action'], 400);
}

function resolvePath(string $rel): string {
    // Normalize the path — start from absolute / root
    if (empty($rel)) $rel = '/';
    // Clean the path
    $rel = '/' . ltrim($rel, '/');
    // Resolve any .. traversal
    $parts = [];
    foreach (explode('/', $rel) as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..') { array_pop($parts); continue; }
        $parts[] = $part;
    }
    $clean = '/' . implode('/', $parts);
    // Attempt realpath
    $real = realpath($clean);
    return $real !== false ? $real : $clean;
}

function relPath(string $abs): string {
    // Since basePath is /, paths are already absolute
    return $abs;
}

function fileIcon(string $name, bool $isDir): string {
    if ($isDir) return 'folder';
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $map = [
        'php' => 'php', 'html' => 'html', 'htm' => 'html',
        'js' => 'js', 'ts' => 'js', 'css' => 'css',
        'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image', 'svg' => 'image', 'webp' => 'image',
        'mp4' => 'video', 'mkv' => 'video', 'avi' => 'video',
        'mp3' => 'audio', 'ogg' => 'audio', 'wav' => 'audio',
        'zip' => 'archive', 'tar' => 'archive', 'gz' => 'archive', 'bz2' => 'archive', 'rar' => 'archive', '7z' => 'archive',
        'pdf' => 'pdf', 'doc' => 'word', 'docx' => 'word',
        'xls' => 'excel', 'xlsx' => 'excel', 'csv' => 'excel',
        'txt' => 'text', 'log' => 'log', 'md' => 'markdown',
        'sql' => 'sql', 'json' => 'json', 'xml' => 'xml',
        'sh' => 'shell', 'bash' => 'shell', 'py' => 'python',
        'env' => 'config', 'ini' => 'config', 'conf' => 'config', 'yaml' => 'config', 'yml' => 'config',
    ];
    return $map[$ext] ?? 'file';
}

function listFiles(): void {
    $dir = resolvePath($_GET['path'] ?? '/');
    if (!is_dir($dir)) { jsonResponse(['error' => 'Not a directory'], 400); return; }
    if (!is_readable($dir)) { jsonResponse(['error' => 'Permission denied: cannot read this directory'], 403); return; }

    $items = [];
    try {
        $entries = new DirectoryIterator($dir);
        foreach ($entries as $entry) {
            if ($entry->isDot()) continue;
            $name  = $entry->getFilename();
            $isDir = $entry->isDir();

            // Skip entries we can't read
            if (!$entry->isReadable()) continue;

            // Skip unreadable / broken symlinks / virtual OS files
            try {
                $mtime = $entry->getMTime();
                $perms = substr(sprintf('%o', $entry->getPerms()), -4);
                $size  = ($isDir || !$entry->isFile()) ? 0 : $entry->getSize();
                $owner = function_exists('posix_getpwuid')
                    ? (posix_getpwuid($entry->getOwner())['name'] ?? $entry->getOwner())
                    : $entry->getOwner();
            } catch (Throwable $e) {
                continue;
            }

            $items[] = [
                'name'      => $name,
                'path'      => relPath($entry->getPathname()),
                'isDir'     => $isDir,
                'size'      => $size,
                'size_fmt'  => $isDir ? '—' : formatBytes($size),
                'mtime'     => $mtime,
                'mtime_fmt' => date('Y-m-d H:i', $mtime),
                'perms'     => $perms,
                'owner'     => $owner,
                'icon'      => fileIcon($name, $isDir),
                'readable'  => true,
                'writable'  => $entry->isWritable(),
                'ext'       => $isDir ? '' : strtolower(pathinfo($name, PATHINFO_EXTENSION)),
            ];
        }
    } catch (Exception $e) {
        jsonResponse(['error' => $e->getMessage()], 500); return;
    }

    usort($items, function($a, $b) {
        if ($a['isDir'] !== $b['isDir']) return $b['isDir'] - $a['isDir'];
        return strcmp($a['name'], $b['name']);
    });
    jsonResponse(['success' => true, 'items' => $items, 'path' => relPath($dir)]);
}



function readFile_(): void {
    $path = resolvePath($_GET['path'] ?? '');
    if (!is_file($path)) { jsonResponse(['error' => 'Not a file'], 400); return; }
    if (!is_readable($path)) { jsonResponse(['error' => 'Not readable'], 403); return; }
    $size = filesize($path);
    if ($size > 2 * 1024 * 1024) { jsonResponse(['error' => 'File too large to edit (max 2MB)'], 400); return; }
    $content = file_get_contents($path);
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $modeMap = ['php'=>'php','js'=>'javascript','ts'=>'javascript','css'=>'css','html'=>'htmlmixed','htm'=>'htmlmixed','xml'=>'xml','sh'=>'shell','bash'=>'shell','json'=>'javascript','md'=>'markdown'];
    jsonResponse([
        'success' => true,
        'content' => $content,
        'path'    => relPath($path),
        'name'    => basename($path),
        'mode'    => $modeMap[$ext] ?? 'text/plain',
        'size'    => $size,
    ]);
}

function writeFile_(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['error' => 'POST required'], 405); return; }
    $path    = resolvePath($_POST['path'] ?? '');
    $content = $_POST['content'] ?? '';
    $dir = dirname($path);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    if (file_put_contents($path, $content) === false) { jsonResponse(['error' => 'Write failed'], 500); return; }
    jsonResponse(['success' => true, 'message' => 'File saved successfully']);
}

function deleteFile(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['error' => 'POST required'], 405); return; }
    $paths = $_POST['paths'] ?? [$_POST['path'] ?? ''];
    if (is_string($paths)) $paths = [$paths];
    $errors = [];
    foreach ($paths as $p) {
        $full = resolvePath($p);
        if (is_dir($full)) {
            if (!deleteDir($full)) $errors[] = $p;
        } else {
            if (!@unlink($full)) $errors[] = $p;
        }
    }
    if ($errors) jsonResponse(['error' => 'Failed to delete: ' . implode(', ', $errors)], 500);
    else jsonResponse(['success' => true, 'message' => count($paths) . ' item(s) deleted']);
}

function deleteDir(string $dir): bool {
    $items = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
    foreach (new RecursiveIteratorMode($items, RecursiveIteratorMode::CHILD_FIRST) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    return rmdir($dir);
}

function renameFile(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['error' => 'POST required'], 405); return; }
    $from = resolvePath($_POST['from'] ?? '');
    $toName = basename($_POST['to'] ?? '');
    $to   = dirname($from) . '/' . $toName;
    if (!file_exists($from)) { jsonResponse(['error' => 'Source not found'], 404); return; }
    if (file_exists($to))    { jsonResponse(['error' => 'Destination already exists'], 409); return; }
    if (!rename($from, $to)) { jsonResponse(['error' => 'Rename failed'], 500); return; }
    jsonResponse(['success' => true, 'message' => 'Renamed to ' . $toName]);
}

function makeDir(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['error' => 'POST required'], 405); return; }
    $parent = resolvePath($_POST['parent'] ?? '/');
    $name   = basename($_POST['name'] ?? '');
    if (empty($name)) { jsonResponse(['error' => 'Name required'], 400); return; }
    $dir = $parent . '/' . $name;
    if (file_exists($dir)) { jsonResponse(['error' => 'Already exists'], 409); return; }
    if (!mkdir($dir, 0755, true)) { jsonResponse(['error' => 'Failed to create directory'], 500); return; }
    jsonResponse(['success' => true, 'message' => 'Directory created']);
}

function uploadFile(): void {
    if (empty($_FILES['files'])) { jsonResponse(['error' => 'No files'], 400); return; }
    $path    = resolvePath($_POST['path'] ?? '/');
    $files   = $_FILES['files'];
    $count   = is_array($files['name']) ? count($files['name']) : 1;
    $errors  = [];
    $success = 0;
    for ($i = 0; $i < $count; $i++) {
        $name = is_array($files['name']) ? $files['name'][$i] : $files['name'];
        $tmp  = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
        $dest = $path . '/' . basename($name);
        if (move_uploaded_file($tmp, $dest)) $success++;
        else $errors[] = $name;
    }
    if ($errors) jsonResponse(['error' => 'Failed to upload: ' . implode(', ', $errors), 'success_count' => $success], 500);
    else jsonResponse(['success' => true, 'message' => "$success file(s) uploaded"]);
}

function chmodFile(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['error' => 'POST required'], 405); return; }
    $path = resolvePath($_POST['path'] ?? '');
    $mode = intval($_POST['mode'] ?? '644', 8);
    if (!file_exists($path)) { jsonResponse(['error' => 'Not found'], 404); return; }
    if (!chmod($path, $mode)) { jsonResponse(['error' => 'chmod failed'], 500); return; }
    jsonResponse(['success' => true, 'message' => 'Permissions changed to ' . decoct($mode)]);
}

function compressFiles(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['error' => 'POST required'], 405); return; }
    $paths    = $_POST['paths'] ?? [];
    $destName = basename($_POST['name'] ?? 'archive.zip');
    $destDir  = resolvePath($_POST['dest'] ?? '/');
    $destFile = $destDir . '/' . $destName;
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($destFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            jsonResponse(['error' => 'Cannot create ZIP'], 500); return;
        }
        foreach ($paths as $p) {
            $full = resolvePath($p);
            if (is_file($full)) $zip->addFile($full, basename($full));
            elseif (is_dir($full)) addDirToZip($zip, $full, basename($full));
        }
        $zip->close();
        jsonResponse(['success' => true, 'path' => relPath($destFile)]);
    } else {
        // fallback to tar
        $sources = implode(' ', array_map(fn($p) => escapeshellarg(resolvePath($p)), $paths));
        exec("tar -czf " . escapeshellarg($destFile) . " $sources 2>&1", $out, $code);
        if ($code !== 0) jsonResponse(['error' => implode("\n", $out)], 500);
        else jsonResponse(['success' => true, 'path' => relPath($destFile)]);
    }
}

function addDirToZip(ZipArchive $zip, string $dir, string $localPrefix): void {
    $files = new RecursiveIteratorMode(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorMode::SELF_FIRST
    );
    foreach ($files as $file) {
        $localName = $localPrefix . '/' . $files->getSubPathname();
        if ($file->isDir()) $zip->addEmptyDir($localName);
        else $zip->addFile($file->getPathname(), $localName);
    }
}

function extractFile(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['error' => 'POST required'], 405); return; }
    $path = resolvePath($_POST['path'] ?? '');
    $dest = resolvePath($_POST['dest'] ?? dirname($_POST['path'] ?? '/'));
    $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === 'zip' && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path) === true) {
            $zip->extractTo($dest);
            $zip->close();
            jsonResponse(['success' => true]);
        } else jsonResponse(['error' => 'Cannot open ZIP'], 500);
    } else {
        exec("tar -xzf " . escapeshellarg($path) . " -C " . escapeshellarg($dest) . " 2>&1", $out, $code);
        if ($code !== 0) jsonResponse(['error' => implode("\n", $out)], 500);
        else jsonResponse(['success' => true]);
    }
}

function downloadFile(): void {
    $path = resolvePath($_GET['path'] ?? '');
    if (!is_file($path)) { http_response_code(404); echo 'File not found'; exit; }
    $name = basename($path);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-cache');
    readfile($path);
    exit;
}

function statFile(): void {
    $path = resolvePath($_GET['path'] ?? '');
    if (!file_exists($path)) { jsonResponse(['error' => 'Not found'], 404); return; }
    jsonResponse([
        'success'  => true,
        'name'     => basename($path),
        'path'     => relPath($path),
        'isDir'    => is_dir($path),
        'size'     => is_file($path) ? filesize($path) : 0,
        'size_fmt' => is_file($path) ? formatBytes(filesize($path)) : '—',
        'perms'    => substr(sprintf('%o', fileperms($path)), -4),
        'mtime'    => filemtime($path),
        'mtime_fmt'=> date('Y-m-d H:i:s', filemtime($path)),
        'readable' => is_readable($path),
        'writable' => is_writable($path),
    ]);
}

function copyFile_(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['error' => 'POST required'], 405); return; }
    $src  = resolvePath($_POST['src'] ?? '');
    $dest = resolvePath($_POST['dest'] ?? '') . '/' . basename($src);
    if (is_file($src)) {
        if (copy($src, $dest)) jsonResponse(['success' => true]);
        else jsonResponse(['error' => 'Copy failed'], 500);
    } else jsonResponse(['error' => 'Source must be a file'], 400);
}

function moveFile(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['error' => 'POST required'], 405); return; }
    $src  = resolvePath($_POST['src'] ?? '');
    $dest = resolvePath($_POST['dest'] ?? '') . '/' . basename($src);
    if (rename($src, $dest)) jsonResponse(['success' => true]);
    else jsonResponse(['error' => 'Move failed'], 500);
}

function searchFiles(): void {
    $dir     = resolvePath($_GET['path'] ?? '/');
    $query   = trim($_GET['q'] ?? '');
    if (empty($query)) { jsonResponse(['error' => 'Query required'], 400); return; }
    $results = [];
    $iter    = new RecursiveIteratorMode(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorMode::SELF_FIRST
    );
    foreach ($iter as $file) {
        if (stripos($file->getFilename(), $query) !== false) {
            $results[] = [
                'name'  => $file->getFilename(),
                'path'  => relPath($file->getPathname()),
                'isDir' => $file->isDir(),
                'size_fmt' => $file->isDir() ? '—' : formatBytes($file->getSize()),
                'icon'  => fileIcon($file->getFilename(), $file->isDir()),
            ];
            if (count($results) >= 100) break;
        }
    }
    jsonResponse(['success' => true, 'results' => $results, 'query' => $query]);
}
