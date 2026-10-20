<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser\Parser;

/**
 * Receives actions from the VT500 parser state machine.
 *
 * Implement this for the real terminal emulator (`ScreenHandler` in
 * sugar-vterm), a debug logger ({@see DebugHandler}), or a unit-test
 * mock for any sub-handler.
 *
 * Mirrors charmbracelet/x/ansi.Handler
 */
interface Handler
{
    /**
     * Print a single grapheme to the current cursor cell.
     *
     * For ASCII printable bytes the string is one byte. For UTF-8 leads
     * the parser accumulates continuation bytes and calls printChar once
     * with the full multi-byte rune.
     */
    public function printChar(string $rune): void;

    /**
     * Execute a C0 or C1 control character (HT, LF, CR, BEL, BS, IND, RI, …).
     */
    public function execute(int $byte): void;

    /**
     * Dispatch a completed CSI sequence.
     *
     * @param int        $final        Final command byte (e.g. ord('m')).
     * @param list<int>  $params       Numeric params; -1 marks a missing/default param.
     * @param int        $prefix       Private-marker byte 0x3C-0x3F (e.g. ord('?')); 0 if none.
     * @param int        $intermediate Intermediate byte 0x20-0x2F; 0 if none.
     */
    public function csiDispatch(int $final, array $params, int $prefix, int $intermediate): void;

    /**
     * Dispatch a completed two-byte ESC sequence (e.g. ESC D = IND, ESC 7 = DECSC).
     *
     * @param int $final        Byte following ESC, in 0x30-0x7E.
     * @param int $intermediate Intermediate byte 0x20-0x2F; 0 if none.
     */
    public function escDispatch(int $final, int $intermediate): void;

    /**
     * Dispatch a completed OSC sequence; data is everything between
     * `ESC ]` and the terminator (BEL, ST, or ESC \).
     */
    public function oscDispatch(string $data): void;

    /**
     * Dispatch a completed DCS sequence.
     *
     * F-16: `$final === 0` means no final byte (0x40-0x7E) was ever seen.
     * The VT500 grammar lets DcsEntry hand the control bytes 0x08-0x0D (and a
     * tmux-quirk ESC) straight into the string payload, so such a sequence
     * dispatches with this sentinel. Zero is not a legal DCS final byte, so it
     * is unambiguous; see {@see \SugarCraft\AnsiParser\Codec\Dcs} for the
     * structured decoder. A DCS that HAS a final byte but no ST is not
     * truncated: `$data` carries the bytes that arrived and `$final` is the
     * real final byte (sixel detection works at end-of-stream). A DCS still
     * in its prelude at end-of-stream is aborted and dispatches nothing.
     *
     * @param int        $final        Final byte that ended the prelude (0x40-0x7E); 0 = truncated.
     * @param list<int>  $params       Numeric params; -1 marks a missing/default param.
     * @param int        $prefix       Private-marker byte; 0 if none.
     * @param int        $intermediate Intermediate byte; 0 if none.
     * @param string     $data         Passthrough string between final byte and terminator.
     */
    public function dcsDispatch(int $final, array $params, int $prefix, int $intermediate, string $data): void;

    /**
     * Dispatch a completed SOS, PM, or APC string.
     *
     * @param string $kind 'sos' | 'pm' | 'apc'
     */
    public function sosPmApcDispatch(string $kind, string $data): void;
}
