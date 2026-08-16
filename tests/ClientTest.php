<?php

declare(strict_types=1);

namespace PlateAPI\Tests;

use PHPUnit\Framework\TestCase;
use PlateAPI\Client;
use PlateAPI\AuthenticationException;
use PlateAPI\QuotaExceededException;
use PlateAPI\RateLimitException;
use PlateAPI\PlateAPIException;

class ClientTest extends TestCase
{
    public function testHealthReturnsOk(): void
    {
        $client = new Client('dummy');
        $health = $client->health();
        $this->assertSame('ok', $health['status']);
    }

    public function testLookupInvalidState(): void
    {
        $client = new Client('dummy');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/invalid state/i');
        $client->lookup('ABC123', 'XX');
    }

    public function testLookupEmptyPlate(): void
    {
        $client = new Client('dummy');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/empty/i');
        $client->lookup('', 'VIC');
    }

    public function testLookupWhitespacePlate(): void
    {
        $client = new Client('dummy');
        $this->expectException(\InvalidArgumentException::class);
        $client->lookup('   ', 'VIC');
    }

    public function testStateCaseInsensitive(): void
    {
        $client = new Client('pk_live_invalid', maxRetries: 0);
        try {
            $client->lookup('TEST123', 'vic');
            $this->fail('Expected exception');
        } catch (\InvalidArgumentException $e) {
            $this->fail('Lowercase state was rejected -- case normalisation broken');
        } catch (AuthenticationException|RateLimitException $e) {
            $this->assertTrue(true);
        }
    }

    public function testAllStatesAccepted(): void
    {
        $client = new Client('pk_live_invalid', maxRetries: 0);
        $states = ['NSW', 'VIC', 'QLD', 'SA', 'WA', 'TAS', 'NT', 'ACT'];
        foreach ($states as $state) {
            try {
                $client->lookup('TEST123', $state);
                $this->fail("Expected exception for state {$state}");
            } catch (\InvalidArgumentException $e) {
                $this->fail("State {$state} was rejected as invalid");
            } catch (\Exception $e) {
                $this->assertTrue(true);
            }
        }
    }

    public function testLookupBadKey(): void
    {
        $client = new Client('pk_live_invalid_key', maxRetries: 0);
        try {
            $client->lookup('TEST123', 'VIC');
            $this->fail('Expected exception');
        } catch (AuthenticationException $e) {
            $this->assertSame(401, $e->getStatusCode());
        } catch (RateLimitException $e) {
            $this->assertTrue(true, 'Rate limited under rapid testing');
        }
    }

    public function testUsageBadKey(): void
    {
        $client = new Client('pk_live_invalid_key', maxRetries: 0);
        try {
            $client->usage();
            $this->fail('Expected exception');
        } catch (AuthenticationException|RateLimitException $e) {
            $this->assertTrue(true);
        }
    }

    public function testLogsBadKey(): void
    {
        $client = new Client('pk_live_invalid_key', maxRetries: 0);
        try {
            $client->logs();
            $this->fail('Expected exception');
        } catch (AuthenticationException|RateLimitException $e) {
            $this->assertTrue(true);
        }
    }

    public function testVehiclesBadKey(): void
    {
        $client = new Client('pk_live_invalid_key', maxRetries: 0);
        try {
            $client->vehicles();
            $this->fail('Expected exception');
        } catch (AuthenticationException|RateLimitException $e) {
            $this->assertTrue(true);
        }
    }

    public function testConnectionError(): void
    {
        $client = new Client('dummy', baseUrl: 'http://127.0.0.1:19', timeout: 2, maxRetries: 0);
        $this->expectException(PlateAPIException::class);
        $client->health();
    }

    public function testCustomOptions(): void
    {
        $client = new Client('dummy', timeout: 10, maxRetries: 1);
        $health = $client->health();
        $this->assertSame('ok', $health['status']);
    }

    public function testTrailingSlashStripped(): void
    {
        $client = new Client('dummy', baseUrl: 'https://api.plateapi.com.au///');
        $health = $client->health();
        $this->assertSame('ok', $health['status']);
    }
}
