<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Rendition;

/**
 * Unit coverage for the {@see Rendition} line-attribute enum that backs the
 * DEC special-line family (`ESC # 3`–`ESC # 6`). The discriminant values are
 * part of the wire contract baked into snapshot normalisers, so they are
 * pinned literally here rather than derived.
 */
final class RenditionTest extends TestCase
{
    public function testThereAreExactlyTheFourDecLineStates(): void
    {
        $this->assertCount(4, Rendition::cases());
    }

    public static function valueProvider(): array
    {
        return [
            'single width/height (DECSWL)' => [Rendition::None, 0],
            'double-height top (DECDHL)' => [Rendition::DoubleTop, 1],
            'double-height bottom (DECDHL)' => [Rendition::DoubleBottom, 2],
            'double width (DECDWL)' => [Rendition::DoubleWidth, 3],
        ];
    }

    #[DataProvider('valueProvider')]
    public function testDiscriminantValuesAreStable(Rendition $rendition, int $value): void
    {
        $this->assertSame($value, $rendition->value);
        $this->assertSame($rendition, Rendition::from($value));
        $this->assertSame($rendition, Rendition::tryFrom($value));
    }

    public static function doubleHeightProvider(): array
    {
        return [
            'None is single height' => [Rendition::None, false],
            'DoubleTop is double height' => [Rendition::DoubleTop, true],
            'DoubleBottom is double height' => [Rendition::DoubleBottom, true],
            'DoubleWidth is single height' => [Rendition::DoubleWidth, false],
        ];
    }

    #[DataProvider('doubleHeightProvider')]
    public function testIsDoubleHeightFlagsOnlyTheTwoHalfLines(Rendition $rendition, bool $expected): void
    {
        $this->assertSame($expected, $rendition->isDoubleHeight());
    }

    public function testTryFromRejectsAnUnknownValue(): void
    {
        $this->assertNull(Rendition::tryFrom(7));
    }
}
