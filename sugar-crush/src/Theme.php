<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\Util\Color;
use SugarCraft\Crush\Tui\TerminalBackground;
use SugarCraft\Shine\Theme as ShineTheme;
use SugarCraft\Sprinkles\Theme as SprinklesTheme;

/**
 * A named color theme for {@see Renderer}'s chrome (borders, user/assistant/
 * system labels) and CandyShine's markdown rendering, resolved from the two
 * theme classes that already exist elsewhere in the monorepo rather than
 * inventing a third color system:
 *
 *   - {@see SprinklesTheme} supplies the chrome colors (border/labels).
 *   - {@see ShineTheme} supplies the markdown-rendering theme.
 *
 * The offered name list ({@see names()}) is deliberately restricted to the
 * intersection of presets both classes support by the same name
 * (`dark`/`light`/`dracula`/`tokyoNight`/`ansi`) - SprinklesTheme also has
 * `oneDark`/`githubDark`/`solarizedDark`/`solarizedLight` and ShineTheme also
 * has `pink`/`plain`/`notty`/`ascii`, but offering a name only one side
 * recognizes would silently mismatch chrome and markdown colors, so those
 * extras are left out of the picker for now.
 *
 * `adaptive` is the one name neither class resolves on its own: it is paired
 * here, from `dark`/`light` on BOTH sides at once, against
 * {@see TerminalBackground}'s answer for the attached terminal. That keeps the
 * chrome/markdown pairing invariant intact (both halves always come from the
 * same side of the light/dark split) while giving the user a preset that does
 * not require them to know to run `/theme light` on a light terminal. It stays
 * resolved-on-read rather than snapshotted, so a persisted `adaptive` re-reads
 * the terminal on the next launch instead of freezing whatever the first one
 * happened to detect.
 */
final class Theme
{
    private const NAMES = ['dark', 'light', 'dracula', 'tokyoNight', 'ansi', 'adaptive'];

    public function __construct(
        public readonly string $name,
        public readonly ShineTheme $markdown,
        public readonly Color $border,
        public readonly Color $userLabel,
        public readonly Color $assistantLabel,
        public readonly Color $systemLabel,
    ) {}

    /**
     * @throws \InvalidArgumentException When $name isn't one of {@see names()}
     */
    public static function byName(string $name): self
    {
        $canonical = self::canonicalize($name);
        if ($canonical === null) {
            throw new \InvalidArgumentException(
                "Unknown theme '{$name}'. Available themes: " . implode(', ', self::NAMES) . '.'
            );
        }

        if ($canonical === 'adaptive') {
            return self::adaptive();
        }

        $sprinkles = match ($canonical) {
            'dark' => SprinklesTheme::dark(),
            'light' => SprinklesTheme::light(),
            'dracula' => SprinklesTheme::dracula(),
            'tokyoNight' => SprinklesTheme::tokyoNight(),
            'ansi' => SprinklesTheme::ansi(),
        };

        return self::pair($canonical, $sprinkles, ShineTheme::byName($canonical) ?? ShineTheme::ansi());
    }

    /**
     * The `dark`/`light` preset the attached terminal actually calls for, kept
     * under the name `adaptive` so the choice re-resolves on every read (and on
     * every launch) instead of being frozen at pick time.
     *
     * {@see SprinklesTheme::adaptive()} is deliberately NOT used: it resolves
     * only the chrome half, leaving the markdown half to be detected a second
     * time by a second rule, and its `COLORFGBG >= 8` heuristic disagrees with
     * {@see TerminalBackground::detect()} on the two indices that matter (see
     * that method). One detector, both halves.
     */
    public static function adaptive(): self
    {
        return TerminalBackground::isDark()
            ? self::pair('adaptive', SprinklesTheme::dark(), ShineTheme::dark())
            : self::pair('adaptive', SprinklesTheme::light(), ShineTheme::light());
    }

    private static function pair(string $name, SprinklesTheme $sprinkles, ShineTheme $markdown): self
    {
        return new self(
            name: $name,
            markdown: $markdown,
            border: $sprinkles->border,
            userLabel: $sprinkles->primary,
            assistantLabel: $sprinkles->secondary,
            systemLabel: $sprinkles->muted,
        );
    }

    public static function default(): self
    {
        return self::byName('dark');
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return self::NAMES;
    }

    private static function canonicalize(string $name): ?string
    {
        foreach (self::NAMES as $canonical) {
            if (strtolower($canonical) === strtolower($name)) {
                return $canonical;
            }
        }

        return null;
    }
}
