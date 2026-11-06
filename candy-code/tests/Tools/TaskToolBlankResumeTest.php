<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\Agents\SuspendedDelegations;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Messages\Message as TypedMessage;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tools\BuiltIn\TaskTool;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolCall;
use SugarCraft\Code\Tools\ToolResult;
use SugarCraft\Code\Tests\Support\RosterAgent;
use SugarCraft\Code\Tests\Support\ScriptedProvider;

/**
 * wf-g4: the Task path's blank-return resume. A delegated run that finishes
 * its turn with no report used to end the delegation as a refusal on the
 * spot; now the engine re-asks the SAME conversation with one "continue"
 * (up to {@see \SugarCraft\Code\Agents\BlankReturnResume::MAX_CONSECUTIVE_BLANKS})
 * before the honest stall refusal is allowed to fire. The pool path's twin
 * pins live in BlankReturnEngineExecutorTest; the shared policy's own
 * semantics are pinned by BlankReturnResumeTest.
 */
final class TaskToolBlankResumeTest extends TestCase
{
    private string $storeDir;

    private SuspendedDelegations $store;

    protected function setUp(): void
    {
        $this->storeDir = sys_get_temp_dir() . '/sc_task_resume_' . bin2hex(random_bytes(6));
        $this->store = new SuspendedDelegations($this->storeDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storeDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storeDir);
    }

    public function testABlankDelegationIsResumedOnTheSameSessionAndTheReportSurvives(): void
    {
        $probe = self::probe('probe');
        $provider = new ScriptedProvider([self::silentUntilContinued('the report after the nudge')]);
        $manager = self::manager([$probe], RosterAgent::named('coder', ['probe'], maxTurns: 3));
        $engine = EngineBackend::new($provider, 'm')->withTools([$probe]);

        $result = (new TaskTool($manager, suspended: $this->store))->withEngine($engine)->execute(self::call());

        $this->assertFalse($result->isError(), $result->content());
        $this->assertStringStartsWith(
            \SugarCraft\Code\Context\DelegatedOutputFence::wrap('the report after the nudge') . "\n\n[sub-agent \"coder\" finished;",
            $result->content(),
        );
        $this->assertStringContainsString('[resumed 1 blank return(s) with a continue instruction]', $result->content());
    }

    public function testTheContinuationRidesTheSameConversationTheBlankEndedOn(): void
    {
        $probe = self::probe('probe');
        $provider = new ScriptedProvider([self::silentUntilContinued('at last, a report')]);
        $manager = self::manager([$probe], RosterAgent::named('coder', ['probe'], maxTurns: 3));
        $engine = EngineBackend::new($provider, 'm')->withTools([$probe]);

        (new TaskTool($manager, suspended: $this->store))->withEngine($engine)->execute(self::call());

        $continued = null;
        $silent = null;
        foreach ($provider->requests as $index => $request) {
            $turns = self::turns($request);
            if ($turns !== [] && $turns[array_key_last($turns)] === ['user', 'continue']) {
                $continued = $index;
            } elseif ($continued === null) {
                $silent = $index;
            }
        }

        $this->assertNotNull($continued, 'a request carried the continue nudge');
        $this->assertNotNull($silent, 'the first attempt ended silently');
        $nudge = self::turns($provider->requests[$continued]);
        $this->assertSame(self::turns($provider->requests[$silent])[0], $nudge[0], 'the preset prompt was carried, not re-added');
        $this->assertSame(self::turns($provider->requests[$silent])[1], $nudge[1], 'the opening task is the same conversation');
        $this->assertGreaterThan(count(self::turns($provider->requests[$silent])), count($nudge), 'the nudge rides the transcript the blank ended on');
        $this->assertSame(['user', 'continue'], $nudge[array_key_last($nudge)]);
    }

    public function testTenConsecutiveBlanksRefuseOnceNamingTheStallAndTheResumesConsumed(): void
    {
        $probe = self::probe('probe');
        $provider = new ScriptedProvider([new CompleteResponse(content: '')]);
        $manager = self::manager([$probe], RosterAgent::named('coder', ['probe'], maxTurns: 1));
        $engine = EngineBackend::new($provider, 'm')->withTools([$probe]);

        $result = (new TaskTool($manager, suspended: $this->store))->withEngine($engine)->execute(self::call());

        $this->assertTrue($result->isError());
        $content = $result->content();
        $this->assertStringContainsString('ended without a final report (step cap 1)', $content, 'the legacy sentence survives inside the stall reason');
        $this->assertStringContainsString('blank-return stall', $content);
        $this->assertStringContainsString('10 consecutive blank returns', $content);
        $this->assertStringContainsString('9 continue-resume(s) consumed', $content);
        self::resumeId($content);
        $this->assertGreaterThanOrEqual(10, count($provider->requests), 'every silent turn was re-asked before the give-up');
        $this->assertSame([], $probe->calls, 'nothing ran — the run was silent, not interrupted');
    }

    public function testAFirstShotReportCarriesNoResumeDisclosure(): void
    {
        $probe = self::probe('probe');
        $provider = new ScriptedProvider([new CompleteResponse(content: 'clean report')]);
        $manager = self::manager([$probe], RosterAgent::named('coder', ['probe'], maxTurns: 3));
        $engine = EngineBackend::new($provider, 'm')->withTools([$probe]);

        $result = (new TaskTool($manager, suspended: $this->store))->withEngine($engine)->execute(self::call());

        $this->assertFalse($result->isError(), $result->content());
        $this->assertStringStartsWith(
            \SugarCraft\Code\Context\DelegatedOutputFence::wrap('clean report') . "\n\n[sub-agent \"coder\" finished;",
            $result->content(),
            'the legacy shape is byte-identical when nothing went silent',
        );
        $this->assertStringNotContainsString('[resumed', $result->content());
        $this->assertCount(1, $provider->requests);
    }

    /**
     * Answers blank until the conversation's last message is the engine's
     * continue nudge — the shape that pins the resume loop actually re-asking
     * the SAME session rather than starting over.
     */
    private static function silentUntilContinued(string $report): \Closure
    {
        return static function (CompleteRequest $request) use ($report): CompleteResponse {
            $last = $request->messages[array_key_last($request->messages)] ?? null;

            if ($last instanceof UserMessage && $last->content() === 'continue') {
                return new CompleteResponse(content: $report);
            }

            return new CompleteResponse(content: '');
        };
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private static function call(array $overrides = []): array
    {
        return $overrides + [
            'description' => 'Audit sugar-core',
            'prompt' => 'Audit sugar-core and report the findings',
            'agent' => 'coder',
        ];
    }

    private static function resumeId(string $refusal): string
    {
        self::assertMatchesRegularExpression('/"resume": "([0-9a-f]{16})"/', $refusal, 'the refusal names a resume id');
        preg_match('/"resume": "([0-9a-f]{16})"/', $refusal, $m);

        return $m[1];
    }

    /**
     * @param list<Tool> $registry
     */
    private static function manager(array $registry, Agent $agent): AgentManager
    {
        $manager = new AgentManager(
            new ScriptedProvider([]),
            new SkillRegistry(),
            toolRegistry: $registry === [] ? null : $registry,
            toolUniverse: $registry === [] ? null : $registry,
        );
        $manager->register($agent);

        return $manager;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function turns(CompleteRequest $request): array
    {
        // Without the `<turn-context>` row (step 1.A-1): it carries the host
        // checkout's git state, not the conversation these tests pin.
        return array_map(
            static fn (TypedMessage $message): array => [$message->role(), $message->content()],
            \SugarCraft\Code\Context\TurnContextBlock::strip($request->messages),
        );
    }

    /**
     * @return Tool&object{calls: list<array<string, mixed>>}
     */
    private static function probe(string $name): Tool
    {
        return new class ($name) implements Tool {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            public function __construct(private string $name)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function description(): string
            {
                return 'records its calls';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                $id = (string) ($args['id'] ?? '');
                unset($args['id']);
                $this->calls[] = $args;

                return new ToolResult(toolCallId: $id, content: $this->name . ' ok');
            }
        };
    }
}
