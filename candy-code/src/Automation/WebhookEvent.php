<?php

declare(strict_types=1);

namespace SugarCraft\Code\Automation;

/**
 * One deliverable automation event: the JSON body an outbound webhook POST
 * carries, plus its type so {@see OutboundWebhookConfig} subscribers can be
 * matched. C-AGT-5.e.
 *
 * THE FOUR EVENTS ARE THE WHOLE VOCABULARY. `turn.completed`,
 * `session.ended`, `permission.asked` and `schedule.fired` are the only
 * types the parser accepts and the only factories this class ships. Each
 * factory takes IDENTIFIERS AND NUMBERS ONLY — no message, no prompt, no
 * tool output, no transcript fragment appears in any signature, because the
 * entire point of the bounded shape is that a webhook receiver (and every
 * log, proxy and third-party store between here and it) never receives
 * conversation content. An integration that needs the text follows the
 * session id up through the local session store; that hop is the operator's
 * consent boundary, and it stays one.
 *
 * BOUND WHERE IT CLIPS, NOT WHERE IT REFUSES: identifiers arrive from code
 * that assembled them, and a too-long id is a bug in the caller, not an
 * attack — clipping through {@see clip()} keeps a delivery flowing and the
 * payload small, which is what the bound is FOR. The four bounds:
 * {@see MAX_ID_CHARS} for session ids, tool names and schedule names, and
 * {@see MAX_OUTCOME_CHARS} for the one-word schedule outcome; numbers are
 * clamped at zero below so a mis-signed duration cannot poison a dashboard.
 * The bounds are enforced in the CONSTRUCTOR ({@see boundedData()}), on
 * every path in — factory or direct `new` — because "no transcript text
 * rides the envelope" is a property of the type, not a courtesy of the four
 * factory names; off-schema keys or value types refuse fail-closed. The
 * factories already build shapes that pass, so their payloads are
 * byte-identical through the re-normalization.
 *
 * The envelope is `{"event":...,"timestamp":...,"data":{...}}`; the
 * timestamp is the event's own unix seconds, independent of the signature
 * header's timestamp (they normally agree, but the receiver validates the
 * header against the bytes, which is the pair {@see WebhookSigner} binds).
 */
final class WebhookEvent
{
    public const TYPE_TURN_COMPLETED = 'turn.completed';

    public const TYPE_SESSION_ENDED = 'session.ended';

    public const TYPE_PERMISSION_ASKED = 'permission.asked';

    public const TYPE_SCHEDULE_FIRED = 'schedule.fired';

    /** @var list<string> the complete accepted type vocabulary, in wire order */
    public const TYPES = [
        self::TYPE_TURN_COMPLETED,
        self::TYPE_SESSION_ENDED,
        self::TYPE_PERMISSION_ASKED,
        self::TYPE_SCHEDULE_FIRED,
    ];

    /** Clip bound for every identifier field (session, tool, schedule names). */
    public const MAX_ID_CHARS = 128;

    /** Clip bound for the schedule outcome word. */
    public const MAX_OUTCOME_CHARS = 64;

    /**
     * Which bound a schema field carries: an identifier clip, the outcome
     * clip, or the clamp-at-zero numeric floor.
     */
    private const BOUND_ID = 'id';
    private const BOUND_OUTCOME = 'outcome';
    private const BOUND_NUMBER = 'number';

    /**
     * Per-type payload schema — the exact key set each event carries and the
     * bound each field obeys. Applied by the constructor to every incoming
     * `$data`, so the bounded-envelope law cannot be stepped around by
     * bypassing the factories.
     *
     * @var array<string,array<string,string>>
     */
    private const SCHEMA = [
        self::TYPE_TURN_COMPLETED => [
            'session_id' => self::BOUND_ID,
            'duration_seconds' => self::BOUND_NUMBER,
            'tool_calls' => self::BOUND_NUMBER,
        ],
        self::TYPE_SESSION_ENDED => [
            'session_id' => self::BOUND_ID,
            'duration_seconds' => self::BOUND_NUMBER,
            'turn_count' => self::BOUND_NUMBER,
        ],
        self::TYPE_PERMISSION_ASKED => [
            'session_id' => self::BOUND_ID,
            'tool' => self::BOUND_ID,
        ],
        self::TYPE_SCHEDULE_FIRED => [
            'session_id' => self::BOUND_ID,
            'schedule' => self::BOUND_ID,
            'outcome' => self::BOUND_OUTCOME,
        ],
    ];

    /** @var array<string,int|float|string> the payload, bounded at construction */
    public readonly array $data;

    /**
     * @param array<string,int|float|string> $data payload fields; the shape is
     *        enforced here — exactly the per-type schema keys, strings clipped
     *        to the same bounds the factories apply, numbers clamped at zero.
     */
    public function __construct(
        public readonly string $type,
        public readonly int $timestamp,
        array $data,
    ) {
        if (!\in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException(\sprintf(
                'unknown webhook event type "%s"; the vocabulary is: %s',
                $type,
                \implode(', ', self::TYPES),
            ));
        }

        $this->data = self::boundedData($type, $data);
    }

    public static function turnCompleted(string $sessionId, float $durationSeconds, int $toolCalls, ?int $timestamp = null): self
    {
        return new self(self::TYPE_TURN_COMPLETED, $timestamp ?? \time(), [
            'session_id' => self::clip($sessionId, self::MAX_ID_CHARS),
            'duration_seconds' => \max(0.0, \round($durationSeconds, 3)),
            'tool_calls' => \max(0, $toolCalls),
        ]);
    }

    public static function sessionEnded(string $sessionId, float $durationSeconds, int $turnCount, ?int $timestamp = null): self
    {
        return new self(self::TYPE_SESSION_ENDED, $timestamp ?? \time(), [
            'session_id' => self::clip($sessionId, self::MAX_ID_CHARS),
            'duration_seconds' => \max(0.0, \round($durationSeconds, 3)),
            'turn_count' => \max(0, $turnCount),
        ]);
    }

    public static function permissionAsked(string $sessionId, string $toolName, ?int $timestamp = null): self
    {
        return new self(self::TYPE_PERMISSION_ASKED, $timestamp ?? \time(), [
            'session_id' => self::clip($sessionId, self::MAX_ID_CHARS),
            'tool' => self::clip($toolName, self::MAX_ID_CHARS),
        ]);
    }

    public static function scheduleFired(string $sessionId, string $scheduleName, string $outcome, ?int $timestamp = null): self
    {
        return new self(self::TYPE_SCHEDULE_FIRED, $timestamp ?? \time(), [
            'session_id' => self::clip($sessionId, self::MAX_ID_CHARS),
            'schedule' => self::clip($scheduleName, self::MAX_ID_CHARS),
            'outcome' => self::clip($outcome, self::MAX_OUTCOME_CHARS),
        ]);
    }

    /**
     * Enforce {@see SCHEMA} on a raw payload: refuse keys outside the type's
     * field set (and missing fields) fail-closed, clip every string field to
     * its bound, clamp every number at zero. Factory payloads are already
     * in this shape, so the rewrite is the identity for them — the pin in
     * the dispatcher test compares bytes to prove it.
     *
     * @param array<string,int|float|string> $data
     * @return array<string,int|float|string>
     */
    private static function boundedData(string $type, array $data): array
    {
        $schema = self::SCHEMA[$type];

        $extra = \array_diff_key($data, $schema);
        if ($extra !== []) {
            throw new \InvalidArgumentException(\sprintf(
                'webhook event "%s" carries field(s) outside its schema: %s',
                $type,
                \implode(', ', \array_map('strval', \array_keys($extra))),
            ));
        }

        $missing = \array_diff_key($schema, $data);
        if ($missing !== []) {
            throw new \InvalidArgumentException(\sprintf(
                'webhook event "%s" is missing field(s): %s',
                $type,
                \implode(', ', \array_keys($missing)),
            ));
        }

        $bounded = [];
        foreach ($schema as $field => $bound) {
            $value = $data[$field];
            $bounded[$field] = match (true) {
                $bound === self::BOUND_ID => \is_string($value)
                    ? self::clip($value, self::MAX_ID_CHARS)
                    : throw self::fieldRefused($type, $field, 'a string'),
                $bound === self::BOUND_OUTCOME => \is_string($value)
                    ? self::clip($value, self::MAX_OUTCOME_CHARS)
                    : throw self::fieldRefused($type, $field, 'a string'),
                \is_float($value) => \max(0.0, $value),
                \is_int($value) => \max(0, $value),
                default => throw self::fieldRefused($type, $field, 'a string or a number'),
            };
        }

        return $bounded;
    }

    private static function fieldRefused(string $type, string $field, string $wanted): \InvalidArgumentException
    {
        return new \InvalidArgumentException(\sprintf(
            'webhook event "%s" field "%s" must be %s',
            $type,
            $field,
            $wanted,
        ));
    }

    /** The exact bytes the delivery POSTs. */
    public function json(): string
    {
        return \json_encode([
            'event' => $this->type,
            'timestamp' => $this->timestamp,
            'data' => $this->data,
        ], \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR);
    }

    /**
     * Trim, then clip to at most `$max` whole characters (codepoints), so a
     * bounded field can never split a multibyte sequence into invalid JSON.
     */
    private static function clip(string $value, int $max): string
    {
        $trimmed = \trim($value);

        return \mb_substr($trimmed, 0, $max, 'UTF-8');
    }
}
