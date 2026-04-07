<?php
require_once __DIR__ . '/../config/auth.php';
requireLogin();
header('Content-Type: application/json');
$action = $_GET['action'] ?? $_POST['action'] ?? '';
switch ($action) {
    case 'info':         phpInfo_(); break;
    case 'ini':          getIni(); break;
    case 'extensions':   getExtensions(); break;
    default: jsonResponse(['error' => 'Unknown action'], 400);
}
function phpInfo_(): void {
    $data = [
        'version'      => PHP_VERSION,
        'major'        => PHP_MAJOR_VERSION,
        'minor'        => PHP_MINOR_VERSION,
        'os'           => PHP_OS,
        'sapi'         => PHP_SAPI,
        'ini_path'     => php_ini_loaded_file() ?: 'N/A',
        'ini_scanned'  => php_ini_scanned_files() ?: 'N/A',
        'extensions'   => get_loaded_extensions(),
        'disabled_fns' => ini_get('disable_functions'),
        'memory_limit' => ini_get('memory_limit'),
        'max_execution'=> ini_get('max_execution_time'),
        'max_input'    => ini_get('max_input_vars'),
        'upload_max'   => ini_get('upload_max_filesize'),
        'post_max'     => ini_get('post_max_size'),
        'timezone'     => ini_get('date.timezone'),
        'error_log'    => ini_get('error_log'),
        'display_errors'=> ini_get('display_errors'),
        'opcache'      => extension_loaded('Zend OPcache'),
        'xdebug'       => extension_loaded('xdebug'),
    ];
    sort($data['extensions']);
    jsonResponse(['success'=>true,'data'=>$data]);
}
function getIni(): void {
    $ini = ini_get_all(null, false);
    $grouped = [];
    $phpIni = parse_ini_file(php_ini_loaded_file() ?: '', true, INI_SCANNER_RAW);
    $important = [
        'memory_limit','max_execution_time','upload_max_filesize','post_max_size',
        'display_errors','error_reporting','date.timezone','session.gc_maxlifetime',
        'max_input_vars','file_uploads','allow_url_fopen','allow_url_include',
        'disable_functions','short_open_tag','output_buffering',
    ];
    $result = [];
    foreach ($important as $key) {
        $result[] = ['key'=>$key, 'value'=>ini_get($key), 'important'=>true];
    }
    foreach ($ini as $key => $val) {
        if (!in_array($key, $important)) $result[] = ['key'=>$key, 'value'=>$val, 'important'=>false];
    }
    jsonResponse(['success'=>true,'ini'=>$result]);
}
function getExtensions(): void {
    $exts = get_loaded_extensions();
    sort($exts);
    $result = [];
    foreach ($exts as $ext) {
        $v = phpversion($ext);
        $result[] = ['name'=>$ext,'version'=>$v ?: 'built-in'];
    }
    jsonResponse(['success'=>true,'extensions'=>$result]);
}
