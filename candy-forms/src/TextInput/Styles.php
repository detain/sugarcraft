<?php

declare(strict_types=1);

namespace SugarCraft\Forms\TextInput;

use SugarCraft\Sprinkles\Style;

/**
 * Styles for {@see TextInput}: one slot per visible element. All
 * default to a no-op {@see Style} so callers / snapshots that
 * never touch styling stay byte-for-byte unchanged. Pass non-default
 * styles to {@see TextInput::withStyles()} to customise.
 *
 * Mirrors upstream Bubbles' `textinput.Styles`.
 */
final class Styles
{
    public readonly Style $prompt;
    public readonly Style $placeholder;
    public readonly Style $text;
    public readonly Style $cursor;

    public function __construct(
        ?Style $prompt      = null,
        ?Style $placeholder = null,
        ?Style $text        = null,
        ?Style $cursor      = null,
    ) {
        $noop = Style::new();
        $this->prompt      = $prompt      ?? $noop;
        $this->placeholder = $placeholder ?? $noop;
        $this->text        = $text        ?? $noop;
        $this->cursor      = $cursor      ?? $noop;
    }
}
