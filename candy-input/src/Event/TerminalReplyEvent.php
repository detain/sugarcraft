<?php

declare(strict_types=1);

namespace SugarCraft\Input\Event;

use SugarCraft\Input\Event;

/**
 * A terminal REPLY that arrived unsolicited on the input stream.
 *
 * Terminals answer queries (DA1/DA2, DSR/CPR, XTWINOPS, kitty keyboard flags,
 * DECRPM, ...) and echo private-mode sequences on the SAME file descriptor the
 * user types on. Such a reply is never a keystroke: the decoder structurally
 * consumes it and emits this event instead of leaving it in the buffer (which
 * would stall every later keystroke) or mis-parsing it as a key.
 *
 * Recognized reply families (see {@see self::FAMILIES}):
 *  - "device-attributes" — DA1 `CSI ? Pm c` / DA2 `CSI > Pm c`
 *  - "cursor-position"   — CPR/DSR report `CSI row ; col R`
 *  - "dsr-status"        — DSR status report `CSI 0 n`
 *  - "window-report"     — XTWINOPS `CSI Pm t`
 *  - "kitty-flags"       — kitty keyboard flags `CSI ? flags u` (incl. bare `CSI ? u`)
 *  - "mode-report"       — DECRPM `CSI ? mode ; status $ y`
 *  - "csi-private"       — any other complete private-mode CSI (drained)
 *  - "string"            — OSC/DCS/APC/PM payload drained to its terminator
 *
 * Hosts that do not care about replies can ignore this class exactly like any
 * other unhandled Event; bytes it carries are already consumed, so the input
 * stream stays in sync either way.
 *
 * @see xterm control sequences — Device Attributes, CSI 6 n, CSI ... t:
 *      https://invisible-island.net/xterm/ctlseqs/ctlseqs.html
 * @see Kitty keyboard protocol — flag query/review `CSI ? flags u`:
 *      https://sw.kovidgoyal.net/kitty/keyboard-protocol/
 * @readonly
 */
final readonly class TerminalReplyEvent implements Event
{
    public const FAMILY_DEVICE_ATTRIBUTES = 'device-attributes';

    public const FAMILY_CURSOR_POSITION = 'cursor-position';

    public const FAMILY_DSR_STATUS = 'dsr-status';

    public const FAMILY_WINDOW_REPORT = 'window-report';

    public const FAMILY_KITTY_FLAGS = 'kitty-flags';

    public const FAMILY_MODE_REPORT = 'mode-report';

    public const FAMILY_CSI_PRIVATE = 'csi-private';

    public const FAMILY_STRING = 'string';

    /** Every family the decoder can emit — the documented roster. */
    public const FAMILIES = [
        self::FAMILY_DEVICE_ATTRIBUTES,
        self::FAMILY_CURSOR_POSITION,
        self::FAMILY_DSR_STATUS,
        self::FAMILY_WINDOW_REPORT,
        self::FAMILY_KITTY_FLAGS,
        self::FAMILY_MODE_REPORT,
        self::FAMILY_CSI_PRIVATE,
        self::FAMILY_STRING,
    ];

    /**
     * @param string        $family One of the FAMILY_* constants above.
     * @param list<int>     $params Numeric CSI parameters, in wire order (private-mode
     *                              prefixes and intermediate bytes are not params).
     * @param string        $raw    The exact bytes consumed from the stream,
     *                              including "ESC ["/"ESC P" introducer and final byte.
     * @param string        $body   Decoded payload for string sequences (OSC/DCS/APC/PM):
     *                              the bytes between introducer and terminator. Empty
     *                              for CSI-family replies.
     * @param bool          $truncated True when a string sequence exceeded the decoder's
     *                              size cap and its tail was discarded mid-payload.
     */
    public function __construct(
        public string $family,
        public array $params = [],
        public string $raw = '',
        public string $body = '',
        public bool $truncated = false,
    ) {}

    public function is(string $family): bool
    {
        return $this->family === $family;
    }
}
