<?php
declare(strict_types=1);
$bootstrap = __DIR__ . '/bootstrap.php';
if (!file_exists($bootstrap)) {
    $bootstrap = dirname(__DIR__) . '/public/bootstrap.php';
}
require_once $bootstrap;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo 'Method Not Allowed';
    exit;
}

$returnTo = (string)($_POST['return_to'] ?? '/');
$returnTo = $returnTo !== '' ? $returnTo : '/';
$parsed = parse_url($returnTo);
if ($returnTo[0] !== '/' || $parsed === false || isset($parsed['scheme']) || isset($parsed['host'])) {
    $returnTo = '/';
}

try {
    $appRoot = app_root();
    $phpPath = (PHP_SAPI === 'cli' && PHP_BINARY) ? PHP_BINARY : (PHP_BINDIR . '/php');
    allegro_write_dashboard_refresh_state([
        'status' => 'queued',
        'requested_at' => gmdate(\DateTimeInterface::ATOM),
        'source' => 'web',
        'return_to' => $returnTo,
    ]);
    $cmd = sprintf(
        'cd %s && %s %s/refresh_dashboard_summary.php >%s/data/manual-refresh.log 2>&1 & echo $!',
        escapeshellarg($appRoot),
        escapeshellarg($phpPath),
        escapeshellarg($appRoot),
        escapeshellarg($appRoot)
    );
    $pid = trim((string)shell_exec($cmd));
    if ($pid === '') {
        throw new \RuntimeException('Could not start dashboard refresh process.');
    }
    $state = allegro_read_dashboard_refresh_state() ?? [];
    $state['status'] = $state['status'] ?? 'queued';
    $state['requested_at'] = $state['requested_at'] ?? gmdate(\DateTimeInterface::ATOM);
    $state['pid'] = (int)$pid;
    $state['source'] = 'web';
    $state['return_to'] = $returnTo;
    allegro_write_dashboard_refresh_state($state);
    $params = [
        'refresh' => 'started',
        'refresh_pid' => $pid,
    ];
} catch (\Throwable $e) {
    $params = [
        'refresh' => 'error',
        'refresh_message' => $e->getMessage(),
    ];
}

$separator = str_contains($returnTo, '?') ? '&' : '?';
header('Location: ' . $returnTo . $separator . http_build_query($params, '', '&', PHP_QUERY_RFC3986), true, 303);
exit;
