<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\ContextBudget;
use SugarCraft\Crush\Context\ContextPressure;
use SugarCraft\Crush\Context\ContextWindow;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Usage;
use SugarCraft\Crush\Util\TokenEstimate;

/**
 * Roadmap 2.1's step budget and the pressure value measured against it.
 */
final class ContextBudgetTest extends TestCase
{
    public function testAMillionTokenWindowIsBoundByEightyPercent(): void
    {
        $this->assertSame(800_000, ContextBudget::new(1_000_000, 32_000)->threshold());
    }

    public function testTheOutputCeilingAndReserveBindAMidSizedWindow(): void
    {
        // 400k − 100k − 64Ki, below 80% (320k).
        $this->assertSame(400_000 - 100_000 - 65_536, ContextBudget::new(400_000, 100_000)->threshold());
        // Below 320k the reserve is a fifth of the window: 200k − 8k − 40k.
        $this->assertSame(152_000, ContextBudget::new(200_000, 8_000)->threshold());
    }

    public function testTheReserveIsCappedAtAFifthOfTheWindow(): void
    {
        // The literal rule would leave 128k − 32k − 64k = 32k; the capped
        // reserve (25,600) keeps 70,400.
        $this->assertSame(128_000 - 32_000 - 25_600, ContextBudget::new(128_000, 32_000)->threshold());
    }

    public function testNoOutputCeilingLeavesOnlyTheReserve(): void
    {
        $this->assertSame(80_000, ContextBudget::new(100_000)->threshold());
        $this->assertNull(ContextBudget::new(100_000, 0)->maxOutputTokens, 'a non-positive ceiling is no ceiling');
    }

    public function testAnOutputCeilingPastTheWindowFallsBackToTheShare(): void
    {
        $this->assertSame(6_553, ContextBudget::new(8_192, 16_000)->threshold());
    }

    public function testAnUnknownWindowTakesTheSharedFallback(): void
    {
        $this->assertSame(ContextWindow::FALLBACK_TOKENS, ContextBudget::new(0)->window);
    }

    public function testTheFirstStepIsEstimatedAndCountsTheSystemPromptAndToolSchemas(): void
    {
        $tool = self::tool();
        $request = new CompleteRequest(model: 'm', messages: [new UserMessage('hello there')], tools: [$tool], systemPrompt: str_repeat('s', 400));

        $pressure = ContextPressure::measure(ContextBudget::new(100_000), $request, [new UserMessage('hello there')]);

        $this->assertNull($pressure->anchorTokens);
        $this->assertSame(100, $pressure->systemTokens);
        $this->assertSame(TokenEstimate::ofToolSchemas([$tool]), $pressure->toolTokens);
        $this->assertGreaterThan(0, $pressure->toolTokens);
        $this->assertSame(100 + $pressure->toolTokens + ContextPressure::MESSAGE_OVERHEAD_TOKENS + 3, $pressure->estimatedTokens);
        $this->assertSame($pressure->estimatedTokens, $pressure->tokens);
        $this->assertSame(0, $pressure->deltaTokens);
        $this->assertFalse($pressure->isOverBudget());
    }

    public function testALaterStepIsTheProvidersCountPlusTheRowsAddedSince(): void
    {
        $rows = [
            new UserMessage('go'),
            new AssistantMessage('reading', [new ToolCall('c1', 'probe', ['path' => 'a'])]),
            new ToolResultMessage('c1', str_repeat('x', 4000)),
        ];
        $request = new CompleteRequest(model: 'm', messages: $rows);

        $pressure = ContextPressure::measure(ContextBudget::new(100_000), $request, $rows, 5_000, 1);

        $this->assertSame(5_000, $pressure->anchorTokens);
        $this->assertSame(ContextPressure::ofMessages(array_slice($rows, 1)), $pressure->deltaTokens);
        $this->assertSame(5_000 + $pressure->deltaTokens, $pressure->tokens);
        $this->assertGreaterThan(1_000, $pressure->deltaTokens, 'the tool result is in the delta');
    }

    public function testTheVerdictTurnsAtTheThreshold(): void
    {
        $budget = ContextBudget::new(10_000);
        $request = new CompleteRequest(model: 'm', messages: []);

        $this->assertFalse(ContextPressure::measure($budget, $request, [], 7_999)->isOverBudget());
        $over = ContextPressure::measure($budget, $request, [], 8_000);
        $this->assertTrue($over->isOverBudget());
        $this->assertSame(80, $over->percentOfWindow());
    }

    public function testTheAnchorFallsBackThroughTheBucketsTheProviderDidReport(): void
    {
        $this->assertNull(ContextPressure::promptTokensOf(null));
        $this->assertSame(900, ContextPressure::promptTokensOf(Usage::new(1_000, 0.0, 600, 100, 200, 100)), 'all three buckets');
        $this->assertSame(800, ContextPressure::promptTokensOf(Usage::new(1_000, 0.0, 600, 200, 200)), 'OpenAI-shaped: no cache-creation bucket');
        $this->assertSame(700, ContextPressure::promptTokensOf(Usage::new(1_000, 0.0, null, 300)), 'only the total and the output side');
        $this->assertNull(ContextPressure::promptTokensOf(Usage::new(1_000)), 'a bare total is no anchor');
    }

    public function testThePressureSurvivesTheFrameRoundTripAndRefusesForeignShapes(): void
    {
        $pressure = new ContextPressure(9, 5, 4, 12, 2, 3, 80, 100);

        $this->assertEquals($pressure, ContextPressure::fromArray(unserialize(serialize($pressure->toArray()), ['allowed_classes' => false])));
        $this->assertNull(ContextPressure::fromArray(['tokens' => '9'] + $pressure->toArray()));
        $this->assertNull(ContextPressure::fromArray(['anchorTokens' => 1.5] + $pressure->toArray()));
        $this->assertNull(ContextPressure::fromArray('junk'));
    }

    private static function tool(): Tool
    {
        return new class implements Tool {
            public function name(): string { return 'probe'; }

            public function description(): string { return 'Reads one path and says what is there.'; }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => ['path' => ['type' => 'string', 'description' => 'the path to read']]];
            }

            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: '', content: 'ok');
            }
        };
    }
}
