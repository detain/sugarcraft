<?php

declare(strict_types=1);

namespace CandyCore\Tick\Tests;

use CandyCore\Tick\Heartbeat;
use PHPUnit\Framework\TestCase;

final class HeartbeatTest extends TestCase
{
    public function testConstructorStoresFields(): void
    {
        $hb = new Heartbeat(time: 1234, project: 'demo', language: 'php', file: 'a.php', duration: 30);
        $this->assertSame(1234, $hb->time);
        $this->assertSame('demo', $hb->project);
        $this->assertSame('php', $hb->language);
        $this->assertSame('a.php', $hb->file);
        $this->assertSame(30, $hb->duration);
    }

    public function testDefaultDurationIs60(): void
    {
        $hb = new Heartbeat(0, 'p', 'l', 'f');
        $this->assertSame(60, $hb->duration);
    }

    public function testFromArrayParsesAllFields(): void
    {
        $hb = Heartbeat::fromArray([
            'time' => 1700000000,
            'project' => 'sugarcraft',
            'language' => 'php',
            'file' => '/src/a.php',
            'duration' => 120,
        ]);
        $this->assertSame(1700000000, $hb->time);
        $this->assertSame('sugarcraft', $hb->project);
        $this->assertSame('php', $hb->language);
        $this->assertSame('/src/a.php', $hb->file);
        $this->assertSame(120, $hb->duration);
    }

    public function testFromArrayUsesDefaultsForMissingFields(): void
    {
        $hb = Heartbeat::fromArray([]);
        $this->assertGreaterThan(0, $hb->time);
        $this->assertSame('unknown', $hb->project);
        $this->assertSame('unknown', $hb->language);
        $this->assertSame('', $hb->file);
        $this->assertSame(60, $hb->duration);
    }

    public function testFromArrayCoercesScalars(): void
    {
        $hb = Heartbeat::fromArray([
            'time' => '42',
            'duration' => '15',
            'project' => 12345,
        ]);
        $this->assertSame(42, $hb->time);
        $this->assertSame(15, $hb->duration);
        $this->assertSame('12345', $hb->project);
    }

    public function testToArrayRoundTrips(): void
    {
        $row = [
            'time' => 1234,
            'project' => 'demo',
            'language' => 'php',
            'file' => 'a.php',
            'duration' => 60,
        ];
        $this->assertSame($row, Heartbeat::fromArray($row)->toArray());
    }
}
