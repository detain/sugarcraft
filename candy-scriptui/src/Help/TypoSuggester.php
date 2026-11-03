<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Help;

final class TypoSuggester
{
    /**
     * @param list<string> $commandNames
     */
    public function __construct(
        private readonly array $commandNames,
    ) {
    }

    /**
     * Return every plausible command for a mistyped name: truncated prefixes
     * first (the common fat-finger class), then close spellings within
     * Levenshtein distance 2. Sorted by distance, then name; capped.
     *
     * @return list<string>
     */
    public function suggestAll(string $input): array
    {
        $inputLower = strtolower($input);
        $pairs = [];
        foreach ($this->commandNames as $name) {
            $nameLower = strtolower($name);
            if ($nameLower === $inputLower) {
                continue;
            }
            $isPrefix = $inputLower !== '' && str_starts_with($nameLower, $inputLower);
            $dist     = $isPrefix ? 0 : levenshtein($inputLower, $nameLower);
            if ($dist <= 2) {
                $pairs[] = [$dist, $name];
            }
        }
        usort($pairs, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $seen = [];
        foreach ($pairs as [, $name]) {
            $seen[] = $name;
        }

        return array_slice($seen, 0, 5);
    }

    /**
     * Return a suggested command name if Levenshtein distance to any known
     * command is ≤ 2, otherwise null.
     */
    public function suggest(string $input): ?string
    {
        return $this->suggestAll($input)[0] ?? null;
    }
}
