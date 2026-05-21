<?php

declare(strict_types=1);

namespace SugarCraft\Tick\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Tick\Milestone;

final class MilestoneTest extends TestCase
{
    public function testConstruction(): void
    {
        $m = new Milestone('v1.0', 1719000000, 'First stable');
        $this->assertSame('v1.0', $m->name);
        $this->assertSame(1719000000, $m->time);
        $this->assertSame('First stable', $m->description);
    }

    public function testDefaultDescription(): void
    {
        $m = new Milestone('v2.0', 1719100000);
        $this->assertSame('', $m->description);
    }

    public function testFromArray(): void
    {
        $row = [
            'name' => 'beta release',
            'time' => 1719200000,
            'description' => 'Beta quality',
        ];
        $m = Milestone::fromArray($row);
        $this->assertSame('beta release', $m->name);
        $this->assertSame(1719200000, $m->time);
        $this->assertSame('Beta quality', $m->description);
    }

    public function testFromArrayWithDefaults(): void
    {
        $m = Milestone::fromArray([]);
        $this->assertSame('', $m->name);
        $this->assertSame(0, $m->time);
        $this->assertSame('', $m->description);
    }

    public function testToArray(): void
    {
        $m = new Milestone('rc1', 1719300000, 'Release candidate');
        $arr = $m->toArray();
        $this->assertSame('rc1', $arr['name']);
        $this->assertSame(1719300000, $arr['time']);
        $this->assertSame('Release candidate', $arr['description']);
    }

    public function testRoundTrip(): void
    {
        $original = new Milestone('final', 1719400000, 'Done');
        $restored = Milestone::fromArray($original->toArray());
        $this->assertSame($original->name, $restored->name);
        $this->assertSame($original->time, $restored->time);
        $this->assertSame($original->description, $restored->description);
    }
}
