<?php

declare(strict_types=1);

namespace SugarCraft\Top\View;

use SugarCraft\Core\Util\Width;

/**
 * The per-frame cell grid every box, panel and embed paints into.
 *
 * btop builds a frame as one string of absolute `Mv::to` jumps; a TEA view
 * must instead return rows, and the line-diff renderer assumes every row is
 * exactly one terminal row. The surface is what makes that a guarantee
 * rather than a hope: a write can never grow a row past the width (it is
 * clipped cluster by cluster), {@see lines()} always returns exactly
 * `height` rows of exactly `width` cells, and wide clusters that would
 * straddle an edge or get half-overwritten degrade to spaces.
 *
 * Deliberately MUTABLE, unlike the house value objects: it is a scratch
 * buffer that lives for one `view()` call (a string builder for cells), and
 * copy-on-write per cell would turn a 200x60 frame into tens of thousands of
 * array copies every repaint. Nothing outside a single render holds one.
 *
 * Each cell stores its glyph and the CANONICAL SGR that styles it — one
 * escape folding every attribute, the fg and the bg, never a history of
 * escapes. {@see $base} (theme main_bg + main_fg) is re-applied after every
 * reset so unstyled text and blanks keep the theme background. Keeping the
 * state canonical is what bounds the output: appending each incoming escape
 * made a 200-cell gradient row re-emit an ever-growing run per cell
 * (quadratic, 3.8 KB in -> 325 KB out).
 */
final class Surface
{
    /** Marks the right half of a two-cell cluster. */
    private const CONT = '';

    /** @var list<list<array{0: string, 1: string}>> */
    private array $cells;

    /** Folded SGR state with nothing set. */
    private const PLAIN = ['a' => [], 'fg' => null, 'bg' => null, 'ul' => null];

    /** Memo for {@see canonical()}; bounded so a hostile stream cannot grow it forever. */
    private const MEMO_LIMIT = 4096;

    /** @var array<string, string> */
    private static array $memo = [];

    private readonly Rect $bounds;

    public readonly string $base;

    private function __construct(
        public readonly int $width,
        public readonly int $height,
        string $base,
    ) {
        $this->base = self::canonical($base);
        $this->bounds = Rect::new(0, 0, $width, $height);
        $row = array_fill(0, max(0, $width), [' ', '']);
        $this->cells = array_fill(0, max(0, $height), $row);
    }

    /**
     * @param string $base SGR re-applied after every reset (theme background/foreground); '' for none
     */
    public static function new(int $width, int $height, string $base = ''): self
    {
        return new self(max(0, $width), max(0, $height), $base);
    }

    public function bounds(): Rect
    {
        return $this->bounds;
    }

    /** A clipped view onto `$rect` with rect-local coordinates. */
    public function region(Rect $rect): Region
    {
        return new Region($this, $rect->intersect($this->bounds));
    }

    /**
     * Write plain `$text` at ($x, $y) in style `$sgr`, clipped to `$clip`
     * (default: the whole surface). Control characters are dropped — a
     * process name or hostname must never smuggle an escape into the frame.
     *
     * @return int columns advanced (clipped cells count as advanced only while inside the clip)
     */
    public function put(int $x, int $y, string $text, string $sgr = '', ?Rect $clip = null): int
    {
        $clip = $clip === null ? $this->bounds : $clip->intersect($this->bounds);
        if ($text === '' || $y < $clip->y || $y >= $clip->bottom()) {
            return 0;
        }
        $text = (string) preg_replace('/[\x00-\x1f\x7f]|\xc2[\x80-\x9f]/', '', $text);
        $sgr = self::canonical($sgr);
        $col = $x;
        $len = strlen($text);
        for ($i = 0; $i < $len;) {
            $cluster = Width::nextCluster($text, $i);
            $i += max(1, strlen($cluster));
            $w = Width::string($cluster);
            if ($w <= 0) {
                continue;
            }
            if ($col + $w > $clip->right()) {
                // A wide cluster with one column left would overhang the edge.
                if ($col < $clip->right() && $col >= $clip->x) {
                    $this->set($col, $y, ' ', $sgr);
                }
                break;
            }
            if ($col >= $clip->x) {
                $this->set($col, $y, $cluster, $sgr);
                if ($w === 2) {
                    $this->set($col + 1, $y, self::CONT, $sgr);
                }
            } elseif ($w === 2 && $col + 1 === $clip->x) {
                // Left half clipped away: keep the visible half honest.
                $this->set($col + 1, $y, ' ', $sgr);
            }
            $col += $w;
        }

        return max(0, $col - $x);
    }

    /**
     * Write a line that carries its own SGR escapes (e.g. a pre-rendered
     * graph row or a bold title run). Each SGR escape updates the folded
     * state (a later fg REPLACES the earlier one, `22` drops bold, `0`
     * resets); every other escape sequence is discarded. `$sgr` is the
     * starting style.
     *
     * @return int columns advanced
     */
    public function ansi(int $x, int $y, string $line, ?Rect $clip = null, string $sgr = ''): int
    {
        $parts = preg_split('/(\x1b\[[0-9;:?]*[ -\/]*[@-~]|\x1b\][^\x07\x1b]*(?:\x07|\x1b\\\\)|\x1b.?)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $col = $x;
        $state = self::fold(self::PLAIN, $sgr);
        $sgr = self::emit($state);
        foreach ($parts === false ? [] : $parts as $part) {
            if ($part[0] !== "\x1b") {
                $col += $this->put($col, $y, $part, $sgr, $clip);
                continue;
            }
            if (preg_match('/^\x1b\[([0-9;:]*)m$/', $part, $m) === 1) {
                $state = self::apply($state, $m[1]);
                $sgr = self::emit($state);
            }
        }

        return max(0, $col - $x);
    }

    /** Paint `$rect` with spaces in style `$sgr`. */
    public function fill(Rect $rect, string $sgr = ''): void
    {
        $r = $rect->intersect($this->bounds);
        $sgr = self::canonical($sgr);
        for ($y = $r->y; $y < $r->bottom(); $y++) {
            for ($x = $r->x; $x < $r->right(); $x++) {
                $this->set($x, $y, ' ', $sgr);
            }
        }
    }

    /** Glyph at ($x, $y): '' for the right half of a wide cluster. */
    public function glyph(int $x, int $y): string
    {
        return $this->cells[$y][$x][0];
    }

    /** Style run at ($x, $y). */
    public function style(int $x, int $y): string
    {
        return $this->cells[$y][$x][1];
    }

    /**
     * Rendered rows: exactly {@see $height} of them, each exactly
     * {@see $width} cells, each self-contained (starts from a reset, ends
     * with one) so the line-diff renderer can repaint any row alone.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        $out = [];
        foreach ($this->cells as $row) {
            $line = '';
            $prev = null;
            foreach ($row as [$glyph, $sgr]) {
                if ($glyph === self::CONT) {
                    continue;
                }
                if ($sgr !== $prev) {
                    $line .= "\x1b[0m" . $this->base . $sgr;
                    $prev = $sgr;
                }
                $line .= $glyph;
            }
            $out[] = $line . "\x1b[0m";
        }

        return $out;
    }

    /** The whole frame, rows joined by "\n". */
    public function render(): string
    {
        return implode("\n", $this->lines());
    }

    /**
     * Rows as plain text (no escapes) — the cell-grid view tests assert on.
     *
     * @return list<string>
     */
    public function plainLines(): array
    {
        return array_map(
            static fn (array $row): string => implode('', array_map(static fn (array $c): string => $c[0], $row)),
            $this->cells,
        );
    }

    /**
     * `$sgr` (any run of SGR escapes) folded into one canonical escape:
     * attributes ascending, then fg, bg, underline colour; '' when the run
     * leaves nothing set. Non-SGR bytes are ignored.
     */
    public static function canonical(string $sgr): string
    {
        if ($sgr === '') {
            return '';
        }
        if (isset(self::$memo[$sgr])) {
            return self::$memo[$sgr];
        }
        if (count(self::$memo) >= self::MEMO_LIMIT) {
            self::$memo = [];
        }

        return self::$memo[$sgr] = self::emit(self::fold(self::PLAIN, $sgr));
    }

    /**
     * @param array{a: array<int, string>, fg: ?string, bg: ?string, ul: ?string} $state
     * @return array{a: array<int, string>, fg: ?string, bg: ?string, ul: ?string}
     */
    private static function fold(array $state, string $sgr): array
    {
        if ($sgr !== '' && preg_match_all('/\x1b\[([0-9;:]*)m/', $sgr, $m) > 0) {
            foreach ($m[1] as $params) {
                $state = self::apply($state, $params);
            }
        }

        return $state;
    }

    /**
     * One SGR parameter list applied to `$state`.
     *
     * @param array{a: array<int, string>, fg: ?string, bg: ?string, ul: ?string} $state
     * @return array{a: array<int, string>, fg: ?string, bg: ?string, ul: ?string}
     */
    private static function apply(array $state, string $params): array
    {
        $p = explode(';', $params);
        $n = count($p);
        for ($i = 0; $i < $n; $i++) {
            $token = $p[$i];
            if (str_contains($token, ':')) {
                // Colon sub-parameters are self-contained (38:2::r:g:b, 4:3).
                match (strstr($token, ':', true)) {
                    '38' => $state['fg'] = $token,
                    '48' => $state['bg'] = $token,
                    '58' => $state['ul'] = $token,
                    '4' => $token === '4:0' ? $state['a'][4] = null : $state['a'][4] = $token,
                    default => null,
                };
                $state['a'] = array_filter($state['a'], static fn (?string $v): bool => $v !== null);
                continue;
            }
            $c = (int) $token;
            if ($c === 38 || $c === 48 || $c === 58) {
                $mode = $p[$i + 1] ?? '';
                if ($mode === '5') {
                    $value = $c . ';5;' . (int) ($p[$i + 2] ?? 0);
                    $i += 2;
                } elseif ($mode === '2') {
                    $value = $c . ';2;' . (int) ($p[$i + 2] ?? 0) . ';' . (int) ($p[$i + 3] ?? 0) . ';' . (int) ($p[$i + 4] ?? 0);
                    $i += 4;
                } else {
                    break; // malformed extended colour: the rest is unparseable
                }
                $state[$c === 38 ? 'fg' : ($c === 48 ? 'bg' : 'ul')] = $value;
                continue;
            }
            switch (true) {
                case $c === 0:
                    $state = self::PLAIN;
                    break;
                case $c === 21:
                    $state['a'][4] = '21';
                    break;
                case ($c >= 1 && $c <= 9) || $c === 53:
                    $state['a'][$c] = (string) $c;
                    break;
                case $c === 22:
                    unset($state['a'][1], $state['a'][2]);
                    break;
                case $c === 24:
                    unset($state['a'][4]);
                    break;
                case $c === 25:
                    unset($state['a'][5], $state['a'][6]);
                    break;
                case $c >= 23 && $c <= 29:
                    unset($state['a'][$c - 20]);
                    break;
                case $c === 55:
                    unset($state['a'][53]);
                    break;
                case ($c >= 30 && $c <= 37) || ($c >= 90 && $c <= 97):
                    $state['fg'] = (string) $c;
                    break;
                case $c === 39:
                    $state['fg'] = null;
                    break;
                case ($c >= 40 && $c <= 47) || ($c >= 100 && $c <= 107):
                    $state['bg'] = (string) $c;
                    break;
                case $c === 49:
                    $state['bg'] = null;
                    break;
                case $c === 59:
                    $state['ul'] = null;
                    break;
            }
        }

        return $state;
    }

    /** @param array{a: array<int, string>, fg: ?string, bg: ?string, ul: ?string} $state */
    private static function emit(array $state): string
    {
        $attrs = $state['a'];
        ksort($attrs);
        $parts = array_values($attrs);
        foreach (['fg', 'bg', 'ul'] as $k) {
            if ($state[$k] !== null) {
                $parts[] = $state[$k];
            }
        }

        return $parts === [] ? '' : "\x1b[" . implode(';', $parts) . 'm';
    }

    private function set(int $x, int $y, string $glyph, string $sgr): void
    {
        if ($x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) {
            return;
        }
        $old = $this->cells[$y][$x][0];
        // Overwriting either half of a wide cluster orphans the other half.
        if ($old === self::CONT && $x > 0 && $glyph !== self::CONT) {
            $this->cells[$y][$x - 1] = [' ', $this->cells[$y][$x - 1][1]];
        }
        if ($old !== self::CONT && $x + 1 < $this->width && $this->cells[$y][$x + 1][0] === self::CONT) {
            $this->cells[$y][$x + 1] = [' ', $this->cells[$y][$x + 1][1]];
        }
        $this->cells[$y][$x] = [$glyph, $sgr];
    }
}
