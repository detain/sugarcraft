<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Mode;

use SugarCraft\Readline\TextPrompt;

/**
 * Key-binding mode that translates sequences of keys into TextPrompt operations.
 *
 * Each mode (vi, emacs) takes a TextPrompt and returns a modified TextPrompt,
 * potentially with updated internal mode state.
 */
interface ModeInterface
{
    /**
     * Handle a keypress within this mode.
     *
     * @param TextPrompt $prompt The current prompt state
     * @param string     $key    The key that was pressed
     * @return TextPrompt A new TextPrompt (possibly with an updated mode attached)
     */
    public function handleKey(TextPrompt $prompt, string $key): TextPrompt;

    /**
     * The name of this mode.
     *
     * @return string 'vi' or 'emacs'
     */
    public function name(): string;
}
