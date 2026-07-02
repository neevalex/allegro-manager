<?php
declare(strict_types=1);

require_once __DIR__ . '/app/AllegroClient.php';

$config = AllegroConfig::load();
$client = new AllegroClient($config);
$statePath = __DIR__ . '/data/dashboard-refresh-state.json';
$lockPath = __DIR__ . '/data/dashboard-refresh.lock';
$lockHandle = fopen($lockPath, 'c+');
if ($lockHandle === false) {
    fwrite(STDERR, "Could not open {$lockPath}\n");
    exit(1);
}
$locked = flock($lockHandle, LOCK_EX | LOCK_NB);
if (!$locked) {
    $existing = allegro_read_dashboard_refresh_state($statePath) ?? [];
    $existingPid = (int)($existing['pid'] ?? 0);
    // Check if the recorded PID is still running (stale-process cleanup)
    if ($existingPid > 0 && function_exists('posix_kill')) {
        if (posix_kill($existingPid, 0) === false) {
            // PID is dead, clean up the stale lock
            fclose($lockHandle);
            @unlink($lockPath);
            $lockHandle = fopen($lockPath, 'c+');
            if ($lockHandle !== false && flock($lockHandle, LOCK_EX | LOCK_NB)) {
                $locked = true;
            }
        }
    }
}
if (!$locked) {
    $existing = allegro_read_dashboard_refresh_state($statePath) ?? [];
    echo json_encode([
        'target' => __DIR__ . '/data/dashboard-summary.json',
        'ok' => false,
        'generated_at' => gmdate(DateTimeInterface::ATOM),
        'error' => 'Refresh already running.',
        'state' => $existing,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(0);
}

allegro_write_dashboard_refresh_state([
    'status' => 'running',
    'started_at' => gmdate(DateTimeInterface::ATOM),
    'pid' => getmypid(),
    'source' => PHP_SAPI,
], $statePath);

try {
    $result = allegro_refresh_dashboard_cache($client, $config, __DIR__ . '/data/dashboard-summary.json');
    allegro_write_dashboard_refresh_state([
        'status' => $result['ok'] ? 'completed' : 'failed',
        'started_at' => allegro_read_dashboard_refresh_state($statePath)['started_at'] ?? null,
        'finished_at' => gmdate(DateTimeInterface::ATOM),
        'generated_at' => $result['generated_at'] ?? null,
        'pid' => getmypid(),
        'source' => PHP_SAPI,
        'error' => $result['error'] ?? null,
    ], $statePath);
} catch (Throwable $e) {
    allegro_write_dashboard_refresh_state([
        'status' => 'failed',
        'finished_at' => gmdate(DateTimeInterface::ATOM),
        'pid' => getmypid(),
        'source' => PHP_SAPI,
        'error' => $e->getMessage(),
    ], $statePath);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

echo json_encode([
    'target' => $result['target'],
    'ok' => $result['ok'],
    'generated_at' => $result['generated_at'],
    'error' => $result['error'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
