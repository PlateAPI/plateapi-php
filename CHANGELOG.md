# Changelog

## 0.3.0 -- 2026-11-13

Every 0.2.0 program keeps working. New methods only.

### Added

- `Client::vehicleChanges($options)` for `GET /api/v1/vehicles/changes`: one page of the vehicles
  added, updated or merged since a date, with `since` (an ISO 8601 string, or a
  `DateTimeInterface` sent in UTC), `vehicle_type`, `limit` and `cursor`. `new_years` is always a
  list and every summary key is present (`null` when the API leaves it out).
- `Client::allVehicleChanges($options)`: follows `next_cursor` to the last page and returns one
  result with every change in order. It throws `PlateAPIException` instead of looping when the API
  reports more pages without a cursor, or sends the same cursor twice.
- README section "Keeping vehicle IDs current".

### Changed

- `User-Agent: plateapi-php/0.3.0` (`Client::VERSION`).

## 0.2.0 -- 2026-10-18

Every 0.1.0 program keeps working. New keys and methods only; see "Upgrading from 0.1.0"
in the README for the few behaviour changes.

### Added

- `vehicle_id` on the primary vehicle and on every entry in `alternatives`: PlateAPI's stable
  ID for the exact variant.
- `match` (`exact`, `multiple`, `ambiguous`) and `candidate_ids` on lookup results.
- `Client::vehicleById($vehicleId)` for `GET /api/v1/vehicles/{vehicle_id}`.
- `Client::vehicles(['vehicle_type' => 'motorcycle'])` for the motorcycle cascade.
- `vehicle_ids` on cascade results (filled at the vehicle level).
- `Client::walkVehicles($make, $model, $options, &$knownIds)`: a generator that walks the cascade
  for one make and model (and its trims) and yields each `vehicle_id` once, with `year_from` /
  `year_to`, `engine_contains`, `per_year`, `delay` and a resumable known-ID list.
- `rate_limit.topup_remaining` from the `X-Topup-Remaining` header.
- `BadRequestException` (400), `PermissionDeniedException` (403) and `NotFoundException` (404).
- `getApiCode()` and `getRequestId()` on every `PlateAPIException`, from the body or the
  `X-Request-ID` header.
- `$maxWait` constructor argument: the longest `Retry-After` the SDK will sleep (default 60 s).
- `$handler` constructor argument: a custom HTTP handler (proxies, tests).
- `User-Agent: plateapi-php/<version>` and `Client::VERSION`.
- Offline test suite (`composer test`).

### Fixed

- A monthly-quota 429 throws `QuotaExceededException` at once instead of sleeping for an hour and
  retrying.
- A `Retry-After` longer than `$maxWait` throws instead of sleeping.
- `health()` makes a single attempt.
- Error messages are the API's own (`detail` / `error`), not a fixed string.
- A lookup that returns `code: "internal_error"` (not billed) is retried before it is returned.
- Query values are encoded with RFC 3986 (spaces as `%20`), which the cascade needs for values
  with double spaces.

### Changed

- `QuotaExceededException` now extends `RateLimitException` (still a `PlateAPIException`).
- An empty API key is rejected by the constructor.
- `composer.json` declares the cURL and JSON extensions.

## 0.1.0 -- 2026-08-16

- First release: `lookup`, `vehicles`, `usage`, `logs`, `health`, retries with backoff.
