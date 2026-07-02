<?php
declare(strict_types=1);

namespace App\Allegro\Offers;

use App\Allegro\AllegroClient;
use InvalidArgumentException;

/**
 * Handles Allegro offers API operations.
 */
final class OffersService
{
    public function __construct(private AllegroClient $client)
    {
    }

    /**
     * List offers with pagination, sorting, and filtering.
     */
    public function listPage(
        int $page = 1,
        int $perPage = 25,
        string $sort = 'created_desc',
        ?string $titleQuery = null,
        ?string $skuQuery = null,
        ?string $statusFilter = null
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $titleQuery = trim((string)$titleQuery);
        $skuQuery = trim((string)$skuQuery);
        $statusFilter = strtoupper(trim((string)$statusFilter));

        $sortOptions = $this->getSortOptions();
        $statusOptions = $this->getStatusOptions();

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

        $response = $this->client->apiRequest('GET', '/sale/offers?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        
        $rawOffers = is_array($response['offers'] ?? null) ? $response['offers'] : [];
        $items = OfferNormalizer::normalizeListOffers($rawOffers);

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

    /**
     * Get full product offer details.
     */
    public function getProduct(string $offerId): array
    {
        $offerId = trim($offerId);
        if ($offerId === '') {
            throw new InvalidArgumentException('Offer ID is required.');
        }
        $response = $this->client->apiRequest('GET', '/sale/product-offers/' . rawurlencode($offerId));
        return OfferNormalizer::normalizeProductOffer($response);
    }

    /**
     * Update a product offer.
     */
    public function updateProduct(string $offerId, array $input): array
    {
        $offerId = trim($offerId);
        if ($offerId === '') {
            throw new InvalidArgumentException('Offer ID is required.');
        }

        $current = $this->client->apiRequest('GET', '/sale/product-offers/' . rawurlencode($offerId));
        $payload = $this->buildUpdatePayload($input, $current);

        if ($payload === []) {
            throw new InvalidArgumentException('No editable offer fields were provided.');
        }

        $response = $this->client->apiRequest('PATCH', '/sale/product-offers/' . rawurlencode($offerId), $payload);
        $latest = $this->waitForOfferUpdate($offerId, $response['_headers']['location'] ?? null, $response['_headers']['retry-after'] ?? null);

        return [
            'submitted' => $payload,
            'status_code' => (int)($response['_status'] ?? 200),
            'trace_id' => $response['_trace_id'] ?? ($latest['trace_id'] ?? null),
            'offer' => $latest,
        ];
    }

    /**
     * Create a new product offer.
     */
    public function create(array $payload): array
    {
        if ($payload === []) {
            throw new InvalidArgumentException('Offer payload is empty.');
        }

        $response = $this->client->apiRequest('POST', '/sale/product-offers', $payload);
        $offerId = trim((string)($response['id'] ?? ''));
        $offer = null;

        if ($offerId !== '') {
            try {
                $offer = $this->waitForOfferUpdate($offerId, $response['_headers']['location'] ?? null, $response['_headers']['retry-after'] ?? null);
            } catch (\Throwable) {
                $offer = null;
            }
        }

        if ($offer === null && isset($response['name'])) {
            $offer = OfferNormalizer::normalizeProductOffer($response);
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

    /**
     * Get latest offer as a creation template.
     */
    public function getLatestAsTemplate(): ?array
    {
        $response = $this->client->apiRequest('GET', '/sale/offers?' . http_build_query([
            'limit' => 1,
            'offset' => 0,
        ], '', '&', PHP_QUERY_RFC3986));
        
        $offers = is_array($response['offers'] ?? null) ? $response['offers'] : [];
        $offerId = trim((string)(is_array($offers[0] ?? null) ? ($offers[0]['id'] ?? '') : ''));
        
        if ($offerId === '') {
            return null;
        }

        $raw = $this->client->apiRequest('GET', '/sale/product-offers/' . rawurlencode($offerId));
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
            'category' => ['id' => trim((string)($raw['category']['id'] ?? ''))],
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

    private function getSortOptions(): array
    {
        return [
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
    }

    private function getStatusOptions(): array
    {
        return [
            '' => 'All statuses',
            'ACTIVE' => 'ACTIVE',
            'INACTIVE' => 'INACTIVE',
            'ENDED' => 'ENDED',
            'ACTIVATING' => 'ACTIVATING',
        ];
    }

    private function buildUpdatePayload(array $input, array $current): array
    {
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
                    'amount' => OfferNormalizer::normalizePriceAmount($input['price_amount']),
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

        return $payload;
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
                        $this->client->apiRequest('GET', $path);
                        break;
                    } catch (\Throwable) {
                        // Fall back to fetching the updated offer below.
                    }
                }
            }
        }

        return $this->getProduct($offerId);
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
}
