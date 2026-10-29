<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Observability;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\PendingAsk;
use SugarCraft\Code\Events\PermissionAsked;
use SugarCraft\Code\Events\PermissionResolved;
use SugarCraft\Code\Events\StepStarted;
use SugarCraft\Code\Events\ToolFinished;
use SugarCraft\Code\Events\UsageUpdated;
use SugarCraft\Code\Observability\OtlpEncoder;
use SugarCraft\Code\Permissions\DenialKind;
use SugarCraft\Code\Permissions\PermissionReply;
use SugarCraft\Code\Tools\ToolResult;
use SugarCraft\Code\Usage;

/**
 * C-SEC-6.e: every safety verdict — a denial on a finished tool call, an
 * ask, and a non-approval settlement — rides the turn's span as a
 * `candy_code.safety_event` span event stamped at its OBSERVED instant.
 *
 * The pins mirror the two laws the step exists under:
 * - PRIVACY: a denial reason can quote the refused command text, so a deny
 *   event carries only the enum kind token, the tool name, and the first
 *   word of the enum's own prefix. The reason BODY and a rejection NOTE
 *   are never exported — each fixture buries a sentinel inside them and
 *   the whole body is scanned.
 * - VERDICT FIDELITY: approve emits nothing; a cancellation is kept apart
 *   from a reject exactly as the PermissionResolved contract keeps the
 *   two refusals apart.
 */
final class OtlpSafetyEventTest extends TestCase
{
    private const SENTINEL_DETAIL = 'SENTINEL-deny-detail-DO-NOT-EXPORT';
    private const SENTINEL_NOTE = 'SENTINEL-reject-note-DO-NOT-EXPORT';
    private const SENTINEL_REASON = 'SENTINEL-ask-reason-DO-NOT-EXPORT';

    public function testRefusedDenialEmitsOneDenyEventWithPrefixTokenOnly(): void
    {
        $encoder = new OtlpEncoder();
        $encoder->encode(new StepStarted(1, 5), 10);
        $encoder->encode(new ToolFinished(
            'call-1',
            'Bash',
            ToolResult::denied('call-1', DenialKind::Refused, self::SENTINEL_DETAIL),
        ), 25);
        $body = $encoder->encode(new UsageUpdated(1, self::usage(), null), 40);

        $events = self::safetyEvents($body);
        $this->assertCount(1, $events);
        $attrs = self::eventAttributes($events[0]);
        $this->assertSame('deny', $attrs['decision']['stringValue']);
        $this->assertSame('refused', $attrs['kind']['stringValue']);
        $this->assertSame('Bash', $attrs['tool']['stringValue']);
        $this->assertSame(
            'Permission',
            $attrs['reason_token']['stringValue'],
            'the exported token is a word of the ENUM prefix, never a word of the carried reason',
        );

        $this->assertStringNotContainsString(self::SENTINEL_DETAIL, (string) json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function testHookAndUnansweredDenialsCarryTheirOwnKindTokens(): void
    {
        $encoder = new OtlpEncoder();
        $encoder->encode(new StepStarted(1, 5), 10);
        $encoder->encode(new ToolFinished(
            'call-h',
            'Bash',
            ToolResult::denied('call-h', DenialKind::Hook, 'rm -rf ' . self::SENTINEL_DETAIL),
        ), 20);
        $encoder->encode(new ToolFinished(
            'call-u',
            'Task',
            ToolResult::denied('call-u', DenialKind::Unanswered, self::SENTINEL_DETAIL),
        ), 21);
        $body = $encoder->encode(new UsageUpdated(1, self::usage(), null), 40);

        $events = self::safetyEvents($body);
        $this->assertCount(2, $events, 'events arrive in order and one per verdict');

        [$hook, $unanswered] = $events;
        $this->assertSame('hook', self::eventAttributes($hook)['kind']['stringValue']);
        $this->assertSame('Hook', self::eventAttributes($hook)['reason_token']['stringValue']);
        $this->assertSame('unanswered', self::eventAttributes($unanswered)['kind']['stringValue']);
        $this->assertSame('Task', self::eventAttributes($unanswered)['tool']['stringValue']);

        $this->assertStringNotContainsString(self::SENTINEL_DETAIL, (string) json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function testUnprefixedCarrierExportsNoReasonTokenButStillTheVerdict(): void
    {
        // A producer that broke the one-space prefix law of
        // DenialKind::reason() must not feed a command word into the wire:
        // the reason_token arm simply stays absent, the deny itself stays
        // visible.
        $encoder = new OtlpEncoder();
        $encoder->encode(new StepStarted(1, 5), 10);
        $encoder->encode(new ToolFinished('call-x', 'Write', new ToolResult(
            toolCallId: 'call-x',
            content: 'totally unprefixed ' . self::SENTINEL_DETAIL,
            isError: true,
            denial: DenialKind::Refused,
        )), 20);
        $body = $encoder->encode(new UsageUpdated(1, self::usage(), null), 40);

        $events = self::safetyEvents($body);
        $this->assertCount(1, $events);
        $attrs = self::eventAttributes($events[0]);
        $this->assertSame('deny', $attrs['decision']['stringValue']);
        $this->assertArrayNotHasKey('reason_token', $attrs);
        $this->assertStringNotContainsString(self::SENTINEL_DETAIL, (string) json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function testAskEmitsAnAskEventWithSourceAndMode(): void
    {
        $encoder = new OtlpEncoder();
        $encoder->encode(new StepStarted(1, 5), 10);
        $encoder->encode(new PermissionAsked(new PendingAsk(
            askId: 'ask-1',
            toolCallId: 'call-1',
            tool: 'Bash',
            arguments: ['command' => 'echo ' . self::SENTINEL_REASON],
            reason: self::SENTINEL_REASON,
            source: PendingAsk::TOOL_SOURCE_PREFIX . 'Bash',
            mode: 'default',
            suggestions: [],
            alwaysScope: [],
            settle: static function (): void {},
        )), 20);
        $body = $encoder->encode(new UsageUpdated(1, self::usage(), null), 40);

        $events = self::safetyEvents($body);
        $this->assertCount(1, $events);
        $attrs = self::eventAttributes($events[0]);
        $this->assertSame('ask', $attrs['decision']['stringValue']);
        $this->assertSame('Bash', $attrs['tool']['stringValue']);
        $this->assertSame('tool:Bash', $attrs['source']['stringValue']);
        $this->assertSame('default', $attrs['mode']['stringValue']);

        $this->assertStringNotContainsString(self::SENTINEL_REASON, (string) json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function testRejectEmitsItsEventAndApprovalEmitsNone(): void
    {
        $rejected = new OtlpEncoder();
        $rejected->encode(new StepStarted(1, 5), 10);
        $rejected->encode(PermissionResolved::replied('ask-1', PermissionReply::Reject, self::SENTINEL_NOTE), 20);
        $body = $rejected->encode(new UsageUpdated(1, self::usage(), null), 40);

        $events = self::safetyEvents($body);
        $this->assertCount(1, $events);
        $attrs = self::eventAttributes($events[0]);
        $this->assertSame('reject', $attrs['decision']['stringValue']);
        $this->assertSame('refused', $attrs['kind']['stringValue'], 'a reject is the Refused refusal');
        $this->assertSame('ask-1', $attrs['ask_id']['stringValue'], 'the identifier joins the event back to the ask');

        $this->assertStringNotContainsString(self::SENTINEL_NOTE, (string) json_encode($body, JSON_THROW_ON_ERROR));

        $approved = new OtlpEncoder();
        $approved->encode(new StepStarted(1, 5), 10);
        $approved->encode(PermissionResolved::replied('ask-2', PermissionReply::Once), 20);
        $approvedBody = $approved->encode(new UsageUpdated(1, self::usage(), null), 40);
        $this->assertArrayNotHasKey('events', self::span($approvedBody), 'an approval is not a safety event');
    }

    public function testCancellationIsItsOwnDecisionNotAReject(): void
    {
        // The PermissionResolved contract keeps "somebody said no" apart
        // from "nobody was left to answer"; folding them here would
        // re-report a hang-up as a "no".
        $encoder = new OtlpEncoder();
        $encoder->encode(new StepStarted(1, 5), 10);
        $encoder->encode(PermissionResolved::cancelled('ask-3', self::SENTINEL_NOTE), 20);
        $body = $encoder->encode(new UsageUpdated(1, self::usage(), null), 40);

        $events = self::safetyEvents($body);
        $this->assertCount(1, $events);
        $attrs = self::eventAttributes($events[0]);
        $this->assertSame('cancel', $attrs['decision']['stringValue']);
        $this->assertSame('unanswered', $attrs['kind']['stringValue']);

        $this->assertStringNotContainsString(self::SENTINEL_NOTE, (string) json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function testAllowedToolFinishAndOrdinaryFailureEmitNothing(): void
    {
        $encoder = new OtlpEncoder();
        $encoder->encode(new StepStarted(1, 5), 10);
        $encoder->encode(new ToolFinished('call-ok', 'Read', new ToolResult(toolCallId: 'call-ok', content: 'the file contents')), 20);
        $encoder->encode(new ToolFinished('call-bad', 'Read', new ToolResult(toolCallId: 'call-bad', content: 'file not found', isError: true)), 21);
        $body = $encoder->encode(new UsageUpdated(1, self::usage(), null), 40);

        $this->assertArrayNotHasKey('events', self::span($body), 'a finished tool that was allowed is not a verdict');
    }

    public function testSafetyEventsFollowTheSingleOpenSpanAttributionLaw(): void
    {
        // Same law as attributes: stepless events are ambiguous while more
        // than one span is open (parallel agents) and are dropped.
        $encoder = new OtlpEncoder();
        $encoder->encode(new StepStarted(1, 5), 10);
        $encoder->encode(new StepStarted(2, 5), 11);
        $encoder->encode(PermissionResolved::replied('ask-9', PermissionReply::Reject), 12);

        $drained = $encoder->drainOpen(500);
        foreach ($drained['resourceSpans'][0]['scopeSpans'][0]['spans'] as $span) {
            $this->assertArrayNotHasKey('events', $span);
        }
    }

    public function testEventTimeIsTheObservedInstantNotTheCloseOrExportInstant(): void
    {
        // C-SES-4.a's consume-time stamp law, carried onto span events:
        // encodeMany hands back the closed span RAW, and the verdict inside
        // it keeps the instant it was observed at.
        $encoder = new OtlpEncoder();
        $spans = $encoder->encodeMany([
            [new StepStarted(1, 5), 100],
            [new ToolFinished('call-1', 'Bash', ToolResult::denied('call-1', DenialKind::Hook, 'gate said no')), 250],
            [new UsageUpdated(1, self::usage(), null), 400],
        ]);

        $this->assertCount(1, $spans);
        $events = $spans[0]['events'];
        $this->assertSame('250', $events[0]['timeUnixNano'], 'the stamp, not the close, times the verdict');
        $this->assertSame('400', $spans[0]['endTimeUnixNano']);

        $body = $encoder->exportBody($spans);
        $this->assertNotNull($body, 'the one-body batch carries the verdict span');
    }

    public function testMultipleVerdictsRideOneSpanInArrivalOrder(): void
    {
        $encoder = new OtlpEncoder();
        $encoder->encode(new StepStarted(1, 5), 10);
        $encoder->encode(new PermissionAsked(self::ask()), 20);
        $encoder->encode(PermissionResolved::replied('ask-1', PermissionReply::Reject, self::SENTINEL_NOTE), 30);
        $encoder->encode(new ToolFinished('call-9', 'Write', ToolResult::denied('call-9', DenialKind::Refused, self::SENTINEL_DETAIL)), 35);
        $body = $encoder->encode(new UsageUpdated(1, self::usage(), null), 40);

        $events = self::safetyEvents($body);
        $this->assertCount(3, $events);
        $this->assertSame(
            ['ask', 'reject', 'deny'],
            array_map(static fn (array $e): string => self::eventAttributes($e)['decision']['stringValue'], $events),
            'ordered events, never a last-write-wins attribute',
        );
    }

    public function testNameIsTheSingleStableHandleAndSatisfiesTheEncoderSurface(): void
    {
        $encoder = new OtlpEncoder();
        $encoder->encode(new StepStarted(1, 5), 10);
        $encoder->encode(new PermissionAsked(self::ask()), 20);
        $body = $encoder->encode(new UsageUpdated(1, self::usage(), null), 40);

        $events = self::safetyEvents($body);
        $this->assertSame(OtlpEncoder::SAFETY_EVENT_NAME, $events[0]['name']);
        $this->assertSame('candy_code.safety_event', OtlpEncoder::SAFETY_EVENT_NAME);
    }

    private static function ask(): PendingAsk
    {
        return new PendingAsk(
            askId: 'ask-1',
            toolCallId: 'call-9',
            tool: 'Write',
            arguments: [],
            reason: self::SENTINEL_REASON,
            source: PendingAsk::TOOL_SOURCE_PREFIX . 'Write',
            mode: 'acceptEdits',
            suggestions: [],
            alwaysScope: [],
            settle: static function (): void {},
        );
    }

    private static function usage(): Usage
    {
        return Usage::new(totalTokens: 10, costUsd: 0.01);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private static function span(array $body): array
    {
        return $body['resourceSpans'][0]['scopeSpans'][0]['spans'][0];
    }

    /** @param array<string, mixed> $body @return list<array<string, mixed>> */
    private static function safetyEvents(array $body): array
    {
        $events = self::span($body)['events'] ?? [];
        foreach ($events as $event) {
            self::assertSame(OtlpEncoder::SAFETY_EVENT_NAME, $event['name'], 'only safety events are minted today');
        }

        return $events;
    }

    /** @param array<string, mixed> $event @return array<string, array<string, string|int|bool|float>> */
    private static function eventAttributes(array $event): array
    {
        $map = [];
        foreach ($event['attributes'] as $entry) {
            $map[$entry['key']] = $entry['value'];
        }

        return $map;
    }
}
