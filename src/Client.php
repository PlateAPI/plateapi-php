<?php

declare(strict_types=1);

namespace PlateAPI;

class Client
{
    private const VALID_STATES = ['NSW', 'VIC', 'QLD', 'SA', 'WA', 'TAS', 'NT', 'ACT'];

    private string $apiKey;
    private string $baseUrl;
    private int $timeout;
    private int $maxRetries;

    public function __construct(
        string $apiKey,
        string $baseUrl = 'https://api.plateapi.com.au',
        int $timeout = 30,
        int $maxRetries = 3,
    ) {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
        $this->maxRetries = $maxRetries;
    }

    /**
     * @return array{success: bool, vehicle: ?array, alternatives: array, source: ?string,
     *               duration_ms: ?float, sandbox: bool, code: ?string, error: ?string,
     *               request_id: ?string, rate_limit: array}
     */
    public function lookup(string $plate, string $state, bool $detailed = false): array
    {
        $state = strtoupper(trim($state));
        $plate = strtoupper(trim($plate));

        if (!in_array($state, self::VALID_STATES, true)) {
            throw new \InvalidArgumentException(
                "Invalid state '{$state}'. Must be one of: " . implode(', ', self::VALID_STATES)
            );
        }
        if ($plate === '') {
            throw new \InvalidArgumentException('Plate cannot be empty');
        }

        $params = ['plate' => $plate, 'state' => $state];
        if ($detailed) {
            $params['detailed'] = 'true';
        }

        $response = $this->request('/api/v1/lookup', $params, true);
        $body = $response['body'];

        if (isset($body['vehicle']['lowest_year'])) {
            $body['vehicle']['year'] = $body['vehicle']['lowest_year'];
        }
        if (!empty($body['alternatives'])) {
            foreach ($body['alternatives'] as &$alt) {
                if (isset($alt['lowest_year'])) {
                    $alt['year'] = $alt['lowest_year'];
                }
            }
            unset($alt);
        }

        $body['rate_limit'] = $response['rate_limit'];
        return $body;
    }

    /**
     * @param array{make?: string, model?: string, year?: int, series?: string,
     *              engine?: string, variant?: string} $options
     * @return array{success: bool, type: ?string, data: array, total: int, duration_ms: ?float}
     */
    public function vehicles(array $options = []): array
    {
        $params = [];
        foreach (['make', 'model', 'series', 'engine', 'variant'] as $key) {
            if (array_key_exists($key, $options)) {
                $params[$key] = (string) $options[$key];
            }
        }
        if (isset($options['year'])) {
            $params['year'] = (string) $options['year'];
        }

        $response = $this->request('/api/v1/vehicles', $params, true);
        $body = $response['body'];

        $result = [
            'success' => $body['success'] ?? false,
            'type' => null,
            'data' => [],
            'total' => $body['total'] ?? 0,
            'duration_ms' => $body['duration_ms'] ?? null,
        ];

        if (!empty($body['data'][0])) {
            $first = $body['data'][0];
            $result['type'] = $first['type'] ?? null;
            $result['data'] = $first['data'] ?? [];
        }

        return $result;
    }

    /**
     * @return array{email: ?string, plan: ?string, monthly_limit: int, used_this_month: int,
     *               remaining: int, percent_used: ?float, rate_limit_per_min: int,
     *               last_lookup_at: ?string, period_start: ?string, period_end: ?string,
     *               days_remaining: ?int, cancel_at_period_end: bool, cancel_at: ?string,
     *               topup_credits: int}
     */
    public function usage(): array
    {
        $response = $this->request('/api/v1/keys/usage', [], true);
        return $response['body'];
    }

    /**
     * @param array{limit?: int, offset?: int, since?: string, until?: string,
     *              plate?: string, success?: bool} $options
     * @return array{logs: array, count: int, total: int, limit: int, offset: int}
     */
    public function logs(array $options = []): array
    {
        $params = [
            'limit' => (string) ($options['limit'] ?? 100),
            'offset' => (string) ($options['offset'] ?? 0),
        ];
        if (isset($options['since'])) {
            $params['since'] = $options['since'];
        }
        if (isset($options['until'])) {
            $params['until'] = $options['until'];
        }
        if (isset($options['plate'])) {
            $params['plate'] = $options['plate'];
        }
        if (isset($options['success'])) {
            $params['success'] = $options['success'] ? 'true' : 'false';
        }

        $response = $this->request('/api/v1/keys/logs', $params, true);
        return $response['body'];
    }

    /**
     * @return array{status: string, version: ?string}
     */
    public function health(): array
    {
        $response = $this->request('/api/v1/health', [], false);
        return $response['body'];
    }

    /**
     * @return array{body: array, rate_limit: array}
     */
    private function request(string $path, array $params, bool $auth): array
    {
        $url = $this->baseUrl . $path;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $lastError = new PlateAPIException('Request failed');

        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HEADER => true,
            ]);

            $headers = ['Accept: application/json'];
            if ($auth) {
                $headers[] = 'X-API-Key: ' . $this->apiKey;
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            $raw = curl_exec($ch);

            if ($raw === false) {
                $error = curl_error($ch);
                curl_close($ch);
                $lastError = new PlateAPIException("Request failed: {$error}");
                if ($attempt < $this->maxRetries) {
                    $this->sleep($this->backoffDelay($attempt));
                    continue;
                }
                throw $lastError;
            }

            $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);

            $rawHeaders = substr((string) $raw, 0, $headerSize);
            $rawBody = substr((string) $raw, $headerSize);

            $parsedHeaders = $this->parseHeaders($rawHeaders);
            $body = json_decode($rawBody, true) ?? [];

            $retryAfter = isset($parsedHeaders['retry-after'])
                ? (float) $parsedHeaders['retry-after']
                : null;

            $rateLimit = [
                'limit' => isset($parsedHeaders['x-ratelimit-limit']) && $parsedHeaders['x-ratelimit-limit'] !== 'unlimited'
                    ? (int) $parsedHeaders['x-ratelimit-limit'] : null,
                'remaining' => isset($parsedHeaders['x-ratelimit-remaining']) && $parsedHeaders['x-ratelimit-remaining'] !== 'unlimited'
                    ? (int) $parsedHeaders['x-ratelimit-remaining'] : null,
                'plan' => $parsedHeaders['x-ratelimit-plan'] ?? null,
            ];

            if ($statusCode === 401) {
                throw new AuthenticationException();
            }

            if ($statusCode === 429) {
                $code = $body['code'] ?? '';
                if ($code === 'quota_exceeded') {
                    throw new QuotaExceededException();
                }
                if ($attempt < $this->maxRetries) {
                    $wait = $retryAfter ?? $this->backoffSeconds($attempt);
                    $this->sleep($wait);
                    continue;
                }
                throw new RateLimitException('Rate limit exceeded', $retryAfter);
            }

            if ($statusCode >= 500) {
                if ($attempt < $this->maxRetries) {
                    $wait = $retryAfter ?? $this->backoffSeconds($attempt);
                    $this->sleep($wait);
                    continue;
                }
                throw new ServerException("Server error ({$statusCode})", $statusCode, $retryAfter);
            }

            if ($statusCode >= 400) {
                $message = $body['detail'] ?? $body['error'] ?? 'Request failed';
                throw new PlateAPIException($message, $statusCode);
            }

            return ['body' => $body, 'rate_limit' => $rateLimit];
        }

        throw $lastError;
    }

    private function parseHeaders(string $raw): array
    {
        $headers = [];
        foreach (explode("\r\n", $raw) as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }
        return $headers;
    }

    private function backoffSeconds(int $attempt): float
    {
        return pow(2, $attempt) + (mt_rand(0, 500) / 1000);
    }

    private function backoffDelay(int $attempt): float
    {
        return $this->backoffSeconds($attempt);
    }

    private function sleep(float $seconds): void
    {
        usleep((int) ($seconds * 1_000_000));
    }
}
