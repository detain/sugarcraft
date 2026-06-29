<?php

declare(strict_types=1);

namespace SugarCraft\Wishlist;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\RawMode;
use SugarCraft\Fuzzy\MatchResult;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;

/**
 * Tiny terminal picker — renders a numbered list of {@see Endpoint}s,
 * lets the user move with j/k or ↑/↓, narrow with type-to-search,
 * and pick with Enter. Returns the selected Endpoint or null on
 * Esc / Ctrl-C.
 *
 * Why not the full SugarBits `List` widget? That's overkill for a
 * one-shot picker that we immediately replace with `pcntl_exec`.
 * This class only needs to draw a static list, read a few keys,
 * and exit fast — no event loop, no full Program lifecycle.
 *
 * Override `inputStream()` / `outputStream()` for tests.
 */
class Picker
{
    /** @var resource */
    protected $in;
    /** @var resource */
    protected $out;
    private string $filter = '';
    private int $cursor = 0;
    private SmithWatermanMatcher $matcher;

    /**
     * @param resource|null $in
     * @param resource|null $out
     */
    public function __construct($in = null, $out = null)
    {
        $this->in  = $in  ?? STDIN;
        $this->out = $out ?? STDOUT;
        $this->matcher = new SmithWatermanMatcher();
    }

    /**
     * @param list<Endpoint> $endpoints
     */
    public function pick(array $endpoints): ?Endpoint
    {
        if ($endpoints === []) {
            return null;
        }
        $this->setRawMode(true);
        try {
            while (true) {
                $matches = $this->filterMatches($endpoints);
                if ($this->cursor >= count($matches)) {
                    $this->cursor = max(0, count($matches) - 1);
                }
                $this->draw($matches);

                $key = $this->readKey();
                switch ($key) {
                    case "\x03": /* ^C */
                    case "\x1b": /* ESC */
                        return null;
                    case "\r":
                    case "\n":
                        if ($matches === []) {
                            continue 2;
                        }
                        return $matches[$this->cursor]['endpoint'];
                    case 'j':
                    case "\x1b[B":
                        if ($this->cursor < count($matches) - 1) {
                            $this->cursor++;
                        }
                        break;
                    case 'k':
                    case "\x1b[A":
                        if ($this->cursor > 0) {
                            $this->cursor--;
                        }
                        break;
                    case "\x7f": /* backspace */
                    case "\x08":
                        $this->filter = substr($this->filter, 0, -1);
                        $this->cursor = 0;
                        break;
                    default:
                        if (preg_match('/^[\w\d\-\. ]$/', $key)) {
                            $this->filter .= $key;
                            $this->cursor = 0;
                        }
                }
            }
        } finally {
            $this->setRawMode(false);
            fwrite($this->out, "\n");
        }
    }

    /**
     * @param list<Endpoint> $endpoints
     * @return list<array{endpoint: Endpoint, result: ?MatchResult}>
     */
    private function filterMatches(array $endpoints): array
    {
        if ($this->filter === '') {
            return array_map(
                fn(Endpoint $e) => ['endpoint' => $e, 'result' => null],
                $endpoints
            );
        }

        // Score each candidate; SmithWatermanMatcher returns null for no match
        $results = $this->matcher->matchAll(
            $this->filter,
            array_map(
                fn(Endpoint $e) => $e->name . ' ' . $e->host . ' ' . ($e->user ?? ''),
                $endpoints
            )
        );

        // Build a map from haystack string to MatchResult for quick lookup
        $resultMap = [];
        foreach ($results as $r) {
            $resultMap[$r->haystack] = $r;
        }

        // Walk endpoints in original order, attaching MatchResult
        $out = [];
        foreach ($endpoints as $e) {
            $hay = $e->name . ' ' . $e->host . ' ' . ($e->user ?? '');
            $out[] = ['endpoint' => $e, 'result' => $resultMap[$hay] ?? null];
        }

        // Filter to only matched (score > 0) and sort by score descending
        $matched = array_filter($out, static fn(array $r) => $r['result'] !== null);
        usort($matched, static fn(array $a, array $b) =>
            $b['result']->score <=> $a['result']->score
        );

        return $matched;
    }

    /**
     * @param list<array{endpoint: Endpoint, result: ?MatchResult}> $matches
     */
    private function draw(array $matches): void
    {
        fwrite($this->out, Ansi::cursorTo(1, 1) . Ansi::eraseToEnd());
        fwrite($this->out, "── wishlist ──\r\n");
        fwrite($this->out, 'filter: ' . Ansi::sgr(36) . $this->filter . Ansi::reset() . "\r\n");
        if ($matches === []) {
            fwrite($this->out, "  (no matches)\r\n");
        }
        foreach ($matches as $i => $record) {
            $e = $record['endpoint'];
            $marker = $i === $this->cursor ? Ansi::sgr(1, 36) . '▸' . Ansi::reset() . ' ' : '  ';
            // Sanitize the display string before highlighting to prevent
            // terminal escape sequence injection from config values.
            $sanitizedDisplay = $this->stripControls($e->displayLine());
            $line = $this->highlightLine($sanitizedDisplay, $record['result']);
            if ($e->description !== null && $e->description !== '') {
                $line .= '  ' . Ansi::sgr(Ansi::FAINT) . $this->stripControls($e->description) . Ansi::reset();
            }
            fwrite($this->out, "{$marker}{$line}\r\n");
        }
        fwrite($this->out, "\r\n  ↑/↓ select · Enter connect · Esc quit · type to filter\r\n");
    }

    /**
     * Strip ANSI escape sequences and C0 control characters from a string
     * before writing it to the terminal. This prevents malicious config
     * values (names, descriptions) from injecting escape sequences.
     */
    private function stripControls(string $s): string
    {
        // Ansi::strip() removes CSI, OSC, and lone ESC sequences.
        $s = Ansi::strip($s);
        // Remove remaining C0 control characters except CR/LF (which the
        // picker handles as line terminators). This catches BEL, STX, etc.
        return preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $s);
    }

    /**
     * Apply bold+cyan highlighting to matched character positions in a display line.
     *
     * The $line argument is expected to be already sanitized via stripControls().
     * Re-matches the display line against the filter needle to get fresh
     * matched indices, then applies ANSI highlighting to those positions.
     */
    private function highlightLine(string $line, ?MatchResult $result): string
    {
        if ($result === null) {
            return $line;
        }

        // Re-match against the display line to get indices aligned with it
        $dispResult = $this->matcher->match($result->needle, $line);
        if ($dispResult === null || $dispResult->matchedIndices === []) {
            return $line;
        }

        $matchSet = array_flip($dispResult->matchedIndices);

        // Walk the line as grapheme clusters and wrap matched ones in ANSI bold+cyan
        $highlighted = '';
        $idx = 0;
        preg_match_all('/\X/u', $line, $matches);
        foreach ($matches[0] as $grapheme) {
            if (isset($matchSet[$idx])) {
                $highlighted .= Ansi::sgr(1, 36) . $grapheme . Ansi::reset();
            } else {
                $highlighted .= $grapheme;
            }
            $idx++;
        }

        return $highlighted;
    }

    private function readKey(): string
    {
        $b = fread($this->in, 1);
        if ($b === false || $b === '') {
            return "\x03";
        }
        if ($b !== "\x1b") {
            return $b;
        }
        // Try to read a CSI sequence: ESC `[` followed by params and a
        // final byte in 0x40-0x7e. Use stream_select with a 50ms timeout
        // on real TTY streams to wait for continuation bytes — this lets
        // us distinguish a genuine lone ESC keypress (which arrives without
        // follow-on bytes) from an arrow-key sequence (where the bytes
        // arrive in quick succession). Memory streams don't support
        // stream_select, so we fall back to immediate fread for those.
        stream_set_blocking($this->in, false);
        try {
            $isMemoryStream = (stream_get_meta_data($this->in)['stream_type'] ?? '') === 'MEMORY';

            if ($isMemoryStream) {
                // Memory streams: data is already buffered; fread returns
                // immediately if bytes are present. This preserves the
                // existing test behavior.
                $next = fread($this->in, 1);
                if ($next !== '[') {
                    return $next === false || $next === '' ? "\x1b" : "\x1b" . $next;
                }
                $seq = "\x1b[";
                for ($i = 0; $i < 16; $i++) {
                    $c = fread($this->in, 1);
                    if ($c === false || $c === '') {
                        break;
                    }
                    $seq .= $c;
                    $code = ord($c);
                    if ($code >= 0x40 && $code <= 0x7e) {
                        break;
                    }
                }
                return $seq;
            }

            // Real TTY / pipe: use stream_select as a 50ms timer to wait
            // for continuation bytes after the ESC.
            $r = [$this->in];
            $w = null;
            $e = null;
            $changed = @stream_select($r, $w, $e, 0, 50000);
            if ($changed === false || $changed === 0) {
                // Timeout — no continuation bytes arrived, treat as
                // a genuine lone ESC keypress.
                return "\x1b";
            }
            $next = fread($this->in, 1);
            if ($next !== '[') {
                return $next === false || $next === '' ? "\x1b" : "\x1b" . $next;
            }
            $seq = "\x1b[";
            // Read remaining CSI bytes, waiting up to 50ms each.
            for ($i = 0; $i < 16; $i++) {
                $r = [$this->in];
                $w = null;
                $e = null;
                $changed = @stream_select($r, $w, $e, 0, 50000);
                if ($changed === false || $changed === 0) {
                    break;
                }
                $c = fread($this->in, 1);
                if ($c === false || $c === '') {
                    break;
                }
                $seq .= $c;
                $code = ord($c);
                if ($code >= 0x40 && $code <= 0x7e) {
                    break;
                }
            }
            return $seq;
        } finally {
            stream_set_blocking($this->in, true);
        }
    }

    /**
     * Toggle the controlling tty into raw mode so the picker sees
     * keys one byte at a time. Delegates to candy-core's portable
     * {@see RawMode} helper, which is a safe no-op on non-tty streams
     * (e.g. piped input in tests).
     */
    protected function setRawMode(bool $on): void
    {
        if ($on) {
            RawMode::enable($this->in);
        } else {
            RawMode::disable($this->in);
        }
    }
}
