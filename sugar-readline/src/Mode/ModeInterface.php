<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Mode;

use SugarCraft\Readline\TextPrompt;

/**
 * Key-binding mode that translates sequences of keys into TextPrompt operations.
 *
 * sugar-readline has a dual-engine architecture: TextPrompt owns the buffer,
 * cursor, history, and rendering, while an optional mode object owns the
 * key-to-operation mapping. TextPrompt::handleKey() delegates every key to
 * the attached mode; the mode replies by calling back into
 * TextPrompt::handleKeyDirect() (never handleKey(), which would recurse) and
 * re-attaching itself via withMode() so its own state survives the prompt's
 * immutable cloning.
 *
 * Two engines ship with the library:
 * - {@see EmacsMode} — stateless-ish readline bindings (Ctrl+A/E/B/F, Alt
 *   word motion, Ctrl+R/S search); its only state is the Alt/Escape prefix.
 * - {@see ViMode} — a modal state machine (insert/normal/visual + pending
 *   motions) that maps keys through sugar-forms' VimKeyHandler/VimAction and
 *   executes the resulting actions as TextPrompt operations.
 *
 * Modes are immutable like the prompt: any internal state change must clone
 * the mode and attach the clone to the returned prompt.
 */
interface ModeInterface
{
    /**
     * Handle a keypress within this mode.
     *
     * Keys arrive in the canonical symbolic language of
     * {@see \SugarCraft\Readline\Key} — raw control bytes are translated by
     * {@see \SugarCraft\Readline\Key::normalize()} at the prompt entry
     * points, so both forms reach the same bindings. Implementations must
     * route unhandled keys to $prompt->handleKeyDirect() so the default
     * bindings still apply.
     *
     * @param TextPrompt $prompt The current prompt state
     * @param string     $key    The key that was pressed
     * @return TextPrompt A new TextPrompt (possibly with an updated mode attached)
     */
    public function handleKey(TextPrompt $prompt, string $key): TextPrompt;

    /**
     * Whether the mode claims plain printable characters as commands.
     *
     * When true, TextPrompt::handleChar() routes the character into
     * handleKey() instead of inserting it — this is how vi normal mode keeps
     * 'h' from typing, and how an armed Escape/meta prefix consumes its
     * following letter. Default editing modes return false so typing still
     * types.
     */
    public function consumesCharacters(): bool;

    /**
     * The name of this mode, used for introspection and tests.
     *
     * @return string 'vi' or 'emacs'
     */
    public function name(): string;
}
