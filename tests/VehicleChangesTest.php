<?php

declare(strict_types=1);

namespace PlateAPI\Tests;

use PHPUnit\Framework\TestCase;
use PlateAPI\BadRequestException;
use PlateAPI\PlateAPIException;

class VehicleChangesTest extends TestCase
{
    private const URL = 'https://api.plateapi.com.au/api/v1/vehicles/changes';

    private const UPDATED = [
        'vehicle_id' => 1611220, 'change' => 'updated', 'changed_at' => '2026-11-18T03:12:44.120318Z',
        'new_years' => [2027], 'merged_into' => null, 'vehicle_type' => 'car', 'make' => 'TOYOTA',
        'model' => 'HILUX SR', 'year_range' => '2020-ON', 'detail' => 'full',
    ];
    private const MERGED = [
        'vehicle_id' => 5000001, 'change' => 'merged', 'changed_at' => '2026-11-18T04:00:00.000001Z',
        'new_years' => [], 'merged_into' => 1611220, 'vehicle_type' => 'car', 'make' => 'TOYOTA',
        'model' => 'HILUX SR', 'year_range' => null, 'detail' => 'full',
    ];
    private const ADDED = [
        'vehicle_id' => 7000002, 'change' => 'added', 'changed_at' => '2026-11-19T01:00:00.000000Z',
        'merged_into' => null, 'vehicle_type' => 'car', 'make' => 'KIA', 'model' => 'EV5',
        'year_range' => '2025-ON', 'detail' => 'basic',
    ];

    private static function page(array $changes, bool $hasMore = false, ?string $nextCursor = null, float $durationMs = 2.0, string $type = 'car'): array
    {
        return TestClient::ok([
            'success' => true, 'since' => '2026-11-01T00:00:00.000000Z', 'until' => '2026-12-01T05:58:00.000000Z',
            'type' => $type, 'count' => count($changes), 'changes' => $changes, 'has_more' => $hasMore,
            'next_cursor' => $nextCursor, 'duration_ms' => $durationMs,
        ]);
    }

    // ---- one page ----

    public function testSendsParamsAndParsesEveryField(): void
    {
        $c = new TestClient([self::page([self::UPDATED, self::MERGED], true, 'c1')]);
        $r = $c->vehicleChanges(['since' => '2026-11-01', 'vehicle_type' => 'car', 'limit' => 2]);
        $this->assertSame(self::URL, $c->calls[0]['url']);
        $this->assertSame(['since' => '2026-11-01', 'type' => 'car', 'limit' => '2'], $c->calls[0]['params']);
        $this->assertSame('pk_live_test', $c->calls[0]['headers']['X-API-Key']);
        $this->assertSame('plateapi-php/0.3.0', $c->calls[0]['headers']['User-Agent']);

        $this->assertTrue($r['success']);
        $this->assertSame('2026-11-01T00:00:00.000000Z', $r['since']);
        $this->assertSame('2026-12-01T05:58:00.000000Z', $r['until']);
        $this->assertSame('car', $r['type']);
        $this->assertSame(2, $r['count']);
        $this->assertTrue($r['has_more']);
        $this->assertSame('c1', $r['next_cursor']);
        $this->assertSame(2.0, $r['duration_ms']);

        [$first, $second] = $r['changes'];
        $this->assertSame(self::UPDATED, $first);
        $this->assertSame([2027], $first['new_years']);
        $this->assertNull($first['merged_into']);
        $this->assertSame('merged', $second['change']);
        $this->assertSame(1611220, $second['merged_into']);
        $this->assertNull($second['year_range']);
    }

    public function testSendsOnlyGivenParams(): void
    {
        $c = new TestClient([self::page([]), self::page([])]);
        $c->vehicleChanges(['since' => '2026-11-01']);
        $this->assertSame(['since' => '2026-11-01'], $c->calls[0]['params']);
        $c->vehicleChanges(['since' => '2026-11-01', 'vehicle_type' => null, 'limit' => null, 'cursor' => null]);
        $this->assertSame(['since' => '2026-11-01'], $c->calls[1]['params']);
    }

    public function testCursorOnly(): void
    {
        $c = new TestClient([self::page([])]);
        $c->vehicleChanges(['cursor' => 'abc']);
        $this->assertSame(['cursor' => 'abc'], $c->calls[0]['params']);
    }

    public function testMotorcycleType(): void
    {
        $c = new TestClient([self::page([], false, null, 2.0, 'motorcycle')]);
        $r = $c->vehicleChanges(['since' => '2026-11-01', 'vehicle_type' => 'motorcycle']);
        $this->assertSame(['since' => '2026-11-01', 'type' => 'motorcycle'], $c->calls[0]['params']);
        $this->assertSame('motorcycle', $r['type']);
    }

    public function testMissingNewYearsParsesToEmptyList(): void
    {
        $c = new TestClient([self::page([self::ADDED, array_merge(self::UPDATED, ['new_years' => null])])]);
        $r = $c->vehicleChanges(['since' => '2026-11-01']);
        $this->assertSame([], $r['changes'][0]['new_years']);
        $this->assertSame('basic', $r['changes'][0]['detail']);
        $this->assertSame([], $r['changes'][1]['new_years']);
    }

    public function testMissingFieldsParseToNullAndEmpty(): void
    {
        $c = new TestClient([TestClient::ok(['success' => true, 'changes' => [['vehicle_id' => 42], 'junk']])]);
        $r = $c->vehicleChanges(['since' => '2026-11-01']);
        $this->assertSame([
            'vehicle_id' => 42, 'change' => null, 'changed_at' => null, 'new_years' => [], 'merged_into' => null,
            'vehicle_type' => null, 'make' => null, 'model' => null, 'year_range' => null, 'detail' => null,
        ], $r['changes'][0]);
        $this->assertCount(1, $r['changes']);
        $this->assertSame(1, $r['count']);
        $this->assertFalse($r['has_more']);
        $this->assertNull($r['next_cursor']);
        $this->assertNull($r['since']);
        $this->assertNull($r['until']);
        $this->assertNull($r['type']);
        $this->assertNull($r['duration_ms']);

        $c = new TestClient([TestClient::ok([])]);
        $r = $c->vehicleChanges(['since' => '2026-11-01']);
        $this->assertFalse($r['success']);
        $this->assertSame([], $r['changes']);
        $this->assertSame(0, $r['count']);
    }

    public static function argumentErrorProvider(): array
    {
        return [
            'nothing' => [[]],
            'null since' => [['since' => null]],
            'empty since' => [['since' => '']],
            'type only' => [['vehicle_type' => 'car']],
            'bad type' => [['since' => '2026-11-01', 'vehicle_type' => 'truck']],
            'limit 0' => [['since' => '2026-11-01', 'limit' => 0]],
            'limit 101' => [['since' => '2026-11-01', 'limit' => 101]],
            'limit bool' => [['since' => '2026-11-01', 'limit' => true]],
            'limit string' => [['since' => '2026-11-01', 'limit' => '50']],
            'limit float' => [['since' => '2026-11-01', 'limit' => 50.0]],
            'since int' => [['since' => 20261101]],
            'cursor int' => [['cursor' => 123]],
        ];
    }

    /** @dataProvider argumentErrorProvider */
    public function testArgumentErrorsMakeNoRequest(array $options): void
    {
        $c = new TestClient([]);
        try {
            $c->vehicleChanges($options);
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame([], $c->calls);
        }
    }

    // ---- since as a DateTimeInterface ----

    public function testSinceDateTimeIsSentInUtcWithSixDigits(): void
    {
        $c = new TestClient([self::page([]), self::page([])]);
        $c->vehicleChanges(['since' => new \DateTimeImmutable('2026-11-01 10:30:00', new \DateTimeZone('+11:00'))]);
        $this->assertSame(['since' => '2026-10-31T23:30:00.000000Z'], $c->calls[0]['params']);
        $c->vehicleChanges(['since' => new \DateTimeImmutable('2026-11-01 05:58:00.000123', new \DateTimeZone('UTC'))]);
        $this->assertSame(['since' => '2026-11-01T05:58:00.000123Z'], $c->calls[1]['params']);
    }

    public function testSinceMutableDateTimeIsNotChanged(): void
    {
        $c = new TestClient([self::page([])]);
        $since = new \DateTime('2026-11-01 10:30:00', new \DateTimeZone('Australia/Sydney'));
        $c->vehicleChanges(['since' => $since]);
        $this->assertSame(['since' => '2026-10-31T23:30:00.000000Z'], $c->calls[0]['params']);
        $this->assertSame('Australia/Sydney', $since->getTimezone()->getName());
        $this->assertSame('2026-11-01 10:30:00', $since->format('Y-m-d H:i:s'));
    }

    // ---- errors ----

    public function testBadRequestSurfacesApiMessage(): void
    {
        $message = 'Invalid query parameters. since: must be 2026-10-20T00:00:00Z or later.';
        $c = new TestClient([TestClient::res(400, ['detail' => $message])]);
        try {
            $c->vehicleChanges(['since' => '2026-10-01']);
            $this->fail('expected BadRequestException');
        } catch (BadRequestException $e) {
            $this->assertSame($message, $e->getMessage());
            $this->assertSame(400, $e->getStatusCode());
        }
        $this->assertCount(1, $c->calls);
    }

    // ---- whole window ----

    public function testAllFollowsTheCursor(): void
    {
        $c = new TestClient([
            self::page([self::UPDATED], true, 'c1', 1.5),
            self::page([self::MERGED, self::ADDED], false, null, 2.5),
        ]);
        $r = $c->allVehicleChanges(['since' => '2026-11-01', 'vehicle_type' => 'car', 'limit' => 1]);
        $this->assertSame(['since' => '2026-11-01', 'type' => 'car', 'limit' => '1'], $c->calls[0]['params']);
        $this->assertSame(['type' => 'car', 'limit' => '1', 'cursor' => 'c1'], $c->calls[1]['params']);
        $this->assertSame([1611220, 5000001, 7000002], array_column($r['changes'], 'vehicle_id'));
        $this->assertSame([], $r['changes'][2]['new_years']);
        $this->assertSame(3, $r['count']);
        $this->assertFalse($r['has_more']);
        $this->assertNull($r['next_cursor']);
        $this->assertSame('2026-12-01T05:58:00.000000Z', $r['until']);
        $this->assertSame('2026-11-01T00:00:00.000000Z', $r['since']);
        $this->assertSame('car', $r['type']);
        $this->assertTrue($r['success']);
        $this->assertSame(4.0, $r['duration_ms']);
        $this->assertSame([], $c->sleeps);
    }

    public function testAllSinglePageIgnoresCursorOption(): void
    {
        $c = new TestClient([self::page([self::UPDATED])]);
        $r = $c->allVehicleChanges(['since' => '2026-11-01', 'cursor' => 'stale']);
        $this->assertCount(1, $c->calls);
        $this->assertSame(['since' => '2026-11-01'], $c->calls[0]['params']);
        $this->assertSame(1, $r['count']);
    }

    public static function allArgumentErrorProvider(): array
    {
        return [
            'nothing' => [[]],
            'cursor only' => [['cursor' => 'abc']],
            'bad type' => [['since' => '2026-11-01', 'vehicle_type' => 'bus']],
            'bad limit' => [['since' => '2026-11-01', 'limit' => 0]],
        ];
    }

    /** @dataProvider allArgumentErrorProvider */
    public function testAllArgumentErrorsMakeNoRequest(array $options): void
    {
        $c = new TestClient([]);
        try {
            $c->allVehicleChanges($options);
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame([], $c->calls);
        }
    }

    public function testAllHasMoreWithoutCursorThrows(): void
    {
        foreach ([null, ''] as $cursor) {
            $c = new TestClient([self::page([self::UPDATED], true, $cursor)]);
            try {
                $c->allVehicleChanges(['since' => '2026-11-01']);
                $this->fail('expected PlateAPIException');
            } catch (PlateAPIException $e) {
                $this->assertSame(PlateAPIException::class, get_class($e));
            }
            $this->assertCount(1, $c->calls);
        }
    }

    public function testAllRepeatedCursorThrows(): void
    {
        $c = new TestClient([
            self::page([self::UPDATED], true, 'c1'),
            self::page([self::MERGED], true, 'c1'),
        ]);
        try {
            $c->allVehicleChanges(['since' => '2026-11-01']);
            $this->fail('expected PlateAPIException');
        } catch (PlateAPIException $e) {
            $this->assertSame(PlateAPIException::class, get_class($e));
        }
        $this->assertCount(2, $c->calls);
    }
}
