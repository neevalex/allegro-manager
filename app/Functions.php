<?php
declare(strict_types=1);

use App\Allegro\AllegroClient;
use App\Allegro\AllegroConfig;

/**
 * Token safety check - returns metadata about authorization status.
 * 
 * Global function in root namespace for backward compatibility.
 */
function allegro_safe_token_status(?array $token): array
{
    if (!$token) {
        return ['authorized' => false];
    }
    return [
        'authorized' => !empty($token['access_token']),
        'token_type' => $token['token_type'] ?? null,
        'expires_at' => $token['expires_at'] ?? null,
        'expires_in_seconds' => isset($token['expires_at']) ? max(0, (int)$token['expires_at'] - time()) : null,
        'environment' => $token['environment'] ?? null,
        'has_refresh_token' => !empty($token['refresh_token']),
    ];
}

/**
 * Refresh dashboard cache with latest summary data.
 */
function allegro_refresh_dashboard_cache(
    AllegroClient $client,
    array $config,
    ?string $target = null
): array {
    $payload = [
        'generated_at' => gmdate(\DateTimeInterface::ATOM),
        'ok' => false,
        'data' => null,
        'error' => null,
    ];

    try {
        if (!AllegroConfig::isConfigured($config)) {
            throw new \RuntimeException('Allegro config is incomplete.');
        }
        $token = $client->getToken();
        $status = allegro_safe_token_status($token);
        if (empty($status['authorized'])) {
            throw new \RuntimeException('Allegro token is not authorized.');
        }
        $payload['data'] = $client->dashboard()->getSummary();
        $payload['ok'] = true;
    } catch (\Throwable $e) {
        $payload['error'] = $e->getMessage();
    }

    $target ??= dirname(__DIR__) . '/data/dashboard-summary.json';
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new \RuntimeException('Could not encode dashboard cache payload.');
    }
    if (file_put_contents($target, $json . PHP_EOL, LOCK_EX) === false) {
        throw new \RuntimeException("Failed to write {$target}");
    }

    return [
        'target' => $target,
        'ok' => $payload['ok'],
        'generated_at' => $payload['generated_at'],
        'error' => $payload['error'],
        'data' => $payload['data'],
    ];
}

/**
 * Get path to dashboard refresh state file.
 */
function allegro_dashboard_refresh_state_path(): string
{
    return dirname(__DIR__) . '/data/dashboard-refresh-state.json';
}

/**
 * Read dashboard refresh state.
 */
function allegro_read_dashboard_refresh_state(?string $path = null): ?array
{
    $path ??= allegro_dashboard_refresh_state_path();
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Write dashboard refresh state.
 */
function allegro_write_dashboard_refresh_state(array $state, ?string $path = null): void
{
    $path ??= allegro_dashboard_refresh_state_path();
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new \RuntimeException('Could not encode dashboard refresh state.');
    }
    if (file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
        throw new \RuntimeException("Failed to write {$path}");
    }
}
