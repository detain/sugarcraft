<?php

declare(strict_types=1);

namespace SugarCraft\Code\Config\Settings\Validator;

use SugarCraft\Code\Commands\KeyBinding;
use SugarCraft\Code\Commands\KeyBindingRegistry;
use SugarCraft\Code\Config\Settings\SettingValidator;

/**
 * The `keybindings` map: binding id => replacement chord, or null to UNBIND
 * that binding (plan_comparison TUI-4b — the row stops claiming its chord and
 * the /keys reference shows it as `keys.unbound`).
 *
 * IDS ARE CHECKED AGAINST THE REGISTRY, not a snapshot: a renamed row must
 * refuse the stale override at the next save rather than park it under an id
 * nothing declares. THE CHORD GRAMMAR MIRRORS
 * {@see \SugarCraft\Code\Commands\KeyBinding::parseChord()} deliberately: a
 * value must name ONE pressable chord carrying a modifier (`Ctrl+<key>`,
 * `Alt+Enter`, `Shift+Tab`, `Ctrl+Space`) or standing alone as a function key
 * (`F10`). Bare printable keys are refused because overriding TO them would
 * steal ordinary typing; sequences, ranges, and mouse shapes are refused
 * because the wire never reports them as one press. Plan_comparison TUI-4c
 * widened this from the pre-4c bare-`Ctrl+<letter>`-only grammar.
 *
 * THE TARGET MUST BE ALIASABLE TOO: dispatch aliases the override chord onto
 * the row's shipped chord, so a row shipped as a two-key sequence or a range
 * has no single chord to land on and is refused.
 *
 * COMMAND BINDINGS (plan_comparison C-EXT-5) are the ids that do NOT name a
 * shipped row: a `command:<name>` entry binds a slash command to its own
 * chord, dispatched by {@see KeyBindingRegistry::commandFor()} before the
 * alias funnel, so its name is checked against the COMMAND GRAMMAR below (one
 * optional bundle or MCP colon, `/`-separated segments — the grammar
 * `CommandRegistry` accepts for registrable names, mirrored here deliberately
 * and pinned equal by test) and no aliasability applies: the binding runs
 * `/<name>` itself, it never stands in for another row's chord.
 */
final class KeyBindingsValidator implements SettingValidator
{
    /**
     * The grammar a `command:<name>` binding id must carry after the prefix.
     * MIRRORS `CommandSpec::NAME_PATTERN` — the same rule that gates which
     * names reach the command registry at all — because a binding for a name
     * that cannot exist is a dead chord; the equality of the two literals is
     * pinned by `KeyBindingCommandBindingTest` so a grammar widened on one
     * side reddens the other's test rather than drifting.
     */
    private const COMMAND_NAME_PATTERN = '/^(?:[A-Za-z0-9][A-Za-z0-9_-]*:)?[A-Za-z0-9][A-Za-z0-9_-]*(\/[A-Za-z0-9][A-Za-z0-9_-]*)*$/';

    private function __construct()
    {
    }

    public static function new(): self
    {
        return new self();
    }

    public function validate(mixed $value, array $all = []): ?string
    {
        if ($value === null) {
            return null;
        }

        // An empty map is the shipped bindings — and array_is_list([]) is
        // TRUE, so the list test below must not see it.
        if ($value === []) {
            return null;
        }

        // array_is_list() is the JSON-array test: `{}` decodes to [] and is a
        // fine (empty) map, `["chat.palette"]` decodes to a list and names no
        // ids at all.
        if (!\is_array($value) || array_is_list($value)) {
            return 'must be an object mapping binding ids to chords, e.g. {"shell.palette": "Ctrl+B"}';
        }

        foreach ($value as $id => $chord) {
            if (!\is_string($id) || trim($id) === '') {
                return 'binding ids must be non-empty strings';
            }

            // A `command:<name>` id (plan_comparison C-EXT-5) names no
            // shipped row BY DESIGN — it is its own dispatch, checked
            // against the command grammar and the chord grammar below, and
            // never against byId()/aliasability (there is no row to alias).
            if (str_starts_with($id, KeyBindingRegistry::COMMAND_BINDING_PREFIX)) {
                $name = substr($id, strlen(KeyBindingRegistry::COMMAND_BINDING_PREFIX));
                if (preg_match(self::COMMAND_NAME_PATTERN, $name) !== 1) {
                    return "'{$id}' is not a bindable command name (letters, digits, '-' and '_', with one optional bundle or MCP colon)";
                }

                if ($chord === null) {
                    continue; // a "unbound" command binding claims no chord — inert, as asked
                }

                $pressed = \is_string($chord) ? KeyBinding::parseChord($chord) : null;
                if ($pressed === null || !self::isAssignableChord($pressed)) {
                    return "the override for '{$id}' must be a chord of the form Ctrl+<key> (or Alt+<key>, Shift+<key>, Ctrl+<named key>, F10…)";
                }

                continue;
            }

            if (KeyBindingRegistry::byId($id) === null) {
                return "'{$id}' is not a known binding (the /keys reference lists them)";
            }

            // A null value UNBINDS the binding (plan_comparison TUI-4b): the
            // id check above is the whole contract — unknown ids still stop
            // the launch, known ids park in the suppression set.
            if ($chord === null) {
                continue;
            }

            $pressed = \is_string($chord) ? KeyBinding::parseChord($chord) : null;
            if ($pressed === null || !self::isAssignableChord($pressed)) {
                return "the override for '{$id}' must be a chord of the form Ctrl+<key> (or Alt+<key>, Shift+<key>, Ctrl+<named key>, F10…)";
            }

            // The alias lands on the row's SHIPPED chord
            // (KeyBindingRegistry::aliasPress()); a row shipped as `Esc Esc`
            // or `Ctrl+X b` has no single chord to land on, so honouring it
            // is impossible.
            if (KeyBindingRegistry::byId($id)?->chord() === null) {
                return "the override for '{$id}' cannot be honoured: its shipped chord is not a single pressable chord (Ctrl+<key>, Alt+<key>, a named key, F10…) to alias";
            }
        }

        return null;
    }

    /**
     * Whether a PARSED chord id may be assigned as an override value: it
     * carries a modifier (every parsed id with a modifier holds a `+`), or
     * it is a function key. Bare printable runes and bare named keys (`Tab`,
     * `Enter`, `Esc`) are refused — overriding TO them would steal ordinary
     * typing wholesale.
     */
    private static function isAssignableChord(string $id): bool
    {
        return str_contains($id, '+') || preg_match('/^f[0-9]{1,2}$/', $id) === 1;
    }

    /**
     * Rows WARNING that a (validated) override chord collides with another
     * binding's SHIPPED chord — the rv6 follow-up to ISS-047's alias funnel.
     *
     * WHY A WARNING AND NOT A REFUSAL: the dispatch is correct in this shape.
     * `KeyBindingRegistry::aliasPress()` translates the pressed chord to the
     * override target's shipped chord BEFORE any claim decision, so the
     * user's chosen chord really does run the chosen binding. What no code
     * fixes is the OTHER row: the binding whose shipped chord was taken stops
     * answering at its own key — silently, because the alias maps have one
     * winner per chord (declaration order). That is a consequence to SEE at
     * launch, not a config to reject; the user may want exactly this trade.
     *
     * A victim the user ALSO re-chorded is not named: its effective chord is
     * no longer the stolen chord, so nothing is silently lost — /keys shows
     * both rows where the overrides put them. The override landing on its
     * OWN shipped chord (`identity override`) is inert by the funnel's
     * `$pressed === $shipped` skip and warns of nothing.
     *
     * The map is expected pre-{@see validate()}-pass; callers feed it only
     * launch-accepted values ({@see \SugarCraft\Code\Cli\Bootstrap::keybindingsSetting()}).
     *
     * @param array<array-key, mixed> $value binding id => replacement chord
     *
     * @return list<string> one rendered row per collision: each stealing
     *                      override, and each pair of overrides sharing one
     *                      chord
     */
    public function collisionNotices(array $value): array
    {
        $rows = [];
        foreach ($value as $id => $chord) {
            if (!\is_string($id) || !\is_string($chord)) {
                continue;
            }

            // Collisions are compared in the canonical id alphabet
            // (KeyBinding::parseChord), so a stolen `Alt+Enter` is named as
            // precisely as a stolen `Ctrl+P` (plan_comparison TUI-4c).
            $pressed = KeyBinding::parseChord($chord);
            if ($pressed === null) {
                continue;
            }

            foreach (KeyBindingRegistry::all() as $binding) {
                if ($binding->id === $id || $binding->chord() !== $pressed) {
                    continue;
                }

                // An unbound victim has no key to lose (plan_comparison
                // TUI-4b): warning about the theft of a chord the user
                // already vacated would warn about nothing.
                if (in_array($binding->id, KeyBindingRegistry::suppressed(), true)) {
                    continue;
                }

                // A victim the user re-chorded too is listed under its own
                // override — the rune it vacated is not silently lost.
                if (\array_key_exists($binding->id, $value)) {
                    continue;
                }

                $rows[] = sprintf(
                    'the keybindings override for "%s" (%s) collides with the shipped chord of "%s"; pressing %s dispatches to "%s", so "%s" loses its own key',
                    $id,
                    $chord,
                    $binding->id,
                    $chord,
                    $id,
                    $binding->id,
                );
            }
        }

        // OVERRIDE-VERSUS-OVERRIDE (plan_comparison TUI-4b): two ids naming
        // the SAME pressed chord. The alias memos take one winner per chord
        // by walking the /keys declaration order (`$pairs[$pressed] ??=`),
        // so the earlier row wins and the later override lands on NOTHING —
        // deterministic and file-order-independent, but silent without this
        // row. Walked from the registry, not from `$value`, because the
        // registry order IS the winner rule the memos apply.
        /** @var array<string, string> $firstClaimant */
        $firstClaimant = [];
        foreach (KeyBindingRegistry::all() as $binding) {
            $chord = $value[$binding->id] ?? null;
            if (!\is_string($chord)) {
                continue; // unset, or null: an unbound row claims no chord
            }

            $pressed = KeyBinding::parseChord($chord);
            if ($pressed === null || !self::isAssignableChord($pressed)) {
                continue; // pre-validate input cannot produce this; fail open
            }

            if (isset($firstClaimant[$pressed])) {
                $rows[] = sprintf(
                    'the keybindings overrides for "%s" and "%s" both claim %s; "%s" is listed first in the /keys reference and wins the chord, so "%s" answers no key at all',
                    $firstClaimant[$pressed],
                    $binding->id,
                    $chord,
                    $firstClaimant[$pressed],
                    $binding->id,
                );

                continue;
            }

            $firstClaimant[$pressed] = $binding->id;
        }

        return $rows;
    }
}
