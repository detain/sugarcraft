<?php

declare(strict_types=1);

namespace SugarCraft\Mines\Tests;

use SugarCraft\Mines\Ui\CustomDifficulty;
use PHPUnit\Framework\TestCase;

final class CustomDifficultyTest extends TestCase
{
    // ─── defaults ─────────────────────────────────────────────────────────────

    public function testDefaultsReturnsSensibleValues(): void
    {
        $cd = CustomDifficulty::defaults();
        $this->assertSame(9, $cd->rows);
        $this->assertSame(9, $cd->cols);
        $this->assertSame(10, $cd->mines);
    }

    public function testDefaultsLabelIsReadable(): void
    {
        $cd = CustomDifficulty::defaults();
        $this->assertSame('9×9 · 10 mines', $cd->label());
    }

    // ─── fromInput validation ──────────────────────────────────────────────────

    public function testValidInputCreatesInstance(): void
    {
        $cd = CustomDifficulty::fromInput(10, 12, 15);
        $this->assertSame(10, $cd->rows);
        $this->assertSame(12, $cd->cols);
        $this->assertSame(15, $cd->mines);
    }

    public function testRowsBelowTwoThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CustomDifficulty::fromInput(1, 10, 5);
    }

    public function testColsBelowTwoThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CustomDifficulty::fromInput(10, 1, 5);
    }

    public function testRowsAboveFiftyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CustomDifficulty::fromInput(51, 10, 5);
    }

    public function testColsAboveFiftyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CustomDifficulty::fromInput(10, 51, 5);
    }

    public function testMinesBelowOneThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CustomDifficulty::fromInput(10, 10, 0);
    }

    public function testMinesAboveMaxThrows(): void
    {
        // 10×10 grid = 100 cells, max safe area = 100 - 9 = 91 mines.
        $this->expectException(\InvalidArgumentException::class);
        CustomDifficulty::fromInput(10, 10, 92);
    }

    public function testMinesAtExactMaxIsAllowed(): void
    {
        // 10×10 = 100 cells, leaving 9-cell safe area → max 91 mines.
        $cd = CustomDifficulty::fromInput(10, 10, 91);
        $this->assertSame(91, $cd->mines);
    }

    public function testLabelReflectsCurrentValues(): void
    {
        $cd = CustomDifficulty::fromInput(20, 30, 50);
        $this->assertSame('20×30 · 50 mines', $cd->label());
    }
}
