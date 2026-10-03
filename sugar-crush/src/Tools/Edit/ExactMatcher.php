<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\Edit;

/**
 * Stage 1: the bytes exactly as given — every non-overlapping occurrence, the
 * same count `substr_count()` reports. The only stage allowed more than one
 * match, and then only under `replace_all`.
 */
final class ExactMatcher implements MatchStage
{
    public function name(): string
    {
        return EditMatcher::STAGE_EXACT;
    }

    public function find(string $content, string $old, string $new): array
    {
        if ($old === '') {
            return [];
        }

        $found = [];
        $offset = 0;
        while (($at = strpos($content, $old, $offset)) !== false) {
            $found[] = new MatchCandidate($at, strlen($old), $new);
            $offset = $at + strlen($old);
        }

        return $found;
    }
}
