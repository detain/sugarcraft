<?php

declare(strict_types=1);

namespace SugarCraft\Log;

/**
 * Which {@see LogRecord}s to keep: `LogFilter::new()->withMinLevel(Level::Warn)
 * ->withField('svc', 'api')->apply($records)`.
 *
 * Every condition must hold. A filter with no conditions keeps everything.
 * Immutable: every with*() returns a new filter through mutate().
 *
 * Original SugarCraft read side: charmbracelet/log has no reader.
 */
final readonly class LogFilter
{
    /**
     * @param ?Level               $minLevel null means no level condition (no
     *                                       sentinel needed: null is the meaning).
     * @param array<string, mixed> $fields   Context entries a record must carry.
     */
    private function __construct(
        public ?Level $minLevel = null,
        public array $fields = [],
    ) {
    }

    /** A filter that keeps every record. */
    public static function new(): self
    {
        return new self();
    }

    /**
     * Keep records at $level or above; null drops the condition. A record
     * whose level could not be read is not known to meet a threshold, so it
     * is dropped while one is set.
     */
    public function withMinLevel(?Level $level): self
    {
        return $this->mutate(['minLevel' => $level]);
    }

    /**
     * Keep records whose context entry $key equals $value. Calling it again
     * for the same key replaces that condition.
     *
     * Scalars compare by their written form, because logfmt hands every
     * value back as a string: `withField('n', 42)` matches both the JSON
     * `"n":42` and the logfmt `n=42`. Booleans and null use the formatters'
     * `true`, `false` and `null` spellings; arrays compare strictly.
     */
    public function withField(string $key, mixed $value): self
    {
        return $this->mutate(['fields' => [$key => $value] + $this->fields]);
    }

    public function matches(LogRecord $record): bool
    {
        if ($this->minLevel !== null
            && ($record->level === null || $record->level->value < $this->minLevel->value)) {
            return false;
        }

        foreach ($this->fields as $key => $want) {
            if (!$record->hasField((string) $key) || !self::same($want, $record->field((string) $key))) {
                return false;
            }
        }

        return true;
    }

    /**
     * The records that match, in their original order.
     *
     * @param iterable<LogRecord> $records
     * @return list<LogRecord>
     */
    public function apply(iterable $records): array
    {
        $kept = [];
        foreach ($records as $record) {
            if ($this->matches($record)) {
                $kept[] = $record;
            }
        }

        return $kept;
    }

    private static function same(mixed $want, mixed $got): bool
    {
        $wantScalar = $want === null || \is_scalar($want);
        $gotScalar = $got === null || \is_scalar($got);
        if ($wantScalar && $gotScalar) {
            return self::written($want) === self::written($got);
        }

        return $want === $got;
    }

    private static function written(string|int|float|bool|null $value): string
    {
        return match (true) {
            $value === null => 'null',
            \is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function mutate(array $changes): self
    {
        return new self(...\array_merge(\get_object_vars($this), $changes));
    }
}
