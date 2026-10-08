<?php

declare(strict_types=1);

namespace SugarCraft\Top\Theme;

use SugarCraft\Core\Util\Color;
use SugarCraft\Dash\Foundation\GradientStore;

/**
 * A resolved truecolor btop theme: the 48 semantic keys (21 singles + 9
 * gradient families × start/mid/end) and the 101-stop gradients built from
 * them, plus btop's two derived pseudo-gradients `proc` (main_fg →
 * inactive_fg) and `proc_color` (inactive_fg → process_start).
 *
 * Resolution reproduces btop's generateColors key by key, including its
 * asymmetries — they decide what a sparse user theme looks like:
 * - a key absent from the source takes the Default value, EXCEPT the
 *   optional `meter_bg`/`graph_text` (→ inactive_fg) and `process_*`
 *   (→ the cpu_* triple, only when process_start is absent);
 * - an empty `main_bg` is the terminal default background (null), and an
 *   empty `*_mid`/`*_end` is an unset stop (null) — an empty value anywhere
 *   else counts as absent and falls back;
 * - a `#`-prefixed value that is not valid hex becomes null (no fallback),
 *   while a malformed `R G B` decimal falls back to Default;
 * - a theme defining process_start but omitting process_mid/process_end
 *   gets BLACK for the missing stop, because btop's gradient pass reads them
 *   through `std::unordered_map::operator[]`, which value-initialises to
 *   {0,0,0}. Kept deliberately: it is what such a theme looks like in btop.
 *
 * - an out-of-range `R G B` decimal is clamped in {@see color()}, but the
 *   gradient pass sees the raw channels exactly as btop's `rgbs` does (a
 *   300 start bends the whole ramp; a negative end red marks the end unset).
 *
 * Gradients are byte-identical to btop's for Default, all 41 shipped themes
 * and the edge cases pinned in tests/fixtures/btop-theme-oracle.json
 * (generated from btop's own code by prompt_kit/tools/btop-theme-oracle.cpp).
 * Only deviation: a non-numeric decimal falls back to Default instead of
 * aborting the process (btop's stoi throws).
 *
 * Default/TTY values and the resolution law are ported from btop
 * (Copyright 2021 Aristocratos, Apache-2.0; see candy-top/themes/LICENSE).
 *
 * Mirrors aristocratos/btop Theme::Default_theme / generateColors /
 * generateGradients (src/btop_theme.cpp).
 */
final class ThemeConfig implements Palette
{
    /**
     * btop's Default_theme, verbatim (src/btop_theme.cpp). `#GG` is a gray
     * level; the rest are `#RRGGBB`.
     */
    public const DEFAULT_THEME = [
        'main_bg' => '#00',
        'main_fg' => '#cc',
        'title' => '#ee',
        'hi_fg' => '#b54040',
        'selected_bg' => '#6a2f2f',
        'selected_fg' => '#ee',
        'inactive_fg' => '#40',
        'graph_text' => '#60',
        'meter_bg' => '#40',
        'proc_misc' => '#0de756',
        'cpu_box' => '#556d59',
        'mem_box' => '#6c6c4b',
        'net_box' => '#5c588d',
        'proc_box' => '#805252',
        'div_line' => '#30',
        'temp_start' => '#4897d4',
        'temp_mid' => '#5474e8',
        'temp_end' => '#ff40b6',
        'cpu_start' => '#77ca9b',
        'cpu_mid' => '#cbc06c',
        'cpu_end' => '#dc4c4c',
        'free_start' => '#384f21',
        'free_mid' => '#b5e685',
        'free_end' => '#dcff85',
        'cached_start' => '#163350',
        'cached_mid' => '#74e6fc',
        'cached_end' => '#26c5ff',
        'available_start' => '#4e3f0e',
        'available_mid' => '#ffd77a',
        'available_end' => '#ffb814',
        'used_start' => '#592b26',
        'used_mid' => '#d9626d',
        'used_end' => '#ff4769',
        'download_start' => '#291f75',
        'download_mid' => '#4f43a3',
        'download_end' => '#b0a9de',
        'upload_start' => '#620665',
        'upload_mid' => '#7d4180',
        'upload_end' => '#dcafde',
        'process_start' => '#80d0a3',
        'process_mid' => '#dcd179',
        'process_end' => '#d45454',
        'proc_pause_bg' => '#b54040',
        'proc_follow_bg' => '#4040b5',
        'proc_banner_bg' => '#7b407b',
        'proc_banner_fg' => '#ee',
        'followed_bg' => '#4040b5',
        'followed_fg' => '#ee',
    ];

    /** The 9 families a `.theme` file can recolor (it cannot add new ones). */
    public const FAMILIES = [
        'temp', 'cpu', 'free', 'cached', 'available', 'used', 'download', 'upload', 'process',
    ];

    /** Derived pseudo-gradients injected after the families, in btop's insert order. */
    public const DERIVED = ['proc', 'proc_color'];

    /** Keys btop never back-fills from Default; they get the dedicated fallbacks instead. */
    private const OPTIONAL = ['meter_bg', 'process_start', 'process_mid', 'process_end', 'graph_text'];

    /** btop's rgbs sentinel for "no color". */
    private const UNSET = [-1, -1, -1];

    /** @var array<string, ?Color> */
    private readonly array $colors;

    private readonly GradientStore $gradients;

    /**
     * Gradients btop builds outside the plain start/mid/end law — blank (all
     * null) ones, and ramps over out-of-range raw channels — so they cannot
     * live in the {@see GradientStore}.
     *
     * @var array<string, list<?Color>>
     */
    private readonly array $fixed;

    /**
     * @param array<string, string> $source raw `.theme` values
     */
    private function __construct(
        private readonly string $name,
        private readonly array $source,
        private readonly bool $themeBackground,
    ) {
        [$this->colors, $raw] = self::resolve($source, $themeBackground);
        [$this->gradients, $this->fixed] = self::buildGradients($this->colors, $raw);
    }

    /** btop's builtin "Default" theme. */
    public static function new(): self
    {
        return new self('Default', self::DEFAULT_THEME, true);
    }

    /**
     * Resolve a raw key → value map (as {@see ThemeFile::parse()} yields).
     * Unknown keys are ignored.
     *
     * @param array<string, string> $source
     */
    public static function fromSource(array $source, string $name = 'custom'): self
    {
        return new self($name, array_intersect_key($source, self::DEFAULT_THEME), true);
    }

    /** Parse and resolve `.theme` text. */
    public static function fromString(string $contents, string $name = 'custom'): self
    {
        return self::fromSource(ThemeFile::parse($contents), $name);
    }

    /**
     * Load a `.theme` file; the name defaults to the file stem, as btop's
     * options menu shows it.
     *
     * @throws \InvalidArgumentException when the file cannot be read
     */
    public static function fromFile(string $path, ?string $name = null): self
    {
        return self::fromSource(ThemeFile::load($path), $name ?? pathinfo($path, PATHINFO_FILENAME));
    }

    /**
     * btop's `theme_background` option: false forces main_bg to the
     * terminal default (null) whatever the theme says.
     */
    public function withThemeBackground(bool $on): self
    {
        return $this->mutate(themeBackground: $on);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function themeBackground(): bool
    {
        return $this->themeBackground;
    }

    /** @return array<string, string> the raw source values this theme was resolved from */
    public function source(): array
    {
        return $this->source;
    }

    public function color(string $key): ?Color
    {
        if (!array_key_exists($key, self::DEFAULT_THEME)) {
            throw new \OutOfBoundsException(sprintf('Unknown theme key "%s"', $key));
        }
        return $this->colors[$key] ?? null;
    }

    /** @return array<string, ?Color> every semantic key, in Default order */
    public function colors(): array
    {
        $out = [];
        foreach (self::DEFAULT_THEME as $key => $_) {
            $out[$key] = $this->colors[$key] ?? null;
        }
        return $out;
    }

    public function at(string $name, int $percent): ?Color
    {
        if (isset($this->fixed[$name])) {
            return $this->fixed[$name][max(0, min(100, $percent))];
        }
        return $this->gradients->at($name, $percent);
    }

    /**
     * The full 101-entry ramp; entries are null only for a blank gradient
     * (btop's empty escapes).
     *
     * @return list<?Color>
     * @throws \OutOfBoundsException when no gradient `$name` exists
     */
    public function gradient(string $name): array
    {
        if (isset($this->fixed[$name])) {
            return $this->fixed[$name];
        }
        return $this->gradients->gradient($name);
    }

    public function hasGradient(string $name): bool
    {
        return isset($this->fixed[$name]) || $this->gradients->has($name);
    }

    public function gradientNames(): array
    {
        return array_values(array_filter(
            [...self::FAMILIES, ...self::DERIVED],
            fn (string $n): bool => $this->hasGradient($n),
        ));
    }

    /**
     * The dash store holding every gradient expressible as start/mid/end
     * stops (shareable with dash widgets) — i.e. all of them except blank
     * ones and ramps over out-of-range decimal channels.
     */
    public function gradients(): GradientStore
    {
        return $this->gradients;
    }

    /**
     * Whether `$key` is painted as a background. btop's rule: names ending
     * in `bg`, except meter_bg (the unfilled meter tail is drawn as glyphs).
     */
    public static function isBackground(string $key): bool
    {
        return str_ends_with($key, 'bg') && $key !== 'meter_bg';
    }

    /**
     * Parse one btop color value: `#RRGGBB`, `#GG` gray, or `R G B` decimal.
     * Null when malformed.
     *
     * Mirrors aristocratos/btop Theme::hex_to_dec + the decimal branch of generateColors.
     */
    public static function parseColor(string $value): ?Color
    {
        if (str_starts_with($value, '#')) {
            return self::parseHex($value);
        }
        $raw = self::parseDecimal($value);
        return $raw === null ? null : self::clamped($raw);
    }

    private function mutate(?bool $themeBackground = null): self
    {
        return new self($this->name, $this->source, $themeBackground ?? $this->themeBackground);
    }

    private static function parseHex(string $value): ?Color
    {
        $hex = substr($value, 1);
        if ($hex === '' || !ctype_xdigit($hex)) {
            return null;
        }
        if (strlen($hex) === 2) {
            $g = (int) hexdec($hex);
            return Color::rgb($g, $g, $g);
        }
        if (strlen($hex) === 6) {
            return Color::hex($hex);
        }
        return null;
    }

    /** @return ?array{int, int, int} raw (unclamped) channels; null when malformed */
    private static function parseDecimal(string $value): ?array
    {
        // btop's ssplit drops empty fields, so runs of spaces are one separator.
        $parts = array_values(array_filter(explode(' ', $value), static fn (string $p): bool => $p !== ''));
        if (count($parts) !== 3) {
            return null;
        }
        $rgb = [];
        foreach ($parts as $part) {
            // stoi semantics: leading integer, trailing junk ignored.
            if (preg_match('/^\s*([+-]?\d+)/', $part, $m) !== 1) {
                return null;
            }
            $rgb[] = (int) $m[1];
        }
        return $rgb;
    }

    /** @param array{int, int, int} $rgb */
    private static function clamped(array $rgb): Color
    {
        return Color::rgb(max(0, min(255, $rgb[0])), max(0, min(255, $rgb[1])), max(0, min(255, $rgb[2])));
    }

    /** @return array{int, int, int} */
    private static function rawOf(?Color $c): array
    {
        return $c === null ? self::UNSET : [$c->r, $c->g, $c->b];
    }

    /**
     * @param array<string, string> $source
     * @return array{array<string, ?Color>, array<string, array{int, int, int}>}
     *         colors (absent key = never set; only process_mid/end can be) and
     *         btop's raw `rgbs` channels for the same keys
     */
    private static function resolve(array $source, bool $themeBackground): array
    {
        $colors = [];
        $raw = [];
        foreach (self::DEFAULT_THEME as $name => $default) {
            if ($name === 'main_bg' && !$themeBackground) {
                $colors[$name] = null;
                $raw[$name] = self::UNSET;
                continue;
            }
            if (array_key_exists($name, $source)) {
                $value = $source[$name];
                if ($value === '' && ($name === 'main_bg' || str_ends_with($name, '_mid') || str_ends_with($name, '_end'))) {
                    $colors[$name] = null;
                    $raw[$name] = self::UNSET;
                    continue;
                }
                if (str_starts_with($value, '#')) {
                    $colors[$name] = self::parseHex($value);
                    $raw[$name] = self::rawOf($colors[$name]);
                } elseif ($value !== '') {
                    $parsed = self::parseDecimal($value);
                    if ($parsed !== null) {
                        $colors[$name] = self::clamped($parsed);
                        $raw[$name] = $parsed;
                    }
                }
            }
            if (!array_key_exists($name, $colors) && !in_array($name, self::OPTIONAL, true)) {
                $colors[$name] = self::parseHex($default);
                $raw[$name] = self::rawOf($colors[$name]);
            }
        }
        $inherit = static function (string $to, string $from) use (&$colors, &$raw): void {
            $colors[$to] = $colors[$from];
            $raw[$to] = $raw[$from];
        };
        if (!array_key_exists('meter_bg', $colors)) {
            $inherit('meter_bg', 'inactive_fg');
        }
        if (!array_key_exists('process_start', $colors)) {
            $inherit('process_start', 'cpu_start');
            $inherit('process_mid', 'cpu_mid');
            $inherit('process_end', 'cpu_end');
        }
        if (!array_key_exists('graph_text', $colors)) {
            $inherit('graph_text', 'inactive_fg');
        }
        return [$colors, $raw];
    }

    /**
     * btop's generateGradients over the raw channels: a gradient is computed
     * only when its end red is >= 0 and the result's first red is not -1;
     * otherwise every entry is the start's escape (empty for the derived
     * pseudo-gradients, whose `colors["proc_start"]` btop never sets).
     *
     * @param array<string, ?Color> $colors
     * @param array<string, array{int, int, int}> $raw
     * @return array{GradientStore, array<string, list<?Color>>}
     */
    private static function buildGradients(array $colors, array $raw): array
    {
        // operator[] on a missing rgbs entry value-initialises to {0,0,0}.
        $rgb = static fn (string $k): array => $raw[$k] ?? [0, 0, 0];

        $defs = [];
        foreach (self::FAMILIES as $family) {
            $defs[$family] = [$rgb($family . '_start'), $rgb($family . '_mid'), $rgb($family . '_end'), $colors[$family . '_start'] ?? null];
        }
        $defs['proc'] = [$rgb('main_fg'), self::UNSET, $rgb('inactive_fg'), null];
        $defs['proc_color'] = [$rgb('inactive_fg'), self::UNSET, $rgb('process_start'), null];

        $store = GradientStore::new();
        $fixed = [];
        foreach ($defs as $name => [$start, $mid, $end, $fill]) {
            $hasMid = $mid[0] >= 0;
            if ($end[0] >= 0 && $start[0] !== -1) {
                $stops = $hasMid ? [$start, $mid, $end] : [$start, $end];
                if (self::inRange(...$stops)) {
                    $store = $store->withGradient(
                        $name,
                        Color::rgb(...$start),
                        $hasMid ? Color::rgb(...$mid) : null,
                        Color::rgb(...$end),
                    );
                } else {
                    $fixed[$name] = self::interpolate($start, $hasMid ? $mid : null, $end);
                }
            } elseif ($fill === null) {
                $fixed[$name] = array_fill(0, 101, null);
            } else {
                $store = $store->withGradient($name, $fill);
            }
        }
        return [$store, $fixed];
    }

    /** @param array{int, int, int} ...$stops */
    private static function inRange(array ...$stops): bool
    {
        foreach ($stops as $stop) {
            foreach ($stop as $v) {
                if ($v < 0 || $v > 255) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * btop's interpolation loop verbatim (truncating division, two 50-wide
     * legs with a mid), clamping only the emitted color as dec_to_color does.
     * Reached only for out-of-range raw stops; in-range ramps go through
     * {@see GradientStore::ramp()}, which implements the same law.
     *
     * @param array{int, int, int} $start
     * @param ?array{int, int, int} $mid
     * @param array{int, int, int} $end
     * @return list<Color>
     */
    private static function interpolate(array $start, ?array $mid, array $end): array
    {
        $in = $mid === null ? [$start, $end] : [$start, $mid, $end];
        $range = $mid === null ? 100 : 50;
        $out = [];
        for ($i = 0; $i <= 100; $i++) {
            $leg = $range === 50 && $i > 50 ? 1 : 0;
            $offset = $leg * 50;
            $c = [];
            for ($ch = 0; $ch < 3; $ch++) {
                $a = $in[$leg][$ch];
                $b = $in[$leg + 1][$ch];
                $c[] = $a + intdiv(($i - $offset) * ($b - $a), $range);
            }
            $out[] = self::clamped($c);
        }
        return $out;
    }
}
