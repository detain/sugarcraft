<?php

declare(strict_types=1);

namespace SugarCraft\Forms;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Palettes;
use SugarCraft\Style\Style;

/**
 * Stylesheet for {@see Form} rendering. Each slot is a {@see Style} for
 * one element type — title, description, focused/blurred field titles,
 * cursor, options, error message, etc.
 *
 * Slot consumption (SF-9, sugar-forms-007): the chrome slots (title,
 * description, help, error, errorSummary) always painted the form-level
 * rows; the FIELD slots now reach the field bodies too — focusedTitle /
 * blurredTitle style every field's title row (focus-aware), cursor styles
 * every highlight surface (list cursor row, Confirm/RadioPills pills,
 * Date cursor cell, FilePicker entry), option styles the un-highlighted
 * list rows (and dims the cursor row while the list is blurred),
 * selectedOption styles checked MultiSelect boxes, prompt carries the
 * focused-Select caret glyph, error styles the field-inline `! error`
 * row, errorSummaryItem styles each bullet inside the error summary
 * (the split researched from huh's own slot pair). A standalone field
 * rendered without a theme keeps its plain legacy bytes; the theme
 * arrives through Form::view().
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
        public readonly Style $errorSummary,
        public readonly Style $cursor,
        public readonly Style $option,
        public readonly Style $selectedOption,
        public readonly Style $help,
        public readonly Style $prompt,
        ?Style $errorSummaryItem = null,
    ) {
        // SF-9 (sugar-forms-094): huh splits the summary BLOCK style from
        // the per-field bullet ITEM style; the slot lands additive with a
        // plain default so pre-split constructions keep their bytes.
        $this->errorSummaryItem = $errorSummaryItem ?? Style::new();
    }

    /** Per-field bullet line inside the error summary (SF-9, slot 12). */
    public readonly Style $errorSummaryItem;

    /**
     * Default coloured theme. Bright pink accents on the chrome.
     *
     * SF-9 (sugar-forms-007): the two field-title slots are deliberately
     * plain here — the shipped default preset must not move any existing
     * render's bytes (dated ruling at the landing commit), and default
     * focus visibility comes from the structural signals instead (the
     * blurred list dims its highlight; the focused Select gains the caret).
     * Every other preset colours these slots, so "seven presets, zero
     * difference inside the body" is dead: pass any non-ansi theme and the
     * titles, pills and options all repaint.
     */
    public static function ansi(): self
    {
        $accent  = Color::ansi(13);   // bright magenta
        $cyan    = Color::ansi(14);
        $red     = Color::ansi(9);
        return new self(
            title:          Style::new()->bold()->foreground($accent),
            description:    Style::new()->faint(),
            focusedTitle:   Style::new(),
            blurredTitle:   Style::new(),
            error:          Style::new()->foreground($red),
            errorSummary:   Style::new()->bold()->foreground($red),
            cursor:         Style::new()->reverse(),
            option:         Style::new(),
            selectedOption: Style::new()->bold()->foreground($accent),
            help:           Style::new()->faint(),
            prompt:         Style::new()->foreground($cyan),
            errorSummaryItem: Style::new()->foreground($red),
        );
    }

    /** No-style theme — every slot renders text verbatim. */
    public static function plain(): self
    {
        $s = Style::new();
        return new self(
            title: $s, description: $s, focusedTitle: $s, blurredTitle: $s,
            error: $s, errorSummary: $s, cursor: $s, option: $s,
            selectedOption: $s, help: $s, prompt: $s,
            errorSummaryItem: $s,
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
            errorSummary:   Style::new()->bold()->foreground(Color::hex('#ff5f5f')),
            cursor:         Style::new()->reverse()->foreground($pink),
            option:         Style::new(),
            selectedOption: Style::new()->bold()->foreground($green),
            help:           Style::new()->faint(),
            prompt:         Style::new()->bold()->foreground($cyan),
            errorSummaryItem: Style::new()->foreground(Color::hex('#ff5f5f')),
        );
    }

    /** Dracula palette — dark magenta / cyan / green. */
    public static function dracula(): self
    {
        $pink  = Color::hex(Palettes::DRACULA['pink']);
        $purp  = Color::hex(Palettes::DRACULA['purple']);
        $cyan  = Color::hex(Palettes::DRACULA['cyan']);
        $green = Color::hex(Palettes::DRACULA['green']);
        $com   = Color::hex(Palettes::DRACULA['comment']);
        return new self(
            title:          Style::new()->bold()->foreground($pink),
            description:    Style::new()->foreground($com),
            focusedTitle:   Style::new()->bold()->foreground($purp),
            blurredTitle:   Style::new()->foreground($com),
            error:          Style::new()->bold()->foreground(Color::hex(Palettes::DRACULA['red'])),
            errorSummary:   Style::new()->bold()->foreground(Color::hex(Palettes::DRACULA['red'])),
            cursor:         Style::new()->reverse()->foreground($pink),
            option:         Style::new()->foreground(Color::hex(Palettes::DRACULA['foreground'])),
            selectedOption: Style::new()->bold()->foreground($green),
            help:           Style::new()->foreground($com),
            prompt:         Style::new()->foreground($cyan),
            errorSummaryItem: Style::new()->foreground(Color::hex(Palettes::DRACULA['red'])),
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
            errorSummary:   Style::new()->bold()->foreground($red),
            cursor:         Style::new()->reverse()->foreground($mauve),
            option:         Style::new(),
            selectedOption: Style::new()->bold()->foreground($green),
            help:           Style::new()->foreground($surf),
            prompt:         Style::new()->foreground($teal),
            errorSummaryItem: Style::new()->foreground($red),
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
            errorSummary:   Style::new()->bold()->foreground($accent),
            cursor:         Style::new()->reverse(),
            option:         Style::new(),
            selectedOption: Style::new()->bold()->foreground($green),
            help:           Style::new()->faint(),
            prompt:         Style::new()->foreground($cyan),
            errorSummaryItem: Style::new()->foreground($accent),
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
            errorSummary:   Style::new()->bold(),
            cursor:         Style::new()->reverse(),
            option:         Style::new(),
            selectedOption: Style::new()->bold(),
            help:           Style::new()->faint(),
            prompt:         Style::new()->bold(),
            errorSummaryItem: Style::new(),
        );
    }

    /**
     * Returns a list of all available theme names.
     *
     * Hand-maintained by design (E736 plan 3.5): themes are frozen value
     * objects and the catalog is a contract with callers, not a registry.
     * Adding a theme means writing its static factory AND appending its
     * name here — reflection over `self::` methods was rejected because a
     * stray public static method would silently join the catalog.
     *
     * @return list<string>
     */
    public static function catalog(): array
    {
        return ['ansi', 'plain', 'charm', 'dracula', 'catppuccin', 'base16', 'base'];
    }
}