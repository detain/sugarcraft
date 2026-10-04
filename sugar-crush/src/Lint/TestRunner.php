<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Lint;

use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Tools\Concerns\CapturesProcessOutput;

/**
 * Runs the user's test command in the project root (step 3.H, Aider's
 * `--auto-test` / `test_cmd`).
 *
 * TWO SETTINGS, both USER TIER ONLY: `testCommand` ({@see SETTINGS_KEY}) is
 * the shell line, `autoTest` ({@see AUTO_TEST_SETTINGS_KEY}) turns the
 * after-the-turn run on. A project file naming a test command would run
 * arbitrary shell at the end of the first turn that edits a file after a
 * clone, with no tool call and no gate in the path — the argument `lintCommands`
 * and `statusLine` make ({@see \SugarCraft\Crush\Config\LayeredSettings::PROJECT_TIER_KEYS}).
 *
 * WHY IT RIDES {@see CapturesProcessOutput} INSTEAD OF SPAWNING ITS OWN
 * CHILD — {@see LintRunner}'s reason: that trait is the package's one bounded
 * spawn path (`setsid -w` detach, non-interactive and credential-scrubbed
 * environment, both pipes drained, a wall-clock deadline ending in the 15→9
 * ladder on the whole process group), so the test child is already accounted
 * for by `DescriptorInheritanceGuardTest` and `tools/check-child-lifetimes.php`
 * without a spawn site of its own.
 *
 * THE BOUND IS THE TURN'S IDLE CEILING, NOT A PREFERENCE. The run happens in
 * the turn's own process (the forked turn child on the TUI path, inside the
 * `Stop` chain), and that process sends the TUI nothing while it waits — so a
 * run longer than `turnIdleTimeoutSeconds` would get the WHOLE TURN killed as
 * hung instead of reporting a slow suite. {@see withinTurnIdleCeiling()} keeps
 * the run {@see IDLE_MARGIN_SECONDS} inside that ceiling; raising the ceiling
 * raises the bound.
 *
 * Immutable: every `with*()` returns a copy.
 */
final class TestRunner
{
    use CapturesProcessOutput;

    /** The settings key the test command is read from. */
    public const SETTINGS_KEY = 'testCommand';

    /** The settings key that turns the after-the-turn run on. */
    public const AUTO_TEST_SETTINGS_KEY = 'autoTest';

    /** One run's wall clock before the idle ceiling narrows it. */
    public const DEFAULT_TIMEOUT_SECONDS = 600.0;

    /**
     * Seconds kept between the run's bound and the turn's idle ceiling: the
     * ladder that stops a run past its bound, and the reflection that follows,
     * must both land before the watchdog fires.
     */
    public const IDLE_MARGIN_SECONDS = 10.0;

    /**
     * Bytes of output kept from one run. A cap, because a runaway suite must
     * not exhaust the turn's memory; generous, because the capture keeps the
     * HEAD and a runner's verdict comes last ({@see TestReport::runOutput()}
     * shows the tail of what was kept).
     */
    public const MAX_CAPTURE_BYTES = 8 * 1024 * 1024;

    private function __construct(
        private readonly ?string $command,
        private readonly float $timeoutSeconds,
    ) {}

    /** A runner with no command: {@see run()} answers null until one is set. */
    public static function new(): self
    {
        return new self(null, self::DEFAULT_TIMEOUT_SECONDS);
    }

    /**
     * The same runner running $setting — the raw `testCommand` value. Only a
     * non-blank string is a command; anything else (unset, a list, `false`)
     * leaves the runner without one, the tolerant read `lintCommands` makes.
     */
    public function withCommand(mixed $setting): self
    {
        $command = is_string($setting) && trim($setting) !== '' ? trim($setting) : null;

        return new self($command, $this->timeoutSeconds);
    }

    /** The same runner with each run bounded at $seconds (floored at 10 ms). */
    public function withTimeout(float $seconds): self
    {
        return new self($this->command, is_finite($seconds) ? max(0.01, $seconds) : self::DEFAULT_TIMEOUT_SECONDS);
    }

    /**
     * The same runner bounded {@see IDLE_MARGIN_SECONDS} inside the turn's idle
     * ceiling — $setting is the raw `turnIdleTimeoutSeconds` value, read the
     * way {@see EngineBackend} reads it: a number at or above
     * {@see EngineBackend::MIN_TURN_IDLE_TIMEOUT_SECONDS} is the ceiling,
     * anything else is {@see EngineBackend::COMPLETE_TIMEOUT_SECONDS}. Only ever
     * narrows: a ceiling above the current bound leaves it alone.
     */
    public function withinTurnIdleCeiling(mixed $setting): self
    {
        if (is_string($setting) && is_numeric($setting)) {
            $setting += 0;
        }

        $ceiling = (is_int($setting) || (is_float($setting) && is_finite($setting)))
            && $setting >= EngineBackend::MIN_TURN_IDLE_TIMEOUT_SECONDS
                ? (float) $setting
                : (float) EngineBackend::COMPLETE_TIMEOUT_SECONDS;

        return $this->withTimeout(min($this->timeoutSeconds, $ceiling - self::IDLE_MARGIN_SECONDS));
    }

    public function command(): ?string
    {
        return $this->command;
    }

    public function timeoutSeconds(): float
    {
        return $this->timeoutSeconds;
    }

    /**
     * Run the command in $root, or null when there is nothing to run: no
     * command configured, or no directory at $root.
     *
     * stderr is folded into stdout by the shell (`exec 2>&1`) rather than
     * captured apart and joined afterwards, so a failure message stays next to
     * the test that printed it — Aider's combined output.
     *
     * @param (\Closure(): void)|null $onWait called while the run is waited
     *        on, at most every 200 ms — a heartbeat for a caller whose
     *        watchdog measures silence
     */
    public function run(string $root, ?float $timeoutSeconds = null, ?\Closure $onWait = null): ?TestReport
    {
        $real = $root === '' ? false : realpath($root);
        if ($this->command === null || $real === false || !is_dir($real)) {
            return null;
        }

        $bound = $timeoutSeconds === null ? $this->timeoutSeconds : max(0.01, min($this->timeoutSeconds, $timeoutSeconds));

        $run = $this->runCaptured(
            "exec 2>&1\n" . $this->command,
            $real,
            self::MAX_CAPTURE_BYTES,
            $bound,
            [],
            $onWait,
        );

        return new TestReport(
            command: $this->command,
            exitCode: $run['exitCode'],
            output: trim($run['stdout'] . ($run['stderr'] === '' ? '' : "\n" . $run['stderr'])),
            timedOut: $run['timedOut'],
            timeoutSeconds: $bound,
            droppedBytes: $run['truncatedBytes'],
        );
    }
}
