<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use SugarCraft\CellBuffer\Buffer;
use SugarCraft\CellBuffer\Cell;
use SugarCraft\Testing\Snapshot\Assertions;

/**
 * The grid walker used to derive width from row 0 and skip any cell whose
 * 'rune' key was not isset — so the README's documented plain-string grid
 * (and every typo'd key, empty array, ragged tail, or all-wildcard grid)
 * passed with ZERO comparisons. This file pins the fail-loud cure: shape
 * validation at the boundary, every expected cell asserted, and — the
 * self-consumption contract — a deliberately wrong grid MUST redden.
 */
final class CellGridShapeTest extends TestCase
{
    private function blanks(int $width, int $height): Buffer
    {
        return Buffer::new($width, $height);
    }

    /** The documented shape: a plain 2D string grid now WORKS as written. */
    public function testTheDocumentedStringGridShapeMatchesABlankBuffer(): void
    {
        Assertions::assertCellGrid([[' '], [' '], [' ']], $this->blanks(1, 3));

        // A row may also be written as one plain string: cluster per cell.
        Assertions::assertCellGrid(['abc'], $this->blanks(3, 1)
            ->withCellAt(0, 0, Cell::new('a'))
            ->withCellAt(1, 0, Cell::new('b'))
            ->withCellAt(2, 0, Cell::new('c')));
    }

    /** Wide clusters in string rows assert the same facts array rows do. */
    public function testWideClusterInStringRowClaimsItsContinuationCell(): void
    {
        $buffer = $this->blanks(2, 1)
            ->withCellAt(0, 0, Cell::new('語', width: 2))
            ->withCellAt(1, 0, Cell::continuation());

        Assertions::assertCellGrid(['語'], $buffer);
    }

    /**
     * HEADLINE regression: the old code walked this grid green with zero
     * assertions (string offset → isset('rune') false → every cell skipped).
     */
    public function testAWrongStringGridAgainstABlankBufferRedsInsteadOfPassing(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("rune: expected 'x', got ' '");

        Assertions::assertCellGrid(['xxx   '], $this->blanks(6, 1));
    }

    /** Self-consumption: a deliberately wrong cell at (3, 0) is named. */
    public function testPlantedWrongCellIsNamedByCoordinates(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('(3, 0)');

        Assertions::assertCellGrid(['ABCD'], $this->blanks(4, 1)
            ->withCellAt(0, 0, Cell::new('A'))
            ->withCellAt(1, 0, Cell::new('B'))
            ->withCellAt(2, 0, Cell::new('C'))
            ->withCellAt(3, 0, Cell::new('Z')));
    }

    public function testUnknownCellKeyThrowsInsteadOfSkippingTheCheck(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("unknown cell key 'run' (allowed: rune, width)");

        Assertions::assertCellGrid([[['run' => 'A']]], $this->blanks(1, 1));
    }

    public function testTypoOfTheWidthKeyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("unknown cell key 'wide'");

        Assertions::assertCellGrid([[['rune' => 'A', 'wide' => 2]]], $this->blanks(1, 1));
    }

    public function testEmptyArrayCellThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('an empty array cell asserts nothing');

        Assertions::assertCellGrid([[[]]], $this->blanks(1, 1));
    }

    public function testNullRuneInsideAnArrayCellThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'rune' => null is not a string");

        Assertions::assertCellGrid([[['rune' => null]]], $this->blanks(1, 1));
    }

    public function testRaggedArrayRowThrowsNamingTheTailGap(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ragged rows used to leave their tails never-compared');

        Assertions::assertCellGrid([[['rune' => 'A']]], $this->blanks(3, 1));
    }

    public function testShortStringRowThrowsToo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('string row expands to 2 cells');

        Assertions::assertCellGrid(['ab'], $this->blanks(4, 1));
    }

    public function testAssociativeRowThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-sequential keys');

        Assertions::assertCellGrid([[5 => 'A']], $this->blanks(3, 1));
    }

    public function testAssociativeOuterGridThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a list of rows keyed 0..n-1');

        Assertions::assertCellGrid([1 => ['A']], $this->blanks(1, 2));
    }

    public function testAllWildcardGridComparesNothingAndFails(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('compared zero cells');

        Assertions::assertCellGrid([[null, null], null], $this->blanks(2, 2));
    }

    public function testHeightMismatchFailsLoud(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Dimension mismatch: expected 1 rows, got 3');

        Assertions::assertCellGrid([['A']], $this->blanks(1, 3));
    }

    public function testMultiClusterStringCellThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('2 grapheme clusters');

        Assertions::assertCellGrid([['ab']], $this->blanks(1, 1));
    }

    public function testWideStringCellThrowsWithTheExplicitRecipe(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("use ['rune' => '語', 'width' => 2]");

        Assertions::assertCellGrid([['語']], $this->blanks(1, 1));
    }

    public function testEmptyStringCellThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('an empty-string cell asserts nothing');

        Assertions::assertCellGrid([['']], $this->blanks(1, 1));
    }

    public function testExplicitNullWildcardSkipsOnlyItsOwnPosition(): void
    {
        // The documented wildcard still works: position 1 is skipped even
        // though it disagrees, position 0 is still compared.
        Assertions::assertCellGrid([[Cell::new('A')->rune(), null]], $this->blanks(2, 1)
            ->withCellAt(0, 0, Cell::new('A'))
            ->withCellAt(1, 0, Cell::new('Anything')));

        $this->expectException(AssertionFailedError::class);
        Assertions::assertCellGrid([[null, 'Q']], $this->blanks(2, 1)
            ->withCellAt(1, 0, Cell::new('R')));
    }

    public function testMismatchDumpIsCappedAndCarriesTotals(): void
    {
        $rows = [];
        for ($r = 0; $r < 25; $r++) {
            $rows[] = [['rune' => 'X']];
        }

        try {
            Assertions::assertCellGrid($rows, $this->blanks(1, 25));
            $this->fail('expected a mismatch');
        } catch (AssertionFailedError $e) {
            $msg = $e->getMessage();
            $this->assertStringContainsString('... and 5 more mismatching cells', $msg);
            $this->assertStringContainsString('(25 of 25 cells compared; 0 skipped as wildcards)', $msg);
        }
    }

    public function testWidthLegIsComparedNotSkipped(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('width: expected 2, got 1');

        Assertions::assertCellGrid([[['rune' => 'A', 'width' => 2]]], $this->blanks(1, 1)
            ->withCellAt(0, 0, Cell::new('A')));
    }
}
