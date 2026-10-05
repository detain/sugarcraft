<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\SuspendedDelegations;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\PrunedOutputPlaceholder;
use SugarCraft\Crush\Context\Pruning\PruningMode;
use SugarCraft\Crush\Context\Pruning\RefTag;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tests\Support\RosterAgent;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\BuiltIn\Prune;
use SugarCraft\Crush\Tools\BuiltIn\Recall;
use SugarCraft\Crush\Tools\BuiltIn\TaskTool;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Roadmap 3.B-5 (DCP §13.2 I): a delegated run manages its OWN context. A
 * run granted `Prune` gets an ephemeral ledger — its refs number only its
 * rows, its prunes land on its next request — the delegated prompt is never
 * prunable, and the ledger is saved with the suspension so a `resume` keeps
 * the pruned view. A run not granted a ledger tool, or one in a mode that
 * does not let the model prune, runs exactly as before: no ledger, no tags.
 */
final class TaskToolLedgerTest extends TestCase
{
    private string $storeDir;

    private SuspendedDelegations $store;

    private string|false $priorMode;

    protected function setUp(): void
    {
        $this->storeDir = sys_get_temp_dir() . '/sc_task_ledger_' . bin2hex(random_bytes(6));
        $this->store = new SuspendedDelegations($this->storeDir);
        $this->priorMode = getenv(PruningMode::ENV);
        putenv(PruningMode::ENV . '=auto');
    }

    protected function tearDown(): void
    {
        putenv($this->priorMode === false ? PruningMode::ENV : PruningMode::ENV . '=' . $this->priorMode);
        foreach (glob($this->storeDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storeDir);
    }

    public function testARunGrantedPruneTagsItsOwnRowsAndItsPruneLandsOnItsNextRequest(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', 'Read', ['file_path' => 'a.php'])]),
            new CompleteResponse(content: '', toolCalls: [new ToolCall('p1', 'Prune', ['targets' => [['ref' => 'r2']], 'reason' => 'done'])]),
            new CompleteResponse(content: 'a.php holds one class'),
        ], contextWindow: 1_000_000);

        $result = $this->task($provider, ['Read', 'Prune'])->execute(self::call());

        $this->assertFalse($result->isError(), $result->content());
        $this->assertContains('Prune', self::offered($provider->requests[0]), 'the run is offered its own Prune');
        $this->assertSame(RefTag::appendTo(self::bigOutput(), 2), self::sent($provider->requests[1], 'c1'), 'its refs number its own rows: r1 is the delegated prompt, r2 the read');
        $this->assertSame(
            RefTag::appendTo(PrunedOutputPlaceholder::for('Read', ['file_path' => 'a.php']), 2),
            self::sent($provider->requests[2], 'c1'),
            'the run\'s very next request is projected through its own prune',
        );
        $this->assertStringStartsWith('Pruned 1 output', (string) self::sent($provider->requests[2], 'p1'));
    }

    public function testTheDelegatedPromptCannotBePruned(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', 'Read', ['file_path' => 'a.php'])]),
            new CompleteResponse(content: '', toolCalls: [new ToolCall('p1', 'Prune', ['targets' => [['ref' => 'r1']], 'reason' => 'noise'])]),
            new CompleteResponse(content: 'done'),
        ], contextWindow: 1_000_000);

        $this->task($provider, ['Read', 'Prune'])->execute(self::call());

        $receipt = (string) self::sent($provider->requests[2], 'p1');
        $this->assertStringContainsString('nothing was pruned', $receipt);
        $this->assertStringContainsString('r1 (a prompt, not a tool result)', $receipt);
        $this->assertSame(RefTag::appendTo('Audit candy-core and report the findings', 1), self::prompt($provider->requests[2]), 'the task reaches every request whole');
        $this->assertNotContains('Compress', self::offered($provider->requests[0]), 'Compress needs a person\'s /compress, which a delegated run never has');
    }

    public function testTheLedgerIsSavedWithTheSuspensionAndAResumeKeepsThePrunedView(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', 'Read', ['file_path' => 'a.php'])]),
            new CompleteResponse(content: '', toolCalls: [new ToolCall('p1', 'Prune', ['targets' => [['ref' => 'r2']], 'reason' => 'done'])]),
            new CompleteResponse(content: 'found two bugs'),
            new CompleteResponse(content: 'fixed both'),
        ], contextWindow: 1_000_000);
        $task = $this->task($provider, ['Read', 'Prune']);

        $first = $task->execute(self::call());
        preg_match('/"resume": "([0-9a-f]{16})"/', $first->content(), $m);
        $this->assertArrayHasKey(1, $m, $first->content());

        $saved = $this->store->load($m[1]);
        $this->assertIsArray($saved['contextLedger'] ?? null, 'the run\'s ledger is kept beside its transcript');
        $ledger = ContextLedger::fromArray($saved['contextLedger']);
        $this->assertTrue($ledger->isPruned('c1'));
        $this->assertSame(2, $ledger->refOf('c1'), 'with its refs fixed as the run read them');

        $followUp = $task->execute(self::call(['resume' => $m[1], 'prompt' => 'now fix them']));

        $this->assertFalse($followUp->isError(), $followUp->content());
        $this->assertSame(
            RefTag::appendTo(PrunedOutputPlaceholder::for('Read', ['file_path' => 'a.php']), 2),
            self::sent($provider->requests[3], 'c1'),
            'the resumed run sends the pruned view it was suspended with',
        );
    }

    public function testARunGrantedRecallBringsBackWhatItPruned(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', 'Read', ['file_path' => 'a.php'])]),
            new CompleteResponse(content: '', toolCalls: [new ToolCall('p1', 'Prune', ['targets' => [['ref' => 'r2']], 'reason' => 'done'])]),
            new CompleteResponse(content: '', toolCalls: [new ToolCall('q1', 'Recall', ['ref' => 'r2'])]),
            new CompleteResponse(content: 'done'),
        ], contextWindow: 1_000_000);

        $this->task($provider, ['Read', 'Prune', 'Recall'])->execute(self::call());

        $recalled = (string) self::sent($provider->requests[3], 'q1');
        $this->assertStringStartsWith('[r2 Read a.php — recalled: the original output', $recalled);
        $this->assertStringContainsString(self::bigOutput(), $recalled);
        $this->assertStringStartsWith('[Read a.php', (string) self::sent($provider->requests[3], 'c1'), 'the ledger is unchanged: the row stays pruned');
    }

    public function testARunNotGrantedALedgerToolRunsWithoutOne(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', 'Read', ['file_path' => 'a.php'])]),
            new CompleteResponse(content: 'done'),
        ], contextWindow: 1_000_000);

        $first = $this->task($provider, ['Read'])->execute(self::call());

        $this->assertSame(self::bigOutput(), self::sent($provider->requests[1], 'c1'), 'no ref tags on its wire');
        $this->assertNotContains('Prune', self::offered($provider->requests[0]));
        preg_match('/"resume": "([0-9a-f]{16})"/', $first->content(), $m);
        $this->assertNull($this->store->load($m[1])['contextLedger'] ?? null);
    }

    public function testAModeThatDoesNotLetTheModelPruneKeepsTheRunLedgerless(): void
    {
        putenv(PruningMode::ENV . '=manual');
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', 'Read', ['file_path' => 'a.php'])]),
            new CompleteResponse(content: 'done'),
        ], contextWindow: 1_000_000);

        $this->task($provider, ['Read', 'Prune'])->execute(self::call());

        $this->assertNotContains('Prune', self::offered($provider->requests[0]));
        $this->assertSame(self::bigOutput(), self::sent($provider->requests[1], 'c1'));
    }

    // ── harness ─────────────────────────────────────────────────────────

    /** @param list<string> $grant */
    private function task(ScriptedProvider $provider, array $grant): TaskTool
    {
        $tools = [self::readTool(), Prune::new(), Recall::new()];
        $manager = new AgentManager(new ScriptedProvider([]), new SkillRegistry(), toolRegistry: $tools, toolUniverse: $tools);
        $manager->register(RosterAgent::named('coder', $grant, maxTurns: 10));

        return (new TaskTool($manager, suspended: $this->store))
            ->withEngine(EngineBackend::new($provider, 'm')->withTools($tools));
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private static function call(array $overrides = []): array
    {
        return $overrides + [
            'description' => 'Audit candy-core',
            'prompt' => 'Audit candy-core and report the findings',
            'agent' => 'coder',
        ];
    }

    /** @return list<string> */
    private static function offered(CompleteRequest $request): array
    {
        return array_map(static fn (Tool $t): string => $t->name(), $request->tools ?? []);
    }

    private static function sent(CompleteRequest $request, string $callId): ?string
    {
        foreach ($request->messages as $message) {
            if ($message instanceof ToolResultMessage && $message->toolCallId() === $callId) {
                return $message->content();
            }
        }

        return null;
    }

    private static function prompt(CompleteRequest $request): ?string
    {
        foreach ($request->messages as $message) {
            if ($message instanceof UserMessage && str_starts_with($message->content(), 'Audit candy-core')) {
                return $message->content();
            }
        }

        return null;
    }

    private static function bigOutput(): string
    {
        return str_repeat("a.php line of source\n", 200);
    }

    private static function readTool(): Tool
    {
        return new class (self::bigOutput()) implements Tool {
            public function __construct(private readonly string $output)
            {
            }

            public function name(): string
            {
                return 'Read';
            }

            public function description(): string
            {
                return 'reads';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => ['file_path' => ['type' => 'string']]];
            }

            public function execute(array $args): ToolResult
            {
                return new ToolResult('', $this->output);
            }
        };
    }
}
