<?php

declare(strict_types=1);

namespace SugarCraft\Log\Formatter;

use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Log\Formatter;
use SugarCraft\Log\Level;

/**
 * JSON formatter — emits one JSON object per log line.
 * Mirrors charmbracelet/log's JSONFormatter.
 *
 * The emitted object always carries the same fixed keys (level, msg, time,
 * caller, prefix, then context); this formatter does NOT honor
 * {@see \SugarCraft\Log\PartsOrder}, which is a TextFormatter-only concern.
 * JSON object member order is not semantically significant, so there is
 * nothing for PartsOrder to reorder here.
 *
 * Trust boundary: every string that reaches the wire — message, prefix,
 * caller, context keys and context values at any nesting depth — passes
 * through Sanitize::untrusted() first, so C1 control bytes (which
 * json_encode passes through verbatim) can never be smuggled into the file
 * and replayed at the terminal of whoever cats it. Raw newlines inside a
 * string are NOT flattened: json_encode escapes them to `\n`, so the record
 * stays one physical line while the data survives decoding. Invalid UTF-8
 * is substituted (U+FFFD) rather than allowed to fail the whole encode, and
 * an encode that fails despite all of this loses at most the offending
 * field — the envelope (level, time, caller, prefix) and every other
 * context entry are preserved, with an `_encode_error` note.
 */
final class JsonFormatter implements Formatter
{
    private const FLAGS = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE;

    private bool $reportTimestamp;
    private string $timeFormat;

    /**
     * @param bool        $reportTimestamp Emit the `time` member.
     * @param string|null $timeFormat      date() format for it; null keeps the
     *                                     machine-parsing default RFC 3339
     *                                     (DateTimeInterface::ATOM). Change it
     *                                     only when a collector demands another
     *                                     shape (m3) — parsers pinned on ATOM
     *                                     will stumble on anything else.
     */
    public function __construct(bool $reportTimestamp = true, ?string $timeFormat = null)
    {
        $this->reportTimestamp = $reportTimestamp;
        $this->timeFormat = $timeFormat ?? \DateTimeInterface::ATOM;
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
            'msg'   => $this->safe($message),
        ];

        if ($this->reportTimestamp) {
            // Deliberately outside safe(): $time arrives as a DateTimeImmutable
            // built by the caller or the clock, and the bytes are governed by
            // the developer-supplied $timeFormat — record context cannot inject
            // anything through this field.
            $record['time'] = $time->format($this->timeFormat);
        }

        if ($caller !== null) {
            $record['caller'] = $this->safe($caller);
        }

        if ($prefix !== null && $prefix !== '') {
            $record['prefix'] = $this->safe($prefix);
        }

        // Context keys are namespaced away from the envelope (see RecordKeys):
        // a forged `{"level": ...}` context entry may never overwrite the real
        // one — it lands as `ctx_level` instead. Keys are sanitized BEFORE the
        // collision walk so two hostile spellings that collapse to the same
        // safe key are themselves namespaced apart.
        $used = [];
        foreach ($context as $k => $v) {
            $record[RecordKeys::claim($this->safe((string) $k), $used)] = $this->safeTree($this->coerceValue($v));
        }

        $result = \json_encode($record, self::FLAGS);
        if (\is_string($result)) {
            return $result . "\n";
        }

        // Defensive path: coercion + sanitization make the record encodable
        // by construction, so reaching here means something novel defeated
        // it. Degrade per field — drop only the offender, keep the envelope —
        // instead of discarding the whole record (and with it time, caller,
        // prefix and every innocent context entry).
        $offenders = [];
        foreach ($record as $key => $value) {
            if (\json_encode([$key => $value], self::FLAGS) === false) {
                $offenders[] = $key;
                $record[$key] = '[unencodable]';
            }
        }
        $record['_encode_error'] = \json_last_error_msg()
            . ($offenders === [] ? '' : ' (fields: ' . \implode(',', $offenders) . ')');

        $result = \json_encode($record, self::FLAGS);
        if (!\is_string($result)) {
            // Last resort — a constant shape assembled from literals only.
            $result = '{"level":"UNKNOWN","msg":"[unencodable record]","_encode_error":"json_encode failed twice"}';
        }

        return $result . "\n";
    }

    private function coerceValue(mixed $v): mixed
    {
        return ValueCoercion::coerce($v);
    }

    /**
     * Sweep control bytes out of any string reaching the wire.
     */
    private function safe(string $s): string
    {
        return Sanitize::untrusted($s);
    }

    /**
     * Apply safe() to every string in a coerced value tree (arrays recurse to
     * the depth cap already enforced by ValueCoercion::coerce()).
     */
    private function safeTree(mixed $v): mixed
    {
        if (\is_string($v)) {
            return $this->safe($v);
        }

        if (\is_array($v)) {
            $out = [];
            foreach ($v as $k => $i) {
                $out[\is_int($k) ? $k : $this->safe($k)] = $this->safeTree($i);
            }

            return $out;
        }

        return $v;
    }
}
