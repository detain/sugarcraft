<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Help;

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
     * Return a suggested command name if Levenshtein distance to any known
     * command is ≤ 2, otherwise null.
     */
    public function suggest(string $input): ?string
    {
        $inputLower = strtolower($input);
        $best = null;
        $bestDist = PHP_INT_MAX;

        foreach ($this->commandNames as $name) {
            $nameLower = strtolower($name);
            if ($nameLower === $inputLower) {
                continue;
            }
            $dist = levenshtein($inputLower, $nameLower);
            if ($dist <= 2 && $dist < $bestDist) {
                $bestDist = $dist;
                $best = $name;
            }
        }

        return $best;
    }
}
