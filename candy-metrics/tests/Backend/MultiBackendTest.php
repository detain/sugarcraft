<?php

declare(strict_types=1);

namespace CandyCore\Metrics\Tests\Backend;

use CandyCore\Metrics\Backend\InMemoryBackend;
use CandyCore\Metrics\Backend\MultiBackend;
use PHPUnit\Framework\TestCase;

final class MultiBackendTest extends TestCase
{
    public function testFanoutToAllChildren(): void
    {
        $a = new InMemoryBackend();
        $b = new InMemoryBackend();
        $c = new InMemoryBackend();
        $multi = new MultiBackend($a, $b, $c);

        $multi->counter('hits', 1);
        $multi->gauge('q', 4);
        $multi->histogram('lat', 0.1);

        foreach ([$a, $b, $c] as $child) {
            $this->assertSame(1.0,         $child->counterValue('hits'));
            $this->assertSame(4.0,         $child->gaugeValue('q'));
            $this->assertSame([0.1],       $child->histogramValues('lat'));
        }
    }

    public function testEmptyMultiIsHarmless(): void
    {
        $multi = new MultiBackend();
        $multi->counter('hits', 1);
        $multi->gauge('q', 4);
        $multi->histogram('lat', 0.1);
        $this->assertTrue(true);
    }
}
