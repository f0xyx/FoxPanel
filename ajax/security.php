<?php
require_once __DIR__ . '/../config/auth.php';
requireLogin();
header('Content-Type: application/json');
$action = $_GET['action'] ?? $_POST['action'] ?? '';
switch ($action) {
    case 'ip_block':     ipBlocker(); break;
    case 'hotlink':      hotlinkProtection(); break;
    case 'leech':        leechProtection(); break;
    case 'scan':         securityScan(); break;
    default: jsonResponse(['error' => 'Unknown action'], 400);
}
function getHtaccessPath(): string {
    return ($_SERVER['DOCUMENT_ROOT'] ?: HOME_PATH.'/public_html') . '/.htaccess';
}
function ipBlocker(): void {
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $ips = array_filter(array_map('trim', explode("\n", $_POST['ips']??'')));
        $lines = ["# WebPanel IP Blocker"];
        foreach ($ips as $ip) {
            if (filter_var($ip,FILTER_VALIDATE_IP)) $lines[] = "Deny from $ip";
        }
        $htFile = getHtaccessPath();
        $existing = file_exists($htFile) ? file_get_contents($htFile) : '';
        $block = implode("\n",$lines);
        // Remove old block
        $existing = preg_replace('/# WebPanel IP Blocker.*?(?=\n\n|\z)/s', '', $existing);
        $new = trim($existing)."\n\n".$block."\n";
        file_put_contents($htFile, $new);
        jsonResponse(['success'=>true,'message'=>count($ips).' IP(s) blocked','config'=>$block]);
    } else {
        $htFile = getHtaccessPath();
        $content = file_exists($htFile) ? file_get_contents($htFile) : '';
        preg_match_all('/^Deny from (.+)$/m', $content, $m);
        jsonResponse(['success'=>true,'blocked_ips'=>$m[1]??[]]);
    }
}
function hotlinkProtection(): void {
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $domain = trim($_POST['domain'] ?? ($_SERVER['SERVER_NAME']??'example.com'));
        $exts   = trim($_POST['exts'] ?? 'jpg|jpeg|png|gif|bmp|svg|mp3|mp4');
        $config = "# WebPanel Hotlink Protection\nRewriteEngine On\nRewriteCond %{HTTP_REFERER} !^$\nRewriteCond %{HTTP_REFERER} !^https?://(www\\.)?".preg_quote($domain,'/')."/ [NC]\nRewriteRule \\.(${exts})$ - [NC,F,L]\n";
        $htFile = getHtaccessPath();
        $existing = file_exists($htFile) ? file_get_contents($htFile) : '';
        $existing = preg_replace('/# WebPanel Hotlink Protection.*?(?=\n\n|\z)/s', '', $existing);
        file_put_contents($htFile, trim($existing)."\n\n".$config);
        jsonResponse(['success'=>true,'message'=>'Hotlink protection enabled','config'=>$config]);
    } else {
        jsonResponse(['success'=>true,'message'=>'Send POST to enable']);
    }
}
function leechProtection(): void {
    jsonResponse(['success'=>true,'message'=>'Leech protection via .htpasswd — configure your web server']);
}
function securityScan(): void {
    $docroot = $_SERVER['DOCUMENT_ROOT'] ?: HOME_PATH.'/public_html';
    $issues  = [];
    // Check world-writable files
    if (function_exists('shell_exec')) {
        $ww = shell_exec("find ".escapeshellarg($docroot)." -perm -o+w -type f 2>/dev/null | head -20");
        if (!empty(trim($ww??''))) {
            $issues[] = ['level'=>'warning','message'=>'World-writable files found','details'=>trim($ww)];
        }
        // Check for common shells
        $shells = shell_exec("find ".escapeshellarg($docroot)." -name '*.php' -exec grep -l 'eval(base64_decode' {} \\; 2>/dev/null | head -10");
        if (!empty(trim($shells??''))) {
            $issues[] = ['level'=>'danger','message'=>'Possible webshell detected','details'=>trim($shells)];
        }
    }
    if (empty($issues)) $issues[] = ['level'=>'success','message'=>'No issues found'];
    jsonResponse(['success'=>true,'issues'=>$issues]);
}
