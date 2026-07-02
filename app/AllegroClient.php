<?php
declare(strict_types=1);

/**
 * Backward compatibility wrapper for refactored Allegro manager.
 * 
 * This file provides aliases and re-exports for the old monolithic class names
 * to maintain backward compatibility with existing code. New code should use
 * the namespaced classes directly.
 * 
 * IMPORTANT: This file ONLY provides class aliases. Function wrappers are
 * intentionally omitted because the global functions are now defined in
 * app/Functions.php. If you need the functions, include Functions.php directly
 * or use the namespaced versions from \App namespace.
 */

// Allegro namespace imports
use App\Allegro\AllegroConfig;
use App\Allegro\AllegroClient as AllegroClientImpl;
use App\Allegro\JsonStore;
use App\Allegro\Auth\OAuth2Client;
use App\WooCommerce\WooCommerceClient;

// Class aliases for backward compatibility
class_alias(AllegroConfig::class, 'AllegroConfig');
class_alias(AllegroClientImpl::class, 'AllegroClient');
class_alias(JsonStore::class, 'JsonStore');
class_alias(OAuth2Client::class, 'OAuth2Client');
class_alias(WooCommerceClient::class, 'WooCommerceClient');

// Load utility functions (global namespace versions are defined here)
require_once __DIR__ . '/Functions.php';
