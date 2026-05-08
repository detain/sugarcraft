<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic;

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
    private const DA1_QUERY  = "\x1b[c";        // DA1 "who are you?"
    private const TIMEOUT_MS = 100;

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
        $cap = self::probeEnv();

        // Env vars gave a definite answer — no DA1 needed.
        if ($cap->kitty || $cap->iterm2) {
            return $cap;
        }

        // Try DA1 to detect sixel when env vars were inconclusive.
        $sixelViaDa1 = self::probeDa1();
        if ($sixelViaDa1 === true) {
            return Capability::sixel($cap->cellSize);
        }

        return $cap;
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
            is_resource(STDIN)
            && is_resource(STDOUT)
            && @posix_isatty(0)
            && @posix_isatty(1)
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
     * Detect based solely on environment variables (no TTY I/O).
     */
    private static function probeEnv(): Capability
    {
        // Kitty: KITTY_WINDOW_ID set, or known kitty-family $TERM, or ghostty/WezTerm.
        if (getenv('KITTY_WINDOW_ID') !== false
            || getenv('TERM_PROGRAM') === 'WezTerm'
            || getenv('TERM_PROGRAM') === 'ghostty'
            || preg_match('/xterm-kitty/i', (string) getenv('TERM')) === 1
        ) {
            return Capability::kitty();
        }

        // iTerm2: iTerm.app, iTerm2, WezTerm, mintty, or LC_TERMINAL=iTerm2.
        $termProgram = (string) getenv('TERM_PROGRAM');
        $lcTerminal  = (string) getenv('LC_TERMINAL');
        if ($termProgram === 'iTerm.app'
            || $termProgram === 'iTerm2'
            || $termProgram === 'WezTerm'
            || $termProgram === 'mintty'
            || $lcTerminal === 'iTerm2'
        ) {
            return Capability::iterm2();
        }

        // Sixel: strong env-var hints (mlterm, foot, xterm with XTERM_VERSION).
        if (self::hasSixelEnvHints()) {
            return Capability::sixel();
        }

        // Half-block: always available.
        return Capability::unknown();
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
