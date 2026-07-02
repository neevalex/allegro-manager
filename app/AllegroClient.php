<?php
declare(strict_types=1);

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
            'client_id' => $runtime['client_id'] ?? $config['client_id'] ?? $env('ALLEGRO_CLIENT_ID'),
            'client_secret' => $runtime['client_secret'] ?? $config['client_secret'] ?? $env('ALLEGRO_CLIENT_SECRET'),
            'environment' => $runtime['environment'] ?? $config['environment'] ?? $env('ALLEGRO_ENV', 'prod'),
            'redirect_uri' => $runtime['redirect_uri'] ?? $config['redirect_uri'] ?? $env('ALLEGRO_REDIRECT_URI'),
            'user_agent' => $runtime['user_agent'] ?? $config['user_agent'] ?? $env('ALLEGRO_USER_AGENT', 'AllegroManager/0.1 (+https://allegro.neevalex.com/)'),
            'user_agent_info_url' => $runtime['user_agent_info_url'] ?? $config['user_agent_info_url'] ?? $env('ALLEGRO_USER_AGENT_INFO_URL', 'https://allegro.neevalex.com/'),
            'woo_site_url' => $runtime['woo_site_url'] ?? $config['woo_site_url'] ?? $env('WC_SITE_URL'),
            'woo_consumer_key' => $runtime['woo_consumer_key'] ?? $config['woo_consumer_key'] ?? $env('WC_CONSUMER_KEY'),
            'woo_consumer_secret' => $runtime['woo_consumer_secret'] ?? $config['woo_consumer_secret'] ?? $env('WC_CONSUMER_SECRET'),
            'woo_namespace' => $runtime['woo_namespace'] ?? $config['woo_namespace'] ?? $env('WC_API_NAMESPACE', 'wc/v3'),
            'woo_timeout' => $runtime['woo_timeout'] ?? $config['woo_timeout'] ?? (int)($env('WC_TIMEOUT', '20') ?? 20),
            'woo_verify_ssl' => $runtime['woo_verify_ssl'] ?? $config['woo_verify_ssl'] ?? static::envBool('WC_VERIFY_SSL', true),
            'exchange_rate_pln_uah' => $runtime['exchange_rate_pln_uah'] ?? $config['exchange_rate_pln_uah'] ?? $env('PLN_UAH_EXCHANGE_RATE'),
            'exchange_rate_source' => $runtime['exchange_rate_source'] ?? $config['exchange_rate_source'] ?? $env('PLN_UAH_EXCHANGE_RATE_SOURCE', ''),
            'exchange_rate_updated_at' => $runtime['exchange_rate_updated_at'] ?? $config['exchange_rate_updated_at'] ?? $env('PLN_UAH_EXCHANGE_RATE_UPDATED_AT', ''),
            'nacenka_percent' => $runtime['nacenka_percent'] ?? $config['nacenka_percent'] ?? $env('NACENKA_PERCENT', '50'),
            'delivery_cost_pln' => $runtime['delivery_cost_pln'] ?? $config['delivery_cost_pln'] ?? $env('DELIVERY_COST_PLN', '0'),
        ];

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
        return dirname(__DIR__) . '/config.php';
    }

    public static function runtimeSettingsPath(): string
    {
        return dirname(__DIR__) . '/data/settings.json';
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
            throw new RuntimeException('Could not encode runtime settings.');
        }
        if (file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Could not write runtime settings.');
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

final class JsonStore
{
    public function __construct(private string $path) {}

    public function read(): ?array
    {
        if (!is_file($this->path)) {
            return null;
        }
        $raw = file_get_contents($this->path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    public function write(array $data): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        $tmp = $this->path . '.tmp.' . bin2hex(random_bytes(4));
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Could not encode JSON store.');
        }
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException('Could not write temporary JSON store.');
        }
        chmod($tmp, 0660);
        if (!rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new RuntimeException('Could not replace JSON store.');
        }
    }

    public function delete(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }
}

final class AllegroClient
{
    private JsonStore $tokenStore;
    private JsonStore $stateStore;

    public function __construct(private array $config)
    {
        $dataDir = dirname(__DIR__) . '/data';
        $this->tokenStore = new JsonStore($dataDir . '/tokens.json');
        $this->stateStore = new JsonStore($dataDir . '/oauth-state.json');
    }

    public function token(): ?array
    {
        return $this->tokenStore->read();
    }

    public function clearToken(): void
    {
        $this->tokenStore->delete();
    }

    public function authorizationUrl(): string
    {
        $state = $this->base64Url(random_bytes(32));
        $verifier = $this->base64Url(random_bytes(48));
        $challenge = $this->base64Url(hash('sha256', $verifier, true));

        $this->stateStore->write([
            'state' => $state,
            'code_verifier' => $verifier,
            'created_at' => time(),
        ]);

        $params = [
            'response_type' => 'code',
            'client_id' => $this->config['client_id'],
            'redirect_uri' => AllegroConfig::redirectUri($this->config),
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];

        return AllegroConfig::authBase($this->config) . '/auth/oauth/authorize?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public function handleCallback(string $code, string $state): array
    {
        $saved = $this->stateStore->read();
        if (!$saved || empty($saved['state']) || !hash_equals((string)$saved['state'], $state)) {
            throw new RuntimeException('OAuth state mismatch. Start authorization again.');
        }
        if (($saved['created_at'] ?? 0) < time() - 900) {
            throw new RuntimeException('OAuth state expired. Start authorization again.');
        }

        $token = $this->requestToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => AllegroConfig::redirectUri($this->config),
            'code_verifier' => $saved['code_verifier'],
        ]);
        $this->saveToken($token);
        $this->stateStore->delete();
        return $this->tokenStore->read() ?? [];
    }

    public function validAccessToken(): string
    {
        $token = $this->tokenStore->read();
        if (!$token || empty($token['access_token'])) {
            throw new RuntimeException('Not authorized with Allegro yet.');
        }
        if (($token['expires_at'] ?? 0) <= time() + 120) {
            if (empty($token['refresh_token'])) {
                throw new RuntimeException('Token is expired and no refresh token is available. Re-authorize.');
            }
            $refreshed = $this->requestToken([
                'grant_type' => 'refresh_token',
                'refresh_token' => $token['refresh_token'],
            ]);
            if (empty($refreshed['refresh_token'])) {
                $refreshed['refresh_token'] = $token['refresh_token'];
            }
            $this->saveToken($refreshed);
            $token = $this->tokenStore->read();
        }
        return (string)$token['access_token'];
    }

    public function refreshNow(): array
    {
        $token = $this->tokenStore->read();
        if (!$token || empty($token['refresh_token'])) {
            throw new RuntimeException('No refresh token is saved. Authorize first.');
        }
        $refreshed = $this->requestToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $token['refresh_token'],
        ]);
        if (empty($refreshed['refresh_token'])) {
            $refreshed['refresh_token'] = $token['refresh_token'];
        }
        $this->saveToken($refreshed);
        return $this->tokenStore->read() ?? [];
    }

    public function me(): array
    {
        return $this->apiRequest('GET', '/me');
    }

    public function listOffersPage(int $page = 1, int $perPage = 25, string $sort = 'created_desc', ?string $titleQuery = null, ?string $skuQuery = null, ?string $statusFilter = null): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $titleQuery = trim((string)$titleQuery);
        $skuQuery = trim((string)$skuQuery);
        $statusFilter = strtoupper(trim((string)$statusFilter));

        $sortOptions = [
            'created_desc' => [
                'label' => 'Date (newest first)',
                'api_sort' => null,
            ],
            'price_asc' => [
                'label' => 'Price (low to high)',
                'api_sort' => 'sellingMode.price.amount',
            ],
            'price_desc' => [
                'label' => 'Price (high to low)',
                'api_sort' => '-sellingMode.price.amount',
            ],
            'sold_desc' => [
                'label' => 'Sold (most first)',
                'api_sort' => '-stock.sold',
            ],
            'sold_asc' => [
                'label' => 'Sold (least first)',
                'api_sort' => 'stock.sold',
            ],
            'stock_desc' => [
                'label' => 'Stock (most first)',
                'api_sort' => '-stock.available',
            ],
            'stock_asc' => [
                'label' => 'Stock (least first)',
                'api_sort' => 'stock.available',
            ],
        ];
        $statusOptions = [
            '' => 'All statuses',
            'ACTIVE' => 'ACTIVE',
            'INACTIVE' => 'INACTIVE',
            'ENDED' => 'ENDED',
            'ACTIVATING' => 'ACTIVATING',
        ];
        if (!isset($sortOptions[$sort])) {
            $sort = 'created_desc';
        }
        if (!isset($statusOptions[$statusFilter])) {
            $statusFilter = '';
        }

        $query = [
            'limit' => $perPage,
            'offset' => ($page - 1) * $perPage,
        ];
        if ($sortOptions[$sort]['api_sort'] !== null) {
            $query['sort'] = $sortOptions[$sort]['api_sort'];
        }
        if ($titleQuery !== '') {
            $query['name'] = $titleQuery;
        }
        if ($skuQuery !== '') {
            $query['external.id'] = $skuQuery;
        }
        if ($statusFilter !== '') {
            $query['publication.status'] = $statusFilter;
        }

        $response = $this->apiRequest('GET', '/sale/offers?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        $rawOffers = is_array($response['offers'] ?? null) ? $response['offers'] : [];
        $items = [];
        foreach ($rawOffers as $offer) {
            $offerId = (string)($offer['id'] ?? '');
            $items[] = [
                'id' => $offerId !== '' ? $offerId : '—',
                'name' => (string)($offer['name'] ?? 'Unnamed offer'),
                'sku' => (string)($offer['external']['id'] ?? '—'),
                'status' => (string)($offer['publication']['status'] ?? '—'),
                'started_at' => $offer['publication']['startedAt'] ?? null,
                'starting_at' => $offer['publication']['startingAt'] ?? null,
                'ended_at' => $offer['publication']['endedAt'] ?? null,
                'price' => $this->moneyAmount($offer['sellingMode']['price'] ?? null),
                'currency' => $this->moneyCurrency($offer['sellingMode']['price'] ?? null),
                'sold' => (int)($offer['stock']['sold'] ?? 0),
                'available' => (int)($offer['stock']['available'] ?? 0),
                'visits' => (int)($offer['stats']['visitsCount'] ?? 0),
                'watchers' => (int)($offer['stats']['watchersCount'] ?? 0),
                'category_id' => (string)($offer['category']['id'] ?? '—'),
                'format' => (string)($offer['sellingMode']['format'] ?? '—'),
                'image_url' => (string)($offer['primaryImage']['url'] ?? ''),
                'allegro_url' => $offerId !== '' ? 'https://allegro.pl/oferta/' . rawurlencode($offerId) : '',
            ];
        }

        $count = (int)($response['count'] ?? count($items));
        $totalCount = max($count, (int)($response['totalCount'] ?? count($items)));
        $totalPages = max(1, (int)ceil($totalCount / max(1, $perPage)));
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        return [
            'items' => $items,
            'sort' => $sort,
            'sort_options' => $sortOptions,
            'filters' => [
                'title' => $titleQuery,
                'sku' => $skuQuery,
                'status' => $statusFilter,
                'status_options' => $statusOptions,
            ],
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'count' => $count,
                'total_count' => $totalCount,
                'total_pages' => $totalPages,
                'has_prev' => $page > 1,
                'has_next' => ($page * $perPage) < $totalCount,
                'prev_page' => max(1, $page - 1),
                'next_page' => min($totalPages, $page + 1),
                'from' => $totalCount > 0 ? (($page - 1) * $perPage) + 1 : 0,
                'to' => min($page * $perPage, $totalCount),
            ],
            'trace_id' => $response['_trace_id'] ?? null,
        ];
    }

    public function getProductOffer(string $offerId): array
    {
        $offerId = trim($offerId);
        if ($offerId === '') {
            throw new InvalidArgumentException('Offer ID is required.');
        }
        $response = $this->apiRequest('GET', '/sale/product-offers/' . rawurlencode($offerId));
        return $this->normalizeProductOffer($response);
    }

    public function updateProductOffer(string $offerId, array $input): array
    {
        $offerId = trim($offerId);
        if ($offerId === '') {
            throw new InvalidArgumentException('Offer ID is required.');
        }

        $current = $this->apiRequest('GET', '/sale/product-offers/' . rawurlencode($offerId));
        $payload = [];

        if (array_key_exists('name', $input)) {
            $payload['name'] = trim((string)$input['name']);
        }
        if (array_key_exists('external_id', $input)) {
            $payload['external'] = [
                'id' => trim((string)$input['external_id']),
            ];
        }
        if (array_key_exists('price_amount', $input)) {
            $currency = (string)($current['sellingMode']['price']['currency'] ?? 'PLN');
            $payload['sellingMode'] = [
                'price' => [
                    'amount' => $this->normalizePriceAmount($input['price_amount']),
                    'currency' => $currency,
                ],
            ];
        }
        if (array_key_exists('stock_available', $input)) {
            $payload['stock'] = [
                'available' => max(0, (int)$input['stock_available']),
                'unit' => (string)($current['stock']['unit'] ?? 'UNIT'),
            ];
        }
        if ($payload === []) {
            throw new InvalidArgumentException('No editable offer fields were provided.');
        }

        $response = $this->apiRequest('PATCH', '/sale/product-offers/' . rawurlencode($offerId), $payload);
        $latest = $this->waitForOfferUpdate($offerId, $response['_headers']['location'] ?? null, $response['_headers']['retry-after'] ?? null);

        return [
            'submitted' => $payload,
            'status_code' => (int)($response['_status'] ?? 200),
            'trace_id' => $response['_trace_id'] ?? ($latest['trace_id'] ?? null),
            'offer' => $latest,
        ];
    }

    public function createProductOffer(array $payload): array
    {
        if ($payload === []) {
            throw new InvalidArgumentException('Offer payload is empty.');
        }

        $response = $this->apiRequest('POST', '/sale/product-offers', $payload);
        $offerId = trim((string)($response['id'] ?? ''));
        $offer = null;

        if ($offerId !== '') {
            try {
                $offer = $this->waitForOfferUpdate($offerId, $response['_headers']['location'] ?? null, $response['_headers']['retry-after'] ?? null);
            } catch (Throwable) {
                $offer = null;
            }
        }

        if ($offer === null && isset($response['name'])) {
            $offer = $this->normalizeProductOffer($response);
        }

        return [
            'submitted' => $payload,
            'status_code' => (int)($response['_status'] ?? 201),
            'trace_id' => $response['_trace_id'] ?? ($offer['trace_id'] ?? null),
            'offer_id' => $offerId,
            'offer' => $offer,
            'raw' => $response,
        ];
    }

    public function latestOfferCreationTemplate(): ?array
    {
        $response = $this->apiRequest('GET', '/sale/offers?' . http_build_query([
            'limit' => 1,
            'offset' => 0,
        ], '', '&', PHP_QUERY_RFC3986));
        $offers = is_array($response['offers'] ?? null) ? $response['offers'] : [];
        $offerId = trim((string)(is_array($offers[0] ?? null) ? ($offers[0]['id'] ?? '') : ''));
        if ($offerId === '') {
            return null;
        }

        $raw = $this->apiRequest('GET', '/sale/product-offers/' . rawurlencode($offerId));
        $publication = [
            'status' => 'INACTIVE',
        ];
        $duration = trim((string)($raw['publication']['duration'] ?? ''));
        if ($duration !== '') {
            $publication['duration'] = $duration;
        }

        $template = [
            'source_offer_id' => $offerId,
            'language' => trim((string)($raw['language'] ?? 'pl-PL')) ?: 'pl-PL',
            'selling_mode_format' => trim((string)($raw['sellingMode']['format'] ?? 'BUY_NOW')) ?: 'BUY_NOW',
            'category' => [
                'id' => trim((string)($raw['category']['id'] ?? '')),
            ],
            'publication' => $publication,
            'delivery' => [
                'handlingTime' => trim((string)($raw['delivery']['handlingTime'] ?? '')),
                'shippingRates' => [
                    'id' => trim((string)($raw['delivery']['shippingRates']['id'] ?? '')),
                    'name' => trim((string)($raw['delivery']['shippingRates']['name'] ?? '')),
                ],
            ],
            'afterSalesServices' => [
                'impliedWarranty' => [
                    'id' => trim((string)($raw['afterSalesServices']['impliedWarranty']['id'] ?? '')),
                    'name' => trim((string)($raw['afterSalesServices']['impliedWarranty']['name'] ?? '')),
                ],
                'returnPolicy' => [
                    'id' => trim((string)($raw['afterSalesServices']['returnPolicy']['id'] ?? '')),
                    'name' => trim((string)($raw['afterSalesServices']['returnPolicy']['name'] ?? '')),
                ],
                'warranty' => [
                    'id' => trim((string)($raw['afterSalesServices']['warranty']['id'] ?? '')),
                    'name' => trim((string)($raw['afterSalesServices']['warranty']['name'] ?? '')),
                ],
            ],
            'payments' => [
                'invoice' => trim((string)($raw['payments']['invoice'] ?? '')),
            ],
            'location' => [
                'city' => trim((string)($raw['location']['city'] ?? '')),
                'province' => trim((string)($raw['location']['province'] ?? '')),
                'postCode' => trim((string)($raw['location']['postCode'] ?? '')),
                'countryCode' => trim((string)($raw['location']['countryCode'] ?? '')),
            ],
            'taxSettings' => is_array($raw['taxSettings'] ?? null) ? $raw['taxSettings'] : null,
        ];

        foreach (['category', 'delivery', 'afterSalesServices', 'payments', 'location'] as $key) {
            if (is_array($template[$key] ?? null)) {
                $template[$key] = $this->pruneEmptyValues($template[$key]);
            }
            if ($template[$key] === [] || $template[$key] === null) {
                unset($template[$key]);
            }
        }
        if (!is_array($template['taxSettings'] ?? null) || $template['taxSettings'] === []) {
            unset($template['taxSettings']);
        }

        return $template;
    }

    public function dashboardSummary(): array
    {
        $now = new DateTimeImmutable('now');
        $todayStart = $now->setTime(0, 0, 0);
        $monthStart = $now->modify('first day of this month')->setTime(0, 0, 0);
        $days7Start = $todayStart->modify('-6 days');
        $days30Start = $todayStart->modify('-29 days');
        $ordersSinceStart = $monthStart->getTimestamp() < $days30Start->getTimestamp() ? $monthStart : $days30Start;

        $isoMonth = $monthStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $isoOrdersSince = $ordersSinceStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

        $latestPayments = $this->paginateCollection('/payments/payment-operations', 'paymentOperations', [
            'limit' => 1,
        ]);
        $payments = $this->paginateCollection('/payments/payment-operations', 'paymentOperations', [
            'occurredAt.gte' => $isoMonth,
            'limit' => 100,
        ]);
        $orders = $this->paginateCollection('/order/checkout-forms', 'checkoutForms', [
            'lineItems.boughtAt.gte' => $isoOrdersSince,
            'limit' => 100,
            'sort' => 'lineItems.boughtAt-desc',
        ]);
        $activeOffersResponse = $this->apiRequest('GET', '/sale/offers?' . http_build_query([
            'publication.status' => 'ACTIVE',
            'limit' => 100,
            'offset' => 0,
        ], '', '&', PHP_QUERY_RFC3986));
        $activeOffers = is_array($activeOffersResponse['offers'] ?? null) ? $activeOffersResponse['offers'] : [];

        $balance = null;
        $balanceCurrency = null;
        $balanceUpdatedAt = null;
        $todayIncome = 0.0;
        $monthIncome = 0.0;
        $todayExpenses = 0.0;
        $monthExpenses = 0.0;
        $todaySalesGross = 0.0;
        $sales7Gross = 0.0;
        $sales30Gross = 0.0;
        $monthSalesGross = 0.0;
        $pendingOrders = 0;
        $awaitingShipmentCount = 0;
        $todayOrders = 0;
        $orders7 = 0;
        $orders30 = 0;
        $monthOrders = 0;
        $todayItems = 0;
        $items7 = 0;
        $items30 = 0;
        $monthItems = 0;
        $currency = 'PLN';
        $awaitingShipment = [];
        $recentSkuMap = [];
        $activeOfferHighlights = [];
        $dailyTrend = [];

        for ($i = 29; $i >= 0; $i--) {
            $day = $todayStart->modify('-' . $i . ' days');
            $dailyTrend[$day->format('Y-m-d')] = [
                'date' => $day->format(DateTimeInterface::ATOM),
                'label' => $day->format('d M'),
                'sales' => 0.0,
                'orders' => 0,
                'items' => 0,
            ];
        }

        $latestPayment = $latestPayments[0] ?? null;
        if (is_array($latestPayment) && isset($latestPayment['wallet']['balance'])) {
            $balance = $this->moneyAmount($latestPayment['wallet']['balance']);
            $balanceCurrency = $this->moneyCurrency($latestPayment['wallet']['balance']) ?? $currency;
            $balanceUpdatedAt = $latestPayment['occurredAt'] ?? null;
            $latestCurrency = $this->moneyCurrency($latestPayment['value'] ?? null);
            if ($latestCurrency !== null) {
                $currency = $latestCurrency;
            }
        }

        foreach ($payments as $operation) {
            $occurredAt = $this->safeDate($operation['occurredAt'] ?? null);
            $amount = $this->moneyAmount($operation['value'] ?? null);
            $opCurrency = $this->moneyCurrency($operation['value'] ?? null);
            if ($opCurrency !== null) {
                $currency = $opCurrency;
            }
            if ($balance === null && isset($operation['wallet']['balance'])) {
                $balance = $this->moneyAmount($operation['wallet']['balance']);
                $balanceCurrency = $this->moneyCurrency($operation['wallet']['balance']) ?? $currency;
                $balanceUpdatedAt = $operation['occurredAt'] ?? null;
            }
            if (!$occurredAt || $amount === null) {
                continue;
            }
            if (($operation['group'] ?? null) === 'INCOME') {
                $monthIncome += $amount;
                if ($occurredAt >= $todayStart) {
                    $todayIncome += $amount;
                }
            } elseif (($operation['group'] ?? null) === 'OUTCOME') {
                $monthExpenses += abs($amount);
                if ($occurredAt >= $todayStart) {
                    $todayExpenses += abs($amount);
                }
            }
        }

        foreach ($orders as $order) {
            $finishedAt = $this->safeDate($order['payment']['finishedAt'] ?? null)
                ?? $this->safeDate($order['updatedAt'] ?? null);
            $paidAmount = $this->moneyAmount($order['payment']['paidAmount'] ?? null)
                ?? $this->moneyAmount($order['summary']['totalToPay'] ?? null)
                ?? 0.0;
            $orderCurrency = $this->moneyCurrency($order['payment']['paidAmount'] ?? null)
                ?? $this->moneyCurrency($order['summary']['totalToPay'] ?? null);
            if ($orderCurrency !== null) {
                $currency = $orderCurrency;
            }
            $itemsCount = 0;
            foreach (($order['lineItems'] ?? []) as $lineItem) {
                $quantity = (int) round((float) ($lineItem['quantity'] ?? 0));
                $itemsCount += $quantity;
                $lineAmount = $this->moneyAmount($lineItem['price'] ?? null)
                    ?? $this->moneyAmount($lineItem['originalPrice'] ?? null)
                    ?? 0.0;
                $offerId = (string)($lineItem['offer']['id'] ?? '');
                $sku = (string)($lineItem['offer']['external']['id'] ?? $offerId);
                $key = $sku !== '' ? $sku : ($offerId !== '' ? $offerId : uniqid('sku_', true));
                if (!isset($recentSkuMap[$key])) {
                    $recentSkuMap[$key] = [
                        'sku' => $sku !== '' ? $sku : '—',
                        'offer_id' => $offerId !== '' ? $offerId : '—',
                        'name' => (string)($lineItem['offer']['name'] ?? 'Unnamed offer'),
                        'qty' => 0,
                        'orders' => 0,
                        'revenue' => 0.0,
                    ];
                }
                if ($finishedAt && $finishedAt >= $days30Start) {
                    $recentSkuMap[$key]['qty'] += $quantity;
                    $recentSkuMap[$key]['revenue'] += $lineAmount * max(1, $quantity);
                    $recentSkuMap[$key]['orders'] += 1;
                }
            }

            $status = (string)($order['status'] ?? '');
            $fulfillmentStatus = (string)($order['fulfillment']['status'] ?? '');
            $shipmentSummary = (string)($order['fulfillment']['shipmentSummary']['lineItemsSent'] ?? '');
            if (in_array($status, ['READY_FOR_PROCESSING', 'FILLED_IN'], true)
                || in_array($fulfillmentStatus, ['NEW', 'PROCESSING'], true)) {
                $pendingOrders++;
            }
            $isAwaitingShipment = in_array($fulfillmentStatus, ['NEW', 'PROCESSING'], true)
                || ($status === 'READY_FOR_PROCESSING' && !in_array($shipmentSummary, ['ALL'], true) && !in_array($fulfillmentStatus, ['PICKED_UP', 'SENT'], true));
            if ($isAwaitingShipment) {
                $awaitingShipmentCount++;
                if (count($awaitingShipment) < 5) {
                    $awaitingShipment[] = [
                        'id' => (string)($order['id'] ?? '—'),
                        'buyer' => trim((string)($order['buyer']['firstName'] ?? '') . ' ' . (string)($order['buyer']['lastName'] ?? '')) ?: ((string)($order['buyer']['login'] ?? 'Buyer')),
                        'status' => $status !== '' ? $status : '—',
                        'fulfillment_status' => $fulfillmentStatus !== '' ? $fulfillmentStatus : '—',
                        'amount' => $paidAmount,
                        'currency' => $orderCurrency ?? $currency,
                        'updated_at' => $order['updatedAt'] ?? null,
                        'delivery_method' => (string)($order['delivery']['method']['name'] ?? '—'),
                    ];
                }
            }

            if (!$finishedAt) {
                continue;
            }
            $dayKey = $finishedAt->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d');
            if (isset($dailyTrend[$dayKey])) {
                $dailyTrend[$dayKey]['sales'] += $paidAmount;
                $dailyTrend[$dayKey]['orders'] += 1;
                $dailyTrend[$dayKey]['items'] += $itemsCount;
            }
            if ($finishedAt >= $monthStart) {
                $monthOrders++;
                $monthItems += $itemsCount;
                $monthSalesGross += $paidAmount;
            }
            if ($finishedAt >= $days30Start) {
                $orders30++;
                $items30 += $itemsCount;
                $sales30Gross += $paidAmount;
            }
            if ($finishedAt >= $days7Start) {
                $orders7++;
                $items7 += $itemsCount;
                $sales7Gross += $paidAmount;
            }
            if ($finishedAt >= $todayStart) {
                $todayOrders++;
                $todayItems += $itemsCount;
                $todaySalesGross += $paidAmount;
            }
        }

        uasort($recentSkuMap, static function (array $a, array $b): int {
            return [$b['qty'], $b['revenue'], $b['orders']] <=> [$a['qty'], $a['revenue'], $a['orders']];
        });
        $topSkus = array_slice(array_values($recentSkuMap), 0, 5);

        foreach ($activeOffers as $offer) {
            $activeOfferHighlights[] = [
                'offer_id' => (string)($offer['id'] ?? '—'),
                'sku' => (string)($offer['external']['id'] ?? '—'),
                'name' => (string)($offer['name'] ?? 'Unnamed offer'),
                'price' => $this->moneyAmount($offer['sellingMode']['price'] ?? null),
                'currency' => $this->moneyCurrency($offer['sellingMode']['price'] ?? null) ?? $currency,
                'sold' => (int)($offer['stock']['sold'] ?? 0),
                'visits' => (int)($offer['stats']['visitsCount'] ?? 0),
                'watchers' => (int)($offer['stats']['watchersCount'] ?? 0),
                'available' => (int)($offer['stock']['available'] ?? 0),
            ];
        }
        usort($activeOfferHighlights, static function (array $a, array $b): int {
            return [$b['sold'], $b['visits'], $b['watchers']] <=> [$a['sold'], $a['visits'], $a['watchers']];
        });
        $activeOfferHighlights = array_slice($activeOfferHighlights, 0, 5);

        return [
            'currency' => $currency,
            'balance' => $balance,
            'balance_currency' => $balanceCurrency ?? $currency,
            'balance_updated_at' => $balanceUpdatedAt,
            'sales_today' => $todaySalesGross,
            'sales_7d' => $sales7Gross,
            'sales_30d' => $sales30Gross,
            'sales_month' => $monthSalesGross,
            'income_today' => $todayIncome,
            'income_month' => $monthIncome,
            'expenses_today' => $todayExpenses,
            'expenses_month' => $monthExpenses,
            'orders_today' => $todayOrders,
            'orders_7d' => $orders7,
            'orders_30d' => $orders30,
            'orders_month' => $monthOrders,
            'items_today' => $todayItems,
            'items_7d' => $items7,
            'items_30d' => $items30,
            'items_month' => $monthItems,
            'pending_orders' => $pendingOrders,
            'awaiting_shipment_count' => $awaitingShipmentCount,
            'awaiting_shipment' => $awaitingShipment,
            'top_recent_skus' => $topSkus,
            'active_offer_highlights' => $activeOfferHighlights,
            'daily_trend_30' => array_values($dailyTrend),
            'daily_trend_7' => array_slice(array_values($dailyTrend), -7),
            'payments_loaded' => count($payments),
            'orders_loaded' => count($orders),
            'offers_loaded' => count($activeOffers),
            'today_start' => $todayStart->format(DateTimeInterface::ATOM),
            'month_start' => $monthStart->format(DateTimeInterface::ATOM),
            'days7_start' => $days7Start->format(DateTimeInterface::ATOM),
            'days30_start' => $days30Start->format(DateTimeInterface::ATOM),
        ];
    }

    private function pruneEmptyValues(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $item = $this->pruneEmptyValues($item);
                if ($item === []) {
                    continue;
                }
                $result[$key] = $item;
                continue;
            }
            if ($item === null) {
                continue;
            }
            if (is_string($item) && trim($item) === '') {
                continue;
            }
            $result[$key] = $item;
        }
        return $result;
    }

    private function normalizeProductOffer(array $offer): array
    {
        $offerId = (string)($offer['id'] ?? '');
        $descriptionSections = is_array($offer['description']['sections'] ?? null) ? $offer['description']['sections'] : [];
        return [
            'id' => $offerId,
            'name' => (string)($offer['name'] ?? ''),
            'external_id' => (string)($offer['external']['id'] ?? ''),
            'status' => (string)($offer['publication']['status'] ?? ''),
            'price_amount' => $this->normalizePriceAmount($offer['sellingMode']['price']['amount'] ?? null),
            'price_currency' => (string)($offer['sellingMode']['price']['currency'] ?? 'PLN'),
            'stock_available' => (int)($offer['stock']['available'] ?? 0),
            'stock_unit' => (string)($offer['stock']['unit'] ?? 'UNIT'),
            'category_id' => (string)($offer['category']['id'] ?? ''),
            'format' => (string)($offer['sellingMode']['format'] ?? ''),
            'primary_image_url' => (string)(is_array($offer['images'] ?? null) ? ($offer['images'][0] ?? '') : ''),
            'allegro_url' => $offerId !== '' ? 'https://allegro.pl/oferta/' . rawurlencode($offerId) : '',
            'created_at' => $offer['createdAt'] ?? null,
            'updated_at' => $offer['updatedAt'] ?? null,
            'publication_started_at' => $offer['publication']['startedAt'] ?? null,
            'publication_ended_at' => $offer['publication']['endedAt'] ?? null,
            'publication_starting_at' => $offer['publication']['startingAt'] ?? null,
            'validation' => $offer['validation'] ?? null,
            'warnings' => $offer['warnings'] ?? null,
            'description_sections' => count($descriptionSections),
            'trace_id' => $offer['_trace_id'] ?? null,
        ];
    }

    private function normalizePriceAmount(mixed $amount): string
    {
        if (is_string($amount) && trim($amount) !== '') {
            return number_format((float)str_replace(',', '.', $amount), 2, '.', '');
        }
        if (is_numeric($amount)) {
            return number_format((float)$amount, 2, '.', '');
        }
        return '0.00';
    }

    private function waitForOfferUpdate(string $offerId, ?string $location, mixed $retryAfter): array
    {
        $delay = max(1, min(5, (int)$retryAfter ?: 1));
        if ($location) {
            $path = $this->apiPathFromLocation($location);
            if ($path !== null) {
                for ($attempt = 0; $attempt < 5; $attempt++) {
                    sleep($delay);
                    try {
                        $this->apiRequest('GET', $path);
                        break;
                    } catch (Throwable) {
                        // Fall back to fetching the updated offer below.
                    }
                }
            }
        }

        return $this->getProductOffer($offerId);
    }

    private function apiPathFromLocation(string $location): ?string
    {
        $location = trim($location);
        if ($location === '') {
            return null;
        }
        if (str_starts_with($location, '/')) {
            return $location;
        }
        $base = AllegroConfig::apiBase($this->config);
        if (str_starts_with($location, $base)) {
            return substr($location, strlen($base));
        }
        $parsed = parse_url($location);
        if (!is_array($parsed) || empty($parsed['path'])) {
            return null;
        }
        $path = (string)$parsed['path'];
        if (!empty($parsed['query'])) {
            $path .= '?' . $parsed['query'];
        }
        return $path;
    }

    public function apiRequest(string $method, string $path, ?array $body = null): array
    {
        $headers = [
            'Authorization: Bearer ' . $this->validAccessToken(),
            'Accept: application/vnd.allegro.public.v1+json',
            'User-Agent: ' . $this->config['user_agent'],
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/vnd.allegro.public.v1+json';
        }
        return $this->http($method, AllegroConfig::apiBase($this->config) . $path, $headers, $body === null ? null : json_encode($body));
    }

    private function paginateCollection(string $path, string $collectionKey, array $query = []): array
    {
        $limit = max(1, min(100, (int)($query['limit'] ?? 100)));
        $offset = 0;
        $items = [];
        do {
            $query['limit'] = $limit;
            $query['offset'] = $offset;
            $glue = str_contains($path, '?') ? '&' : '?';
            $response = $this->apiRequest('GET', $path . $glue . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
            $chunk = $response[$collectionKey] ?? [];
            if (!is_array($chunk)) {
                break;
            }
            $items = array_merge($items, $chunk);
            $count = (int)($response['count'] ?? count($chunk));
            $totalCount = (int)($response['totalCount'] ?? count($items));
            $offset += $count > 0 ? $count : count($chunk);
            if (count($chunk) < $limit) {
                break;
            }
        } while ($offset < $totalCount && $offset < 1000);
        return $items;
    }

    private function moneyAmount(mixed $value): ?float
    {
        if (!is_array($value) || !isset($value['amount']) || !is_numeric($value['amount'])) {
            return null;
        }
        return (float)$value['amount'];
    }

    private function moneyCurrency(mixed $value): ?string
    {
        if (!is_array($value) || empty($value['currency'])) {
            return null;
        }
        return (string)$value['currency'];
    }

    private function safeDate(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function requestToken(array $fields): array
    {
        $headers = [
            'Authorization: Basic ' . base64_encode($this->config['client_id'] . ':' . $this->config['client_secret']),
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
            'User-Agent: ' . $this->config['user_agent'],
        ];
        return $this->http('POST', AllegroConfig::authBase($this->config) . '/auth/oauth/token', $headers, http_build_query($fields, '', '&', PHP_QUERY_RFC3986));
    }

    private function saveToken(array $token): void
    {
        if (empty($token['access_token'])) {
            throw new RuntimeException('Token response does not contain access_token.');
        }
        $now = time();
        $token['obtained_at'] = $now;
        $token['expires_at'] = $now + max(60, (int)($token['expires_in'] ?? 3600)) - 60;
        $token['environment'] = $this->config['environment'];
        $this->tokenStore->write($token);
    }

    private function http(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 45,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('HTTP request failed: ' . $err);
        }
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headerRaw = substr($raw, 0, $headerSize);
        $bodyRaw = substr($raw, $headerSize);
        $traceId = null;
        $responseHeaders = [];
        foreach (explode("\n", $headerRaw) as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headerName = strtolower(trim($name));
            $headerValue = trim($value);
            $responseHeaders[$headerName] = $headerValue;
            if ($headerName === 'trace-id') {
                $traceId = $headerValue;
            }
        }
        $decoded = json_decode($bodyRaw, true);
        if (!is_array($decoded)) {
            $decoded = ['raw' => $bodyRaw];
        }
        if ($status < 200 || $status >= 300) {
            $msg = 'Allegro HTTP ' . $status;
            if ($traceId) {
                $msg .= ' Trace-Id=' . $traceId;
            }
            if (isset($decoded['error_description'])) {
                $msg .= ': ' . $decoded['error_description'];
            } elseif (isset($decoded['errors'][0]['userMessage'])) {
                $msg .= ': ' . $decoded['errors'][0]['userMessage'];
            } elseif (isset($decoded['errors'][0]['message'])) {
                $msg .= ': ' . $decoded['errors'][0]['message'];
            }
            throw new RuntimeException($msg);
        }
        if ($traceId) {
            $decoded['_trace_id'] = $traceId;
        }
        $decoded['_status'] = $status;
        $decoded['_headers'] = $responseHeaders;
        return $decoded;
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}

final class WooCommerceClient
{
    public function __construct(private array $config)
    {
    }

    public function listProductsPage(int $page = 1, int $perPage = 25, string $sort = 'modified_desc', ?string $search = null, ?string $sku = null, ?string $status = null): array
    {
        $this->assertConfigured();

        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $search = trim((string)$search);
        $sku = trim((string)$sku);
        $status = strtolower(trim((string)$status));

        $sortOptions = [
            'modified_desc' => ['label' => 'Updated (newest first)', 'orderby' => 'modified', 'order' => 'desc'],
            'date_desc' => ['label' => 'Created (newest first)', 'orderby' => 'date', 'order' => 'desc'],
            'date_asc' => ['label' => 'Created (oldest first)', 'orderby' => 'date', 'order' => 'asc'],
            'title_asc' => ['label' => 'Title (A → Z)', 'orderby' => 'title', 'order' => 'asc'],
            'title_desc' => ['label' => 'Title (Z → A)', 'orderby' => 'title', 'order' => 'desc'],
            'price_asc' => ['label' => 'Price (low to high)', 'orderby' => 'price', 'order' => 'asc'],
            'price_desc' => ['label' => 'Price (high to low)', 'orderby' => 'price', 'order' => 'desc'],
        ];
        if (!isset($sortOptions[$sort])) {
            $sort = 'modified_desc';
        }

        $statusOptions = [
            '' => 'All statuses',
            'publish' => 'Publish',
            'draft' => 'Draft',
            'pending' => 'Pending',
            'private' => 'Private',
        ];
        if ($status !== '' && !isset($statusOptions[$status])) {
            $status = '';
        }

        $query = [
            'page' => $page,
            'per_page' => $perPage,
            'orderby' => $sortOptions[$sort]['orderby'],
            'order' => $sortOptions[$sort]['order'],
        ];
        if ($search !== '') {
            $query['search'] = $search;
        }
        if ($sku !== '') {
            $query['sku'] = $sku;
        }
        if ($status !== '') {
            $query['status'] = $status;
        }

        $response = $this->request('GET', '/products', $query);
        $items = array_map(fn(array $product): array => $this->normalizeProduct($product), $response['items']);
        $totalCount = max(0, (int)($response['headers']['x-wp-total'] ?? count($items)));
        $totalPages = max(1, (int)($response['headers']['x-wp-totalpages'] ?? ceil(max(1, $totalCount) / $perPage)));
        $from = $totalCount > 0 ? (($page - 1) * $perPage) + 1 : 0;
        $to = $totalCount > 0 ? min($page * $perPage, $totalCount) : 0;

        return [
            'items' => $items,
            'sort' => $sort,
            'sort_options' => $sortOptions,
            'filters' => [
                'search' => $search,
                'sku' => $sku,
                'status' => $status,
                'status_options' => $statusOptions,
            ],
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'count' => count($items),
                'total_count' => $totalCount,
                'total_pages' => $totalPages,
                'has_prev' => $page > 1,
                'has_next' => $page < $totalPages,
                'prev_page' => max(1, $page - 1),
                'next_page' => min($totalPages, $page + 1),
                'from' => $from,
                'to' => $to,
            ],
            'meta' => [
                'api_base' => AllegroConfig::wooApiBase($this->config),
            ],
        ];
    }

    public function getProductDetails(int $productId): array
    {
        $this->assertConfigured();
        if ($productId <= 0) {
            throw new InvalidArgumentException('WooCommerce product ID is required.');
        }

        $productResponse = $this->request('GET', '/products/' . $productId);
        $product = $this->normalizeProduct(is_array($productResponse['items']) ? $productResponse['items'] : []);

        $variationsResponse = $this->request('GET', '/products/' . $productId . '/variations', [
            'per_page' => 100,
            'orderby' => 'menu_order',
            'order' => 'asc',
        ]);
        $variations = [];
        foreach ($variationsResponse['items'] as $variation) {
            if (is_array($variation)) {
                $variations[] = $this->normalizeVariation($variation);
            }
        }

        return [
            'product' => $product,
            'variations' => $variations,
            'variation_count' => count($variations),
            'meta' => [
                'api_base' => AllegroConfig::wooApiBase($this->config),
            ],
        ];
    }

    public function probe(): array
    {
        $response = $this->request('GET', '/products', ['page' => 1, 'per_page' => 1, 'orderby' => 'date', 'order' => 'desc']);
        return [
            'ok' => true,
            'product_count' => (int)($response['headers']['x-wp-total'] ?? count($response['items'])),
            'total_pages' => (int)($response['headers']['x-wp-totalpages'] ?? 1),
            'api_base' => AllegroConfig::wooApiBase($this->config),
        ];
    }

    private function assertConfigured(): void
    {
        if (!AllegroConfig::isWooConfigured($this->config)) {
            throw new RuntimeException('WooCommerce settings are incomplete.');
        }
    }

    private function normalizeProduct(array $product): array
    {
        $categories = [];
        foreach (($product['categories'] ?? []) as $category) {
            if (is_array($category) && isset($category['name'])) {
                $categories[] = (string)$category['name'];
            }
        }

        $attributes = [];
        foreach (($product['attributes'] ?? []) as $attribute) {
            if (!is_array($attribute)) {
                continue;
            }
            $options = [];
            foreach (($attribute['options'] ?? []) as $option) {
                $options[] = (string)$option;
            }
            $attributes[] = [
                'id' => (int)($attribute['id'] ?? 0),
                'name' => (string)($attribute['name'] ?? ''),
                'slug' => (string)($attribute['slug'] ?? ''),
                'variation' => !empty($attribute['variation']),
                'visible' => !empty($attribute['visible']),
                'options' => $options,
            ];
        }

        $images = is_array($product['images'] ?? null) ? $product['images'] : [];
        $firstImage = is_array($images[0] ?? null) ? $images[0] : [];
        $dimensions = is_array($product['dimensions'] ?? null) ? $product['dimensions'] : [];

        return [
            'id' => (int)($product['id'] ?? 0),
            'name' => (string)($product['name'] ?? ''),
            'permalink' => (string)($product['permalink'] ?? ''),
            'sku' => (string)($product['sku'] ?? ''),
            'status' => (string)($product['status'] ?? ''),
            'catalog_visibility' => (string)($product['catalog_visibility'] ?? ''),
            'price' => (string)($product['price'] ?? ''),
            'regular_price' => (string)($product['regular_price'] ?? ''),
            'sale_price' => (string)($product['sale_price'] ?? ''),
            'currency' => (string)($product['currency'] ?? ''),
            'stock_status' => (string)($product['stock_status'] ?? ''),
            'stock_quantity' => $product['stock_quantity'] ?? null,
            'manage_stock' => !empty($product['manage_stock']),
            'type' => (string)($product['type'] ?? ''),
            'featured' => !empty($product['featured']),
            'virtual' => !empty($product['virtual']),
            'downloadable' => !empty($product['downloadable']),
            'categories' => $categories,
            'attributes' => $attributes,
            'image_url' => (string)($firstImage['src'] ?? ''),
            'description' => (string)($product['description'] ?? ''),
            'short_description' => (string)($product['short_description'] ?? ''),
            'weight' => (string)($product['weight'] ?? ''),
            'dimensions' => [
                'length' => (string)($dimensions['length'] ?? ''),
                'width' => (string)($dimensions['width'] ?? ''),
                'height' => (string)($dimensions['height'] ?? ''),
            ],
            'updated_at' => $product['date_modified_gmt'] ?? $product['date_modified'] ?? null,
            'created_at' => $product['date_created_gmt'] ?? $product['date_created'] ?? null,
        ];
    }

    private function normalizeVariation(array $variation): array
    {
        $attributes = [];
        foreach (($variation['attributes'] ?? []) as $attribute) {
            if (!is_array($attribute)) {
                continue;
            }
            $attributes[] = [
                'id' => (int)($attribute['id'] ?? 0),
                'name' => (string)($attribute['name'] ?? ''),
                'option' => (string)($attribute['option'] ?? ''),
            ];
        }

        $metaMap = [];
        foreach (($variation['meta_data'] ?? []) as $meta) {
            if (!is_array($meta)) {
                continue;
            }
            $key = trim((string)($meta['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $metaMap[$key] = $meta['value'] ?? null;
        }

        $allegroIdRaw = $metaMap['allegro_id'] ?? null;
        $allegroId = trim((string)(is_scalar($allegroIdRaw) ? $allegroIdRaw : ''));
        $isSyncedToAllegro = $allegroId !== '';

        $dimensions = is_array($variation['dimensions'] ?? null) ? $variation['dimensions'] : [];
        $image = is_array($variation['image'] ?? null) ? $variation['image'] : [];

        return [
            'id' => (int)($variation['id'] ?? 0),
            'sku' => (string)($variation['sku'] ?? ''),
            'status' => (string)($variation['status'] ?? ''),
            'price' => (string)($variation['price'] ?? ''),
            'regular_price' => (string)($variation['regular_price'] ?? ''),
            'sale_price' => (string)($variation['sale_price'] ?? ''),
            'stock_status' => (string)($variation['stock_status'] ?? ''),
            'stock_quantity' => $variation['stock_quantity'] ?? null,
            'manage_stock' => !empty($variation['manage_stock']),
            'on_sale' => !empty($variation['on_sale']),
            'purchasable' => !empty($variation['purchasable']),
            'virtual' => !empty($variation['virtual']),
            'downloadable' => !empty($variation['downloadable']),
            'weight' => (string)($variation['weight'] ?? ''),
            'dimensions' => [
                'length' => (string)($dimensions['length'] ?? ''),
                'width' => (string)($dimensions['width'] ?? ''),
                'height' => (string)($dimensions['height'] ?? ''),
            ],
            'attributes' => $attributes,
            'image_url' => (string)($image['src'] ?? ''),
            'updated_at' => $variation['date_modified_gmt'] ?? $variation['date_modified'] ?? null,
            'created_at' => $variation['date_created_gmt'] ?? $variation['date_created'] ?? null,
            'meta' => $metaMap,
            'allegro_id' => $allegroId,
            'synced_to_allegro' => $isSyncedToAllegro,
            'allegro_frontend_url' => $isSyncedToAllegro ? 'https://allegro.pl/oferta/' . rawurlencode($allegroId) : '',
            'allegro_backend_url' => $isSyncedToAllegro ? '/offer.php?id=' . rawurlencode($allegroId) : '',
        ];
    }

    private function request(string $method, string $path, array $query = []): array
    {
        $base = AllegroConfig::wooApiBase($this->config);
        if ($base === '') {
            throw new RuntimeException('WooCommerce site URL is missing.');
        }
        $url = $base . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $headers = [
            'Accept: application/json',
            'User-Agent: ' . (string)($this->config['user_agent'] ?? 'AllegroManager/0.1'),
        ];
        $auth = base64_encode((string)$this->config['woo_consumer_key'] . ':' . (string)$this->config['woo_consumer_secret']);
        $headers[] = 'Authorization: Basic ' . $auth;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => (int)($this->config['woo_timeout'] ?? 20),
            CURLOPT_SSL_VERIFYPEER => (bool)($this->config['woo_verify_ssl'] ?? true),
            CURLOPT_SSL_VERIFYHOST => (bool)($this->config['woo_verify_ssl'] ?? true) ? 2 : 0,
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $message = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('WooCommerce request failed: ' . $message);
        }

        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headerRaw = substr($raw, 0, $headerSize);
        $bodyRaw = substr($raw, $headerSize);
        $responseHeaders = [];
        foreach (explode("\n", $headerRaw) as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $responseHeaders[strtolower(trim($name))] = trim($value);
        }

        $decoded = json_decode($bodyRaw, true);
        if ($status < 200 || $status >= 300) {
            $message = 'WooCommerce HTTP ' . $status;
            if (is_array($decoded)) {
                $apiMessage = $decoded['message'] ?? $decoded['code'] ?? null;
                if (is_string($apiMessage) && $apiMessage !== '') {
                    $message .= ': ' . $apiMessage;
                }
            }
            throw new RuntimeException($message);
        }

        return [
            'status' => $status,
            'headers' => $responseHeaders,
            'items' => is_array($decoded) ? $decoded : [],
        ];
    }
}

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

function allegro_refresh_dashboard_cache(AllegroClient $client, array $config, ?string $target = null): array
{
    $payload = [
        'generated_at' => gmdate(DateTimeInterface::ATOM),
        'ok' => false,
        'data' => null,
        'error' => null,
    ];

    try {
        if (!AllegroConfig::isConfigured($config)) {
            throw new RuntimeException('Allegro config is incomplete.');
        }
        $token = $client->token();
        $status = allegro_safe_token_status($token);
        if (empty($status['authorized'])) {
            throw new RuntimeException('Allegro token is not authorized.');
        }
        $payload['data'] = $client->dashboardSummary();
        $payload['ok'] = true;
    } catch (Throwable $e) {
        $payload['error'] = $e->getMessage();
    }

    $target ??= dirname(__DIR__) . '/data/dashboard-summary.json';
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Could not encode dashboard cache payload.');
    }
    if (file_put_contents($target, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException("Failed to write {$target}");
    }

    return [
        'target' => $target,
        'ok' => $payload['ok'],
        'generated_at' => $payload['generated_at'],
        'error' => $payload['error'],
        'data' => $payload['data'],
    ];
}

function allegro_dashboard_refresh_state_path(): string
{
    return dirname(__DIR__) . '/data/dashboard-refresh-state.json';
}

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

function allegro_write_dashboard_refresh_state(array $state, ?string $path = null): void
{
    $path ??= allegro_dashboard_refresh_state_path();
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Could not encode dashboard refresh state.');
    }
    if (file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException("Failed to write {$path}");
    }
}
