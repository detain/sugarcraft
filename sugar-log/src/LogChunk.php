<?php

declare(strict_types=1);

namespace SugarCraft\Log;

/**
 * What one {@see LogReader::follow()} read found.
 *
 * $fresh says whether $records replace what a follower holds (the first
 * read, or the file was truncated or replaced since $cursor) or extend it
 * (the lines written since the cursor the read was given). $cursor is where
 * the next read resumes; it is null while the file does not exist.
 *
 * Read-only value: it has no with*() setters, so it needs no mutate().
 *
 * Original SugarCraft read side: charmbracelet/log has no reader.
 */
final readonly class LogChunk
{
    /**
     * @param list<LogRecord> $records Oldest first.
     */
    public function __construct(
        public array $records,
        public ?LogCursor $cursor,
        public bool $fresh,
    ) {
    }
}
