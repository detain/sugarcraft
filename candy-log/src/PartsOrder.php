<?php

declare(strict_types=1);

namespace SugarCraft\Log;

/**
 * Config DTO for configurable timestamp/level/msg/fields ordering in formatters.
 *
 * Allows callers to specify which log-parts appear and in what sequence,
 * enabling custom log formats (e.g., msg-first, syslog-friendly, etc.).
 *
 * Mirrors charmbracelet/log's PartsOrder where applicable.
 */
final class PartsOrder
{
    /**
     * @param list<self::PART_*> $parts Which parts to include and in what order.
     */
    public const PART_TIMESTAMP = 'timestamp';
    public const PART_LEVEL     = 'level';
    public const PART_PREFIX    = 'prefix';
    public const PART_CALLER    = 'caller';
    public const PART_MESSAGE   = 'message';
    public const PART_FIELDS    = 'fields';

    /**
     * @var list<self::PART_*>
     * @phpstan-var list<'timestamp'|'level'|'prefix'|'caller'|'message'|'fields'>
     */
    public readonly array $parts;

    /**
     * @param list<self::PART_*>|null $parts Which parts to emit and in what order.
     *                                        Defaults to [timestamp, level, prefix?, caller?, message, fields?].
     */
    public function __construct(
        ?array $parts = null,
    ) {
        $this->parts = $parts ?? [
            self::PART_TIMESTAMP,
            self::PART_LEVEL,
            self::PART_PREFIX,
            self::PART_CALLER,
            self::PART_MESSAGE,
            self::PART_FIELDS,
        ];
    }

    /** DefaultPartsOrder: timestamp level message fields. */
    public static function default(): self
    {
        return new self();
    }

    /** SyslogOrder: timestamp level message fields (no prefix/caller). */
    public static function syslog(): self
    {
        return new self([
            self::PART_TIMESTAMP,
            self::PART_LEVEL,
            self::PART_MESSAGE,
            self::PART_FIELDS,
        ]);
    }

    /** MessageFirstOrder: message level timestamp fields (prefix/caller omitted). */
    public static function messageFirst(): self
    {
        return new self([
            self::PART_MESSAGE,
            self::PART_LEVEL,
            self::PART_TIMESTAMP,
            self::PART_FIELDS,
        ]);
    }

    /**
     * @param self::PART_* $part
     */
    public function has(string $part): bool
    {
        return \in_array($part, $this->parts, true);
    }
}
