<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Completion;

/**
 * Escapes names intended for shell completion scripts, preventing injection
 * of arbitrary shell code via malicious command/option names registered through
 * {@see \SugarCraft\Shell\Discovery\CommandScanner}.
 *
 * Only names matching the safe identifier pattern are emitted; all others are
 * silently skipped so the generated script remains safe to source.
 */
final class CompletionEscaper
{
    /** Valid identifier pattern for command and option names in completion scripts. */
    private const SAFE_PATTERN = '/^[A-Za-z0-9_-]+$/';

    /**
     * Returns the name unchanged if it is safe for shell interpolation;
     * returns null if the name could contain shell metacharacters and must
     * not be emitted into a completion script.
     *
     * @return string|null The safe name, or null if unsafe.
     */
    public static function safeName(string $name): ?string
    {
        return preg_match(self::SAFE_PATTERN, $name) === 1 ? $name : null;
    }

    /**
     * Like {@see safeName()} but filters an entire list, returning only
     * the safe elements in their original order.
     *
     * @param list<string> $names
     * @return list<string>
     */
    public static function filterSafeList(array $names): array
    {
        return array_values(array_filter(
            $names,
            static fn (string $n): bool => self::safeName($n) !== null,
        ));
    }
}
