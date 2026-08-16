# PlateAPI PHP SDK

PHP client for [PlateAPI](https://plateapi.com.au) -- Australian vehicle registration plate lookup.

## Install

```bash
composer require plateapi/plateapi-php
```

Or add to your `composer.json` from GitHub:

```json
{
    "repositories": [
        {"type": "vcs", "url": "https://github.com/PlateAPI/plateapi-php"}
    ],
    "require": {
        "plateapi/plateapi-php": "dev-main"
    }
}
```

Requires PHP 8.1+ and the cURL extension.

## Quick start

```php
use PlateAPI\Client;

$client = new Client('pk_live_your_api_key');

$result = $client->lookup('ABC123', 'VIC');
if ($result['success']) {
    echo $result['vehicle']['make'] . ' ' . $result['vehicle']['model'];
}
```

## Plate lookup

```php
$result = $client->lookup('ABC123', 'VIC');

echo $result['success'];                    // true
echo $result['vehicle']['make'];             // "TOYOTA"
echo $result['vehicle']['model'];            // "HILUX"
echo $result['vehicle']['year'];             // 2015
echo $result['vehicle']['year_range'];       // "2015 - 2023"
echo $result['vehicle']['lowest_year'];      // 2015
echo $result['vehicle']['highest_year'];     // 2023
echo $result['vehicle']['body'];             // "UTILITY"
echo $result['vehicle']['engine'];           // "2.8L"
echo $result['vehicle']['description'];      // "TOYOTA HILUX UTILITY 2.8L"
echo $result['duration_ms'];                 // 2451.3
echo $result['source'];                      // "plateapi"
echo $result['request_id'];                  // "req_7f3a9c1b4e..."
```

Valid states: `NSW`, `VIC`, `QLD`, `SA`, `WA`, `TAS`, `NT`, `ACT`.

## Detailed lookup

```php
$result = $client->lookup('ABC123', 'NSW', detailed: true);
if ($result['success']) {
    echo $result['vehicle']['detailed_description'];
    echo $result['vehicle']['series'];
}
```

## Multiple matches

```php
$result = $client->lookup('ABC123', 'VIC');
foreach ($result['alternatives'] ?? [] as $alt) {
    echo "Also matched: {$alt['make']} {$alt['model']} ({$alt['year_range']})\n";
}
```

## Vehicle database

Browse the full vehicle database (32,000+ vehicles, 213 makes). Each call narrows the cascade through all 7 levels. Paid plans only, no quota consumed.

```php
// Step 1: All makes
$makes = $client->vehicles();
// $makes['type'] == 'make', $makes['data'] == ['ABARTH', 'AC', ...]

// Step 2: Models for a make
$models = $client->vehicles(['make' => 'TOYOTA']);

// Step 3: Years
$years = $client->vehicles(['make' => 'TOYOTA', 'model' => 'HILUX']);

// Step 4: Series
$series = $client->vehicles(['make' => 'TOYOTA', 'model' => 'HILUX', 'year' => 2020]);

// Step 5: Engines
$engines = $client->vehicles([
    'make' => 'TOYOTA', 'model' => 'HILUX', 'year' => 2020, 'series' => 'SR5',
]);

// Step 6: Variants
$variants = $client->vehicles([
    'make' => 'TOYOTA', 'model' => 'HILUX', 'year' => 2020,
    'series' => 'SR5', 'engine' => '2.8L',
]);

// Step 7: Full vehicle details
$vehicles = $client->vehicles([
    'make' => 'TOYOTA', 'model' => 'HILUX', 'year' => 2020,
    'series' => 'SR5', 'engine' => '2.8L', 'variant' => '4x4 Double Cab',
]);
```

For vehicles without a series code, pass an empty string:

```php
$result = $client->vehicles([
    'make' => 'TOYOTA', 'model' => 'HILUX', 'year' => 2020, 'series' => '',
]);
```

## Check usage

```php
$usage = $client->usage();
echo "{$usage['used_this_month']}/{$usage['monthly_limit']} lookups used\n";
echo "{$usage['remaining']} remaining\n";
echo "{$usage['percent_used']}% used\n";
echo "Plan: {$usage['plan']}\n";
echo "Rate limit: {$usage['rate_limit_per_min']}/min\n";
echo "Period: {$usage['period_start']} to {$usage['period_end']}\n";
echo "Days remaining: {$usage['days_remaining']}\n";
echo "Top-up credits: {$usage['topup_credits']}\n";
```

## Request logs

```php
// Last 10 lookups
$logs = $client->logs(['limit' => 10]);
foreach ($logs['logs'] as $entry) {
    $status = $entry['success'] ? 'found' : 'not found';
    echo "{$entry['created_at']} | {$entry['plate']} ({$entry['state']}) | {$status} | {$entry['duration_ms']}ms\n";
}
echo "Showing {$logs['count']} of {$logs['total']} total\n";
```

### Filtering

```php
// Filter by plate
$plateLogs = $client->logs(['plate' => 'ABC123']);

// Only failed lookups
$failed = $client->logs(['success' => false]);

// Time range
$july = $client->logs([
    'since' => '2026-07-01T00:00:00',
    'until' => '2026-07-31T23:59:59',
]);

// Pagination
$page1 = $client->logs(['limit' => 50, 'offset' => 0]);
$page2 = $client->logs(['limit' => 50, 'offset' => 50]);
```

## Health check

No authentication required, no quota consumed.

```php
$health = $client->health();
echo $health['status']; // "ok"
```

## Rate limits

```php
$result = $client->lookup('ABC123', 'VIC');
if ($result['rate_limit']['remaining'] !== null) {
    echo "Lookups remaining: {$result['rate_limit']['remaining']}\n";
}
```

## Error handling

```php
use PlateAPI\Client;
use PlateAPI\AuthenticationException;
use PlateAPI\QuotaExceededException;
use PlateAPI\RateLimitException;
use PlateAPI\ServerException;
use PlateAPI\PlateAPIException;

try {
    $result = $client->lookup('ABC123', 'VIC');
} catch (AuthenticationException $e) {
    echo "Invalid API key\n";
} catch (QuotaExceededException $e) {
    echo "Monthly quota exceeded\n";
} catch (RateLimitException $e) {
    echo "Rate limited";
    if ($e->getRetryAfter() !== null) {
        echo ", retry after {$e->getRetryAfter()}s";
    }
    echo "\n";
} catch (ServerException $e) {
    echo "Server error ({$e->getStatusCode()})\n";
} catch (PlateAPIException $e) {
    echo "API error: {$e->getMessage()} (status {$e->getStatusCode()})\n";
}
```

## Retry behaviour

The SDK automatically retries on:
- Connection errors
- Timeouts
- 429 rate limit responses (waits for Retry-After header)
- 5xx server errors

Default: 3 retries with exponential backoff and jitter. Configure with:

```php
$client = new Client(
    apiKey: 'pk_live_your_api_key',
    baseUrl: 'https://api.plateapi.com.au',
    timeout: 60,
    maxRetries: 5,
);
```

## Sandbox

Use plate `TEST123` with any state for testing. Returns a fixed response instantly, no quota consumed.

```php
$result = $client->lookup('TEST123', 'VIC');
// $result['sandbox'] == true
// $result['success'] == true
// $result['vehicle']['make'] == 'TOYOTA'
```

## WordPress / WooCommerce

```php
// In your theme's functions.php or a custom plugin:
require_once __DIR__ . '/vendor/autoload.php';

use PlateAPI\Client;

function plateapi_lookup(string $plate, string $state): ?array {
    $client = new Client('pk_live_your_api_key');
    try {
        $result = $client->lookup($plate, $state);
        return $result['success'] ? $result['vehicle'] : null;
    } catch (\Exception $e) {
        error_log('PlateAPI error: ' . $e->getMessage());
        return null;
    }
}
```

## Links

- [API Documentation](https://plateapi.com.au/docs)
- [Pricing](https://plateapi.com.au/pricing)
- [Dashboard](https://plateapi.com.au/dashboard)
- [Sign up for free](https://plateapi.com.au/register)
- [Status page](https://plateapi.com.au/status)
- [GitHub](https://github.com/PlateAPI)
