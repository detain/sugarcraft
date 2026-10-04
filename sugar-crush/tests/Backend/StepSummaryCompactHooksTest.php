<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Context\Compaction\StepSummarizer;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Roadmap 3.D-2's remainder: the engine's step-level summary (2.4-1) is a
 * compaction like `/compact`, so it runs inside the session's `PreCompact` /
 * `PostCompact` chains — `PreCompact` (`trigger: auto`) before the summary is
 * requested, where a refusal skips it before it is paid for and the step's
 * request goes out as it stands; `PostCompact` once it is applied, carrying
 * the summary the model now reads.
 */
final class StepSummaryCompactHooksTest extends TestCase
{
    private ?string $root = null;

    protected function tearDown(): void
    {
        if ($this->root !== null) {
            @rmdir($this->root);
        }
    }

    public function testARefusingPreCompactHookSkipsTheSummaryBeforeItIsPaidFor(): void
    {
        $fired = new \ArrayObject();
        $provider = self::provider();

        $turn = $this->engine($provider, HookResult::deny('keep the raw rows'), $fired)
            ->completeTranscript([new UserMessage('map the project')]);

        $this->assertCount(4, $provider->requests, 'three tool steps and the answer — no summary request');
        foreach ($provider->requests as $request) {
            $this->assertFalse(self::asksForSummary($request));
        }
        $this->assertSame(['PreCompact'], self::events($fired), 'refused, so nothing to observe after');
        $this->assertSame(['trigger' => 'auto', 'custom_instructions' => ''], json_decode($fired[0]->toolInput, true));
        $this->assertSame('all mapped', $turn->reply->content, 'the step went out as it stood');
    }

    public function testAPermittingHookLetsTheSummaryLandAndPostCompactSeesIt(): void
    {
        $fired = new \ArrayObject();
        $provider = self::provider();

        $this->engine($provider, HookResult::allow(), $fired)->completeTranscript([new UserMessage('map the project')]);

        $this->assertCount(5, $provider->requests);
        $this->assertTrue(self::asksForSummary($provider->requests[3]));
        $this->assertSame(['PreCompact', 'PostCompact'], self::events($fired));
        $this->assertSame(
            ['trigger' => 'auto', 'compact_summary' => 'SUMMARY OF STEPS 1-2: probed twice.'],
            json_decode($fired[1]->toolInput, true),
        );
    }

    public function testWithNoCompactionHookWiredTheSummaryIsUnchanged(): void
    {
        $provider = self::provider();

        $this->engine($provider, null, new \ArrayObject())->completeTranscript([new UserMessage('map the project')]);

        $this->assertCount(5, $provider->requests);
    }

    // ── harness ─────────────────────────────────────────────────────────

    /** @return list<string> */
    private static function events(\ArrayObject $fired): array
    {
        return array_map(static fn (HookContext $context): string => $context->toolName, $fired->getArrayCopy());
    }

    private function engine(ScriptedProvider $provider, ?HookResult $preVerdict, \ArrayObject $fired): EngineBackend
    {
        $this->root ??= sys_get_temp_dir() . '/crush-stepsum-hooks-' . bin2hex(random_bytes(6));
        if (!is_dir($this->root)) {
            mkdir($this->root, 0o700, true);
        }

        $engine = EngineBackend::new($provider, 'm')->withoutHooks()->withRoot($this->root)->withTools([self::probe()]);
        if ($preVerdict === null) {
            return $engine;
        }

        $registry = new HookRegistry();
        $registry->register(self::recorder(HookEvent::PreCompact, $preVerdict, $fired));
        $registry->register(self::recorder(HookEvent::PostCompact, HookResult::allow(), $fired));

        return $engine->withHooks(new HookManager($registry));
    }

    private static function recorder(HookEvent $event, HookResult $verdict, \ArrayObject $fired): HookInterface
    {
        return new class ($event, $verdict, $fired) implements HookInterface {
            public function __construct(
                private readonly HookEvent $event,
                private readonly HookResult $verdict,
                private readonly \ArrayObject $fired,
            ) {
            }

            public function name(): string
            {
                return 'recorder-' . $this->event->value;
            }

            public function event(): HookEvent
            {
                return $this->event;
            }

            public function matcher(): string
            {
                return '';
            }

            public function execute(HookContext $context): HookResult
            {
                $this->fired[] = $context;

                return $this->verdict;
            }
        };
    }

    /** Steps 1-3 read 30k each; step 4's request is the first over the 80k budget. */
    private static function provider(): ScriptedProvider
    {
        $step = 0;

        return new ScriptedProvider([
            static function (CompleteRequest $request) use (&$step): CompleteResponse {
                if (self::asksForSummary($request)) {
                    return new CompleteResponse(content: 'SUMMARY OF STEPS 1-2: probed twice.');
                }
                $step++;

                return $step <= 3
                    ? new CompleteResponse(content: '', toolCalls: [new ToolCall("c{$step}", 'probe', ['n' => $step])])
                    : new CompleteResponse(content: 'all mapped');
            },
        ], contextWindow: 100_000);
    }

    private static function asksForSummary(CompleteRequest $request): bool
    {
        $last = $request->messages[array_key_last($request->messages)] ?? null;

        return $last instanceof UserMessage && $last->content() === StepSummarizer::INSTRUCTION;
    }

    private static function probe(): Tool
    {
        return new class () implements Tool {
            public function name(): string
            {
                return 'probe';
            }

            public function description(): string
            {
                return 'Reads a lot.';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => ['n' => ['type' => 'integer']]];
            }

            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: '', content: str_repeat('lorem ipsum ', 10_000));
            }
        };
    }
}
