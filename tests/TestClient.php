<?php

declare(strict_types=1);

namespace PlateAPI\Tests;

use PlateAPI\Client;

/** Client with a queued fake HTTP handler and recorded sleeps (no network). */
class TestClient extends Client
{
    /** @var float[] */
    public array $sleeps = [];
    /** @var array[] */
    public array $calls = [];
    /** @var array */
    private array $queue;

    public function __construct(array $responses, array $options = [])
    {
        $this->queue = $responses;
        $calls = &$this->calls;
        $queue = &$this->queue;
        $handler = function (string $url, array $headers, int $timeout) use (&$calls, &$queue): array {
            $parts = parse_url($url);
            $params = [];
            if (isset($parts['query'])) {
                parse_str($parts['query'], $params);
            }
            $named = [];
            foreach ($headers as $h) {
                [$k, $v] = explode(':', $h, 2);
                $named[trim($k)] = trim($v);
            }
            $calls[] = [
                'url' => $parts['scheme'] . '://' . $parts['host'] . ($parts['path'] ?? ''),
                'params' => $params,
                'headers' => $named,
                'timeout' => $timeout,
            ];
            if ($queue === []) {
                throw new \LogicException('unexpected request: ' . $url);
            }
            $item = array_shift($queue);
            if ($item instanceof \Throwable) {
                throw $item;
            }
            return $item;
        };
        parent::__construct(
            'pk_live_test',
            $options['baseUrl'] ?? Client::DEFAULT_BASE_URL,
            $options['timeout'] ?? 30,
            $options['maxRetries'] ?? 3,
            array_key_exists('maxWait', $options) ? $options['maxWait'] : 60.0,
            $handler,
        );
    }

    protected function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;
    }

    public static function res(int $status, ?array $body, array $headers = [], ?string $text = null): array
    {
        return [
            'status' => $status,
            'headers' => $headers,
            'body' => $text ?? ($body === null ? '<html>bad gateway</html>' : json_encode($body)),
        ];
    }

    public static function ok(array $body, array $headers = []): array
    {
        return self::res(200, $body, $headers);
    }

    public static function level(string $name, array $items): array
    {
        return self::ok(['success' => true, 'data' => [['type' => $name, 'data' => $items]], 'total' => count($items), 'duration_ms' => 1.0]);
    }
}
