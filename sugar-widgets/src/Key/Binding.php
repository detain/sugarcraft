<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Key;

use SugarCraft\Core\Keys\KeyBindings;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Widgets\Lang;

/**
 * A keybinding: a list of accepted key strings, an optional {@see Help}
 * label, and a disabled flag that suppresses both matching and help
 * display. Always test a press with {@see matches()}, never by comparing
 * `$keys` to {@see KeyMsg::string()} by hand: the two construction paths
 * match differently.
 *
 * A binding from {@see new()} matches when a key string equals
 * {@see KeyMsg::string()}. A binding from {@see chords()} — the kind
 * {@see KeyView::binding()} hands out — holds sugar-core KeyBindings
 * chords, parsed by `KeyBindings::parseChord()` and matched by
 * `KeyBindings::chordsMatch()`, so Shift+A (string `shift+a`) meets the
 * chord `a`, and a spelling `parseChord()` refuses throws there.
 *
 * Key strings are NORMALIZED and VALIDATED at construction: each is
 * trimmed, lowercased, run through the alias table, and checked against
 * the grammar {@see KeyMsg::string()} actually emits — an optional
 * fixed-order `ctrl+` `alt+` `shift+` prefix chain over a named key
 * (every {@see KeyType} value except `char`) or a single printable
 * character. `new Binding(['Tab'])` matches Tab; `new Binding(['nope'])`
 * throws at wiring time naming the grammar (the strict `in_array` era
 * made mis-spellings silently unmatchable forever).
 *
 * ```php
 * $up = Binding::new(['up', 'k'], new Help('↑/k', 'move up'));
 * if ($up->matches($keyMsg)) { ... }
 * ```
 */
final class Binding
{
    /**
     * Deliberate accepted spellings that rewrite to the canonical key
     * name before validation (documented implementer call: accept, don't
     * reject). ONE lookup table — `matches()` compares canonical strings,
     * so bindings and messages meet in the same namespace.
     */
    private const ALIASES = [
        // Deliberate, documented acceptance — one lookup table consulted
        // by BOTH ingress and matches(): legacy spellings stay alive
        // without ever duplicating the grammar. 'esc' is the universal
        // shorthand for KeyType::Escape ('escape').
        'pgdn' => 'pagedown',
        'pgup' => 'pageup',
        'esc'  => 'escape',
    ];

    /** Modifier prefixes in the exact order KeyMsg::string() emits them. */
    private const MODIFIER_ORDER = ['ctrl', 'alt', 'shift'];

    /** @var list<string> normalized, validated, deduplicated key strings */
    public readonly array $keys;

    /**
     * True only on a binding built by {@see chords()}: matches() then asks
     * sugar-core's KeyBindings rule instead of comparing KeyMsg::string().
     * Set once, inside chords(), before the instance escapes; every wither
     * carries it through {@see mutate()}.
     */
    private bool $chordMatched = false;

    /**
     * @deprecated Prefer {@see self::new()} — the house factory spells the
     *     same shape. Kept public (BC); removal at 2.0.
     *
     * @param list<string> $keys
     */
    public function __construct(
        array $keys,
        public readonly Help $help = new Help(),
        public readonly bool $disabled = false,
    ) {
        $this->keys = self::ingress($keys);
    }

    /**
     * Canonical factory. Mirrors charmbracelet/bubbles `key.NewBinding`.
     *
     * ```php
     * Binding::new(keys: ['ctrl+c'], help: new Help('ctrl+c', 'quit'));
     * Binding::new(keys: ['?'], help: new Help('?', 'help'), disabled: true);
     * ```
     *
     * @param list<string> $keys
     */
    public static function new(array $keys = [], Help $help = new Help(), bool $disabled = false): self
    {
        return new self($keys, $help, $disabled);
    }

    /**
     * Construct a disabled-from-the-start binding. Mirrors charmbracelet/
     * bubbles `key.WithDisabled`.
     *
     * @deprecated The static `with*` name collides with the immutable
     *     instance-verb convention (`withHelp`). Use
     *     `Binding::new($keys, $help, disabled: true)`. Removal at 2.0.
     *
     * @param list<string> $keys
     */
    public static function withDisabled(array $keys, Help $help = new Help()): self
    {
        return new self($keys, $help, true);
    }

    /**
     * A binding over sugar-core KeyBindings chords, matched by the
     * registry's own rule.
     *
     * {@see KeyView} builds its help bindings here, so a Binding taken from
     * the help footer answers a key press exactly as
     * `KeyBindings::matches()` does for the same chords: a Char rune `' '`
     * meets `space`, Shift over a letter meets the bare letter, and a key
     * release or auto-repeat meets nothing. A binding from {@see new()}
     * keeps the upstream rule (the key string must equal
     * {@see KeyMsg::string()}). Each chord is parsed by
     * `KeyBindings::parseChord()`, so a spelling the registry would refuse
     * (`shift+a`, `ctrl+ctrl+s`) throws here too; {@see withKeys()} on the
     * result parses the same way.
     *
     * @internal built by KeyView; bind keys through KeyBindings, not here.
     *
     * @param list<string> $chords
     */
    public static function chords(array $chords, Help $help = new Help(), bool $disabled = false): self
    {
        $canonical = [];
        foreach ($chords as $chord) {
            if (!\is_string($chord)) {
                throw new \InvalidArgumentException(
                    Lang::t('key.bad_binding_type', ['key' => \get_debug_type($chord)]),
                );
            }
            $parsed = KeyBindings::parseChord($chord);
            if (!\in_array($parsed, $canonical, true)) {
                $canonical[] = $parsed;
            }
        }
        $binding = new self($canonical, $help, $disabled);
        $binding->chordMatched = true;

        return $binding;
    }

    public function matches(KeyMsg $msg): bool
    {
        if ($this->disabled) {
            return false;
        }
        if ($this->chordMatched) {
            return KeyBindings::chordsMatch($msg, $this->keys);
        }
        return \in_array($msg->string(), $this->keys, true);
    }

    /**
     * Variadic top-level helper: `Binding::any($msg, $up, $down)`
     * returns true when `$msg` matches any of the supplied bindings.
     * Mirrors charmbracelet/bubbles `Matches(KeyMsg, ...Binding)`.
     */
    public static function any(KeyMsg $msg, self ...$bindings): bool
    {
        foreach ($bindings as $b) {
            if ($b->matches($msg)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Canonical key-list replacement (immutable). Mirrors charmbracelet/
     * bubbles `SetKeys` — the house name drops the `set` prefix.
     *
     * @param list<string> $keys
     */
    public function withKeys(array $keys): self
    {
        return $this->mutate(['keys' => $keys]);
    }

    /**
     * Replace the help label (immutable). Mirrors charmbracelet/bubbles `WithHelp`.
     */
    public function withHelp(string $key, string $desc): self
    {
        return $this->mutate(['help' => new Help($key, $desc)]);
    }

    /**
     * Flip the enabled state (immutable). `disable()` is the canonical
     * positive verb; `withEnabled()` is its named-polarity twin.
     */
    public function disable(bool $disabled = true): self
    {
        return $this->mutate(['disabled' => $disabled]);
    }

    /**
     * Canonical positive-sense enable/disable verb (immutable).
     */
    public function withEnabled(bool $on): self
    {
        return $this->mutate(['disabled' => !$on]);
    }

    /**
     * @deprecated The `set`-prefixed name suggests mutation; this class is
     *     immutable and returns a new instance. Use {@see withKeys()}.
     *     Kept as a BC alias for external callers. Removal at 2.0.
     *
     * @param list<string> $keys
     */
    public function setKeys(array $keys): self
    {
        return $this->withKeys($keys);
    }

    /**
     * Replace the help label. Mirrors charmbracelet/bubbles `SetHelp`.
     *
     * @deprecated The `set`-prefixed name suggests mutation; this class is
     *     immutable and returns a new instance. Use {@see withHelp()}.
     *     Kept as a BC alias for external callers. Removal at 2.0.
     */
    public function setHelp(string $key, string $desc): self
    {
        return $this->withHelp($key, $desc);
    }

    /**
     * `enabled()` returns the inverse of `disabled` for read ergonomics
     * and parity with upstream Bubbles (`Enabled()`).
     */
    public function enabled(): bool
    {
        return !$this->disabled;
    }

    /**
     * Mirror of {@see disable()} that takes the positive-sense flag.
     *
     * @deprecated The `set`-prefixed name suggests mutation; this class is
     *     immutable and returns a new instance. Use {@see withEnabled()}.
     *     Kept as a BC alias for external callers. Removal at 2.0.
     */
    public function setEnabled(bool $on): self
    {
        return $this->withEnabled($on);
    }

    /**
     * Drop the binding's keys (still keeps help / disabled). Bound
     * keys can be repopulated later with {@see withKeys()}; useful
     * when a Bubbles-style component wants to take an existing
     * binding out of rotation without losing its label.
     */
    public function unbind(): self
    {
        return $this->mutate(['keys' => []]);
    }

    /**
     * A new binding with $changes applied, built the way this one was: a
     * {@see chords()} binding stays chord-matched and parses its keys as
     * chords, any other binding runs the upstream grammar.
     *
     * @param array{keys?: array<array-key, mixed>, help?: Help, disabled?: bool} $changes
     */
    private function mutate(array $changes): self
    {
        $keys = $changes['keys'] ?? $this->keys;
        $help = $changes['help'] ?? $this->help;
        $disabled = $changes['disabled'] ?? $this->disabled;

        return $this->chordMatched
            ? self::chords($keys, $help, $disabled)
            : new self($keys, $help, $disabled);
    }

    // ─── ingress: normalize → validate → dedupe ──────────────────────

    /**
     * @param array<array-key, mixed> $keys
     * @return list<string>
     */
    private static function ingress(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            if (!\is_string($key)) {
                throw new \InvalidArgumentException(
                    Lang::t('key.bad_binding_type', ['key' => \get_debug_type($key)]),
                );
            }
            $normalized = self::normalize($key);
            self::validate($key, $normalized);
            if (!\in_array($normalized, $out, true)) {
                $out[] = $normalized;
            }
        }
        return $out;
    }

    private static function normalize(string $key): string
    {
        $key = \strtolower(\trim($key));
        return self::ALIASES[$key] ?? $key;
    }

    private static function validate(string $original, string $key): void
    {
        // Split off the fixed-order modifier chain; the remainder is the base.
        $modifiers = [];
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach (self::MODIFIER_ORDER as $position => $m) {
                if (\str_starts_with($key, $m . '+')) {
                    if (\in_array($m, $modifiers, true)) {
                        self::reject($original); // duplicate modifier
                    }
                    $modifiers[] = $position;
                    $key = \substr($key, \strlen($m) + 1);
                    $changed = true;
                    break;
                }
            }
        }
        // Modifier emission order is ctrl, alt, shift (KeyMsg::string()).
        $previous = -1;
        foreach ($modifiers as $position) {
            if ($position < $previous) {
                self::reject($original);
            }
            $previous = $position;
        }
        if (!self::baseIsValid($key)) {
            self::reject($original);
        }
    }

    /** A base is a named key (every KeyType value but `char`) or one printable rune. */
    private static function baseIsValid(string $base): bool
    {
        if ($base === '') {
            return false;
        }
        foreach (KeyType::cases() as $case) {
            if ($case->value === $base && $case !== KeyType::Char) {
                return true;
            }
        }
        return \mb_strlen($base, 'UTF-8') === 1
            && \mb_ord($base, 'UTF-8') !== false
            && \mb_ord($base, 'UTF-8') >= 32
            && \mb_ord($base, 'UTF-8') !== 127;
    }

    private static function reject(string $original): never
    {
        throw new \InvalidArgumentException(
            Lang::t('key.bad_binding', ['key' => $original]),
        );
    }
}
