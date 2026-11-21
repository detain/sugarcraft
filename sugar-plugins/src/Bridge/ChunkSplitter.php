<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Bridge;

/**
 * Splits one reply into platform-sized pieces. Bundles implement this on
 * their chunker; the router passes the face's own limits on every call.
 */
interface ChunkSplitter
{
    /**
     * @return list<string> the pieces, in order; never empty for non-empty text
     */
    public function chunk(string $text, int $limit, int $maxLines): array;
}
