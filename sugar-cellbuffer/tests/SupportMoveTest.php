<?php

declare(strict_types=1);

namespace SugarCraft\CellBuffer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\CellBuffer\Support\RepeatGate;
use SugarCraft\CellBuffer\Support\SgrEmitter;

/**
 * The CB-30 moves left class_alias shims at the old Diff\ paths. Those shims
 * are retired: the canonical Support classes are the only names, so a
 * consumer that still imports Diff\RepeatGate or Diff\SgrEmitter fails loudly
 * instead of resolving through a façade.
 */
final class SupportMoveTest extends TestCase
{
    public function testCanonicalSupportClassesResolve(): void
    {
        self::assertTrue(class_exists(SgrEmitter::class));
        self::assertTrue(class_exists(RepeatGate::class));
        self::assertIsBool(RepeatGate::worth(6, 1));
        self::assertIsString(SgrEmitter::emit(null));
    }

    public function testRetiredDiffShimsAreGone(): void
    {
        self::assertFalse(class_exists('SugarCraft\\CellBuffer\\Diff\\RepeatGate'));
        self::assertFalse(class_exists('SugarCraft\\CellBuffer\\Diff\\SgrEmitter'));
    }
}
