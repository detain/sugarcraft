<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Settings;

/**
 * One file the settings view reports on: where it is, what it is to the merge,
 * and whether this launch reads it.
 *
 * Listed so "which files matter" is answered on screen rather than left to the
 * reader of `docs/SETTINGS.md` — including a file that LOOKS like a settings
 * layer and is not one (the project's own `config.json`, N-DOC-3).
 */
final readonly class SettingsFile
{
    public function __construct(
        /** What the file is, e.g. "your config". */
        public string $role,
        public string $path,
        /** One short state word: read, absent, ignored, not shown, not a layer. */
        public string $status,
        /** Why, in a sentence. */
        public string $note,
    ) {
    }
}
