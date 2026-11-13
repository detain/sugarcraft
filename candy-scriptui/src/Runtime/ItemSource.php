<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Runtime;

/**
 * Shared item-source intake for choose and filter (scriptui-46, finding 064).
 *
 * gum feeds both commands from THREE shapes — bare positionals, a
 * repeatable/comma-split --item, and (filter only) stdin. Candy grew the
 * shapes one command at a time: choose took positionals only, filter took
 * stdin only, and every `filter Pizza Burger` or `choose --item X` script
 * died on the missing spelling. The precedence lives here once:
 *
 *   1. explicit items — positionals verbatim (one argument = one item, the
 *      shell already made the boundaries), then --item chunks through the
 *      escape-aware OptionList splitter (-76: `\,` stays a literal comma);
 *   2. stdin (reader supplied by the caller) — ONLY when no explicit items
 *      exist, so a piped heredoc never fights a positional list;
 *   3. empty — the caller's refusal door (each command names its own
 *      silence in its own copy).
 */
final class ItemSource
{
    /**
     * @param list<string>                   $positionals
     * @param list<string>                   $itemFlags    raw --item option values
     * @param (callable(): list<string>)|null $stdinReader
     * @return list<string>
     */
    public static function collect(array $positionals, array $itemFlags, ?callable $stdinReader): array
    {
        $items = array_values(array_map(strval(...), $positionals));
        foreach (OptionList::expand(array_map(strval(...), $itemFlags)) as $item) {
            $items[] = $item;
        }

        if ($items !== []) {
            return $items;
        }

        return $stdinReader !== null ? $stdinReader() : [];
    }
}
