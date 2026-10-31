<?php

declare(strict_types=1);

namespace SugarCraft\Code\Hooks;

use SugarCraft\Code\Memory\SecretRedactor;

/**
 * One line of {@see HookLog}: one configured hook run, reduced to the seven
 * fields a debugging user actually asks about (C-EXT-6.b, GAP-HK-08).
 *
 * The shape is a value object over the wire format, not over the hook — the
 * hook object itself can never be here, because the run it describes often
 * happened in a forked turn or tool child the parent process holds no live
 * handle on. That is the whole reason the log is a file.
 *
 * THE REDACTION ORDER IS LOAD-BEARING. {@see forRun()} runs the message
 * through {@see SecretRedactor} BEFORE the first-line pick and the byte cut,
 * never after: a secret straddling the cut boundary survives `cut()` whole
 * when the cut comes first (the pattern below `{16,}` never sees a long-enough
 * run), and `AuditHook` lost real bearer tokens that way. The order law is
 * pinned behaviorally in tests/Hooks/HookLogTest.php with the same
 * padding-straddle fixture the audit pair uses.
 *
 * The control characters inside a message need no escaping pass: JSON is the
 * wire format and `json_encode` escapes every byte the terminal cares about,
 * so one record stays exactly one physical line even for hostile hook output
 * (`JSON_INVALID_UTF8_SUBSTITUTE` covers the malformed-UTF-8 arm, which would
 * otherwise make the whole encode — and with it the line — silently false).
 */
final class HookLogEntry
{
    /** Byte ceiling on the logged message line (mirrors AuditHook's excerpt budget). */
    public const MESSAGE_CAP_BYTES = 200;

    public function __construct(
        public readonly string $ts,
        public readonly string $session,
        public readonly string $event,
        public readonly string $hook,
        public readonly string $verdict,
        public readonly int $ms,
        public readonly string $message,
    ) {
    }

    /**
     * Reduce a finished run to an entry. Callers pass the wall clock they
     * measured around `$hook->execute()` — the same `$elapsed` the spend
     * ledger already charges the chain budget with — so the log's `ms` and
     * the panel's spend figure can never disagree.
     */
    public static function forRun(string $event, HookContext $context, string $hookName, HookResult $result, float $elapsed): self
    {
        // Redact first, then pick the first line, then cut. See class note.
        $redacted = self::secretFree($result->message);
        $firstLine = explode("\n", $redacted)[0];

        return new self(
            ts: date('Y-m-d H:i:s'),
            session: $context->sessionId,
            event: $event,
            hook: $hookName,
            verdict: $result->action,
            ms: (int) round($elapsed * 1000),
            message: strlen($firstLine) > self::MESSAGE_CAP_BYTES
                ? mb_strcut($firstLine, 0, self::MESSAGE_CAP_BYTES)
                : $firstLine,
        );
    }

    /** One JSONL record, newline included. */
    public function toLine(): string
    {
        return (string) json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    }

    /**
     * @return array{ts: string, session: string, event: string, hook: string, verdict: string, ms: int, message: string}
     */
    public function toArray(): array
    {
        return [
            'ts' => $this->ts,
            'session' => $this->session,
            'event' => $this->event,
            'hook' => $this->hook,
            'verdict' => $this->verdict,
            'ms' => $this->ms,
            'message' => $this->message,
        ];
    }

    /**
     * Parse one JSONL record; null for anything that is not a complete,
     * well-typed entry. A torn tail line and a foreign file's content land on
     * the SAME arm — the reader drops both, quietly and honestly: the log is
     * a debugging surface, and a junk line here says "something wrote here
     * outside the contract", which the user acts on by looking, not by the
     * reader guessing at semantics it never promised.
     */
    public static function fromLine(string $line): ?self
    {
        $decoded = json_decode(trim($line), true);
        if (!\is_array($decoded)) {
            return null;
        }

        foreach (['ts', 'session', 'event', 'hook', 'verdict', 'message'] as $key) {
            if (!\is_string($decoded[$key] ?? null)) {
                return null;
            }
        }
        if (!\is_int($decoded['ms'] ?? null)) {
            return null;
        }

        return new self(
            ts: $decoded['ts'],
            session: $decoded['session'],
            event: $decoded['event'],
            hook: $decoded['hook'],
            verdict: $decoded['verdict'],
            ms: $decoded['ms'],
            message: $decoded['message'],
        );
    }

    /** One fresh redactor per record — the class is stateless (AuditHook's law). */
    private static function secretFree(string $text): string
    {
        return $text === '' ? '' : SecretRedactor::new()->redact($text);
    }
}
