<?php
require_once __DIR__ . '/../config/auth.php';
requireLogin();
header('Content-Type: application/json');
$action = $_GET['action'] ?? $_POST['action'] ?? '';
switch ($action) {
    case 'info':    sslInfo(); break;
    case 'certs':   listCerts(); break;
    case 'generate': generateCSR(); break;
    default: jsonResponse(['error' => 'Unknown action'], 400);
}
function sslInfo(): void {
    $host = $_GET['host'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $info = ['host'=>$host,'ssl'=>false,'valid_from'=>null,'valid_to'=>null,'issuer'=>null,'subject'=>null,'days_left'=>null];
    // Check if SSL is active
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $info['ssl'] = true;
    }
    // Try to get cert info for a given host
    if (function_exists('stream_context_create')) {
        $ctx = stream_context_create(['ssl'=>['verify_peer'=>false,'capture_peer_cert'=>true]]);
        $fp  = @stream_socket_client("ssl://{$host}:443", $e, $em, 5, STREAM_CLIENT_CONNECT, $ctx);
        if ($fp) {
            $params = stream_context_get_params($fp);
            $cert   = openssl_x509_parse($params['options']['ssl']['peer_certificate'] ?? null);
            if ($cert) {
                $info['ssl']        = true;
                $info['subject']    = $cert['subject']['CN'] ?? '';
                $info['issuer']     = $cert['issuer']['O'] ?? '';
                $info['valid_from'] = date('Y-m-d', $cert['validFrom_time_t']);
                $info['valid_to']   = date('Y-m-d', $cert['validTo_time_t']);
                $info['days_left']  = (int)(($cert['validTo_time_t'] - time()) / 86400);
            }
            fclose($fp);
        }
    }
    jsonResponse(['success'=>true,'data'=>$info]);
}
function listCerts(): void {
    $certs = [];
    $commonPaths = ['/etc/ssl/certs', '/etc/letsencrypt/live', HOME_PATH.'/ssl', HOME_PATH.'/certs'];
    foreach ($commonPaths as $dir) {
        if (!is_dir($dir)) continue;
        foreach (glob($dir.'/*.{pem,crt,cer}', GLOB_BRACE) as $f) {
            $certs[] = ['name'=>basename($f),'path'=>$f,'size_fmt'=>formatBytes(filesize($f)),'mtime_fmt'=>date('Y-m-d',filemtime($f))];
        }
    }
    jsonResponse(['success'=>true,'certs'=>$certs]);
}
function generateCSR(): void {
    if ($_SERVER['REQUEST_METHOD']!=='POST'){jsonResponse(['error'=>'POST required'],405);return;}
    if (!extension_loaded('openssl')){jsonResponse(['error'=>'OpenSSL extension not loaded'],503);return;}
    $cn      = trim($_POST['cn']??'');
    $org     = trim($_POST['org']??'');
    $country = trim($_POST['country']??'US');
    $email   = trim($_POST['email']??'');
    if (empty($cn)){jsonResponse(['error'=>'Common Name required'],400);return;}
    $dn = ['commonName'=>$cn,'organizationName'=>$org?:'N/A','countryName'=>$country,'emailAddress'=>$email?:'admin@'.$cn];
    $key = openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new($dn, $key);
    openssl_csr_export($csr, $csrOut);
    openssl_pkey_export($key, $keyOut);
    jsonResponse(['success'=>true,'csr'=>$csrOut,'private_key'=>$keyOut,'domain'=>$cn]);
}
