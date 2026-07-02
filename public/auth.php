<?php
declare(strict_types=1);
$bootstrap = __DIR__ . '/bootstrap.php';
if (!file_exists($bootstrap)) {
    $bootstrap = dirname(__DIR__) . '/public/bootstrap.php';
}
require_once $bootstrap;

$action = $_GET['action'] ?? 'start';

try {
    if (!AllegroConfig::isConfigured($config)) {
        fail('Allegro Manager is not configured yet. Create config.php first.');
    }

    if ($action === 'start') {
        header('Location: ' . $client->authorizationUrl(), true, 302);
        exit;
    }

    if ($action === 'callback') {
        if (!empty($_GET['error'])) {
            fail('Allegro authorization error: ' . (string)$_GET['error']);
        }
        $code = (string)($_GET['code'] ?? '');
        $state = (string)($_GET['state'] ?? '');
        if ($code === '' || $state === '') {
            fail('Missing OAuth code or state.');
        }
        $client->handleCallback($code, $state);
        header('Location: ' . home_url() . '?authorized=1', true, 302);
        exit;
    }

    if ($action === 'refresh') {
        $client->refreshNow();
        header('Location: ' . home_url() . '?refreshed=1', true, 302);
        exit;
    }

    if ($action === 'logout') {
        $client->clearToken();
        header('Location: ' . home_url() . '?forgot=1', true, 302);
        exit;
    }

    fail('Unknown action.');
} catch (Throwable $e) {
    fail($e->getMessage());
}
