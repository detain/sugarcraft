<?php

declare(strict_types=1);

namespace CandyCore\Log\Formatter;

use CandyCore\Log\Formatter;
use CandyCore\Log\Level;

/**
 * Logfmt formatter — emits key=value pairs on a single line.
 * Mirrors charmbracelet/log's LogfmtFormatter.
 */
final class LogfmtFormatter implements Formatter
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
        $parts = [];

        if ($this->reportTimestamp) {
            $parts[] = 'time=' . $this->escape($time->format(\DateTimeInterface::ATOM));
        }

        $parts[] = 'level=' . $this->escape($level->label());
        $parts[] = 'msg=' . $this->escape($message);

        if ($prefix !== null && $prefix !== '') {
            $parts[] = 'prefix=' . $this->escape($prefix);
        }

        if ($caller !== null) {
            $parts[] = 'caller=' . $this->escape($caller);
        }

        foreach ($context as $k => $v) {
            $parts[] = $this->escape((string) $k) . '=' . $this->escape($this->formatValue($v));
        }

        return \implode(' ', $parts) . "\n";
    }

    private function escape(string $s): string
    {
        if (\preg_match('/[\s="]/', $s)) {
            return '"' . \str_replace('"', '\\"', $s) . '"';
        }
        return $s;
    }

    private function formatValue(mixed $v): string
    {
        if (\is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (\is_array($v)) {
            return '[' . \implode(',', \array_map(fn($i) => (string) $i, $v)) . ']';
        }
        if ($v === null) {
            return 'null';
        }
        return (string) $v;
    }
}
