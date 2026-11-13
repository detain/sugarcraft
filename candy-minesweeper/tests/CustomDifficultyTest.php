<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper\Tests;

use SugarCraft\Core\I18n\T;
use SugarCraft\Minesweeper\Lang;
use SugarCraft\Minesweeper\Ui\CustomDifficulty;
use PHPUnit\Framework\TestCase;

final class CustomDifficultyTest extends TestCase
{
    // ─── new (msw-09/-032: renamed from defaults() — the fleet factory
    //     spelling; the picker form seeds from it) ───────────────────────────

    public function testNewReturnsSensibleValues(): void
    {
        $cd = CustomDifficulty::new();
        $this->assertSame(9, $cd->rows);
        $this->assertSame(9, $cd->cols);
        $this->assertSame(10, $cd->mines);
    }

    public function testNewLabelIsReadable(): void
    {
        $cd = CustomDifficulty::new();
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

    public function testSubTenCellBoardRefusesWithTheAreaRuleNotMineBlame(): void
    {
        // msw-18/-020 (UXD repro `candy-minesweeper 3 3`): the CLI derives
        // mines = 1 for a 3×3 — the refusal must name the 10-cell floor,
        // never the mine-count message that blames a number nobody typed.
        $prior = T::locale();
        T::setLocale('en');
        try {
            foreach ([[3, 3, 1], [3, 3, 2], [2, 2, 1], [2, 4, 1]] as [$r, $c, $m]) {
                try {
                    CustomDifficulty::fromInput($r, $c, $m);
                    $this->fail("{$r}×{$c} must refuse with the area rule");
                } catch (\InvalidArgumentException $e) {
                    $this->assertSame(Lang::t('custom.min_area'), $e->getMessage(),
                        "{$r}×{$c} refused with the wrong message: {$e->getMessage()}");
                }
            }

            // The floor itself is legal: exactly 10 cells, one mine.
            $cd = CustomDifficulty::fromInput(2, 5, 1);
            $this->assertSame(1, $cd->mines);
            $transposed = CustomDifficulty::fromInput(5, 2, 1);
            $this->assertSame(5, $transposed->rows);
            $this->assertSame(2, $transposed->cols);

            // Side-length rules still lead (a 1×10 board is a side problem,
            // not an area one — messages stay antecedent-faithful).
            foreach ([[1, 10, 1, 'custom.min_rows'], [10, 1, 1, 'custom.min_cols']] as [$r, $c, $m, $key]) {
                try {
                    CustomDifficulty::fromInput($r, $c, $m);
                    $this->fail("{$r}×{$c} must refuse");
                } catch (\InvalidArgumentException $e) {
                    $this->assertSame(Lang::t($key), $e->getMessage());
                }
            }
        } finally {
            T::setLocale($prior);
        }
    }

    public function testLabelReflectsCurrentValues(): void
    {
        $cd = CustomDifficulty::fromInput(20, 30, 50);
        // msw-09/-033: the label prints WIDTH first (cols=30) then height
        // (rows=20) — the same order the CLI grammar and README use. The
        // rows×cols spelling here used to invert that contract.
        $this->assertSame('30×20 · 50 mines', $cd->label());
    }

    public function testLabelIsTranslatedNotInlined(): void
    {
        // -033's Lang::t leg: the "mines" word used to be an English
        // literal baked into the return string. Deleting the Lang::t route
        // must redden THIS test, not ship silent English.
        $prior = T::locale();
        T::setLocale('de');
        try {
            $cd = CustomDifficulty::fromInput(20, 30, 50);
            $this->assertSame('30×20 · 50 Minen', $cd->label());
        } finally {
            T::setLocale($prior);
        }
    }
}
