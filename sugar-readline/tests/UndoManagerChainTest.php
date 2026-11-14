<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\UndoManager;

/**
 * Findings 078/079: UndoManager rebuilt the whole 512-entry stack on
 * every push/undo/redo (spread + array_slice). The cure is a node chain
 * with a visible window — O(1) pointer moves plus lazy consolidation.
 * These pins prove the transcripts are byte-for-byte what the old
 * array-stack law produced, including at the cap and for raw-seeded
 * stacks, and that the hot operations copy no stack.
 */
final class UndoManagerChainTest extends TestCase
{
    /**
     * Reference oracle: the pre-fix array-stack law, kept here verbatim
     * so the chain implementation is measured against the behavior it
     * replaced.
     *
     * @param list<string> $undoSeed
     * @param list<string> $redoSeed
     */
    private static function oracleTranscript(array $undoSeed, array $redoSeed, array $ops): array
    {
        $undo = $undoSeed;
        $redo = $redoSeed;
        $max  = UndoManager::MAX_STATES;
        $log  = [];

        foreach ($ops as [$kind, $value]) {
            if ($kind === 'push') {
                array_unshift($undo, $value);
                if (\count($undo) > $max) {
                    array_pop($undo);
                }
                $redo = [];
                $log[] = ['push', null, null, \count($undo) > 0, \count($redo) > 0];
                continue;
            }

            if ($kind === 'undo') {
                if ($undo === []) {
                    // The old law returned [this, current, false] on failure.
                    $log[] = ['undo', $value, false, false, \count($redo) > 0];
                    continue;
                }
                $restored = $undo[0];
                $undo     = array_slice($undo, 1);
                array_unshift($redo, $value);
                if (\count($redo) > $max) {
                    array_pop($redo);
                }
                $log[] = ['undo', $restored, true, \count($undo) > 0, \count($redo) > 0];
                continue;
            }

            // redo
            if ($redo === []) {
                $log[] = ['redo', $value, false, \count($undo) > 0, false];
                continue;
            }
            $restored = $redo[0];
            $redo     = array_slice($redo, 1);
            array_unshift($undo, $value);
            if (\count($undo) > $max) {
                array_pop($undo);
            }
            $log[] = ['redo', $restored, true, \count($undo) > 0, \count($redo) > 0];
        }

        return $log;
    }

    public function testRandomOperationTranscriptMatchesTheOldLaw(): void
    {
        \mt_srand(20261115);
        $ops = [];
        for ($i = 0; $i < 3000; $i++) {
            $roll = \mt_rand(1, 10);
            $kind = $roll <= 5 ? 'push' : ($roll <= 8 ? 'undo' : 'redo');
            $ops[] = [$kind, 'v' . $i];
        }

        $manager = new UndoManager();
        $expected = self::oracleTranscript([], [], $ops);
        $index    = 0;
        foreach ($ops as [$kind, $value]) {
            if ($kind === 'push') {
                $manager = $manager->push($value);
                [, , , , $eCanRedo] = $expected[$index];
                $this->assertSame($eCanRedo, $manager->canRedo(), "canRedo after op {$index}");
                $index++;
                continue;
            }

            if ($kind === 'undo') {
                [$manager, $restored, $ok] = $manager->undo($value);
                [, $eRestored, $eOk, $eCanUndo, $eCanRedo] = $expected[$index];
                $this->assertSame($eOk, $ok, "ok flag at op {$index}");
                $this->assertSame($eRestored, $restored, "restored at op {$index}");
                $this->assertSame($eCanUndo, $manager->canUndo(), "canUndo at op {$index}");
                $this->assertSame($eCanRedo, $manager->canRedo(), "canRedo at op {$index}");
            } else {
                [$manager, $restored, $ok] = $manager->redo($value);
                [, $eRestored, $eOk, $eCanUndo, $eCanRedo] = $expected[$index];
                $this->assertSame($eOk, $ok, "ok flag at op {$index}");
                $this->assertSame($eRestored, $restored, "restored at op {$index}");
                $this->assertSame($eCanUndo, $manager->canUndo(), "canUndo at op {$index}");
                $this->assertSame($eCanRedo, $manager->canRedo(), "canRedo at op {$index}");
            }
            $index++;
        }

        $this->assertSame(\count($expected), $index);
    }

    public function testDeepOverflowWalkSurvivesConsolidation(): void
    {
        $manager = new UndoManager();
        $total   = 3 * UndoManager::MAX_STATES + 5;
        for ($i = 0; $i < $total; $i++) {
            $manager = $manager->push('s' . $i);
        }

        // Consolidation kept the physical chain bounded: without it the
        // chain would hold all $total nodes.
        $length = new \ReflectionProperty(UndoManager::class, 'undoLen');
        $this->assertLessThan(2 * UndoManager::MAX_STATES, $length->getValue($manager));

        // Exactly the cap survives; the walk restores newest-to-oldest.
        $current = 'live';
        $seen    = [];
        while (true) {
            [$manager, $current, $ok] = $manager->undo($current);
            if (!$ok) {
                break;
            }
            $seen[] = $current;
        }

        $this->assertCount(UndoManager::MAX_STATES, $seen);
        $this->assertSame('s' . ($total - 1), $seen[0]);
        $this->assertSame('s' . ($total - UndoManager::MAX_STATES), $seen[UndoManager::MAX_STATES - 1]);


        // The redo side mirrors the walk: it restores the currents pushed on
        // the way down, newest first — exactly the reverse of ['live', ...the
        // states the walk had already restored].
        $redone = [];
        while (true) {
            [$manager, $restored, $ok] = $manager->redo('r');
            if (!$ok) {
                break;
            }
            $redone[] = $restored;
        }

        $this->assertSame(array_reverse(array_merge(['live'], array_slice($seen, 0, UndoManager::MAX_STATES - 1))), $redone);
        $this->assertTrue($manager->canUndo(), 'the redo walk pushed the states back onto undo');
    }

    public function testRawSeededStacksWalkUncappedLikeTheOldArrays(): void
    {
        $undoSeed = [];
        $redoSeed = [];
        for ($i = 0; $i < 600; $i++) {
            $undoSeed[] = 'u' . $i; // newest first
            $redoSeed[] = 'r' . $i;
        }

        $manager = new UndoManager($undoSeed, $redoSeed);

        $walked = 0;
        $last   = null;
        while (true) {
            [$manager, $restored, $ok] = $manager->undo('c' . $walked);
            if (!$ok) {
                break;
            }
            $last = $restored;
            $walked++;
        }
        $this->assertSame(600, $walked, 'a raw-seeded stack deeper than the cap walks fully');
        $this->assertSame('u599', $last);

        $manager = new UndoManager($undoSeed, $redoSeed);
        $walked  = 0;
        while (true) {
            [$manager, $restored, $ok] = $manager->redo('c' . $walked);
            if (!$ok) {
                break;
            }
            $last = $restored;
            $walked++;
        }
        $this->assertSame(600, $walked);
        $this->assertSame('r599', $last);
    }

    public function testHotOperationsNeverCopyTheStack(): void
    {
        $source = \file_get_contents(\dirname(__DIR__) . '/src/UndoManager.php');
        foreach (['push', 'undo', 'redo'] as $method) {
            $this->assertSame(
                1,
                \preg_match('/public function ' . $method . '\(.*?\n    \}/s', $source, $match),
                "locate {$method} body",
            );
            $body = $match[0];
            $this->assertStringNotContainsString('array_slice', $body, "{$method} must not slice the stack");
            $this->assertStringNotContainsString('array_reverse', $body, "{$method} must not rebuild the stack");
            $this->assertStringNotContainsString('...$', $body, "{$method} must not spread the stack");
        }
    }

    public function testBranchedManagersKeepIndependentTranscripts(): void
    {
        $base = (new UndoManager())->push('shared');
        $left  = $base->push('left');
        $right = $base->push('right');

        // Each branch undoes to its own newest; the base is untouched.
        $this->assertSame('left', $left->undo('x')[1]);
        $this->assertSame('right', $right->undo('x')[1]);
        $this->assertSame('shared', $base->undo('x')[1]);
        $this->assertFalse((new UndoManager())->push('one')->undo('two')[0]->push('three')->canRedo());
    }
}
