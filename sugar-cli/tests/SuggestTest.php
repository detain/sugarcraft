<?php

declare(strict_types=1);

namespace SugarCraft\Cli\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Cli\Internal\Suggest;

final class SuggestTest extends TestCase
{
    public function testClosestWithinTwoEdits(): void
    {
        self::assertSame('model', Suggest::closest('modle', ['verbose', 'model']));
        self::assertSame('model', Suggest::closest('mdel', ['model']));
    }

    public function testNothingBeyondTwoEdits(): void
    {
        self::assertNull(Suggest::closest('xyzzy', ['model', 'verbose']));
    }

    public function testShortInputNeedsFewerEditsThanItsLength(): void
    {
        self::assertNull(Suggest::closest('x', ['y']));
        self::assertSame('ab', Suggest::closest('ax', ['ab']));
    }

    public function testExactMatchIsNotASuggestion(): void
    {
        self::assertNull(Suggest::closest('run', ['run']));
    }

    public function testTieGoesToEarlierCandidate(): void
    {
        self::assertSame('cat', Suggest::closest('bat', ['cat', 'hat']));
    }

    public function testTranspositionIsOneEdit(): void
    {
        self::assertSame(1, Suggest::distance('modle', 'model'));
        self::assertSame(3, Suggest::distance('ca', 'abc')); // OSA never edits a substring twice
        self::assertSame(0, Suggest::distance('', ''));
        self::assertSame(3, Suggest::distance('', 'abc'));
    }

    public function testTransposedTypoPrefersEarlierEqualCandidate(): void
    {
        self::assertSame('model', Suggest::closest('modle', ['model', 'mode']));
    }
}
