<?php

declare(strict_types=1);

namespace CandyCore\Metrics\Tests\Backend;

use CandyCore\Metrics\Backend\JsonStreamBackend;
use PHPUnit\Framework\TestCase;

final class JsonStreamBackendTest extends TestCase
{
    public function testEmitsOneJsonLinePerEvent(): void
    {
        $stream = fopen('php://memory', 'w+');
        $b = new JsonStreamBackend($stream);
        $b->counter('hits', 1, ['route' => '/x']);
        $b->gauge('q', 4);
        $b->histogram('lat', 0.005);
        rewind($stream);
        $contents = (string) stream_get_contents($stream);
        $lines = array_filter(explode("\n", $contents));
        $this->assertCount(3, $lines);
        $a = json_decode((string) $lines[0], true);
        $this->assertSame('counter', $a['kind']);
        $this->assertSame('hits',    $a['name']);
        $this->assertSame(1,         $a['value']);
        $this->assertSame(['route' => '/x'], (array) $a['tags']);
        $b2 = json_decode((string) $lines[1], true);
        $this->assertSame('gauge', $b2['kind']);
        $c = json_decode((string) $lines[2], true);
        $this->assertSame('histogram', $c['kind']);
        fclose($stream);
    }

    public function testRejectsInvalidTarget(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new JsonStreamBackend(42);
    }
}
