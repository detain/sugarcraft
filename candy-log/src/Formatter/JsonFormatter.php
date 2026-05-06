<?php

declare(strict_types=1);

namespace CandyCore\Log\Formatter;

use CandyCore\Log\Formatter;
use CandyCore\Log\Level;

/**
 * JSON formatter — emits one JSON object per log line.
 * Mirrors charmbracelet/log's JSONFormatter.
 */
final class JsonFormatter implements Formatter
{
    private bool $reportTimestamp;

    public function __construct(bool $reportTimestamp = true)
    {
        $this->reportTimestamp = $reportTimestamp;
    }

    public function format(
        Level $level,
        string $message,
        array $context,
        \DateTimeImmutable $time,
        ?string $caller,
        ?string $prefix,
    ): string {
        $record = [
            'level' => $level->label(),
            'msg'   => $message,
        ];

        if ($this->reportTimestamp) {
            $record['time'] = $time->format(\DateTimeInterface::ATOM);
        }

        if ($caller !== null) {
            $record['caller'] = $caller;
        }

        if ($prefix !== null && $prefix !== '') {
            $record['prefix'] = $prefix;
        }

        foreach ($context as $k => $v) {
            $record[$k] = $this->coerceValue($v);
        }

        return (string) \json_encode($record, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n";
    }

    private function coerceValue(mixed $v): mixed
    {
        if (\is_bool($v) || \is_int($v) || \is_float($v)) {
            return $v;
        }
        if (\is_array($v)) {
            return $v;
        }
        if ($v === null) {
            return null;
        }
        return (string) $v;
    }
}
