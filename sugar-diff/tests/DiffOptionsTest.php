<?php

declare(strict_types=1);

namespace SugarCraft\Diff\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Diff\DiffOptions;

/**
 * Coercion + immutability pins for the options value object: every `with*()`
 * returns a new instance, negatives clamp at their safe floor, and the
 * defaults reproduce the ported engine exactly.
 */
final class DiffOptionsTest extends TestCase
{
    public function testDefaultsReproduceThePortedEngine(): void
    {
        $options = DiffOptions::new();

        $this->assertSame(3, $options->contextLines);
        $this->assertSame(DiffOptions::DEFAULT_CONTEXT_LINES, $options->contextLines);
        $this->assertSame(250_000, $options->maxLcsCells);
        $this->assertSame(DiffOptions::DEFAULT_MAX_LCS_CELLS, $options->maxLcsCells);
        $this->assertFalse($options->ignoreWhitespace);
        $this->assertFalse($options->ignoreCase);
        $this->assertFalse($options->noNewlineMarker);
        $this->assertFalse($options->devNullOnEmptySide);
    }

    public function testContextLinesClampAtZero(): void
    {
        $this->assertSame(0, DiffOptions::new()->withContextLines(-5)->contextLines);
        $this->assertSame(7, DiffOptions::new()->withContextLines(7)->contextLines);
    }

    public function testMaxLcsCellsClampAtZero(): void
    {
        $this->assertSame(0, DiffOptions::new()->withMaxLcsCells(-1)->maxLcsCells);
        // Zero is meaningful, not refused: it forces the fallback block always.
        $this->assertSame(0, DiffOptions::new()->withMaxLcsCells(0)->maxLcsCells);
    }

    public function testFluentSettersReturnNewInstancesAndLeaveReceiverAlone(): void
    {
        $base = DiffOptions::new();
        $wider = $base->withContextLines(9);

        $this->assertNotSame($base, $wider);
        $this->assertSame(3, $base->contextLines, 'receiver unchanged');
        $this->assertSame(9, $wider->contextLines);
        // Untouched fields carry over.
        $this->assertSame(DiffOptions::DEFAULT_MAX_LCS_CELLS, $wider->maxLcsCells);
        $this->assertFalse($wider->ignoreCase);
    }

    public function testEachSetterChangesOnlyItsOwnField(): void
    {
        $base = DiffOptions::new();

        $cases = [
            'withIgnoreWhitespace' => $base->withIgnoreWhitespace(),
            'withIgnoreCase' => $base->withIgnoreCase(),
            'withNoNewlineMarker' => $base->withNoNewlineMarker(),
            'withDevNullOnEmptySide' => $base->withDevNullOnEmptySide(),
        ];

        foreach ($cases as $name => $option) {
            $this->assertSame(3, $option->contextLines, $name);
            $this->assertSame(250_000, $option->maxLcsCells, $name);
            $this->assertSame($name === 'withIgnoreWhitespace', $option->ignoreWhitespace);
            $this->assertSame($name === 'withIgnoreCase', $option->ignoreCase);
            $this->assertSame($name === 'withNoNewlineMarker', $option->noNewlineMarker);
            $this->assertSame($name === 'withDevNullOnEmptySide', $option->devNullOnEmptySide);
        }
    }

    public function testBoolSettersAcceptExplicitFalseToUndo(): void
    {
        $on = DiffOptions::new()->withIgnoreCase();
        $off = $on->withIgnoreCase(false);

        $this->assertTrue($on->ignoreCase);
        $this->assertFalse($off->ignoreCase);
    }
}
