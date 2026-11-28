<?php

declare(strict_types=1);

namespace SugarCraft\Log;

/**
 * Where a {@see LogReader::follow()} read stopped: the byte just past the
 * last complete line it took, in the file it took it from.
 *
 * The inode tells a rotated file (a new file under the same name) from the
 * one the cursor was taken in; on a platform that reports no inode it is 0,
 * and a rotation is then only caught when the new file is shorter than the
 * offset or does not end a line there.
 *
 * Read-only value: it has no with*() setters, so it needs no mutate().
 *
 * Original SugarCraft read side: charmbracelet/log has no reader.
 */
final readonly class LogCursor
{
    public function __construct(
        public int $offset,
        public int $inode,
    ) {
    }

    /** True when $other points at the same byte of the same file. */
    public function equals(?self $other): bool
    {
        return $other !== null && $other->offset === $this->offset && $other->inode === $this->inode;
    }
}
