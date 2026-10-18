# PlateAPI PHP SDK

PHP client for [PlateAPI](https://plateapi.com.au) -- Australian vehicle registration plate
lookup and the PlateAPI vehicle database.

## Install

Add the GitHub repository to your `composer.json`:

```json
{
    "repositories": [
        {"type": "vcs", "url": "https://github.com/PlateAPI/plateapi-php"}
    ],
    "require": {
        "plateapi/plateapi-php": "^0.2"
    }
}
```

Then run:

```bash
composer install
```

(`"dev-main"` also works and tracks the latest commit.) Requires PHP 8.1+ with the cURL and
JSON extensions. No other dependencies.

## Quick start

```php
use PlateAPI\Client;

$client = new Client('pk_live_your_api_key');

$result = $client->lookup('ABC123', 'VIC');
if ($result['success']) {
    echo $result['vehicle']['make'] . ' ' . $result['vehicle']['model'] . ' ' . $result['vehicle']['year_range'];
    echo $result['vehicle']['vehicle_id'];   // PlateAPI's stable ID for this variant
    echo $result['match'];                   // "exact", "multiple" or "ambiguous"
}
```

Get a free API key (20 lookups a month, no card) at [plateapi.com.au/register](https://plateapi.com.au/register/).
Full API reference: [plateapi.com.au/docs](https://plateapi.com.au/docs/).

Every method returns a plain array with the API's field names, so anything the API adds later is
reachable without an SDK update.

## Plate lookup

```php
$result = $client->lookup('ABC123', 'VIC');

$result['success']                    // true when a vehicle was found
$result['match']                      // "exact" | "multiple" | "ambiguous" (null when not found)
$result['vehicle']['make']            // "TOYOTA"
$result['vehicle']['model']           // "HILUX"
$result['vehicle']['year_range']      // "2015-2020" ("2020-ON" for models still in production)
$result['vehicle']['lowest_year']     // 2015
$result['vehicle']['highest_year']    // 2020 (null for an open range)
$result['vehicle']['year']            // same as lowest_year
$result['vehicle']['body']            // "UTE" (may be null)
$result['vehicle']['engine']          // "2.8L" (may be null)
$result['vehicle']['description']     // "15~20 TOYOTA HILUX 2.8L DIESEL"
$result['vehicle']['vehicle_id']      // 1380801 (null when the variant is not certain)
$result['alternatives']               // other candidate variants, same fields, each with its own vehicle_id
$result['candidate_ids']              // every non-null vehicle_id, primary first: [1380801]
$result['request_id']                 // "req_7f3a9c1b4e8d2f6a0c5e" -- quote this when contacting support
$result['duration_ms']                // 1509.96
$result['sandbox']                    // true only for the TEST123 sandbox plate
```

Valid states: `NSW`, `VIC`, `QLD`, `SA`, `WA`, `TAS`, `NT`, `ACT`. Plate and state are
upper-cased and trimmed for you; an unknown state throws `InvalidArgumentException` before any
request.

### Not found

A plate that does not resolve is a normal result, not an exception. It is still HTTP 200 and it
still counts against your monthly quota (typos are common, so validate input first).

```php
$result = $client->lookup('ZZZ999', 'NSW');
if (!$result['success']) {
    echo $result['code'];    // "not_found"
    echo $result['error'];   // human-readable message
}
```

### `vehicle_id` and `match`

Every vehicle in a lookup carries `vehicle_id`: PlateAPI's ID for the exact variant. It is stable
(the same variant always returns the same ID) and it is the same ID the vehicle database uses, so
you can link your own records (products, SKUs, fitment rows) to it. It is `null` when the variant
could not be identified with certainty; an ID is never guessed.

`match` tells you how to read the result:

| `match` | Meaning | What to store |
|---------|---------|---------------|
| `exact` | one variant | `$result['vehicle']['vehicle_id']` |
| `multiple` | several trims of one make and model, all on sale at the same time; `vehicle` is the first with its ID, the rest are in `alternatives` | all of `$result['candidate_ids']`, or let the customer pick |
| `ambiguous` | candidates are different makes or models, or different generations; `vehicle.vehicle_id` is `null` and `alternatives` lists every candidate (including the one shown as `vehicle`) with its ID | `$result['candidate_ids']` (a picker is best) |

```php
$result = $client->lookup('ABC123', 'VIC');
if ($result['match'] === 'exact') {
    save($result['vehicle']['vehicle_id']);
} else {
    foreach ($result['alternatives'] as $alt) {
        echo "{$alt['vehicle_id']} {$alt['description']} {$alt['year_range']}\n";
    }
    saveAll($result['candidate_ids']);
}
```

With `match: "multiple"` the primary `vehicle_id` identifies the first candidate, not necessarily
the plate's own trim. Lookup text (`make`, `model`, `description`) can be worded differently from
the record the ID points to: integrate on `vehicle_id`, not on text.

### Detailed lookup

```php
$result = $client->lookup('ABC123', 'NSW', detailed: true);
echo $result['vehicle']['detailed_description'];
// "TOYOTA HILUX SR Auto or Manual AN120 AN130 05/2015~12/2020 4 Door UTE 4x4 DIESEL 2.8 litre, ..."
```

`detailed_description` may be `null` for some vehicles even when requested.

### Rate limit headers

Each lookup result carries the quota headers from the response:

```php
$result['rate_limit']['limit']            // monthly lookup allowance
$result['rate_limit']['remaining']        // lookups left this period
$result['rate_limit']['plan']             // "starter"
$result['rate_limit']['topup_remaining']  // remaining top-up credits, only while they are being consumed
```

## Vehicle database

Starter plans and above. The database holds 23,000+ Australian-market car variants (225+ makes)
and 15,000+ motorcycles, scooters and ATVs (420+ makes). Queries do not consume lookup quota; they
have their own limit of 500 requests per minute.

### Cascade

`vehicles()` returns one level of the seven-level cascade. Each call narrows it: no arguments gives
the makes, `make` gives models, and so on down to full vehicle records with a `vehicle_id`.

```php
$r = $client->vehicles();                                              // $r['type'] == 'make'
array_slice($r['data'], 0, 3);                                         // ['ABARTH', 'ACE EV', 'ACURA']

$r = $client->vehicles(['make' => 'TOYOTA']);                          // 'model'
$r = $client->vehicles(['make' => 'TOYOTA', 'model' => 'HILUX']);      // 'year'  (newest first, ints)
$r = $client->vehicles(['make' => 'TOYOTA', 'model' => 'HILUX', 'year' => 2019]);              // 'series'
$r = $client->vehicles(['make' => 'TOYOTA', 'model' => 'HILUX', 'year' => 2019,
                        'series' => 'GUN126R -GUN126']);                                        // 'engine'
$r = $client->vehicles(['make' => 'TOYOTA', 'model' => 'HILUX', 'year' => 2019,
                        'series' => 'GUN126R -GUN126',
                        'engine' => '2.8L,  DIE 1GDFTV I4 16v DOHC I/C Turbo CRD {130kW}']);    // 'variant'
$r = $client->vehicles(['make' => 'TOYOTA', 'model' => 'HILUX', 'year' => 2019,
                        'series' => 'GUN126R -GUN126',
                        'engine' => '2.8L,  DIE 1GDFTV I4 16v DOHC I/C Turbo CRD {130kW}',
                        'variant' => '4D Ute -, 4WD MR0HA3CD  [THAILAND], AT/MT']);            // 'vehicle'
$r['data']           // [['vehicle_id' => 1346446, 'select_text' => '...', 'description' => '...', 'long_description' => '...']]
$r['vehicle_ids']    // [1346446]
```

Result keys: `success`, `type` (the level returned), `data` (strings, ints at the year level, or
arrays at the vehicle level), `vehicle_ids` (filled at the vehicle level), `total`, `duration_ms`.

Rules worth knowing:

- Pass each value exactly as the previous level returned it. Values contain commas, double spaces,
  braces and brackets, and matching is case-sensitive. The SDK URL-encodes them for you.
- An empty-string series is a real value. Pass `'series' => ''` to continue below it.
- Unknown values are not errors: you get `success == true` with an empty `data` array. A `503`
  (database briefly unavailable) is retried by the SDK and then thrown as `ServerException`.
- Trims are filed as separate models (`HILUX`, `HILUX SR`, `HILUX SR5 EXTRA CAB`), so to cover a
  base model walk every model name that starts with it. `walkVehicles()` below does this.
- The vehicle level is a list: a few paths return two records whose catalogue entries overlap in
  that year. Use each record's description and `vehicleById()` to tell them apart.
- `vehicle_id` identifies the variant, not the year: a variant returns the same ID under every
  model year it was sold.

### Motorcycles

Motorcycles have their own cascade. Pass `'vehicle_type' => 'motorcycle'` on every level
(`'car'` is the default).

```php
$r = $client->vehicles(['vehicle_type' => 'motorcycle']);                                  // 420+ makes
$r = $client->vehicles(['make' => 'YAMAHA', 'model' => 'MT-07', 'vehicle_type' => 'motorcycle']);
```

A motorcycle plate lookup returns the same ID as the motorcycle cascade. Any other `vehicle_type`
throws `InvalidArgumentException`.

### One vehicle by ID

```php
$record = $client->vehicleById(1380801);   // also accepts '1380801'

$record['vehicle_id']        // 1380801
$record['vehicle_type']      // "car" or "motorcycle"
$record['make']              // "TOYOTA"
$record['model']             // "HILUX SR"
$record['year_range']        // "2015-2020"
$record['lowest_year']       // 2015
$record['highest_year']      // 2020 (null for an open range)
$record['years']             // [2015, 2016, 2017, 2018, 2019, 2020]
$record['description']       // "15~20 TOYOTA HILUX 2.8L DIESEL"
$record['long_description']  // "TOYOTA HILUX 10/2015~04/2020"
$record['series']            // "GUN126R -GUN126"
$record['engine']            // "2.8L,  DIE 1GDFTV I4 16v DOHC I/C Turbo CRD {130kW}"
$record['variant']           // "SR,  4D Ute -, 4WD MR0KA3CD  [THAILAND], AT/MT"
$record['body']              // "UTE"
$record['body_size']         // "COMPACT" / "MEDIUM" / "FULL-SIZE" for SUVs, else null
$record['doors']             // 4
$record['drive']             // "4WD", "AWD", "RWD", "FWD", "6X4", ...
$record['transmission']      // "AT", "MT", "AT/MT", "CVT", "DCT", "AMT"
$record['detail']            // "full" or "basic"
```

- `detail == 'basic'` means the vehicle has an ID but its full record has not been added yet:
  `series`, `variant` and `long_description` are `null` and `years` is empty. The ID does not
  change when the full record is added.
- `body`, `body_size`, `doors`, `drive` and `transmission` are read from the variant name and are
  `null` when it does not carry them.
- A retired ID that was merged into another returns the current record, so `$record['vehicle_id']`
  can differ from the ID you asked for. Compare them if you care.
- An unknown ID throws `NotFoundException` (API code `not_found`). Anything that is not a
  non-negative integer or a string of digits throws `InvalidArgumentException` before any request.
- The sandbox plate `TEST123` returns `vehicle_id` 1380801, a real record, so you can test this
  call without using quota.

## Walking the cascade

`walkVehicles()` collects every `vehicle_id` for one make and model: it walks models, years,
series, engines, variants and vehicles for you and yields each vehicle the first time it is seen.
This is how you map existing fitment text (make, model, years, engine) to PlateAPI IDs once.

```php
foreach ($client->walkVehicles('TOYOTA', 'HILUX', ['year_from' => 2015, 'year_to' => 2020, 'engine_contains' => '2.8L']) as $v) {
    echo "{$v['vehicle_id']} {$v['model']} {$v['year']} {$v['series']} {$v['engine']} {$v['variant']}\n";
}
```

| Option | Default | Meaning |
|--------|---------|---------|
| `$make`, `$model` (arguments) | required | base model; must be non-empty |
| `trims` | `true` | also walk every model name starting with `$model . ' '` (`HILUX SR`, `HILUX SR5 EXTRA CAB`). `false` walks `$model` only and skips the models request |
| `year_from`, `year_to` | none | inclusive model-year window; `InvalidArgumentException` if from > to |
| `engine_contains` | none | only engines whose name contains this text (case-insensitive), e.g. `'2.8L'` or `'DIE'` |
| `vehicle_type` | none | `'motorcycle'` for the motorcycle cascade |
| `per_year` | `false` | yield one row per vehicle per model year instead of one row per vehicle (for year-level master lists) |
| `delay` | `0.15` | seconds to pause after each request; the default stays under 500 requests/minute |

The fourth argument, `?array &$knownIds`, is a list of IDs already handled (or of `[id, year]`
pairs with `per_year`). They are not yielded again and the list is appended to as the walk goes,
so an interrupted walk can be resumed.

Each yielded array has `vehicle_id`, `make`, `model` (the exact model name it was found under),
`year`, `series`, `engine`, `variant` (the cascade's `select_text`), `description`,
`long_description`, `vehicle_type` and `raw` (the vehicle record). The path fields are where the
vehicle was first seen; with `per_year` you get a row for every year.

The walk is a generator, so results arrive as they are found and you can `break` at any time.
Errors are thrown at the point they happen; everything already yielded stays with you. A `429`
whose `Retry-After` is longer than `$maxWait` (60 s by default) throws `RateLimitException`
immediately rather than sleeping, so a long walk never sits silently. Catch it, save your progress
and resume later with `$knownIds`.

A Hilux walk (all trims, all years) is about 350 requests and takes a minute or two at the default
delay; a single make and model with a year window is usually a few seconds.

### Example: stream IDs to a CSV, resumable

```php
use PlateAPI\Client;
use PlateAPI\RateLimitException;

$filename = 'hilux.csv';
$fields = ['vehicle_id', 'make', 'model', 'year', 'series', 'engine', 'variant', 'description'];

$known = [];
if (file_exists($filename)) {
    $h = fopen($filename, 'r');
    fgetcsv($h);
    while (($row = fgetcsv($h)) !== false) {
        $known[] = (int) $row[0];
    }
    fclose($h);
}

$client = new Client(getenv('PLATEAPI_KEY'));
$out = fopen($filename, 'a');
if ($known === []) {
    fputcsv($out, $fields);
}
try {
    foreach ($client->walkVehicles('TOYOTA', 'HILUX', [], $known) as $v) {
        fputcsv($out, array_map(fn ($k) => $v[$k], $fields));
        fflush($out);
    }
} catch (RateLimitException $e) {
    echo "rate limited, retry in {$e->getRetryAfter()} s; " . count($known) . " rows saved\n";
} finally {
    fclose($out);
}
```

Run it again later and it continues where it stopped.

### Linking products (SKUs) to vehicles

Keep a many-to-many table `(sku, vehicle_id)`. Walk the cascade once per fitment line to fill it,
then on each plate lookup query the table by `$result['vehicle']['vehicle_id']` (or every ID in
`$result['candidate_ids']` when `match` is `multiple`). Store IDs, not text. The full guide is at
[plateapi.com.au/docs/vehicles](https://plateapi.com.au/docs/vehicles/#vehicles-skus).

## Account

### Usage

```php
$usage = $client->usage();
$usage['plan']               // "starter"
$usage['monthly_limit']      // 600
$usage['used_this_month']    // 12
$usage['remaining']          // 588
$usage['percent_used']       // 2.0
$usage['rate_limit_per_min'] // 10
$usage['period_start']       // "2026-09-07 00:00:00" (UTC)
$usage['period_end']         // null when there is no billing period (free plan)
$usage['days_remaining']     // null when there is no billing period
$usage['last_lookup_at']     // "2026-10-03 03:20:02"
$usage['topup_credits']      // remaining purchased top-up lookups
$usage['cancel_at_period_end'], $usage['cancel_at']
```

### Lookup history

```php
$logs = $client->logs(['limit' => 50, 'offset' => 0, 'since' => '2026-10-01T00:00:00']);
foreach ($logs['logs'] as $entry) {
    echo "{$entry['created_at']} {$entry['plate']} {$entry['state']} {$entry['success']} {$entry['duration_ms']} {$entry['request_id']}\n";
}
echo "{$logs['count']} of {$logs['total']}\n";
```

`limit` is at most 500. `since` / `until` are ISO 8601 UTC. `'success' => true` / `false`
filters found / not found. Log entry keys: `plate`, `state`, `success` (1 or 0), `error`,
`duration_ms`, `make`, `model`, `year`, `client_ip`, `request_id`, `created_at`.

### Health

```php
$client->health()['status'];   // "ok"
```

No key is sent, no quota is used, and the call makes a single attempt.

## Sandbox

Plate `TEST123` with any state returns a fixed vehicle instantly: free, no quota, no rate limit.
A valid key is still required. Use it in integration tests and CI.

```php
$result = $client->lookup('TEST123', 'VIC');
$result['sandbox']                  // true
$result['vehicle']['vehicle_id']    // 1380801
$client->vehicleById($result['vehicle']['vehicle_id'])['model'];   // "HILUX SR"
```

## Errors

All exceptions extend `PlateAPIException` and carry `getStatusCode()`, `getApiCode()` (the API's
machine code when it sent one) and `getRequestId()`. Messages are the API's own.

| Exception | HTTP | When |
|-----------|------|------|
| `BadRequestException` | 400 | invalid plate, state, cascade parameter or vehicle ID |
| `AuthenticationException` | 401 | missing, invalid or deactivated key; email not verified |
| `PermissionDeniedException` | 403 | IP not on the key's allowlist, account banned, or vehicle database on a Free / Lite plan |
| `NotFoundException` | 404 | `vehicleById()` with an unknown ID |
| `RateLimitException` | 429 | per-minute limit; `getRetryAfter()` in seconds |
| `QuotaExceededException` | 429 | monthly quota used up (extends `RateLimitException`; `getRetryAfter()` is 3600) |
| `ServerException` | 5xx | after retries; `getApiCode()` is `lookup_failed` or `unavailable` when the API sent one, `getRetryAfter()` when it sent a header |
| `PlateAPIException` | other | connection failures, timeouts, unexpected statuses |

```php
use PlateAPI\Client;
use PlateAPI\AuthenticationException;
use PlateAPI\PlateAPIException;
use PlateAPI\QuotaExceededException;
use PlateAPI\RateLimitException;
use PlateAPI\ServerException;

try {
    $result = $client->lookup('ABC123', 'VIC');
} catch (QuotaExceededException $e) {
    echo "monthly quota used up: {$e->getMessage()}\n";
} catch (RateLimitException $e) {
    echo "slow down, retry in {$e->getRetryAfter()} seconds\n";
} catch (AuthenticationException $e) {
    echo "key problem: {$e->getMessage()}\n";
} catch (ServerException $e) {
    echo "temporary failure, not billed: {$e->getApiCode()} {$e->getRequestId()}\n";
} catch (PlateAPIException $e) {
    echo "error {$e->getStatusCode()}: {$e->getMessage()}\n";
}
```

Catch `QuotaExceededException` before `RateLimitException`, as above. A lookup that cannot be
completed (`503`, code `lookup_failed`) is never billed; retry it after a few seconds. A `200`
with `code: "internal_error"` is also not billed and is retried by the SDK before it is returned.

## Retries and waiting

| Response | Behaviour |
|----------|-----------|
| connection error, timeout | retried up to `$maxRetries` with backoff (1 s, 2 s, 4 s plus jitter), then `PlateAPIException` |
| 400 / 401 / 403 / 404 | thrown at once, no retry |
| 429 monthly quota | `QuotaExceededException` at once, no sleep |
| 429 other | sleeps `Retry-After` (backoff if absent) and retries; if `Retry-After` is longer than `$maxWait` or retries are used up, `RateLimitException` |
| 5xx | same rule, then `ServerException` |
| 200 lookup with `internal_error` | retried with backoff, then returned |

```php
$client = new Client(
    apiKey: 'pk_live_your_api_key',
    baseUrl: 'https://api.plateapi.com.au',  // default
    timeout: 30,          // seconds per request
    maxRetries: 3,        // retries after the first attempt
    maxWait: 60.0,        // longest Retry-After to sleep; null waits any length
    handler: null,        // optional HTTP handler (proxies, tests); see the docblock
);
```

Every request sends `User-Agent: plateapi-php/<version>` (`Client::VERSION`).

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
        "plateapi/plateapi-php": "^0.2"
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
    $client = new Client(PLATEAPI_KEY);
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

    $client = new Client(PLATEAPI_KEY);
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

    $client = new Client(PLATEAPI_KEY);
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

- **Don't hardcode your API key.** Store it in `wp-config.php` as a constant (`define('PLATEAPI_KEY', 'pk_live_...')`) and reference it with `new Client(PLATEAPI_KEY)`, as the examples above do.
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

- **Link products by vehicle ID.** Store `$result['vehicle']['vehicle_id']` against the WooCommerce products that fit it (see "Linking products" above) rather than matching on make and model text.
- **PHP version.** This SDK requires PHP 8.1+. Most modern WordPress hosts run 8.1 or 8.2. Check yours with `phpinfo()` or ask your host.
- **Shared hosting.** If you can't run Composer, download the repo as a ZIP from GitHub, extract it, and `require_once` the individual files from `src/` manually (no autoloader needed -- include `Client.php` and the exception files).

## Upgrading from 0.1.0

Everything written against 0.1.0 keeps working: no public name, signature, default, key or
exception class was removed. New in 0.2.0: `vehicle_id`, `match`, `candidate_ids`,
`vehicleById()`, `walkVehicles()`, `'vehicle_type' => 'motorcycle'`, `vehicle_ids`,
`topup_remaining`, `BadRequestException`, `PermissionDeniedException`, `NotFoundException`,
`getApiCode()` and `getRequestId()` on every exception, `$maxWait`, `$handler`.

Behaviour changes to be aware of:

- A monthly-quota `429` now throws `QuotaExceededException` immediately. 0.1.0 only recognised a
  body code the API does not send, so it slept for an hour and retried. `QuotaExceededException`
  now extends `RateLimitException`, so `catch (RateLimitException $e)` catches both, as it
  effectively did before.
- A `Retry-After` longer than `$maxWait` (60 s) throws instead of sleeping. Pass `maxWait: null`
  for the old behaviour.
- `health()` makes one attempt.
- Error messages are the API's `detail` / `error` text instead of fixed strings.
- 400, 403 and 404 throw `BadRequestException`, `PermissionDeniedException` and
  `NotFoundException` (all extend `PlateAPIException`, which 0.1.0 threw).
- An empty API key throws `InvalidArgumentException` from the constructor.

See [CHANGELOG.md](CHANGELOG.md).

## Links

- [API documentation](https://plateapi.com.au/docs/)
- [Vehicle database and vehicle IDs](https://plateapi.com.au/docs/vehicles/)
- [OpenAPI spec](https://plateapi.com.au/openapi.json)
- [Pricing](https://plateapi.com.au/pricing/)
- [Dashboard](https://plateapi.com.au/dashboard/)
- [Status page](https://plateapi.com.au/status/)
- [Support](mailto:support@plateapi.com.au)
