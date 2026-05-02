<?php

declare(strict_types=1);

namespace CandyCore\Core;

use CandyCore\Core\Util\ColorProfile;
use React\EventLoop\LoopInterface;

/**
 * Tunables for {@see Program}. All fields are optional with sensible defaults.
 *
 * @phpstan-type Stream resource
 */
final class ProgramOptions
{
    /**
     * @param resource|null $input  stdin replacement; null = STDIN
     * @param resource|null $output stdout replacement; null = STDOUT
     */
    public function __construct(
        public readonly bool $useAltScreen = false,
        public readonly bool $catchInterrupts = true,
        public readonly bool $hideCursor = true,
        public readonly float $framerate = 60.0,
        public readonly MouseMode $mouseMode = MouseMode::Off,
        public readonly bool $reportFocus = false,
        public readonly bool $bracketedPaste = false,
        /** Enable DEC mode 2027 (grapheme cluster mode) — Bubble Tea v2 default. */
        public readonly bool $unicodeMode = true,
        /**
         * Inline mode — render only the program's own rows instead of
         * taking over the viewport. Pairs with `useAltScreen=false`
         * for CandyShell-style prompts that should leave scrollback
         * intact.
         */
        public readonly bool $inlineMode = false,
        /**
         * Open the controlling terminal directly (`/dev/tty`) for
         * input/output instead of using STDIN/STDOUT. Useful when
         * stdin is piped (`candyshell choose < some.txt`) and the
         * program still needs to read keys. Falls back to
         * STDIN/STDOUT when `/dev/tty` isn't available (Windows,
         * sandboxed envs).
         */
        public readonly bool $openTty = false,
        public readonly mixed $input = null,
        public readonly mixed $output = null,
        public readonly ?LoopInterface $loop = null,
        /**
         * Override the process environment that goes into the
         * startup `EnvMsg`. Pass `null` (default) to use the live
         * `getenv()` snapshot. Useful for tests and for re-parenting
         * a program with synthetic env state.
         *
         * @var array<string,string>|null
         */
        public readonly ?array $environment = null,
        /**
         * Force the initial window size instead of querying the TTY.
         * Pass `['cols' => N, 'rows' => N]`. Mostly useful for tests
         * — production programs should let the runtime ask the TTY
         * and react to SIGWINCH.
         *
         * @var array{cols:int,rows:int}|null
         */
        public readonly ?array $windowSize = null,
        /**
         * Override the auto-detected colour profile. Forces a
         * specific tier instead of inferring from `TERM` / `COLORTERM`.
         */
        public readonly ?ColorProfile $colorProfile = null,
        /**
         * Catch fatal PHP errors / uncaught exceptions during the
         * program run so the runtime gets a chance to restore the
         * terminal before the process dies. Defaults to true. Pass
         * `catchPanics: false` (mirrors Bubble Tea's
         * `WithoutCatchPanics`) when you want raw stack traces or are
         * already running under a debugger that does its own state
         * capture.
         */
        public readonly bool $catchPanics = true,
        /**
         * Skip rendering entirely. Useful for headless tests that just
         * want to drive `update()` without painting any output.
         * Mirrors `WithoutRenderer`.
         */
        public readonly bool $withoutRenderer = false,
        /**
         * Pre-process every Msg before it reaches `update()`. The
         * filter receives the current model + the candidate Msg and
         * returns either the Msg (or a replacement) to dispatch, or
         * null to drop it. Mirrors `WithFilter`.
         *
         * @var ?\Closure(Model, Msg): ?Msg
         */
        public readonly ?\Closure $filter = null,
        /**
         * Enable the cursed cell-diff renderer. Default `false` —
         * the line-diff baseline is always correct. Flipping this
         * on opts into the Bubble Tea v2 algorithm: per-row token-
         * aware partial repaints that emit dramatically fewer bytes
         * for slow-changing lines (progress bars, ticking counters).
         *
         * Worth turning on for SSH sessions (CandyWish) and any
         * remote / latency-sensitive deployment. Local terminals
         * notice no difference at typical frame sizes.
         */
        public readonly bool $cellDiffRenderer = false,
    ) {}
}
