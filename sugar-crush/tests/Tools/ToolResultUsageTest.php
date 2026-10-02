<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Usage;

/**
 * Audit B4's carrier: a tool that billed a provider itself (a Task
 * sub-agent) reports that spend on its {@see ToolResult}, and every seam the
 * result passes on its way to {@see \SugarCraft\Crush\Backend\EngineBackend}'s
 * turn sum keeps it — the result message, the fork-IPC codec, and each of
 * {@see Runtime}'s rewrites (hook notes, the UTF-8 scrub, a PostToolUse
 * withhold). One rebuild that spells its fields out and forgets the new one
 * is all it takes for a delegated run's dollars to leak off the spend cap,
 * so each rewrite is pinned on its own.
 *
 * Runtime's rewrites are private statics; they are driven by reflection
 * because the behaviour under test is exactly "this one rebuild keeps the
 * field", which a full turn would only show in aggregate.
 */
final class ToolResultUsageTest extends TestCase
{
    public function testAToolResultCarriesItsOwnUsageAndKeepsItOffTheProviderWire(): void
    {
        $usage = self::billed();
        $result = new ToolResult(toolCallId: 'c1', content: 'report', usage: $usage);

        $this->assertSame($usage, $result->usage());
        $this->assertNull((new ToolResult(toolCallId: 'c1', content: 'x'))->usage(), 'no spend is the default');
        $this->assertArrayNotHasKey('usage', $result->toArray(), 'accounting is not something the model reads');
    }

    public function testWithContentReplacesOnlyTheTextAndCarriesEveryOtherField(): void
    {
        $usage = self::billed();
        $result = new ToolResult(
            toolCallId: 'c1',
            content: 'before',
            isError: true,
            durationMs: 12,
            imageBytes: 'PNG',
            imagePath: '/tmp/x.png',
            imageProtocol: 'kitty',
            diff: "--- a/x\n+++ b/x\n",
            usage: $usage,
        );

        $rewritten = $result->withContent('after');

        $this->assertSame('after', $rewritten->content());
        $this->assertSame('before', $result->content(), 'immutable: the original is untouched');
        $this->assertSame(
            ['c1', true, 12, 'PNG', '/tmp/x.png', 'kitty', "--- a/x\n+++ b/x\n", $usage],
            [
                $rewritten->toolCallId(), $rewritten->isError(), $rewritten->durationMs(), $rewritten->imageBytes(),
                $rewritten->imagePath(), $rewritten->imageProtocol(), $rewritten->diff(), $rewritten->usage(),
            ],
        );
    }

    public function testWithUsageSetsAndClearsTheSpend(): void
    {
        $usage = self::billed();
        $result = (new ToolResult(toolCallId: 'c1', content: 'x'))->withUsage($usage);

        $this->assertSame($usage, $result->usage());
        $this->assertNull($result->withUsage(null)->usage());
        $this->assertSame('x', $result->withUsage(null)->content());
    }

    public function testAToolResultMessageCarriesUsageButNotOntoTheWire(): void
    {
        $usage = self::billed();
        $message = new ToolResultMessage('c1', 'report', false, null, null, $usage);

        $this->assertSame($usage, $message->usage());
        $this->assertNull((new ToolResultMessage('c1', 'report'))->usage());
        $this->assertArrayNotHasKey('usage', $message->toArray());
    }

    public function testTheRuntimesOneResultMessageBuilderThreadsUsage(): void
    {
        $usage = self::billed();
        $message = self::runtimeStatic('resultMessage', new ToolCall('c1', 'Task', []), new ToolResult('c1', 'report', usage: $usage));

        $this->assertInstanceOf(ToolResultMessage::class, $message);
        $this->assertSame($usage, $message->usage());
    }

    public function testAHookNoteAnnotationKeepsTheUsage(): void
    {
        $usage = self::billed();
        $annotated = self::runtimeStatic('annotate', new ToolResult('c1', 'report', usage: $usage), '[a hook note]');

        $this->assertSame("report\n\n[a hook note]", $annotated->content());
        $this->assertSame($usage, $annotated->usage());
    }

    public function testTheUtf8ScrubKeepsTheUsage(): void
    {
        $usage = self::billed();
        $scrubbed = self::runtimeStatic('utf8Safe', new ToolResult('c1', "caf\xe9", usage: $usage));

        $this->assertTrue(mb_check_encoding($scrubbed->content(), 'UTF-8'), 'the scrub ran');
        $this->assertSame($usage, $scrubbed->usage());
    }

    public function testAWithheldOutputStillCarriesWhatTheRunSpent(): void
    {
        $usage = self::billed();
        $withheld = self::runtimeStatic('withheld', new ToolResult('c1', 'AKIA-secret', usage: $usage), 'secret scanner');

        $this->assertStringContainsString('output withheld by PostToolUse hook', $withheld->content());
        $this->assertStringNotContainsString('AKIA', $withheld->content());
        $this->assertSame($usage, $withheld->usage(), 'withholding the text does not un-spend the run');
    }

    public function testTheForkIpcCodecRoundTripsUsageThroughSerialize(): void
    {
        $usage = Usage::new(totalTokens: 300, costUsd: 1.25, inputTokens: 200, outputTokens: 100, cacheReadTokens: 0, unpricedModel: null);
        $encoded = self::runtimeStatic('encodeResult', new ToolResult('c1', 'report', usage: $usage));

        // The same trip the parallel arm makes: serialize() in the child,
        // unserialize() with no classes allowed in the parent.
        $wire = unserialize(serialize($encoded), ['allowed_classes' => false]);
        $decoded = self::runtimeStatic('decodeResult', $wire, new ToolCall('c1', 'Task', []));

        $this->assertEquals($usage, $decoded->usage());
        $this->assertNull($decoded->usage()?->cacheCreationTokens, 'an unreported bucket stays unreported across the fork');
    }

    public function testAFrameWithoutUsageOrWithAMalformedOneDecodesToNoSpend(): void
    {
        $call = new ToolCall('c1', 'Task', []);
        $legacy = ['toolCallId' => 'c1', 'content' => 'report', 'isError' => false];

        $this->assertNull(self::runtimeStatic('decodeResult', $legacy, $call)->usage(), 'a frame from before B4');
        $malformed = self::runtimeStatic('decodeResult', $legacy + ['usage' => 'garbage'], $call);
        $this->assertNull($malformed->usage());
        $this->assertSame('report', $malformed->content(), 'a corrupt usage costs the accounting, not the call');
    }

    private static function billed(): Usage
    {
        return Usage::new(totalTokens: 120, costUsd: 0.75);
    }

    private static function runtimeStatic(string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod(Runtime::class, $method))->invoke(null, ...$args);
    }
}
