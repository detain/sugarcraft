<?php

declare(strict_types=1);

/**
 * sugar-core/src/Keys/KeyBindings.php
 *
 * The shared binding-registry shape behind finding m27: an app's key map as a
 * value object — named actions, each owning a set of lowercase key tokens —
 * so a game or TUI consults ONE registry instead of hand-written character
 * comparisons per key gate, and a user override layer hangs off the registry
 * rather than off every call site.
 *
 * The wire tokenization is centralized because the raw stream is genuinely
 * ambiguous: InputReader decodes a bare space as KeyType::Space whose rune is
 * ' ' (legacy and kitty paths alike), while hand-built test fixtures and
 * sibling decoders emit KeyType::Char with rune ' ' too. Both normalize to
 * the token 'space'. Letters normalize to their lowercase rune — case was
 * always dropped at actuation time (m26: Caps Lock must not strand bindings).
 *
 * Modifier chords are NOT part of this vocabulary: matching looks only at the
 * key identity (type token or rune), preserving what per-site comparisons
 * did before adoption — a legacy 'q' gate fires on Ctrl+Q too, and a registry
 * that started demanding ctrl:false would silently narrow what a bare letter
 * press means on streams that carry the flag. Chord bindings are a honest
 * non-goal of v1, not a silent gap.
 *
 * The same token across two actions is legal and meaningful: dispatch decides
 * which action matters on a given screen, and one physical key actuating a
 * context-dependent action is normal keyboard design (space can begin one
 * screen and hard-drop another).
 */

namespace SugarCraft\Core\Keys;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\KeyReleaseMsg;
use SugarCraft\Core\Msg\KeyRepeatMsg;

/**
 * Immutable action→tokens registry: {@see withOverride()} replaces one
 * action's token list and returns a new registry, mirroring the
 * sugar-style/sugar-widgets with* convention.
 *
 * @immutable
 */
final class KeyBindings
{
    /**
     * Token each action id answers to by default. Apps layer user overrides
     * on top of {@see defaults()} — or (games with their own verbs) start
     * from {@see fromMap()} with the app's own table — so this map is one
     * single source of truth for the shared layout, and help text derived
     * from the registry and dispatch consult exactly the same words.
     *
     * @var array<string,list<string>>
     */
    private const DEFAULT_TOKENS = [
        // Text-editor primitives every candy-* shell shares.
        'cursor-left'     => ['left'],
        'cursor-right'    => ['right'],
        'delete-backward' => ['backspace'],
        'delete-forward'  => ['delete'],
        // Runnable-game verbs: one id per distinct press a game screen acts on.
        'quit'        => ['q'],
        'pause'       => ['p'],
        'restart'     => ['r'],
        'begin'       => ['space', 'enter'],
        'move-left'   => ['left'],
        'move-right'  => ['right'],
        'move-up'     => ['up'],
        'move-down'   => ['down'],
        'soft-drop'   => ['down'],
        'hard-drop'   => ['space'],
        'rotate-cw'   => ['x'],
        'rotate-ccw'  => ['z'],
        'hold'        => ['c'],
        'action-a'    => ['z'],
        'action-b'    => ['x'],
        'action-c'    => ['c'],
        'flap'        => ['space'],
        'defuse'      => ['f'],
        'flag'        => ['s'],
    ];

    /**
     * Terminal-friendly short labels for the named keys — language-neutral
     * glyphs/abbreviations. A localized label table would collide with the
     * translated verb columns the apps already ship: only the key column
     * derives from the registry, the verbs stay in the app's strings.
     *
     * @var array<string,string>
     */
    private const TOKEN_GLYPHS = [
        'left'      => '←',
        'right'     => '→',
        'up'        => '↑',
        'down'      => '↓',
        'space'     => 'spc',
        'enter'     => 'ent',
        'escape'    => 'esc',
        'backspace' => 'bs',
        'delete'    => 'del',
        'tab'       => 'tab',
    ];

    /**
     * @param array<string,list<string>> $actions action id => lowercase tokens
     */
    private function __construct(
        private readonly array $actions,
    ) {
    }

    /**
     * The shared default layout, built from {@see DEFAULT_TOKENS}.
     */
    public static function defaults(): self
    {
        return new self(self::DEFAULT_TOKENS);
    }

    /**
     * A registry from an explicit id=>tokens map (each list may be one token
     * or many). Unknown ids are not rejected — the registry does not know an
     * app's vocabulary, only its shape; an id nothing dispatches on is inert,
     * and an id an app does dispatch on is simply served.
     *
     * @param array<string,list<string>> $actions
     */
    public static function fromMap(array $actions): self
    {
        $normalized = [];
        foreach ($actions as $id => $tokens) {
            $normalized[(string) $id] = self::foldTokens($tokens);
        }

        return new self($normalized);
    }

    /**
     * The registry with one action's tokens REPLACED (not appended: an
     * override means "this action answers to exactly these keys now", the
     * shape a config loader dials).
     */
    public function withOverride(string $id, string ...$tokens): self
    {
        $actions = $this->actions;
        $actions[$id] = self::foldTokens($tokens);

        return new self($actions);
    }

    /**
     * True when $msg actuates $id: the message carries the shape of a press
     * (base KeyMsg or explicit KeyPressMsg — auto-releases and auto-repeats
     * carry the same key identity and must not double-fire a toggle, m11),
     * and one of $id's tokens equals the message's wire token.
     */
    public function matches(KeyMsg $msg, string $id): bool
    {
        if ($msg instanceof KeyReleaseMsg || $msg instanceof KeyRepeatMsg) {
            return false;
        }

        $tokens = $this->actions[$id] ?? [];
        if ($tokens === []) {
            return false;
        }

        return in_array(self::tokenOf($msg), $tokens, true);
    }

    /**
     * The wire token of a key message: lowercase rune for a printable char
     * (space rune folds to 'space' — the same physical key either way), the
     * enum value for named keys.
     */
    public static function tokenOf(KeyMsg $msg): string
    {
        if ($msg->type === KeyType::Char) {
            return $msg->rune === ' ' ? 'space' : strtolower($msg->rune);
        }

        return $msg->type->value;
    }

    /**
     * @return list<string> the tokens $id answers to (empty when unknown)
     */
    public function tokens(string $id): array
    {
        return $this->actions[$id] ?? [];
    }

    /**
     * The whole registry as an id=>tokens map — the shape a config writer
     * serializes and a pin asserts against (arrays are values in PHP; the
     * internal map cannot be mutated through the copy).
     *
     * @return array<string,list<string>>
     */
    public function map(): array
    {
        return $this->actions;
    }

    /**
     * Display label for one action: its tokens' short forms joined by a
     * space, arrows and space rendered as glyphs. This is the column help
     * cards and key strips are composed from — derived, never hardcoded, so
     * a user override lands in the UI truthfully without the app editing a
     * single string (m27's whole point).
     */
    public function label(string $id): string
    {
        return implode(' ', array_map(
            static fn(string $token): string => self::TOKEN_GLYPHS[$token] ?? $token,
            $this->tokens($id),
        ));
    }

    /**
     * @param array<array-key,string> $tokens
     *
     * @return list<string>
     */
    private static function foldTokens(array $tokens): array
    {
        return array_values(array_map(
            static fn(string $token): string => strtolower($token),
            $tokens,
        ));
    }
}
