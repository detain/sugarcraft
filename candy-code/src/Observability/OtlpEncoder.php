<?php

declare(strict_types=1);

namespace SugarCraft\Code\Observability;

use SugarCraft\Code\Events\ContextLedgerChanged;
use SugarCraft\Code\Events\MediaProgress;
use SugarCraft\Code\Events\PermissionAsked;
use SugarCraft\Code\Events\PermissionResolved;
use SugarCraft\Code\Events\ReasoningDelta;
use SugarCraft\Code\Events\SpendCapBreached;
use SugarCraft\Code\Events\StepStarted;
use SugarCraft\Code\Events\SubAgentActivity;
use SugarCraft\Code\Events\TodoUpdated;
use SugarCraft\Code\Events\TokenDelta;
use SugarCraft\Code\Events\ToolFinished;
use SugarCraft\Code\Events\ToolProgress;
use SugarCraft\Code\Events\ToolStarted;
use SugarCraft\Code\Events\UsageUpdated;
use SugarCraft\Code\Permissions\DenialKind;

/**
 * Hand-rolled OTLP/HTTP-JSON encoding of the typed event DTOs.
 *
 * The repository deliberately does not depend on any open-telemetry
 * package: the SDK chain would add roughly fifteen dependencies to a
 * terminal application for one export format. This encoder emits the
 * `resourceSpans` shape of OTLP 1.x JSON (proto-JSON casing), which every
 * collector accepting OTLP/HTTP understands.
 *
 * Mapping law:
 * - {@see StepStarted} opens a span named `chat.turn` for its step; spans
 *   are `INTERNAL` (kind 1), `time` fields are nanoseconds as strings.
 * - {@see UsageUpdated} and {@see SpendCapBreached} CLOSE the turn's span
 *   (they are the settle reports of a step) and ride onto it first.
 * - every other recognised event rides as attributes of the open span of
 *   its step. Only StepStarted/UsageUpdated carry a step number, so an
 *   event without one attributes to the single open span when exactly one
 *   is open — the honest interpretation of a sequential chat turn — and is
 *   dropped while several steps are open at once (parallel agents).
 * - Unrecognised objects are dropped; the return value reports the closed
 *   spans produced by a call, so a caller can never emit a span twice.
 * - Every SAFETY VERDICT additionally rides the span as a time-stamped
 *   span event named `candy_code.safety_event` (C-SEC-6.e): a denial on a
 *   finished tool call (`decision=deny`, the `DenialKind` token, the tool,
 *   the first word of the kind's reason prefix), an ask
 *   (`decision=ask`, tool, source, mode), and a non-approval settlement
 *   (`decision=reject` answered "no", `decision=cancel` nobody answered —
 *   the two refusals the `PermissionResolved` contract keeps apart). An
 *   approval or an allowed tool finish emits nothing. Attributes stay
 *   bare keys because the event name scopes them.
 * - A batch arrives through {@see encodeMany()} as consume-time-stamped
 *   pairs (OBS-1-P1: spans are measured across the real event gap, never
 *   the export instant) and returns its closed spans RAW, so exactly one
 *   {@see exportBody()} call — one OTLP body, one POST — carries them all
 *   (OBS-1-P2). The safety events ride those spans with their own observed
 *   instants, so a batched export never blurs a verdict to the close time.
 *
 * Privacy law: identifiers, counters, durations, token totals and costs are
 * exported; payloads are NOT. No tool arguments, tool output tails, media
 * frames, prompt/reasoning text or task descriptions ever become attributes
 * (character COUNTS of deltas are fine — they size activity, not content).
 * The same law bounds the safety events: a denial reason can quote the
 * refused command text, so a deny event carries the enum's kind token and
 * the first word of the enum's own prefix — never the reason body, and
 * never any word taken from the carried content at all.
 *
 * Pricing law: a `0.0` cost for a model with no price entry exports the
 * model name under `candy_code.model.unpriced` so a dashboard can tell
 * "not priced" from "free" instead of silently reading the turn as $0.
 */
final class OtlpEncoder
{
    public const SERVICE_NAME = 'candy-code';
    public const TURN_SPAN_NAME = 'chat.turn';

    /**
     * Open spans keyed by step. `events` carries the C-SEC-6.e safety
     * verdicts in arrival order, each stamped with its observed instant.
     *
     * @var array<int, array{traceId: string, spanId: string, startNano: string, lastNano: string, attrs: array<string, array{key: string, value: array<string, string|int|bool|float>}>, events: list<array<string, mixed>>}>
     */
    private array $openSpans = [];

    /** Span-event name for every safety verdict (C-SEC-6.e). */
    public const SAFETY_EVENT_NAME = 'candy_code.safety_event';

    /**
     * @param array<string, string|int|bool|float> $resourceAttributes
     */
    public function __construct(private readonly array $resourceAttributes = [])
    {
    }

    /**
     * Absorbs one event. Returns an OTLP export request body when this
     * call closed a span, null otherwise (event opened/annotated/dropped).
     *
     * C-SES-4.a: $nowUnixNanos is the event's OBSERVED instant. A batched
     * caller stamps at consume time and hands the value through; null
     * keeps the single-shot shape, where observe and encode coincide.
     *
     * @return ?array{resourceSpans: list<array<string, mixed>>}
     */
    public function encode(object $event, ?int $nowUnixNanos = null): ?array
    {
        $closed = $this->absorb($event, $nowUnixNanos);

        return null === $closed ? null : $this->exportBody([$closed]);
    }

    /**
     * OBS-1-P2 (the one-body half): absorbs a whole batch of consume-time-
     * stamped events and returns EVERY span the batch closed, raw and in
     * arrival order. The caller wraps them in a single {@see exportBody()},
     * so one batch leaves in one POST instead of one POST per closed span.
     * An empty list means the batch closed nothing (openings and
     * annotations only).
     *
     * @param list<array{object, ?int}> $stampedEvents
     *
     * @return list<array<string, mixed>> rendered closed spans
     */
    public function encodeMany(array $stampedEvents): array
    {
        $spans = [];
        foreach ($stampedEvents as [$event, $nanos]) {
            $closed = $this->absorb($event, $nanos);
            if (null !== $closed) {
                $spans[] = $closed;
            }
        }

        return $spans;
    }

    /**
     * The shared absorb core of {@see encode()} and {@see encodeMany()}:
     * state changes only, returning the raw rendered span when this event
     * closed one.
     *
     * @return ?array<string, mixed>
     */
    private function absorb(object $event, ?int $nowUnixNanos): ?array
    {
        $nanos = $nowUnixNanos ?? self::wallClockNanos();

        if ($event instanceof StepStarted) {
            $this->openSpans[$event->step] = self::openSpan($event, $nanos);

            return null;
        }

        $step = $this->targetStep($event);
        if (null === $step) {
            return null;
        }

        $span = &$this->openSpans[$step];
        $span['lastNano'] = (string) \max((int) $span['lastNano'], $nanos);
        self::addEventAttributes($span['attrs'], $event);
        self::addSafetyEvent($span['events'], $event, $nanos);
        if (!self::closesTurn($event)) {
            unset($span);

            return null;
        }

        $closed = self::renderSpan($span);
        unset($this->openSpans[$step]);
        unset($span);

        return $closed;
    }

    /**
     * Renders every still-open span (shutdown arm). Nothing arrives after
     * the flush ordering guarantees, so an open span here is a turn whose
     * settle report never came; ending it at its last observed instant is
     * the honest, bounded choice.
     *
     * @return ?array{resourceSpans: list<array<string, mixed>>}
     */
    public function drainOpen(?int $nowUnixNanos = null): ?array
    {
        if ([] === $this->openSpans) {
            return null;
        }

        $nanos = $nowUnixNanos ?? self::wallClockNanos();
        $spans = [];
        foreach ($this->openSpans as $step => $span) {
            $span['lastNano'] = (string) \max((int) $span['lastNano'], $nanos);
            $spans[] = self::renderSpan($span);
            unset($this->openSpans[$step]);
        }

        return $this->exportBody($spans);
    }

    /** How many spans are currently open (test/diagnostic surface). */
    public function openSpanCount(): int
    {
        return \count($this->openSpans);
    }

    private function targetStep(object $event): ?int
    {
        if ($event instanceof UsageUpdated) {
            return isset($this->openSpans[$event->step]) ? $event->step : null;
        }

        if (self::attributesKnownEvent($event) && 1 === \count($this->openSpans)) {
            return (int) \array_key_first($this->openSpans);
        }

        return null;
    }

    /**
     * Whether the encoder knows how to turn this shape into attributes at
     * all (unknown shapes must not attach anywhere).
     */
    private static function attributesKnownEvent(object $event): bool
    {
        return $event instanceof SpendCapBreached
            || $event instanceof ToolStarted
            || $event instanceof ToolFinished
            || $event instanceof ToolProgress
            || $event instanceof MediaProgress
            || $event instanceof PermissionAsked
            || $event instanceof PermissionResolved
            || $event instanceof TokenDelta
            || $event instanceof ReasoningDelta
            || $event instanceof SubAgentActivity
            || $event instanceof ContextLedgerChanged
            || $event instanceof TodoUpdated;
    }

    private static function closesTurn(object $event): bool
    {
        return $event instanceof UsageUpdated || $event instanceof SpendCapBreached;
    }

    /**
     * @param array{traceId: string, spanId: string, startNano: string, lastNano: string, attrs: array<string, array{key: string, value: array<string, string|int|bool|float>}>, events: list<array<string, mixed>>} $span
     *
     * @return array<string, mixed>
     */
    private static function renderSpan(array $span): array
    {
        $rendered = [
            'traceId' => $span['traceId'],
            'spanId' => $span['spanId'],
            'name' => self::TURN_SPAN_NAME,
            'kind' => 1,
            'startTimeUnixNano' => $span['startNano'],
            'endTimeUnixNano' => $span['lastNano'],
            'attributes' => array_values($span['attrs']),
        ];

        // An empty repeated field is omitted, the proto-JSON shape's own
        // rule: a turn without a safety verdict renders byte-identically
        // to before C-SEC-6.e.
        if ([] !== $span['events']) {
            $rendered['events'] = \array_values($span['events']);
        }

        return $rendered;
    }

    /**
     * Wraps rendered spans into the OTLP request body this encoder's
     * resource attributes describe. Public since C-SES-4.a: the batch arm
     * hands {@see encodeMany()}'s spans here ONCE per batch, which is what
     * makes one batch equal one POST.
     *
     * @param list<array<string, mixed>> $spans
     *
     * @return array{resourceSpans: list<array<string, mixed>>}
     */
    public function exportBody(array $spans): array
    {
        $attributes = [];
        foreach (['service.name' => self::SERVICE_NAME] + $this->resourceAttributes as $key => $value) {
            $attributes[] = ['key' => (string) $key, 'value' => self::attributeValue($value)];
        }

        return ['resourceSpans' => [[
            'resource' => ['attributes' => $attributes],
            'scopeSpans' => [['scope' => ['name' => self::SERVICE_NAME], 'spans' => $spans]],
        ]]];
    }

    private function openSpan(StepStarted $event, int $nanos): array
    {
        $attrs = [];
        self::setAttribute($attrs, 'chat.step', $event->step);
        self::setAttribute($attrs, 'chat.max_steps', $event->maxSteps);
        if (null !== $event->pressure) {
            self::setAttribute($attrs, 'context.pressure_tokens', $event->pressure->tokens);
            self::setAttribute($attrs, 'context.window', $event->pressure->window);
        }

        return [
            'traceId' => bin2hex(self::randomBytes(16)),
            'spanId' => bin2hex(self::randomBytes(8)),
            'startNano' => (string) $nanos,
            'lastNano' => (string) $nanos,
            'attrs' => $attrs,
            'events' => [],
        ];
    }

    /**
     * @param array<string, array{key: string, value: array<string, string|int|bool|float>}> $attrs
     */
    private static function addEventAttributes(array &$attrs, object $event): void
    {
        if ($event instanceof UsageUpdated) {
            $usage = $event->turnUsage ?? $event->usage;
            if (null !== $usage) {
                $report = $usage->toArray();
                // Null buckets mean "the provider never said" and must not
                // be exported as 0 — the same zero-is-not-unknown law the
                // Usage class itself pins.
                foreach ([
                    'gen_ai.usage.input_tokens' => $report['inputTokens'],
                    'gen_ai.usage.output_tokens' => $report['outputTokens'],
                    'gen_ai.usage.cache_read_tokens' => $report['cacheReadTokens'],
                    'gen_ai.usage.cache_creation_tokens' => $report['cacheCreationTokens'],
                ] as $key => $bucket) {
                    if (null !== $bucket) {
                        self::setAttribute($attrs, $key, (int) $bucket);
                    }
                }

                self::setAttribute($attrs, 'gen_ai.usage.total_tokens', (int) $report['totalTokens']);
                self::setAttribute($attrs, 'gen_ai.usage.cost_usd', (float) $report['costUsd']);

                $unpriced = $report['unpricedModel'];
                if (\is_string($unpriced) && '' !== $unpriced) {
                    self::setAttribute($attrs, 'candy_code.model.unpriced', $unpriced);
                }
            }

            return;
        }

        if ($event instanceof SpendCapBreached) {
            self::setAttribute($attrs, 'candy_code.spend.breached', true);
            self::setAttribute($attrs, 'candy_code.spend.completed_calls', $event->completedCalls);
            self::setAttribute($attrs, 'candy_code.spend.usd', $event->spentUsd);
            self::setAttribute($attrs, 'candy_code.spend.cap_usd', $event->capUsd);

            return;
        }

        if ($event instanceof ToolStarted) {
            self::setAttribute($attrs, 'tool.name', $event->toolName);
            self::setAttribute($attrs, 'tool.call_id', $event->toolCallId);
            self::setAttribute($attrs, 'tool.argument_count', \count($event->arguments));

            return;
        }

        if ($event instanceof ToolFinished) {
            self::setAttribute($attrs, 'tool.finished_name', $event->toolName);
            self::setAttribute($attrs, 'tool.finished_call_id', $event->toolCallId);

            return;
        }

        if ($event instanceof ToolProgress) {
            self::setAttribute($attrs, 'tool.progress_seq', $event->seq);
            self::setAttribute($attrs, 'tool.progress_truncated', $event->truncated);

            return;
        }

        if ($event instanceof MediaProgress) {
            self::setAttribute($attrs, 'media.call_id', $event->toolCallId);
            if (null !== $event->progress) {
                self::setAttribute($attrs, 'media.progress', $event->progress);
            }
            if (null !== $event->eta) {
                self::setAttribute($attrs, 'media.eta_seconds', $event->eta);
            }

            return;
        }

        if ($event instanceof PermissionAsked) {
            self::setAttribute($attrs, 'permission.ask_id', $event->ask->askId);
            self::setAttribute($attrs, 'permission.tool', $event->ask->tool);

            return;
        }

        if ($event instanceof PermissionResolved) {
            self::setAttribute($attrs, 'permission.resolved_ask_id', $event->askId);
            self::setAttribute($attrs, 'permission.permits', $event->permits());
            if ($event->cancelled) {
                self::setAttribute($attrs, 'permission.cancelled', true);
            }

            return;
        }

        if ($event instanceof TokenDelta) {
            self::setAttribute($attrs, 'token.delta_chars', self::counter($attrs, 'token.delta_chars') + \strlen($event->text));

            return;
        }

        if ($event instanceof ReasoningDelta) {
            self::setAttribute($attrs, 'reasoning.delta_chars', self::counter($attrs, 'reasoning.delta_chars') + \strlen($event->text));

            return;
        }

        if ($event instanceof SubAgentActivity) {
            self::setAttribute($attrs, 'subagent.op', $event->op);
            self::setAttribute($attrs, 'subagent.id', $event->id);
            self::setAttribute($attrs, 'subagent.name', $event->name);

            return;
        }

        if ($event instanceof ContextLedgerChanged) {
            self::setAttribute($attrs, 'context.ledger_call_id', $event->toolCallId);

            return;
        }

        if ($event instanceof TodoUpdated) {
            self::setAttribute($attrs, 'todo.updated', true);
        }
    }

    /**
     * C-SEC-6.e: the safety verdicts of a turn — every denial, ask and
     * non-approval settlement — additionally ride the span as ordered
     * span events, so a collector can chart verdicts over the turn's
     * timeline instead of reading a last-write-wins attribute.
     *
     * The privacy law of the class docblock BINDS HERE HARDEST: the only
     * strings this method may ever place in an event are the enum tokens,
     * the fixed decision words, and identifiers/shape fields that are
     * already exported as attributes (tool name, ask source, permission
     * mode, ask id). A denial's reason body and a rejection's note — both
     * of which can quote refused command text or user prose — are read
     * ONLY to test them against the enum's own prefix, never copied out;
     * the exported `reason_token` is a word of the ENUM, not a word of
     * the carried string.
     *
     * @param list<array<string, mixed>> $events
     */
    private static function addSafetyEvent(array &$events, object $event, int $nanos): void
    {
        $attrs = [];

        if ($event instanceof ToolFinished) {
            $kind = $event->result->denial();
            if (null === $kind) {
                // An ordinary finish (success or failure) is not a verdict.
                return;
            }

            self::setAttribute($attrs, 'decision', 'deny');
            self::setAttribute($attrs, 'kind', $kind->token());
            self::setAttribute($attrs, 'tool', $event->toolName);
            $reasonToken = self::reasonPrefixToken($kind, $event->result->content());
            if (null !== $reasonToken) {
                self::setAttribute($attrs, 'reason_token', $reasonToken);
            }
        } elseif ($event instanceof PermissionAsked) {
            self::setAttribute($attrs, 'decision', 'ask');
            self::setAttribute($attrs, 'tool', $event->ask->tool);
            self::setAttribute($attrs, 'source', $event->ask->source);
            self::setAttribute($attrs, 'mode', $event->ask->mode);
        } elseif ($event instanceof PermissionResolved) {
            if ($event->permits()) {
                // An approval is not a refusal; it gets no safety event.
                return;
            }

            // The two refusals the PermissionResolved contract keeps apart
            // stay apart here: somebody said no, versus nobody was left to.
            $kind = $event->cancelled ? DenialKind::Unanswered : DenialKind::Refused;
            self::setAttribute($attrs, 'decision', $event->cancelled ? 'cancel' : 'reject');
            self::setAttribute($attrs, 'kind', $kind->token());
            self::setAttribute($attrs, 'ask_id', $event->askId);
        } else {
            return;
        }

        $events[] = [
            'timeUnixNano' => (string) $nanos,
            'name' => self::SAFETY_EVENT_NAME,
            'attributes' => array_values($attrs),
        ];
    }

    /**
     * The first word of the kind's own prefix, and ONLY when $content
     * genuinely opens with that prefix.
     *
     * The returned string is a slice of the ENUM (`Permission` for the two
     * permission kinds, `Hook` for the hook kind), never a slice of the
     * carried reason text — that is what makes "first reason token" safe to
     * export even though a reason body may quote the refused command. An
     * unprefixed carrier (a producer that broke the one-space law of
     * {@see DenialKind::reason()}) exports nothing: a missing token is
     * honest, a leaked command word is not.
     */
    private static function reasonPrefixToken(DenialKind $kind, string $content): ?string
    {
        if (!\str_starts_with($content, $kind->value)) {
            return null;
        }

        $words = \explode(' ', $kind->value, 2);

        return $words[0];
    }

    /**
     * @param array<string, array{key: string, value: array<string, string|int|bool|float>}> $attrs
     */
    private static function counter(array $attrs, string $key): int
    {
        $existing = $attrs[$key]['value']['intValue'] ?? '0';

        return (int) $existing;
    }

    /**
     * @param array<string, array{key: string, value: array<string, string|int|bool|float>}> $attrs
     */
    private static function setAttribute(array &$attrs, string $key, string|int|float|bool $value): void
    {
        $attrs[$key] = ['key' => $key, 'value' => self::attributeValue($value)];
    }

    /**
     * @return array<string, string|int|bool|float>
     */
    private static function attributeValue(string|int|float|bool $value): array
    {
        if (\is_float($value)) {
            return ['doubleValue' => $value];
        }

        if (\is_int($value)) {
            return ['intValue' => (string) $value];
        }

        if (\is_bool($value)) {
            return ['boolValue' => $value];
        }

        return ['stringValue' => $value];
    }

    private static function wallClockNanos(): int
    {
        return (int) (\microtime(true) * 1_000_000_000);
    }

    private static function randomBytes(int $length): string
    {
        try {
            return random_bytes($length);
        } catch (\Throwable) {
            return str_repeat('0', $length);
        }
    }
}
