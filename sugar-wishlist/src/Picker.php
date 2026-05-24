<?php

declare(strict_types=1);

namespace SugarCraft\Wishlist;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\TtyDetect;

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

    /**
     * @param resource|null $in
     * @param resource|null $out
     */
    public function __construct($in = null, $out = null)
    {
        $this->in  = $in  ?? STDIN;
        $this->out = $out ?? STDOUT;
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
                        return $matches[$this->cursor];
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
     * @return list<Endpoint>
     */
    private function filterMatches(array $endpoints): array
    {
        if ($this->filter === '') {
            return $endpoints;
        }
        $needle = strtolower($this->filter);
        $out = [];
        foreach ($endpoints as $e) {
            $hay = strtolower($e->name . ' ' . $e->host . ' ' . ($e->user ?? ''));
            if (str_contains($hay, $needle)) {
                $out[] = $e;
            }
        }
        return $out;
    }

    /**
     * @param list<Endpoint> $matches
     */
    private function draw(array $matches): void
    {
        fwrite($this->out, Ansi::cursorTo(1, 1) . Ansi::eraseToEnd());
        fwrite($this->out, "── wishlist ──\r\n");
        fwrite($this->out, "filter: \x1b[36m{$this->filter}\x1b[0m\r\n");
        if ($matches === []) {
            fwrite($this->out, "  (no matches)\r\n");
        }
        foreach ($matches as $i => $e) {
            $marker = $i === $this->cursor ? "\x1b[1;36m▸\x1b[0m " : '  ';
            $line   = $e->displayLine();
            if ($e->description !== null && $e->description !== '') {
                $line .= '  \x1b[2m' . $e->description . '\x1b[0m';
            }
            fwrite($this->out, "{$marker}{$line}\r\n");
        }
        fwrite($this->out, "\r\n  ↑/↓ select · Enter connect · Esc quit · type to filter\r\n");
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
        // final byte in 0x40-0x7e. Non-blocking so a lone Esc doesn't
        // hang, and a single byte at a time so we don't gobble the
        // next keypress.
        stream_set_blocking($this->in, false);
        try {
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
        } finally {
            stream_set_blocking($this->in, true);
        }
    }

    /**
     * Toggle the controlling tty into raw mode so the picker sees
     * keys one byte at a time. Falls back silently if `stty` is
     * unavailable (e.g. non-tty stream in tests).
     *
     * Note: TtyDetect only covers TTY detection, not raw-mode toggling.
     * The stty calls below are direct and should be moved to TtyDetect
     * if/when raw-mode support is added there (follow-up issue).
     */
    protected function setRawMode(bool $on): void
    {
        if (!TtyDetect::isAtty($this->in)) {
            return;
        }
        if ($on) {
            shell_exec('stty -icanon -echo min 1 time 0 2>/dev/null');
        } else {
            shell_exec('stty sane 2>/dev/null');
        }
    }
}
