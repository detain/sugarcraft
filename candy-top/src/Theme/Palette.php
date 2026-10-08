<?php

declare(strict_types=1);

namespace SugarCraft\Top\Theme;

use SugarCraft\Core\Util\Color;

/**
 * What a panel reads to paint: named semantic colors plus 101-entry
 * percent gradients. Both the truecolor {@see ThemeConfig} and the stepped
 * 16-color {@see TtyTheme} satisfy it, so a view never branches on mode.
 *
 * Mirrors aristocratos/btop Theme::c / Theme::g (src/btop_theme.hpp).
 */
interface Palette
{
    /** Theme identifier as shown in the options menu ("Default", "TTY", a file stem). */
    public function name(): string;

    /**
     * Color for semantic key `$key` (one of {@see ThemeConfig::DEFAULT_THEME}'s
     * keys). Null means "emit nothing" — btop's empty escape, e.g. a
     * transparent `main_bg` or an empty `*_mid` stop.
     *
     * @throws \OutOfBoundsException on a key outside the btop vocabulary
     */
    public function color(string $key): ?Color;

    /**
     * Color at `$percent` (clamped 0..100) of gradient `$name`; null when the
     * gradient exists but carries no color.
     *
     * @throws \OutOfBoundsException when no gradient `$name` exists
     */
    public function at(string $name, int $percent): ?Color;

    public function hasGradient(string $name): bool;

    /** @return list<string> gradient names this palette defines */
    public function gradientNames(): array;
}
