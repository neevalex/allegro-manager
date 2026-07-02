<?php
declare(strict_types=1);

namespace App\Allegro\Http;

use Throwable;

/**
 * HTTP client wrapper around cURL.
 * 
 * Handles requests/responses, status codes, headers, and JSON parsing.
 */
final class HttpClient
{
    public function __construct(
        private int $timeout = 45,
    ) {
    }

    /**
     * Execute an HTTP request.
     * 
     * @param string $method HTTP method (GET, POST, PATCH, etc.)
     * @param string $url Full URL to request
     * @param string[] $headers Request headers
     * @param string|null $body Request body (JSON)
     * 
     * @return array Response data with status, headers, and decoded body
     * @throws \RuntimeException on network or HTTP errors
     */
    public function request(string $method, string $url, array $headers, ?string $body = null): array
    {
        $ch = curl_init($url);
        
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);
        
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('HTTP request failed: ' . $err);
        }
        
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        
        return $this->parseResponse($status, $raw, $headerSize);
    }

    private function parseResponse(int $status, string $raw, int $headerSize): array
    {
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
        
        return [
            'status' => $status,
            'headers' => $responseHeaders,
            'data' => $decoded,
            'trace_id' => $traceId,
        ];
    }
}
