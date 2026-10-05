<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Commands;

/**
 * One keyboard (or mouse) binding, as {@see KeyBindingRegistry} declares it.
 *
 * The same relationship {@see CommandSpec} has to slash commands: pure
 * display data plus the one machine-readable field the runtime consumes
 * ({@see ctrlRune()}, which {@see \SugarCraft\Crush\Tui\KeyboardHandler}
 * derives its claim sets from). What a key DOES still lives in
 * `Chat::update()` / `KeyboardHandler` — a row here describes a binding, it
 * does not create one.
 */
final class KeyBinding
{
    public function __construct(
        /** Stable identifier, e.g. "chat.palette" — what tests name a row by. */
        public readonly string $id,
        /** The chord as a user reads it, e.g. "Ctrl+P", "Alt+1…9", "Esc Esc". */
        public readonly string $keys,
        /** One line, in the imperative: "Open the command palette". */
        public readonly string $description,
        /** Grouping label — one of {@see KeyBindingRegistry}'s CONTEXT_* values. */
        public readonly string $context,
        /**
         * Why this row is NOT shown in the in-app reference. Non-null means
         * the key is claimed by a handler but has no observable effect yet,
         * so advertising it would promise something the app does not do.
         *
         * The row stays declared either way — a dormant seam is documented in
         * this repo, not deleted — and the reason is per-row, because whether
         * dropping the row would change routing differs from chord to chord
         * (a bare `Ctrl+<rune>` row feeds {@see KeyBindingRegistry::shellCtrlRunes()},
         * a two-key sequence or a letter in a pane's own context feeds no
         * derived set at all).
         *
         * No row is dormant today: the last one, the Agent View's `Ctrl+X b`
         * (`agentview.background`), went live with roadmap P-E3, after
         * `Ctrl+G` and the agent dashboard's `c`/`r`/`s` did with P-D3. The
         * field stays for the next chord that is claimed before it is wired.
         */
        public readonly ?string $dormantReason = null,
        /**
         * Why the pane SHELL keeps this chord for itself while one of its own
         * keyboard-owning views is up, even though the row's context is
         * {@see KeyBindingRegistry::CONTEXT_CHAT}. Null (the default) means
         * the content model owns the chord unconditionally.
         *
         * Only meaningful on a `Ctrl+<rune>` chat row — see
         * {@see KeyBindingRegistry::chatCtrlRunesYieldedToShell()} for the
         * criterion and {@see \SugarCraft\Crush\Tui\KeyboardHandler::chatOwns()}
         * for the one place that reads it.
         */
        public readonly ?string $yieldsToShellReason = null,
    ) {}

    public static function new(
        string $id,
        string $keys,
        string $description,
        string $context,
        ?string $dormantReason = null,
        ?string $yieldsToShellReason = null,
    ): self {
        return new self($id, $keys, $description, $context, $dormantReason, $yieldsToShellReason);
    }

    /** Whether the in-app reference lists this row. */
    public function isLive(): bool
    {
        return $this->dormantReason === null;
    }

    /**
     * Whether the pane shell takes this chord back while it owns the keyboard.
     *
     * @see $yieldsToShellReason
     */
    public function yieldsToShell(): bool
    {
        return $this->yieldsToShellReason !== null;
    }

    /**
     * The lowercase rune of a bare `Ctrl+<rune>` chord, or null for anything
     * else — `Ctrl+Tab`, `Alt+1…9`, `Enter`, a mouse gesture.
     *
     * Deliberately strict about the single-character tail: `Ctrl+Shift+Tab`
     * and `Ctrl+Enter` are chords the terminal reports as a named key, not as
     * `KeyType::Char` with a rune, so treating their tail as a rune would put
     * a name into a set that is compared against `KeyMsg::$rune`.
     */
    public function ctrlRune(): ?string
    {
        if (!str_starts_with($this->keys, 'Ctrl+')) {
            return null;
        }

        $rest = substr($this->keys, 5);

        return mb_strlen($rest) === 1 ? mb_strtolower($rest) : null;
    }
}
