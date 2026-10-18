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
 * routing cannot honour.
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
        }

        return null;
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
