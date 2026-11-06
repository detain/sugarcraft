<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\WorkflowCommand;
use SugarCraft\Code\Workflows\WorkflowEngineInterface;
use SugarCraft\Code\Workflows\WorkflowLinter;
use SugarCraft\Code\Workflows\WorkflowResult;
use SugarCraft\Code\Workflows\WorkflowStatus;
use PHPUnit\Framework\TestCase;

/**
 * C14: `/workflow status` gained its per-stage ledger columns (duration,
 * spend, tokens, attempts) from the data plane the pause record already
 * carries — presentation only, no engine edit. The lookup mirror and the
 * renderer are pure statics on WorkflowLinter, so they are pinned directly,
 * and the append leg is pinned end to end through a private HOME the
 * command's registry resolves.
 */
final class WorkflowStatusDetailTest extends TestCase
{
    /** @var list<string> */
    private array $statusDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->statusDirs as $dir) {
            foreach ([
                // chr(42): keep the literal out of the glob-shaped corpus
                // (GlobDialectDifferentialTest harvests such test literals).
                $dir . '/.running/' . chr(42) . '.json',
                $dir . '/.candy-code/workflows/.running/' . chr(42) . '.json',
            ] as $pattern) {
                foreach (glob($pattern) ?: [] as $file) {
                    unlink($file);
                }
            }
            @rmdir($dir . '/.running');
            @rmdir($dir . '/.candy-code/workflows/.running');
            @rmdir($dir . '/.candy-code/workflows');
            @rmdir($dir . '/.candy-code');
            @rmdir($dir);
        }
        $this->statusDirs = [];
    }

    public function testTheLedgerRendersPerStageColumnsAndTotals(): void
    {
        $table = WorkflowLinter::renderStageLedger($this->ledgerDoc());

        self::assertStringContainsString('Attempts', $table);
        self::assertStringContainsString('gather', $table);
        self::assertStringContainsString('12s', $table);
        self::assertStringContainsString('$0.2500', $table);
        // The attempts-bearing row sums its agents; the older row shape
        // (pre-attempts pause files) shows the em-dash instead of lying.
        self::assertMatchesRegularExpression('/gather\s*\|\s*completed\s*\|\s*12s\s*\|\s*\$0\.2500\s*\|\s*100\s*\|\s*3/', $table);
        self::assertMatchesRegularExpression('/fix\s*\|\s*failed\s*\|\s*1m05s\s*\|\s*\$0\.1000\s*\|\s*40\s*\|/', $table);
        self::assertStringContainsString('—', $table);
        self::assertStringContainsString('Totals: 140 tokens, $0.3500 (paused 2026-11-06T10:05:00+00:00)', $table);
    }

    public function testALedgerWithoutStagesRendersNothing(): void
    {
        self::assertSame('', WorkflowLinter::renderStageLedger(['stageResults' => []]));
        self::assertSame('', WorkflowLinter::renderStageLedger([]));
    }

    public function testTheExactNameFileWinsAndAContentMatchRescuesAStaleName(): void
    {
        $root = $this->makeStatusDir([
            'run-7.json' => ['workflowId' => 'run-7', 'stageResults' => []],
            'stale-name.json' => ['workflowId' => 'run-8', 'stageResults' => []],
        ]);

        $direct = WorkflowLinter::readPauseLedger($root, 'run-7');
        self::assertIsArray($direct);
        self::assertSame('run-7', $direct['workflowId']);

        // Run ids re-mint per process; the engine's own lookup falls back to
        // the recorded workflowId, and so does the mirror.
        $rescued = WorkflowLinter::readPauseLedger($root, 'run-8');
        self::assertIsArray($rescued);
        self::assertSame('run-8', $rescued['workflowId']);
    }

    public function testAFileWhoseContentNamesAnotherRunIsNeverReturned(): void
    {
        $root = $this->makeStatusDir([
            'run-9.json' => ['workflowId' => 'someone-else', 'stageResults' => []],
        ]);

        self::assertNull(WorkflowLinter::readPauseLedger($root, 'run-9'));
    }

    public function testPathShapedIdentifiersAreRefusedBeforeTheFilesystem(): void
    {
        $root = $this->makeStatusDir(['run-1.json' => ['workflowId' => 'run-1', 'stageResults' => []]]);

        foreach (['../run-1', 'a/b', '', 'x\\.y'] as $identifier) {
            self::assertNull(WorkflowLinter::readPauseLedger($root, $identifier), 'identifier ' . var_export($identifier, true) . ' must not resolve');
        }
    }

    public function testAMissingPauseDirectoryIsNoLedger(): void
    {
        $bare = sys_get_temp_dir() . '/wf-wfa-empty-' . bin2hex(random_bytes(6));
        mkdir($bare, 0700);
        $this->statusDirs[] = $bare;

        self::assertNull(WorkflowLinter::readPauseLedger($bare, 'run-1'));
    }

    public function testThreeArgumentStatusKeepsThePlainRowForOlderCallers(): void
    {
        $result = WorkflowCommand::status($this->pausedStub(), '/workflow status wf-x', 'wf-x');

        self::assertFalse($result->rows[1]->error);
        self::assertStringContainsString('wf-x', $result->rows[1]->content);
        self::assertStringContainsString('paused', $result->rows[1]->content);
        self::assertStringNotContainsString('Attempts', $result->rows[1]->content);
    }

    public function testAContextWithoutAReachableLedgerStillPrintsThePlainRow(): void
    {
        $result = WorkflowCommand::status(
            $this->pausedStub(),
            '/workflow status wf-y',
            'wf-y',
            CommandContext::new(workflowEngine: $this->pausedStub(), root: sys_get_temp_dir()),
        );

        self::assertFalse($result->rows[1]->error);
        self::assertStringContainsString('wf-y', $result->rows[1]->content);
        self::assertStringNotContainsString('Attempts', $result->rows[1]->content);
    }

    public function testAContextWhoseHomeHoldsTheLedgerAppendsTheColumns(): void
    {
        // The append leg end to end: with HOME pointed at a private config
        // dir, the command's own registry resolves the pause directory,
        // finds the ledger, and the row carries the stage table.
        $home = sys_get_temp_dir() . '/wf-wfa-home-' . bin2hex(random_bytes(6));
        mkdir($home . '/.candy-code/workflows/.running', 0700, true);
        $this->statusDirs[] = $home;
        $previous = getenv('HOME');
        $previousServer = $_SERVER['HOME'] ?? null;
        putenv('HOME=' . $home);
        // Both HOME spellings move together (OneSidedHomeSandboxTest law): a
        // reader that consults $_SERVER must see the sandbox too.
        $_SERVER['HOME'] = $home;
        try {
            $ledger = $this->ledgerDoc();
            $ledger['workflowId'] = 'run-7';
            file_put_contents(
                $home . '/.candy-code/workflows/.running/run-7.json',
                json_encode($ledger, JSON_THROW_ON_ERROR),
            );
            $result = WorkflowCommand::status(
                $this->pausedStub(),
                '/workflow status run-7',
                'run-7',
                CommandContext::new(workflowEngine: $this->pausedStub()),
            );
            self::assertStringContainsString('Attempts', $result->rows[1]->content);
            self::assertStringContainsString('gather', $result->rows[1]->content);
        } finally {
            if ($previous === false) {
                putenv('HOME');
            } else {
                putenv('HOME=' . $previous);
            }
            if ($previousServer === null) {
                unset($_SERVER['HOME']);
            } else {
                $_SERVER['HOME'] = $previousServer;
            }
        }
    }

    // ------------------------------------------------------------------ shapes

    /** @return array<string,mixed> */
    private function ledgerDoc(): array
    {
        return [
            'workflowId' => 'run-7',
            'workflowPath' => 'demo',
            'status' => 'paused',
            'stagesCompleted' => 1,
            'context' => [],
            'stageResults' => [
                [
                    'stageName' => 'gather',
                    'status' => 'completed',
                    'agents' => [
                        [
                            'agentId' => 'a1',
                            'status' => 'completed',
                            'tokensUsed' => 100,
                            'costUsd' => 0.25,
                            'startedAt' => '2026-11-06T10:00:00+00:00',
                            'completedAt' => '2026-11-06T10:00:12+00:00',
                            'attempts' => 3,
                        ],
                    ],
                    'startedAt' => '2026-11-06T10:00:00+00:00',
                    'completedAt' => '2026-11-06T10:00:12+00:00',
                ],
                [
                    // Pre-attempts pause shape: no stage span, so the
                    // duration falls to the widest agent span and the
                    // attempts column reads as unknown.
                    'stageName' => 'fix',
                    'status' => 'failed',
                    'agents' => [
                        [
                            'agentId' => 'a2',
                            'status' => 'failed',
                            'tokensUsed' => 40,
                            'costUsd' => 0.1,
                            'startedAt' => '2026-11-06T10:01:00+00:00',
                            'completedAt' => '2026-11-06T10:02:05+00:00',
                        ],
                    ],
                ],
            ],
            'totalTokens' => 140,
            'totalCost' => 0.35,
            'startedAt' => '2026-11-06T09:59:00+00:00',
            'pausedAt' => '2026-11-06T10:05:00+00:00',
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $files basename => document
     */
    private function makeStatusDir(array $files): string
    {
        $root = sys_get_temp_dir() . '/wf-wfa-status-' . bin2hex(random_bytes(6));
        mkdir($root . '/.running', 0700, true);
        $this->statusDirs[] = $root;
        foreach ($files as $name => $document) {
            file_put_contents($root . '/.running/' . $name, json_encode($document, JSON_THROW_ON_ERROR));
        }

        return $root;
    }

    private function pausedStub(): WorkflowEngineInterface
    {
        return new class () implements WorkflowEngineInterface {
            public function run(string $workflowPath, array $context = [], ?\SugarCraft\Code\Backend\CancellationToken $cancellation = null): WorkflowResult
            {
                throw new \LogicException('status tests never run a workflow');
            }

            public function pause(string $workflowId): void
            {
                throw new \LogicException('status tests never pause');
            }

            public function resume(string $workflowId, ?\SugarCraft\Code\Backend\CancellationToken $cancellation = null): WorkflowResult
            {
                throw new \LogicException('status tests never resume');
            }

            public function status(string $workflowId): WorkflowStatus
            {
                return WorkflowStatus::Paused;
            }

            public function listWorkflows(): array
            {
                return [];
            }
        };
    }
}
