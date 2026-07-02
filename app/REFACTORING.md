# Allegro Manager - Refactored Architecture

This document explains the refactored structure of the Allegro Manager API client.

## Overview

The original 1578-line monolithic `AllegroClient.php` has been refactored into a clean, modern PHP architecture with separated concerns and improved maintainability.

## Directory Structure

```
app/
├── Allegro/                      # Allegro API client & services
│   ├── AllegroConfig.php         # Configuration management
│   ├── AllegroClient.php         # Main client orchestrator
│   ├── JsonStore.php             # Atomic JSON file persistence
│   ├── Auth/
│   │   └── OAuth2Client.php      # OAuth2 PKCE implementation
│   ├── Http/
│   │   └── HttpClient.php        # HTTP wrapper (cURL)
│   ├── Offers/
│   │   ├── OffersService.php     # Offers API operations
│   │   └── OfferNormalizer.php   # Offer data normalization
│   └── Dashboard/
│       ├── DashboardService.php  # Dashboard analytics
│       └── Aggregates.php        # Data aggregation helper
├── WooCommerce/                  # WooCommerce integration
│   ├── WooCommerceClient.php     # WooCommerce API client
│   └── ProductNormalizer.php     # Product data normalization
├── AllegroClient.php             # Backward compatibility wrapper
└── Functions.php                 # Utility functions
```

## Key Components

### Configuration (`Allegro/AllegroConfig.php`)
- Loads from multiple sources: config file, runtime settings, environment variables
- Validates and normalizes configuration values
- Static helper methods for API endpoints and URLs

```php
$config = \App\Allegro\AllegroConfig::load();
if (\App\Allegro\AllegroConfig::isConfigured($config)) {
    // Safe to use
}
```

### JSON Persistence (`Allegro/JsonStore.php`)
- Atomic file writes using temporary files + rename
- Prevents corruption on write failures
- Used for tokens and OAuth state

```php
$store = new \App\Allegro\JsonStore('/path/to/file.json');
$data = $store->read();
$store->write(['key' => 'value']);
$store->delete();
```

### HTTP Client (`Allegro/Http/HttpClient.php`)
- Thin wrapper around cURL
- Handles headers, status codes, JSON parsing
- Returns consistent response format

```php
$http = new \App\Allegro\Http\HttpClient(45); // 45s timeout
$response = $http->request('GET', $url, $headers, $body);
// Returns: ['status', 'headers', 'data', 'trace_id']
```

### OAuth2 (`Allegro/Auth/OAuth2Client.php`)
- PKCE (Proof Key for Code Exchange) implementation
- Authorization URL generation
- Token management (obtain, refresh, validate)
- Automatic token refresh when expired

```php
$oauth = new \App\Allegro\Auth\OAuth2Client($config, $tokenStore, $stateStore);
$url = $oauth->authorizationUrl();
$token = $oauth->handleCallback($code, $state);
$accessToken = $oauth->validAccessToken(); // Auto-refreshes if needed
```

### Offers Service (`Allegro/Offers/OffersService.php`)
- List offers with pagination, sorting, filtering
- Get product offer details
- Create/update offers
- Offer normalization

```php
$offers = new \App\Allegro\Offers\OffersService($client);
$page = $offers->listPage(1, 25, 'price_asc');
$offer = $offers->getProduct($offerId);
$result = $offers->create($payload);
```

### Dashboard Service (`Allegro/Dashboard/DashboardService.php`)
- Comprehensive sales analytics
- Order aggregation
- Inventory highlights
- Daily trend calculation
- Uses `Aggregates` helper for data accumulation

```php
$dashboard = new \App\Allegro\Dashboard\DashboardService($client);
$summary = $dashboard->getSummary();
// Returns: sales, orders, items, top SKUs, active offers, trends, etc.
```

### WooCommerce Integration (`WooCommerce/WooCommerceClient.php`)
- Product listing with pagination
- Product details with variations
- Connection probing

```php
$woo = new \App\WooCommerce\WooCommerceClient($config);
$products = $woo->listProductsPage(1, 25, 'price_desc');
$details = $woo->getProductDetails($productId);
```

### Main Client (`Allegro/AllegroClient.php`)
- Orchestrates OAuth2, services, and API requests
- Delegating facade pattern
- Raw API request capability

```php
$client = new \App\Allegro\AllegroClient($config);

// OAuth methods
$url = $client->getAuthorizationUrl();
$client->handleOAuthCallback($code, $state);
$client->refreshToken();

// Service access
$client->offers()->listPage();
$client->dashboard()->getSummary();

// Raw API access
$me = $client->me();
$data = $client->apiRequest('GET', '/api/path');
```

## Namespace Organization

- `App\Allegro\` - Allegro API client
- `App\Allegro\Auth\` - Authentication
- `App\Allegro\Http\` - HTTP layer
- `App\Allegro\Offers\` - Offers management
- `App\Allegro\Dashboard\` - Analytics
- `App\WooCommerce\` - WooCommerce integration
- `App\` - Global utilities

## Backward Compatibility

The old monolithic `AllegroClient.php` file is replaced with a compatibility wrapper that provides class aliases. Existing code continues to work:

```php
// Old code still works (backward compat)
$client = new AllegroClient($config);
$token = allegro_safe_token_status($token);

// New code uses namespaces (recommended)
$client = new \App\Allegro\AllegroClient($config);
$status = \App\allegro_safe_token_status($token);
```

The original 1578-line file is backed up as `AllegroClient.php.bak`.

## Utility Functions (`Functions.php`)

Global utility functions for common operations:

- `allegro_safe_token_status()` - Check authorization status
- `allegro_refresh_dashboard_cache()` - Update cached dashboard
- `allegro_dashboard_refresh_state_path()` - Get state file path
- `allegro_read_dashboard_refresh_state()` - Read refresh state
- `allegro_write_dashboard_refresh_state()` - Write refresh state

## Key Improvements

1. **Separation of Concerns** - Each class has a single responsibility
2. **Testability** - Small, focused classes are easier to unit test
3. **Reusability** - Components can be used independently
4. **Maintainability** - Clear structure, easy to understand
5. **Type Safety** - Strict types throughout, better IDE support
6. **Documentation** - Clear docblocks and comments
7. **Error Handling** - Explicit exceptions with meaningful messages
8. **Performance** - No reduction in performance vs. original

## Usage Examples

### Complete OAuth Flow

```php
use App\Allegro\AllegroConfig;
use App\Allegro\AllegroClient;

$config = AllegroConfig::load();
$client = new AllegroClient($config);

// 1. Get authorization URL
$authUrl = $client->getAuthorizationUrl();
// Redirect user to $authUrl

// 2. Handle callback
$code = $_GET['code'];
$state = $_GET['state'];
$token = $client->handleOAuthCallback($code, $state);
// Token is now saved

// 3. Make API calls
$me = $client->me();
$offers = $client->offers()->listPage(1, 25);
```

### List Offers with Filtering

```php
$client = new \App\Allegro\AllegroClient($config);
$result = $client->offers()->listPage(
    page: 2,
    perPage: 50,
    sort: 'price_asc',
    titleQuery: 'laptop',
    skuQuery: null,
    statusFilter: 'ACTIVE'
);

echo "Page {$result['pagination']['page']} of {$result['pagination']['total_pages']}";
foreach ($result['items'] as $offer) {
    echo $offer['name'] . ': ' . $offer['price'] . ' ' . $offer['currency'];
}
```

### Dashboard Analytics

```php
$dashboard = $client->dashboard()->getSummary();

echo "Sales today: {$dashboard['sales_today']} {$dashboard['currency']}";
echo "Orders: {$dashboard['orders_today']} (pending: {$dashboard['pending_orders']})";
echo "Top SKU: {$dashboard['top_recent_skus'][0]['sku']}";
```

### WooCommerce Products

```php
use App\WooCommerce\WooCommerceClient;

$woo = new WooCommerceClient($config);
$products = $woo->listProductsPage(1, 25, 'price_desc', search: 'shirt');

foreach ($products['items'] as $product) {
    echo "{$product['name']} - {$product['price']} {$product['currency']}";
}
```

## Migration Guide

If you have code using the old structure:

**Before (monolithic):**
```php
$client = new AllegroClient($config);
$offers = $client->listOffersPage(1, 25);
```

**After (new structure, identical behavior):**
```php
$client = new \App\Allegro\AllegroClient($config);
$offers = $client->offers()->listPage(1, 25);
```

The old global functions still work via the compatibility wrapper, but new code should use the namespaced approach.

## Testing

With the refactored structure, testing is much easier:

```php
// Test OAuth only
$oauth = new \App\Allegro\Auth\OAuth2Client($config, $tokenStore, $stateStore);
$url = $oauth->authorizationUrl();

// Test offers service
$offers = new \App\Allegro\Offers\OffersService($mockClient);
$offers->listPage(1, 25);

// Test WooCommerce
$woo = new \App\WooCommerce\WooCommerceClient($config);
// etc.
```

## Performance

The refactored code has identical performance to the original:
- Same HTTP library (cURL)
- Same API calls and caching strategy
- Same JSON parsing
- No additional overhead from architecture

## Questions?

The code is well-documented with docblocks and comments. Each file is focused on a specific responsibility.
