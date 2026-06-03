<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Reel\Render\Mode;

/**
 * Unit tests for the Mode enum backing values and enum cases.
 *
 * @covers \SugarCraft\Reel\Render\Mode
 */
final class ModeTest extends TestCase
{
    /**
     * @testdox all Mode cases have a non-empty string backing value
     */
    public function testAllCasesHaveStringBacking(): void
    {
        foreach (Mode::cases() as $case) {
            $this->assertNotEmpty($case->value, "Mode::{$case->name} has an empty backing value");
        }
    }

    /**
     * @testdox Mode::Ascii backs to 'ascii'
     */
    public function testAsciiBackingValue(): void
    {
        $this->assertSame('ascii', Mode::Ascii->value);
    }

    /**
     * @testdox Mode::HalfBlock backs to 'halfblock'
     */
    public function testHalfBlockBackingValue(): void
    {
        $this->assertSame('halfblock', Mode::HalfBlock->value);
    }

    /**
     * @testdox Mode is a backed string enum (implements BackedString via PHP enum)
     */
    public function testModeIsBackedStringEnum(): void
    {
        // BackedString is not a standalone interface — only an enum case can be
        // checked via enum_implements(). Verify Mode::cases() are UnitEnum and
        // have string backing values.
        foreach (Mode::cases() as $case) {
            $this->assertInstanceOf(\UnitEnum::class, $case);
            $this->assertInstanceOf(\BackedEnum::class, $case);
            $this->assertIsString($case->value);
            $this->assertSame('string', gettype($case->value));
        }
    }

    /**
     * @testdox Mode::label() returns a human-readable string for each case
     */
    public function testLabelReturnsString(): void
    {
        foreach (Mode::cases() as $case) {
            $label = $case->label();
            $this->assertIsString($label);
            $this->assertNotEmpty($label);
        }
    }

    /**
     * @testdox Mode::Ascii label describes plain ASCII
     */
    public function testAsciiLabel(): void
    {
        $this->assertStringContainsString('ASCII', Mode::Ascii->label());
    }

    /**
     * @testdox Mode::HalfBlock label mentions the half-block glyph
     */
    public function testHalfBlockLabel(): void
    {
        $this->assertStringContainsString('▀', Mode::HalfBlock->label());
    }

    /**
     * @testdox Mode::TrueColor label mentions 24-bit color
     */
    public function testTrueColorLabel(): void
    {
        $this->assertStringContainsString('TrueColor', Mode::TrueColor->label());
    }

    /**
     * @testdox Mode::HalfBlock packs 2 source pixel-rows per cell, all others 1
     */
    public function testRowsPerCell(): void
    {
        $this->assertSame(2, Mode::HalfBlock->rowsPerCell());

        // Every non-HalfBlock case consumes a single source pixel-row per cell.
        foreach (Mode::cases() as $case) {
            if ($case === Mode::HalfBlock) {
                continue;
            }
            $this->assertSame(1, $case->rowsPerCell(), "Mode::{$case->name} should consume 1 row per cell");
        }
    }

    /**
     * @testdox Mode::colsPerCell is 1 for every case
     */
    public function testColsPerCell(): void
    {
        foreach (Mode::cases() as $case) {
            $this->assertSame(1, $case->colsPerCell(), "Mode::{$case->name} should consume 1 col per cell");
        }
    }
}
