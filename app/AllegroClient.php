<?php
declare(strict_types=1);

/**
 * Backward compatibility wrapper for refactored Allegro manager.
 * 
 * This file provides aliases and re-exports for the old monolithic class names
 * to maintain backward compatibility with existing code. New code should use
 * the namespaced classes directly.
 */

// Allegro namespace imports
use App\Allegro\AllegroConfig;
use App\Allegro\AllegroClient as AllegroClientImpl;
use App\Allegro\JsonStore;
use App\Allegro\Auth\OAuth2Client;
use App\WooCommerce\WooCommerceClient;

// Function imports
use function App\allegro_safe_token_status;
use function App\allegro_refresh_dashboard_cache;
use function App\allegro_dashboard_refresh_state_path;
use function App\allegro_read_dashboard_refresh_state;
use function App\allegro_write_dashboard_refresh_state;

// Class aliases for backward compatibility
class_alias(AllegroConfig::class, 'AllegroConfig');
class_alias(AllegroClientImpl::class, 'AllegroClient');
class_alias(JsonStore::class, 'JsonStore');
class_alias(OAuth2Client::class, 'OAuth2Client');
class_alias(WooCommerceClient::class, 'WooCommerceClient');

// Function aliases for backward compatibility
if (!function_exists('allegro_safe_token_status')) {
    function allegro_safe_token_status(?array $token): array {
        return \App\allegro_safe_token_status($token);
    }
}

if (!function_exists('allegro_refresh_dashboard_cache')) {
    function allegro_refresh_dashboard_cache(AllegroClientImpl $client, array $config, ?string $target = null): array {
        return \App\allegro_refresh_dashboard_cache($client, $config, $target);
    }
}

if (!function_exists('allegro_dashboard_refresh_state_path')) {
    function allegro_dashboard_refresh_state_path(): string {
        return \App\allegro_dashboard_refresh_state_path();
    }
}

if (!function_exists('allegro_read_dashboard_refresh_state')) {
    function allegro_read_dashboard_refresh_state(?string $path = null): ?array {
        return \App\allegro_read_dashboard_refresh_state($path);
    }
}

if (!function_exists('allegro_write_dashboard_refresh_state')) {
    function allegro_write_dashboard_refresh_state(array $state, ?string $path = null): void {
        \App\allegro_write_dashboard_refresh_state($state, $path);
    }
}
