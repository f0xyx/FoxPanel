<?php
require_once __DIR__ . '/../config/auth.php';
requireLogin();
header('Content-Type: application/json');
$action = $_GET['action'] ?? $_POST['action'] ?? '';
switch ($action) {
    case 'change_password': changePassword(); break;
    case 'get_info':        getInfo(); break;
    default: jsonResponse(['error' => 'Unknown action'], 400);
}
function getInfo(): void {
    jsonResponse(['success'=>true,'data'=>[
        'username' => $_SESSION['username'] ?? 'admin',
        'last_login' => date('Y-m-d H:i:s', $_SESSION['last_activity'] ?? time()),
        'php_version' => PHP_VERSION,
        'panel_version' => PANEL_VERSION,
        'home_path' => HOME_PATH,
    ]]);
}
function changePassword(): void {
    if ($_SERVER['REQUEST_METHOD']!=='POST'){jsonResponse(['error'=>'POST required'],405);return;}
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    if (!password_verify($current, ADMIN_PASSWORD)) {
        jsonResponse(['error'=>'Current password is incorrect'],400); return;
    }
    if (strlen($new) < 8) { jsonResponse(['error'=>'Password must be at least 8 characters'],400); return; }
    if ($new !== $confirm) { jsonResponse(['error'=>'Passwords do not match'],400); return; }
    $hash = password_hash($new, PASSWORD_BCRYPT, ['cost'=>12]);
    // Update config file
    $configFile = __DIR__.'/../config/config.php';
    $config = file_get_contents($configFile);
    $config = preg_replace("/define\('ADMIN_PASSWORD',\s*'.+?'\);/", "define('ADMIN_PASSWORD', '$hash');", $config);
    if (file_put_contents($configFile, $config)) {
        jsonResponse(['success'=>true,'message'=>'Password changed successfully']);
    } else {
        jsonResponse(['error'=>'Could not write config file. Update manually.','hash'=>$hash],500);
    }
}
