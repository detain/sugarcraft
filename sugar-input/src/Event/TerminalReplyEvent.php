<?php

declare(strict_types=1);

namespace SugarCraft\Input\Event;

use SugarCraft\Input\Event;

/**
 * A terminal REPLY that arrived unsolicited on the input stream.
 *
 * Terminals answer queries (DA1/DA2, DSR/CPR, XTWINOPS, Kitty keyboard flags,
 * DECRPM, ...) and echo private-mode sequences on the SAME file descriptor the
 * user types on. Such a reply is never a keystroke: the decoder structurally
 * consumes it and emits this event instead of leaving it in the buffer (which
 * would stall every later keystroke) or mis-parsing it as a key.
 *
 * Recognized reply families (see {@see self::FAMILIES}):
 *  - "device-attributes" — DA1 `CSI ? Pm c` / DA2 `CSI > Pm c`
 *  - "cursor-position"   — CPR/DSR report `CSI row ; col R`
 *  - "dsr-status"        — DSR status report `CSI 0 n` / `CSI 3 n`; any
 *                          `CSI … n` final drains, not only these two
 *  - "window-report"     — XTWINOPS `CSI Pm t`
 *  - "kitty-flags"       — Kitty keyboard flags `CSI ? flags u` (incl. bare `CSI ? u`)
 *  - "mode-report"       — DECRPM `CSI ? mode ; status $ y`
 *  - "csi-private"       — any other complete private-mode CSI (drained)
 *  - "string"            — OSC/DCS/APC/PM payload drained to its terminator
 *
 * Hosts that do not care about replies can ignore this class exactly like any
 * other unhandled Event; bytes it carries are already consumed, so the input
 * stream stays in sync either way.
 *
 * No upstream charmbracelet counterpart — this is a SugarCraft drain extension
 * that makes input-stream poisoning observable (bubbletea silently discards
 * replies; here they surface as a typed Event with an exact raw byte record).
 *
 * @see xterm control sequences — Device Attributes, CSI 6 n, CSI ... t:
 *      https://invisible-island.net/xterm/ctlseqs/ctlseqs.html
 * @see Kitty keyboard protocol — flag query/reply `CSI ? flags u`:
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
     * Hard ceiling on carried CSI parameters. A hostile or buggy
     * terminal can pack a reply with hundreds of semicolon-separated ints;
     * the first MAX_PARAMS ride on the event and the excess is counted,
     * never unbounded memory keyed off attacker bytes.
     */
    public const MAX_PARAMS = 32;

    public readonly array $params;

    /** How many parameters the wire carried beyond {@see MAX_PARAMS}. */
    public readonly int $paramsDropped;

    /**
     * @param string        $family    The reply family — must be a member of
     *                                 {@see self::FAMILIES}; anything else is
     *                                 invented vocabulary and throws.
     * @param list<int>     $params    Numeric CSI parameters, in wire order (private-mode
     *                                 prefixes and intermediate bytes are not params).
     * @param string        $raw       The exact bytes consumed from the stream,
     *                                 including "ESC ["/"ESC P" introducer and final
     *                                 byte — two exceptions: a string reply clipped at
     *                                 the decoder's size cap carries only the
     *                                 introducer plus the drained head (its
     *                                 terminator was never seen; {@see $body} is the
     *                                 authoritative payload slice there), and a CSI
     *                                 reply past the decoder's raw-retention cap
     *                                 carries its leading bytes with {@see $truncated}
     *                                 set.
     * @param string        $body      Decoded payload for string sequences (OSC/DCS/APC/PM):
     *                                 the bytes between introducer and terminator. Empty
     *                                 for CSI-family replies.
     * @param bool          $truncated True when a string sequence exceeded the decoder's
     *                                 size cap and its tail was discarded mid-payload,
     *                                 or when a drained CSI reply frame passed the
     *                                 decoder's raw-retention cap and only its head
     *                                 rides on the event.
     */
    public function __construct(
        public string $family,
        array $params = [],
        public string $raw = '',
        public string $body = '',
        public bool $truncated = false,
    ) {
        // Parse-don't-validate at the boundary: an unknown family means the
        // caller invented vocabulary the decoder roster does not model — the
        // same census MouseEvent applies to actions.
        if (!in_array($family, self::FAMILIES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown terminal reply family "%s"; expected one of %s.',
                $family,
                implode(', ', self::FAMILIES),
            ));
        }

        $this->paramsDropped = max(0, count($params) - self::MAX_PARAMS);
        $this->params = $this->paramsDropped > 0
            ? array_slice($params, 0, self::MAX_PARAMS)
            : $params;
    }

    public function is(string $family): bool
    {
        return $this->family === $family;
    }
}
