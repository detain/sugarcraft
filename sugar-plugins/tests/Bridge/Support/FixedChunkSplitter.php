<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Tests\Bridge\Support;

use SugarCraft\Plugins\Bridge\ChunkSplitter;

/** Splits text into pieces of at most $limit bytes, ignoring line budgets. */
final class FixedChunkSplitter implements ChunkSplitter
{
    public function chunk(string $text, int $limit, int $maxLines): array
    {
        if ($text === '') {
            return [];
        }

        return \str_split($text, \max(1, $limit));
    }
}
