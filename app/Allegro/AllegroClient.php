<?php
declare(strict_types=1);

namespace App\Allegro;

use App\Allegro\Auth\OAuth2Client;
use App\Allegro\Dashboard\DashboardService;
use App\Allegro\Http\HttpClient;
use App\Allegro\Offers\OffersService;
use Throwable;

/**
 * Main Allegro API client orchestrator.
 * 
 * Coordinates OAuth2, API requests, and service layer access.
 */
final class AllegroClient
{
    private HttpClient $http;
    private OAuth2Client $oauth;
    private OffersService $offers;
    private DashboardService $dashboard;

    public function __construct(private array $config)
    {
        $dataDir = dirname(__DIR__, 2) . '/data';
        
        $this->http = new HttpClient();
        $this->oauth = new OAuth2Client(
            $config,
            new JsonStore($dataDir . '/tokens.json'),
            new JsonStore($dataDir . '/oauth-state.json')
        );
        $this->offers = new OffersService($this);
        $this->dashboard = new DashboardService($this);
    }

    // OAuth2 delegation
    public function getAuthorizationUrl(): string
    {
        return $this->oauth->authorizationUrl();
    }

    public function handleOAuthCallback(string $code, string $state): array
    {
        return $this->oauth->handleCallback($code, $state);
    }

    public function refreshToken(): array
    {
        return $this->oauth->refresh();
    }

    public function getToken(): ?array
    {
        return $this->oauth->getToken();
    }

    public function clearToken(): void
    {
        $this->oauth->clearToken();
    }

    // Offers delegation
    public function offers(): OffersService
    {
        return $this->offers;
    }

    // Dashboard delegation
    public function dashboard(): DashboardService
    {
        return $this->dashboard;
    }

    // Core API methods
    public function me(): array
    {
        return $this->apiRequest('GET', '/me');
    }

    /**
     * Execute a raw API request.
     * 
     * @throws \RuntimeException on HTTP error or authentication failure
     */
    public function apiRequest(string $method, string $path, ?array $body = null): array
    {
        $token = $this->oauth->validAccessToken();
        
        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/vnd.allegro.public.v1+json',
            'User-Agent: ' . $this->config['user_agent'],
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/vnd.allegro.public.v1+json';
        }

        $response = $this->http->request(
            $method,
            AllegroConfig::apiBase($this->config) . $path,
            $headers,
            $body === null ? null : json_encode($body)
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            $this->throwApiError($response);
        }

        return $this->buildApiResponse($response);
    }

    private function throwApiError(array $response): void
    {
        $msg = 'Allegro HTTP ' . $response['status'];
        if ($response['trace_id']) {
            $msg .= ' Trace-Id=' . $response['trace_id'];
        }

        $data = $response['data'];
        if (isset($data['error_description'])) {
            $msg .= ': ' . $data['error_description'];
        } elseif (isset($data['errors'][0]['userMessage'])) {
            $msg .= ': ' . $data['errors'][0]['userMessage'];
        } elseif (isset($data['errors'][0]['message'])) {
            $msg .= ': ' . $data['errors'][0]['message'];
        }

        throw new \RuntimeException($msg);
    }

    private function buildApiResponse(array $response): array
    {
        $result = $response['data'];
        $result['_status'] = $response['status'];
        $result['_headers'] = $response['headers'];
        if ($response['trace_id']) {
            $result['_trace_id'] = $response['trace_id'];
        }
        return $result;
    }

    // Backward compatibility wrappers (old monolithic API)
    public function authorizationUrl(): string
    {
        return $this->getAuthorizationUrl();
    }

    public function handleCallback(string $code, string $state): array
    {
        return $this->handleOAuthCallback($code, $state);
    }

    public function token(): ?array
    {
        return $this->getToken();
    }

    public function refreshNow(): array
    {
        return $this->refreshToken();
    }

    public function listOffersPage(
        int $page = 1,
        int $limit = 100,
        string $sort = 'newest',
        string $titleQuery = '',
        string $skuQuery = '',
        string $statusFilter = ''
    ): array {
        return $this->offers->listPage($page, $limit, $sort, $titleQuery, $skuQuery, $statusFilter);
    }

    public function getOfferProduct(string $offerId): ?array
    {
        try {
            return $this->offers->getProduct($offerId);
        } catch (Throwable) {
            return null;
        }
    }

    public function updateOfferProduct(string $offerId, array $product): bool
    {
        try {
            $this->offers->updateProduct($offerId, $product);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function createOffer(array $offer): ?array
    {
        try {
            return $this->offers->create($offer);
        } catch (Throwable) {
            return null;
        }
    }

    public function getLatestOfferAsTemplate(): ?array
    {
        try {
            return $this->offers->getLatestAsTemplate();
        } catch (Throwable) {
            return null;
        }
    }

    public function getDashboardSummary(): array
    {
        return $this->dashboard->getSummary();
    }
}
