<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Hooks\BuiltIn;

use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookResult;

/**
 * The edit half of auto-test reflection (step 3.H): notes every `Write` /
 * `Edit` so {@see AutoTestHook} runs the tests only after a turn that changed
 * a file — Aider's "if edited and auto_test" — and keeps the reflection count
 * that hook spends.
 *
 * WHY A SECOND HOOK. A `Stop` verdict sees the final answer and nothing of the
 * turn behind it, so "did this turn edit anything" can only be learned where
 * the edits pass: the `PostToolUse` chain, which both live tool paths run in
 * the turn's own process ({@see \SugarCraft\Crush\Runtime::settle()} after
 * every call, a forked parallel call's included). A `Task` sub-agent's edits
 * count too: its chain is a copy of the launch's, and a copy shares this
 * object.
 *
 * KEYED BY PROCESS. On the TUI path every turn runs in a child forked for
 * that turn, so an edit noted there is gone with it; an edit noted in the TUI
 * process itself (the dormant sync tool path, which never fires `Stop`) is
 * kept apart by pid, so a child forked later does not inherit it as its own.
 * In a process that runs turn after turn (`-p`, an embedder) the count lasts
 * until a `Stop` takes it.
 *
 * A failed edit is noted too — the hook sees the result, not whether the file
 * changed — which costs at most one test run that finds nothing new.
 *
 * IT NEVER REFUSES and never adds a note: it is bookkeeping, and the result
 * the model reads stays byte-identical.
 *
 * Registered with {@see AutoTestHook} by
 * {@see \SugarCraft\Crush\Cli\Bootstrap::hooks()}, only when `autoTest` is on
 * and `testCommand` is set.
 */
final class AutoTestEditHook implements HookInterface
{
    /** Stable, so a hook file cannot register over it (`loadEntries()` refuses a taken event+name). */
    public const NAME = 'auto-test-edits';

    /** @var array<int, int> pid => edits not yet tested */
    private array $edits = [];

    /** @var array<int, int> pid => reflections spent since the last clean or abandoned run */
    private array $reflections = [];

    public static function new(): self
    {
        return new self();
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function event(): HookEvent
    {
        return HookEvent::PostToolUse;
    }

    /** The two tools that change a file's contents, as the post-edit lint matches them. */
    public function matcher(): string
    {
        return '^(Write|Edit)$';
    }

    public function execute(HookContext $context): HookResult
    {
        $pid = self::pid();
        $this->edits[$pid] = ($this->edits[$pid] ?? 0) + 1;

        return HookResult::allow();
    }

    /** Edits noted in this process since the last {@see takeEdits()}. */
    public function pendingEdits(): int
    {
        return $this->edits[self::pid()] ?? 0;
    }

    /** The edits noted in this process, cleared: a test run is about to cover them. */
    public function takeEdits(): int
    {
        $pid = self::pid();
        $count = $this->edits[$pid] ?? 0;
        unset($this->edits[$pid]);

        return $count;
    }

    /** Reflections this process has spent since the last clean or abandoned run. */
    public function reflections(): int
    {
        return $this->reflections[self::pid()] ?? 0;
    }

    /** One more reflection spent; the new count. */
    public function countReflection(): int
    {
        $pid = self::pid();

        return $this->reflections[$pid] = ($this->reflections[$pid] ?? 0) + 1;
    }

    /** The tests passed, or the turn is ending without them: the next failure starts a fresh count. */
    public function resetReflections(): void
    {
        unset($this->reflections[self::pid()]);
    }

    private static function pid(): int
    {
        return (int) getmypid();
    }
}
