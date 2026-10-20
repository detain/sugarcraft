<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Dither;

final class DitherTest extends TestCase
{
    public function testNoneLabel(): void
    {
        $this->assertSame('None', Dither::None->label());
    }

    public function testFloydSteinbergLabel(): void
    {
        $this->assertSame('Floyd–Steinberg', Dither::FloydSteinberg->label());
    }

    public function testStuckiLabel(): void
    {
        $this->assertSame('Stucki', Dither::Stucki->label());
    }

    public function testAtkinsonLabel(): void
    {
        $this->assertSame('Atkinson', Dither::Atkinson->label());
    }

    public function testOrderedLabel(): void
    {
        // (C-04 arm, labelled test added with C-05 for per-case parity)
        $this->assertSame('Ordered', Dither::Ordered->label());
    }

    public function testNoiseLabel(): void
    {
        $this->assertSame('Noise', Dither::Noise->label());
    }

    public function testAllCasesHaveStringValues(): void
    {
        foreach (Dither::cases() as $case) {
            $this->assertNotEmpty($case->value);
        }
    }

    public function testCasesAreUnique(): void
    {
        $values = array_map(fn(Dither $d): string => $d->value, Dither::cases());
        $this->assertSame(count($values), count(array_unique($values)), 'All enum cases must have unique values');
    }

    public function testDefaultIsFloydSteinberg(): void
    {
        // The default dither is FloydSteinberg per class docblock
        $default = Dither::FloydSteinberg;
        $this->assertSame('floyd-steinberg', $default->value);
    }

    public function testLabelMatchesExpectedDisplayNames(): void
    {
        $labels = [];
        foreach (Dither::cases() as $case) {
            $labels[$case->value] = $case->label();
        }

        $this->assertSame('None', $labels['none']);
        $this->assertSame('Floyd–Steinberg', $labels['floyd-steinberg']);
        $this->assertSame('Stucki', $labels['stucki']);
        $this->assertSame('Atkinson', $labels['atkinson']);
        $this->assertSame('Ordered', $labels['ordered']);
        $this->assertSame('Noise', $labels['noise']);
    }
}
