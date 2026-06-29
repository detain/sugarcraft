<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Tests\Backend;

use SugarCraft\Metrics\Backend\StatsdBackend;
use PHPUnit\Framework\TestCase;

final class StatsdBackendTest extends TestCase
{
    public function testCounterFormat(): void
    {
        $sock = fopen('php://memory', 'w+');
        $b = new StatsdBackend(existingSocket: $sock);
        $b->counter('hits', 1.0, ['route' => '/x', 'env' => 'prod']);
        rewind($sock);
        $payload = (string) stream_get_contents($sock);
        $this->assertStringStartsWith('hits:1|c', $payload);
        $this->assertStringContainsString('|#', $payload);
        $this->assertStringContainsString('route:/x', $payload);
        $this->assertStringContainsString('env:prod', $payload);
        fclose($sock);
    }

    public function testHistogramFormat(): void
    {
        $sock = fopen('php://memory', 'w+');
        $b = new StatsdBackend(existingSocket: $sock);
        $b->histogram('lat', 0.005);
        rewind($sock);
        $this->assertSame('lat:0.005|h', (string) stream_get_contents($sock));
        fclose($sock);
    }

    public function testGaugeFormat(): void
    {
        $sock = fopen('php://memory', 'w+');
        $b = new StatsdBackend(existingSocket: $sock);
        $b->gauge('q', 7);
        rewind($sock);
        $this->assertSame('q:7|g', (string) stream_get_contents($sock));
        fclose($sock);
    }

    public function testLegacyStatsdDropsTags(): void
    {
        $sock = fopen('php://memory', 'w+');
        $b = new StatsdBackend(dogstatsd: false, existingSocket: $sock);
        $b->counter('hits', 1, ['route' => '/x']);
        rewind($sock);
        $payload = (string) stream_get_contents($sock);
        $this->assertSame('hits:1|c', $payload);
        $this->assertStringNotContainsString('|#', $payload);
        fclose($sock);
    }

    public function testIntFormatStripsTrailingZeros(): void
    {
        $sock = fopen('php://memory', 'w+');
        $b = new StatsdBackend(existingSocket: $sock);
        $b->counter('a', 5.0);
        rewind($sock);
        $this->assertSame('a:5|c', (string) stream_get_contents($sock));
        fclose($sock);
    }

    public function testUpDownCounterEmitsSignedDelta(): void
    {
        $sock = fopen('php://memory', 'w+');
        $b = new StatsdBackend(existingSocket: $sock);
        $b->upDownCounter('conns', 1.0);
        rewind($sock);
        $this->assertSame('conns:+1|g', (string) stream_get_contents($sock));
        fclose($sock);

        $sock2 = fopen('php://memory', 'w+');
        $b2 = new StatsdBackend(existingSocket: $sock2);
        $b2->upDownCounter('conns', -1.0);
        rewind($sock2);
        $this->assertSame('conns:-1|g', (string) stream_get_contents($sock2));
        fclose($sock2);
    }

    public function testUpDownCounterEmitsZeroWithPlusSign(): void
    {
        $sock = fopen('php://memory', 'w+');
        $b = new StatsdBackend(existingSocket: $sock);
        $b->upDownCounter('delta', 0.0);
        rewind($sock);
        // StatsD treats +0 as a no-op delta, which is correct for a zero increment.
        $this->assertSame('delta:+0|g', (string) stream_get_contents($sock));
        fclose($sock);
    }

    public function testAsyncCounterEmitsCounter(): void
    {
        $sock = fopen('php://memory', 'w+');
        $b = new StatsdBackend(existingSocket: $sock);
        $b->asyncCounter('jvm_gc_count', 1.0, ['gen' => 'young']);
        rewind($sock);
        $payload = (string) stream_get_contents($sock);
        $this->assertStringStartsWith('jvm_gc_count:1|c', $payload);
        $this->assertStringContainsString('|#', $payload);
        fclose($sock);
    }

    public function testAsyncGaugeEmitsGauge(): void
    {
        $sock = fopen('php://memory', 'w+');
        $b = new StatsdBackend(existingSocket: $sock);
        $b->asyncGauge('heap_used', 7.5, ['area' => 'old']);
        rewind($sock);
        $payload = (string) stream_get_contents($sock);
        $this->assertStringStartsWith('heap_used:7.5|g', $payload);
        $this->assertStringContainsString('|#', $payload);
        fclose($sock);
    }
}
