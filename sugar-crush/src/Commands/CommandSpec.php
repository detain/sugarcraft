<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Commands;

use SugarCraft\Crush\Palette\PaletteAction;

/**
 * Metadata for one command, used by BOTH command surfaces - the "/" popup
 * ({@see \SugarCraft\Crush\Renderer::renderSlashMenu()}) and the Ctrl+P
 * palette ({@see \SugarCraft\Crush\Renderer::renderPalette()}). Pure display
 * data - it does not affect {@see \SugarCraft\Crush\Chat::submit()}'s own
 * dispatch chain, which stays the single source of truth for what a command
 * actually does.
 *
 * A row is visible in the "/" popup unless $slashVisible is false, and
 * visible in the Ctrl+P palette exactly when it carries a $paletteAction -
 * the two flags are what let one registry feed two surfaces that legitimately
 * do not list identical sets (e.g. "New session" has no slash form).
 */
final class CommandSpec
{
    public function __construct(
        /** Command name without the leading "/", e.g. "compact". */
        public readonly string $name,
        /** One-line human-readable description shown in the popup/palette. */
        public readonly string $description,
        /** Grouping label shown in the Ctrl+P palette, e.g. "Session". */
        public readonly string $category,
        /**
         * The palette action {@see \SugarCraft\Crush\Chat} dispatches when
         * this row is picked in Ctrl+P; null for commands the palette does
         * not list (they are reachable by typing "/name" instead).
         */
        public readonly ?PaletteAction $paletteAction = null,
        /**
         * Palette row text when it should read differently from the bare
         * command name ("Switch session" vs "sessions"). Null falls back to
         * {@see label()}'s default, the name itself.
         */
        public readonly ?string $paletteLabel = null,
        /** Argument placeholder shown after the name, e.g. "<name>". */
        public readonly ?string $argumentHint = null,
        /** Keybind that also triggers this command, e.g. "Ctrl+C". */
        public readonly ?string $shortcut = null,
        /** Whether the "/" popup lists this row (false = palette-only). */
        public readonly bool $slashVisible = true,
    ) {}

    public static function new(
        string $name,
        string $description,
        string $category,
        ?PaletteAction $paletteAction = null,
        ?string $paletteLabel = null,
        ?string $argumentHint = null,
        ?string $shortcut = null,
        bool $slashVisible = true,
    ): self {
        return new self(
            $name,
            $description,
            $category,
            $paletteAction,
            $paletteLabel,
            $argumentHint,
            $shortcut,
            $slashVisible,
        );
    }

    /**
     * The row's display text in the Ctrl+P palette.
     */
    public function label(): string
    {
        return $this->paletteLabel ?? $this->name;
    }
}
