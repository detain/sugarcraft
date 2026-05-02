<?php

declare(strict_types=1);

namespace CandyCore\Charts\Tests\LineChart;

use CandyCore\Charts\LineChart\Streamline;
use PHPUnit\Framework\TestCase;

final class StreamlineTest extends TestCase
{
    public function testEmptyRendersBlankCanvas(): void
    {
        $out = Streamline::new(8, 3)->view();
        $this->assertSame(3, substr_count($out, "\n") + 1);
    }

    public function testPushAppendsAndCapsAtWidth(): void
    {
        $s = Streamline::new(5, 4);
        for ($i = 0; $i < 10; $i++) {
            $s = $s->push($i);
        }
        $this->assertCount(5, $s->window);
        // Window holds the most recent 5 samples (5..9).
        $this->assertSame([5, 6, 7, 8, 9], $s->window);
    }

    public function testPushAllAppendsAll(): void
    {
        $s = Streamline::new(10, 4)->pushAll([1, 2, 3]);
        $this->assertSame([1, 2, 3], $s->window);
    }

    public function testWithSizeShrinksWindow(): void
    {
        $s = Streamline::new(10, 4)->pushAll(range(1, 10))->withSize(3, 4);
        $this->assertCount(3, $s->window);
        $this->assertSame([8, 9, 10], $s->window);
    }

    public function testViewProducesCanvas(): void
    {
        $out = Streamline::new(5, 3)->pushAll([1, 3, 2, 4, 1])->view();
        $this->assertSame(3, substr_count($out, "\n") + 1);
        $this->assertStringContainsString('*', $out);
    }
}
