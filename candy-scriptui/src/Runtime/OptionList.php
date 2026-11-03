<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Runtime;

/**
 * Expands repeatable list options: each occurrence may itself carry a
 * comma-separated list, mirroring how scripts pass --selected="a,b".
 * Items keep their exact bytes — no trimming, so a name with a leading
 * space stays addressable via a repeated flag.
 */
final class OptionList
{
    /**
     * @param  list<string> $raw
     * @return list<string>
     */
    public static function expand(array $raw): array
    {
        $out = [];
        foreach ($raw as $item) {
            foreach (explode(',', $item) as $piece) {
                $out[] = $piece;
            }
        }

        return $out;
    }
}
