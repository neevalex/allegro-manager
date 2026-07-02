<?php
/**
 * Bootstrap - initializes common dependencies for all views.
 * 
 * Provides:
 * - AllegroClient and configuration
 * - Common utility functions for HTML escaping and formatting
 * - Navigation structure
 */
declare(strict_types=1);

// Determine app root with flexible path resolution
$appRoot = __DIR__;
if (basename($appRoot) === 'public') {
    $appRoot = dirname($appRoot);
}

// Load the backward compat wrapper which loads all classes
require_once $appRoot . '/app/AllegroClient.php';

// Common utility functions
if (!function_exists('h')) {
    function h(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('fmt_time')) {
    function fmt_time(?int $ts): string
    {
        return $ts ? date('Y-m-d H:i:s T', $ts) : '—';
    }
}

if (!function_exists('fmt_duration')) {
    function fmt_duration(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }
        $d = intdiv($seconds, 86400); $seconds %= 86400;
        $h = intdiv($seconds, 3600); $seconds %= 3600;
        $m = intdiv($seconds, 60);
        return ($d ? $d . 'd ' : '') . ($h ? $h . 'h ' : '') . $m . 'm';
    }
}

if (!function_exists('fmt_money')) {
    function fmt_money($amount, ?string $currency): string
    {
        if ($amount === null || !is_numeric($amount)) {
            return '—';
        }
        return number_format((float)$amount, 2, '.', ' ') . ' ' . ($currency ?: 'PLN');
    }
}

if (!function_exists('fmt_iso_time')) {
    function fmt_iso_time(?string $value): string
    {
        if (!$value) {
            return '—';
        }
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s T');
        } catch (\Throwable) {
            return $value;
        }
    }
}

if (!function_exists('fmt_amount')) {
    function fmt_amount(?array $amount): string
    {
        if (!$amount) {
            return '—';
        }
        return fmt_money($amount['amount'] ?? null, $amount['currency'] ?? null);
    }
}

if (!function_exists('fmt_decimal')) {
    function fmt_decimal(?float $value, int $decimals = 2): string
    {
        return $value !== null ? number_format($value, $decimals, '.', ' ') : '—';
    }
}

function base_path(): string
{
    return rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
}

function home_url(string $path = ''): string
{
    $base = base_path();
    $prefix = $base === '' ? '/' : $base . '/';
    return $prefix . ltrim($path, '/');
}

function app_root(): string
{
    $root = __DIR__;
    if (basename($root) === 'public') {
        $root = dirname($root);
    }
    return $root;
}

function data_root(): string
{
    return app_root() . '/data';
}

function draft_dir(): string
{
    return data_root() . '/woo-allegro-drafts';
}

function log_dir(): string
{
    return data_root() . '/woo-allegro-logs';
}

function registry_path(): string
{
    return data_root() . '/woo-allegro-created-products.json';
}

function fail(string $message): never
{
    http_response_code(400);
    echo '<!doctype html><meta charset="utf-8"><title>Allegro Manager error</title>';
    echo '<body style="font-family:system-ui;padding:32px"><h1>Allegro Manager</h1><p style="color:#b91c1c">' . h($message) . '</p><p><a href="' . h(home_url()) . '">Back to app</a></p></body>';
    exit;
}

// Initialize core objects
$config = AllegroConfig::load();
$configured = AllegroConfig::isConfigured($config);
$client = new AllegroClient($config);
$token = $client->token();
$status = allegro_safe_token_status($token);

// Common navigation structure
$navTabs = [
    ['label' => 'Dashboard', 'href' => '/', 'match' => ['/']],
    ['label' => 'Offers', 'href' => '/offers.php', 'match' => ['/offers.php', '/offer.php']],
    ['label' => 'WooCommerce', 'href' => '/woocommerce.php', 'match' => ['/woocommerce.php', '/woocommerce-product.php', '/woocommerce-to-allegro.php']],
    ['label' => 'Settings', 'href' => '/settings.php', 'match' => ['/settings.php']],
];
