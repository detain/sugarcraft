<?php

declare(strict_types=1);

namespace SugarCraft\Code\Config\Settings\Validator;

use SugarCraft\Code\Commands\KeyBindingRegistry;
use SugarCraft\Code\Config\Settings\SettingValidator;

/**
 * The `keybindings` map: binding id => replacement `Ctrl+<key>` chord.
 *
 * IDS ARE CHECKED AGAINST THE REGISTRY, not a snapshot: a renamed row must
 * refuse the stale override at the next save rather than park it under an id
 * nothing declares. The chord grammar mirrors
 * {@see \SugarCraft\Code\Commands\KeyBinding::ctrlRune()} deliberately — a
 * bare single-character `Ctrl+<key>` tail is the only shape today's handlers
 * claim and dispatch, so accepting anything wider would write a value the
 * routing cannot honour. THE SAME SHAPE IS REQUIRED OF THE TARGET: dispatch
 * aliases the override chord onto the row's shipped rune, so overriding a row
 * that ships `Enter` (or any non-`Ctrl+<key>` chord) would be accepted,
 * registered, and silently inert.
 */
final class KeyBindingsValidator implements SettingValidator
{
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

            if (KeyBindingRegistry::byId($id) === null) {
                return "'{$id}' is not a known binding (the /keys reference lists them)";
            }

            if (!self::isCtrlChord($chord)) {
                return "the override for '{$id}' must be a chord of the form Ctrl+<key>";
            }

            // The alias lands on the row's SHIPPED rune (KeyBindingRegistry::
            // shippedRuneFor()); a row shipped as `Enter` or a two-key
            // sequence has no rune to land on, so honouring it is impossible.
            if (KeyBindingRegistry::byId($id)?->ctrlRune() === null) {
                return "the override for '{$id}' cannot be honoured: its shipped chord is not a bare Ctrl+<key> to alias";
            }
        }

        return null;
    }

    /**
     * Rows WARNING that a (validated) override chord collides with another
     * binding's SHIPPED chord — the rv6 follow-up to ISS-047's alias funnel.
     *
     * WHY A WARNING AND NOT A REFUSAL: the dispatch is correct in this shape.
     * `KeyBindingRegistry::shippedRuneFor()` translates the pressed chord to
     * the override target's shipped rune BEFORE any claim decision, so the
     * user's chosen chord really does run the chosen binding. What no code
     * fixes is the OTHER row: the binding whose shipped chord was taken stops
     * answering at its own key — silently, because the alias map has one
     * winner per rune (declaration order). That is a consequence to SEE at
     * launch, not a config to reject; the user may want exactly this trade.
     *
     * A victim the user ALSO re-chorded is not named: its effective chord is
     * no longer the stolen rune, so nothing is silently lost — /keys shows
     * both rows where the overrides put them. The override landing on its
     * OWN shipped chord (`identity override`) is inert by the funnel's
     * `$pressed === $shipped` skip and warns of nothing.
     *
     * The map is expected pre-{@see validate()}-pass; callers feed it only
     * launch-accepted values ({@see \SugarCraft\Code\Cli\Bootstrap::keybindingsSetting()}).
     *
     * @param array<array-key, mixed> $value binding id => replacement chord
     *
     * @return list<string> one rendered row per stealing override
     */
    public function collisionNotices(array $value): array
    {
        $rows = [];
        foreach ($value as $id => $chord) {
            if (!\is_string($id) || !\is_string($chord)) {
                continue;
            }

            $rune = self::chordRune($chord);
            if ($rune === null) {
                continue;
            }

            foreach (KeyBindingRegistry::all() as $binding) {
                if ($binding->id === $id || $binding->ctrlRune() !== $rune) {
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

        return $rows;
    }

    /**
     * The lowercase rune of a `Ctrl+<one char>` chord — the same extraction
     * {@see \SugarCraft\Code\Commands\KeyBinding::ctrlRune()} makes of a
     * shipped label, so the two sides of the collision compare in the same
     * space dispatch does.
     */
    private static function chordRune(string $chord): ?string
    {
        if (!self::isCtrlChord($chord)) {
            return null;
        }

        return mb_strtolower(substr($chord, 5));
    }

    /**
     * The chord shape the handlers can honour: `Ctrl+` and exactly one
     * character. Mirrors KeyBinding::ctrlRune()'s strictness on purpose.
     */
    private static function isCtrlChord(mixed $chord): bool
    {
        if (!\is_string($chord) || !str_starts_with($chord, 'Ctrl+')) {
            return false;
        }

        return mb_strlen(substr($chord, 5)) === 1;
    }
}
