<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Hooks\BuiltIn;

use SugarCraft\Crush\Hooks\BoundedHookInterface;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Lint\TestRunner;

/**
 * Auto-test reflection (step 3.H, Aider's `--auto-test`): when a turn that
 * edited a file is about to end, run the user's `testCommand`; if it fails,
 * hand its output back to the model in Aider's `run_output` shape and keep the
 * turn going, at most {@see MAX_REFLECTIONS} times.
 *
 * WHY A `Stop` HOOK. 3.D-2's continuation is exactly "continue the turn with
 * this text": a `Stop` verdict that does not permit appends its reason as the
 * next prompt and takes another step, under the turn's step ceiling, its
 * spend cap, a pending soft cancel and
 * {@see \SugarCraft\Crush\Hooks\HookManager::MAX_STOP_CONTINUATIONS}. Riding it
 * means the reflection loop owns no loop of its own in
 * {@see \SugarCraft\Crush\Backend\EngineBackend::runTurn()} and inherits every
 * one of those bounds. Each reflection is one step and one provider call.
 *
 * WHAT EACH OUTCOME DOES:
 *
 *  - no edit since the last run ({@see AutoTestEditHook}) — allow; nothing ran.
 *    A reflection the model answered without editing again ends here too.
 *  - the tests pass — allow.
 *  - the tests fail, reflections left — DENY with the `run_output` text, so the
 *    model reads `Stop hook "auto-test" did not let you finish yet: I ran this
 *    command: …` and carries on.
 *  - the tests still fail after {@see MAX_REFLECTIONS} — `"continue": false`
 *    ({@see HookResult::stop()}): the turn ends as it was about to, and its
 *    reply closes with `[turn stopped by hook "auto-test": …]`, so the operator
 *    sees the suite is still red. Aider's "Only 3 reflections allowed".
 *  - the command cannot start (exit 126/127) or overruns its bound — the same
 *    stop: neither is something the model's next edit can fix, and spending
 *    reflections on a typo in `testCommand` or a slow suite would only bill
 *    three more calls.
 *
 * Only `Stop`, never `SubagentStop`: the tests judge the session's turn, which
 * a `Task` sub-agent's edits are part of ({@see AutoTestEditHook} counts them),
 * not each delegated run.
 *
 * BOUNDED, so the chain's shared deadline counts it
 * ({@see \SugarCraft\Crush\Hooks\HookRegistry::executeHooks()}). The run beats
 * through the turn's idle ceiling with the context's
 * {@see HookContext::$heartbeat} ({@see \SugarCraft\Crush\Hooks\HookManager::stop()});
 * a run with no beat is kept inside it ({@see TestRunner::withinTurnIdleCeiling()}).
 *
 * Registered by {@see \SugarCraft\Crush\Cli\Bootstrap::hooks()} with its
 * {@see AutoTestEditHook}, only when `autoTest` is on and `testCommand` is set.
 */
final readonly class AutoTestHook implements BoundedHookInterface
{
    /** Stable, so a hook file cannot register over it (`loadEntries()` refuses a taken event+name). */
    public const NAME = 'auto-test';

    /** Failing runs handed back to the model per turn — Aider's `max_reflections`. */
    public const MAX_REFLECTIONS = 3;

    public function __construct(
        private TestRunner $runner,
        private AutoTestEditHook $edits,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function event(): HookEvent
    {
        return HookEvent::Stop;
    }

    /** Every `Stop`: the matcher is tested against the event name, and this hook only ever sits on one event. */
    public function matcher(): string
    {
        return '.*';
    }

    /** The runner this hook tests with — for a caller that reads the chain back. */
    public function runner(): TestRunner
    {
        return $this->runner;
    }

    /** The edit ledger this hook reads — the {@see AutoTestEditHook} registered beside it. */
    public function edits(): AutoTestEditHook
    {
        return $this->edits;
    }

    public function timeoutSeconds(): float
    {
        return $this->runner->timeoutSeconds();
    }

    /** A copy bounded at $seconds; it shares this hook's edit ledger, so the count survives the copy. */
    public function withTimeoutSeconds(float $seconds): self
    {
        return new self($this->runner->withTimeout(min($this->runner->timeoutSeconds(), $seconds)), $this->edits);
    }

    public function execute(HookContext $context): HookResult
    {
        if ($this->edits->takeEdits() === 0) {
            $this->edits->resetReflections();

            return HookResult::allow();
        }

        $report = $this->runner->run($context->projectRoot, null, $context->heartbeat);
        if ($report === null || $report->passed()) {
            $this->edits->resetReflections();

            return HookResult::allow();
        }

        if ($report->couldNotRun() || $report->timedOut) {
            $this->edits->resetReflections();
            $reason = $report->timedOut
                ? sprintf(
                    'the test command `%s` did not finish within %s seconds and was stopped',
                    $report->command,
                    rtrim(rtrim(number_format($report->timeoutSeconds, 3, '.', ''), '0'), '.'),
                )
                : $report->couldNotRunReason();

            return HookResult::stop($reason, $reason);
        }

        if ($this->edits->reflections() >= self::MAX_REFLECTIONS) {
            $this->edits->resetReflections();
            $reason = sprintf(
                'the test command `%s` still fails (exit %d) after %d attempts to fix it',
                $report->command,
                $report->exitCode,
                self::MAX_REFLECTIONS,
            );

            return HookResult::stop($reason, $reason);
        }

        $this->edits->countReflection();

        return HookResult::deny($report->runOutput());
    }
}
