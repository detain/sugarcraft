<?php

declare(strict_types=1);

namespace SugarCraft\Log;

/**
 * One parsed log line, as {@see LogReader} hands it back.
 *
 * Read-only value: it has no with*() setters, so it needs no mutate(). The
 * envelope members (time, prefix, caller) stay the strings found on disk —
 * the writer's time format is configurable, so the reader does not guess a
 * date parser. Context entries land in $fields under the names actually
 * written, which includes the `ctx_` namespacing {@see Formatter\RecordKeys}
 * applies to colliding keys.
 *
 * A line the format cannot parse (a torn JSON write, a foreign line in a
 * logfmt file) is still a record: $level null, $message the raw line.
 *
 * Original SugarCraft read side: charmbracelet/log has no reader.
 */
final readonly class LogRecord
{
    /**
     * @param string               $line    The physical line, without its terminator.
     * @param array<string, mixed> $fields  Context entries in written order.
     */
    public function __construct(
        public string $line,
        public ?Level $level,
        public string $message,
        public ?string $time = null,
        public ?string $prefix = null,
        public ?string $caller = null,
        public array $fields = [],
    ) {
    }

    /** True when the record carries a context entry named $key (even a null one). */
    public function hasField(string $key): bool
    {
        return \array_key_exists($key, $this->fields);
    }

    /** The context entry named $key, or null when absent. */
    public function field(string $key): mixed
    {
        return $this->fields[$key] ?? null;
    }
}
