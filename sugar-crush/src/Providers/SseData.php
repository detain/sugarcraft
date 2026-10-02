<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

/**
 * The value of one SSE `data` field line, read the way the event-stream spec
 * says to read it.
 *
 * WHY IT EXISTS (audit 15a A5). The OpenAI-compatible stream loops matched the
 * literal prefix `data: ` - colon AND space. The spec (WHATWG HTML, "event
 * stream interpretation") makes that space optional: the field name ends at
 * the colon, and ONE leading U+0020 of the value is dropped if present. Some
 * OpenAI-compatible gateways emit `data:{...}`, and every such frame was
 * skipped: the reply came back empty, and since the A3 cut-detection fix the
 * same stream - which never "showed" its finish frame or `[DONE]` - surfaced
 * as a premature-end error instead.
 *
 * ONE place for both providers that speak that wire (Sglang, Custom), so the
 * frame loop and the end-of-stream `[DONE]` residue check cannot disagree
 * about what a data line is.
 */
final class SseData
{
    private function __construct()
    {
    }

    /**
     * The field value of a `data` line, or null when $line is not one.
     *
     * Exactly one optional space is stripped, as the spec says - not trimmed:
     * the callers already `trim()` the whole line, so for a JSON payload the
     * difference never shows, but a value that genuinely starts with a space
     * after `data: ` keeps it. `data` with no colon at all is a field with an
     * empty value per the spec; no provider emits it and nothing here wants
     * an empty payload, so it is reported as not-a-data-line.
     */
    public static function value(string $line): ?string
    {
        if (!str_starts_with($line, 'data:')) {
            return null;
        }

        $value = substr($line, 5);

        return str_starts_with($value, ' ') ? substr($value, 1) : $value;
    }

    /** Whether $line is the OpenAI-wire `[DONE]` sentinel, in either spacing. */
    public static function isDone(string $line): bool
    {
        return self::value(trim($line)) === '[DONE]';
    }
}
