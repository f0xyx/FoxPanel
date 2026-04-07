<?php
require_once __DIR__ . '/../config/auth.php';
requireLogin();
header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'info': systemInfo(); break;
    case 'disk': diskInfo(); break;
    default: jsonResponse(['error' => 'Unknown action'], 400);
}

function systemInfo(): void {
    $data = [];

    // PHP version
    $data['php_version'] = PHP_VERSION;
    $data['php_os'] = PHP_OS;
    $data['sapi'] = php_sapi_name();
    $data['server_software'] = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
    $data['server_name'] = $_SERVER['SERVER_NAME'] ?? php_uname('n');

    // OS info
    $data['os'] = php_uname();
    $data['hostname'] = php_uname('n');

    // Uptime — Linux /proc/uptime or macOS uptime command
    if (is_readable('/proc/uptime')) {
        $uptime = (float) explode(' ', file_get_contents('/proc/uptime'))[0];
        $data['uptime'] = formatUptime((int) $uptime);
        $data['uptime_seconds'] = (int) $uptime;
    } elseif (function_exists('shell_exec')) {
        $raw = shell_exec('uptime 2>/dev/null');
        // Parse macOS/Linux uptime output
        if ($raw && preg_match('/up\s+(.*?),\s+\d+\s+user/i', $raw, $um)) {
            $data['uptime'] = trim($um[1]);
        } else {
            $data['uptime'] = trim($raw ?? 'N/A');
        }
        $data['uptime_seconds'] = 0;
    } else {
        $data['uptime'] = 'N/A';
        $data['uptime_seconds'] = 0;
    }

    // CPU cores — Linux: /proc/cpuinfo or nproc, macOS: sysctl
    $cores = 1;
    if (is_readable('/proc/cpuinfo')) {
        preg_match_all('/^processor/m', file_get_contents('/proc/cpuinfo'), $cpuM);
        $cores = count($cpuM[0]) ?: 1;
    } elseif (function_exists('shell_exec')) {
        $c = shell_exec('sysctl -n hw.ncpu 2>/dev/null') ?: shell_exec('nproc 2>/dev/null');
        $cores = (int) trim($c ?? '1') ?: 1;
    }
    $data['cpu_cores'] = $cores;

    // CPU model — Linux: /proc/cpuinfo, macOS: sysctl
    $data['cpu_model'] = 'Unknown';
    if (is_readable('/proc/cpuinfo')) {
        preg_match('/model name\s*:\s*(.+)/i', file_get_contents('/proc/cpuinfo'), $m);
        $data['cpu_model'] = trim($m[1] ?? 'Unknown');
    } elseif (function_exists('shell_exec')) {
        $model = shell_exec('sysctl -n machdep.cpu.brand_string 2>/dev/null')
              ?: shell_exec('lscpu 2>/dev/null | grep "Model name" | cut -d: -f2');
        if ($model) $data['cpu_model'] = trim($model);
    }

    // CPU usage
    $data['cpu_usage'] = getCpuUsage();

    // Memory — Linux: /proc/meminfo, macOS: vm_stat + sysctl
    $data['memory'] = getMemoryInfo();

    // Disk
    $data['disk'] = getDiskInfo();

    // Load average
    if (function_exists('sys_getloadavg')) {
        $load = sys_getloadavg();
        $data['load_avg'] = array_map(fn($v) => round($v, 2), $load);
    } else {
        $data['load_avg'] = [0, 0, 0];
    }

    // Network info
    $data['ip_address'] = $_SERVER['SERVER_ADDR'] ?? gethostbyname(php_uname('n'));

    // PHP extensions
    $data['php_extensions'] = get_loaded_extensions();
    sort($data['php_extensions']);

    // Functions availability
    $disabled = array_map('trim', explode(',', ini_get('disable_functions')));
    $data['exec_available'] = function_exists('exec') && !in_array('exec', $disabled);
    $data['shell_exec_available'] = function_exists('shell_exec') && !in_array('shell_exec', $disabled);

    // PHP limits
    $data['max_upload'] = ini_get('upload_max_filesize');
    $data['max_post'] = ini_get('post_max_size');
    $data['max_execution'] = ini_get('max_execution_time');
    $data['memory_limit'] = ini_get('memory_limit');

    // Document root
    $data['document_root'] = $_SERVER['DOCUMENT_ROOT'] ?? HOME_PATH;

    jsonResponse(['success' => true, 'data' => $data]);
}

function getCpuUsage(): float {
    // Linux: /proc/stat (most accurate)
    if (is_readable('/proc/stat')) {
        $stat1 = parseCpuStat();
        usleep(200000);
        $stat2 = parseCpuStat();
        $idle1 = $stat1[3] + $stat1[4];
        $idle2 = $stat2[3] + $stat2[4];
        $totalDiff = array_sum($stat2) - array_sum($stat1);
        $idleDiff  = $idle2 - $idle1;
        if ($totalDiff === 0) return 0.0;
        return round((($totalDiff - $idleDiff) / $totalDiff) * 100, 1);
    }
    if (function_exists('shell_exec')) {
        // macOS: top -l 1 (no loop)
        if (PHP_OS === 'Darwin') {
            $raw = shell_exec("top -l 1 -s 0 2>/dev/null | grep 'CPU usage'");
            if ($raw && preg_match('/(\d+\.?\d*)\%\s+user.*?(\d+\.?\d*)\%\s+sys/i', $raw, $m)) {
                return round((float)$m[1] + (float)$m[2], 1);
            }
        }
        // Linux fallback
        $cpu = shell_exec("top -bn1 2>/dev/null | grep 'Cpu(s)' | awk '{print $2 + $4}'");
        if ($cpu) return round((float) trim($cpu), 1);
    }
    return 0.0;
}


function parseCpuStat(): array {
    $line = fgets(fopen('/proc/stat', 'r'));
    preg_match('/cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $line, $m);
    return [(int)$m[1], (int)$m[2], (int)$m[3], (int)$m[4], (int)$m[5]];
}

function getMemoryInfo(): array {
    $result = ['total' => 0, 'used' => 0, 'free' => 0, 'percent' => 0, 'total_fmt' => 'N/A', 'used_fmt' => 'N/A', 'free_fmt' => 'N/A'];

    // Linux: /proc/meminfo
    if (is_readable('/proc/meminfo')) {
        $info = file_get_contents('/proc/meminfo');
        preg_match('/MemTotal:\s+(\d+)/', $info, $total);
        preg_match('/MemAvailable:\s+(\d+)/', $info, $available);
        preg_match('/MemFree:\s+(\d+)/', $info, $free);
        $totalKb = (int)($total[1] ?? 0);
        $freeKb  = (int)($available[1] ?? $free[1] ?? 0);
        $usedKb  = $totalKb - $freeKb;
        if ($totalKb > 0) {
            return [
                'total'      => $totalKb * 1024,
                'used'       => $usedKb * 1024,
                'free'       => $freeKb * 1024,
                'percent'    => round(($usedKb / $totalKb) * 100, 1),
                'total_fmt'  => formatBytes($totalKb * 1024),
                'used_fmt'   => formatBytes($usedKb * 1024),
                'free_fmt'   => formatBytes($freeKb * 1024),
            ];
        }
    }

    // macOS: sysctl hw.memsize + vm_stat
    if (function_exists('shell_exec') && PHP_OS === 'Darwin') {
        $totalBytes = (int) trim(shell_exec('sysctl -n hw.memsize 2>/dev/null') ?? '0');
        $vmStat = shell_exec('vm_stat 2>/dev/null');
        $pageSize = 4096;
        preg_match('/page size of (\d+)/', $vmStat ?? '', $ps);
        if (!empty($ps[1])) $pageSize = (int)$ps[1];
        preg_match('/Pages free:\s+(\d+)/', $vmStat ?? '', $pf);
        preg_match('/Pages inactive:\s+(\d+)/', $vmStat ?? '', $pi);
        preg_match('/Pages speculative:\s+(\d+)/', $vmStat ?? '', $psp);
        $freePages = ((int)($pf[1]??0) + (int)($pi[1]??0) + (int)($psp[1]??0));
        $freeBytes = $freePages * $pageSize;
        $usedBytes = $totalBytes - $freeBytes;
        if ($totalBytes > 0) {
            return [
                'total'      => $totalBytes,
                'used'       => $usedBytes,
                'free'       => $freeBytes,
                'percent'    => round(($usedBytes / $totalBytes) * 100, 1),
                'total_fmt'  => formatBytes($totalBytes),
                'used_fmt'   => formatBytes($usedBytes),
                'free_fmt'   => formatBytes($freeBytes),
            ];
        }
    }

    return $result;
}


function getDiskInfo(): array {
    $path = HOME_PATH;
    $total = disk_total_space($path) ?: 0;
    $free  = disk_free_space($path) ?: 0;
    $used  = $total - $free;
    return [
        'total'      => $total,
        'used'       => $used,
        'free'       => $free,
        'percent'    => $total > 0 ? round(($used / $total) * 100, 1) : 0,
        'total_fmt'  => formatBytes((int)$total),
        'used_fmt'   => formatBytes((int)$used),
        'free_fmt'   => formatBytes((int)$free),
    ];
}

function diskInfo(): void {
    jsonResponse(['success' => true, 'data' => getDiskInfo()]);
}

function formatUptime(int $seconds): string {
    $days    = floor($seconds / 86400);
    $hours   = floor(($seconds % 86400) / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $parts   = [];
    if ($days > 0) $parts[] = "{$days}d";
    if ($hours > 0) $parts[] = "{$hours}h";
    $parts[] = "{$minutes}m";
    return implode(' ', $parts);
}
