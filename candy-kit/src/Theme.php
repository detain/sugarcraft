<?php

declare(strict_types=1);

namespace SugarCraft\Kit;

use SugarCraft\Core\Msg\BackgroundColorMsg;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Palettes;
use SugarCraft\Sprinkles\Style;

/**
 * Per-status palette used by {@see StatusLine} and {@see Banner}.
 * Themes are immutable; {@see ansi()} ships the default colourful
 * palette, {@see plain()} produces a no-op palette ideal for
 * snapshot tests, and a handful of named presets (`charm`,
 * `dracula`, `nord`, `catppuccin`) cover the most-requested
 * branded palettes.
 */
final class Theme
{
    /**
     * All seven slots are non-nullable: a theme without a style for a level
     * is an illegal state, so the type system enforces it at the boundary
     * (strict_types turns a null argument into a TypeError) instead of a
     * hand-rolled constructor loop.
     */
    public function __construct(
        public readonly Style $success,
        public readonly Style $error,
        public readonly Style $warn,
        public readonly Style $info,
        public readonly Style $prompt,
        public readonly Style $accent,
        public readonly Style $muted,
    ) {}

    public static function ansi(): self
    {
        return new self(
            success: Style::new()->bold()->foreground(Color::ansi(10)),  // bright green
            error:   Style::new()->bold()->foreground(Color::ansi(9)),   // bright red
            warn:    Style::new()->bold()->foreground(Color::ansi(11)),  // bright yellow
            info:    Style::new()->bold()->foreground(Color::ansi(12)),  // bright blue
            prompt:  Style::new()->bold()->foreground(Color::hex('#ff5f87')),
            accent:  Style::new()->bold()->foreground(Color::ansi(13)),  // bright magenta
            muted:   Style::new()->faint(),
        );
    }

    public static function plain(): self
    {
        $s = Style::new();
        return new self($s, $s, $s, $s, $s, $s, $s);
    }

    /**
     * Terminal-adaptive factory — picks a dark or light theme by checking
     * the terminal background colour.
     *
     * The TEA program sends {@see \SugarCraft\Core\Cmd::requestBackgroundColor()}
     * during init, receives a {@see BackgroundColorMsg} in its update loop, and
     * passes it here to select the appropriate palette. Theme itself keeps no
     * state — the program owns the detection — so calling this with no msg
     * simply returns the {@see ansi()} fallback.
     *
     * Dark terminals get the {@see nord()} palette; light terminals get
     * {@see catppuccin()}.
     *
     * @param BackgroundColorMsg|null $bg  parsed OSC-11 reply; null (or a
     *                                     reply that never arrived) falls back
     *                                     to {@see ansi()}
     */
    public static function auto(?BackgroundColorMsg $bg = null): self
    {
        if ($bg !== null) {
            return $bg->isDark() ? self::nord() : self::catppuccin();
        }
        // Stateless: without a msg in hand there is nothing to detect from.
        return self::ansi();
    }

    /**
     * Begin building a custom theme with a fluent interface.
     *
     * @example Theme::build()->success(...)->error(...)->muted(...)->build()
     */
    public static function build(): ThemeBuilder
    {
        return new ThemeBuilder();
    }

    /** Charm-brand pink + cyan accent set. */
    public static function charm(): self
    {
        $pink = Color::hex('#ff5fd2');
        $cyan = Color::hex('#5fafff');
        return new self(
            success: Style::new()->bold()->foreground(Color::hex('#5fff87')),
            error:   Style::new()->bold()->foreground(Color::hex('#ff5f5f')),
            warn:    Style::new()->bold()->foreground(Color::hex('#ffd75f')),
            info:    Style::new()->bold()->foreground($cyan),
            prompt:  Style::new()->bold()->foreground($pink),
            accent:  Style::new()->bold()->foreground($pink),
            muted:   Style::new()->foreground(Color::hex('#888888')),
        );
    }

    /**
     * Dracula palette — hex literals re-sourced from candy-core's
     * {@see Palettes::DRACULA} single-source-of-truth so they no longer
     * drift as they are hand-copied around the monorepo.
     *
     * `accent` binds Dracula's `pink` (matching candy-sprinkles' accent
     * slot), not `purple`; before W5 it had diverged to `purple` #bd93f9.
     */
    public static function dracula(): self
    {
        $p = Palettes::DRACULA;
        return new self(
            success: Style::new()->bold()->foreground(Color::hex($p['green'])),
            error:   Style::new()->bold()->foreground(Color::hex($p['red'])),
            warn:    Style::new()->bold()->foreground(Color::hex($p['yellow'])),
            info:    Style::new()->bold()->foreground(Color::hex($p['cyan'])),
            prompt:  Style::new()->bold()->foreground(Color::hex($p['pink'])),
            accent:  Style::new()->bold()->foreground(Color::hex($p['pink'])),
            muted:   Style::new()->foreground(Color::hex($p['comment'])),
        );
    }

    /** Nord palette — cool blues and frost tones. */
    public static function nord(): self
    {
        return new self(
            success: Style::new()->bold()->foreground(Color::hex('#a3be8c')),
            error:   Style::new()->bold()->foreground(Color::hex('#bf616a')),
            warn:    Style::new()->bold()->foreground(Color::hex('#ebcb8b')),
            info:    Style::new()->bold()->foreground(Color::hex('#88c0d0')),
            prompt:  Style::new()->bold()->foreground(Color::hex('#5e81ac')),
            accent:  Style::new()->bold()->foreground(Color::hex('#88c0d0')),
            muted:   Style::new()->foreground(Color::hex('#4c566a')),
        );
    }

    /** Catppuccin Mocha — pastel set. */
    public static function catppuccin(): self
    {
        return new self(
            success: Style::new()->bold()->foreground(Color::hex('#a6e3a1')),
            error:   Style::new()->bold()->foreground(Color::hex('#f38ba8')),
            warn:    Style::new()->bold()->foreground(Color::hex('#f9e2af')),
            info:    Style::new()->bold()->foreground(Color::hex('#94e2d5')),
            prompt:  Style::new()->bold()->foreground(Color::hex('#cba6f7')),
            accent:  Style::new()->bold()->foreground(Color::hex('#cba6f7')),
            muted:   Style::new()->foreground(Color::hex('#a6adc8')),
        );
    }

    /**
     * Resolve a theme by name. Presets: `'ansi'`, `'plain'`, `'charm'`,
     * `'dracula'`, `'nord'`, `'catppuccin'`, and `'auto'` (the adaptive
     * factory with no background msg — the {@see ansi()} fallback).
     * Case-insensitive.
     *
     * @throws \InvalidArgumentException if the name is not a recognised preset
     */
    public static function byName(string $name): self
    {
        return match (strtolower($name)) {
            'ansi'        => self::ansi(),
            'plain'       => self::plain(),
            'charm'       => self::charm(),
            'dracula'     => self::dracula(),
            'nord'        => self::nord(),
            'catppuccin'  => self::catppuccin(),
            'auto'        => self::auto(),
            default       => throw new \InvalidArgumentException("unknown theme: {$name}"),
        };
    }
}
