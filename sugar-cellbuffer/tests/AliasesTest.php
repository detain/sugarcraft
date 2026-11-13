<?php

declare(strict_types=1);

namespace SugarCraft\CellBuffer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\CellBuffer\Support\RepeatGate;
use SugarCraft\CellBuffer\Support\SgrEmitter;

/**
 * Façade law (CB-30): the pre-move Diff\ FQNs stay loadable via class_alias
 * shims at their old paths so consumer import bytes (sugar-image's
 * OutputOptimizer `use ...Diff\RepeatGate;`) never change. One smoke test —
 * per house policy a façade does not re-run the canonical suite.
 */
final class AliasesTest extends TestCase
{
    public function testBothOldPathsResolveToTheCanonicalSupportClasses(): void
    {
        self::assertTrue(class_exists(\SugarCraft\CellBuffer\Diff\SgrEmitter::class));
        self::assertTrue(class_exists(\SugarCraft\CellBuffer\Diff\RepeatGate::class));

        self::assertInstanceOf(SgrEmitter::class, new \SugarCraft\CellBuffer\Diff\SgrEmitter());
        self::assertInstanceOf(RepeatGate::class, new \SugarCraft\CellBuffer\Diff\RepeatGate());

        // Static entry points ride the alias unchanged:
        self::assertSame(
            SgrEmitter::emit(null),
            \SugarCraft\CellBuffer\Diff\SgrEmitter::emit(null),
        );
        self::assertSame(
            RepeatGate::worth(6, 1),
            \SugarCraft\CellBuffer\Diff\RepeatGate::worth(6, 1),
        );
    }
}
