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
     * Current SGR state, updated on every csiDispatch where final='m'.
     *
     * @readonly
     */
    public SgrState $state;

    /** Events logged for inspection in tests. */
    private array $events = [];

    public function __construct(SgrState $initialState = null)
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
     * Apply an SGR parameter list to update the current state.
     *
     * @param list<int> $params  Numeric SGR parameters; -1 means default.
     * @see https://vt100.net/emu/decbrm#SGR
     */
    private function applySgr(array $params): void
    {
        if ($params === [] || $params === [-1]) {
            // SGR 0 — reset all attributes
            $this->state = new SgrState();
            return;
        }

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
                    // 256-color
                    $state = $this->withForeground256($state, $params[$i + 2]);
                    $i += 2;
                } elseif ($i + 4 < \count($params) && $params[$i + 1] === 2) {
                    // RGB
                    $r = $params[$i + 2];
                    $g = $params[$i + 3];
                    $b = $params[$i + 4];
                    $state = $this->withForegroundRgb($state, ($r << 16) | ($g << 8) | $b);
                    $i += 4;
                }
            } elseif ($p === 39) {
                // Default foreground — revert to 9 (default)
                $state = $this->withForeground($state, SgrState::COLOR_DEFAULT);
            } elseif ($p >= 40 && $p <= 47) {
                // Standard background color (0-7)
                $state = $this->withBackground($state, $p - 40);
            } elseif ($p === 48) {
                // Extended background: 48;5;n (256-color) or 48;2;r;g;b (RGB)
                if ($i + 2 < \count($params) && $params[$i + 1] === 5) {
                    // 256-color
                    $state = $this->withBackground256($state, $params[$i + 2]);
                    $i += 2;
                } elseif ($i + 4 < \count($params) && $params[$i + 1] === 2) {
                    // RGB
                    $r = $params[$i + 2];
                    $g = $params[$i + 3];
                    $b = $params[$i + 4];
                    $state = $this->withBackgroundRgb($state, ($r << 16) | ($g << 8) | $b);
                    $i += 4;
                }
            } elseif ($p === 49) {
                // Default background — revert to 9 (default)
                $state = $this->withBackground($state, SgrState::COLOR_DEFAULT);
            } elseif ($p >= 90 && $p <= 97) {
                // Bright foreground (0-7 + 8 = 9, 10-17 for 8 bright colors)
                $state = $this->withForeground($state, $p - 90 + 8);
            } elseif ($p >= 100 && $p <= 107) {
                // Bright background
                $state = $this->withBackground($state, $p - 100 + 8);
            }
            // All other SGR codes are ignored for state-tracking purposes.
            $i++;
        }

        $this->state = $state;
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
            foreground256: SgrState::COLOR_256,
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
            background256: SgrState::COLOR_256,
            foregroundRgb: $s->foregroundRgb,
            backgroundRgb: $rgb,
        );
    }

    /**
     * Helper to create a mutated SgrState with specific fields changed.
     */
    private function mutate(
        SgrState $s,
        bool $bold = null,
        bool $dim = null,
        bool $italic = null,
        bool $underline = null,
        bool $blink = null,
        bool $reverse = null,
        bool $invisible = null,
        bool $strike = null,
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
