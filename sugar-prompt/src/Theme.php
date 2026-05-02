<?php

declare(strict_types=1);

namespace CandyCore\Prompt;

use CandyCore\Core\Util\Color;
use CandyCore\Sprinkles\Style;

/**
 * Stylesheet for {@see Form} rendering. Each slot is a {@see Style} for
 * one element type — title, description, focused/blurred field titles,
 * cursor, options, error message, etc.
 *
 * Mirrors charmbracelet/huh's `Theme`. Built-in presets:
 *  - {@see ansi()}  — coloured 16-color palette (default).
 *  - {@see plain()} — every Style is a no-op (plain-text fallback).
 *  - {@see charm()} — Charm-brand pink + cyan.
 *  - {@see dracula()} — popular dark-purple palette.
 *  - {@see catppuccin()} — pastel "Catppuccin" palette.
 *  - {@see base16()} — neutral Base16 default.
 *  - {@see base()}  — minimal monochrome (bold focus, no colour).
 */
final class Theme
{
    public function __construct(
        public readonly Style $title,
        public readonly Style $description,
        public readonly Style $focusedTitle,
        public readonly Style $blurredTitle,
        public readonly Style $error,
        public readonly Style $cursor,
        public readonly Style $option,
        public readonly Style $selectedOption,
        public readonly Style $help,
        public readonly Style $prompt,
    ) {}

    /** Default coloured theme. Bright pink accents, cyan focus. */
    public static function ansi(): self
    {
        $accent  = Color::ansi(13);   // bright magenta
        $cyan    = Color::ansi(14);
        $grey    = Color::ansi(8);
        $red     = Color::ansi(9);
        return new self(
            title:          Style::new()->bold()->foreground($accent),
            description:    Style::new()->faint(),
            focusedTitle:   Style::new()->bold()->foreground($cyan),
            blurredTitle:   Style::new()->faint(),
            error:          Style::new()->foreground($red),
            cursor:         Style::new()->reverse(),
            option:         Style::new(),
            selectedOption: Style::new()->bold()->foreground($accent),
            help:           Style::new()->faint(),
            prompt:         Style::new()->foreground($cyan),
        );
    }

    /** No-style theme — every slot renders text verbatim. */
    public static function plain(): self
    {
        $s = Style::new();
        return new self(
            title: $s, description: $s, focusedTitle: $s, blurredTitle: $s,
            error: $s, cursor: $s, option: $s, selectedOption: $s, help: $s,
            prompt: $s,
        );
    }

    /** Charm-brand pink + cyan. */
    public static function charm(): self
    {
        $pink  = Color::hex('#ff5fd2');
        $cyan  = Color::hex('#5fafff');
        $green = Color::hex('#5fff87');
        return new self(
            title:          Style::new()->bold()->foreground($pink),
            description:    Style::new()->foreground(Color::hex('#888888')),
            focusedTitle:   Style::new()->bold()->foreground($cyan),
            blurredTitle:   Style::new()->foreground(Color::hex('#5f5f5f')),
            error:          Style::new()->bold()->foreground(Color::hex('#ff5f5f')),
            cursor:         Style::new()->reverse()->foreground($pink),
            option:         Style::new(),
            selectedOption: Style::new()->bold()->foreground($green),
            help:           Style::new()->faint(),
            prompt:         Style::new()->bold()->foreground($cyan),
        );
    }

    /** Dracula palette — dark magenta / cyan / green. */
    public static function dracula(): self
    {
        $pink  = Color::hex('#ff79c6');
        $purp  = Color::hex('#bd93f9');
        $cyan  = Color::hex('#8be9fd');
        $green = Color::hex('#50fa7b');
        $com   = Color::hex('#6272a4');
        return new self(
            title:          Style::new()->bold()->foreground($pink),
            description:    Style::new()->foreground($com),
            focusedTitle:   Style::new()->bold()->foreground($purp),
            blurredTitle:   Style::new()->foreground($com),
            error:          Style::new()->bold()->foreground(Color::hex('#ff5555')),
            cursor:         Style::new()->reverse()->foreground($pink),
            option:         Style::new()->foreground(Color::hex('#f8f8f2')),
            selectedOption: Style::new()->bold()->foreground($green),
            help:           Style::new()->foreground($com),
            prompt:         Style::new()->foreground($cyan),
        );
    }

    /** Catppuccin "Mocha" pastel palette. */
    public static function catppuccin(): self
    {
        $mauve  = Color::hex('#cba6f7');
        $teal   = Color::hex('#94e2d5');
        $green  = Color::hex('#a6e3a1');
        $red    = Color::hex('#f38ba8');
        $surf   = Color::hex('#a6adc8');
        return new self(
            title:          Style::new()->bold()->foreground($mauve),
            description:    Style::new()->foreground($surf),
            focusedTitle:   Style::new()->bold()->foreground($teal),
            blurredTitle:   Style::new()->foreground($surf),
            error:          Style::new()->bold()->foreground($red),
            cursor:         Style::new()->reverse()->foreground($mauve),
            option:         Style::new(),
            selectedOption: Style::new()->bold()->foreground($green),
            help:           Style::new()->foreground($surf),
            prompt:         Style::new()->foreground($teal),
        );
    }

    /** Base16 default — neutral and broadly compatible. */
    public static function base16(): self
    {
        $accent = Color::hex('#cc6666');  // Base16 red
        $cyan   = Color::hex('#8abeb7');
        $green  = Color::hex('#b5bd68');
        return new self(
            title:          Style::new()->bold()->foreground($accent),
            description:    Style::new()->foreground(Color::hex('#969896')),
            focusedTitle:   Style::new()->bold()->foreground($cyan),
            blurredTitle:   Style::new()->faint(),
            error:          Style::new()->bold()->foreground($accent),
            cursor:         Style::new()->reverse(),
            option:         Style::new(),
            selectedOption: Style::new()->bold()->foreground($green),
            help:           Style::new()->faint(),
            prompt:         Style::new()->foreground($cyan),
        );
    }

    /** Bare-bones monochrome — bold focus + reverse cursor, no colour. */
    public static function base(): self
    {
        return new self(
            title:          Style::new()->bold(),
            description:    Style::new()->faint(),
            focusedTitle:   Style::new()->bold()->underline(),
            blurredTitle:   Style::new()->faint(),
            error:          Style::new()->bold(),
            cursor:         Style::new()->reverse(),
            option:         Style::new(),
            selectedOption: Style::new()->bold(),
            help:           Style::new()->faint(),
            prompt:         Style::new()->bold(),
        );
    }
}
