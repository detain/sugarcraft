<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentStatus;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\ExecutorInterface;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Workflows\Tasks;
use SugarCraft\Crush\Workflows\WorkflowBuilder;
use SugarCraft\Crush\Workflows\WorkflowEngine;
use SugarCraft\Crush\Workflows\WorkflowRegistry;
use SugarCraft\Crush\Workflows\WorkflowStatus;

/**
 * What `/workflow resume` and `/workflow pause` TELL the user (AUDIT WF-2).
 *
 * `/workflow resume` printed `**Workflow 'x' resumed and completed**` for every
 * result, so a resumed run that failed again read as a success with
 * `Status: failed` underneath; it also ran synchronously inside `update()`, so
 * the TUI froze for the resumed run's length and the run could not be paused.
 * And a `/workflow run` paused while live reported "completed".
 */
final class WorkflowResumeReportingTest extends TestCase
{
    use \SugarCraft\Crush\Tests\Support\DrivesWorkflowRunsTrait;

    private string $tempDir;
    private WorkflowEngine $engine;

    /** Set by a test: b fails while true. */
    public bool $failB = true;

    /** Set by a test: the executor suspends its fiber once, inside stage a. */
    public bool $suspendInA = false;

    /** Set by the executor once it has suspended. */
    public bool $suspended = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sugarcrush_wf_resume_report_' . uniqid('', true);
        mkdir($this->tempDir . '/workflows', 0755, true);
        $registry = new WorkflowRegistry($this->tempDir . '/workflows');
        $registry->register(
            (new WorkflowBuilder())
                ->name('three')
                ->description('a then b then c')
                ->stage('a', Tasks::agent('coder')->prompt('do a'))
                ->stage('b', Tasks::agent('coder')->prompt('do b using {{a.output}}'))
                ->stage('c', Tasks::agent('coder')->prompt('do c using {{b.output}}'))
                ->build(),
        );

        $test = $this;
        $executor = new class ($test) implements ExecutorInterface {
            public function __construct(private readonly WorkflowResumeReportingTest $test)
            {
            }

            public function execute(SubAgent $subAgent, CompleteRequest $request): AgentResult
            {
                if ($this->test->suspendInA && str_starts_with($subAgent->task, 'do a') && \Fiber::getCurrent() !== null) {
                    $this->test->suspendInA = false;
                    $this->test->suspended = true;
                    // Stands for AgentWorkerPool::idle() yielding to the loop
                    // while a forked agent works. The loop is stopped first so
                    // the test gets control in that gap rather than on a tick
                    // that has already resumed the fiber.
                    Loop::get()->stop();
                    \Fiber::suspend();
                }

                if ($this->test->failB && str_starts_with($subAgent->task, 'do b')) {
                    return new AgentResult(
                        agentId: $subAgent->id,
                        status: AgentStatus::Failed,
                        error: new \RuntimeException('b broke'),
                        startedAt: new \DateTimeImmutable(),
                        completedAt: new \DateTimeImmutable(),
                    );
                }

                return new AgentResult(
                    agentId: $subAgent->id,
                    status: AgentStatus::Completed,
                    output: 'OUT[' . $subAgent->task . ']',
                    tokensUsed: 10,
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
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    private function reply(string $command): string
    {
        return $this->runWorkflowCommandToReply(new Chat(inputBuf: $command, workflowEngine: $this->engine));
    }

    /** A failed run, paused for recovery; returns the printed run ID. */
    private function pausedFailedRun(): string
    {
        $run = $this->engine->run('three');
        $this->assertSame(WorkflowStatus::Failed, $run->status);
        $this->engine->pause($run->workflowId);

        return $run->workflowId;
    }

    public function testAResumeThatFailsAgainIsReportedAsFailedWithTheStageError(): void
    {
        $id = $this->pausedFailedRun();

        $response = $this->reply("/workflow resume {$id}");

        $this->assertStringContainsString("**Workflow '{$id}' resumed and failed**", $response);
        $this->assertStringNotContainsString('resumed and completed', $response);
        $this->assertStringContainsString('Status: failed', $response);
        $this->assertStringContainsString("Stage 'b': b broke", $response);
    }

    public function testAResumeThatSucceedsIsReportedAsCompletedWithTheWholeRunsCounts(): void
    {
        $id = $this->pausedFailedRun();
        $this->failB = false;

        $response = $this->reply("/workflow resume {$id}");

        $this->assertStringContainsString("**Workflow '{$id}' resumed and completed**", $response);
        $this->assertStringContainsString('Stages completed: 3', $response);
        // a's 10 from the paused leg carried over, plus b and c.
        $this->assertStringContainsString('Total tokens: 30', $response);

        $this->assertStringContainsString(
            'No paused workflow found',
            $this->reply("/workflow resume {$id}"),
            'the pause file is consumed, so a second resume is refused',
        );
    }

    /** The resume runs off update()'s stack, like /workflow run: Enter returns a Cmd and a live turn. */
    public function testResumeIsDrivenAsAnAsyncTurnNotInsideUpdate(): void
    {
        $id = $this->pausedFailedRun();
        $this->failB = false;

        [$next, $cmd] = $this->submitWorkflowCommand(new Chat(inputBuf: "/workflow resume {$id}", workflowEngine: $this->engine));

        $this->assertTrue($next->inFlight, 'a resume occupies the session like a run does');
        $this->assertCount(1, $next->history, 'nothing has run yet: only the command is echoed');
        $this->assertSame(WorkflowStatus::Paused, $this->engine->getStatus($id), 'not started on the update() tick');

        $this->settleWorkflowCmd($cmd);
        $this->assertSame(WorkflowStatus::Completed, $this->engine->getStatus($id));
    }

    /**
     * `/workflow pause` reaching a LIVE run — the run's fiber suspended
     * mid-stage, the turn released (as double-Escape releases it), and the
     * command typed into the session. The run stops before b and its own
     * report says paused, with how to continue it.
     */
    public function testALivePauseFromChatStopsTheRunAndItsReportSaysPaused(): void
    {
        $this->failB = false;
        $this->suspendInA = true;

        [, $cmd] = $this->submitWorkflowCommand(new Chat(inputBuf: '/workflow run three', workflowEngine: $this->engine));
        $async = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $async);

        $loop = Loop::get();
        $resolved = null;
        $async->promise->then(static function ($msg) use (&$resolved, $loop): void {
            $resolved = $msg;
            $loop->stop();
        });

        $safety = $loop->addTimer(20.0, static fn () => $loop->stop());
        $loop->run();
        $this->assertTrue($this->suspended, 'the run never reached stage a');
        $this->assertNull($resolved, 'the run must still be live');
        $this->assertSame(WorkflowStatus::Running, $this->engine->getStatus('three'));

        [$paused] = (new Chat(inputBuf: '/workflow pause three', workflowEngine: $this->engine))
            ->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertStringContainsString('Pause requested', $paused->history[1]->content);
        $this->assertSame(WorkflowStatus::Paused, $this->engine->getStatus('three'));

        if ($resolved === null) {
            $loop->run();
        }
        $loop->cancelTimer($safety);

        $this->assertInstanceOf(AssistantMsg::class, $resolved);
        $report = $resolved->message->content;
        $this->assertStringContainsString("**Workflow 'three' paused**", $report);
        $this->assertStringContainsString('Stages completed: 1', $report);
        $this->assertMatchesRegularExpression('/Continue it with `\/workflow resume three-[0-9a-f]{8}`/', $report);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) && !is_link($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
