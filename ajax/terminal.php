<?php
require_once __DIR__ . '/../config/auth.php';
requireLogin();
header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'exec':    execCommand(); break;
    case 'env':     getEnv_(); break;
    default: jsonResponse(['error' => 'Unknown action'], 400);
}

function execCommand(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['error' => 'POST required'], 405); return;
    }

    $cmd = trim($_POST['cmd'] ?? '');
    $cwd = trim($_POST['cwd'] ?? HOME_PATH);

    if (empty($cmd)) {
        jsonResponse(['error' => 'No command'], 400); return;
    }

    // Validate / sanitize cwd — fallback to HOME if invalid
    if (!is_dir($cwd)) $cwd = HOME_PATH;

    // Handle cd specially — track working directory in response so JS can update the prompt
    if (preg_match('/^\s*cd\s*(.*)/i', $cmd, $m)) {
        $target = trim($m[1] ?? '');
        if ($target === '' || $target === '~') {
            $newCwd = HOME_PATH;
        } elseif ($target === '-') {
            $newCwd = $_POST['prev_cwd'] ?? $cwd;
        } elseif ($target[0] === '/') {
            $newCwd = $target;
        } else {
            $newCwd = rtrim($cwd, '/') . '/' . $target;
        }
        $newCwd = realpath($newCwd) ?: $newCwd;
        if (!is_dir($newCwd)) {
            jsonResponse(['output' => "bash: cd: $target: No such file or directory", 'code' => 1, 'cwd' => $cwd]);
        } else {
            jsonResponse(['output' => '', 'code' => 0, 'cwd' => $newCwd]);
        }
        return;
    }

    // Check execution availability
    $disabled = array_map('trim', explode(',', ini_get('disable_functions') ?: ''));
    $hasExec       = function_exists('exec')       && !in_array('exec',       $disabled);
    $hasShellExec  = function_exists('shell_exec') && !in_array('shell_exec', $disabled);
    $hasProc       = function_exists('proc_open')  && !in_array('proc_open',  $disabled);
    $hasPopen      = function_exists('popen')      && !in_array('popen',      $disabled);

    if (!$hasExec && !$hasShellExec && !$hasProc && !$hasPopen) {
        jsonResponse([
            'output' => '⚠ Shell execution is disabled on this server (exec/shell_exec/popen/proc_open are all disabled in php.ini).',
            'code'   => 1,
            'cwd'    => $cwd,
        ]); return;
    }

    // Build full command — cd into cwd first, then run, merge stderr into stdout
    $safeCwd = escapeshellarg($cwd);
    $fullCmd = "cd {$safeCwd} && {$cmd} 2>&1";

    $outStr = '';
    $code   = 0;

    // Use proc_open for best results (captures both stdout+stderr, returns exit code)
    if ($hasProc) {
        $descriptors = [
            0 => ['pipe', 'r'],   // stdin
            1 => ['pipe', 'w'],   // stdout
            2 => ['pipe', 'w'],   // stderr
        ];
        $env  = null; // inherit environment
        $proc = proc_open($fullCmd, $descriptors, $pipes, $cwd);
        if (is_resource($proc)) {
            fclose($pipes[0]);
            $outStr  = stream_get_contents($pipes[1]);
            $outStr .= stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);
        } else {
            $code   = 1;
            $outStr = 'Failed to start process.';
        }
    } elseif ($hasExec) {
        $output = [];
        exec($fullCmd, $output, $code);
        $outStr = implode("\n", $output);
    } elseif ($hasShellExec) {
        $outStr = shell_exec($fullCmd) ?? '';
        $code   = 0;
    } elseif ($hasPopen) {
        $handle = popen($fullCmd, 'r');
        $outStr = $handle ? fread($handle, 1024 * 1024) : '';
        pclose($handle);
    }

    jsonResponse([
        'output' => $outStr,
        'code'   => $code,
        'cwd'    => $cwd,
    ]);
}

function getEnv_(): void {
    // Try to get actual username
    $user = 'user';
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $pw   = posix_getpwuid(posix_geteuid());
        $user = $pw['name'] ?? get_current_user();
    } else {
        $user = get_current_user() ?: ($_SERVER['USER'] ?? $_SERVER['USERNAME'] ?? 'user');
    }

    $env = [
        'user'     => $user,
        'home'     => HOME_PATH,
        'hostname' => php_uname('n'),
        'shell'    => $_SERVER['SHELL'] ?? '/bin/bash',
        'path'     => $_SERVER['PATH'] ?? '/usr/local/bin:/usr/bin:/bin',
        'pwd'      => HOME_PATH,
    ];
    jsonResponse(['success' => true, 'env' => $env]);
}
