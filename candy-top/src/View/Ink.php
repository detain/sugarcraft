<?php

declare(strict_types=1);

namespace SugarCraft\Top\View;

use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Top\Theme\Palette;
use SugarCraft\Top\Theme\ThemeConfig;

/**
 * A {@see Palette} resolved to escape strings for one color profile —
 * btop's `Theme::c(key)` / `Theme::g(name).at(i)` as the view consumes them.
 *
 * The 48 semantic keys are resolved once at construction (a frame asks for
 * the same dozen box colors hundreds of times); gradient stops resolve on
 * demand through the palette's own 101-stop cache.
 */
final class Ink
{
    /** @var array<string, string> */
    private readonly array $fg;

    /** @var array<string, string> */
    private readonly array $bg;

    private function __construct(
        private readonly Palette $palette,
        private readonly ColorProfile $profile,
    ) {
        $fg = [];
        $bg = [];
        foreach (array_keys(ThemeConfig::DEFAULT_THEME) as $key) {
            $color = $palette->color($key);
            $fg[$key] = $color?->toFg($profile) ?? '';
            $bg[$key] = $color?->toBg($profile) ?? '';
        }
        $this->fg = $fg;
        $this->bg = $bg;
    }

    public static function new(Palette $palette, ColorProfile $profile = ColorProfile::TrueColor): self
    {
        return new self($palette, $profile);
    }

    public function palette(): Palette
    {
        return $this->palette;
    }

    public function profile(): ColorProfile
    {
        return $this->profile;
    }

    /** Foreground escape for semantic `$key` ('' when the theme leaves it unset). */
    public function fg(string $key): string
    {
        return $this->fg[$key] ?? throw new \OutOfBoundsException(sprintf('Unknown theme key "%s"', $key));
    }

    /** Background escape for semantic `$key` ('' when unset). */
    public function bg(string $key): string
    {
        return $this->bg[$key] ?? throw new \OutOfBoundsException(sprintf('Unknown theme key "%s"', $key));
    }

    /** Foreground escape at `$percent` of gradient `$name` — btop `Theme::g(name).at(percent)`. */
    public function gradient(string $name, int $percent): string
    {
        return $this->palette->at($name, $percent)?->toFg($this->profile) ?? '';
    }

    /**
     * The style every cell starts from: theme background (when
     * theme_background is on — the palette then returns a main_bg) plus
     * main_fg for unstyled text.
     */
    public function base(): string
    {
        return $this->bg['main_bg'] . $this->fg['main_fg'];
    }
}
