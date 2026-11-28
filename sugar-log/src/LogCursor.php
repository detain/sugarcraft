<?php

declare(strict_types=1);

namespace SugarCraft\Log;

/**
 * Where a {@see LogReader::follow()} read stopped: the byte just past the
 * last complete line it took, in the file it took it from.
 *
 * The inode tells a rotated file (a new file under the same name) from the
 * one the cursor was taken in; on a platform that reports no inode it is 0.
 * The fingerprint covers the file's first bytes and the bytes just before
 * the offset ({@see LogReader::FINGERPRINT_BYTES} of each), so a file
 * truncated in place and written again past the offset (copytruncate) is
 * caught too: its new lines no longer match the ones the cursor was taken
 * past. Only a rewrite that puts back the very same bytes in both spans
 * reads on as though the file had been appended to.
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
        public string $fingerprint,
    ) {
    }

    /** True when $other points at the same byte of the same file. */
    public function equals(?self $other): bool
    {
        return $other !== null && $other->offset === $this->offset && $other->inode === $this->inode
            && $other->fingerprint === $this->fingerprint;
    }
}
