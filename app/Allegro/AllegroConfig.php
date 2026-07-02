<?php
declare(strict_types=1);

namespace App\Allegro;

/**
 * Configuration management for Allegro and WooCommerce integration.
 * 
 * Loads configuration from multiple sources (file, runtime settings, environment variables)
 * with cascading fallbacks.
 */
final class AllegroConfig
{
    public static function load(): array
    {
        $configPath = static::configPath();
        $config = is_file($configPath) ? require $configPath : [];
        if (!is_array($config)) {
            $config = [];
        }
        $runtime = static::runtimeSettings();

        $env = static fn(string $key, ?string $default = null): ?string => getenv($key) !== false ? (string)getenv($key) : $default;

        $merged = [
            // Allegro API credentials
            'client_id' => $runtime['client_id'] ?? $config['client_id'] ?? $env('ALLEGRO_CLIENT_ID'),
            'client_secret' => $runtime['client_secret'] ?? $config['client_secret'] ?? $env('ALLEGRO_CLIENT_SECRET'),
            'environment' => $runtime['environment'] ?? $config['environment'] ?? $env('ALLEGRO_ENV', 'prod'),
            'redirect_uri' => $runtime['redirect_uri'] ?? $config['redirect_uri'] ?? $env('ALLEGRO_REDIRECT_URI'),
            'user_agent' => $runtime['user_agent'] ?? $config['user_agent'] ?? $env('ALLEGRO_USER_AGENT', 'AllegroManager/0.1 (+https://allegro.neevalex.com/)'),
            'user_agent_info_url' => $runtime['user_agent_info_url'] ?? $config['user_agent_info_url'] ?? $env('ALLEGRO_USER_AGENT_INFO_URL', 'https://allegro.neevalex.com/'),
            // WooCommerce integration
            'woo_site_url' => $runtime['woo_site_url'] ?? $config['woo_site_url'] ?? $env('WC_SITE_URL'),
            'woo_consumer_key' => $runtime['woo_consumer_key'] ?? $config['woo_consumer_key'] ?? $env('WC_CONSUMER_KEY'),
            'woo_consumer_secret' => $runtime['woo_consumer_secret'] ?? $config['woo_consumer_secret'] ?? $env('WC_CONSUMER_SECRET'),
            'woo_namespace' => $runtime['woo_namespace'] ?? $config['woo_namespace'] ?? $env('WC_API_NAMESPACE', 'wc/v3'),
            'woo_timeout' => $runtime['woo_timeout'] ?? $config['woo_timeout'] ?? (int)($env('WC_TIMEOUT', '20') ?? 20),
            'woo_verify_ssl' => $runtime['woo_verify_ssl'] ?? $config['woo_verify_ssl'] ?? static::envBool('WC_VERIFY_SSL', true),
            // Exchange rates
            'exchange_rate_pln_uah' => $runtime['exchange_rate_pln_uah'] ?? $config['exchange_rate_pln_uah'] ?? $env('PLN_UAH_EXCHANGE_RATE'),
            'exchange_rate_source' => $runtime['exchange_rate_source'] ?? $config['exchange_rate_source'] ?? $env('PLN_UAH_EXCHANGE_RATE_SOURCE', ''),
            'exchange_rate_updated_at' => $runtime['exchange_rate_updated_at'] ?? $config['exchange_rate_updated_at'] ?? $env('PLN_UAH_EXCHANGE_RATE_UPDATED_AT', ''),
            // Pricing settings
            'nacenka_percent' => $runtime['nacenka_percent'] ?? $config['nacenka_percent'] ?? $env('NACENKA_PERCENT', '50'),
            'delivery_cost_pln' => $runtime['delivery_cost_pln'] ?? $config['delivery_cost_pln'] ?? $env('DELIVERY_COST_PLN', '0'),
        ];

        // Validate and normalize values
        $merged['environment'] = in_array($merged['environment'], ['prod', 'sandbox'], true) ? $merged['environment'] : 'prod';
        $merged['woo_namespace'] = trim((string)($merged['woo_namespace'] ?? 'wc/v3'), '/');
        if ($merged['woo_namespace'] === '') {
            $merged['woo_namespace'] = 'wc/v3';
        }
        $merged['woo_timeout'] = max(3, min(120, (int)($merged['woo_timeout'] ?? 20)));
        $merged['woo_verify_ssl'] = (bool)($merged['woo_verify_ssl'] ?? true);
        $merged['exchange_rate_pln_uah'] = max(0, (float)($merged['exchange_rate_pln_uah'] ?? 0));
        $merged['exchange_rate_source'] = trim((string)($merged['exchange_rate_source'] ?? ''));
        $merged['exchange_rate_updated_at'] = trim((string)($merged['exchange_rate_updated_at'] ?? ''));
        $merged['nacenka_percent'] = max(0, (float)($merged['nacenka_percent'] ?? 50));
        $merged['delivery_cost_pln'] = max(0, (float)($merged['delivery_cost_pln'] ?? 0));

        return $merged;
    }

    public static function isConfigured(array $config): bool
    {
        return !empty($config['client_id'])
            && !empty($config['client_secret'])
            && !str_contains((string)$config['client_id'], 'PASTE_')
            && !str_contains((string)$config['client_secret'], 'PASTE_');
    }

    public static function isWooConfigured(array $config): bool
    {
        return !empty($config['woo_site_url'])
            && !empty($config['woo_consumer_key'])
            && !empty($config['woo_consumer_secret'])
            && !str_contains((string)$config['woo_consumer_key'], 'PASTE_')
            && !str_contains((string)$config['woo_consumer_secret'], 'PASTE_');
    }

    public static function configPath(): string
    {
        return dirname(__DIR__, 2) . '/config.php';
    }

    public static function runtimeSettingsPath(): string
    {
        return dirname(__DIR__, 2) . '/data/settings.json';
    }

    public static function runtimeSettings(): array
    {
        $path = static::runtimeSettingsPath();
        if (!is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function saveRuntimeSettings(array $settings): void
    {
        $path = static::runtimeSettingsPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Could not encode runtime settings.');
        }
        if (file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException('Could not write runtime settings.');
        }
        @chmod($path, 0660);
    }

    public static function envBool(string $key, bool $default): bool
    {
        $value = getenv($key);
        if ($value === false) {
            return $default;
        }
        $normalized = strtolower(trim((string)$value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
        return $default;
    }

    public static function wooApiBase(array $config): string
    {
        $site = rtrim((string)($config['woo_site_url'] ?? ''), '/');
        $namespace = trim((string)($config['woo_namespace'] ?? 'wc/v3'), '/');
        return $site === '' ? '' : $site . '/wp-json/' . $namespace;
    }

    public static function authBase(array $config): string
    {
        return $config['environment'] === 'sandbox'
            ? 'https://allegro.pl.allegrosandbox.pl'
            : 'https://allegro.pl';
    }

    public static function apiBase(array $config): string
    {
        return $config['environment'] === 'sandbox'
            ? 'https://api.allegro.pl.allegrosandbox.pl'
            : 'https://api.allegro.pl';
    }

    public static function redirectUri(array $config): string
    {
        if (!empty($config['redirect_uri'])) {
            return (string)$config['redirect_uri'];
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/auth.php')), '/');
        return $scheme . '://' . $host . $dir . '/auth.php?action=callback';
    }
}
