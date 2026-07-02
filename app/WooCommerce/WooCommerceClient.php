<?php
declare(strict_types=1);

namespace App\WooCommerce;

use App\Allegro\AllegroConfig;
use InvalidArgumentException;
use RuntimeException;

/**
 * WooCommerce REST API client for product management.
 */
final class WooCommerceClient
{
    public function __construct(private array $config)
    {
    }

    /**
     * List products with pagination, sorting, and filtering.
     */
    public function listProductsPage(
        int $page = 1,
        int $perPage = 25,
        string $sort = 'modified_desc',
        ?string $search = null,
        ?string $sku = null,
        ?string $status = null
    ): array {
        $this->assertConfigured();

        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $search = trim((string)$search);
        $sku = trim((string)$sku);
        $status = strtolower(trim((string)$status));

        $sortOptions = $this->getSortOptions();
        $statusOptions = $this->getStatusOptions();

        if (!isset($sortOptions[$sort])) {
            $sort = 'modified_desc';
        }
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
        $items = array_map(
            fn(array $product): array => ProductNormalizer::normalizeProduct($product),
            $response['items']
        );
        
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

    /**
     * Get full product details including variations.
     */
    public function getProductDetails(int $productId): array
    {
        $this->assertConfigured();
        if ($productId <= 0) {
            throw new InvalidArgumentException('WooCommerce product ID is required.');
        }

        $productResponse = $this->request('GET', '/products/' . $productId);
        $product = ProductNormalizer::normalizeProduct(
            is_array($productResponse['items']) ? $productResponse['items'] : []
        );

        $variationsResponse = $this->request('GET', '/products/' . $productId . '/variations', [
            'per_page' => 100,
            'orderby' => 'menu_order',
            'order' => 'asc',
        ]);
        
        $variations = [];
        foreach ($variationsResponse['items'] as $variation) {
            if (is_array($variation)) {
                $variations[] = ProductNormalizer::normalizeVariation($variation);
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

    /**
     * Probe WooCommerce connection.
     */
    public function probe(): array
    {
        $response = $this->request('GET', '/products', [
            'page' => 1,
            'per_page' => 1,
            'orderby' => 'date',
            'order' => 'desc',
        ]);
        
        return [
            'ok' => true,
            'product_count' => (int)($response['headers']['x-wp-total'] ?? count($response['items'])),
            'total_pages' => (int)($response['headers']['x-wp-totalpages'] ?? 1),
            'api_base' => AllegroConfig::wooApiBase($this->config),
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

    private function assertConfigured(): void
    {
        if (!AllegroConfig::isWooConfigured($this->config)) {
            throw new RuntimeException('WooCommerce settings are incomplete.');
        }
    }

    private function getSortOptions(): array
    {
        return [
            'modified_desc' => ['label' => 'Updated (newest first)', 'orderby' => 'modified', 'order' => 'desc'],
            'date_desc' => ['label' => 'Created (newest first)', 'orderby' => 'date', 'order' => 'desc'],
            'date_asc' => ['label' => 'Created (oldest first)', 'orderby' => 'date', 'order' => 'asc'],
            'title_asc' => ['label' => 'Title (A → Z)', 'orderby' => 'title', 'order' => 'asc'],
            'title_desc' => ['label' => 'Title (Z → A)', 'orderby' => 'title', 'order' => 'desc'],
            'price_asc' => ['label' => 'Price (low to high)', 'orderby' => 'price', 'order' => 'asc'],
            'price_desc' => ['label' => 'Price (high to low)', 'orderby' => 'price', 'order' => 'desc'],
        ];
    }

    private function getStatusOptions(): array
    {
        return [
            '' => 'All statuses',
            'publish' => 'Publish',
            'draft' => 'Draft',
            'pending' => 'Pending',
            'private' => 'Private',
        ];
    }
}
