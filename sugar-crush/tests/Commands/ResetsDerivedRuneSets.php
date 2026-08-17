<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Commands;

use SugarCraft\Crush\Commands\KeyBindingRegistry;

/**
 * Puts {@see KeyBindingRegistry}'s two derived-set memos back to "not yet
 * derived".
 *
 * Both are process-global statics with no production reset, which is fine in
 * production (the rows are compile-time data, so a memo cannot go stale) and
 * NOT fine in a test process: without this, the first test to press a key
 * warms them for every later test in the run, so no later test can observe a
 * cold derivation and per-test isolation of the derived sets is impossible.
 * That is the shape a test asserting on the STORED value could not see — an
 * accessor that memoises correctly and then returns something else passes
 * warm, because every later reader is served from the memo it filled.
 *
 * Call it from `setUp()` in any class that reads a derived set, the way
 * `KeyboardHandlerTest::resetMenuBarState()` is called for
 * `MenuBar::$activeMenu`.
 */
trait ResetsDerivedRuneSets
{
    protected function resetDerivedRuneSets(): void
    {
        (new \ReflectionProperty(KeyBindingRegistry::class, 'ctrlRuneMemo'))->setValue(null, []);
        (new \ReflectionProperty(KeyBindingRegistry::class, 'yieldedRuneMemo'))->setValue(null, null);
    }

    /**
     * How many derived sets the process has built since the last reset.
     *
     * A memo slot is filled exactly once per derivation and nothing else fills
     * one, so counting filled slots after a single keypress counts that
     * keypress's derivations. This is the instrument behind the table in
     * {@see KeyBindingRegistry::$ctrlRuneMemo}'s docblock.
     */
    protected function derivedRuneSetCount(): int
    {
        /** @var array<string, list<string>> $byContext */
        $byContext = (new \ReflectionProperty(KeyBindingRegistry::class, 'ctrlRuneMemo'))->getValue();
        $yielded = (new \ReflectionProperty(KeyBindingRegistry::class, 'yieldedRuneMemo'))->getValue();

        return count($byContext) + ($yielded === null ? 0 : 1);
    }
}
