<?php

declare(strict_types=1);

namespace SugarCraft\Log\Formatter;

use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Log\Formatter;
use SugarCraft\Log\Level;

/**
 * Logfmt formatter — emits key=value pairs on a single line.
 * Mirrors charmbracelet/log's LogfmtFormatter.
 *
 * Field order is fixed (time, level, msg, prefix, caller, then context in
 * insertion order); this formatter does NOT honor {@see \SugarCraft\Log\PartsOrder},
 * which is a TextFormatter-only concern. A machine-parsed logfmt record has no
 * meaningful notion of reordered parts.
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
        // Neutralize log-injection / terminal-control bytes before emitting a
        // single-line logfmt record. Sanitize::untrusted() strips ESC-based
        // ANSI sequences and lone C0/C1 controls; the strtr then backslash-
        // escapes the newline/tab bytes it preserves, so a value containing
        // "\n" cannot split the record across two physical lines (which would
        // otherwise let an attacker forge a second, fake logfmt entry).
        $s = Sanitize::untrusted($s);
        $s = \strtr($s, ["\n" => '\\n', "\r" => '\\r', "\t" => '\\t']);

        if (\preg_match('/[\s="]/', $s)) {
            return '"' . \str_replace('"', '\\"', $s) . '"';
        }
        return $s;
    }

    private function formatValue(mixed $v): string
    {
        return ValueCoercion::stringify($v, 0, ',');
    }
}
