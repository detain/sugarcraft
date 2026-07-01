<?php

declare(strict_types=1);

namespace SugarCraft\Log\Formatter;

use SugarCraft\Log\Formatter;
use SugarCraft\Log\Level;

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

        $result = \json_encode($record, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if ($result === false) {
            // Fallback: encode a minimal safe record so we never emit an empty line
            $result = \json_encode([
                'level' => $record['level'] ?? 'UNKNOWN',
                'msg'   => $record['msg']   ?? '',
                '_encode_error' => \json_last_error_msg(),
            ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        }

        return $result . "\n";
    }

    private function coerceValue(mixed $v): mixed
    {
        return ValueCoercion::coerce($v);
    }
}
