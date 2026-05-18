<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic;

use SugarCraft\Core\Util\TtyDetect;

/**
 * Terminal image-protocol capability detection.
 *
 * Probes environment variables and sends DA1 queries to determine
 * which image-rendering protocols the current terminal supports.
 * Results are cached per-process.
 *
 * Precedence: Kitty → iTerm2 → Sixel → HalfBlock (always available).
 */
final class Detect
{
    private const DA1_QUERY    = "\x1b[c";           // DA1 "who are you?"
    private const XTWINOP_14  = "\x1b[14t";          // window pixel size
    private const XTWINOP_16  = "\x1b[16t";          // cell pixel size
    private const XTWINOP_18  = "\x1b[18t";          // terminal cell count
    private const TIMEOUT_MS  = 100;

    /** @var resource|null */
    private static $probeStdin = null;

    /** Raw bytes of the last DA1 reply, for debugging / testing. */
    private static ?string $lastDa1Reply = null;

    private static ?Capability $cached = null;

    /**
     * Probe once and cache the result for the lifetime of this process.
     */
    public static function cached(): Capability
    {
        return self::$cached ??= self::probe();
    }

    /**
     * Probe the terminal: env vars first, then optional DA1 query.
     *
     * If env vars already give a definite protocol (Kitty / iTerm2),
     * DA1 is skipped.  It only fills gaps — primarily detecting sixel
     * on xterm-compatible terminals that have no identifying env var.
     */
    public static function probe(): Capability
    {
        $inTmux = getenv('TMUX') !== false;

        $cap = self::probeEnv($inTmux);

        // Env vars gave a definite answer — no DA1 needed.
        if ($cap->kitty || $cap->iterm2) {
            return $cap->withCellSize(self::probeFontSize());
        }

        // Run DA1 sixel probing BEFORE font-size probing so that in tests
        // using a preloaded socket pair, DA1 reply bytes are not consumed
        // by probeFontSize() before probeDa1() gets to read them.
        $sixelViaDa1 = self::probeDa1();
        if ($sixelViaDa1 === true) {
            return Capability::sixel(self::probeFontSize(), $inTmux);
        }

        return $cap->withCellSize(self::probeFontSize());
    }

    /**
     * Clear the cached capability and last DA1 reply (for testing).
     */
    public static function reset(): void
    {
        self::$cached      = null;
        self::$lastDa1Reply = null;
    }

    /**
     * Return the raw bytes of the last DA1 reply, or null if no query
     * was made or it timed out.
     */
    public static function lastDa1Reply(): ?string
    {
        return self::$lastDa1Reply;
    }

    /**
     * Override the stdin source used during DA1 probing.
     * Use this in tests to inject canned responses.
     *
     * Pass null to restore the real stdin.
     *
     * @param resource|null $fd
     */
    public static function setProbeStdin($fd): void
    {
        self::$probeStdin = $fd;
    }

    /**
     * Send a DA1 query to the terminal and parse the reply.
     *
     * Writes `` \\x1b[c `` to stdout and reads the response from stdin
     * with a 100 ms timeout.  Returns null when stdin/out is not a TTY
     * or the reply times out.
     *
     * @return bool|null  true = sixel supported, false = not supported,
     *                    null = could not query (not a TTY or timeout)
     */
    private static function probeDa1(): ?bool
    {
        if (!self::isInteractiveTty()) {
            return null;
        }

        $fd = self::stdinFd();

        // Note: we intentionally skip a pre-read drain here.  In a real
        // interactive terminal any stale bytes before our query are
        // unlikely (we're the only thing writing), and even if some
        // arrive they can't be confused for a valid DA1 reply because
        // that reply always ends with the 'c' terminator we haven't
        // sent yet.  In tests, pre-loading the reply avoids any
        // drain-then-read race condition.

        // Write DA1 query to stdout (terminal receives it as input).
        $written = @fwrite(STDOUT, self::DA1_QUERY);
        if ($written === false || $written !== strlen(self::DA1_QUERY)) {
            return null;
        }
        fflush(STDOUT);

        // Read the reply from stdin (terminal echoes it back).
        $reply = self::readStdinTimed(self::TIMEOUT_MS, $fd);
        self::$lastDa1Reply = $reply;

        if ($reply === '') {
            return null;
        }

        return self::parseDa1Reply($reply);
    }

    /**
     * Drain up to $limitMs milliseconds of already-available stdin data.
     * This suppresses spurious replies from earlier queries.
     *
     * @param resource $fd
     */
    private static function drainStdin(int $limitMs, $fd): void
    {
        $read    = [$fd];
        $deadline = Deadline::in($limitMs);
        while (true) {
            $remaining = $deadline->remaining();
            if ($remaining <= 0) {
                break;
            }
            $w = $e = null;
            /** @var int|false */
            $changed = @stream_select($read, $w, $e, 0, $remaining * 1000);
            if ($changed === false || $changed === 0) {
                break;
            }
            $chunk = @fread($read[0], 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
        }
    }

    /**
     * Read available bytes from stdin until $timeoutMs elapses or the
     * full DA1 reply is received.  Returns '' on timeout.
     *
     * @param resource $fd
     */
    private static function readStdinTimed(int $timeoutMs, $fd): string
    {
        $buf      = '';
        $deadline = Deadline::in($timeoutMs);

        while (true) {
            $remaining = $deadline->remaining();
            if ($remaining <= 0) {
                break;
            }
            $r = [$fd];
            $w = $e = null;
            /** @var int|false */
            $changed = @stream_select($r, $w, $e, 0, $remaining * 1000);
            if ($changed === false || $changed === 0) {
                break; // timeout
            }
            $chunk = @fread($r[0], 8192);
            if ($chunk === false) {
                break;
            }
            $buf .= $chunk;

            // DA1 reply terminates with ESC [ ... c
            if (str_contains($buf, "\x1b[c")) {
                break;
            }
        }

        return $buf;
    }

    /**
     * Whether stdin and stdout are connected to an interactive TTY.
     *
     * @return resource
     */
    private static function stdinFd()
    {
        return self::$probeStdin ?? STDIN;
    }

    private static function isInteractiveTty(): bool
    {
        // Test override: when setProbeStdin() injected a mock stdin, skip
        // the real TTY check (CI environments have no real TTY).
        if (self::$probeStdin !== null) {
            return true;
        }

        // Explicitly disabled for testing daemon/CGI environments.
        if (isset($GLOBALS['__candy_non_interactive'])) {
            return !$GLOBALS['__candy_non_interactive'];
        }

        return (
            TtyDetect::isAtty(self::stdinFd())
            && TtyDetect::isAtty(STDOUT)
        );
    }

    /**
     * Parse a raw DA1 reply string.
     *
     * The reply format is `` ESC [ <primary> ; <secondary> ; ... c ``.
     * Sixel graphics support is indicated by bit 2 (value 4) in the
     * secondary attributes field, e.g. `` \\x1b[?62;4;0c ``.
     *
     * @return bool  true if `` ;4; `` appears in the reply
     */
    private static function parseDa1Reply(string $reply): bool
    {
        // DA1 reply format: ESC [ <attrs> c
        // Sixel support indicated by bit 4 in the SECONDARY or TERTIARY
        // attribute field.  The '4' appears as one of the semicolon-
        // separated values.  We accept:
        //   ;4;    sixel as secondary attr (most common: ?62;4;0c)
        //   ;4c    sixel as terminal attr in tertiary position (?62;0;4c)
        //   ?4c    sixel-only reply (mlterm sends this)
        // We scan for either ";4" or "?4" to cover all known formats.
        return (str_contains($reply, ';4;') || str_contains($reply, ';4c')
            || str_contains($reply, '?4c'));
    }

    /**
     * Probe font / cell size via XTWINOPS queries.
     *
     * Probes in order: 16t (cell pixels), 14t (window px) + 18t
     * (cell count) as fallback.  Returns null when stdin/out is not
     * a TTY or all queries time out.
     */
    private static function probeFontSize(): ?CellSize
    {
        if (!self::isInteractiveTty()) {
            return null;
        }

        // Try 16t first — it gives cell pixels directly.
        $cellSize = self::probeXtwino(self::XTWINOP_16);
        if ($cellSize !== null) {
            return $cellSize;
        }

        // Fall back: 14t (window px) + 18t (cell count).
        $windowPx = self::probeXtwino(self::XTWINOP_14);
        $cellCount = self::probeXtwinoCellCount();
        if ($windowPx !== null && $cellCount !== null
            && $cellCount->cellWidth > 0 && $cellCount->cellHeight > 0
        ) {
            $cellW = (int) round($windowPx->cellWidth / $cellCount->cellWidth);
            $cellH = (int) round($windowPx->cellHeight / $cellCount->cellHeight);
            if ($cellW > 0 && $cellH > 0) {
                return new CellSize($cellW, $cellH);
            }
        }

        return null;
    }

    /**
     * Send a single XTWINOPS query and parse the numeric reply.
     *
     * Replies are `` ESC [ <val1> ; <val2> t `` for 16t, and
     * `` ESC [ <rows> ; <cols> t `` for 14t.
     *
     * @return CellSize|null  parsed cell size, or null on timeout/parse error
     */
    private static function probeXtwino(string $query): ?CellSize
    {
        // Write the query to stdout.
        $written = @fwrite(STDOUT, $query);
        if ($written === false || $written !== strlen($query)) {
            return null;
        }
        fflush(STDOUT);

        // Read the response with a tight timeout (terminal responds fast).
        $reply = self::readStdinTimed(self::TIMEOUT_MS, self::$probeStdin ?? STDIN);

        return self::parseXtwinoReply($reply, $query);
    }

    /**
     * Probe terminal cell count via XTWINOPS 18t.
     *
     * Returns a CellSize where cellWidth = columns, cellHeight = rows.
     */
    private static function probeXtwinoCellCount(): ?CellSize
    {
        $written = @fwrite(STDOUT, self::XTWINOP_18);
        if ($written === false || $written !== strlen(self::XTWINOP_18)) {
            return null;
        }
        fflush(STDOUT);

        $reply = self::readStdinTimed(self::TIMEOUT_MS, self::$probeStdin ?? STDIN);

        return self::parseXtwinoReply($reply, self::XTWINOP_18);
    }

    /**
     * Parse an XTWINOPS reply into a CellSize.
     *
     * - 16t: `` ESC [ 6 ; <cellHeight> ; <cellWidth> t ``
     * - 14t: `` ESC [ 4 ; <windowHeight> ; <windowWidth> t ``
     *
     * @return CellSize|null
     */
    private static function parseXtwinoReply(string $reply, string $query): ?CellSize
    {
        if (!str_contains($reply, "\x1b[")) {
            return null;
        }

        $inner = substr($reply, strpos($reply, "\x1b[") + 2);
        if (!str_ends_with($inner, 't')) {
            return null;
        }
        $inner = rtrim(substr($inner, 0, -1)); // strip trailing 't'
        $parts = array_map('intval', explode(';', $inner));

        // 16t: format is 6;<cellHeight>;<cellWidth>t
        // 14t: format is 4;<windowHeight>;<windowWidth>t
        if (count($parts) < 3) {
            return null;
        }
        [$id, $v1, $v2] = $parts;

        if ($id === 6 && $query === self::XTWINOP_16) {
            return new CellSize($v2, $v1); // w, h
        }
        if ($id === 4 && $query === self::XTWINOP_14) {
            return new CellSize($v2, $v1); // w, h
        }
        if ($id === 8 && $query === self::XTWINOP_18) {
            return new CellSize($v2, $v1); // cols, rows
        }

        return null;
    }

    /**
     * Detect based solely on environment variables (no TTY I/O).
     */
    private static function probeEnv(bool $inTmux = false): Capability
    {
        // Kitty: KITTY_WINDOW_ID set, or known kitty-family $TERM, or ghostty/WezTerm.
        if (getenv('KITTY_WINDOW_ID') !== false
            || getenv('TERM_PROGRAM') === 'WezTerm'
            || getenv('TERM_PROGRAM') === 'ghostty'
            || preg_match('/xterm-kitty/i', (string) getenv('TERM')) === 1
        ) {
            return Capability::kitty(null, $inTmux);
        }

        // iTerm2: iTerm.app, iTerm2, mintty, or LC_TERMINAL=iTerm2.
        // Note: WezTerm is handled exclusively in the Kitty block above
        // (Kitty protocol family takes precedence; WezTerm is not iTerm2).
        $termProgram = (string) getenv('TERM_PROGRAM');
        $lcTerminal = (string) getenv('LC_TERMINAL');
        if ($termProgram === 'iTerm.app'
            || $termProgram === 'iTerm2'
            || $termProgram === 'mintty'
            || $lcTerminal === 'iTerm2'
        ) {
            return Capability::iterm2(null, $inTmux);
        }

        // Sixel: strong env-var hints (mlterm, foot, xterm with XTERM_VERSION).
        if (self::hasSixelEnvHints()) {
            return Capability::sixel(null, $inTmux);
        }

        // Half-block: always available.
        return Capability::unknown(null, $inTmux);
    }

    /**
     * Terminals known to support Sixel based purely on $TERM + $XTERM_VERSION.
     */
    private static function hasSixelEnvHints(): bool
    {
        $term        = (string) getenv('TERM');
        $xtermVersion = (string) getenv('XTERM_VERSION');

        return (
            ($xtermVersion !== '')
            && preg_match('/^(mlterm|foot|xterm(-256color)?)$/i', $term) === 1
        );
    }
}

/**
 * Monotonic deadline tracker for I/O timeout management.
 */
final class Deadline
{
    private float $targetUs;

    private function __construct(float $targetUs)
    {
        $this->targetUs = $targetUs;
    }

    public static function in(int $ms): self
    {
        // hrtime is nanoseconds
        return new self(hrtime(true) + ($ms * 1_000));
    }

    /** Remaining milliseconds, floored at 0. */
    public function remaining(): int
    {
        $r = $this->targetUs - hrtime(true);

        return $r <= 0 ? 0 : (int) ($r / 1_000);
    }
}
