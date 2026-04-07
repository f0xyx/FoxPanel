<?php
require_once __DIR__ . '/../config/auth.php';
requireLogin();
header('Content-Type: application/json');
$action = $_GET['action'] ?? $_POST['action'] ?? '';
switch ($action) {
    case 'list':   listDomains(); break;
    case 'add':    addDomain(); break;
    case 'delete': deleteDomain(); break;
    case 'vhost':  generateVhost(); break;
    default: jsonResponse(['error' => 'Unknown action'], 400);
}

function getDomainsFile(): string { return HOME_PATH . '/.webpanel_domains'; }
function loadDomains(): array {
    $file = getDomainsFile();
    if (!file_exists($file)) return [];
    return json_decode(file_get_contents($file), true) ?? [];
}
function saveDomains(array $domains): void {
    file_put_contents(getDomainsFile(), json_encode($domains, JSON_PRETTY_PRINT));
}

function listDomains(): void {
    $domains = loadDomains();
    // Add current server name
    $serverName = $_SERVER['SERVER_NAME'] ?? php_uname('n');
    $main = ['domain'=>$serverName,'type'=>'main','docroot'=>$_SERVER['DOCUMENT_ROOT']??HOME_PATH.'/public_html','ssl'=>false];
    jsonResponse(['success'=>true,'domains'=>array_merge([$main], $domains)]);
}

function addDomain(): void {
    if ($_SERVER['REQUEST_METHOD']!=='POST'){jsonResponse(['error'=>'POST required'],405);return;}
    $domain  = strtolower(trim($_POST['domain']??''));
    $type    = $_POST['type'] ?? 'addon'; // addon, subdomain, parked
    $docroot = trim($_POST['docroot']??'');
    if (empty($domain)) {jsonResponse(['error'=>'Domain required'],400);return;}
    if (!preg_match('/^[a-z0-9][a-z0-9\-\.]+\.[a-z]{2,}$/', $domain)) {
        jsonResponse(['error'=>'Invalid domain name'],400);return;
    }
    $domains = loadDomains();
    foreach ($domains as $d) { if ($d['domain']===$domain){jsonResponse(['error'=>'Already exists'],409);return;} }
    if (empty($docroot)) $docroot = HOME_PATH.'/public_html/'.$domain;
    if (!is_dir($docroot)) @mkdir($docroot, 0755, true);
    $domains[] = ['domain'=>$domain,'type'=>$type,'docroot'=>$docroot,'ssl'=>false,'created'=>date('Y-m-d H:i:s')];
    saveDomains($domains);
    updateHtaccessRouting();
    jsonResponse(['success'=>true,'message'=>"Domain '$domain' added and routing activated"]);
}

function deleteDomain(): void {
    if ($_SERVER['REQUEST_METHOD']!=='POST'){jsonResponse(['error'=>'POST required'],405);return;}
    $domain = trim($_POST['domain']??'');
    $domains = array_filter(loadDomains(), fn($d)=>$d['domain']!==$domain);
    saveDomains(array_values($domains));
    updateHtaccessRouting();
    jsonResponse(['success'=>true,'message'=>"Domain '$domain' removed and routing updated"]);
}

function generateVhost(): void {
    $domain  = trim($_GET['domain']??'');
    $docroot = trim($_GET['docroot']??HOME_PATH.'/public_html/'.$domain);
    $server  = trim($_GET['server']??'apache'); // apache or nginx
    if (empty($domain)){jsonResponse(['error'=>'Domain required'],400);return;}
    if ($server==='nginx') {
        $conf = "server {\n    listen 80;\n    listen [::]:80;\n    server_name $domain www.$domain;\n    root $docroot;\n    index index.php index.html index.htm;\n\n    access_log /var/log/nginx/{$domain}_access.log;\n    error_log  /var/log/nginx/{$domain}_error.log;\n\n    location / {\n        try_files \$uri \$uri/ /index.php?\$query_string;\n    }\n\n    location ~ \\.php$ {\n        include snippets/fastcgi-php.conf;\n        fastcgi_pass unix:/run/php/php8.1-fpm.sock;\n    }\n\n    location ~ /\\.ht { deny all; }\n}\n";
    } else {
        $conf = "<VirtualHost *:80>\n    ServerName $domain\n    ServerAlias www.$domain\n    DocumentRoot $docroot\n    ErrorLog \${APACHE_LOG_DIR}/{$domain}_error.log\n    CustomLog \${APACHE_LOG_DIR}/{$domain}_access.log combined\n    <Directory $docroot>\n        AllowOverride All\n        Require all granted\n    </Directory>\n</VirtualHost>\n";
    }
    jsonResponse(['success'=>true,'config'=>$conf,'server'=>$server,'domain'=>$domain]);
}

function updateHtaccessRouting(): void {
    $htaccessPath = WEBROOT_PATH . '/.htaccess';
    
    if (!is_dir(WEBROOT_PATH)) @mkdir(WEBROOT_PATH, 0755, true);

    $domains = loadDomains();
    $rules = "\n# BEGIN WebPanel Pro Routing\n";
    $rules .= "<IfModule mod_rewrite.c>\n";
    $rules .= "RewriteEngine On\n";

    foreach ($domains as $d) {
        if ($d['type'] === 'main') continue; // Main domain naturally routes to public_html
        
        $host = preg_quote($d['domain']);
        // Relative path from WEBROOT_PATH (public_html)
        $relPath = str_replace(realpath(WEBROOT_PATH), '', realpath($d['docroot']) ?: $d['docroot']);
        $relPath = trim($relPath, '/');
        
        // If it's outside public_html, we can't reliably .htaccess route it without absolute path, 
        // but absolute path rewriting in .htaccess depends on server config.
        // We'll use the relative path mapped from public_html.
        if (empty($relPath)) continue; 

        $rules .= "\n    # Routing for {$d['domain']}\n";
        $rules .= "    RewriteCond %{HTTP_HOST} ^(www\.)?$host$ [NC]\n";
        $rules .= "    RewriteCond %{REQUEST_URI} !^/$relPath/ [NC]\n";
        $rules .= "    RewriteRule ^(.*)$ /$relPath/$1 [L]\n";
    }

    $rules .= "</IfModule>\n";
    $rules .= "# END WebPanel Pro Routing\n";

    $currentContent = file_exists($htaccessPath) ? file_get_contents($htaccessPath) : '';
    
    // Replace existing block or append new one
    $pattern = '/# BEGIN WebPanel Pro Routing.*?# END WebPanel Pro Routing\n?/s';
    
    if (preg_match($pattern, $currentContent)) {
        if (empty($domains)) {
            $newContent = preg_replace($pattern, '', $currentContent);
        } else {
            $newContent = preg_replace($pattern, $rules, $currentContent);
        }
    } else {
        if (!empty($domains)) {
            $newContent = $currentContent . $rules;
        } else {
            $newContent = $currentContent;
        }
    }

    @file_put_contents($htaccessPath, ltrim($newContent));
}
