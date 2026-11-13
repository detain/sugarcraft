<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Runtime;

/**
 * Expands repeatable list options: each occurrence may itself carry a
 * comma-separated list, mirroring how scripts pass --selected="a,b".
 * Items keep their exact bytes — no trimming, so a name with a leading
 * space stays addressable via a repeated flag.
 *
 * The split is escape-aware (-76): a comma only separates when it is
 * unescaped, so a title that CONTAINS a comma (CSV-ish inputs — this
 * lib's audience) stays preselectable as `a\,b`. Exactly two escapes
 * exist — `\,` for a literal comma and `\\` for a literal backslash;
 * every other backslash stays verbatim data (no newline or unicode
 * interpretation, byte-purity first). Gum ships repeat-only spellings;
 * the compat-table note rides scriptui-60.
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
            foreach (self::splitUnescaped($item) as $piece) {
                $out[] = $piece;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function splitUnescaped(string $item): array
    {
        $pieces = [];
        $current = '';
        $length = strlen($item);
        for ($i = 0; $i < $length; $i++) {
            $char = $item[$i];
            if (
                $char === '\\'
                && $i + 1 < $length
                && ($item[$i + 1] === ',' || $item[$i + 1] === '\\')
            ) {
                $current .= $item[++$i];
                continue;
            }
            if ($char === ',') {
                $pieces[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $pieces[] = $current;

        return $pieces;
    }
}
