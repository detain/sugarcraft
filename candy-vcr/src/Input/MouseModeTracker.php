<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Input;

use SugarCraft\AnsiParser\Parser\Handler;
use SugarCraft\AnsiParser\Parser\Parser;

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
 * the sugar-ansi-parser {@see \SugarCraft\AnsiParser\Parser\HandlerAdapter} dispatches
 * every parameter — this class implements the top-level {@see Handler}
 * directly and does the same in its own `csiDispatch`.
 *
 * Canonical DECSET/DECRST bytes are scanned by a byte pattern without the
 * parser (VCR-S28); whatever could be state-dependent — raw C1 introducers,
 * non-canonical `ESC [ ?` shapes, fragments crossing chunk boundaries —
 * still runs through the {@see Parser}, so the recorded mode ledger is the
 * same one either way.
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

    /**
     * Canonical DECSET/DECRST shape: `ESC [ ? <digits/semicolons> h|l`.
     * Anything `ESC [ ?`-prefixed that deviates (intermediates, sub-params,
     * unknown finals) belongs to the full parser, not this pattern.
     */
    private const DEC_PRIVATE_PATTERN = '/\x1b\[\?([0-9;]*)([hl])/';

    /** In-flight sequence (or bare introducer) at a chunk tail. */
    private const DEC_PRIVATE_TRAILER = '/\x1b\[[\x20-\x3F]*\z|\x1b\z/';

    /**
     * Raw 8-bit C1 controls (this parser executes them); a `0x9B`-opened
     * private CSI would be invisible to the ESC pattern, so any chunk
     * containing one is fed to the parser unchanged. Bytes 0x80-0x9F that
     * are really UTF-8 continuations only widen the slow path — the parser
     * decodes them, the pattern never sees the chunk either way.
     */
    private const C1_PRESENT = '/[\x80-\x9F]/';

    /** CSI final-byte range; seeing one closes the sequence. */
    private const CSI_FINAL = '/[\x40-\x7E]/';

    /**
     * Classify the tail after the last introducer of a chunk the parser has
     * just consumed: could the state machine still sit mid-sequence? Any
     * raw C1 byte keeps the answer conservative (true); string states
     * (OSC/DCS/SOS/PM/APC) are only closed by a byte that cannot appear
     * without a fresh introducer, so they report in-flight unless BEL (OSC)
     * proves the close. A CSI closes on its final byte (0x40-0x7E).
     */
    private static function endsMidSequence(string $bytes): bool
    {
        if ($bytes === '') {
            return false;
        }
        if (preg_match(self::C1_PRESENT, $bytes) === 1) {
            return true;
        }
        $esc = strrpos($bytes, "\x1b");
        if ($esc === false) {
            return false; // pure printable text: ground before, ground after
        }
        $tail = (string) substr($bytes, $esc);
        if (strlen($tail) === 1) {
            return true; // lone ESC
        }
        $second = $tail[1];
        if ($second === '[') {
            return preg_match(self::CSI_FINAL, (string) substr($tail, 2)) !== 1;
        }
        if ($second === ']' || $second === 'P' || $second === 'X' || $second === '^' || $second === '_') {
            return !str_contains($tail, "\x07");
        }
        $ord = ord($second);
        if ($ord >= 0x20 && $ord <= 0x2F) {
            return strlen($tail) < 3; // introducer + intermediate: one more byte closes
        }
        if ($ord >= 0x30 && $ord <= 0x7E) {
            return false; // esc_dispatch single byte
        }

        return false; // C0 after ESC (BEL/CAN/SUB/…) executed and stayed ground
    }

    private readonly Parser $parser;

    /** @var array<int, bool> mode number => enabled */
    private array $modes = [];

    /** Canonical `ESC [ ?` bytes cut across a chunk boundary. */
    private string $carry = '';

    /** The parser holds an unresolved sequence; the next chunk must be fed. */
    private bool $parserCarry = false;

    public function __construct()
    {
        $this->parser = new Parser($this);
    }

    /**
     * Feed recorded output bytes; updates mouse-mode state as DECSET/DECRST
     * sequences complete. Safe to call with stream fragments — in-flight
     * sequences carry across calls.
     *
     * Fast path (VCR-S28): recorded frames overwhelmingly consist of print
     * runs and plain CSI repaint that cannot touch a mouse mode. A chunk
     * without raw C1 bytes is scanned by {@see DEC_PRIVATE_PATTERN} plus a
     * tail-carry check and never reaches the parser; the parser keeps the
     * slow path for C1 bytes and for non-canonical `ESC [ ?` sequences
     * (intermediates, odd finals), where only the state machine can decide
     * what dispatches.
     *
     * @return bool true when the chunk went to the full parser (slow path);
     *              false when the pattern scan handled it.
     */
    public function observe(string $outputBytes): bool
    {
        if ($this->carry !== '') {
            $outputBytes = $this->carry . $outputBytes;
            $this->carry = '';
        }

        if ($this->parserCarry || preg_match(self::C1_PRESENT, $outputBytes) === 1) {
            $this->parserCarry = false;
            $this->parser->feed($outputBytes);
            $this->parserCarry = self::endsMidSequence($outputBytes);

            return true;
        }

        $scanable = $outputBytes;
        if (preg_match(self::DEC_PRIVATE_TRAILER, $outputBytes, $tail) === 1) {
            // Tail is an in-flight sequence (or a bare introducer that may
            // still become one): hold it, scan the rest.
            $this->carry = $tail[0];
            $scanable = substr($outputBytes, 0, -strlen($tail[0]));
        }

        if (preg_match_all(self::DEC_PRIVATE_PATTERN, $scanable, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as [, $params, $final]) {
                $this->applyModes($params, $final === 'h');
            }
            $scanable = preg_replace(self::DEC_PRIVATE_PATTERN, '', $scanable) ?? '';
        }

        if (str_contains($scanable, "\x1b[?")) {
            // A surviving `ESC [ ?` deviates from the canonical shape:
            // its fate belongs to the state machine. Re-feed the whole
            // chunk (modes are idempotent bools) and let the parser own
            // any in-flight tail from here on.
            $this->carry = '';
            $this->parser->feed($outputBytes);
            $this->parserCarry = self::endsMidSequence($outputBytes);

            return true;
        }

        return false;
    }

    /** Clear all recorded mode state (call at the start of each replay). */
    public function reset(): void
    {
        $this->parser->reset();
        $this->modes = [];
        $this->carry = '';
        $this->parserCarry = false;
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
        $this->applyModes(implode(';', $params), $char === 'h');
    }

    /**
     * Arm or clear every watched mode named by a DEC private `h`/`l`
     * sequence. Both dispatch paths — the VCR-S28 byte pattern and the
     * parser's `csiDispatch` — funnel through this one ledger so the two
     * can never disagree about a mode number's meaning.
     */
    private function applyModes(string $params, bool $on): void
    {
        if ($params === '') {
            return;
        }
        foreach (explode(';', $params) as $mode) {
            $number = (int) $mode;
            if (in_array($number, self::WATCHED, true)) {
                $this->modes[$number] = $on;
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
