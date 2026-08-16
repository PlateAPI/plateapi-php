<?php

require_once __DIR__ . '/../vendor/autoload.php';

use PlateAPI\Client;
use PlateAPI\AuthenticationException;
use PlateAPI\QuotaExceededException;
use PlateAPI\RateLimitException;
use PlateAPI\ServerException;

$apiKey = getenv('PLATEAPI_KEY') ?: 'pk_live_your_api_key';
$client = new Client($apiKey);

try {
    $health = $client->health();
    echo "API status: {$health['status']}\n";

    $result = $client->lookup('TEST123', 'VIC');
    if ($result['success'] && isset($result['vehicle'])) {
        $v = $result['vehicle'];
        echo "Make: {$v['make']}\n";
        echo "Model: {$v['model']}\n";
        echo "Year range: {$v['year_range']}\n";
        echo "Body: " . ($v['body'] ?? '') . "\n";
        echo "Engine: " . ($v['engine'] ?? '') . "\n";
        echo "Duration: {$result['duration_ms']}ms\n";
        echo "Request ID: {$result['request_id']}\n";
    }

    if ($result['rate_limit']['remaining'] !== null) {
        echo "Lookups remaining: {$result['rate_limit']['remaining']}\n";
    }
} catch (AuthenticationException $e) {
    fwrite(STDERR, "Invalid API key\n");
    exit(1);
} catch (QuotaExceededException $e) {
    fwrite(STDERR, "Monthly quota exceeded\n");
    exit(1);
} catch (RateLimitException $e) {
    fwrite(STDERR, "Rate limited");
    if ($e->getRetryAfter() !== null) {
        fwrite(STDERR, sprintf(", retry after %.0fs", $e->getRetryAfter()));
    }
    fwrite(STDERR, "\n");
    exit(1);
} catch (ServerException $e) {
    fwrite(STDERR, "Server error ({$e->getStatusCode()})\n");
    exit(1);
} catch (\Exception $e) {
    fwrite(STDERR, "Error: {$e->getMessage()}\n");
    exit(1);
}
