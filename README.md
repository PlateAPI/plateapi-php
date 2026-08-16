# PlateAPI PHP SDK

PHP client for [PlateAPI](https://plateapi.com.au) -- Australian vehicle registration plate lookup.

## Install

Add the GitHub repository to your `composer.json`:

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

Then run:

```bash
composer install
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

### 1. Install Composer on your server

Most managed WordPress hosts don't include Composer. If `composer` isn't available, install it:

```bash
cd /path/to/your/wordpress
curl -sS https://getcomposer.org/installer | php
```

This creates `composer.phar` in your WordPress root. You only need to do this once.

### 2. Install the SDK

From your WordPress root directory (where `wp-config.php` lives), create or edit `composer.json`:

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

Then run:

```bash
composer install
```

Or if Composer isn't in your PATH:

```bash
php composer.phar install
```

This creates a `vendor/` directory with the SDK and its autoloader.

**Important:** Make sure `vendor/` is not publicly accessible. Most WordPress installs already block it, but check that your `.htaccess` or server config doesn't serve files from `vendor/`.

### 3. Load the autoloader

Add this line near the top of your theme's `functions.php`, or in a custom plugin file:

```php
require_once ABSPATH . 'vendor/autoload.php';
```

If you installed Composer inside your theme directory instead, use:

```php
require_once get_stylesheet_directory() . '/vendor/autoload.php';
```

### 4. Use the SDK

```php
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

### 5. Example: shortcode for plate lookup

Add this to `functions.php` to create a `[plateapi]` shortcode:

```php
use PlateAPI\Client;

add_shortcode('plateapi', function ($atts) {
    $atts = shortcode_atts(['plate' => '', 'state' => 'VIC'], $atts);
    if (empty($atts['plate'])) {
        return '<p>No plate provided.</p>';
    }

    $client = new Client('pk_live_your_api_key');
    try {
        $result = $client->lookup($atts['plate'], $atts['state']);
    } catch (\Exception $e) {
        return '<p>Lookup unavailable.</p>';
    }

    if (!$result['success']) {
        return '<p>No vehicle found.</p>';
    }

    $v = $result['vehicle'];
    return sprintf(
        '<p><strong>%s %s</strong> (%s)</p>',
        esc_html($v['make'] ?? ''),
        esc_html($v['model'] ?? ''),
        esc_html($v['year_range'] ?? '')
    );
});
```

Usage in any post or page: `[plateapi plate="ABC123" state="VIC"]`

### 6. Example: AJAX endpoint for live lookup

```php
use PlateAPI\Client;

add_action('wp_ajax_plateapi_lookup', 'handle_plateapi_lookup');
add_action('wp_ajax_nopriv_plateapi_lookup', 'handle_plateapi_lookup');

function handle_plateapi_lookup() {
    check_ajax_referer('plateapi_nonce', 'nonce');

    $plate = sanitize_text_field($_POST['plate'] ?? '');
    $state = sanitize_text_field($_POST['state'] ?? 'VIC');

    if (empty($plate)) {
        wp_send_json_error('No plate provided');
    }

    $client = new Client('pk_live_your_api_key');
    try {
        $result = $client->lookup($plate, $state);
        wp_send_json_success($result);
    } catch (\Exception $e) {
        wp_send_json_error($e->getMessage());
    }
}
```

Call it from JavaScript:

```js
const form = new FormData();
form.append('action', 'plateapi_lookup');
form.append('nonce', plateapi_vars.nonce); // localized via wp_localize_script
form.append('plate', 'ABC123');
form.append('state', 'VIC');

fetch(plateapi_vars.ajax_url, { method: 'POST', body: form })
    .then(r => r.json())
    .then(data => console.log(data));
```

### Tips

- **Don't hardcode your API key.** Store it in `wp-config.php` as a constant (`define('PLATEAPI_KEY', 'pk_live_...')`) and reference it with `new Client(PLATEAPI_KEY)`.
- **Cache results.** Use WordPress transients to avoid repeated lookups for the same plate:

```php
function plateapi_cached_lookup(string $plate, string $state): ?array {
    $key = 'plateapi_' . md5($plate . $state);
    $cached = get_transient($key);
    if ($cached !== false) {
        return $cached;
    }

    $vehicle = plateapi_lookup($plate, $state);
    if ($vehicle !== null) {
        set_transient($key, $vehicle, DAY_IN_SECONDS);
    }
    return $vehicle;
}
```

- **PHP version.** This SDK requires PHP 8.1+. Most modern WordPress hosts run 8.1 or 8.2. Check yours with `phpinfo()` or ask your host.
- **Shared hosting.** If you can't run Composer, download the repo as a ZIP from GitHub, extract it, and `require_once` the individual files from `src/` manually (no autoloader needed -- just include `Client.php` and the exception files).

## Links

- [API Documentation](https://plateapi.com.au/docs)
- [Pricing](https://plateapi.com.au/pricing)
- [Dashboard](https://plateapi.com.au/dashboard)
- [Sign up for free](https://plateapi.com.au/register)
- [Status page](https://plateapi.com.au/status)
- [GitHub](https://github.com/PlateAPI)
