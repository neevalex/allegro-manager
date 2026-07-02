<?php
declare(strict_types=1);
require_once '/var/www/allegro-manager/app/AllegroClient.php';

$config = AllegroConfig::load();
$client = new AllegroClient($config);
$action = $_GET['action'] ?? 'start';

function fail(string $message): never {
    http_response_code(400);
    $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/auth.php')), '/');
    $homeHref = $basePath === '' ? '/' : $basePath . '/';
    echo '<!doctype html><meta charset="utf-8"><title>Allegro Manager error</title>';
    echo '<body style="font-family:system-ui;padding:32px"><h1>Allegro Manager</h1><p style="color:#b91c1c">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p><p><a href="' . htmlspecialchars($homeHref, ENT_QUOTES, 'UTF-8') . '">Back to app</a></p></body>';
    exit;
}

try {
    if (!AllegroConfig::isConfigured($config)) {
        fail('Allegro Manager is not configured yet. Create /var/www/allegro-manager/config.php first.');
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
        $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/auth.php')), '/');
        header('Location: ' . ($basePath === '' ? '/' : $basePath . '/') . '?authorized=1', true, 302);
        exit;
    }

    if ($action === 'refresh') {
        $client->refreshNow();
        $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/auth.php')), '/');
        header('Location: ' . ($basePath === '' ? '/' : $basePath . '/') . '?refreshed=1', true, 302);
        exit;
    }

    if ($action === 'logout') {
        $client->clearToken();
        $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/auth.php')), '/');
        header('Location: ' . ($basePath === '' ? '/' : $basePath . '/') . '?forgot=1', true, 302);
        exit;
    }

    fail('Unknown action.');
} catch (Throwable $e) {
    fail($e->getMessage());
}
