<?php

declare(strict_types=1);

namespace SugarCraft\Log;

/**
 * Config DTO for configurable timestamp/level/prefix/caller/message/fields
 * ordering in formatters.
 *
 * Allows callers to specify which log-parts appear and in what sequence,
 * enabling custom log formats (e.g., message-first, syslog-friendly, etc.).
 *
 * Scope: PartsOrder is honored ONLY by {@see \SugarCraft\Log\Formatter\TextFormatter}.
 * The JSON and logfmt formatters intentionally ignore it — a JSON object has
 * no significant member order and a logfmt record follows a fixed conventional
 * order. {@see \SugarCraft\Log\Logger::withPartsOrder()} therefore only rewires
 * the active formatter when it is a TextFormatter.
 *
 * Mirrors charmbracelet/log's PartsOrder where applicable.
 */
final class PartsOrder
{
    /** Recognised log-line parts, in their default emission order. */
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
     *                                        Defaults to [timestamp, level, prefix, caller, message, fields].
     *
     * @throws InvalidArgumentException if the list names an unknown part or repeats one —
     *                                  a typo'd part (e.g. 'msg') would otherwise silently
     *                                  delete the piece from every line [M15].
     */
    public function __construct(
        ?array $parts = null,
    ) {
        $given = $parts ?? [
            self::PART_TIMESTAMP,
            self::PART_LEVEL,
            self::PART_PREFIX,
            self::PART_CALLER,
            self::PART_MESSAGE,
            self::PART_FIELDS,
        ];

        $known = [
            self::PART_TIMESTAMP,
            self::PART_LEVEL,
            self::PART_PREFIX,
            self::PART_CALLER,
            self::PART_MESSAGE,
            self::PART_FIELDS,
        ];

        $unknown = \array_values(\array_filter($given, static fn (string $part): bool => !\in_array($part, $known, true)));
        if ($unknown !== []) {
            throw new \InvalidArgumentException(Lang::t('parts.unknown', ['parts' => \implode(', ', $unknown)]));
        }

        $duplicates = \array_keys(\array_filter(\array_count_values($given), static fn (int $count): bool => $count > 1));
        if ($duplicates !== []) {
            throw new \InvalidArgumentException(Lang::t('parts.duplicate', ['parts' => \implode(', ', $duplicates)]));
        }

        $this->parts = \array_values($given);
    }

    /** DefaultPartsOrder: timestamp level prefix caller message fields. */
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
