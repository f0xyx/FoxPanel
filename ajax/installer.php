<?php
require_once __DIR__ . '/../config/auth.php';
requireLogin();
header('Content-Type: application/json');
$action = $_GET['action'] ?? $_POST['action'] ?? '';
switch ($action) {
    case 'list':      listApps(); break;
    case 'install':   installApp(); break;
    case 'progress':  getProgress(); break;
    default: jsonResponse(['error' => 'Unknown action'], 400);
}
function listApps(): void {
    $apps = [
        ['id'=>'wordpress','name'=>'WordPress','version'=>'6.5','desc'=>'Most popular CMS in the world','icon'=>'wordpress','color'=>'#21759b','category'=>'CMS','size'=>'~60MB'],
        ['id'=>'laravel','name'=>'Laravel','version'=>'11.x','desc'=>'The PHP framework for web artisans','icon'=>'laravel','color'=>'#FF2D20','category'=>'Framework','size'=>'~50MB'],
        ['id'=>'codeigniter','name'=>'CodeIgniter','version'=>'4.x','desc'=>'Slim PHP framework','icon'=>'code','color'=>'#dd4814','category'=>'Framework','size'=>'~5MB'],
        ['id'=>'joomla','name'=>'Joomla!','version'=>'5.x','desc'=>'Award-winning CMS','icon'=>'joomla','color'=>'#F44321','category'=>'CMS','size'=>'~30MB'],
        ['id'=>'drupal','name'=>'Drupal','version'=>'10.x','desc'=>'Enterprise CMS','icon'=>'drupal','color'=>'#0678BE','category'=>'CMS','size'=>'~25MB'],
        ['id'=>'nextcloud','name'=>'Nextcloud','version'=>'28.x','desc'=>'Self-hosted cloud storage','icon'=>'cloud','color'=>'#0082C9','category'=>'Cloud','size'=>'~80MB'],
    ];
    jsonResponse(['success'=>true,'apps'=>$apps]);
}
function installApp(): void {
    if ($_SERVER['REQUEST_METHOD']!=='POST'){jsonResponse(['error'=>'POST required'],405);return;}
    $id       = $_POST['app'] ?? '';
    $path     = trim($_POST['path'] ?? '');
    $domain   = trim($_POST['domain'] ?? '');
    if (empty($id)) {jsonResponse(['error'=>'App required'],400);return;}
    $installDir = HOME_PATH.'/public_html/'.($path ?: $id);
    if (!is_dir($installDir)) @mkdir($installDir, 0755, true);

    // Track progress via temp file
    $progressFile = sys_get_temp_dir().'/webpanel_install_'.session_id().'.json';
    $steps = [];

    switch ($id) {
        case 'wordpress':
            $url = 'https://wordpress.org/latest.zip';
            $steps = installFromZip($url, $installDir, $progressFile, 'wordpress');
            break;
        case 'laravel':
            if (function_exists('shell_exec') && !empty(shell_exec('which composer'))) {
                $out = shell_exec('cd '.escapeshellarg($installDir).' && composer create-project laravel/laravel . 2>&1 | tail -20');
                $steps[] = $out ?? 'Done';
            } else {
                $url = 'https://github.com/laravel/laravel/archive/refs/heads/master.zip';
                $steps = installFromZip($url, $installDir, $progressFile, 'laravel-master');
            }
            break;
        case 'codeigniter':
            $url = 'https://github.com/bcit-ci/CodeIgniter/archive/refs/heads/develop.zip';
            $steps = installFromZip($url, $installDir, $progressFile, 'CodeIgniter-develop');
            break;
        default:
            jsonResponse(['error'=>'App not supported for auto-install'],400);return;
    }
    jsonResponse(['success'=>true,'message'=>ucfirst($id).' installed to '.$installDir,'steps'=>$steps,'dir'=>$installDir]);
}
function installFromZip(string $url, string $destDir, string $progressFile, string $innerFolder): array {
    $steps = [];
    $tmpFile = sys_get_temp_dir().'/webpanel_dl_'.uniqid().'.zip';
    $hasCurl = !empty(shell_exec('which curl 2>/dev/null'));
    if ($hasCurl) {
        shell_exec("curl -fsSL ".escapeshellarg($url)." -o ".escapeshellarg($tmpFile)." 2>&1");
    } else {
        shell_exec("wget -q ".escapeshellarg($url)." -O ".escapeshellarg($tmpFile)." 2>&1");
    }
    $steps[] = 'Downloaded package';
    if (!file_exists($tmpFile)||filesize($tmpFile)<1000){$steps[]='Download failed';return $steps;}
    $tmpDir = sys_get_temp_dir().'/webpanel_extract_'.uniqid();
    @mkdir($tmpDir);
    if (class_exists('ZipArchive')) {
        $z=new ZipArchive(); $z->open($tmpFile); $z->extractTo($tmpDir); $z->close();
    } else {
        shell_exec("unzip -q ".escapeshellarg($tmpFile)." -d ".escapeshellarg($tmpDir));
    }
    $steps[] = 'Extracted archive';
    $inner = $tmpDir.'/'.$innerFolder;
    if (is_dir($inner)) {
        shell_exec("cp -r ".escapeshellarg($inner)."/. ".escapeshellarg($destDir)."/");
    } else {
        shell_exec("cp -r ".escapeshellarg($tmpDir)."/. ".escapeshellarg($destDir)."/");
    }
    shell_exec("rm -rf ".escapeshellarg($tmpDir)." ".escapeshellarg($tmpFile));
    $steps[] = 'Files moved to destination';
    return $steps;
}
function getProgress(): void {
    $file = sys_get_temp_dir().'/webpanel_install_'.session_id().'.json';
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        jsonResponse(['success'=>true,'progress'=>$data]);
    } else {
        jsonResponse(['success'=>true,'progress'=>null]);
    }
}
