<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Backend\ToolCallLoopGuard;
use SugarCraft\Crush\Hooks\BuiltIn\RepeatCallCountHook;
use SugarCraft\Crush\Hooks\BuiltIn\RepeatCallGuardHook;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\BuiltIn\TaskTool;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * The step budget as WAVE_PLAN_2 §5 decided it: a 1000-step default, the
 * repeat-call loop guard that makes a ceiling that high safe, and the one
 * no-tools summary request a stopped turn ends with — all driven through
 * {@see EngineBackend::complete()} end to end, against what the provider was
 * actually sent.
 */
final class EngineBackendStepBudgetTest extends TestCase
{
    public function testTheShippedCeilingsAreOneThousandStepsAndTwoHundredForSubAgents(): void
    {
        $maxSteps = (new \ReflectionProperty(EngineBackend::class, 'maxSteps'))
            ->getValue(EngineBackend::new(new ScriptedProvider([]), 'm'));

        $this->assertSame(1000, $maxSteps);
        $this->assertSame(200, TaskTool::DEFAULT_MAX_TURNS);
    }

    public function testAnExhaustedBudgetEndsInOneNoToolsSummaryRequest(): void
    {
        $provider = self::toolCallingProvider(distinctArgs: true, summary: 'done: 3 probes; remaining: the rest; next: probe 4');
        $probe = self::probe();

        $reply = EngineBackend::new($provider, 'm')->withTools([$probe])->withMaxSteps(3)->complete([Message::user('go')]);

        $this->assertCount(4, $provider->requests, 'three tool steps, then exactly one summary request');
        $this->assertSame(3, $probe->runs);
        foreach ([0, 1, 2] as $step) {
            $this->assertNotNull($provider->requests[$step]->tools, "step {$step} still advertises tools");
        }
        $summaryRequest = $provider->requests[3];
        // Roadmap 2.4-1: the same tools stay advertised, so the summary
        // request is the last step's request plus one row and its prefix is
        // cached; the reply is taken before any call it asks for could run.
        $this->assertSame($provider->requests[2]->tools, $summaryRequest->tools, 'the summary keeps the step\'s tool block');
        $asked = \SugarCraft\Crush\Context\TurnContextBlock::strip($summaryRequest->messages);
        $ask = $asked[array_key_last($asked)];
        $this->assertInstanceOf(UserMessage::class, $ask);
        $this->assertStringContainsString('whole budget of 3 tool steps', $ask->content());
        $this->assertStringContainsString('what has been done, what remains', $ask->content());

        $this->assertSame('done: 3 probes; remaining: the rest; next: probe 4', $reply->content);
        $this->assertTrue($reply->stepsTruncated, 'the budget still ran out — the step-exhausted notice keeps firing');
        $this->assertNull($reply->loopGuardStoppedBy, 'a budget exit is not the loop guard\'s');
        $this->assertSame(4 * 10, $reply->usage?->totalTokens, 'the summary request is billed into the turn like any step');
    }

    public function testTheLoopGuardWarnsOnTheThirdRefusesTheFifthAndEndsTheTurnOnTheEighth(): void
    {
        $provider = self::toolCallingProvider(distinctArgs: false, summary: 'I was stopped for repeating probe.');
        $probe = self::probe();

        $reply = EngineBackend::new($provider, 'm')->withTools([$probe])->withMaxSteps(50)->complete([Message::user('go')]);

        $this->assertSame(4, $probe->runs, 'calls 5-8 were refused, never run');
        $this->assertCount(ToolCallLoopGuard::END_TURN_AT + 1, $provider->requests, 'eight identical calls, then the summary — not fifty steps');

        // Request k carries the result of call k as its last message.
        $this->assertStringNotContainsString('loop guard', self::lastContent($provider->requests[2]), 'call 2 is not warned');
        $this->assertStringContainsString('identical call #3 to probe', self::lastContent($provider->requests[3]));
        $this->assertStringStartsWith('same', self::lastContent($provider->requests[3]), 'the warning is APPENDED; the result is intact');
        $this->assertStringContainsString('refused identical call #5 to probe', self::lastContent($provider->requests[5]));

        // The summary request: the 8th call's turn-ending refusal, then the ask.
        $summaryRequest = $provider->requests[ToolCallLoopGuard::END_TURN_AT];
        $this->assertSame($provider->requests[0]->tools, $summaryRequest->tools);
        $messages = \SugarCraft\Crush\Context\TurnContextBlock::strip($summaryRequest->messages);
        $this->assertStringContainsString('identical call #8 to probe', $messages[count($messages) - 2]->content());
        $this->assertStringContainsString('The turn is being ended', $messages[count($messages) - 2]->content());
        $this->assertStringContainsString('called probe with identical arguments and got the identical result 8 times', self::lastContent($summaryRequest));

        $this->assertSame('I was stopped for repeating probe.', $reply->content);
        $this->assertFalse($reply->stepsTruncated, 'a loop is not a budget problem — the raise-maxToolSteps notice would be the wrong advice');
        $this->assertSame('probe', $reply->loopGuardStoppedBy, 'the reply names the loop the guard ended, so Chat can say so');
    }

    public function testAChangingResultIsNeverTreatedAsALoop(): void
    {
        $provider = self::toolCallingProvider(distinctArgs: false, summary: 'summary');
        $probe = self::probe(changing: true);

        EngineBackend::new($provider, 'm')->withTools([$probe])->withMaxSteps(12)->complete([Message::user('go')]);

        $this->assertSame(12, $probe->runs, 'the same call kept producing new output, so every step ran');
        foreach ($provider->requests as $request) {
            $this->assertStringNotContainsString('loop guard', self::lastContent($request));
        }
    }

    public function testTheGuardStillRunsOnAWithoutHooksTurn(): void
    {
        $provider = self::toolCallingProvider(distinctArgs: false, summary: 'stopped');
        $probe = self::probe();

        EngineBackend::new($provider, 'm')->withTools([$probe])->withoutHooks()->withMaxSteps(50)->complete([Message::user('go')]);

        $this->assertSame(4, $probe->runs, 'withoutHooks() drops the permission guards, not the runaway-loop brake');
        $this->assertCount(ToolCallLoopGuard::END_TURN_AT + 1, $provider->requests);
    }

    public function testTheGuardIsArmedOnACopyAndNeverLeftOnTheLaunchsSharedManager(): void
    {
        $manager = new HookManager(new HookRegistry());
        $manager->registerBuiltIns();
        $provider = self::toolCallingProvider(distinctArgs: false, summary: 'stopped');
        $probe = self::probe();
        $backend = EngineBackend::new($provider, 'm')->withTools([$probe])->withHooks($manager)->withMaxSteps(50);

        $backend->complete([Message::user('go')]);
        $this->assertSame(4, $probe->runs, 'the shared manager\'s turn is guarded');
        $this->assertNull($manager->hook(HookEvent::PreToolUse->value, RepeatCallGuardHook::NAME), 'the per-turn ledger must not outlive its turn on the shared chain');
        $this->assertNull($manager->hook(HookEvent::PostToolUse->value, RepeatCallCountHook::NAME));

        // And the next turn starts from an empty ledger: four more runs, not
        // an immediate refusal carried over from the previous turn.
        $backend->complete([Message::user('again')]);
        $this->assertSame(8, $probe->runs);
    }

    public function testCallsTheSummaryAsksForAnywayNeverRun(): void
    {
        // A model that ignores "do not call any tools": its calls are dropped
        // from the reply and none of them runs — the tools were advertised
        // only so the request could reuse the cached prefix.
        $provider = new ScriptedProvider([
            new CompleteResponse(content: 'step', toolCalls: [new ToolCall('call_1', 'probe', ['n' => 1])], tokensUsed: 10),
            new CompleteResponse(content: 'summary anyway', toolCalls: [new ToolCall('call_2', 'probe', ['n' => 2])], tokensUsed: 10),
        ]);
        $probe = self::probe();

        $turn = EngineBackend::new($provider, 'm')->withTools([$probe])->withMaxSteps(1)->completeTranscript([new UserMessage('go')]);

        $this->assertSame(1, $probe->runs, 'only the step\'s own call ran');
        $this->assertSame('summary anyway', $turn->reply->content);
        $last = $turn->transcript[array_key_last($turn->transcript)];
        $this->assertInstanceOf(AssistantMessage::class, $last);
        $this->assertNull($last->toolCalls(), 'no call is left without a result');
    }

    public function testTheSummaryExchangeIsPartOfTheTranscriptAResumeReplays(): void
    {
        $provider = self::toolCallingProvider(distinctArgs: true, summary: 'where I stopped');

        $turn = EngineBackend::new($provider, 'm')
            ->withTools([self::probe()])
            ->withMaxSteps(1)
            ->completeTranscript([new UserMessage('go')]);

        $tail = array_slice($turn->transcript, -2);
        $this->assertInstanceOf(UserMessage::class, $tail[0]);
        $this->assertStringContainsString('whole budget of 1 tool steps', $tail[0]->content());
        $this->assertInstanceOf(AssistantMessage::class, $tail[1]);
        $this->assertSame('where I stopped', $tail[1]->content());
        $this->assertSame('where I stopped', $turn->reply->content);
    }

    /**
     * A model that calls `probe` on every step and answers with $summary when
     * the last row asks it to stop calling tools (the summary request).
     */
    private static function toolCallingProvider(bool $distinctArgs, string $summary): ScriptedProvider
    {
        $step = 0;

        return new ScriptedProvider([
            static function (CompleteRequest $request) use (&$step, $distinctArgs, $summary): CompleteResponse {
                $last = $request->messages[array_key_last($request->messages)] ?? null;
                if ($last instanceof UserMessage && str_contains($last->content(), 'Do not call any tools')) {
                    return new CompleteResponse(content: $summary, tokensUsed: 10);
                }
                $step++;

                return new CompleteResponse(
                    content: "step {$step}",
                    toolCalls: [new ToolCall("call_{$step}", 'probe', $distinctArgs ? ['n' => $step] : ['path' => 'same/file'])],
                    tokensUsed: 10,
                );
            },
        ]);
    }

    private static function probe(bool $changing = false): Tool
    {
        return new class($changing) implements Tool {
            public int $runs = 0;

            public function __construct(private bool $changing) {}

            public function name(): string { return 'probe'; }

            public function description(): string { return 'test probe'; }

            public function inputSchema(): array { return ['type' => 'object', 'properties' => []]; }

            public function execute(array $args): ToolResult
            {
                $this->runs++;

                return new ToolResult(toolCallId: '', content: $this->changing ? "result {$this->runs}" : 'same');
            }
        };
    }

    private static function lastContent(CompleteRequest $request): string
    {
        // The newest conversation row; the `<turn-context>` row (step 1.A-1) trails it.
        $rows = \SugarCraft\Crush\Context\TurnContextBlock::strip($request->messages);
        $last = $rows[array_key_last($rows)];

        return is_object($last) && method_exists($last, 'content') ? (string) $last->content() : '';
    }
}
