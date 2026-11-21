<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Symbol;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Symbol\SymbolMap;

/**
 * Rank 31: the per-cell Hamming walk is memoised on (bitmap, allowInverse,
 * limit), and a blank cell answers from a slot of its own.
 *
 * @covers \SugarCraft\Image\Symbol\SymbolMap::findCandidates
 */
final class SymbolMapCandidateMemoTest extends TestCase
{
    private function memo(SymbolMap $map, string $property): array
    {
        $prop = new \ReflectionProperty(SymbolMap::class, $property);

        return $prop->getValue($map);
    }

    public function testRepeatedQueryAnswersFromTheMemoWithoutRescanning(): void
    {
        $map    = SymbolMap::new();
        $bitmap = 0x0F0F0F0F0F0F0F0F;

        $first = $map->findCandidates($bitmap, true, 8);
        $this->assertCount(1, $this->memo($map, 'candidateMemo'), 'the first ask fills one memo slot');

        // A second ask must return the identical list and must not grow the memo:
        // it was answered from the stored entry, not walked again.
        $second = $map->findCandidates($bitmap, true, 8);
        $this->assertSame($first, $second);
        $this->assertCount(1, $this->memo($map, 'candidateMemo'));
    }

    public function testMemoKeyIncludesInverseAndLimit(): void
    {
        $map    = SymbolMap::new();
        $bitmap = 0x00000000FFFFFFFF;

        $map->findCandidates($bitmap, true, 8);
        $map->findCandidates($bitmap, false, 8);
        $map->findCandidates($bitmap, true, 2);

        $this->assertCount(3, $this->memo($map, 'candidateMemo'), 'three distinct keys, three slots');
    }

    public function testMemoizedAnswerMatchesAFreshMapWalk(): void
    {
        $bitmap = 0x3C3C3C3C3C3C3C3C;

        $memoised = SymbolMap::new();
        $memoised->findCandidates($bitmap, true, 8);
        $fresh = SymbolMap::new()->findCandidates($bitmap, true, 8);

        $this->assertSame(
            array_map(static fn (array $e): array => [$e[0]->char, $e[1]], $fresh),
            array_map(static fn (array $e): array => [$e[0]->char, $e[1]], $memoised->findCandidates($bitmap, true, 8)),
        );
    }

    public function testBlankCellUsesItsOwnSlotAndIsNotCountedAgainstTheBoundedMemo(): void
    {
        $map = SymbolMap::new();

        $blank = $map->findCandidates(0, true, 8);
        $this->assertNotEmpty($blank);
        $this->assertCount(0, $this->memo($map, 'candidateMemo'), 'a blank cell never enters the bounded memo');
        $this->assertCount(1, $this->memo($map, 'blankCandidateMemo'));

        $this->assertSame($blank, $map->findCandidates(0, true, 8));
        $this->assertCount(1, $this->memo($map, 'blankCandidateMemo'), 'the second blank ask hits the same slot');
    }

    public function testInvalidLimitIsRefusedBeforeAnyMemoWrite(): void
    {
        $map = SymbolMap::new();

        $this->expectException(\InvalidArgumentException::class);
        try {
            $map->findCandidates(0x1234, true, 0);
        } finally {
            $this->assertCount(0, $this->memo($map, 'candidateMemo'));
        }
    }
}
