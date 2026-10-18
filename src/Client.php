<?php

declare(strict_types=1);

namespace PlateAPI;

class Client
{
    public const VERSION = '0.2.0';
    public const DEFAULT_BASE_URL = 'https://api.plateapi.com.au';
    public const DEFAULT_WALK_DELAY = 0.15;

    private const VALID_STATES = ['NSW', 'VIC', 'QLD', 'SA', 'WA', 'TAS', 'NT', 'ACT'];
    private const VALID_VEHICLE_TYPES = ['car', 'motorcycle'];
    private const INITIAL_BACKOFF = 1.0;

    private string $apiKey;
    private string $baseUrl;
    private int $timeout;
    private int $maxRetries;
    private ?float $maxWait;
    private ?\Closure $handler;

    /**
     * @param ?float $maxWait longest Retry-After (seconds) the SDK will sleep; null waits any length
     * @param ?callable $handler HTTP handler for tests or proxies:
     *        fn(string $url, string[] $headers, int $timeout): array{status: int, headers: array, body: string}
     *        It throws \RuntimeException on a connection failure or timeout.
     */
    public function __construct(
        string $apiKey,
        string $baseUrl = self::DEFAULT_BASE_URL,
        int $timeout = 30,
        int $maxRetries = 3,
        ?float $maxWait = 60.0,
        ?callable $handler = null,
    ) {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('apiKey must be a non-empty string');
        }
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
        $this->maxRetries = $maxRetries;
        $this->maxWait = $maxWait;
        $this->handler = $handler === null ? null : \Closure::fromCallable($handler);
    }

    // ---- lookups ----

    /**
     * @return array{success: bool, vehicle: ?array, alternatives: array, match: ?string,
     *               candidate_ids: int[], source: ?string, duration_ms: ?float, sandbox: bool,
     *               code: ?string, error: ?string, request_id: ?string, rate_limit: array}
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

        $attempt = 0;
        while (true) {
            $response = $this->request('/api/v1/lookup', $params, true);
            $body = $response['body'];
            // internal_error is a 200 that is not billed; one more try usually succeeds.
            if (($body['success'] ?? null) === false
                && ($body['code'] ?? null) === 'internal_error'
                && $attempt < $this->maxRetries) {
                $this->sleep($this->backoffSeconds($attempt));
                $attempt++;
                continue;
            }
            break;
        }

        $body['success'] = ($body['success'] ?? false) === true;
        $body['vehicle'] = isset($body['vehicle']) && is_array($body['vehicle']) ? $body['vehicle'] : null;
        $body['alternatives'] = isset($body['alternatives']) && is_array($body['alternatives'])
            ? array_values(array_filter($body['alternatives'], 'is_array'))
            : [];
        $body['match'] = isset($body['match']) && is_string($body['match']) ? $body['match'] : null;
        $body['sandbox'] = ($body['sandbox'] ?? false) === true;
        $body['code'] = $body['code'] ?? null;
        $body['error'] = $body['error'] ?? null;
        $body['source'] = $body['source'] ?? null;
        $body['duration_ms'] = $body['duration_ms'] ?? null;

        if ($body['vehicle'] !== null) {
            $body['vehicle'] = $this->normaliseVehicle($body['vehicle']);
        }
        foreach ($body['alternatives'] as $i => $alt) {
            $body['alternatives'][$i] = $this->normaliseVehicle($alt);
        }

        $ids = [];
        foreach (array_merge([$body['vehicle']], $body['alternatives']) as $v) {
            if ($v !== null && $v['vehicle_id'] !== null && !in_array($v['vehicle_id'], $ids, true)) {
                $ids[] = $v['vehicle_id'];
            }
        }
        $body['candidate_ids'] = $ids;

        if (!isset($body['request_id']) || !is_string($body['request_id'])) {
            $body['request_id'] = $response['headers']['x-request-id'] ?? null;
        }
        $body['rate_limit'] = $response['rate_limit'];
        return $body;
    }

    private function normaliseVehicle(array $vehicle): array
    {
        $vehicle['vehicle_id'] = isset($vehicle['vehicle_id']) && is_int($vehicle['vehicle_id'])
            ? $vehicle['vehicle_id'] : null;
        if (isset($vehicle['lowest_year'])) {
            $vehicle['year'] = $vehicle['lowest_year'];
        } elseif (!array_key_exists('year', $vehicle)) {
            $vehicle['year'] = null;
        }
        return $vehicle;
    }

    // ---- vehicle database ----

    /**
     * @param array{make?: string, model?: string, year?: int, series?: string,
     *              engine?: string, variant?: string, vehicle_type?: string} $options
     * @return array{success: bool, type: ?string, data: array, vehicle_ids: int[], total: int, duration_ms: ?float}
     */
    public function vehicles(array $options = []): array
    {
        $params = [];
        if (array_key_exists('vehicle_type', $options) && $options['vehicle_type'] !== null) {
            if (!in_array($options['vehicle_type'], self::VALID_VEHICLE_TYPES, true)) {
                throw new \InvalidArgumentException(
                    "Invalid vehicle_type '{$options['vehicle_type']}'. Must be one of: "
                    . implode(', ', self::VALID_VEHICLE_TYPES)
                );
            }
            $params['type'] = $options['vehicle_type'];
        }
        foreach (['make', 'model'] as $key) {
            if (array_key_exists($key, $options) && $options[$key] !== null) {
                $params[$key] = (string) $options[$key];
            }
        }
        if (isset($options['year'])) {
            $params['year'] = (string) $options['year'];
        }
        foreach (['series', 'engine', 'variant'] as $key) {
            if (array_key_exists($key, $options) && $options[$key] !== null) {
                $params[$key] = (string) $options[$key];
            }
        }

        $response = $this->request('/api/v1/vehicles', $params, true);
        $body = $response['body'];

        $result = [
            'success' => ($body['success'] ?? false) === true,
            'type' => null,
            'data' => [],
            'vehicle_ids' => [],
            'total' => $body['total'] ?? 0,
            'duration_ms' => $body['duration_ms'] ?? null,
        ];

        if (!empty($body['data'][0]) && is_array($body['data'][0])) {
            $first = $body['data'][0];
            $result['type'] = $first['type'] ?? null;
            $result['data'] = isset($first['data']) && is_array($first['data']) ? $first['data'] : [];
            foreach ($result['data'] as $item) {
                if (is_array($item) && isset($item['vehicle_id']) && is_int($item['vehicle_id'])) {
                    $result['vehicle_ids'][] = $item['vehicle_id'];
                }
            }
        }

        return $result;
    }

    /**
     * One vehicle record by vehicle_id (GET /api/v1/vehicles/{vehicle_id}).
     *
     * @return array{vehicle_id: int, vehicle_type: string, make: string, model: string,
     *               year_range: ?string, lowest_year: ?int, highest_year: ?int, years: int[],
     *               description: string, long_description: ?string, series: ?string,
     *               engine: ?string, variant: ?string, body: ?string, body_size: ?string,
     *               doors: ?int, drive: ?string, transmission: ?string, detail: string}
     */
    public function vehicleById(int|string $vehicleId): array
    {
        if (is_string($vehicleId)) {
            if ($vehicleId === '' || !ctype_digit($vehicleId)) {
                throw new \InvalidArgumentException('vehicle_id must be a non-negative integer');
            }
            $vehicleId = (int) $vehicleId;
        } elseif ($vehicleId < 0) {
            throw new \InvalidArgumentException('vehicle_id must be a non-negative integer');
        }

        $response = $this->request('/api/v1/vehicles/' . $vehicleId, [], true);
        $vehicle = $response['body']['vehicle'] ?? [];
        if (!is_array($vehicle)) {
            $vehicle = [];
        }
        foreach (['vehicle_id', 'vehicle_type', 'make', 'model', 'year_range', 'lowest_year',
                  'highest_year', 'description', 'long_description', 'series', 'engine', 'variant',
                  'body', 'body_size', 'doors', 'drive', 'transmission', 'detail'] as $key) {
            $vehicle[$key] = $vehicle[$key] ?? null;
        }
        $vehicle['years'] = isset($vehicle['years']) && is_array($vehicle['years']) ? $vehicle['years'] : [];
        return $vehicle;
    }

    /**
     * Walk the cascade for one make and model (and its trims) and yield each vehicle once.
     *
     * @param array{trims?: bool, year_from?: int, year_to?: int, engine_contains?: string,
     *              vehicle_type?: string, per_year?: bool, delay?: float} $options
     * @param ?array $knownIds IDs already handled, as a list of ints (or of [id, year] pairs with
     *               per_year); they are not yielded again and the list is appended to as the walk goes
     * @return \Generator<int, array{vehicle_id: int, make: string, model: string, year: int,
     *               series: string, engine: string, variant: string, description: ?string,
     *               long_description: ?string, vehicle_type: string, raw: array}>
     */
    public function walkVehicles(string $make, string $model, array $options = [], ?array &$knownIds = null): \Generator
    {
        if (trim($make) === '') {
            throw new \InvalidArgumentException('make must be a non-empty string');
        }
        if (trim($model) === '') {
            throw new \InvalidArgumentException('model must be a non-empty string');
        }
        $yearFrom = $options['year_from'] ?? null;
        $yearTo = $options['year_to'] ?? null;
        if ($yearFrom !== null && $yearTo !== null && (int) $yearFrom > (int) $yearTo) {
            throw new \InvalidArgumentException('year_from must not be greater than year_to');
        }
        $delay = $options['delay'] ?? self::DEFAULT_WALK_DELAY;
        if ($delay < 0) {
            throw new \InvalidArgumentException('delay must not be negative');
        }
        $vehicleType = $options['vehicle_type'] ?? null;
        if ($vehicleType !== null && !in_array($vehicleType, self::VALID_VEHICLE_TYPES, true)) {
            throw new \InvalidArgumentException(
                "Invalid vehicle_type '{$vehicleType}'. Must be one of: " . implode(', ', self::VALID_VEHICLE_TYPES)
            );
        }
        $trims = $options['trims'] ?? true;
        $perYear = $options['per_year'] ?? false;
        $needle = isset($options['engine_contains']) && $options['engine_contains'] !== ''
            ? strtoupper((string) $options['engine_contains']) : null;

        if ($knownIds === null) {
            $knownIds = [];
        }
        $seen = [];
        foreach ($knownIds as $known) {
            if ($perYear && is_array($known) && count($known) === 2) {
                $seen[$known[0] . ':' . $known[1]] = true;
            } elseif (!$perYear && !is_array($known)) {
                $seen[(string) $known] = true;
            }
        }

        $fetch = function (array $params) use ($vehicleType, $delay): array {
            if ($vehicleType !== null) {
                $params['vehicle_type'] = $vehicleType;
            }
            $result = $this->vehicles($params);
            if ($delay > 0) {
                $this->sleep((float) $delay);
            }
            return $result['data'];
        };

        if ($trims) {
            $models = [];
            foreach ($fetch(['make' => $make]) as $m) {
                if (is_string($m) && ($m === $model || str_starts_with($m, $model . ' '))) {
                    $models[] = $m;
                }
            }
        } else {
            $models = [$model];
        }

        foreach ($models as $modelName) {
            foreach ($fetch(['make' => $make, 'model' => $modelName]) as $yearValue) {
                if (!is_numeric($yearValue)) {
                    continue;
                }
                $year = (int) $yearValue;
                if ($yearFrom !== null && $year < (int) $yearFrom) {
                    continue;
                }
                if ($yearTo !== null && $year > (int) $yearTo) {
                    continue;
                }

                foreach ($fetch(['make' => $make, 'model' => $modelName, 'year' => $year]) as $series) {
                    $series = is_string($series) ? $series : (string) $series;
                    $engines = $fetch(['make' => $make, 'model' => $modelName, 'year' => $year, 'series' => $series]);
                    foreach ($engines as $engine) {
                        $engine = is_string($engine) ? $engine : (string) $engine;
                        if ($needle !== null && !str_contains(strtoupper($engine), $needle)) {
                            continue;
                        }
                        $variants = $fetch([
                            'make' => $make, 'model' => $modelName, 'year' => $year,
                            'series' => $series, 'engine' => $engine,
                        ]);
                        foreach ($variants as $variant) {
                            $variant = is_string($variant) ? $variant : (string) $variant;
                            $records = $fetch([
                                'make' => $make, 'model' => $modelName, 'year' => $year,
                                'series' => $series, 'engine' => $engine, 'variant' => $variant,
                            ]);
                            foreach ($records as $record) {
                                if (!is_array($record) || !isset($record['vehicle_id']) || !is_int($record['vehicle_id'])) {
                                    continue;
                                }
                                $vehicleId = $record['vehicle_id'];
                                $key = $perYear ? $vehicleId . ':' . $year : (string) $vehicleId;
                                if (isset($seen[$key])) {
                                    continue;
                                }
                                $seen[$key] = true;
                                $knownIds[] = $perYear ? [$vehicleId, $year] : $vehicleId;
                                yield [
                                    'vehicle_id' => $vehicleId,
                                    'make' => $make,
                                    'model' => $modelName,
                                    'year' => $year,
                                    'series' => $series,
                                    'engine' => $engine,
                                    'variant' => isset($record['select_text']) && is_string($record['select_text'])
                                        ? $record['select_text'] : $variant,
                                    'description' => $record['description'] ?? null,
                                    'long_description' => $record['long_description'] ?? null,
                                    'vehicle_type' => $vehicleType ?? 'car',
                                    'raw' => $record,
                                ];
                            }
                        }
                    }
                }
            }
        }
    }

    // ---- account ----

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
        $response = $this->request('/api/v1/health', [], false, 0);
        $body = $response['body'];
        return ['status' => $body['status'] ?? 'unknown', 'version' => $body['version'] ?? null];
    }

    // ---- transport ----

    /**
     * @return array{body: array, headers: array, rate_limit: array}
     */
    private function request(string $path, array $params, bool $auth, ?int $maxRetries = null): array
    {
        $url = $this->baseUrl . $path;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }
        $retries = $maxRetries ?? $this->maxRetries;

        $headers = ['Accept: application/json', 'User-Agent: plateapi-php/' . self::VERSION];
        if ($auth) {
            $headers[] = 'X-API-Key: ' . $this->apiKey;
        }

        for ($attempt = 0; ; $attempt++) {
            try {
                $raw = $this->send($url, $headers);
            } catch (\RuntimeException $e) {
                if ($attempt < $retries) {
                    $this->sleep($this->backoffSeconds($attempt));
                    continue;
                }
                throw new PlateAPIException($e->getMessage(), 0, $e);
            }

            $statusCode = (int) $raw['status'];
            $parsedHeaders = $raw['headers'];
            $decoded = json_decode($raw['body'], true);
            $body = is_array($decoded) ? $decoded : [];

            if ($statusCode < 400) {
                return [
                    'body' => $body,
                    'headers' => $parsedHeaders,
                    'rate_limit' => [
                        'limit' => $this->headerInt($parsedHeaders, 'x-ratelimit-limit'),
                        'remaining' => $this->headerInt($parsedHeaders, 'x-ratelimit-remaining'),
                        'plan' => $parsedHeaders['x-ratelimit-plan'] ?? null,
                        'topup_remaining' => $this->headerInt($parsedHeaders, 'x-topup-remaining'),
                    ],
                ];
            }

            $message = $this->errorMessage($body, $statusCode);
            $apiCode = isset($body['code']) && is_string($body['code']) ? $body['code'] : null;
            $requestId = isset($body['request_id']) && is_string($body['request_id'])
                ? $body['request_id'] : ($parsedHeaders['x-request-id'] ?? null);
            $retryAfter = $this->parseRetryAfter($parsedHeaders['retry-after'] ?? null);

            if ($statusCode === 429) {
                if ($this->isQuotaExceeded($body)) {
                    throw new QuotaExceededException($message, $retryAfter, $requestId);
                }
                $wait = $retryAfter ?? $this->backoffSeconds($attempt);
                if ($attempt < $retries && $this->canWait($wait)) {
                    $this->sleep($wait);
                    continue;
                }
                throw new RateLimitException($message, $retryAfter, $apiCode, $requestId);
            }

            if ($statusCode >= 500) {
                $wait = $retryAfter ?? $this->backoffSeconds($attempt);
                if ($attempt < $retries && $this->canWait($wait)) {
                    $this->sleep($wait);
                    continue;
                }
                throw new ServerException($message, $statusCode, $retryAfter, $apiCode, $requestId);
            }

            switch ($statusCode) {
                case 400:
                    throw new BadRequestException($message, $apiCode, $requestId);
                case 401:
                    throw new AuthenticationException($message, $apiCode, $requestId);
                case 403:
                    throw new PermissionDeniedException($message, $apiCode, $requestId);
                case 404:
                    throw new NotFoundException($message, $apiCode ?? 'not_found', $requestId);
                default:
                    throw new PlateAPIException($message, $statusCode, null, $apiCode, $requestId);
            }
        }
    }

    /**
     * @param string[] $headers
     * @return array{status: int, headers: array, body: string}
     */
    private function send(string $url, array $headers): array
    {
        if ($this->handler !== null) {
            $result = ($this->handler)($url, $headers, $this->timeout);
            $result['headers'] = $this->lowercaseKeys($result['headers'] ?? []);
            return $result;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            curl_close($ch);
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                throw new \RuntimeException('Request timed out');
            }
            throw new \RuntimeException("Connection failed: {$error}");
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        return [
            'status' => $statusCode,
            'headers' => $this->parseHeaders(substr((string) $raw, 0, $headerSize)),
            'body' => substr((string) $raw, $headerSize),
        ];
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

    private function lowercaseKeys(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $out[strtolower((string) $name)] = is_array($value) ? (string) end($value) : (string) $value;
        }
        return $out;
    }

    private function headerInt(array $headers, string $name): ?int
    {
        if (!isset($headers[$name]) || $headers[$name] === 'unlimited' || !is_numeric($headers[$name])) {
            return null;
        }
        return (int) $headers[$name];
    }

    private function errorMessage(array $body, int $statusCode): string
    {
        foreach (['detail', 'error'] as $key) {
            if (isset($body[$key]) && is_string($body[$key]) && $body[$key] !== '') {
                return $body[$key];
            }
        }
        return "Request failed (HTTP {$statusCode})";
    }

    private function isQuotaExceeded(array $body): bool
    {
        if (($body['code'] ?? null) === 'quota_exceeded') {
            return true;
        }
        $detail = $body['detail'] ?? null;
        return is_string($detail) && str_starts_with(strtolower($detail), 'monthly quota exceeded');
    }

    private function parseRetryAfter(?string $value): ?float
    {
        if ($value === null || !is_numeric($value)) {
            return null;
        }
        $seconds = (float) $value;
        return $seconds < 0 ? null : $seconds;
    }

    private function canWait(float $seconds): bool
    {
        return $this->maxWait === null || $seconds <= $this->maxWait;
    }

    private function backoffSeconds(int $attempt): float
    {
        return self::INITIAL_BACKOFF * (2 ** $attempt) + (mt_rand(0, 500) / 1000);
    }

    /** Pause for $seconds. Protected so tests can record waits instead of sleeping. */
    protected function sleep(float $seconds): void
    {
        usleep((int) ($seconds * 1_000_000));
    }
}
