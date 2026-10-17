<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Output;

use SugarCraft\Ansi\Parser\Handler;

/**
 * ANSI parser Handler that tracks SGR (Select Graphic Rendition) state.
 *
 * Feed PTY output bytes through a {@see \SugarCraft\Ansi\Parser\Parser}
 * that uses this handler to accumulate the current text style (colors,
 * bold, underline, etc.). Callers can then inspect {@see $state} after
 * each chunk to observe style transitions.
 *
 * This handler is used by {@see AnsiOutputParser} to enable PTY consumers
 * to track SGR state without implementing the full VT500 state machine.
 *
 * @see \SugarCraft\Ansi\Parser\Parser
 * @see Mirrors charmbracelet/x/ansi SGR state tracking.
 */
final class SgrHandler implements Handler
{
    /**
     * Upper bound on the undrained transition log.
     *
     * This is a diagnostic/test-support surface: a consumer that feeds
     * chunks without ever calling {@see drainTransitions()} must not grow
     * memory without bound, so once the log is full the OLDEST event is
     * dropped to admit the newest. Slow consumers therefore lose the head
     * of the history, never its tail — the most recent transitions always
     * survive.
     */
    public const MAX_EVENTS = 1024;

    /**
     * Current SGR state, updated on every csiDispatch where final='m'.
     *
     * @readonly
     */
    public SgrState $state;

    /** @var list<array{SgrState, SgrState}> Events logged for inspection in tests, capped at {@see MAX_EVENTS}. */
    private array $events = [];

    public function __construct(?SgrState $initialState = null)
    {
        $this->state = $initialState ?? new SgrState();
    }

    /**
     * Called for each printable UTF-8 rune in the stream.
     * No-op for SGR tracking — we only care about escape sequences.
     */
    public function printChar(string $rune): void
    {
        // No-op: SGR state doesn't change on printable characters.
    }

    /**
     * Execute a C0/C1 control character (BEL, LF, FF, CR, etc.).
     * No-op for SGR tracking.
     */
    public function execute(int $byte): void
    {
        // No-op: SGR state doesn't change on control characters.
    }

    /**
     * Dispatch a completed CSI sequence.
     *
     * Only SGR (Select Graphic Rendition, final='m') is tracked here.
     * All other CSI sequences are ignored for SGR state purposes.
     *
     * @see Handler::csiDispatch
     */
    public function csiDispatch(int $final, array $params, int $prefix, int $intermediate): void
    {
        if ($final !== 0x6D /* 'm' */) {
            return;
        }
        $this->applySgr($params);
    }

    /**
     * Dispatch a completed ESC sequence.
     * No-op for SGR tracking.
     */
    public function escDispatch(int $final, int $intermediate): void
    {
        // No-op: standard escape sequences don't affect SGR directly.
    }

    /**
     * Dispatch a completed OSC (Operating System Command) sequence.
     * OSC 8 hyperlink is tracked here if needed.
     */
    public function oscDispatch(string $data): void
    {
        // No-op: OSC doesn't affect SGR color/attribute state.
    }

    /**
     * Dispatch a completed DCS sequence.
     * No-op for SGR tracking.
     */
    public function dcsDispatch(int $final, array $params, int $prefix, int $intermediate, string $data): void
    {
        // No-op: DCS doesn't affect SGR color/attribute state.
    }

    /**
     * Dispatch a completed SOS/PM/APC string.
     * No-op for SGR tracking.
     */
    public function sosPmApcDispatch(string $kind, string $data): void
    {
        // No-op.
    }

    /**
     * Drain and reset the transition log accumulated since the last drain.
     *
     * Each entry is a pair `[SgrState from, SgrState to]` representing
     * one SGR state change (e.g. default→red, red→reset, reset→bold).
     *
     * @return list<array{SgrState, SgrState}>
     */
    public function drainTransitions(): array
    {
        $events = $this->events;
        $this->events = [];
        return $events;
    }

    /**
     * Clear the tracked SGR state to a fresh default and drop the pending
     * transition log. Used by {@see AnsiOutputParser::reset()} so a reset
     * really does mean "from a clean slate" for both the parser and the
     * style model.
     */
    public function reset(): void
    {
        $this->state = new SgrState();
        $this->events = [];
    }

    /**
     * Append one transition, enforcing the {@see MAX_EVENTS} drop-oldest
     * bound so undrained growth cannot exhaust memory.
     */
    private function record(SgrState $from, SgrState $to): void
    {
        $this->events[] = [$from, $to];
        while (\count($this->events) > self::MAX_EVENTS) {
            \array_shift($this->events);
        }
    }

    /**
     * Apply an SGR parameter list to update the current state.
     *
     * @param list<int> $params  Numeric SGR parameters; -1 means default.
     * @see https://vt100.net/emu/decbrm#SGR
     */
    private function applySgr(array $params): void
    {
        if ($params === [] || $params === [-1]) {
            // SGR 0 — reset all attributes
            $prev = $this->state;
            $this->state = new SgrState();
            if (!$this->state->equals($prev)) {
                $this->record($prev, $this->state);
            }
            return;
        }

        $prev = $this->state;
        $state = $this->state;
        $i = 0;

        while ($i < \count($params)) {
            $p = $params[$i];

            if ($p === 0 || $p === -1) {
                // Reset
                $state = new SgrState();
            } elseif ($p === 1) {
                $state = $this->mutate($state, bold: true);
            } elseif ($p === 2) {
                $state = $this->mutate($state, dim: true);
            } elseif ($p === 3) {
                $state = $this->mutate($state, italic: true);
            } elseif ($p === 4) {
                $state = $this->mutate($state, underline: true);
            } elseif ($p === 5 || $p === 6) {
                // Blink (slow or rapid — both map to blink flag)
                $state = $this->mutate($state, blink: true);
            } elseif ($p === 7) {
                $state = $this->mutate($state, reverse: true);
            } elseif ($p === 8) {
                $state = $this->mutate($state, invisible: true);
            } elseif ($p === 9) {
                $state = $this->mutate($state, strike: true);
            } elseif ($p === 21 || $p === 22) {
                $state = $this->mutate($state, bold: false, dim: false);
            } elseif ($p === 23) {
                $state = $this->mutate($state, italic: false);
            } elseif ($p === 24) {
                $state = $this->mutate($state, underline: false);
            } elseif ($p === 25) {
                $state = $this->mutate($state, blink: false);
            } elseif ($p === 27) {
                $state = $this->mutate($state, reverse: false);
            } elseif ($p === 28) {
                $state = $this->mutate($state, invisible: false);
            } elseif ($p === 29) {
                $state = $this->mutate($state, strike: false);
            } elseif ($p >= 30 && $p <= 37) {
                // Standard foreground color (0-7)
                $state = $this->withForeground($state, $p - 30);
            } elseif ($p === 38) {
                // Extended foreground: 38;5;n (256-color) or 38;2;r;g;b (RGB)
                if ($i + 2 < \count($params) && $params[$i + 1] === 5) {
                    // 256-color — clamp index to valid 0-255 range
                    $state = $this->withForeground256($state, \max(0, \min(255, $params[$i + 2])));
                    $i += 2;
                } elseif ($i + 4 < \count($params) && $params[$i + 1] === 2) {
                    // RGB — clamp channels to 0-255 like the 38;5 path, so a
                    // wild parameter cannot wrap bits into a neighbouring
                    // channel (300 must read as 255, not 44).
                    $r = \max(0, \min(255, $params[$i + 2]));
                    $g = \max(0, \min(255, $params[$i + 3]));
                    $b = \max(0, \min(255, $params[$i + 4]));
                    $state = $this->withForegroundRgb($state, ($r << 16) | ($g << 8) | $b);
                    $i += 4;
                } else {
                    // Malformed: consume whatever sub-params were present so
                    // orphaned 5/2 markers and trailing numbers are not re-fed
                    // as standalone SGR codes.
                    if ($i + 1 < \count($params) && $params[$i + 1] === 5) {
                        $i = \count($params); // consume orphaned 5 and rest
                    } elseif ($i + 1 < \count($params) && $params[$i + 1] === 2) {
                        $i = \count($params); // consume orphaned 2 and rest
                    }
                }
            } elseif ($p === 39) {
                // Default foreground — revert to the default sentinel
                // (not a palette index; see SgrState::COLOR_DEFAULT).
                $state = $this->withForeground($state, SgrState::COLOR_DEFAULT);
            } elseif ($p >= 40 && $p <= 47) {
                // Standard background color (0-7)
                $state = $this->withBackground($state, $p - 40);
            } elseif ($p === 48) {
                // Extended background: 48;5;n (256-color) or 48;2;r;g;b (RGB)
                if ($i + 2 < \count($params) && $params[$i + 1] === 5) {
                    // 256-color — clamp index to valid 0-255 range
                    $state = $this->withBackground256($state, \max(0, \min(255, $params[$i + 2])));
                    $i += 2;
                } elseif ($i + 4 < \count($params) && $params[$i + 1] === 2) {
                    // RGB — clamp channels to 0-255 like the 48;5 path.
                    $r = \max(0, \min(255, $params[$i + 2]));
                    $g = \max(0, \min(255, $params[$i + 3]));
                    $b = \max(0, \min(255, $params[$i + 4]));
                    $state = $this->withBackgroundRgb($state, ($r << 16) | ($g << 8) | $b);
                    $i += 4;
                } else {
                    // Malformed: consume whatever sub-params were present so
                    // orphaned 5/2 markers and trailing numbers are not re-fed
                    // as standalone SGR codes.
                    if ($i + 1 < \count($params) && $params[$i + 1] === 5) {
                        $i = \count($params); // consume orphaned 5 and rest
                    } elseif ($i + 1 < \count($params) && $params[$i + 1] === 2) {
                        $i = \count($params); // consume orphaned 2 and rest
                    }
                }
            } elseif ($p === 49) {
                // Default background — revert to the default sentinel.
                $state = $this->withBackground($state, SgrState::COLOR_DEFAULT);
            } elseif ($p >= 90 && $p <= 97) {
                // Bright foreground — xterm palette indices 8-15.
                $state = $this->withForeground($state, $p - 90 + 8);
            } elseif ($p >= 100 && $p <= 107) {
                // Bright background — xterm palette indices 8-15.
                $state = $this->withBackground($state, $p - 100 + 8);
            }
            // All other SGR codes are ignored for state-tracking purposes.
            $i++;
        }

        $this->state = $state;
        if (!$state->equals($prev)) {
            $this->record($prev, $state);
        }
    }

    private function withForeground(SgrState $s, int $color): SgrState
    {
        return new SgrState(
            foreground: $color,
            background: $s->background,
            bold: $s->bold,
            italic: $s->italic,
            underline: $s->underline,
            reverse: $s->reverse,
            strike: $s->strike,
            dim: $s->dim,
            invisible: $s->invisible,
            blink: $s->blink,
            foreground256: SgrState::COLOR_256,
            background256: $s->background256,
            foregroundRgb: 0,
            backgroundRgb: $s->backgroundRgb,
        );
    }

    private function withBackground(SgrState $s, int $color): SgrState
    {
        return new SgrState(
            foreground: $s->foreground,
            background: $color,
            bold: $s->bold,
            italic: $s->italic,
            underline: $s->underline,
            reverse: $s->reverse,
            strike: $s->strike,
            dim: $s->dim,
            invisible: $s->invisible,
            blink: $s->blink,
            foreground256: $s->foreground256,
            background256: SgrState::COLOR_256,
            foregroundRgb: $s->foregroundRgb,
            backgroundRgb: 0,
        );
    }

    private function withForeground256(SgrState $s, int $c256): SgrState
    {
        return new SgrState(
            foreground: SgrState::COLOR_DEFAULT,
            background: $s->background,
            bold: $s->bold,
            italic: $s->italic,
            underline: $s->underline,
            reverse: $s->reverse,
            strike: $s->strike,
            dim: $s->dim,
            invisible: $s->invisible,
            blink: $s->blink,
            foreground256: $c256,
            background256: $s->background256,
            foregroundRgb: 0,
            backgroundRgb: $s->backgroundRgb,
        );
    }

    private function withBackground256(SgrState $s, int $c256): SgrState
    {
        return new SgrState(
            foreground: $s->foreground,
            background: SgrState::COLOR_DEFAULT,
            bold: $s->bold,
            italic: $s->italic,
            underline: $s->underline,
            reverse: $s->reverse,
            strike: $s->strike,
            dim: $s->dim,
            invisible: $s->invisible,
            blink: $s->blink,
            foreground256: $s->foreground256,
            background256: $c256,
            foregroundRgb: $s->foregroundRgb,
            backgroundRgb: 0,
        );
    }

    private function withForegroundRgb(SgrState $s, int $rgb): SgrState
    {
        return new SgrState(
            foreground: SgrState::COLOR_DEFAULT,
            background: $s->background,
            bold: $s->bold,
            italic: $s->italic,
            underline: $s->underline,
            reverse: $s->reverse,
            strike: $s->strike,
            dim: $s->dim,
            invisible: $s->invisible,
            blink: $s->blink,
            foreground256: SgrState::COLOR_RGB,
            background256: $s->background256,
            foregroundRgb: $rgb,
            backgroundRgb: $s->backgroundRgb,
        );
    }

    private function withBackgroundRgb(SgrState $s, int $rgb): SgrState
    {
        return new SgrState(
            foreground: $s->foreground,
            background: SgrState::COLOR_DEFAULT,
            bold: $s->bold,
            italic: $s->italic,
            underline: $s->underline,
            reverse: $s->reverse,
            strike: $s->strike,
            dim: $s->dim,
            invisible: $s->invisible,
            blink: $s->blink,
            foreground256: $s->foreground256,
            background256: SgrState::COLOR_RGB,
            foregroundRgb: $s->foregroundRgb,
            backgroundRgb: $rgb,
        );
    }

    /**
     * Helper to create a mutated SgrState with specific fields changed.
     */
    private function mutate(
        SgrState $s,
        ?bool $bold = null,
        ?bool $dim = null,
        ?bool $italic = null,
        ?bool $underline = null,
        ?bool $blink = null,
        ?bool $reverse = null,
        ?bool $invisible = null,
        ?bool $strike = null,
    ): SgrState {
        return new SgrState(
            foreground: $s->foreground,
            background: $s->background,
            bold: $bold ?? $s->bold,
            italic: $italic ?? $s->italic,
            underline: $underline ?? $s->underline,
            reverse: $reverse ?? $s->reverse,
            strike: $strike ?? $s->strike,
            dim: $dim ?? $s->dim,
            invisible: $invisible ?? $s->invisible,
            blink: $blink ?? $s->blink,
            foreground256: $s->foreground256,
            background256: $s->background256,
            foregroundRgb: $s->foregroundRgb,
            backgroundRgb: $s->backgroundRgb,
        );
    }
}
