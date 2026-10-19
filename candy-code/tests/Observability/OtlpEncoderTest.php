<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Observability;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\PendingAsk;
use SugarCraft\Code\Context\Pruning\LedgerDelta;
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
use SugarCraft\Code\Observability\OtlpEncoder;
use SugarCraft\Code\Permissions\DenialKind;
use SugarCraft\Code\Todo\TodoList;
use SugarCraft\Code\Tools\ToolResult;
use SugarCraft\Code\Usage;

/**
 * OBS-1: the OTLP/HTTP-JSON encoder's span lifecycle, attribute shape,
 * attribution rules and the two laws the mission names — payload privacy
 * (identifiers and counts only, never content) and the unpricedModel
 * marker that keeps a $0.0 cost from reading as "free".
 */
final class OtlpEncoderTest extends TestCase
{
    public function testStepOpensTheTurnSpanAndUsageClosesItWithOneBody(): void
    {
        $encoder = new OtlpEncoder(['session.id' => 'sess-7']);
        $encoder->encode(new StepStarted(1, 40), 1_000);

        $this->assertSame(1, $encoder->openSpanCount());
        $body = $encoder->encode(new UsageUpdated(1, null, self::pricedUsage()), 2_500);
        $this->assertNotNull($body);
        $this->assertSame(0, $encoder->openSpanCount(), 'a closed span leaves the open set forever');

        $span = $body['resourceSpans'][0]['scopeSpans'][0]['spans'][0];
        $this->assertSame('chat.turn', $span['name']);
        $this->assertSame(1, $span['kind']);
        $this->assertSame('1000', $span['startTimeUnixNano']);
        $this->assertSame('2500', $span['endTimeUnixNano']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $span['traceId']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $span['spanId']);

        $resource = $body['resourceSpans'][0]['resource']['attributes'];
        $this->assertSame('service.name', $resource[0]['key']);
        $this->assertSame('candy-code', $resource[0]['value']['stringValue']);
        $this->assertSame('session.id', $resource[1]['key']);
    }

    public function testASecondReportForAClosedStepIsDroppedNotReEmitted(): void
    {
        $encoder = new OtlpEncoder();
        $encoder->encode(new StepStarted(1, 5), 10);
        $this->assertNotNull($encoder->encode(new UsageUpdated(1, null, self::pricedUsage()), 20));

        $this->assertNull($encoder->encode(new UsageUpdated(1, null, self::pricedUsage()), 30));
    }

    public function testUnreportedUsageBucketsAreAbsentNotZero(): void
    {
        $encoder = new OtlpEncoder();
        $encoder->encode(new StepStarted(2, 5), 10);
        $bare = Usage::new(totalTokens: 0, costUsd: 0.0);
        $body = $encoder->encode(new UsageUpdated(2, $bare, null), 20);

        $keys = array_column($body['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['attributes'], 'key');
        $this->assertNotContains('gen_ai.usage.input_tokens', $keys);
        $this->assertContains('gen_ai.usage.total_tokens', $keys, 'totalTokens is a reported zero and does ship');
    }

    public function testUnpricedModelRidesAsAnAttributeBesideTheZeroCost(): void
    {
        $encoder = new OtlpEncoder();
        $encoder->encode(new StepStarted(3, 5), 10);
        $usage = Usage::new(totalTokens: 7, costUsd: 0.0, unpricedModel: 'mystery-7b');
        $body = $encoder->encode(new UsageUpdated(3, null, $usage), 20);

        $attrs = self::attributeMap($body);
        $this->assertSame('mystery-7b', $attrs['candy_code.model.unpriced']['stringValue']);
        $this->assertSame(0.0, $attrs['gen_ai.usage.cost_usd']['doubleValue']);
    }

    public function testSpendBreachClosesTheTurnAndStampsTheCeiling(): void
    {
        $encoder = new OtlpEncoder();
        $encoder->encode(new StepStarted(4, 5), 10);
        $body = $encoder->encode(new SpendCapBreached(3, 1.25, 1.0), 40);

        $attrs = self::attributeMap($body);
        $this->assertTrue($attrs['candy_code.spend.breached']['boolValue']);
        $this->assertSame('3', $attrs['candy_code.spend.completed_calls']['intValue']);
        $this->assertSame(1.0, $attrs['candy_code.spend.cap_usd']['doubleValue']);
    }

    public function testSteplessEventsAttributeToTheSingleOpenSpanOnly(): void
    {
        $encoder = new OtlpEncoder();
        $encoder->encode(new StepStarted(1, 9), 10);
        $encoder->encode(new ToolStarted('c1', 'Bash', ['command' => 'ls']), 15);

        // One span open → the tool event rode it. Drain it and look.
        $drained = $encoder->drainOpen(999);
        $attrs = self::attributeMap($drained);
        $this->assertSame('Bash', $attrs['tool.name']['stringValue']);
        $this->assertSame('c1', $attrs['tool.call_id']['stringValue']);

        // Two spans open → stepless events are ambiguous and dropped.
        $both = new OtlpEncoder();
        $both->encode(new StepStarted(1, 9), 10);
        $both->encode(new StepStarted(2, 9), 11);
        $both->encode(new ToolStarted('c2', 'Read', []), 12);
        $drained2 = self::drainAttributes($both->drainOpen(500));
        foreach ($drained2 as $attributes) {
            $this->assertArrayNotHasKey('tool.name', $attributes);
        }
    }

    public function testDeltasAccumulateAsCountsNeverAsText(): void
    {
        $secret = 'SENTINEL-delta-body-DO-NOT-EXPORT';
        $encoder = new OtlpEncoder();
        $encoder->encode(new StepStarted(1, 9), 10);
        $encoder->encode(new TokenDelta($secret), 20);
        $encoder->encode(new TokenDelta($secret), 25);
        $encoder->encode(new ReasoningDelta($secret), 30);
        $body = $encoder->encode(new UsageUpdated(1, null, self::pricedUsage()), 40);

        $json = json_encode($body, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($secret, $json);
        $attrs = self::attributeMap($body);
        $this->assertSame((string) (2 * strlen($secret)), $attrs['token.delta_chars']['intValue']);
        $this->assertSame((string) strlen($secret), $attrs['reasoning.delta_chars']['intValue']);
    }

    public function testNoPayloadFieldOfAnyEventEverReachesTheWire(): void
    {
        $sentinels = [
            'SENTINEL-tool-arguments',
            'SENTINEL-tool-tail',
            'SENTINEL-media-frame',
            'SENTINEL-subagent-task',
            'SENTINEL-ask-reason',
        ];
        [$a, $b, $c, $d, $e] = $sentinels;

        $encoder = new OtlpEncoder();
        $encoder->encode(new StepStarted(1, 9), 10);
        $encoder->encode(new ToolStarted('call-1', 'Write', ['content' => $a]), 11);
        $encoder->encode(new ToolFinished('call-1', 'Write', ToolResult::denied('call-1', DenialKind::Refused, 'x')), 12);
        $encoder->encode(new ToolProgress(toolCallId: 'call-1', toolName: 'Bash', tail: $b, seq: 4, truncated: true, tailBudget: 2000), 13);
        $encoder->encode(new MediaProgress(toolCallId: 'call-1', frameB64: $c, progress: 0.5, eta: 1.25), 14);
        $encoder->encode(new SubAgentActivity(op: 'progress', id: 'ag-1', name: 'lane', task: $d, seq: 1, tail: 'ignored'), 15);
        $encoder->encode(new PermissionAsked(new PendingAsk(
            askId: 'ask-9',
            toolCallId: 'call-9',
            tool: 'Bash',
            arguments: ['command' => $a],
            reason: $e,
            source: PendingAsk::TOOL_SOURCE_PREFIX . 'Bash',
            mode: 'default',
            suggestions: [],
            alwaysScope: [],
            settle: static function (): void {},
        )), 16);
        $encoder->encode(PermissionResolved::cancelled('ask-9'), 17);
        $encoder->encode(new ContextLedgerChanged(LedgerDelta::new(), 'call-9'), 18);
        $encoder->encode(new TodoUpdated('call-9', TodoList::new()), 19);
        $body = $encoder->encode(new UsageUpdated(1, null, self::pricedUsage()), 20);

        $json = json_encode($body, JSON_THROW_ON_ERROR);
        foreach ($sentinels as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $json);
        }

        $attrs = self::attributeMap($body);
        $this->assertSame('1', $attrs['tool.argument_count']['intValue'], 'the COUNT of arguments is metadata, not payload');
        $this->assertSame('Write', $attrs['tool.name']['stringValue']);
        $this->assertSame('Write', $attrs['tool.finished_name']['stringValue']);
        $this->assertSame('4', $attrs['tool.progress_seq']['intValue']);
        $this->assertTrue($attrs['tool.progress_truncated']['boolValue']);
        $this->assertSame(0.5, $attrs['media.progress']['doubleValue']);
        $this->assertSame(1.25, $attrs['media.eta_seconds']['doubleValue']);
        $this->assertSame('progress', $attrs['subagent.op']['stringValue']);
        $this->assertSame('ask-9', $attrs['permission.ask_id']['stringValue']);
        $this->assertSame('Bash', $attrs['permission.tool']['stringValue']);
        $this->assertFalse($attrs['permission.permits']['boolValue']);
        $this->assertTrue($attrs['permission.cancelled']['boolValue']);
        $this->assertSame('call-9', $attrs['context.ledger_call_id']['stringValue']);
        $this->assertTrue($attrs['todo.updated']['boolValue']);
    }

    public function testSteplessEventsAreDroppedWhileNoSpanIsOpen(): void
    {
        $encoder = new OtlpEncoder();

        $this->assertNull($encoder->encode(new SpendCapBreached(1, 1.0, 0.5)));
        $this->assertNull($encoder->encode(new ToolStarted('c', 'T', [])));
        $this->assertSame(0, $encoder->openSpanCount());
    }

    public function testUnknownObjectsAreIgnoredEntirely(): void
    {
        $encoder = new OtlpEncoder();
        $encoder->encode(new StepStarted(1, 9), 10);

        $this->assertNull($encoder->encode(new \stdClass()));
        $this->assertSame(1, $encoder->openSpanCount());
        $drained = $encoder->drainOpen(500);
        $this->assertArrayNotHasKey('permission.permits', self::attributeMap($drained));
    }

    public function testDrainOpenRendersAndClearsExactlyOnce(): void
    {
        $encoder = new OtlpEncoder();
        $encoder->encode(new StepStarted(1, 9), 10);
        $encoder->encode(new StepStarted(2, 9), 12);

        $drained = $encoder->drainOpen(500);
        $this->assertCount(2, $drained['resourceSpans'][0]['scopeSpans'][0]['spans']);
        $this->assertSame(0, $encoder->openSpanCount());
        $this->assertNull($encoder->drainOpen(600), 'the drain arm reports nothing twice');
    }

    public function testPressureRidesOnlyWhenTheStepCarriesIt(): void
    {
        $plain = new OtlpEncoder();
        $plain->encode(new StepStarted(1, 9), 10);
        $this->assertArrayNotHasKey('context.pressure_tokens', self::onlySpanAttributes($plain->drainOpen(20)));

        $pressed = new OtlpEncoder();
        $pressed->encode(new StepStarted(1, 9, new \SugarCraft\Code\Context\ContextPressure(
            90_000,
            null,
            10,
            90_000,
            1_000,
            100,
            85_000,
            100_000,
        )), 10);
        $this->assertArrayHasKey('context.pressure_tokens', self::onlySpanAttributes($pressed->drainOpen(20)));
    }

    private static function pricedUsage(): Usage
    {
        return Usage::new(totalTokens: 100, costUsd: 0.02, unpricedModel: null)
            ->withInputTokens(70)
            ->withOutputTokens(30);
    }

    /** @param array<string, mixed> $body @return array<string, array<string, string|int|bool|float>> */
    private static function attributeMap(array $body): array
    {
        $map = [];
        foreach ($body['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['attributes'] as $entry) {
            $map[$entry['key']] = $entry['value'];
        }

        return $map;
    }

    /** @param array<string, mixed> $body @return array<string, array<string, array<string, string|int|bool|float>>> */
    private static function drainAttributes(array $body): array
    {
        $out = [];
        foreach ($body['resourceSpans'][0]['scopeSpans'][0]['spans'] as $span) {
            $map = [];
            foreach ($span['attributes'] as $entry) {
                $map[$entry['key']] = $entry['value'];
            }
            $out[] = $map;
        }

        return $out;
    }

    /** @param array<string, mixed> $body @return array<string, array<string, string|int|bool|float>> */
    private static function onlySpanAttributes(array $body): array
    {
        return self::attributeMap($body);
    }
}
