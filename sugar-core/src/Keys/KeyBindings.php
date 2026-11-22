<?php

declare(strict_types=1);

/**
 * sugar-core/src/Keys/KeyBindings.php
 *
 * The shared binding-registry shape behind finding m27: an app's key map as a
 * value object — named actions, each owning a set of lowercase key chords —
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
 * Modifier chords ARE part of this vocabulary (P2-1 removed the v1 non-goal):
 * a binding token is `[ctrl+][alt+][shift+]<base>`, where the base is a named
 * key (every {@see KeyType} value except `char`) or one printable character.
 * Matching compares the whole chord, so `ctrl+s` fires only with Ctrl held
 * and a bare `s` no longer fires on Ctrl+S or Alt+S. That is the point of
 * chords: under the v1 fold, a game binding `c` (hold) and `ctrl+c` (quit)
 * actuated hold on the interrupt (candy-tetris-034), and `tab` beside
 * `shift+tab` could not be told apart. Shift is folded away from printable
 * characters on both sides: the rune already carries the shifted glyph and
 * case is dropped (m26), so `shift+a` is rejected as a token rather than
 * accepted as a binding no key press could ever produce.
 *
 * The same token across two actions is legal and meaningful: dispatch decides
 * which action matters on a given screen, and one physical key actuating a
 * context-dependent action is normal keyboard design (space can begin one
 * screen and hard-drop another).
 */

namespace SugarCraft\Core\Keys;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Lang;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\KeyReleaseMsg;
use SugarCraft\Core\Msg\KeyRepeatMsg;
use SugarCraft\Core\Util\Json;

/**
 * Immutable action→chords registry with optional per-action help text.
 * Every with*() and bind() returns a new registry through {@see mutate()},
 * mirroring the sugar-style/sugar-widgets with* convention. Mirrors
 * charmbracelet/bubbles key.Binding (keys plus help) at registry scope.
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
     * Accepted shorthand spellings of named keys, rewritten before
     * validation — the same three sugar-widgets `Key\Binding` accepts, so a
     * user file written for either surface reads the same.
     *
     * @var array<string,string>
     */
    private const ALIASES = [
        'esc'  => 'escape',
        'pgup' => 'pageup',
        'pgdn' => 'pagedown',
    ];

    /** Modifier prefixes in the order {@see KeyMsg::string()} emits them. */
    private const MODIFIERS = ['ctrl', 'alt', 'shift'];

    /**
     * Longest chord accepted, in bytes. `ctrl+alt+shift+kp_separator` is 27;
     * anything past this is not a key name, and a help column cannot absorb
     * it without lying about what the key is.
     */
    private const MAX_CHORD_BYTES = 32;

    /**
     * Largest override file {@see fromFile()} reads. A key map is a few
     * hundred bytes; the cap keeps a misplaced path (a log, a dump) from
     * being slurped into memory before the shape check can refuse it.
     */
    private const MAX_FILE_BYTES = 1048576;

    /**
     * @param array<string,list<string>> $actions action id => canonical chords
     * @param array<string,string>       $help    action id => help description
     */
    private function __construct(
        private readonly array $actions,
        private readonly array $help = [],
    ) {
    }

    /**
     * An empty registry — the root for an app that declares its own
     * vocabulary through {@see bind()}.
     */
    public static function new(): self
    {
        return new self([]);
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
     * and an id an app does dispatch on is simply served. Every token is
     * parsed by {@see parseChord()}, so a misspelled key throws at wiring
     * time instead of binding nothing.
     *
     * @param array<array-key,list<string>> $actions
     *
     * @throws \InvalidArgumentException when a token is not a valid chord
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
     * Fold a user override file onto $base (an empty registry when null).
     *
     * The file is a JSON object of action id => chord list (or one chord
     * string); each entry REPLACES that action's chords, the same contract
     * as {@see withOverride()}, and an empty list unbinds the action. A
     * missing file is "no overrides": $base comes back unchanged, so an app
     * that passes its defaults as $base can always point at its XDG slot.
     * With no $base the result holds only the actions the file names, and a
     * missing file yields an empty registry that matches nothing, so an app
     * with defaults must pass them. Anything else wrong — a slot that is
     * not a plain file (symlink, FIFO, socket: refused by lstat, never
     * followed or opened), an oversized file, malformed JSON, a bad shape or
     * a token {@see parseChord()} rejects — throws, and nothing is applied:
     * a typo must not half-load and strand a binding mid-session.
     *
     * @throws \RuntimeException naming the path and the problem
     */
    public static function fromFile(string $path, ?self $base = null): self
    {
        $base ??= self::new();

        $slot = @lstat($path);
        if ($slot === false) {
            return $base;
        }
        if (($slot['mode'] & 0o170000) !== 0o100000) {
            throw new \RuntimeException(Lang::t('keys.file_not_regular', ['path' => $path]));
        }
        if ($slot['size'] > self::MAX_FILE_BYTES) {
            throw new \RuntimeException(Lang::t('keys.file_too_large', [
                'path' => $path,
                'max'  => self::MAX_FILE_BYTES,
            ]));
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException(Lang::t('keys.file_unreadable', ['path' => $path]));
        }

        try {
            $entries = Json::decodeArray($raw);
        } catch (\JsonException | \RuntimeException $e) {
            throw new \RuntimeException(
                Lang::t('keys.file_bad_json', ['path' => $path, 'reason' => $e->getMessage()]),
                0,
                $e,
            );
        }

        if ($entries !== [] && array_is_list($entries)) {
            throw new \RuntimeException(Lang::t('keys.file_not_object', ['path' => $path]));
        }

        $overrides = [];
        foreach ($entries as $id => $tokens) {
            $id = (string) $id;
            if ($id === '') {
                throw new \RuntimeException(Lang::t('keys.file_empty_action', ['path' => $path]));
            }
            if (\is_string($tokens)) {
                $tokens = [$tokens];
            }
            if (!\is_array($tokens) || !array_is_list($tokens)) {
                throw new \RuntimeException(Lang::t('keys.file_bad_tokens', ['path' => $path, 'action' => self::visible($id)]));
            }
            $chords = [];
            foreach ($tokens as $token) {
                if (!\is_string($token)) {
                    throw new \RuntimeException(Lang::t('keys.file_bad_tokens', ['path' => $path, 'action' => self::visible($id)]));
                }
                try {
                    $chords[] = self::parseChord($token);
                } catch (\InvalidArgumentException $e) {
                    throw new \RuntimeException(
                        Lang::t('keys.file_bad_chord', ['path' => $path, 'action' => self::visible($id), 'reason' => $e->getMessage()]),
                        0,
                        $e,
                    );
                }
            }
            $overrides[$id] = array_values(array_unique($chords));
        }

        // Assigned one by one, not array_merge(): PHP stores a numeric-looking
        // id such as '1' as an int key, which array_merge() would renumber
        // into a new phantom action instead of replacing the base's.
        $actions = $base->actions;
        foreach ($overrides as $id => $chords) {
            $actions[$id] = $chords;
        }

        return $base->mutate(['actions' => $actions]);
    }

    /**
     * Parse one chord into its canonical spelling: lowercase, aliases
     * resolved, modifiers reordered to ctrl, alt, shift (any input order is
     * accepted), and a lone space folded to `space`.
     *
     * Rejected: an empty token; control bytes or an over-long token; an
     * unknown modifier or key name (`meta+x`, `spce`); a repeated modifier
     * (`ctrl+ctrl+s`); a dangling modifier (`ctrl+`); the internal `char`
     * type name; and `shift+` over a printable character, which the rune
     * already expresses (`A`, `!`) and case folding would erase.
     *
     * @throws \InvalidArgumentException naming the chord and the grammar
     */
    public static function parseChord(string $chord): string
    {
        if ($chord === ' ') {
            return 'space';
        }
        if ($chord === ''
            || \strlen($chord) > self::MAX_CHORD_BYTES
            || preg_match('//u', $chord) !== 1
            || preg_match('/[\p{C}\s]/u', $chord) === 1
        ) {
            self::reject($chord);
        }

        [$held, $rest] = self::split(mb_strtolower($chord, 'UTF-8'));
        if (\count($held) !== \count(array_unique($held))) {
            self::reject($chord);
        }

        $base = self::ALIASES[$rest] ?? $rest;
        $named = $base !== KeyType::Char->value && KeyType::tryFrom($base) !== null;
        if (!$named && mb_strlen($base, 'UTF-8') !== 1) {
            self::reject($chord);
        }
        if (!$named && \in_array('shift', $held, true)) {
            self::reject($chord);
        }

        return self::compose($held, $base);
    }

    /**
     * The registry with one action's tokens REPLACED (not appended: an
     * override means "this action answers to exactly these keys now", the
     * shape a config loader dials). No tokens unbinds the action.
     *
     * @throws \InvalidArgumentException when a token is not a valid chord
     */
    public function withOverride(string $id, string ...$tokens): self
    {
        $actions = $this->actions;
        $actions[$id] = self::foldTokens($tokens);

        return $this->mutate(['actions' => $actions]);
    }

    /**
     * Several {@see withOverride()} calls at once: each id in $map has its
     * chords replaced; ids not named keep theirs. Validated as a whole
     * before anything is applied.
     *
     * @param array<array-key,list<string>|string> $map
     *
     * @throws \InvalidArgumentException when a token is not a valid chord
     */
    public function withOverrides(array $map): self
    {
        $actions = $this->actions;
        foreach ($map as $id => $tokens) {
            $actions[(string) $id] = self::foldTokens(\is_string($tokens) ? [$tokens] : $tokens);
        }

        return $this->mutate(['actions' => $actions]);
    }

    /**
     * Declare (or redeclare) one action: its chords and, optionally, the
     * help description a generated help view shows beside the key column.
     * The description is the app's own (usually translated) text; the key
     * column is always derived from the chords, never written by hand.
     *
     * @param list<string>|string $keys
     *
     * @throws \InvalidArgumentException when a token is not a valid chord
     */
    public function bind(string $id, array|string $keys, string $help = ''): self
    {
        $actions = $this->actions;
        $actions[$id] = self::foldTokens(\is_string($keys) ? [$keys] : $keys);
        $descriptions = $this->help;
        if ($help !== '') {
            $descriptions[$id] = $help;
        }

        return $this->mutate(['actions' => $actions, 'help' => $descriptions]);
    }

    /**
     * The registry with $id's help description replaced (empty clears it).
     * Chords are untouched, so a user override file and a locale switch
     * compose in either order.
     */
    public function withHelp(string $id, string $help): self
    {
        $descriptions = $this->help;
        if ($help === '') {
            unset($descriptions[$id]);
        } else {
            $descriptions[$id] = $help;
        }

        return $this->mutate(['help' => $descriptions]);
    }

    /**
     * True when $msg actuates $id: the message carries the shape of a press
     * (base KeyMsg or explicit KeyPressMsg — auto-releases and auto-repeats
     * carry the same key identity and must not double-fire a toggle, m11),
     * and one of $id's chords equals the message's chord ({@see chordOf()}).
     * Any other Msg answers false, so an update() can dispatch on the bare
     * message without its own instanceof gate.
     */
    public function matches(Msg $msg, string $id): bool
    {
        if (!$msg instanceof KeyMsg || $msg instanceof KeyReleaseMsg || $msg instanceof KeyRepeatMsg) {
            return false;
        }

        $tokens = $this->actions[$id] ?? [];
        if ($tokens === []) {
            return false;
        }

        return \in_array(self::chordOf($msg), $tokens, true);
    }

    /**
     * The first action, in declaration order, that $msg actuates — or null.
     * The dispatch shape for a screen that owns every action in the
     * registry; a screen sharing one key between actions dispatches through
     * {@see matches()} in its own priority order instead.
     */
    public function actionFor(Msg $msg): ?string
    {
        foreach ($this->actions() as $id) {
            if ($this->matches($msg, $id)) {
                return $id;
            }
        }

        return null;
    }

    /**
     * The wire token of a key message WITHOUT modifiers: lowercase rune for
     * a printable char (space rune folds to 'space' — the same physical key
     * either way), the enum value for named keys.
     */
    public static function tokenOf(KeyMsg $msg): string
    {
        if ($msg->type === KeyType::Char) {
            return $msg->rune === ' ' ? 'space' : mb_strtolower($msg->rune, 'UTF-8');
        }

        return $msg->type->value;
    }

    /**
     * The canonical chord a key message presents: {@see tokenOf()} behind
     * its held modifiers in ctrl, alt, shift order. Shift is dropped over a
     * printable character (the rune already carries it, see the class doc),
     * so the spelling is always one {@see parseChord()} accepts.
     */
    public static function chordOf(KeyMsg $msg): string
    {
        $base = self::tokenOf($msg);
        $held = [];
        if ($msg->ctrl) {
            $held[] = 'ctrl';
        }
        if ($msg->alt) {
            $held[] = 'alt';
        }
        if ($msg->shift && ($msg->type !== KeyType::Char || $base === 'space')) {
            $held[] = 'shift';
        }

        return self::compose($held, $base);
    }

    /**
     * @return list<string> the canonical chords $id answers to (empty when unknown)
     */
    public function tokens(string $id): array
    {
        return $this->actions[$id] ?? [];
    }

    /**
     * @return list<string> every action id, in declaration order
     */
    public function actions(): array
    {
        // A numeric-looking id is an int array key; hand it back as the string
        // it was declared as.
        return array_map(strval(...), array_keys($this->actions));
    }

    /**
     * The help description declared for $id ('' when none was given).
     */
    public function help(string $id): string
    {
        return $this->help[$id] ?? '';
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
     * Display label for one action: its chords' short forms joined by
     * $separator, named-key bases rendered as glyphs behind their modifier
     * prefix (`ctrl+←`, `shift+tab`). This is the column help cards and key
     * strips are composed from — derived, never hardcoded, so a user
     * override lands in the UI truthfully without the app editing a single
     * string (m27's whole point).
     */
    public function label(string $id, string $separator = ' '): string
    {
        return implode($separator, array_map(
            static fn(string $token): string => self::labelOf($token),
            $this->tokens($id),
        ));
    }

    /**
     * The short display form of one canonical chord.
     */
    public static function labelOf(string $chord): string
    {
        [$held, $base] = self::split($chord);
        $glyph = self::TOKEN_GLYPHS[$base] ?? $base;

        return $held === [] ? $glyph : implode('+', $held) . '+' . $glyph;
    }

    /**
     * @param array<string,mixed> $changes
     */
    private function mutate(array $changes): self
    {
        return new self(...array_merge(['actions' => $this->actions, 'help' => $this->help], $changes));
    }

    /**
     * Peel the leading modifier prefixes off a lowercase chord, in input
     * order and with repeats kept (the caller judges them). A prefix is
     * consumed only when something follows it, so `ctrl++` is Ctrl plus the
     * `+` key and a dangling `ctrl+` stays whole for the base check to refuse.
     *
     * @return array{0: list<string>, 1: string}
     */
    private static function split(string $chord): array
    {
        $held = [];
        do {
            $consumed = false;
            foreach (self::MODIFIERS as $modifier) {
                $prefix = $modifier . '+';
                if (str_starts_with($chord, $prefix) && \strlen($chord) > \strlen($prefix)) {
                    $held[] = $modifier;
                    $chord = substr($chord, \strlen($prefix));
                    $consumed = true;
                    break;
                }
            }
        } while ($consumed);

        return [$held, $chord];
    }

    /**
     * @param list<string> $held
     */
    private static function compose(array $held, string $base): string
    {
        $ordered = array_values(array_filter(
            self::MODIFIERS,
            static fn(string $modifier): bool => \in_array($modifier, $held, true),
        ));

        return $ordered === [] ? $base : implode('+', $ordered) . '+' . $base;
    }

    /**
     * @param array<array-key,mixed> $tokens
     *
     * @return list<string>
     */
    private static function foldTokens(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $token) {
            if (!\is_string($token)) {
                throw new \InvalidArgumentException(
                    Lang::t('keys.bad_chord_type', ['type' => get_debug_type($token)]),
                );
            }
            $chord = self::parseChord($token);
            if (!\in_array($chord, $out, true)) {
                $out[] = $chord;
            }
        }

        return $out;
    }

    private static function reject(string $chord): never
    {
        throw new \InvalidArgumentException(Lang::t('keys.bad_chord', ['chord' => self::visible($chord)]));
    }

    /**
     * A file-sourced chord or action name made safe to print: the message
     * reaches a terminal (stderr notice, error frame), so raw control bytes
     * and broken UTF-8 are escaped and an over-long value is cut short.
     */
    private static function visible(string $text): string
    {
        if (preg_match('//u', $text) !== 1) {
            return '0x' . bin2hex(substr($text, 0, self::MAX_CHORD_BYTES));
        }
        $shown = (string) preg_replace_callback(
            '/\p{C}/u',
            static fn(array $m): string => \sprintf('\\u{%04x}', mb_ord($m[0], 'UTF-8')),
            mb_substr($text, 0, self::MAX_CHORD_BYTES, 'UTF-8'),
        );

        return mb_strlen($text, 'UTF-8') > self::MAX_CHORD_BYTES ? $shown . '…' : $shown;
    }
}
