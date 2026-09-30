<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tests\Support\RosterAgent;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;

/**
 * The PARENT half of the sub-agent visibility fix: SubAgentActivity frames
 * (however they arrived — socket here, wire in the codec test) materialise as
 * mirror rows in the parent's AgentManager so active(), liveOutput(), the
 * dashboard, and AgentsCommand render delegated runs unchanged. Pins the
 * projection's fail-closed edges too: orphan beats, unknown roster names, and
 * post-terminal stragglers must NOT invent or resurrect rows, and clearing
 * between turns removes mirrors without ever touching genuinely-spawned
 * local rows.
 */
final class AgentManagerProjectionTest extends TestCase
{
    private function manager(): AgentManager
    {
        $manager = new AgentManager(new ScriptedProvider([]), new SkillRegistry());
        // Registered INACTIVE on purpose: every "working" verdict below must
        // come from the projected mirror, never from the registration.
        $manager->register(RosterAgent::named('coder')->withActive(false));

        return $manager;
    }

    private static function beat(
        string $op,
        string $id,
        string $name,
        string $task = '',
        int $seq = 1,
        string $tail = '',
    ): SubAgentActivity {
        return new SubAgentActivity($op, $id, $name, $task, $seq, $tail);
    }

    public function testAStartedBeatMaterialisesAWorkingMirrorRow(): void
    {
        $manager = $this->manager();

        $manager->projectRemoteSubAgent(self::beat(SubAgentActivity::OP_STARTED, 'cid-1', 'coder', 'Audit candy-core', 1, 'begins'));

        $this->assertSame(1, $manager->projectedSubAgentCount());
        $this->assertTrue($manager->isWorking('coder'), 'the dashboard dot flips on from the open beat alone');
        $this->assertSame(['coder'], array_map(static fn ($a) => $a->name, $manager->active()));
        $row = $manager->getSubAgent('cid-1');
        $this->assertNotNull($row);
        $this->assertSame(SubAgent::STATUS_RUNNING, $row->status);
        $this->assertSame('begins', $row->output);
        $this->assertNotNull($row->startedAt, 'elapsed time reads from the beat, not from construction');
        $this->assertNull($row->permissionGate, 'a mirror observes, it does not execute — no gate to seal');
    }

    public function testAnOrphanProgressBeatIsDropped(): void
    {
        $manager = $this->manager();

        $manager->projectRemoteSubAgent(self::beat(SubAgentActivity::OP_PROGRESS, 'ghost', 'coder', '', 4, 'stray tail'));

        $this->assertSame(0, $manager->projectedSubAgentCount(), 'no started beat, no row — out-of-order frames must not invent one');
        $this->assertFalse($manager->isWorking('coder'));
    }

    public function testAStartedBeatForAnUnknownRosterNameIsDropped(): void
    {
        $manager = $this->manager();

        $manager->projectRemoteSubAgent(self::beat(SubAgentActivity::OP_STARTED, 'cid-1', 'no-such-agent', 'task', 1, ''));

        $this->assertSame(0, $manager->projectedSubAgentCount());
        $this->assertNull($manager->getSubAgent('cid-1'));
    }

    public function testProgressBeatsStreamIntoTheLiveBuffers(): void
    {
        $manager = $this->manager();
        $manager->projectRemoteSubAgent(self::beat(SubAgentActivity::OP_STARTED, 'cid-1', 'coder', 'task', 1, ''));

        $manager->projectRemoteSubAgent(self::beat(SubAgentActivity::OP_PROGRESS, 'cid-1', 'coder', '', 2, 'step one done'));

        $row = $manager->getSubAgent('cid-1');
        $this->assertNotNull($row);
        $this->assertSame(SubAgent::STATUS_STREAMING, $row->status);
        $this->assertSame('step one done', $row->output);
        $this->assertSame(['coder' => 'step one done'], $manager->liveOutputs(), 'the strip sees a delegation in flight');
        $this->assertSame('step one done', $manager->liveOutput('coder'));
    }

    public function testFinishedSettlesTheRowTerminalAndLateBeatsCannotReviveIt(): void
    {
        $manager = $this->manager();
        $manager->projectRemoteSubAgent(self::beat(SubAgentActivity::OP_STARTED, 'cid-1', 'coder', 'task', 1, ''));
        $manager->projectRemoteSubAgent(self::beat(SubAgentActivity::OP_PROGRESS, 'cid-1', 'coder', '', 2, 'working'));

        $manager->projectRemoteSubAgent(self::beat(SubAgentActivity::OP_FINISHED, 'cid-1', 'coder', '', 3, 'final report'));

        $row = $manager->getSubAgent('cid-1');
        $this->assertNotNull($row);
        $this->assertSame(SubAgent::STATUS_COMPLETE, $row->status);
        $this->assertNotNull($row->completedAt);
        $this->assertSame([], $manager->liveOutputs(), 'a settled row leaves the live strip');
        $this->assertSame('final report', $manager->liveOutput('coder'), 'but the report stays peekable');

        $manager->projectRemoteSubAgent(self::beat(SubAgentActivity::OP_PROGRESS, 'cid-1', 'coder', '', 9, 'too late'));

        $this->assertSame('final report', $row->output, 'post-terminal stragglers are ignored, not applied out of order');
        $this->assertSame(SubAgent::STATUS_COMPLETE, $row->status);
    }

    public function testClearingRemovesMirrorsButNeverLocalRows(): void
    {
        $manager = $this->manager();
        $manager->projectRemoteSubAgent(self::beat(SubAgentActivity::OP_STARTED, 'mirror-1', 'coder', 'task', 1, ''));
        $local = $manager->createSubAgent('coder', 'genuinely spawned');

        $manager->clearProjectedSubAgents();

        $this->assertNull($manager->getSubAgent('mirror-1'));
        $this->assertSame(0, $manager->projectedSubAgentCount());
        $rows = $manager->subAgentsOf('coder');
        $this->assertCount(1, $rows, 'the local spawn is the manager\'s own bookkeeping — clearing display state must not destroy it');
        $this->assertSame($local->id, $rows[0]->id);
    }

    public function testTwoConcurrentRunsOfOneNameStayTwoDistinctRows(): void
    {
        $manager = $this->manager();
        $manager->projectRemoteSubAgent(self::beat(SubAgentActivity::OP_STARTED, 'run-a', 'coder', 'first task', 1, 'A1'));
        $manager->projectRemoteSubAgent(self::beat(SubAgentActivity::OP_STARTED, 'run-b', 'coder', 'second task', 1, 'B1'));

        $this->assertCount(2, $manager->subAgentsOf('coder'), 'the id, not the name, is the projection key');

        $manager->projectRemoteSubAgent(self::beat(SubAgentActivity::OP_PROGRESS, 'run-a', 'coder', '', 2, 'A2'));

        $this->assertSame("A2\nB1", $manager->liveOutput('coder'), 'creation order, newest last, both runs rolled up');
    }
}
