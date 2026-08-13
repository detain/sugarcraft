<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Crush\Tools\ToolCall as EngineToolCall;
use SugarCraft\Crush\Tools\ToolResult as EngineToolResult;

/**
 * Reconciliation of the two ToolCall/ToolResult type pairs crush_feat.md
 * §1 D flags ("different field names ... itself a maintenance hazard") via
 * the lossless adapters §1 E1 asks for.
 *
 * @see ToolCall::toEngineCall()
 * @see ToolResult::toEngineResult()
 */
final class ToolTypeAdapterTest extends TestCase
{
    public function testToEngineCallMapsEveryField(): void
    {
        $engine = (new ToolCall('bash', ['command' => 'ls -la'], 'call_1'))->toEngineCall();

        $this->assertSame('call_1', $engine->id());
        $this->assertSame('bash', $engine->name());
        $this->assertSame(['command' => 'ls -la'], $engine->arguments());
    }

    /**
     * Tools\ToolCall::$id is non-nullable; the fallback must match
     * Message::toolRunning()'s `$call->id ?? $call->name` so the placeholder
     * and the engine-side call key identically.
     */
    public function testToEngineCallFallsBackToTheToolNameWhenTheCallHasNoId(): void
    {
        $this->assertSame('grep', (new ToolCall('grep'))->toEngineCall()->id());
    }

    public function testFromEngineCallMapsEveryField(): void
    {
        $call = ToolCall::fromEngineCall(new EngineToolCall('call_7', 'read', ['path' => '/tmp/x']));

        $this->assertSame('read', $call->name);
        $this->assertSame(['path' => '/tmp/x'], $call->arguments);
        $this->assertSame('call_7', $call->id);
    }

    public function testToolCallSurvivesARoundTripThroughTheEnginePair(): void
    {
        $original = new ToolCall('edit', ['path' => 'a.php', 'old' => 'x'], 'call_9');

        $this->assertEquals($original, ToolCall::fromEngineCall($original->toEngineCall()));
    }

    public function testToEngineResultCollapsesResultAndErrorIntoContentPlusFlag(): void
    {
        $ok = ToolResult::ok('bash', 'total 0', 'call_1')->toEngineResult();
        $this->assertSame('call_1', $ok->toolCallId());
        $this->assertSame('total 0', $ok->content());
        $this->assertFalse($ok->isError());

        $failed = ToolResult::error('bash', 'command not found', 'call_2')->toEngineResult();
        $this->assertSame('command not found', $failed->content());
        $this->assertTrue($failed->isError());
    }

    public function testToEngineResultFallsBackToTheToolNameWhenTheResultHasNoId(): void
    {
        $this->assertSame('bash', ToolResult::ok('bash', 'out')->toEngineResult()->toolCallId());
    }

    /**
     * The whole point of the reconciliation: $diff (W1.F1, engine-side only)
     * and the image fields (W1.G2, Chat-side only) must both cross, in both
     * directions -- otherwise crush_feat.md §1 E3's diff can never reach
     * Renderer, which only ever sees the Chat-side ToolResult.
     */
    public function testToEngineResultCarriesTheDiffAndImageFields(): void
    {
        $engine = (new ToolResult(
            name: 'edit',
            result: 'File updated: a.php',
            error: null,
            id: 'call_3',
            imageBytes: 'PNGBYTES',
            imagePath: '/tmp/shot.png',
            imageProtocol: 'kitty',
            diff: "--- a/a.php\n+++ b/a.php\n@@ -1 +1 @@\n-x\n+y\n",
            durationMs: 42,
        ))->toEngineResult();

        $this->assertSame("--- a/a.php\n+++ b/a.php\n@@ -1 +1 @@\n-x\n+y\n", $engine->diff());
        $this->assertTrue($engine->hasDiff());
        $this->assertSame('PNGBYTES', $engine->imageBytes());
        $this->assertSame('/tmp/shot.png', $engine->imagePath());
        $this->assertSame('kitty', $engine->imageProtocol());
        $this->assertSame(42, $engine->durationMs());
    }

    public function testFromEngineResultMapsASuccessfulEngineResult(): void
    {
        $result = ToolResult::fromEngineResult(
            new EngineToolResult(
                toolCallId: 'call_4',
                content: 'File updated: a.php',
                isError: false,
                durationMs: 7,
                imageBytes: 'PNGBYTES',
                imagePath: '/tmp/shot.png',
                imageProtocol: 'sixel',
                diff: "--- a/a.php\n+++ b/a.php\n",
            ),
            'edit',
        );

        $this->assertSame('edit', $result->name);
        $this->assertSame('File updated: a.php', $result->result);
        $this->assertNull($result->error);
        $this->assertFalse($result->isError());
        $this->assertSame('call_4', $result->id);
        $this->assertSame(7, $result->durationMs);
        $this->assertSame('PNGBYTES', $result->imageBytes);
        $this->assertTrue($result->hasImage());
        $this->assertSame('/tmp/shot.png', $result->imagePath);
        $this->assertSame('sixel', $result->imageProtocol);
        $this->assertSame("--- a/a.php\n+++ b/a.php\n", $result->diff);
        $this->assertTrue($result->hasDiff());
    }

    public function testFromEngineResultRoutesAnErroredEngineResultIntoTheErrorField(): void
    {
        $result = ToolResult::fromEngineResult(
            new EngineToolResult(toolCallId: 'call_5', content: 'boom', isError: true),
            'bash',
        );

        $this->assertSame('boom', $result->error);
        $this->assertSame('', $result->result);
        $this->assertTrue($result->isError());
        $this->assertNull($result->diff);
        $this->assertFalse($result->hasDiff());
    }

    public function testSuccessfulToolResultSurvivesARoundTripThroughTheEnginePair(): void
    {
        $original = new ToolResult(
            name: 'edit',
            result: 'File updated: a.php',
            error: null,
            id: 'call_6',
            imageBytes: 'PNGBYTES',
            imagePath: '/tmp/shot.png',
            imageProtocol: 'kitty',
            diff: "--- a/a.php\n+++ b/a.php\n",
            durationMs: 11,
        );

        $this->assertEquals($original, ToolResult::fromEngineResult($original->toEngineResult(), 'edit'));
    }

    public function testErroredToolResultSurvivesARoundTripThroughTheEnginePair(): void
    {
        $original = ToolResult::error('bash', 'command not found', 'call_8');

        $this->assertEquals($original, ToolResult::fromEngineResult($original->toEngineResult(), 'bash'));
    }

    /**
     * withImage() rebuilds the instance field by field, so it has to carry
     * the two newly-reconciled fields too or attaching a screenshot would
     * silently drop an already-computed diff.
     */
    public function testWithImagePreservesTheDiffAndDuration(): void
    {
        $result = (new ToolResult(
            name: 'edit',
            result: 'File updated: a.php',
            diff: "--- a/a.php\n+++ b/a.php\n",
            durationMs: 3,
        ))->withImage('PNGBYTES', '/tmp/shot.png');

        $this->assertSame("--- a/a.php\n+++ b/a.php\n", $result->diff);
        $this->assertSame(3, $result->durationMs);
        $this->assertSame('PNGBYTES', $result->imageBytes);
    }

    /** The new fields default to null, so existing call sites are untouched. */
    public function testDiffAndDurationDefaultToNull(): void
    {
        $result = ToolResult::ok('bash', 'out');

        $this->assertNull($result->diff);
        $this->assertFalse($result->hasDiff());
        $this->assertNull($result->durationMs);
    }

    /**
     * crush_feat.md §3 E2: the call's one-liner rides on the RESULT so a
     * finished (and therefore collapsed) row can still say what ran.
     */
    public function testWithDescriptionAttachesTheCallsOneLinerWithoutTouchingAnythingElse(): void
    {
        $result = ToolResult::ok('bash', 'a.txt', 'call_1');
        $described = $result->withDescription('bash(command: "ls -la")');

        $this->assertSame('bash(command: "ls -la")', $described->description);
        $this->assertTrue($described->hasDescription());
        $this->assertSame('a.txt', $described->result);
        $this->assertSame('call_1', $described->id);

        // Immutable+fluent: the original is untouched (AGENTS.md).
        $this->assertNull($result->description);
        $this->assertFalse($result->hasDescription());
    }

    /**
     * A placeholder that never carried a describeToolCall() one-liner would
     * otherwise make the renderer draw a dangling " — " separator.
     */
    public function testWithDescriptionTreatsBlankAsUnknown(): void
    {
        $this->assertFalse(ToolResult::ok('bash', 'out')->withDescription('   ')->hasDescription());
        $this->assertFalse(ToolResult::ok('bash', 'out')->withDescription(null)->hasDescription());
    }

    /** withImage() rebuilds field by field, so it must carry this one too. */
    public function testWithImagePreservesTheDescription(): void
    {
        $result = ToolResult::ok('bash', 'out')
            ->withDescription('bash(command: "ls")')
            ->withImage('PNGBYTES');

        $this->assertSame('bash(command: "ls")', $result->description);
    }
}
