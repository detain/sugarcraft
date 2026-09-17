<?php

declare(strict_types=1);

namespace SugarCraft\Forms;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * Per-widget key-override maps (E736 plan 5.4, round 89).
 *
 * Lets a host rebind a keystroke that the input domain already knows how to
 * handle to ANY other action of the same widget's registry — e.g.
 * `['ctrl+u' => 'move_end', 'f1' => 'noop']`. The map is consulted BEFORE the
 * vim branch and BEFORE the widget's built-in matches, so an override always
 * wins; an unbound key falls through untouched.
 *
 * Upstream `charmbracelet/bubbles.textinput` has no key remapping (bindings
 * are hard-coded switch arms) — this is a SugarCraft extension; the action
 * vocabulary mirrors the primitives it dispatches, not an upstream type.
 *
 * Parse-don't-validate: the door is {@see withKeyOverrides()} itself. Spellings
 * are canonicalised and actions checked against the widget's registry at
 * set time, so the hot path (`update()`) only performs one array lookup on an
 * already-trusted `array<string,string>`. Fail-loud: an unknown spelling, an
 * unknown action, or two spellings that collapse onto the same canonical form
 * throw at set time — a half-accepted map silently losing a binding is the
 * failure mode this design refuses.
 *
 * Scope law (5.4): overrides live on the INPUT DOMAIN (TextInput / TextArea
 * widgets, reached through the Input and Text field pass-throughs), never on
 * Form — form-level navigation belongs to {@see \SugarCraft\Forms\Form::withKeys()}.
 *
 * Requires the using class to expose `private mutate(?array $keyOverrides = null, ...) : self`
 * plus a `public readonly array $keyOverrides` constructor slot, and to
 * implement the per-widget action registry.
 */
trait HasKeyOverrides
{
    /** Move the caret to the start (TextInput: buffer start; TextArea: column 0 of the caret row). */
    public const OVERRIDE_MOVE_START = 'move_start';

    /** Move the caret to the end (TextInput: buffer end; TextArea: end of the caret row). */
    public const OVERRIDE_MOVE_END = 'move_end';

    /** One cell left. */
    public const OVERRIDE_MOVE_LEFT = 'move_left';

    /** One cell right. */
    public const OVERRIDE_MOVE_RIGHT = 'move_right';

    /** Delete the cell before the caret. */
    public const OVERRIDE_BACKSPACE = 'backspace';

    /** Delete the cell under the caret. */
    public const OVERRIDE_DELETE_FORWARD = 'delete_forward';

    /** Delete from the caret backwards (TextInput: buffer start; TextArea: row start). */
    public const OVERRIDE_DELETE_TO_START = 'delete_to_start';

    /** Delete from the caret forwards (TextInput: buffer end; TextArea: row end). */
    public const OVERRIDE_DELETE_TO_END = 'delete_to_end';

    /** Recall the previous history entry (TextInput only — TextArea has no history). */
    public const OVERRIDE_HISTORY_UP = 'history_up';

    /** Recall the next history entry (TextInput only — TextArea has no history). */
    public const OVERRIDE_HISTORY_DOWN = 'history_down';

    /** Swallow the keystroke and do nothing. */
    public const OVERRIDE_NOOP = 'noop';

    /**
     * Replace the binding map (canonical spellings documented on
     * {@see parseKeyOverrides()}). The widget's stored `keyOverrides` stays
     * the parsed canonical form; consult it with {@see keyOverrides()}.
     *
     * @param array<string,string> $map spelling => action, e.g. ['ctrl+u' => self::OVERRIDE_MOVE_END]
     *
     * @throws \InvalidArgumentException on an unknown spelling, an unknown action, or a collision
     */
    public function withKeyOverrides(array $map): static
    {
        return $this->mutate(keyOverrides: self::parseKeyOverrides($map, static::keyOverrideActions()));
    }

    /**
     * The parsed canonical map: `['ctrl+u' => 'move_end', ...]`. Empty (default)
     * means every key falls through to the widget's built-in handling.
     *
     * @return array<string,string>
     */
    public function keyOverrides(): array
    {
        return $this->keyOverrides;
    }

    /**
     * The override vocabulary this widget implements — registry constants only,
     * each backed by a real primitive. The {@see parseKeyOverrides()} door
     * checks every requested action against exactly this list.
     *
     * @return list<string>
     */
    abstract protected static function keyOverrideActions(): array;

    /**
     * Execute a parsed override action. The action string is trusted (it went
     * through the door); an unmapped constant is a developer bug, not user
     * input, so it fails loudly.
     *
     * @return array{0:Model, 1:?\Closure}
     */
    abstract protected function applyKeyOverride(string $action): array;

    /**
     * Canonicalise + validate a user-supplied map at the boundary.
     *
     * Spellings are `mod+...+key` with lower-case parts, modifiers from
     * {ctrl, alt, shift} in canonical order (order in the source spelling does
     * not matter — 'ctrl+alt+x' and 'alt+ctrl+x' collapse to one form, which
     * is why a duplicate is an error rather than a silent overwrite), and a
     * key part that is either a single character, or one of:
     * enter, escape (esc), tab, backspace (bksp), space, delete (del), insert,
     * home, end, pageup (pgup), pagedown (pgdn), up, down, left, right,
     * or an F-key `f1`..`f35`.
     *
     * @param array<string,string> $map
     * @param list<string>         $supported registry of the calling widget
     *
     * @return array<string,string> canonical spelling => action
     *
     * @throws \InvalidArgumentException fail-loud door
     */
    private static function parseKeyOverrides(array $map, array $supported): array
    {
        $parsed = [];
        foreach ($map as $spelling => $action) {
            if (!\is_string($spelling) || !\is_string($action)) {
                throw new \InvalidArgumentException(Lang::t('keys.override_shape'));
            }
            if (!\in_array($action, $supported, true)) {
                throw new \InvalidArgumentException(Lang::t('keys.override_unknown_action', [
                    'action' => $action,
                ]));
            }
            $canonical = self::canonicalOverrideSpelling($spelling);
            if (\array_key_exists($canonical, $parsed)) {
                throw new \InvalidArgumentException(Lang::t('keys.override_duplicate', [
                    'key' => $canonical,
                ]));
            }
            $parsed[$canonical] = $action;
        }

        return $parsed;
    }

    /**
     * @throws \InvalidArgumentException on any unparseable part
     */
    private static function canonicalOverrideSpelling(string $spelling): string
    {
        $parts  = \explode('+', \strtolower(\trim($spelling)));
        $key    = self::normalizeOverrideKey((string) \array_pop($parts));
        $mods   = [];
        $failed = $key === null;
        foreach ($parts as $mod) {
            if (!\in_array($mod, self::OVERRIDE_MOD_ORDER, true) || \in_array($mod, $mods, true)) {
                $failed = true;

                continue;
            }
            $mods[] = $mod;
        }
        if ($failed) {
            throw new \InvalidArgumentException(Lang::t('keys.override_unknown_key', [
                'key' => $spelling,
            ]));
        }
        // Canonical modifier order regardless of how the author typed them, so
        // 'alt+ctrl+x' and 'ctrl+alt+x' are the same binding (hence the
        // duplicate-collision guard upstream).
        \usort($mods, static fn (string $a, string $b): int => \array_search($a, self::OVERRIDE_MOD_ORDER, true) <=> \array_search($b, self::OVERRIDE_MOD_ORDER, true));

        return $mods === [] ? $key : \implode('+', [...$mods, $key]);
    }

    /** Canonical modifier order for spellings. */
    private const OVERRIDE_MOD_ORDER = ['ctrl', 'alt', 'shift'];

    /**
     * Canonical name of a key part, folding the documented aliases
     * (esc/bksp/del/pgup/pgdn) so a stored spelling and a live keystroke
     * computed by {@see keyOverrideSpelling()} always meet on the same form.
     * Single characters and f1..f35 pass through as typed.
     */
    private static function normalizeOverrideKey(string $key): ?string
    {
        $aliases = [
            'esc' => 'escape', 'bksp' => 'backspace', 'del' => 'delete',
            'pgup' => 'pageup', 'pgdn' => 'pagedown',
        ];
        if (isset($aliases[$key])) {
            return $aliases[$key];
        }
        if (\mb_strlen($key, 'UTF-8') === 1) {
            return $key;
        }
        if (\preg_match('/^f(?:[1-9]|[12][0-9]|3[0-5])$/', $key) === 1) {
            return $key;
        }
        if (\in_array($key, [
            'enter', 'escape', 'tab', 'backspace', 'space', 'delete', 'insert',
            'home', 'end', 'pageup', 'pagedown', 'up', 'down', 'left', 'right',
        ], true)) {
            return $key;
        }

        return null;
    }

    /**
     * Canonical spelling of a live keystroke, or null when the key is not
     * addressable by spelling (multi-codepoint runes, unmodelled types).
     * Hot path: a single built match — no allocation for the common case? It
     * does build one small string; callers gate on a non-empty map first so
     * the default shape costs an array-is-empty check only.
     */
    private function keyOverrideSpelling(KeyMsg $msg): ?string
    {
        $key = match (true) {
            $msg->type === KeyType::Char => \mb_strlen($msg->rune, 'UTF-8') === 1 ? $msg->rune : null,
            $msg->type === KeyType::Space => 'space',
            $msg->type === KeyType::Enter => 'enter',
            $msg->type === KeyType::Escape => 'escape',
            $msg->type === KeyType::Tab => 'tab',
            $msg->type === KeyType::Backspace => 'backspace',
            $msg->type === KeyType::Delete => 'delete',
            $msg->type === KeyType::Insert => 'insert',
            $msg->type === KeyType::Home => 'home',
            $msg->type === KeyType::End => 'end',
            $msg->type === KeyType::PageUp => 'pageup',
            $msg->type === KeyType::PageDown => 'pagedown',
            $msg->type === KeyType::Up => 'up',
            $msg->type === KeyType::Down => 'down',
            $msg->type === KeyType::Left => 'left',
            $msg->type === KeyType::Right => 'right',
            \preg_match('/^f\d+$/', $msg->type->value) === 1 => $msg->type->value,
            default => null,
        };
        if ($key === null) {
            return null;
        }
        $mods = [];
        if ($msg->ctrl) {
            $mods[] = 'ctrl';
        }
        if ($msg->alt) {
            $mods[] = 'alt';
        }
        if ($msg->shift) {
            $mods[] = 'shift';
        }

        return $mods === [] ? $key : \implode('+', [...$mods, $key]);
    }

    /**
     * The override action bound to this keystroke, or null when the widget has
     * no binding for it. Called by update() BEFORE vim/built-in handling.
     */
    private function keyOverrideAction(KeyMsg $msg): ?string
    {
        if ($this->keyOverrides === []) {
            return null;
        }
        $spelling = $this->keyOverrideSpelling($msg);

        return $spelling === null ? null : ($this->keyOverrides[$spelling] ?? null);
    }
}
