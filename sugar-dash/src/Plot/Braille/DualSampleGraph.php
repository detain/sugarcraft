<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot\Braille;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Dash\Foundation\SizedItem;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Dash\Plot\Gradient101;

/**
 * btop's history graph: two adjacent samples quantized into one cell glyph.
 *
 * Every cell pairs the previous sample (left half / row of the 5×5 table)
 * with the current one (right half / column), each reduced to a band 0..4
 * per graph row, and looks the pair up in a 25-entry glyph table. The
 * output is the frame btop's `Graph` constructor builds over the same
 * sample deque; every {@see push()} rebuilds that frame from the retained
 * history, so the newest sample always lands in the rightmost cell.
 *
 * Four symbol families mirror btop's `graph_symbols` map: braille (two
 * samples per cell), block (quadrants; bands 1/2 share a glyph, so the
 * five bands collapse to four visible heights), block2 (2×3 sextants,
 * U+1FB00 block: bands 0..3, so its quantizer clamps at 3 with a larger
 * rounding bias — see {@see quantizer()}) and tty (shades only, one sample
 * per cell, its glyph still keyed on the previous sample). Sextant glyphs
 * need font support (Iosevka, Cascadia, JetBrains Mono 2.3+, or a
 * terminal with built-in box drawing); braille stays the default.
 *
 * Deviations, all presentation-side: btop returns an empty string before
 * the first sample, here the blank padded frame keeps the layout width
 * stable; btop's frame-wide `Fx::reset` and cursor moves become one reset
 * per row and "\n" so each line composes on its own; cells btop skips
 * with `Mv::r(1)` (height-1 blanks and padding) print as a space, or as
 * the graph_bg underlay glyph when {@see withUnderlay()} is set — the
 * canvas-composition equivalent of btop printing the underlay first and
 * overdrawing. A short history whose first cell pairs with a missing
 * predecessor reads that predecessor as 0, which is the constructor path;
 * btop's incremental `operator()` path can differ from it in that single
 * leading half-cell under no_zero.
 *
 * Mirrors aristocratos/btop Draw::Graph (Graph::Graph + Graph::_create,
 * src/btop_draw.cpp) and Symbols::graph_symbols; block2 mirrors upstream
 * PR aristocratos/btop#1783 at f3fb5b8c6b3020e9020cf49a7fed5123b86ec925.
 * That PR also makes Graph::operator()'s first-glyph trim UTF-8 aware
 * (it erased a fixed 3 bytes, which would split a 4-byte sextant); this
 * port rebuilds the frame from the sample history on every push and keeps
 * cells as whole glyphs, so it has no byte trim to fix.
 */
final class DualSampleGraph implements SizedItem
{
    public const FAMILY_BRAILLE = 'braille';
    public const FAMILY_BLOCK = 'block';
    public const FAMILY_TTY = 'tty';
    /** Sextant family from btop PR #1783 ("block2"). */
    public const FAMILY_BLOCK2 = 'block2';

    /** @var list<string> every family, in btop's valid_graph_symbols order */
    public const FAMILIES = [self::FAMILY_BRAILLE, self::FAMILY_BLOCK, self::FAMILY_BLOCK2, self::FAMILY_TTY];

    /**
     * Verbatim port of btop's Symbols::graph_symbols. Index = prev*5 + cur.
     * block2_* are verbatim from btop PR #1783: 5×5 like the rest ("The
     * size of all charts is assumed to be 5x5, so pad with spaces"), with
     * only bands 0..3 reachable, so row 4 and column 4 are padding.
     *
     * @var array<string, list<string>>
     */
    public const SYMBOLS = [
        'braille_up' => [
            ' ', '⢀', '⢠', '⢰', '⢸',
            '⡀', '⣀', '⣠', '⣰', '⣸',
            '⡄', '⣄', '⣤', '⣴', '⣼',
            '⡆', '⣆', '⣦', '⣶', '⣾',
            '⡇', '⣇', '⣧', '⣷', '⣿',
        ],
        'braille_down' => [
            ' ', '⠈', '⠘', '⠸', '⢸',
            '⠁', '⠉', '⠙', '⠹', '⢹',
            '⠃', '⠋', '⠛', '⠻', '⢻',
            '⠇', '⠏', '⠟', '⠿', '⢿',
            '⡇', '⡏', '⡟', '⡿', '⣿',
        ],
        'block_up' => [
            ' ', '▗', '▗', '▐', '▐',
            '▖', '▄', '▄', '▟', '▟',
            '▖', '▄', '▄', '▟', '▟',
            '▌', '▙', '▙', '█', '█',
            '▌', '▙', '▙', '█', '█',
        ],
        'block_down' => [
            ' ', '▝', '▝', '▐', '▐',
            '▘', '▀', '▀', '▜', '▜',
            '▘', '▀', '▀', '▜', '▜',
            '▌', '▛', '▛', '█', '█',
            '▌', '▛', '▛', '█', '█',
        ],
        'block2_up' => [
            ' ', '🬞', '🬦', '▐', ' ',
            '🬏', '🬭', '🬵', '🬷', ' ',
            '🬓', '🬱', '🬹', '🬻', ' ',
            '▌', '🬲', '🬺', '█', ' ',
            ' ', ' ', ' ', ' ', ' ',
        ],
        'block2_down' => [
            ' ', '🬁', '🬉', '▐', ' ',
            '🬀', '🬂', '🬊', '🬨', ' ',
            '🬄', '🬆', '🬎', '🬬', ' ',
            '▌', '🬕', '🬝', '█', ' ',
            ' ', ' ', ' ', ' ', ' ',
        ],
        'tty_up' => [
            ' ', '░', '░', '▒', '▒',
            '░', '░', '▒', '▒', '█',
            '░', '▒', '▒', '▒', '█',
            '▒', '▒', '▒', '█', '█',
            '▒', '█', '█', '█', '█',
        ],
        'tty_down' => [
            ' ', '░', '░', '▒', '▒',
            '░', '░', '▒', '▒', '█',
            '░', '▒', '▒', '▒', '█',
            '▒', '▒', '▒', '█', '█',
            '▒', '█', '█', '█', '█',
        ],
    ];

    /**
     * btop's graph_bg idiom reads `graph_symbols.at(<family>_up).at(6)`:
     * band (1,1), the lowest non-blank level in both halves — a neutral
     * floor line (⣀ / ▄ / 🬭 / ░).
     */
    private const UNDERLAY_INDEX = 6;

    /**
     * Retained-history floor: a graph shrunk and grown back within this
     * many cells (×2, +1 predecessor) redraws its old samples instead of
     * starting blank.
     */
    public const HISTORY_CELLS = 1024;

    /**
     * Samples and offsets saturate at ±this so the percent law
     * `(v + offset) * 100` cannot overflow PHP_INT_MAX (btop's `long long`
     * overflow is UB; saturating keeps every result in the clamped band).
     */
    public const SAMPLE_LIMIT = 46_116_860_184_273_879; // intdiv(PHP_INT_MAX, 200)

    /** @var list<int> raw samples, oldest first, capped by {@see trim()} */
    private array $samples = [];

    /** Widest width this graph has had, so a resize never drops visible history. */
    private int $widthSeen;

    /** @var list<Color>|null */
    private ?array $gradientMemo = null;

    private ?Color $underlayColor = null;

    private function __construct(
        private int $width,
        private int $height,
        private string $family,
        private bool $invert,
        private bool $noZero,
        private int $maxValue,
        private int $offset,
    ) {
        $this->widthSeen = $width;
    }

    /**
     * Argument order follows btop's Graph constructor (max_value before
     * offset). A positive offset with no max_value implies max_value 100,
     * as in btop, so the stored maxValue() is the effective one.
     *
     * @throws \InvalidArgumentException on width/height < 1 or an unknown family
     */
    public static function new(
        int $width,
        int $height,
        string $family = self::FAMILY_BRAILLE,
        bool $invert = false,
        bool $noZero = false,
        int $maxValue = 0,
        int $offset = 0,
    ): self {
        if ($width < 1 || $height < 1) {
            throw new \InvalidArgumentException(sprintf(
                'DualSampleGraph needs width and height >= 1, got %dx%d',
                $width,
                $height,
            ));
        }
        self::assertFamily($family);
        if ($maxValue === 0 && $offset > 0) {
            $maxValue = 100;
        }
        $offset = max(-self::SAMPLE_LIMIT, min(self::SAMPLE_LIMIT, $offset));
        return new self($width, $height, $family, $invert, $noZero, $maxValue, $offset);
    }

    /**
     * Append samples (oldest first); the frame scrolls left by half a cell
     * per sample (a whole cell in the tty family).
     *
     * @throws \InvalidArgumentException on a non-finite float
     */
    public function push(int|float ...$values): self
    {
        $clone = clone $this;
        $clone->samples = $clone->trim([...$this->samples, ...self::ingest($values)]);
        return $clone;
    }

    /**
     * Replace the whole history, like handing btop's constructor a deque.
     *
     * @throws \InvalidArgumentException on a non-finite float
     */
    public function withData(int|float ...$values): self
    {
        $clone = clone $this;
        $clone->samples = $clone->trim(self::ingest($values));
        return $clone;
    }

    /**
     * Color through a 101-stop ramp ({@see Gradient101::expand()}). Height 1
     * colors each drawn cell by max(prev, cur); taller graphs color each row
     * by its vertical position. Named withGradient() rather than the plan's
     * setGradient() to keep the immutable with*() convention.
     *
     * @param list<Color> $stops ordered low→high, at least 2
     * @throws \InvalidArgumentException on fewer than 2 stops or a non-Color stop
     */
    public function withGradient(array $stops): self
    {
        return $this->mutate(['gradientMemo' => Gradient101::expand($stops)]);
    }

    public function withoutGradient(): self
    {
        return $this->mutate(['gradientMemo' => null]);
    }

    /**
     * Show the family's graph_bg glyph, in `$inactiveFg`, wherever btop's
     * graph is transparent. Only height-1 graphs have transparent cells
     * (btop prints taller graphs' blanks as opaque spaces), matching every
     * btop call site, which underlays only one-row graphs.
     */
    public function withUnderlay(Color $inactiveFg): self
    {
        return $this->mutate(['underlayColor' => $inactiveFg]);
    }

    public function withoutUnderlay(): self
    {
        return $this->mutate(['underlayColor' => null]);
    }

    /** The family's graph_bg glyph: ⣀ braille, ▄ block, 🬭 block2, ░ tty. */
    public function underlayGlyph(): string
    {
        return self::SYMBOLS[$this->family . '_up'][self::UNDERLAY_INDEX];
    }

    /**
     * btop's standalone underlay strip,
     * `Theme::c("inactive_fg") + graph_bg * n`, for callers that compose
     * the overdraw themselves.
     *
     * @throws \InvalidArgumentException on an unknown family
     */
    public static function underlay(string $family, int $cells, Color $inactiveFg, ?ColorProfile $profile = null): string
    {
        self::assertFamily($family);
        if ($cells < 1) {
            return '';
        }
        $profile ??= ColorProfile::detect();
        $glyphs = str_repeat(self::SYMBOLS[$family . '_up'][self::UNDERLAY_INDEX], $cells);
        if (!$profile->supportsAnsi()) {
            return $glyphs;
        }
        return $inactiveFg->toFg($profile) . $glyphs . Ansi::reset();
    }

    public function render(?ColorProfile $profile = null): string
    {
        $profile ??= ColorProfile::detect();
        $ansi = $profile->supportsAnsi();
        $frame = $this->frame();

        if ($this->height === 1) {
            return $this->renderSingleRow($frame['rows'][0], $profile, $ansi);
        }

        $lines = [];
        foreach ($frame['rows'] as $r => $cells) {
            $line = implode('', array_column($cells, 'glyph'));
            if ($ansi && $this->gradientMemo !== null) {
                $line = $this->gradientMemo[$frame['rowColors'][$r]]->toFg($profile) . $line . Ansi::reset();
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    public function setSize(int $width, int $height): Sizer
    {
        // Layout containers may offer a zero-size slot; a graph needs at
        // least one cell to compute a frame, so clamp rather than throw.
        $width = max(1, $width);
        return $this->mutate([
            'width' => $width,
            'height' => max(1, $height),
            'widthSeen' => max($this->widthSeen, $width),
        ]);
    }

    public function getInnerSize(): array
    {
        return [$this->width, $this->height];
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    public function family(): string
    {
        return $this->family;
    }

    public function inverted(): bool
    {
        return $this->invert;
    }

    public function noZero(): bool
    {
        return $this->noZero;
    }

    public function maxValue(): int
    {
        return $this->maxValue;
    }

    public function offset(): int
    {
        return $this->offset;
    }

    /** @return list<int> retained raw samples, oldest first */
    public function samples(): array
    {
        return $this->samples;
    }

    /** @return list<Color>|null the 101-entry ramp, or null when uncolored */
    public function gradient(): ?array
    {
        return $this->gradientMemo;
    }

    public function underlayColor(): ?Color
    {
        return $this->underlayColor;
    }

    /**
     * Build btop's frame for the retained samples.
     *
     * Ported line-for-line from Graph::Graph + Graph::_create, including
     * both alternating buffers: the buffer not shown holds the pairing
     * shifted by one sample, and the no_zero exemption for the very first
     * left sample lands there in the braille/block families (visible only
     * in tty, which has a single buffer).
     *
     * @return array{rows: list<list<array{glyph:string, transparent:bool, color:?int}>>, rowColors: list<int>}
     */
    private function frame(): array
    {
        $data = $this->samples;
        $n = count($data);
        $tty = $this->family === self::FAMILY_TTY;
        $height = $this->height;
        $width = $this->width;

        $valueWidth = $tty ? $n : (int) ceil($n / 2);
        $dataOffset = $valueWidth > $width ? $n - $width * ($tty ? 1 : 2) : 0;
        if (!$tty && ($n - $dataOffset) % 2 !== 0) {
            $dataOffset--;
        }

        $current = true;
        $pad = $valueWidth < $width ? $width - $valueWidth : 0;
        // Height-1 padding is btop's Mv::r(1) (transparent); taller graphs
        // pad with opaque spaces.
        $padCell = ['glyph' => ' ', 'transparent' => $height === 1, 'color' => null];
        $graphs = [0 => [], 1 => []];
        for ($h = 0; $h < $height; $h++) {
            $graphs[1][$h] = array_fill(0, $pad, $padCell);
            $graphs[0][$h] = $graphs[1][$h];
        }

        if ($n > 0) {
            $table = self::SYMBOLS[$this->family . '_' . ($this->invert ? 'down' : 'up')];
            $mult = ($n - $dataOffset) > 1;
            [$clampMax, $mod] = $this->quantizer();
            $last = 0;
            $dataValue = 0;
            if ($mult && $dataOffset > 0) {
                $last = $this->scale($data[$dataOffset - 1]);
            }

            for ($i = $dataOffset; $i < $n; $i++) {
                if (!$tty && $mult) {
                    $current = !$current;
                }
                if ($i < 0) {
                    $dataValue = 0;
                    $last = 0;
                } else {
                    $dataValue = $this->scale($data[$i]);
                }

                for ($horizon = 0; $horizon < $height; $horizon++) {
                    $curHigh = $height > 1 ? (int) round(100.0 * ($height - $horizon) / $height) : 100;
                    $curLow = $height > 1 ? (int) round(100.0 * ($height - ($horizon + 1)) / $height) : 0;
                    $result = [];
                    foreach ([$last, $dataValue] as $ai => $value) {
                        $clampMin = ($this->noZero && $horizon === $height - 1
                            && !($mult && $i === $dataOffset && $ai === 0)) ? 1 : 0;
                        if ($value >= $curHigh) {
                            $result[$ai] = $clampMax;
                        } elseif ($value <= $curLow) {
                            $result[$ai] = $clampMin;
                        } else {
                            $q = self::f32(self::f32(($value - $curLow) * $clampMax) / ($curHigh - $curLow));
                            $band = (int) round(self::f32($q + $mod));
                            $result[$ai] = max($clampMin, min($clampMax, $band));
                        }
                    }

                    $key = (int) $current;
                    if ($height === 1 && $result[0] + $result[1] === 0) {
                        $graphs[$key][$horizon][] = ['glyph' => ' ', 'transparent' => true, 'color' => null];
                        continue;
                    }
                    $color = ($height === 1 && $this->gradientMemo !== null)
                        ? max(0, min(100, max($last, $dataValue)))
                        : null;
                    $graphs[$key][$horizon][] = [
                        'glyph' => $table[$result[0] * 5 + $result[1]],
                        'transparent' => false,
                        'color' => $color,
                    ];
                }
                if ($mult && $i >= 0) {
                    $last = $dataValue;
                }
            }
        }

        $shown = $graphs[(int) $current];
        if ($height === 1) {
            return ['rows' => [$shown[0]], 'rowColors' => []];
        }
        $rows = [];
        $rowColors = [];
        for ($i = 1; $i <= $height; $i++) {
            $rowColors[] = $this->invert ? intdiv($i * 100, $height) : 100 - intdiv(($i - 1) * 100, $height);
            $rows[] = $this->invert ? $shown[$height - $i] : $shown[$i - 1];
        }
        return ['rows' => $rows, 'rowColors' => $rowColors];
    }

    /**
     * @param list<array{glyph:string, transparent:bool, color:?int}> $cells
     */
    private function renderSingleRow(array $cells, ColorProfile $profile, bool $ansi): string
    {
        $underlayGlyph = $this->underlayGlyph();
        $underlaySgr = ($ansi && $this->underlayColor !== null) ? $this->underlayColor->toFg($profile) : '';
        $out = '';
        // SGR currently in effect, so an uncolored glyph after an underlay
        // run is reset back to the terminal default instead of inheriting
        // inactive_fg.
        $active = '';
        foreach ($cells as $cell) {
            if ($cell['transparent']) {
                if ($this->underlayColor === null) {
                    $out .= ' ';
                    continue;
                }
                if ($underlaySgr !== '' && $active !== $underlaySgr) {
                    $out .= $underlaySgr;
                    $active = $underlaySgr;
                }
                $out .= $underlayGlyph;
                continue;
            }
            if ($ansi && $cell['color'] !== null && $this->gradientMemo !== null) {
                // btop re-emits the color on every drawn cell; kept so the
                // byte stream matches cell-for-cell.
                $active = $this->gradientMemo[$cell['color']]->toFg($profile);
                $out .= $active;
            } elseif ($active !== '') {
                $out .= Ansi::reset();
                $active = '';
            }
            $out .= $cell['glyph'];
        }
        if ($ansi && ($active !== '' || $this->gradientMemo !== null)) {
            $out .= Ansi::reset();
        }
        return $out;
    }

    /**
     * btop's per-family band ceiling and rounding bias (PR #1783 `_create`):
     * block2 has four bands (0..3) per half-cell and biases rounding up
     * harder (0.6 at height 1, 0.2 taller) to offset its coarser bands'
     * pull toward 0; every other family keeps 4 and 0.3 / 0.1.
     *
     * @return array{int, float} [clamp_max, mod]; mod is a C++ `float`, so
     *   the sum it joins is single precision
     */
    private function quantizer(): array
    {
        if ($this->family === self::FAMILY_BLOCK2) {
            return [3, self::f32($this->height === 1 ? 0.6 : 0.2)];
        }
        return [4, self::f32($this->height === 1 ? 0.3 : 0.1)];
    }

    /** btop's percent law: clamp((v + offset) * 100 / max_value, 0, 100), truncating. */
    private function scale(int $value): int
    {
        if ($this->maxValue <= 0) {
            return $value;
        }
        return max(0, min(100, intdiv(($value + $this->offset) * 100, $this->maxValue)));
    }

    /**
     * Bound memory, not the view: frame() windows the history itself, so
     * keep enough for the widest width seen (2 samples/cell + predecessor)
     * or the HISTORY_CELLS floor, whichever is larger.
     *
     * @param list<int> $samples
     * @return list<int>
     */
    private function trim(array $samples): array
    {
        $keep = 2 * max($this->widthSeen, self::HISTORY_CELLS) + 1;
        return count($samples) > $keep ? array_slice($samples, -$keep) : $samples;
    }

    /**
     * btop's data is `long long`; its callers round before pushing.
     * Out-of-range values saturate at ±SAMPLE_LIMIT (see the constant).
     *
     * @param array<int|float> $values
     * @return list<int>
     */
    private static function ingest(array $values): array
    {
        $out = [];
        foreach ($values as $v) {
            if (is_float($v)) {
                if (!is_finite($v)) {
                    throw new \InvalidArgumentException('DualSampleGraph samples must be finite');
                }
                // Clamp before the cast: a float beyond the int range would
                // otherwise wrap/truncate unpredictably.
                $v = (int) round(max(-self::SAMPLE_LIMIT, min(self::SAMPLE_LIMIT, $v)));
            }
            $out[] = max(-self::SAMPLE_LIMIT, min(self::SAMPLE_LIMIT, $v));
        }
        return $out;
    }

    /** Round a double to IEEE-754 single precision, as C++ `float` math does. */
    private static function f32(float $v): float
    {
        return unpack('g', pack('g', $v))[1];
    }

    private static function assertFamily(string $family): void
    {
        if (!in_array($family, self::FAMILIES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown DualSampleGraph family "%s" (expected braille, block, block2 or tty)',
                $family,
            ));
        }
    }

    /**
     * @param array<string, mixed> $props
     */
    private function mutate(array $props): self
    {
        $clone = clone $this;
        foreach ($props as $name => $value) {
            $clone->{$name} = $value;
        }
        return $clone;
    }
}
