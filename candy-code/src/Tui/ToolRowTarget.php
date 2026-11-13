<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui;

/**
 * The literal thing a tool call acted on, for the transcript row that shows
 * it: the command a shell ran, the file a read or write touched, the pattern a
 * search looked for.
 *
 * A row reads the model's own `description` argument when the model sent one,
 * and that summary is the better label. When it did not, the row used to fall
 * back to a `name(key: value, …)` dump, which hides the one thing a user scans
 * a transcript for. This answers the question the dump answered badly, and
 * answers nothing for a tool it does not know, so the caller keeps the dump.
 */
final class ToolRowTarget
{
    /**
     * The row's text for $name called with $arguments, or null when the call
     * names no target this class knows how to show.
     *
     * @param array<string, mixed> $arguments
     */
    public static function of(string $name, array $arguments): ?string
    {
        $target = match (strtolower($name)) {
            'bash' => self::shellCommand($arguments),
            'read', 'write', 'edit', 'notebookedit' => self::stringArgument($arguments, 'file_path'),
            'grep', 'glob' => self::stringArgument($arguments, 'pattern'),
            default => null,
        };

        return $target;
    }

    /**
     * `$ <command>`, the shell's own prompt: the one-line form of what ran.
     *
     * @param array<string, mixed> $arguments
     */
    private static function shellCommand(array $arguments): ?string
    {
        $command = self::stringArgument($arguments, 'command');

        return $command === null ? null : '$ ' . $command;
    }

    /** @param array<string, mixed> $arguments */
    private static function stringArgument(array $arguments, string $key): ?string
    {
        $value = $arguments[$key] ?? null;
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
