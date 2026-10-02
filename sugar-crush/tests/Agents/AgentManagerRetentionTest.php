<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentStatus;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\ExecutorInterface;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tests\Support\RosterAgent;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;

/**
 * Audit AG-3: AgentManager used to keep every sub-agent it ever saw — each
 * with its full final output — for the life of the process, and
 * liveOutputs() scans that whole map on every frame. A TUI left open running
 * a workflow an hour grew without bound. Finished sub-agents are now capped
 * at {@see AgentManager::RETAINED_TERMINAL_SUB_AGENTS}, oldest evicted first,
 * while anything still pending or running is never touched and the per-agent
 * telemetry roll-ups keep counting what was evicted.
 */
final class AgentManagerRetentionTest extends TestCase
{
    private const int RUNS = 200;

    public function testTwoHundredSequentialBatchesLeaveTheMapBounded(): void
    {
        $manager = $this->manager();

        for ($i = 0; $i < self::RUNS; $i++) {
            $this->runBatch($manager, 1);
        }

        $this->assertLessThanOrEqual(
            AgentManager::RETAINED_TERMINAL_SUB_AGENTS,
            count($manager->subAgentsOf('worker')),
            'finished sub-agents must not accumulate for the life of the session',
        );
    }

    public function testABatchLargerThanTheCapStillYieldsEveryResult(): void
    {
        $manager = $this->manager();

        $results = $this->runBatch($manager, self::RUNS);

        $this->assertCount(self::RUNS, $results, 'eviction must never eat the current batch\'s results');
        $this->assertCount(self::RUNS, array_unique(array_map(static fn (AgentResult $r): string => $r->agentId, $results)));
        foreach ($results as $result) {
            $this->assertSame(AgentStatus::Completed, $result->status);
            $this->assertSame('output of ' . $result->agentId, $result->output);
        }
        $this->assertLessThanOrEqual(AgentManager::RETAINED_TERMINAL_SUB_AGENTS, count($manager->subAgentsOf('worker')));
    }

    public function testRunningAndPendingSubAgentsAreNeverEvicted(): void
    {
        $manager = $this->manager();
        $manager->register(RosterAgent::named('watcher'));

        $running = $manager->createSubAgent('watcher', 'long-lived task');
        $running->status = SubAgent::STATUS_STREAMING;
        $running->startedAt = new \DateTimeImmutable();
        $running->output = 'still going';
        $pending = $manager->createSubAgent('watcher', 'queued task');

        for ($i = 0; $i < self::RUNS; $i++) {
            $this->runBatch($manager, 1);
        }

        $this->assertSame($running, $manager->getSubAgent($running->id));
        $this->assertSame($pending, $manager->getSubAgent($pending->id));
        $this->assertTrue($manager->isWorking('watcher'));
        $this->assertSame(['watcher' => 'still going'], $manager->liveOutputs(), 'liveOutputs() still reports the running agent');
    }

    public function testTheNewestFinishedSubAgentsAreTheOnesKept(): void
    {
        $manager = $this->manager();

        $first = $this->runBatch($manager, 1)[0]->agentId;
        for ($i = 1; $i < self::RUNS - 1; $i++) {
            $this->runBatch($manager, 1);
        }
        $last = $this->runBatch($manager, 1)[0]->agentId;

        $this->assertNull($manager->getSubAgent($first), 'the oldest finished sub-agent goes first');
        $kept = $manager->getSubAgent($last);
        $this->assertNotNull($kept, 'the batch that just settled stays readable for the dashboard');
        $this->assertSame('output of ' . $last, $kept->output);
        $this->assertSame('output of ' . $last, substr($manager->liveOutput('worker'), -strlen('output of ' . $last)));
    }

    public function testRolledUpTelemetryStillCountsEvictedSubAgents(): void
    {
        $manager = $this->manager();

        for ($i = 0; $i < self::RUNS; $i++) {
            $this->runBatch($manager, 1);
        }

        $this->assertSame(self::RUNS * 3, $manager->tokensUsed('worker'));
        $this->assertEqualsWithDelta(self::RUNS * 0.01, $manager->costUsd('worker'), 0.000001);
        // Run i spans [T+i, T+i+10]; the earliest start belongs to an evicted
        // run, so a span computed from the survivors alone would be shorter.
        $this->assertSame(self::RUNS - 1 + 10, $manager->elapsedSeconds('worker'));
    }

    public function testExplicitRemovalIsNotFoldedIntoTheRollUp(): void
    {
        $manager = $this->manager();
        $id = $this->runBatch($manager, 1)[0]->agentId;

        $manager->removeSubAgent($id);

        $this->assertNull($manager->getSubAgent($id));
        $this->assertSame(0, $manager->tokensUsed('worker'), 'a caller that removes a row asked for it to be forgotten');
        $this->assertSame(0, $manager->elapsedSeconds('worker'));
    }

    public function testProjectedMirrorRowsAreLeftToTheTurnClear(): void
    {
        $manager = $this->manager();
        $manager->register(RosterAgent::named('coder'));
        $manager->projectRemoteSubAgent(new SubAgentActivity(SubAgentActivity::OP_STARTED, 'cid-1', 'coder', 'task', 1, 'begins'));
        $manager->projectRemoteSubAgent(new SubAgentActivity(SubAgentActivity::OP_FINISHED, 'cid-1', 'coder', 'task', 2, 'report'));

        for ($i = 0; $i < self::RUNS; $i++) {
            $this->runBatch($manager, 1);
        }

        $this->assertNotNull($manager->getSubAgent('cid-1'), 'a settled mirror is read between turns and cleared at the next dispatch');
        $this->assertSame(1, $manager->projectedSubAgentCount());

        $manager->clearProjectedSubAgents();
        $this->assertNull($manager->getSubAgent('cid-1'));
    }

    private function manager(): AgentManager
    {
        $manager = new AgentManager(
            new ScriptedProvider([]),
            new SkillRegistry(),
            new AgentWorkerPool(4, self::executor()),
        );
        $manager->register(RosterAgent::named('worker'));

        return $manager;
    }

    /**
     * @return list<AgentResult>
     */
    private function runBatch(AgentManager $manager, int $size): array
    {
        $agents = [];
        for ($i = 0; $i < $size; $i++) {
            $agents[] = $manager->createSubAgent('worker', 'task ' . $i);
        }

        return iterator_to_array(
            $manager->executeAll($agents, new CompleteRequest(model: 'test-model', messages: [])),
            false,
        );
    }

    /**
     * A synchronous fake whose results carry a known output, usage and a
     * strictly increasing time window, so eviction is observable in every
     * roll-up the dashboard reads.
     */
    private static function executor(): ExecutorInterface
    {
        return new class implements ExecutorInterface {
            private int $n = 0;

            public function execute(SubAgent $agent, CompleteRequest $request): AgentResult
            {
                $start = (new \DateTimeImmutable('2024-01-15T10:00:00Z'))->modify('+' . $this->n++ . ' seconds');

                return new AgentResult(
                    agentId: $agent->id,
                    status: AgentStatus::Completed,
                    output: 'output of ' . $agent->id,
                    tokensUsed: 3,
                    costUsd: 0.01,
                    startedAt: $start,
                    completedAt: $start->modify('+10 seconds'),
                );
            }

            public function executeStream(SubAgent $agent, CompleteRequest $request): \Generator
            {
                yield $this->execute($agent, $request);
            }

            public function cancel(string $agentId): void {}

            public function cancelAll(): void {}
        };
    }
}
