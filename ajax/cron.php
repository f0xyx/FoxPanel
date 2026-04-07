<?php
require_once __DIR__ . '/../config/auth.php';
requireLogin();
header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'list':   listCrons(); break;
    case 'add':    addCron(); break;
    case 'delete': deleteCron(); break;
    case 'presets': getPresets(); break;
    default: jsonResponse(['error' => 'Unknown action'], 400);
}

function getCrontab(): string {
    $output = [];
    exec('crontab -l 2>/dev/null', $output);
    return implode("\n", $output);
}

function listCrons(): void {
    $raw  = getCrontab();
    $lines = explode("\n", $raw);
    $crons = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || $line[0] === '#') continue;
        if (preg_match('/^(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(.+)$/', $line, $m)) {
            $crons[] = [
                'raw'     => $line,
                'minute'  => $m[1],
                'hour'    => $m[2],
                'dom'     => $m[3],
                'month'   => $m[4],
                'dow'     => $m[5],
                'command' => $m[6],
                'schedule'=> cronToHuman($m[1], $m[2], $m[3], $m[4], $m[5]),
            ];
        }
    }
    jsonResponse(['success' => true, 'crons' => $crons, 'raw' => $raw]);
}

function addCron(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['error' => 'POST required'], 405); return; }
    $minute  = $_POST['minute']  ?? '*';
    $hour    = $_POST['hour']    ?? '*';
    $dom     = $_POST['dom']     ?? '*';
    $month   = $_POST['month']   ?? '*';
    $dow     = $_POST['dow']     ?? '*';
    $command = trim($_POST['command'] ?? '');
    if (empty($command)) { jsonResponse(['error' => 'Command required'], 400); return; }

    $entry   = "$minute $hour $dom $month $dow $command";
    $current = getCrontab();
    $new     = trim($current) . "\n" . $entry . "\n";

    $tmpFile = tempnam(sys_get_temp_dir(), 'crontab_');
    file_put_contents($tmpFile, $new);
    exec('crontab ' . escapeshellarg($tmpFile) . ' 2>&1', $out, $code);
    unlink($tmpFile);

    if ($code !== 0) jsonResponse(['error' => implode("\n", $out)], 500);
    else jsonResponse(['success' => true, 'message' => 'Cron job added']);
}

function deleteCron(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['error' => 'POST required'], 405); return; }
    $rawLine = trim($_POST['raw'] ?? '');
    $current = getCrontab();
    $lines   = explode("\n", $current);
    $new     = array_filter($lines, fn($l) => trim($l) !== $rawLine);
    $newStr  = implode("\n", $new) . "\n";

    $tmpFile = tempnam(sys_get_temp_dir(), 'crontab_');
    file_put_contents($tmpFile, $newStr);
    exec('crontab ' . escapeshellarg($tmpFile) . ' 2>&1', $out, $code);
    unlink($tmpFile);

    if ($code !== 0) jsonResponse(['error' => implode("\n", $out)], 500);
    else jsonResponse(['success' => true, 'message' => 'Cron job deleted']);
}

function getPresets(): void {
    jsonResponse(['success' => true, 'presets' => [
        ['label' => 'Every minute',        'value' => '* * * * *'],
        ['label' => 'Every 5 minutes',     'value' => '*/5 * * * *'],
        ['label' => 'Every 15 minutes',    'value' => '*/15 * * * *'],
        ['label' => 'Every 30 minutes',    'value' => '*/30 * * * *'],
        ['label' => 'Every hour',          'value' => '0 * * * *'],
        ['label' => 'Every 6 hours',       'value' => '0 */6 * * *'],
        ['label' => 'Every 12 hours',      'value' => '0 */12 * * *'],
        ['label' => 'Once a day (midnight)','value' => '0 0 * * *'],
        ['label' => 'Once a week (Sunday)', 'value' => '0 0 * * 0'],
        ['label' => 'Once a month (1st)',   'value' => '0 0 1 * *'],
    ]]);
}

function cronToHuman(string $min, string $hr, string $dom, string $mon, string $dow): string {
    if ($min === '*' && $hr === '*' && $dom === '*' && $mon === '*' && $dow === '*') return 'Every minute';
    if ($min === '0' && $hr === '*') return 'Every hour';
    if (preg_match('/^\*\/(\d+)$/', $min, $m)) return "Every {$m[1]} minutes";
    if ($min === '0' && $hr === '0' && $dom === '*') return 'Daily at midnight';
    if ($min === '0' && $hr === '0' && $dom === '*' && $dow === '0') return 'Weekly on Sunday';
    if ($min === '0' && $hr === '0' && $dom === '1') return 'Monthly on 1st';
    return "$min $hr $dom $mon $dow";
}
