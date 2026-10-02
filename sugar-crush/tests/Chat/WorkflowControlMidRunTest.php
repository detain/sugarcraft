<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentStatus;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\ExecutorInterface;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Tests\Support\DrivesWorkflowRunsTrait;
use SugarCraft\Crush\Workflows\Tasks;
use SugarCraft\Crush\Workflows\WorkflowBuilder;
use SugarCraft\Crush\Workflows\WorkflowEngine;
use SugarCraft\Crush\Workflows\WorkflowRegistry;
use SugarCraft\Crush\Workflows\WorkflowStatus;

/**
 * Audit WF-4: since WF-2 the engine can pause a LIVE run, but the run occupies
 * Chat's turn, and Chat refused every slash command mid-turn — so the very
 * command that pauses it was refused, and the only way through was Esc Esc,
 * which releases the turn rather than pausing the run.
 *
 * The fixture's executor suspends the workflow fiber in the middle of stage
 * `a` — the state the real pool's idle poll leaves it in between ticks — and
 * stops the loop there, so the test can type at the Chat while the run is
 * genuinely live.
 *
 * @see Chat::isWorkflowControlDuringWorkflowTurn()
 */
final class WorkflowControlMidRunTest extends TestCase
{
    use DrivesWorkflowRunsTrait;

    private string $tempDir;
    private WorkflowEngine $engine;

    /** @var list<string> */
    public array $prompts = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sugarcrush_wf4_' . uniqid('', true);
        mkdir($this->tempDir . '/workflows', 0700, true);
        $registry = new WorkflowRegistry($this->tempDir . '/workflows');
        $registry->register(
            (new WorkflowBuilder())
                ->name('three')
                ->description('a then b then c')
                ->stage('a', Tasks::agent('coder')->prompt('do a'))
                ->stage('b', Tasks::agent('coder')->prompt('do b'))
                ->stage('c', Tasks::agent('coder')->prompt('do c'))
                ->build(),
        );

        $test = $this;
        $executor = new class ($test) implements ExecutorInterface {
            public function __construct(private readonly WorkflowControlMidRunTest $test)
            {
            }

            public function execute(SubAgent $subAgent, CompleteRequest $request): AgentResult
            {
                $this->test->prompts[] = $subAgent->task;
                if ($subAgent->task === 'do a' && \Fiber::getCurrent() !== null) {
                    // Hand the loop back mid-stage, as the pool's idle poll
                    // does, and let the test type while the run is live.
                    Loop::get()->stop();
                    \Fiber::suspend();
                }

                return new AgentResult(
                    agentId: $subAgent->id,
                    status: AgentStatus::Completed,
                    output: 'OUT[' . $subAgent->task . ']',
                    tokensUsed: 1,
                    costUsd: 0.0,
                    startedAt: new \DateTimeImmutable(),
                    completedAt: new \DateTimeImmutable(),
                );
            }

            public function executeStream(SubAgent $subAgent, CompleteRequest $request): \Generator
            {
                yield $this->execute($subAgent, $request);
            }

            public function cancel(string $agentId): void
            {
            }

            public function cancelAll(): void
            {
            }
        };

        $this->engine = new WorkflowEngine($registry, new AgentWorkerPool(1, $executor));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tempDir);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    private static function typed(Chat $chat, string $draft): Chat
    {
        return (new \ReflectionMethod(Chat::class, 'mutate'))->invoke($chat, ['inputBuf' => $draft]);
    }

    private static function last(Chat $chat): Message
    {
        return $chat->history[array_key_last($chat->history)];
    }

    /**
     * Submit `/workflow run three` and step the loop until the fiber is
     * suspended inside stage `a`.
     *
     * @return array{0: Chat, 1: \SugarCraft\Core\AsyncCmd}
     */
    private function liveRun(): array
    {
        $chat = new Chat(inputBuf: '/workflow run three', workflowEngine: $this->engine, backend: new EchoBackend());
        [$running, $cmd] = $this->submitWorkflowCommand($chat);
        self::assertTrue($running->inFlight, 'fixture: the run occupies the turn');

        $async = $cmd();
        $loop = Loop::get();
        $safety = $loop->addTimer(10.0, static fn() => $loop->stop());
        $loop->run();
        $loop->cancelTimer($safety);
        self::assertSame(['do a'], $this->prompts, 'fixture: the fiber is suspended inside stage a');
        self::assertSame(WorkflowStatus::Running, $this->engine->getStatus('three'), 'fixture: the run is live');

        return [$running, $async];
    }

    /** Run the loop until the run's report lands, and apply it. */
    private function settle(Chat $chat, \SugarCraft\Core\AsyncCmd $async): Chat
    {
        $loop = Loop::get();
        $resolved = null;
        $async->promise->then(static function ($msg) use (&$resolved, $loop): void {
            $resolved = $msg;
            $loop->stop();
        });
        if ($resolved === null) {
            $safety = $loop->addTimer(10.0, static fn() => $loop->stop());
            $loop->run();
            $loop->cancelTimer($safety);
        }
        self::assertNotNull($resolved, 'the run did not settle');
        [$after] = $chat->update($resolved);

        return $after;
    }

    public function testWorkflowPauseIsLetThroughMidRunAndTheRunSettlesPaused(): void
    {
        [$running, $async] = $this->liveRun();

        [$paused, $cmd] = self::typed($running, '/workflow pause three')->update(new KeyMsg(KeyType::Enter, ''));

        self::assertNull($cmd);
        self::assertStringNotContainsString('do not run while a turn is in flight', self::last($paused)->content, 'the pause is not refused');
        self::assertStringContainsString('Pause requested', self::last($paused)->content);
        self::assertTrue($paused->inFlight, 'answering the pause must not release the run\'s turn');
        self::assertSame('', $paused->inputBuf);

        $settled = $this->settle($paused, $async);

        self::assertSame(['do a'], $this->prompts, 'b and c never start once the pause landed');
        self::assertStringContainsString("Workflow 'three' paused", self::last($settled)->content);
        self::assertFalse($settled->inFlight, 'the report ends the turn');
    }

    public function testWorkflowStatusIsLetThroughMidRun(): void
    {
        [$running, $async] = $this->liveRun();

        [$asked] = self::typed($running, '/workflow status three')->update(new KeyMsg(KeyType::Enter, ''));

        self::assertStringContainsString('status: **running**', self::last($asked)->content);
        self::assertTrue($asked->inFlight);

        $this->settle($asked, $async);
    }

    public function testTheColonSpellingIsLetThroughToo(): void
    {
        [$running, $async] = $this->liveRun();

        [$asked] = self::typed($running, '/workflow:status three')->update(new KeyMsg(KeyType::Enter, ''));

        self::assertStringContainsString('status: **running**', self::last($asked)->content);

        $this->settle($asked, $async);
    }

    public function testEveryOtherCommandIsStillRefusedMidRun(): void
    {
        [$running, $async] = $this->liveRun();

        foreach (['/workflow run three', '/workflow resume three', '/workflow list', '/clear'] as $draft) {
            [$after] = self::typed($running, $draft)->update(new KeyMsg(KeyType::Enter, ''));
            self::assertStringContainsString('do not run while a turn is in flight', self::last($after)->content, "{$draft} must still be refused mid-run");
            self::assertSame($draft, $after->inputBuf, 'and the draft kept');
        }

        $this->settle($running, $async);
    }

    public function testAModelTurnStillRefusesWorkflowPause(): void
    {
        [$turn] = (new Chat(inputBuf: 'hello', workflowEngine: $this->engine, backend: new EchoBackend()))
            ->update(new KeyMsg(KeyType::Enter, ''));
        self::assertTrue($turn->inFlight, 'fixture: a model turn is in flight');

        [$after] = self::typed($turn, '/workflow pause three')->update(new KeyMsg(KeyType::Enter, ''));

        self::assertStringContainsString('do not run while a turn is in flight', self::last($after)->content, 'only a workflow turn lets the pause through');
        self::assertSame('/workflow pause three', $after->inputBuf);
    }

    public function testDoubleEscapeEndsTheExceptionWithTheTurn(): void
    {
        [$running, $async] = $this->liveRun();

        [$once] = $running->update(new KeyMsg(KeyType::Escape, ''));
        [$cancelled] = $once->update(new KeyMsg(KeyType::Escape, ''));
        self::assertFalse($cancelled->inFlight, 'fixture: Esc Esc released the turn');
        self::assertFalse(
            (new \ReflectionProperty(Chat::class, 'workflowTurnInFlight'))->getValue($cancelled),
            'the exception belongs to the workflow turn and ends with it',
        );

        // The released run still reports (WF-2's known limitation); settle it
        // so no timer outlives the test.
        $this->settle($cancelled, $async);
    }
}
