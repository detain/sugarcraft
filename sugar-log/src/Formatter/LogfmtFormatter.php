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

        // Context keys are namespaced away from the envelope (see RecordKeys):
        // a forged `level=...` context entry may never overwrite the real one.
        $used = [];
        foreach ($context as $k => $v) {
            $key = RecordKeys::claim((string) $k, $used);
            $parts[] = $this->escape($key) . '=' . $this->escape($this->formatValue($v));
        }

        return \implode(' ', $parts) . "\n";
    }

    private function escape(string $s): string
    {
        // Neutralize log-injection / terminal-control bytes before emitting a
        // single-line logfmt record. Sanitize::untrusted() strips ESC-based
        // ANSI sequences and lone C0/C1 controls. Data backslashes and quotes
        // are escaped FIRST (they are payload bytes), then the surviving
        // newline/CR/tab characters become backslash sequences (syntax, added
        // last so the escaping pass never double-dips on them). A value that
        // contains ANY space, '=', '"', or '\' is therefore always emitted in
        // quoted form — an attacker cannot close a quoted value early with a
        // bare backslash-quote and forge a trailing `key=value` field, and a
        // `"\n"` payload cannot split the record across two physical lines
        // (which would otherwise let an attacker mint a second, fake entry).
        $s = Sanitize::untrusted($s);
        $s = \str_replace(['\\', '"'], ['\\\\', '\\"'], $s);
        $s = \strtr($s, ["\n" => '\\n', "\r" => '\\r', "\t" => '\\t']);

        if (\preg_match('/[\s="\\\\]/', $s)) {
            return '"' . $s . '"';
        }
        return $s;
    }

    private function formatValue(mixed $v): string
    {
        return ValueCoercion::stringify($v, 0, ',');
    }
}
