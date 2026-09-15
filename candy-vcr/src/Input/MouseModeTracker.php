<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Input;

use SugarCraft\Ansi\Parser\Handler;
use SugarCraft\Ansi\Parser\Parser;

/**
 * Observes recorded program OUTPUT and tracks which DEC private mouse
 * reporting modes are currently enabled.
 *
 * A cassette replays recorded input bytes without a live terminal, so the
 * encoding those bytes were produced in is whatever the program had enabled
 * at record time — `CSI ? 1000 h` (X10), `?1005 h` (UTF-8 coordinates),
 * `?1015 h` (urxvt), `?1006 h` (SGR), `?1016 h` (SGR pixel) and the motion
 * variants `?1002 h` / `?1003 h`. Assuming SGR silently mis-decodes every
 * other encoding; this tracker makes "which mode produced this byte
 * sequence" explicit by replaying the output stream's DECSET/DECRST through
 * a {@see Parser} and remembering the mode state.
 *
 * Multi-mode sequences (`CSI ? 1000 ; 1006 h`) are recorded per mode because
 * the candy-ansi {@see \SugarCraft\Ansi\Parser\HandlerAdapter} dispatches
 * every parameter — this class implements the top-level {@see Handler}
 * directly and does the same in its own `csiDispatch`.
 *
 * Modes default to off, matching xterm's startup state.
 */
final class MouseModeTracker implements Handler
{
    /** X11 (X10-compatible) button-event tracking, 3-byte raw coordinates. */
    public const X10_TRACKING = 1000;

    /** Button-event tracking with motion into cell report. */
    public const CELL_MOTION = 1002;

    /** Any-event tracking (motion without a button held). */
    public const ANY_MOTION = 1003;

    /** UTF-8 coordinate variant of the X10 3-byte form. */
    public const UTF8_COORDINATES = 1005;

    /** SGR coordinate report `CSI < b ; x ; y M/m` (self-identifying). */
    public const SGR_COORDINATES = 1006;

    /** urxvt extended-coordinate report `CSI b ; x ; y M`. */
    public const URXVT_COORDINATES = 1015;

    /** SGR pixel-coordinate report `CSI < b ; x ; y ; z M` (kitty). */
    public const PIXEL_COORDINATES = 1016;

    private const WATCHED = [
        self::X10_TRACKING,
        self::CELL_MOTION,
        self::ANY_MOTION,
        self::UTF8_COORDINATES,
        self::SGR_COORDINATES,
        self::URXVT_COORDINATES,
        self::PIXEL_COORDINATES,
    ];

    private readonly Parser $parser;

    /** @var array<int, bool> mode number => enabled */
    private array $modes = [];

    public function __construct()
    {
        $this->parser = new Parser($this);
    }

    /**
     * Feed recorded output bytes; updates mouse-mode state as DECSET/DECRST
     * sequences complete. Safe to call with stream fragments — in-flight
     * sequences carry across calls.
     */
    public function observe(string $outputBytes): void
    {
        $this->parser->feed($outputBytes);
    }

    /** Clear all recorded mode state (call at the start of each replay). */
    public function reset(): void
    {
        $this->parser->reset();
        $this->modes = [];
    }

    public function enabled(int $mode): bool
    {
        return $this->modes[$mode] ?? false;
    }

    /** True while any mode that reports mouse bytes in the legacy 3-byte form is on. */
    public function legacyReportingActive(): bool
    {
        return $this->enabled(self::X10_TRACKING)
            || $this->enabled(self::CELL_MOTION)
            || $this->enabled(self::ANY_MOTION);
    }

    public function printChar(string $rune): void
    {
    }

    public function execute(int $byte): void
    {
    }

    public function csiDispatch(int $final, array $params, int $prefix, int $intermediate): void
    {
        if ($prefix !== 0x3F) { // DEC private '`?`' only — ANSI modes don't report mouse bytes
            return;
        }
        $char = chr($final);
        if ($char !== 'h' && $char !== 'l') {
            return;
        }
        foreach ($params as $mode) {
            if (in_array($mode, self::WATCHED, true)) {
                $this->modes[$mode] = $char === 'h';
            }
        }
    }

    public function escDispatch(int $final, int $intermediate): void
    {
    }

    public function oscDispatch(string $data): void
    {
    }

    public function dcsDispatch(int $final, array $params, int $prefix, int $intermediate, string $data): void
    {
    }

    public function sosPmApcDispatch(string $kind, string $data): void
    {
    }
}
