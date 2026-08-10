<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\Util\Color;
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
 * `oneDark`/`githubDark`/`solarizedDark`/`solarizedLight`/`adaptive` and
 * ShineTheme also has `pink`/`plain`/`notty`/`ascii`, but offering a name
 * only one side recognizes would silently mismatch chrome and markdown
 * colors, so those extras are left out of the picker for now.
 */
final class Theme
{
    private const NAMES = ['dark', 'light', 'dracula', 'tokyoNight', 'ansi'];

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

        $sprinkles = match ($canonical) {
            'dark' => SprinklesTheme::dark(),
            'light' => SprinklesTheme::light(),
            'dracula' => SprinklesTheme::dracula(),
            'tokyoNight' => SprinklesTheme::tokyoNight(),
            'ansi' => SprinklesTheme::ansi(),
        };

        return new self(
            name: $canonical,
            markdown: ShineTheme::byName($canonical) ?? ShineTheme::ansi(),
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
