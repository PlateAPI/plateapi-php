<?php

declare(strict_types=1);

namespace PlateAPI\Tests;

use PHPUnit\Framework\TestCase;
use PlateAPI\AuthenticationException;
use PlateAPI\BadRequestException;
use PlateAPI\Client;
use PlateAPI\NotFoundException;
use PlateAPI\PermissionDeniedException;
use PlateAPI\PlateAPIException;
use PlateAPI\QuotaExceededException;
use PlateAPI\RateLimitException;
use PlateAPI\ServerException;

class ClientTest extends TestCase
{
    private const HILUX = [
        'year_range' => '2015-2020', 'lowest_year' => 2015, 'highest_year' => 2020,
        'make' => 'TOYOTA', 'model' => 'HILUX', 'description' => '15~20 TOYOTA HILUX 2.8L DIESEL',
        'body' => 'UTE', 'engine' => '2.8L', 'vehicle_id' => 1380801,
    ];
    private const COROLLA_A = [
        'year_range' => '2003-2006', 'lowest_year' => 2003, 'highest_year' => 2006,
        'make' => 'TOYOTA', 'model' => 'COROLLA', 'description' => '03~06 TOYOTA COROLLA SPORTIVO 1.8L PETROL',
        'body' => 'HATCHBACK', 'engine' => '1.8L', 'vehicle_id' => 5152708,
    ];
    private const RECORD = [
        'vehicle_id' => 1380801, 'vehicle_type' => 'car', 'make' => 'TOYOTA', 'model' => 'HILUX SR',
        'year_range' => '2015-2020', 'lowest_year' => 2015, 'highest_year' => 2020,
        'years' => [2015, 2016, 2017, 2018, 2019, 2020],
        'description' => '15~20 TOYOTA HILUX 2.8L DIESEL', 'long_description' => 'TOYOTA HILUX 10/2015~04/2020',
        'series' => 'GUN126R -GUN126', 'engine' => '2.8L,  DIE 1GDFTV I4 16v DOHC I/C Turbo CRD {130kW}',
        'variant' => 'SR,  4D Ute -, 4WD MR0KA3CD  [THAILAND], AT/MT',
        'body' => 'UTE', 'body_size' => null, 'doors' => 4, 'drive' => '4WD', 'transmission' => 'AT/MT', 'detail' => 'full',
    ];

    // ---- lookup ----

    public function testExactLookup(): void
    {
        $c = new TestClient([TestClient::ok([
            'success' => true, 'vehicle' => self::HILUX, 'alternatives' => [], 'match' => 'exact',
            'source' => 'plateapi', 'duration_ms' => 1509.9, 'request_id' => 'req_abc',
        ], ['X-RateLimit-Limit' => '600', 'X-RateLimit-Remaining' => '599', 'X-RateLimit-Plan' => 'starter'])]);
        $r = $c->lookup('abc 123', 'vic');
        $this->assertSame('https://api.plateapi.com.au/api/v1/lookup', $c->calls[0]['url']);
        $this->assertSame(['plate' => 'ABC 123', 'state' => 'VIC'], $c->calls[0]['params']);
        $this->assertSame('pk_live_test', $c->calls[0]['headers']['X-API-Key']);
        $this->assertSame('plateapi-php/' . Client::VERSION, $c->calls[0]['headers']['User-Agent']);
        $this->assertTrue($r['success']);
        $this->assertSame('exact', $r['match']);
        $this->assertSame(1380801, $r['vehicle']['vehicle_id']);
        $this->assertSame(2015, $r['vehicle']['year']);
        $this->assertSame([1380801], $r['candidate_ids']);
        $this->assertSame('req_abc', $r['request_id']);
        $this->assertSame(['limit' => 600, 'remaining' => 599, 'plan' => 'starter', 'topup_remaining' => null], $r['rate_limit']);
    }

    public function testMultipleCandidateIdsInOrderWithoutDuplicates(): void
    {
        $b = array_merge(self::COROLLA_A, ['vehicle_id' => 1857621]);
        $c = new TestClient([TestClient::ok(['success' => true, 'vehicle' => self::COROLLA_A, 'match' => 'multiple', 'alternatives' => [$b, self::COROLLA_A]])]);
        $r = $c->lookup('ABC123', 'VIC');
        $this->assertSame('multiple', $r['match']);
        $this->assertSame([5152708, 1857621], $r['candidate_ids']);
    }

    public function testAmbiguousPrimaryHasNoId(): void
    {
        $primary = array_merge(self::COROLLA_A, ['vehicle_id' => null]);
        $c = new TestClient([TestClient::ok(['success' => true, 'vehicle' => $primary, 'match' => 'ambiguous', 'alternatives' => [self::COROLLA_A, self::HILUX]])]);
        $r = $c->lookup('ABC123', 'VIC');
        $this->assertNull($r['vehicle']['vehicle_id']);
        $this->assertSame([5152708, 1380801], $r['candidate_ids']);
    }

    public function testNotFoundIsANormalResult(): void
    {
        $c = new TestClient([TestClient::ok(['success' => false, 'vehicle' => null, 'code' => 'not_found', 'error' => 'No vehicle found', 'request_id' => 'req_nf'])]);
        $r = $c->lookup('ZZZ999', 'NSW');
        $this->assertFalse($r['success']);
        $this->assertSame('not_found', $r['code']);
        $this->assertNull($r['vehicle']);
        $this->assertSame([], $r['candidate_ids']);
        $this->assertNull($r['match']);
        $this->assertSame('req_nf', $r['request_id']);
    }

    public function testSandboxDetailedAndHeaderFallbacks(): void
    {
        $c = new TestClient([
            TestClient::ok(['success' => true, 'vehicle' => self::HILUX, 'sandbox' => true], ['X-Request-ID' => 'req_hdr', 'X-Topup-Remaining' => '17']),
            TestClient::ok(['success' => true, 'vehicle' => self::HILUX], ['X-RateLimit-Limit' => 'unlimited']),
        ]);
        $r = $c->lookup('TEST123', 'VIC', true);
        $this->assertSame('true', $c->calls[0]['params']['detailed']);
        $this->assertTrue($r['sandbox']);
        $this->assertSame('req_hdr', $r['request_id']);
        $this->assertSame(17, $r['rate_limit']['topup_remaining']);
        $this->assertNull($c->lookup('ABC123', 'VIC')['rate_limit']['limit']);
    }

    public function testInternalErrorRetriedThenReturned(): void
    {
        $internal = ['success' => false, 'vehicle' => null, 'code' => 'internal_error', 'error' => 'Internal error'];
        $c = new TestClient([TestClient::ok($internal), TestClient::ok(['success' => true, 'vehicle' => self::HILUX])]);
        $this->assertTrue($c->lookup('ABC123', 'VIC')['success']);
        $this->assertCount(2, $c->calls);
        $this->assertCount(1, $c->sleeps);

        $c = new TestClient(array_fill(0, 4, TestClient::ok($internal)));
        $r = $c->lookup('ABC123', 'VIC');
        $this->assertSame('internal_error', $r['code']);
        $this->assertCount(4, $c->calls);
    }

    public function testInvalidInputMakesNoRequest(): void
    {
        $c = new TestClient([]);
        try {
            $c->lookup('ABC123', 'XX');
            $this->fail('expected exception');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Invalid state', $e->getMessage());
        }
        try {
            $c->lookup('   ', 'VIC');
            $this->fail('expected exception');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('empty', $e->getMessage());
        }
        $this->assertSame([], $c->calls);
        $this->expectException(\InvalidArgumentException::class);
        new Client('');
    }

    // ---- errors ----

    public static function mappingProvider(): array
    {
        return [
            [400, BadRequestException::class, ['detail' => 'Invalid state. Use one of: ACT, NSW'], 'Invalid state. Use one of: ACT, NSW'],
            [401, AuthenticationException::class, ['detail' => 'API key has been deactivated'], 'API key has been deactivated'],
            [401, AuthenticationException::class, null, 'Request failed (HTTP 401)'],
            [403, PermissionDeniedException::class, ['detail' => 'This API key is not allowed from your IP address.'], 'This API key is not allowed from your IP address.'],
            [404, NotFoundException::class, ['success' => false, 'code' => 'not_found', 'error' => 'No vehicle with that vehicle_id.'], 'No vehicle with that vehicle_id.'],
            [418, PlateAPIException::class, ['detail' => 'teapot'], 'teapot'],
        ];
    }

    /** @dataProvider mappingProvider */
    public function test4xxMappingNoRetry(int $status, string $cls, ?array $body, string $expected): void
    {
        $c = new TestClient([TestClient::res($status, $body, ['X-Request-ID' => 'req_x'])]);
        try {
            $c->lookup('ABC123', 'VIC');
            $this->fail('expected ' . $cls);
        } catch (PlateAPIException $e) {
            $this->assertInstanceOf($cls, $e);
            $this->assertSame($expected, $e->getMessage());
            $this->assertSame($status, $e->getStatusCode());
            $this->assertSame('req_x', $e->getRequestId());
        }
        $this->assertCount(1, $c->calls);
        $this->assertSame([], $c->sleeps);
    }

    public function test404CarriesCode(): void
    {
        $c = new TestClient([TestClient::res(404, ['success' => false, 'code' => 'not_found', 'error' => 'No vehicle with that vehicle_id.'])]);
        try {
            $c->vehicleById(1);
            $this->fail('expected NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertSame('not_found', $e->getApiCode());
        }
    }

    public function test429RetryAfterSleptThenSuccess(): void
    {
        $c = new TestClient([
            TestClient::res(429, ['detail' => 'Per-minute rate limit exceeded (10/min on starter plan). Slow down.'], ['Retry-After' => '42']),
            TestClient::ok(['success' => true, 'vehicle' => self::HILUX]),
        ]);
        $this->assertTrue($c->lookup('ABC123', 'VIC')['success']);
        $this->assertSame([42.0], $c->sleeps);
        $this->assertCount(2, $c->calls);
    }

    public function test429AboveMaxWaitRaisesImmediately(): void
    {
        $c = new TestClient([TestClient::res(429, ['detail' => 'Vehicle query rate limit exceeded. Try again later.'], ['Retry-After' => '61'])], ['maxWait' => 60.0]);
        try {
            $c->vehicles(['make' => 'TOYOTA']);
            $this->fail('expected RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertNotInstanceOf(QuotaExceededException::class, $e);
            $this->assertSame(61.0, $e->getRetryAfter());
            $this->assertSame('Vehicle query rate limit exceeded. Try again later.', $e->getMessage());
        }
        $this->assertSame([], $c->sleeps);
        $this->assertCount(1, $c->calls);
    }

    public function test429WithoutRetryAfterUsesBackoffThenRaises(): void
    {
        $c = new TestClient(array_fill(0, 4, TestClient::res(429, ['detail' => 'slow down'])));
        try {
            $c->lookup('ABC123', 'VIC');
            $this->fail('expected RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertNull($e->getRetryAfter());
        }
        $this->assertCount(3, $c->sleeps);
        $this->assertCount(4, $c->calls);
    }

    public function testMaxWaitNullSleepsAnyLength(): void
    {
        $c = new TestClient([TestClient::res(429, ['detail' => 'slow down'], ['Retry-After' => '3600']), TestClient::ok(['success' => true, 'vehicle' => self::HILUX])], ['maxWait' => null]);
        $this->assertTrue($c->lookup('ABC123', 'VIC')['success']);
        $this->assertSame([3600.0], $c->sleeps);
    }

    public function testQuota429RaisesAtOnce(): void
    {
        $c = new TestClient([TestClient::res(429, ['detail' => 'Monthly quota exceeded. Used 600/600 lookups on starter plan. Upgrade at plateapi.com.au'], ['Retry-After' => '3600'])]);
        try {
            $c->lookup('ABC123', 'VIC');
            $this->fail('expected QuotaExceededException');
        } catch (RateLimitException $e) {
            $this->assertInstanceOf(QuotaExceededException::class, $e);
            $this->assertSame('quota_exceeded', $e->getApiCode());
            $this->assertSame(3600.0, $e->getRetryAfter());
            $this->assertStringStartsWith('Monthly quota exceeded', $e->getMessage());
        }
        $this->assertSame([], $c->sleeps);
        $this->assertCount(1, $c->calls);

        $c = new TestClient([TestClient::res(429, ['code' => 'quota_exceeded', 'error' => 'Quota exceeded'])]);
        $this->expectException(QuotaExceededException::class);
        $c->lookup('ABC123', 'VIC');
    }

    public function test503LookupFailedRetriedThenServerException(): void
    {
        $failed = TestClient::res(503, ['success' => false, 'vehicle' => null, 'code' => 'lookup_failed', 'error' => 'Lookup temporarily unavailable. Please retry.', 'request_id' => 'req_lf']);
        $c = new TestClient(array_fill(0, 4, $failed));
        try {
            $c->lookup('ABC123', 'VIC');
            $this->fail('expected ServerException');
        } catch (ServerException $e) {
            $this->assertSame('lookup_failed', $e->getApiCode());
            $this->assertSame(503, $e->getStatusCode());
            $this->assertSame('req_lf', $e->getRequestId());
            $this->assertSame('Lookup temporarily unavailable. Please retry.', $e->getMessage());
        }
        $this->assertCount(4, $c->calls);
        $this->assertCount(3, $c->sleeps);
    }

    public function test503RetryAfterSleptAnd502Html(): void
    {
        $c = new TestClient([
            TestClient::res(503, ['success' => false, 'code' => 'unavailable', 'data' => [], 'total' => 0], ['Retry-After' => '5']),
            TestClient::level('make', ['TOYOTA']),
        ]);
        $this->assertSame(['TOYOTA'], $c->vehicles()['data']);
        $this->assertSame([5.0], $c->sleeps);

        $c = new TestClient(array_fill(0, 4, TestClient::res(502, null)));
        try {
            $c->lookup('ABC123', 'VIC');
            $this->fail('expected ServerException');
        } catch (ServerException $e) {
            $this->assertSame('Request failed (HTTP 502)', $e->getMessage());
            $this->assertNull($e->getApiCode());
        }
    }

    public function testJunkRetryAfterCountsAsAbsent(): void
    {
        $c = new TestClient([TestClient::res(429, ['detail' => 'slow'], ['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT']), TestClient::ok(['success' => true, 'vehicle' => self::HILUX])]);
        $c->lookup('ABC123', 'VIC');
        $this->assertCount(1, $c->sleeps);
        $this->assertGreaterThanOrEqual(1.0, $c->sleeps[0]);
        $this->assertLessThan(2.0, $c->sleeps[0]);
    }

    public function testConnectionErrorRetriedThenRaised(): void
    {
        $c = new TestClient([new \RuntimeException('Connection failed: refused'), new \RuntimeException('Request timed out'), TestClient::ok(['success' => true, 'vehicle' => self::HILUX])]);
        $this->assertTrue($c->lookup('ABC123', 'VIC')['success']);
        $this->assertCount(2, $c->sleeps);

        $c = new TestClient(array_fill(0, 4, new \RuntimeException('Connection failed: refused')));
        try {
            $c->lookup('ABC123', 'VIC');
            $this->fail('expected PlateAPIException');
        } catch (PlateAPIException $e) {
            $this->assertStringStartsWith('Connection failed', $e->getMessage());
            $this->assertSame(0, $e->getStatusCode());
        }
        $this->assertCount(4, $c->calls);
    }

    // ---- vehicles ----

    public function testVehiclesParamsIncludingEmptySeries(): void
    {
        $c = new TestClient([TestClient::level('engine', ['2.8L'])]);
        $r = $c->vehicles(['make' => 'TOYOTA', 'model' => 'HILUX', 'year' => 2020, 'series' => '']);
        $this->assertSame('https://api.plateapi.com.au/api/v1/vehicles', $c->calls[0]['url']);
        $this->assertSame(['make' => 'TOYOTA', 'model' => 'HILUX', 'year' => '2020', 'series' => ''], $c->calls[0]['params']);
        $this->assertTrue($r['success']);
        $this->assertSame('engine', $r['type']);
        $this->assertSame(['2.8L'], $r['data']);
        $this->assertSame(1, $r['total']);
        $this->assertSame([], $r['vehicle_ids']);
    }

    public function testVehiclesTypesAndLevels(): void
    {
        $c = new TestClient([TestClient::level('make', ['YAMAHA']), TestClient::level('year', [2021, 2020]), TestClient::ok(['success' => true, 'data' => [], 'total' => 0])]);
        $c->vehicles(['vehicle_type' => 'motorcycle']);
        $this->assertSame(['type' => 'motorcycle'], $c->calls[0]['params']);
        $this->assertSame([2021, 2020], $c->vehicles(['make' => 'TOYOTA', 'model' => 'HILUX'])['data']);
        $empty = $c->vehicles(['make' => 'NOPE']);
        $this->assertSame([], $empty['data']);
        $this->assertNull($empty['type']);
        try {
            $c->vehicles(['vehicle_type' => 'truck']);
            $this->fail('expected exception');
        } catch (\InvalidArgumentException $e) {
            $this->assertCount(3, $c->calls);
        }
    }

    public function testVehicleLevelIds(): void
    {
        $c = new TestClient([TestClient::level('vehicle', [['vehicle_id' => 5434441, 'select_text' => 'a'], ['vehicle_id' => 2573335, 'select_text' => 'b']])]);
        $r = $c->vehicles(['make' => 'KIA', 'model' => 'SELTOS', 'year' => 2022, 'series' => 'EP81A', 'engine' => '2.0L', 'variant' => 'x']);
        $this->assertSame([5434441, 2573335], $r['vehicle_ids']);
        $this->assertSame('a', $r['data'][0]['select_text']);
    }

    public function testVehicleByIdFields(): void
    {
        $c = new TestClient([TestClient::ok(['success' => true, 'vehicle' => self::RECORD, 'duration_ms' => 2.2]), TestClient::ok(['success' => true, 'vehicle' => self::RECORD])]);
        $r = $c->vehicleById(1380801);
        $this->assertSame('https://api.plateapi.com.au/api/v1/vehicles/1380801', $c->calls[0]['url']);
        $this->assertSame([], $c->calls[0]['params']);
        $this->assertSame(1380801, $r['vehicle_id']);
        $this->assertSame('car', $r['vehicle_type']);
        $this->assertSame('HILUX SR', $r['model']);
        $this->assertSame([2015, 2016, 2017, 2018, 2019, 2020], $r['years']);
        $this->assertSame(4, $r['doors']);
        $this->assertSame('4WD', $r['drive']);
        $this->assertNull($r['body_size']);
        $this->assertSame('full', $r['detail']);
        $c->vehicleById('1380801');
        $this->assertStringEndsWith('/vehicles/1380801', $c->calls[1]['url']);
    }

    public function testVehicleByIdBasicRecord(): void
    {
        $basic = array_merge(self::RECORD, ['vehicle_id' => 7295503, 'detail' => 'basic', 'years' => [], 'series' => null, 'variant' => null, 'long_description' => null]);
        $c = new TestClient([TestClient::ok(['success' => true, 'vehicle' => $basic])]);
        $r = $c->vehicleById(7295503);
        $this->assertSame('basic', $r['detail']);
        $this->assertSame([], $r['years']);
        $this->assertNull($r['series']);
    }

    public static function badIdProvider(): array
    {
        return [['abc'], [''], [-1], ['12a'], ['-5']];
    }

    /** @dataProvider badIdProvider */
    public function testVehicleByIdRejectsBadInput(int|string $bad): void
    {
        $c = new TestClient([]);
        $this->expectException(\InvalidArgumentException::class);
        $c->vehicleById($bad);
    }

    // ---- walker ----

    private static function vehicle(int $id, string $text = '4D Ute'): array
    {
        return ['vehicle_id' => $id, 'select_text' => $text, 'description' => "desc $id", 'long_description' => "long $id"];
    }

    private static function hiluxResponses(bool $trims = true): array
    {
        $responses = [];
        if ($trims) {
            $responses[] = TestClient::level('model', ['HILUX', 'HILUX SR', 'HILUXX', 'CAMRY']);
        }
        array_push($responses,
            TestClient::level('year', [2020, 2019]),
            TestClient::level('series', ['GUN126R']),
            TestClient::level('engine', ['2.8L DIE']),
            TestClient::level('variant', ['4D Ute']),
            TestClient::level('vehicle', [self::vehicle(100)]),
            TestClient::level('series', ['GUN126R']),
            TestClient::level('engine', ['2.8L DIE']),
            TestClient::level('variant', ['4D Ute']),
            TestClient::level('vehicle', [self::vehicle(100)]),
        );
        if ($trims) {
            array_push($responses,
                TestClient::level('year', [2019]),
                TestClient::level('series', ['']),
                TestClient::level('engine', ['2.8L DIE', '2.4L DIE']),
                TestClient::level('variant', ['SR 4D Ute']),
                TestClient::level('vehicle', [self::vehicle(200, 'SR 4D Ute'), self::vehicle(201, 'SR 4D Ute')]),
                TestClient::level('variant', ['SR 4D Ute']),
                TestClient::level('vehicle', [self::vehicle(300, 'SR 4D Ute')]),
            );
        }
        return $responses;
    }

    public function testWalkWithTrimsDedupesAcrossYears(): void
    {
        $responses = self::hiluxResponses();
        $c = new TestClient($responses);
        $found = iterator_to_array($c->walkVehicles('TOYOTA', 'HILUX'), false);
        $this->assertSame([100, 200, 201, 300], array_column($found, 'vehicle_id'));
        $first = $found[0];
        $this->assertSame(['TOYOTA', 'HILUX', 2020, 'GUN126R', '2.8L DIE', '4D Ute'],
            [$first['make'], $first['model'], $first['year'], $first['series'], $first['engine'], $first['variant']]);
        $this->assertSame('desc 100', $first['description']);
        $this->assertSame('car', $first['vehicle_type']);
        $this->assertSame(self::vehicle(100), $first['raw']);
        $this->assertSame('HILUX SR', $found[1]['model']);
        $this->assertSame('', $found[1]['series']);
        $this->assertSame('2.4L DIE', $found[3]['engine']);
        $this->assertSame(['make' => 'TOYOTA'], $c->calls[0]['params']);
        $this->assertSame(['make' => 'TOYOTA', 'model' => 'HILUX', 'year' => '2019', 'series' => 'GUN126R'], $c->calls[7]['params']);
        $this->assertArrayNotHasKey('series', $c->calls[11]['params']);
        $this->assertSame('', $c->calls[12]['params']['series']);
        $this->assertCount(count($responses), $c->calls);
        $this->assertSame(array_fill(0, count($responses), 0.15), $c->sleeps);
    }

    public function testWalkWithoutTrimsSkipsModelsRequest(): void
    {
        $c = new TestClient(self::hiluxResponses(false));
        $found = iterator_to_array($c->walkVehicles('TOYOTA', 'HILUX', ['trims' => false]), false);
        $this->assertSame([100], array_column($found, 'vehicle_id'));
        $this->assertSame(['make' => 'TOYOTA', 'model' => 'HILUX'], $c->calls[0]['params']);
    }

    public function testWalkYearRangeAndEngineFilter(): void
    {
        $c = new TestClient([
            TestClient::level('year', [2021, 2020, 2019]), TestClient::level('series', ['S']),
            TestClient::level('engine', ['2.8L DIE', '2.4L DIE']), TestClient::level('variant', ['V']),
            TestClient::level('vehicle', [self::vehicle(1)]),
        ]);
        $found = iterator_to_array($c->walkVehicles('TOYOTA', 'HILUX', ['trims' => false, 'year_from' => 2020, 'year_to' => 2020, 'engine_contains' => '2.8l']), false);
        $this->assertSame([1], array_column($found, 'vehicle_id'));
        $years = array_unique(array_map(fn ($call) => $call['params']['year'] ?? null, array_slice($c->calls, 1)));
        $this->assertSame(['2020'], array_values($years));
        $this->assertSame('2.8L DIE', $c->calls[3]['params']['engine']);
    }

    public function testWalkPerYearAndKnownIdsResume(): void
    {
        $c = new TestClient(self::hiluxResponses(false));
        $known = null;
        $found = iterator_to_array($c->walkVehicles('TOYOTA', 'HILUX', ['trims' => false, 'per_year' => true], $known), false);
        $this->assertSame([[100, 2020], [100, 2019]], array_map(fn ($v) => [$v['vehicle_id'], $v['year']], $found));
        $this->assertSame([[100, 2020], [100, 2019]], $known);

        $c = new TestClient(self::hiluxResponses());
        $known = [100, 200];
        $found = iterator_to_array($c->walkVehicles('TOYOTA', 'HILUX', [], $known), false);
        $this->assertSame([201, 300], array_column($found, 'vehicle_id'));
        $this->assertSame([100, 200, 201, 300], $known);
    }

    public function testWalkPassesTypeAndDelayZero(): void
    {
        $c = new TestClient([
            TestClient::level('model', ['MT-07']), TestClient::level('year', [2022]), TestClient::level('series', ['RM33']),
            TestClient::level('engine', ['0.7L']), TestClient::level('variant', ['MT-07']), TestClient::level('vehicle', [self::vehicle(7076553, 'MT-07')]),
        ]);
        $found = iterator_to_array($c->walkVehicles('YAMAHA', 'MT-07', ['vehicle_type' => 'motorcycle', 'delay' => 0]), false);
        $this->assertSame('motorcycle', $found[0]['vehicle_type']);
        foreach ($c->calls as $call) {
            $this->assertSame('motorcycle', $call['params']['type']);
        }
        $this->assertSame([], $c->sleeps);
    }

    public function testWalk429MidWalkRaisesAfterEarlierYields(): void
    {
        $responses = self::hiluxResponses(false);
        $responses[5] = TestClient::res(429, ['detail' => 'Vehicle query rate limit exceeded. Try again later.'], ['Retry-After' => '7200']);
        $c = new TestClient($responses);
        $found = [];
        try {
            foreach ($c->walkVehicles('TOYOTA', 'HILUX', ['trims' => false]) as $v) {
                $found[] = $v['vehicle_id'];
            }
            $this->fail('expected RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertSame(7200.0, $e->getRetryAfter());
        }
        $this->assertSame([100], $found);
    }

    public static function walkValidationProvider(): array
    {
        return [
            ['', 'HILUX', []], ['TOYOTA', ' ', []],
            ['TOYOTA', 'HILUX', ['year_from' => 2020, 'year_to' => 2019]],
            ['TOYOTA', 'HILUX', ['delay' => -1]], ['TOYOTA', 'HILUX', ['vehicle_type' => 'bus']],
        ];
    }

    /** @dataProvider walkValidationProvider */
    public function testWalkValidationMakesNoRequest(string $make, string $model, array $options): void
    {
        $c = new TestClient([]);
        try {
            iterator_to_array($c->walkVehicles($make, $model, $options), false);
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame([], $c->calls);
        }
    }

    // ---- account ----

    public function testUsageLogsAndHealth(): void
    {
        $c = new TestClient([
            TestClient::ok(['plan' => 'starter', 'monthly_limit' => 600, 'remaining' => 588, 'topup_credits' => 40]),
            TestClient::ok(['logs' => [['plate' => 'ABC123', 'success' => 1, 'request_id' => 'req_1']], 'count' => 1, 'total' => 9, 'limit' => 50, 'offset' => 10]),
            TestClient::ok(['status' => 'ok']),
            TestClient::res(503, ['detail' => 'down']),
        ]);
        $u = $c->usage();
        $this->assertSame('starter', $u['plan']);
        $this->assertSame(40, $u['topup_credits']);
        $logs = $c->logs(['limit' => 50, 'offset' => 10, 'since' => '2026-10-01T00:00:00', 'plate' => 'ABC123', 'success' => false]);
        $this->assertSame(['limit' => '50', 'offset' => '10', 'since' => '2026-10-01T00:00:00', 'plate' => 'ABC123', 'success' => 'false'], $c->calls[1]['params']);
        $this->assertSame('req_1', $logs['logs'][0]['request_id']);
        $this->assertSame(['status' => 'ok', 'version' => null], $c->health());
        $this->assertArrayNotHasKey('X-API-Key', $c->calls[2]['headers']);
        try {
            $c->health();
            $this->fail('expected ServerException');
        } catch (ServerException $e) {
            $this->assertCount(4, $c->calls);
            $this->assertSame([], $c->sleeps);
        }
    }

    public function testQuickStartFrom010StillWorks(): void
    {
        $c = new TestClient([TestClient::ok(['success' => true, 'vehicle' => ['make' => 'TOYOTA', 'model' => 'HILUX', 'lowest_year' => 2015], 'alternatives' => []])]);
        $result = $c->lookup('ABC123', 'VIC');
        if ($result['success']) {
            $this->assertSame('TOYOTA HILUX', $result['vehicle']['make'] . ' ' . $result['vehicle']['model']);
            $this->assertSame(2015, $result['vehicle']['year']);
        }
        $this->assertSame([], $result['alternatives'] ?? []);
    }
}
