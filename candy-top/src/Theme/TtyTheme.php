<?php

declare(strict_types=1);

namespace SugarCraft\Top\Theme;

use SugarCraft\Core\Util\Color;

/**
 * btop's builtin "TTY" theme: every key is a fixed 16-color SGR escape and
 * each gradient is stepped, not interpolated — three bands split at 33/66
 * (start 0-33, mid 34-66, end 67-100) when the family has a mid, two bands
 * split at 50 (start 0-50, end 51-100) when it does not. Used for the
 * linux console and `tty_mode`, where a 101-stop ramp would only alias.
 *
 * Only the 9 families get gradients: btop's generateTTYColors never derives
 * `proc`/`proc_color`, so {@see hasGradient()} is false for them and a view
 * must not ask (btop would throw from `gradients.at()` too).
 *
 * Colors are {@see Color::ansi()} palette slots, so they emit the terminal's
 * own 16 colors; {@see sgr()} returns btop's escape verbatim (depth baked in,
 * and main_bg's leading reset).
 *
 * Default/TTY values and the resolution law are ported from btop
 * (Copyright 2021 Aristocratos, Apache-2.0; see candy-top/themes/LICENSE).
 *
 * Mirrors aristocratos/btop Theme::TTY_theme / generateTTYColors (src/btop_theme.cpp).
 */
final class TtyTheme implements Palette
{
    /** btop's TTY_theme, verbatim. */
    public const TTY_THEME = [
        'main_bg' => "\x1b[0;40m",
        'main_fg' => "\x1b[37m",
        'title' => "\x1b[97m",
        'hi_fg' => "\x1b[91m",
        'selected_bg' => "\x1b[41m",
        'selected_fg' => "\x1b[97m",
        'inactive_fg' => "\x1b[90m",
        'graph_text' => "\x1b[90m",
        'meter_bg' => "\x1b[90m",
        'proc_misc' => "\x1b[92m",
        'cpu_box' => "\x1b[32m",
        'mem_box' => "\x1b[33m",
        'net_box' => "\x1b[35m",
        'proc_box' => "\x1b[31m",
        'div_line' => "\x1b[90m",
        'temp_start' => "\x1b[94m",
        'temp_mid' => "\x1b[96m",
        'temp_end' => "\x1b[95m",
        'cpu_start' => "\x1b[92m",
        'cpu_mid' => "\x1b[93m",
        'cpu_end' => "\x1b[91m",
        'free_start' => "\x1b[32m",
        'free_mid' => '',
        'free_end' => "\x1b[92m",
        'cached_start' => "\x1b[36m",
        'cached_mid' => '',
        'cached_end' => "\x1b[96m",
        'available_start' => "\x1b[33m",
        'available_mid' => '',
        'available_end' => "\x1b[93m",
        'used_start' => "\x1b[31m",
        'used_mid' => '',
        'used_end' => "\x1b[91m",
        'download_start' => "\x1b[34m",
        'download_mid' => '',
        'download_end' => "\x1b[94m",
        'upload_start' => "\x1b[35m",
        'upload_mid' => '',
        'upload_end' => "\x1b[95m",
        'process_start' => "\x1b[32m",
        'process_mid' => "\x1b[33m",
        'process_end' => "\x1b[31m",
        'proc_pause_bg' => "\x1b[41m",
        'proc_follow_bg' => "\x1b[44m",
        'proc_banner_bg' => "\x1b[45m",
        'proc_banner_fg' => "\x1b[97m",
        'followed_bg' => "\x1b[44m",
        'followed_fg' => "\x1b[97m",
    ];

    /** btop's escape for "terminal default background" when theme_background is off. */
    public const DEFAULT_BG = "\x1b[49m";

    /** @var array<string, string> */
    private readonly array $sgr;

    /** @var array<string, list<?Color>> */
    private readonly array $gradients;

    private function __construct(
        private readonly bool $themeBackground,
    ) {
        $sgr = self::TTY_THEME;
        if (!$themeBackground) {
            $sgr['main_bg'] = self::DEFAULT_BG;
        }
        $this->sgr = $sgr;
        $this->gradients = self::step($sgr);
    }

    public static function new(): self
    {
        return new self(true);
    }

    /** btop's `theme_background` option: false swaps main_bg for the terminal default. */
    public function withThemeBackground(bool $on): self
    {
        return $this->mutate(themeBackground: $on);
    }

    public function name(): string
    {
        return 'TTY';
    }

    public function themeBackground(): bool
    {
        return $this->themeBackground;
    }

    /**
     * The raw escape btop emits for `$key` ('' for an empty mid stop).
     *
     * @throws \OutOfBoundsException on an unknown key
     */
    public function sgr(string $key): string
    {
        if (!array_key_exists($key, $this->sgr)) {
            throw new \OutOfBoundsException(sprintf('Unknown theme key "%s"', $key));
        }
        return $this->sgr[$key];
    }

    public function color(string $key): ?Color
    {
        return self::toColor($this->sgr($key));
    }

    public function at(string $name, int $percent): ?Color
    {
        return $this->gradient($name)[max(0, min(100, $percent))];
    }

    /**
     * @return list<?Color> exactly 101 entries
     * @throws \OutOfBoundsException when no gradient `$name` exists
     */
    public function gradient(string $name): array
    {
        if (!isset($this->gradients[$name])) {
            throw new \OutOfBoundsException(sprintf('Unknown gradient "%s"', $name));
        }
        return $this->gradients[$name];
    }

    public function hasGradient(string $name): bool
    {
        return isset($this->gradients[$name]);
    }

    public function gradientNames(): array
    {
        return array_keys($this->gradients);
    }

    /**
     * Map a 16-color SGR escape to its palette slot: 30-37/40-47 → 0-7,
     * 90-97/100-107 → 8-15. The last parameter wins, so main_bg's
     * `0;40` reads as slot 0. Anything else (`49`, '') is null.
     */
    public static function toColor(string $sgr): ?Color
    {
        if (preg_match('/^\x1b\[(?:\d+;)*(\d+)m$/', $sgr, $m) !== 1) {
            return null;
        }
        $code = (int) $m[1];
        $index = match (true) {
            $code >= 30 && $code <= 37 => $code - 30,
            $code >= 40 && $code <= 47 => $code - 40,
            $code >= 90 && $code <= 97 => $code - 82,
            $code >= 100 && $code <= 107 => $code - 92,
            default => null,
        };
        return $index === null ? null : Color::ansi($index);
    }

    private function mutate(?bool $themeBackground = null): self
    {
        return new self($themeBackground ?? $this->themeBackground);
    }

    /**
     * @param array<string, string> $sgr
     * @return array<string, list<?Color>>
     */
    private static function step(array $sgr): array
    {
        $out = [];
        foreach ($sgr as $key => $_) {
            if (!str_ends_with($key, '_start')) {
                continue;
            }
            $base = substr($key, 0, -strlen('_start'));
            $section = '_start';
            $split = $sgr[$base . '_mid'] === '' ? 50 : 33;
            $ramp = [];
            for ($i = 0; $i <= 100; $i++) {
                $ramp[] = self::toColor($sgr[$base . $section]);
                // Same walk as btop: switch after writing index `split`, then double it,
                // so 33 → mid → 66 → end, or 50 → end.
                if ($i === $split) {
                    $section = $split === 33 ? '_mid' : '_end';
                    $split *= 2;
                }
            }
            $out[$base] = $ramp;
        }
        return $out;
    }
}
