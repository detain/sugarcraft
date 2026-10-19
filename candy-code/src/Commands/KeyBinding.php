<?php

declare(strict_types=1);

namespace SugarCraft\Code\Commands;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * One keyboard (or mouse) binding, as {@see KeyBindingRegistry} declares it.
 *
 * The same relationship {@see CommandSpec} has to slash commands: pure
 * display data plus the one machine-readable field the runtime consumes
 * ({@see ctrlRune()}, which {@see \SugarCraft\Code\Tui\KeyboardHandler}
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
        /**
         * One line, in the imperative: "Open the command palette". Already
         * translated: the registry looks it up under `keys.<id>`.
         */
        public readonly string $description,
        /**
         * Grouping key — one of {@see KeyBindingRegistry}'s CONTEXT_* values,
         * English in every locale; {@see KeyBindingRegistry::contextLabel()}
         * is the heading shown for it.
         */
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
         * criterion and {@see \SugarCraft\Code\Tui\KeyboardHandler::chatOwns()}
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

    /**
     * The shapes a label's key part may spell out instead of a single rune.
     * Values are `KeyType::$value` strings — the same alphabet
     * {@see KeyMsg::string()} answers named presses with.
     */
    private const NAMED_CHORD_KEYS = [
        'enter' => 'enter',
        'tab' => 'tab',
        'space' => 'space',
        'backspace' => 'backspace',
        'delete' => 'delete',
        'esc' => 'escape',
        'escape' => 'escape',
        'home' => 'home',
        'end' => 'end',
        'pgup' => 'pageup',
        'pageup' => 'pageup',
        'pgdn' => 'pagedown',
        'pagedown' => 'pagedown',
        '↑' => 'up',
        '↓' => 'down',
        '←' => 'left',
        '→' => 'right',
        'up' => 'up',
        'down' => 'down',
        'left' => 'left',
        'right' => 'right',
    ];

    /**
     * This row's chord as a canonical key id — the shape
     * {@see KeyMsg::string()} reports when the key is actually pressed — or
     * null when the label describes anything OTHER than one pressable chord:
     * a dual ("↑ / ↓"), a range ("Alt+1…9"), a sequence ("Esc Esc",
     * "Ctrl+X b"), a mouse gesture ("Wheel", "Click tab"), or free text
     * ("any text").
     *
     * The id space is `KeyMsg::string()`'s own: modifiers lowercase in the
     * order `ctrl`/`alt`/`shift`, then the key — a lowercased rune for
     * printable keys, the `KeyType` value for named ones. That makes the
     * parse an EQUALITY, not a convention: two spellings share an id exactly
     * when the wire reports them identically (plan_comparison TUI-4c).
     */
    public function chord(): ?string
    {
        return self::parseChord($this->keys);
    }

    /**
     * Parse one label into the canonical key id of the single chord it
     * names, or null. `Ctrl+P` and `ctrl+p` and `CTRL+P` all parse to
     * `ctrl+p`; `Alt+Enter` to `alt+enter`; `F10` to `f10`; `?` to `?`.
     *
     * Deliberately strict — a label that cannot be a single `KeyMsg` must
     * return null rather than guess, because callers route on equality with
     * {@see KeyMsg::string()}: a wrong parse is a silently dead alias.
     */
    public static function parseChord(string $label): ?string
    {
        // A space or an ellipsis means the label describes a dual, a
        // sequence, a range, or prose — never one press. (Every dual in the
        // shipped table is written with a spaced slash, so `str_contains` on
        // the space is enough to reject them and a lone `/` key still
        // parses.) Checked character-wise on purpose: the ellipsis shares a
        // lead byte with the arrow glyphs, so a byte-mask function would
        // reject `←` outright.
        if ($label === '' || str_contains($label, ' ')
            || str_contains($label, "\t") || str_contains($label, '…')) {
            return null;
        }

        $ctrl = false;
        $alt = false;
        $shift = false;
        $key = null;

        foreach (explode('+', $label) as $part) {
            $name = strtolower($part);
            if ($key === null && $name === 'ctrl') {
                $ctrl = true;
                continue;
            }
            if ($key === null && $name === 'alt') {
                $alt = true;
                continue;
            }
            if ($key === null && $name === 'shift') {
                $shift = true;
                continue;
            }
            if ($key !== null) {
                return null; // two key parts — "Ctrl+X+b" is not one chord
            }
            $key = $part;
        }

        if ($key === null || $key === '') {
            return null; // bare modifier ("Ctrl+") or nothing at all
        }

        $base = self::NAMED_CHORD_KEYS[strtolower($key)]
            ?? (preg_match('/^f[0-9]{1,2}$/', strtolower($key)) && KeyType::tryFrom(strtolower($key)) !== null
                ? strtolower($key)
                : null);
        if ($base === null && mb_strlen($key) === 1) {
            $base = mb_strtolower($key); // a printable rune, `?` and `,` included
        }
        if ($base === null) {
            return null;
        }

        return ($ctrl ? 'ctrl+' : '') . ($alt ? 'alt+' : '') . ($shift ? 'shift+' : '') . $base;
    }

    /**
     * The canonical id of a press, in {@see parseChord()}'s alphabet —
     * `KeyMsg::string()` with the `Char` rune lowercased, so a decoder that
     * reports `Ctrl+Shift+P` as rune `P` or rune `p` matches either spelling
     * a binding declared.
     */
    public static function pressId(KeyMsg $msg): string
    {
        $base = $msg->type === KeyType::Char ? mb_strtolower($msg->rune) : $msg->type->value;

        return ($msg->ctrl ? 'ctrl+' : '') . ($msg->alt ? 'alt+' : '') . ($msg->shift ? 'shift+' : '') . $base;
    }

    /**
     * The `KeyMsg` a canonical id ({@see parseChord()}, {@see pressId()})
     * describes — the reconstruction of a press from the wire's own shape,
     * so a chord-lane alias lands at handlers as if the shipped chord had
     * been typed. Null for ids outside the alphabet; {@see parseChord()}
     * only ever emits ids inside it, so this is a fail-closed guard, not a
     * user-facing door.
     */
    public static function keyMsgFromId(string $id): ?KeyMsg
    {
        $rest = $id;
        $ctrl = false;
        $alt = false;
        $shift = false;

        while (true) {
            if (!$ctrl && str_starts_with($rest, 'ctrl+')) {
                $ctrl = true;
                $rest = substr($rest, 5);
                continue;
            }
            if (!$alt && str_starts_with($rest, 'alt+')) {
                $alt = true;
                $rest = substr($rest, 4);
                continue;
            }
            if (!$shift && str_starts_with($rest, 'shift+')) {
                $shift = true;
                $rest = substr($rest, 6);
                continue;
            }
            break;
        }

        if ($rest === '') {
            return null;
        }

        $type = KeyType::tryFrom($rest);
        if ($type !== null && $type !== KeyType::Char) {
            return new KeyMsg($type, '', $alt, $ctrl, $shift);
        }

        return mb_strlen($rest) === 1
            ? new KeyMsg(KeyType::Char, $rest, $alt, $ctrl, $shift)
            : null;
    }
}
