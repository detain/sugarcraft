<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Context\ContextPressure;
use SugarCraft\Crush\Events\StepStarted;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Usage;
use SugarCraft\Crush\Util\TokenEstimate;

/**
 * Roadmap 2.1: every step of a turn measures the request it is about to send
 * — system prompt and tool schemas included, anchored on the provider's own
 * count for the step before — and reports it on `$onStep` before the call.
 */
final class StepPressureCheckTest extends TestCase
{
    public function testEveryStepIsReportedBeforeItsProviderCall(): void
    {
        $calls = 0;
        $seenBeforeCall = [];
        $events = [];
        $provider = self::provider($calls, $seenBeforeCall, $events);

        EngineBackend::new($provider, 'm')
            ->withTools([self::probe(400)])
            ->withMaxSteps(5)
            ->completeTranscript([new UserMessage('go')], onStep: static function (object $e) use (&$events): void {
                if ($e instanceof StepStarted) {
                    $events[] = $e;
                }
            });

        $this->assertSame([1, 2, 3], array_map(static fn (StepStarted $e): int => $e->step, $events));
        $this->assertSame([5, 5, 5], array_map(static fn (StepStarted $e): int => $e->maxSteps, $events));
        $this->assertSame([1, 2, 3], $seenBeforeCall, 'step N is reported before request N is sent');
    }

    public function testTheFirstStepCountsTheSystemPromptAndToolSchemas(): void
    {
        $calls = 0;
        $seen = [];
        $events = [];
        $provider = self::provider($calls, $seen, $events);
        $probe = self::probe(40);

        EngineBackend::new($provider, 'm')->withTools([$probe])->withMaxSteps(5)
            ->completeTranscript([new UserMessage('go')], onStep: static function (object $e) use (&$events): void {
                if ($e instanceof StepStarted) {
                    $events[] = $e;
                }
            });

        $first = $events[0]->pressure;
        $this->assertInstanceOf(ContextPressure::class, $first);
        $this->assertNull($first->anchorTokens, 'nothing has been counted yet');
        $this->assertSame(TokenEstimate::ofText((string) $provider->requests[0]->systemPrompt), $first->systemTokens);
        $this->assertGreaterThan(0, $first->systemTokens, 'the system prompt is in the figure');
        $this->assertSame(TokenEstimate::ofToolSchemas([$probe]), $first->toolTokens, 'and so are the tool schemas');
        $this->assertSame($first->estimatedTokens, $first->tokens);
        $this->assertGreaterThan($first->systemTokens + $first->toolTokens, $first->tokens);
    }

    public function testALaterStepIsAnchoredOnTheProvidersCountPlusWhatTheStepAdded(): void
    {
        $calls = 0;
        $seen = [];
        $events = [];
        $provider = self::provider($calls, $seen, $events);

        EngineBackend::new($provider, 'm')->withTools([self::probe(8_000)])->withMaxSteps(5)
            ->completeTranscript([new UserMessage('go')], onStep: static function (object $e) use (&$events): void {
                if ($e instanceof StepStarted) {
                    $events[] = $e;
                }
            });

        $second = $events[1]->pressure;
        $this->assertSame(3_000, $second?->anchorTokens, 'the first response said its prompt was 3,000 tokens');
        $this->assertGreaterThanOrEqual(2_000, $second->deltaTokens, 'the 8,000-byte tool result is in the delta');
        $this->assertSame(3_000 + $second->deltaTokens, $second->tokens);
        $this->assertSame(3_000 + 1_000, $events[2]->pressure?->anchorTokens, 'each step re-anchors on its own response');
    }

    public function testTheVerdictTurnsOnceTheTurnOutgrowsTheStepBudget(): void
    {
        $calls = 0;
        $seen = [];
        $events = [];
        // 8k window, no output ceiling: the step budget is 6,400. The first
        // request is small; the provider counts it at 6,000, and what the step
        // adds on top tips the second one over.
        $provider = self::provider($calls, $seen, $events, window: 8_000, basePrompt: 6_000);

        EngineBackend::new($provider, 'm')->withTools([self::probe(4_000)])->withMaxSteps(5)
            ->completeTranscript([new UserMessage('go')], onStep: static function (object $e) use (&$events): void {
                if ($e instanceof StepStarted) {
                    $events[] = $e;
                }
            });

        $this->assertSame(6_400, $events[0]->pressure?->threshold);
        $this->assertFalse($events[0]->pressure->isOverBudget());
        $this->assertTrue($events[1]->pressure?->isOverBudget(), 'the provider\'s count plus the step\'s tool result is over');
    }

    public function testNoObserverMeansNoMeasurement(): void
    {
        $calls = 0;
        $seen = [];
        $events = [];
        $provider = self::provider($calls, $seen, $events);

        $turn = EngineBackend::new($provider, 'm')->withTools([self::probe(40)])->withMaxSteps(5)
            ->completeTranscript([new UserMessage('go')]);

        $this->assertSame('done', $turn->reply->content);
        $this->assertCount(3, $provider->requests);
    }

    /**
     * Two tool steps, then an answer; each response reports its prompt as
     * $basePrompt + 1,000 per earlier step. Records which step had been reported
     * when each request arrived.
     *
     * @param list<int>         $seenBeforeCall
     * @param list<StepStarted> $events
     */
    private static function provider(int &$calls, array &$seenBeforeCall, array &$events, int $window = 1_000_000, int $basePrompt = 3_000): ScriptedProvider
    {
        $answer = static function (CompleteRequest $request) use (&$calls, &$seenBeforeCall, &$events, $basePrompt): CompleteResponse {
            $calls++;
            $seenBeforeCall[] = $events === [] ? 0 : $events[count($events) - 1]->step;
            $prompt = $basePrompt + 1_000 * ($calls - 1);
            $usage = Usage::new($prompt + 50, 0.0, $prompt, 50, 0);

            return $calls < 3
                ? new CompleteResponse(content: "step {$calls}", toolCalls: [new ToolCall("c{$calls}", 'probe', ['n' => $calls])], tokensUsed: $prompt + 50, usage: $usage)
                : new CompleteResponse(content: 'done', tokensUsed: $prompt + 50, usage: $usage);
        };

        return new ScriptedProvider([$answer, $answer, $answer], contextWindow: $window);
    }

    private static function probe(int $bytes): Tool
    {
        return new class($bytes) implements Tool {
            public function __construct(private int $bytes) {}

            public function name(): string { return 'probe'; }

            public function description(): string { return 'Reads one path.'; }

            public function inputSchema(): array { return ['type' => 'object', 'properties' => ['n' => ['type' => 'integer']]]; }

            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: '', content: str_repeat('x', $this->bytes));
            }
        };
    }
}
