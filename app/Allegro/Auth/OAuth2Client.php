<?php
declare(strict_types=1);

namespace App\Allegro\Auth;

use App\Allegro\AllegroConfig;
use App\Allegro\Http\HttpClient;
use App\Allegro\JsonStore;
use Throwable;

/**
 * OAuth 2.0 PKCE flow implementation for Allegro API.
 */
final class OAuth2Client
{
    private HttpClient $http;

    public function __construct(
        private array $config,
        private JsonStore $tokenStore,
        private JsonStore $stateStore,
    ) {
        $this->http = new HttpClient();
    }

    /**
     * Generate authorization URL for OAuth flow.
     */
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

    /**
     * Handle OAuth callback and exchange code for token.
     * 
     * @throws \RuntimeException on state validation failure or expired state
     */
    public function handleCallback(string $code, string $state): array
    {
        $saved = $this->stateStore->read();
        if (!$saved || empty($saved['state']) || !hash_equals((string)$saved['state'], $state)) {
            throw new \RuntimeException('OAuth state mismatch. Start authorization again.');
        }
        if (($saved['created_at'] ?? 0) < time() - 900) {
            throw new \RuntimeException('OAuth state expired. Start authorization again.');
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

    /**
     * Get valid access token, refreshing if necessary.
     * 
     * @throws \RuntimeException if not authorized or token refresh fails
     */
    public function validAccessToken(): string
    {
        $token = $this->tokenStore->read();
        if (!$token || empty($token['access_token'])) {
            throw new \RuntimeException('Not authorized with Allegro yet.');
        }
        
        if (($token['expires_at'] ?? 0) <= time() + 120) {
            if (empty($token['refresh_token'])) {
                throw new \RuntimeException('Token is expired and no refresh token is available. Re-authorize.');
            }
            $this->refresh();
            $token = $this->tokenStore->read();
        }
        
        return (string)$token['access_token'];
    }

    /**
     * Manually refresh the access token.
     * 
     * @throws \RuntimeException if no refresh token available
     */
    public function refresh(): array
    {
        $token = $this->tokenStore->read();
        if (!$token || empty($token['refresh_token'])) {
            throw new \RuntimeException('No refresh token is saved. Authorize first.');
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

    /**
     * Get the current stored token.
     */
    public function getToken(): ?array
    {
        return $this->tokenStore->read();
    }

    /**
     * Clear the stored token.
     */
    public function clearToken(): void
    {
        $this->tokenStore->delete();
    }

    private function requestToken(array $fields): array
    {
        $headers = [
            'Authorization: Basic ' . base64_encode($this->config['client_id'] . ':' . $this->config['client_secret']),
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
            'User-Agent: ' . $this->config['user_agent'],
        ];
        
        $response = $this->http->request(
            'POST',
            AllegroConfig::authBase($this->config) . '/auth/oauth/token',
            $headers,
            http_build_query($fields, '', '&', PHP_QUERY_RFC3986)
        );
        
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $msg = 'Allegro token request failed: HTTP ' . $response['status'];
            if (isset($response['data']['error_description'])) {
                $msg .= ' - ' . $response['data']['error_description'];
            }
            throw new \RuntimeException($msg);
        }
        
        return $response['data'];
    }

    private function saveToken(array $token): void
    {
        if (empty($token['access_token'])) {
            throw new \RuntimeException('Token response does not contain access_token.');
        }
        $now = time();
        $token['obtained_at'] = $now;
        $token['expires_at'] = $now + max(60, (int)($token['expires_in'] ?? 3600)) - 60;
        $token['environment'] = $this->config['environment'];
        $this->tokenStore->write($token);
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
