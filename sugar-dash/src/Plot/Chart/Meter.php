<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot\Chart;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Dash\Plot\Gradient101;

/**
 * An analog-style meter/gauge component.
 *
 * Displays a ratio as a vertical meter with a needle indicator,
 * similar to VU meters or analog voltmeters. The meter shows
 * a vertical scale with tick marks and a needle pointing to
 * the current value.
 *
 * Mirrors analog meter concepts adapted to PHP with
 * wither-style immutable setters.
 *
 * Position-gradient mode ({@see withGradient()} with `positionWise: true`)
 * swaps the analog face for btop's one-row percent bar: every glyph cell is
 * colored at ITS OWN horizontal position through a 101-stop ramp, not at the
 * bar's value — so a 50% bar on a green→red ramp is green-to-yellow, never
 * all yellow. Mirrors aristocratos/btop Draw::Meter::operator().
 */
final class Meter implements \SugarCraft\Dash\Foundation\SizedItem
{
    private ?int $sizerWidth = null;
    private ?int $sizerHeight = null;

    /**
     * Meter scale characters.
     */
    private const SCALE_CHARS = [
        0 => '۝', // High mark
        1 => '｜', // Major tick
        2 => '·', // Minor tick
    ];

    /**
     * Needle characters (pointing left, centered on position).
     */
    private const NEEDLE = '❮';

    /**
     * Rendered position-mode bars, keyed by {@see cacheKey()} then by value
     * 0-100 — btop's per-Meter `std::array<string,101>` cache. Static rather
     * than per-instance because withers and frame rebuilds discard instances
     * every tick; keying on everything that shapes the bytes keeps sharing
     * safe across them.
     *
     * @var array<string, array<int, string>>
     */
    private static array $memo = [];

    /** Bounds the memo when widths churn (resizes mint fresh keys). */
    private const MEMO_KEY_LIMIT = 64;

    /**
     * Byte ceiling across all memoized bars. A full row is ~101 values ×
     * width × ~20 SGR bytes per cell, so a few very wide bars could dwarf
     * the key cap alone; the oldest shapes go first once this is crossed.
     */
    private const MEMO_BYTE_LIMIT = 4 * 1024 * 1024;

    private static int $memoBytes = 0;

    private static int $memoHits = 0;
    private static int $memoMisses = 0;

    /** @var list<Color> ordered low→high stops as given, for {@see cacheKey()} */
    private array $gradientStops = [];

    /** @var list<Color>|null 101-entry ramp (index = percent), null when unset */
    private ?array $gradientMemo = null;

    private bool $positionWise = false;
    private string $glyph = '■';
    private bool $invert = false;
    private ?int $requestedWidth = null;
    private ?Color $meterBg = null;

    /** Ratio in [0.0, 1.0] — enforced by the constructor, never re-checked downstream. */
    private readonly float $ratio;

    public function __construct(
        float $ratio,
        private readonly int $meterHeight = 12,
        private readonly int $meterWidth = 5,
        private readonly bool $showNeedle = true,
        private readonly bool $showScale = true,
        private readonly bool $showLabel = true,
        private readonly ?Color $meterColor = null,
        private readonly ?Color $needleColor = null,
        private readonly ?Color $scaleColor = null,
    ) {
        // E733 (round 83) — the single clamp boundary, mirroring Gauge's E726
        // fold. Every ratio enters through this constructor (new() factory,
        // withRatio() wither, direct construction alike), so [0.0, 1.0] is
        // parsed into trusted state exactly once here; render() consumes it raw.
        // The parameter stays `float $ratio` in slot 1: the 9-positional
        // `new Meter(...)` test call-sites (MeterTest :100/:109) and any
        // named-argument callers remain byte-compatible through de-promotion.
        $this->ratio = max(0.0, min(1.0, $ratio));
    }

    /**
     * Create a new analog meter with default styling.
     *
     * Default: purple meter, red needle, 12 rows tall, 5 chars wide.
     */
    public static function new(float $ratio): self
    {
        return new self(
            ratio: $ratio,
            meterHeight: 12,
            meterWidth: 5,
            showNeedle: true,
            showScale: true,
            showLabel: true,
            meterColor: Color::hex('#874BFD'),
            needleColor: Color::hex('#FF6B6B'),
            scaleColor: Color::hex('#888888'),
        );
    }

    /**
     * Set the allocated dimensions for this meter.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->sizerWidth = $width;
        $clone->sizerHeight = $height;
        return $clone;
    }

    /**
     * Render the analog meter.
     */
    public function render(): string
    {
        if ($this->positionWise && $this->gradientMemo !== null) {
            return $this->renderPositionBar();
        }

        // Ratio was parsed into trusted [0.0, 1.0] state by the constructor
        // (the single clamp boundary) — render reads it raw.
        $ratio = $this->ratio;
        $meterHeight = $this->meterHeight;
        $meterWidth = $this->meterWidth;

        // Ensure minimum dimensions
        $meterHeight = max(5, $meterHeight);
        $meterWidth = max(3, $meterWidth);

        // A value-wise gradient (positionWise=false) colors the active body
        // by the bar's value, like Threshold/Gauge; without one, today's
        // flat meterColor stays byte-identical.
        $bodyColor = $this->gradientMemo !== null
            ? $this->gradientMemo[(int) round($ratio * 100)]
            : $this->meterColor;

        $result = '';
        $needleY = (int) round((1.0 - $ratio) * ($meterHeight - 1));
        $needleY = max(1, min($meterHeight - 2, $needleY));

        for ($y = 0; $y < $meterHeight; $y++) {
            $row = '';

            // Scale column (left side)
            if ($this->showScale) {
                $scaleRatio = 1.0 - ($y / ($meterHeight - 1));
                $isMajorTick = ($y % 3 === 0);
                $tickChar = $isMajorTick ? '』' : '·';

                if ($this->scaleColor !== null) {
                    $row .= $this->scaleColor->toFg(ColorProfile::TrueColor);
                }
                $row .= $tickChar;
                if ($this->scaleColor !== null) {
                    $row .= Ansi::reset();
                }
            }

            // Meter body column
            $isActive = ($y <= $needleY);
            $bodyChar = ($y === 0 || $y === $meterHeight - 1) ? '─' : '│';

            if ($isActive && $bodyColor !== null) {
                $row .= $bodyColor->toFg(ColorProfile::TrueColor);
                $row .= '█';
                $row .= Ansi::reset();
            } elseif ($this->meterColor !== null) {
                $row .= $this->meterColor->toFg(ColorProfile::TrueColor);
                $row .= '░';
                $row .= Ansi::reset();
            } else {
                $row .= $isActive ? '█' : '░';
            }

            // Needle column
            if ($this->showNeedle && $y === $needleY) {
                if ($this->needleColor !== null) {
                    $row .= $this->needleColor->toFg(ColorProfile::TrueColor);
                }
                $row .= self::NEEDLE;
                if ($this->needleColor !== null) {
                    $row .= Ansi::reset();
                }
            } else {
                $row .= ' ';
            }

            // Right side indicator column
            if ($y === $needleY) {
                // Value indicator
                if ($this->needleColor !== null) {
                    $row .= $this->needleColor->toFg(ColorProfile::TrueColor);
                }
                $row .= '●';
                if ($this->needleColor !== null) {
                    $row .= Ansi::reset();
                }
            } else {
                $row .= ' ';
            }

            $result .= $row . "\n";
        }

        // Add percentage label at bottom
        if ($this->showLabel) {
            $percentage = (int) round($ratio * 100);
            $label = sprintf(' %d%% ', $percentage);

            // Center the label under the meter
            $labelWidth = mb_strlen($label, 'UTF-8');
            $padding = max(0, (int) floor(($meterWidth - $labelWidth + 2) / 2));
            $label = str_repeat(' ', $padding) . $label;

            if ($this->needleColor !== null) {
                $label = $this->needleColor->toFg(ColorProfile::TrueColor) . $label . Ansi::reset();
            }
            $result .= $label;
        }

        return rtrim($result, "\n");
    }

    /**
     * Calculate the natural dimensions of this meter.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        if ($this->positionWise && $this->gradientMemo !== null) {
            return [$this->barWidth(), 1];
        }
        $labelHeight = $this->showLabel ? 1 : 0;
        return [$this->meterWidth, $this->meterHeight + $labelHeight];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the meter height.
     */
    public function withHeight(int $height): self
    {
        return $this->mutate(['meterHeight' => max(5, $height)]);
    }

    /**
     * Set the meter width. The analog face keeps its 3-column minimum; the
     * position-mode bar honours the request down to 1 cell, since btop sizes
     * meters to whatever columns remain (a 1-2 cell bar is legal there).
     */
    public function withWidth(int $width): self
    {
        return $this->mutate(['meterWidth' => max(3, $width)], ['requestedWidth' => max(1, $width)]);
    }

    /**
     * Show or hide the needle.
     */
    public function withShowNeedle(bool $show): self
    {
        return $this->mutate(['showNeedle' => $show]);
    }

    /**
     * Show or hide the scale marks.
     */
    public function withShowScale(bool $show): self
    {
        return $this->mutate(['showScale' => $show]);
    }

    /**
     * Show or hide the percentage label.
     */
    public function withShowLabel(bool $show): self
    {
        return $this->mutate(['showLabel' => $show]);
    }

    /**
     * Set the ratio value.
     */
    public function withRatio(float $ratio): self
    {
        // The constructor clamps — no re-clamp here (E733 single boundary).
        return $this->mutate(['ratio' => $ratio]);
    }

    /**
     * Set the meter body color.
     */
    public function withMeterColor(?Color $color): self
    {
        return $this->mutate(['meterColor' => $color]);
    }

    /**
     * Set the needle color.
     */
    public function withNeedleColor(?Color $color): self
    {
        return $this->mutate(['needleColor' => $color]);
    }

    /**
     * Set the scale color.
     */
    public function withScaleColor(?Color $color): self
    {
        return $this->mutate(['scaleColor' => $color]);
    }

    /**
     * Attach a value→color ramp, expanded once into btop's 101-entry memo
     * by {@see Gradient101::expand()} (truncating integer law).
     *
     * `$positionWise = false` colors the analog body by the meter's value.
     * `$positionWise = true` renders btop's one-row `■` bar instead: cell
     * `i` (1-based) sits at `y = round(i * 100 / width)`, is filled while
     * `value >= y` and then takes ramp color `y` (`100 - y` when inverted);
     * the first unfilled cell paints the whole tail in meter_bg. Width is
     * the allocated {@see setSize()} width when laid out, else
     * {@see withWidth()}.
     *
     * Mirrors aristocratos/btop Draw::Meter::operator().
     *
     * @param list<Color> $stops ordered low→high, at least 2
     * @throws \InvalidArgumentException on fewer than 2 stops or a non-Color stop
     */
    public function withGradient(array $stops, bool $positionWise = false): self
    {
        $memo = Gradient101::expand($stops);

        return $this->mutate([], [
            'gradientStops' => array_values($stops),
            'gradientMemo' => $memo,
            'positionWise' => $positionWise,
        ]);
    }

    /** Detach the ramp; the meter returns to its flat analog face. */
    public function withoutGradient(): self
    {
        return $this->mutate([], ['gradientStops' => [], 'gradientMemo' => null, 'positionWise' => false]);
    }

    /**
     * Glyph for every position-mode bar cell (filled and tail alike, as in
     * btop where both are `Symbols::meter`). Defaults to `■` (U+25A0).
     *
     * @throws \InvalidArgumentException on an empty glyph
     */
    public function withGlyph(string $glyph = '■'): self
    {
        if ($glyph === '') {
            throw new \InvalidArgumentException('Meter glyph must not be empty');
        }

        return $this->mutate([], ['glyph' => $glyph]);
    }

    /**
     * Flip the ramp direction in position mode (btop's `invert`): the
     * leftmost cell takes the ramp's high end — e.g. a discharging battery.
     */
    public function withInvert(bool $invert = true): self
    {
        return $this->mutate([], ['invert' => $invert]);
    }

    /**
     * Color of the unfilled tail in position mode. Null restores btop's
     * default theme `meter_bg` (`#40` → #404040).
     */
    public function withMeterBg(?Color $color): self
    {
        return $this->mutate([], ['meterBg' => $color]);
    }

    /** @return list<Color>|null the 101-entry ramp (index = percent), null when unset */
    public function gradient(): ?array
    {
        return $this->gradientMemo;
    }

    public function positionWise(): bool
    {
        return $this->positionWise;
    }

    public function glyph(): string
    {
        return $this->glyph;
    }

    public function invert(): bool
    {
        return $this->invert;
    }

    public function meterBg(): Color
    {
        return $this->meterBg ?? self::defaultMeterBg();
    }

    /**
     * Memo key for one position-mode bar shape. Two meters with equal keys
     * render byte-identical strings for every value, so they share one
     * 101-slot memo row.
     *
     * @param list<Color> $stops
     */
    public static function cacheKey(
        array $stops,
        int $width,
        bool $invert,
        string $glyph = '■',
        ?Color $meterBg = null,
    ): string {
        $hex = array_map(
            static fn (mixed $c): string => $c instanceof Color ? $c->toHex() : get_debug_type($c),
            array_values($stops),
        );

        return implode(',', $hex)
            . '|' . $width
            . '|' . ($invert ? 'i' : 'n')
            . '|' . ($meterBg ?? self::defaultMeterBg())->toHex()
            . '|' . $glyph;
    }

    /**
     * Memo diagnostics: distinct bar shapes held, rendered rows across them,
     * and lookup hits/misses since the last {@see resetMemo()}.
     *
     * @return array{keys:int, entries:int, bytes:int, hits:int, misses:int}
     */
    public static function memoStats(): array
    {
        return [
            'keys' => count(self::$memo),
            'entries' => array_sum(array_map('count', self::$memo)),
            'bytes' => self::$memoBytes,
            'hits' => self::$memoHits,
            'misses' => self::$memoMisses,
        ];
    }

    /** Drop every memoized bar and zero the hit/miss counters. */
    public static function resetMemo(): void
    {
        self::$memo = [];
        self::$memoBytes = 0;
        self::$memoHits = 0;
        self::$memoMisses = 0;
    }

    private function barWidth(): int
    {
        return $this->sizerWidth ?? $this->requestedWidth ?? $this->meterWidth;
    }

    private function renderPositionBar(): string
    {
        $width = $this->barWidth();
        if ($width < 1 || $this->gradientMemo === null) {
            return '';
        }
        $value = (int) round($this->ratio * 100);
        $key = self::cacheKey($this->gradientStops, $width, $this->invert, $this->glyph, $this->meterBg);

        if (isset(self::$memo[$key][$value])) {
            self::$memoHits++;
            self::touch($key);
            return self::$memo[$key][$value];
        }
        self::$memoMisses++;

        $out = '';
        for ($i = 1; $i <= $width; $i++) {
            // PHP round() is half-away-from-zero, same as C++ std::round.
            $y = (int) round($i * 100 / $width);
            if ($value >= $y) {
                $out .= $this->gradientMemo[$this->invert ? 100 - $y : $y]->toFg(ColorProfile::TrueColor)
                    . $this->glyph;
            } else {
                $out .= $this->meterBg()->toFg(ColorProfile::TrueColor)
                    . str_repeat($this->glyph, $width + 1 - $i);
                break;
            }
        }
        $out .= Ansi::reset();

        self::touch($key);
        self::$memo[$key][$value] = $out;
        self::$memoBytes += strlen($out);
        self::evict($key);

        return $out;
    }

    /**
     * Move `$key` to the young end of the memo (insertion order doubles as
     * recency), so eviction drops the least recently used shape.
     */
    private static function touch(string $key): void
    {
        if (!isset(self::$memo[$key])) {
            self::$memo[$key] = [];
            return;
        }
        $row = self::$memo[$key];
        unset(self::$memo[$key]);
        self::$memo[$key] = $row;
    }

    /** Evict oldest shapes past either cap, never the one just written. */
    private static function evict(string $keep): void
    {
        while (
            count(self::$memo) > 1
            && (count(self::$memo) > self::MEMO_KEY_LIMIT || self::$memoBytes > self::MEMO_BYTE_LIMIT)
        ) {
            $oldest = array_key_first(self::$memo);
            if ($oldest === $keep) {
                break;
            }
            self::$memoBytes -= array_sum(array_map('strlen', self::$memo[$oldest]));
            unset(self::$memo[$oldest]);
        }
    }

    private static function defaultMeterBg(): Color
    {
        return Color::hex('#404040');
    }

    /**
     * Rebuild through the constructor (the single ratio clamp) with `$args`
     * overriding its parameters, then carry the non-constructor state —
     * gradient, glyph, polarity, tail color and any allocated size — so no
     * wither silently drops another's setting.
     *
     * @param array<string, mixed> $args constructor overrides
     * @param array<string, mixed> $extra non-constructor property overrides
     */
    private function mutate(array $args, array $extra = []): self
    {
        $clone = new self(...array_merge([
            'ratio' => $this->ratio,
            'meterHeight' => $this->meterHeight,
            'meterWidth' => $this->meterWidth,
            'showNeedle' => $this->showNeedle,
            'showScale' => $this->showScale,
            'showLabel' => $this->showLabel,
            'meterColor' => $this->meterColor,
            'needleColor' => $this->needleColor,
            'scaleColor' => $this->scaleColor,
        ], $args));

        foreach ([
            'gradientStops', 'gradientMemo', 'positionWise', 'glyph',
            'invert', 'meterBg', 'requestedWidth', 'sizerWidth', 'sizerHeight',
        ] as $prop) {
            $clone->{$prop} = array_key_exists($prop, $extra) ? $extra[$prop] : $this->{$prop};
        }

        return $clone;
    }
}
