<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents\Live;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Live\ActivityItem;
use SugarCraft\Crush\Agents\Live\AgentLiveRegistry;
use SugarCraft\Crush\Agents\Live\AgentLiveState;
use SugarCraft\Crush\Events\SubAgentActivity;

/**
 * Roadmap P-B2: the parent's fold of the v2 activity frames — one state per
 * run, the runs hung under their Task call, the queued placeholder handed
 * over to the real run, and a clock that moves only when update() says so.
 */
final class AgentLiveRegistryTest extends TestCase
{
    public function testFramesFoldIntoOneStatePerRunUnderItsTaskCall(): void
    {
        $registry = AgentLiveRegistry::new(static fn (): float => 50.0);

        $registry->apply(self::frame('started', 'run_a', 1, 'tc_1'));
        $registry->apply(self::frame('progress', 'run_a', 2, 'tc_1', [ActivityItem::toolStarted('c1', 'Read', 'a.php')], ['tools' => 1, 'tokensIn' => 10, 'tokensOut' => 5]));
        $registry->apply(self::frame('started', 'run_b', 1, 'tc_2'));

        $runs = $registry->forCall('tc_1');
        self::assertCount(1, $runs);
        self::assertSame('run_a', $runs[0]->id);
        self::assertTrue($runs[0]->isRunning());
        self::assertSame('Read', $runs[0]->currentCall()?->tool);
        self::assertSame(1, $runs[0]->toolCount);
        self::assertSame(15, $runs[0]->tokens());
        self::assertSame(['run_b'], array_map(static fn (AgentLiveState $s): string => $s->id, $registry->forCall('tc_2')));
        self::assertSame([], $registry->forCall('tc_unknown'));
    }

    public function testAStaleOrReplayedFrameChangesNothing(): void
    {
        $registry = AgentLiveRegistry::new(static fn (): float => 50.0);
        $registry->apply(self::frame('progress', 'run_a', 5, 'tc_1', [ActivityItem::thinking()]));
        $before = $registry->get('run_a');

        $registry->apply(self::frame('progress', 'run_a', 3, 'tc_1', [ActivityItem::toolStarted('c9', 'Bash', 'rm')]));
        $registry->apply(self::frame('progress', 'run_a', 5, 'tc_1', [ActivityItem::toolStarted('c9', 'Bash', 'rm')]));

        self::assertSame($before, $registry->get('run_a'));
    }

    public function testAFinishedRunStaysFinishedWithItsRealOutcome(): void
    {
        $registry = AgentLiveRegistry::new(static fn (): float => 50.0);
        $registry->apply(self::frame('progress', 'run_a', 2, 'tc_1', [ActivityItem::toolStarted('c1', 'Read', 'a.php')]));
        $registry->apply(new SubAgentActivity('finished', 'run_a', 'explore', '', 3, '', parentCallId: 'tc_1', outcome: 'failed', error: 'boom', resumeId: 'r1'));
        $registry->apply(self::frame('progress', 'run_a', 4, 'tc_1', [ActivityItem::thinking()]));

        $run = $registry->get('run_a');
        self::assertNotNull($run);
        self::assertTrue($run->isFinished());
        self::assertSame('failed', $run->outcome);
        self::assertSame('boom', $run->error);
        self::assertSame('r1', $run->resumeId);
        self::assertNull($run->currentCall(), 'a finished run is in no call');
        self::assertSame(50.0, $run->finishedAt);
    }

    public function testAQueuedPlaceholderIsReplacedByItsRunsOwnState(): void
    {
        $registry = AgentLiveRegistry::new(static fn (): float => 50.0);
        $registry->apply(new SubAgentActivity('queued', SubAgentActivity::queuedId('tc_1'), 'explore', 'map it', 1, '', parentCallId: 'tc_1', description: 'Map it'));

        $queued = $registry->forCall('tc_1');
        self::assertCount(1, $queued);
        self::assertTrue($queued[0]->isQueued());
        self::assertNull($queued[0]->elapsed(60.0), 'a queued member has not started, so it has no clock');

        $registry->apply(self::frame('started', 'run_a', 1, 'tc_1'));

        self::assertSame(['run_a'], array_map(static fn (AgentLiveState $s): string => $s->id, $registry->forCall('tc_1')));
        self::assertNull($registry->get(SubAgentActivity::queuedId('tc_1')));
    }

    public function testAReusedCallIdDoesNotHangAnOldRunUnderTheNewRow(): void
    {
        $registry = AgentLiveRegistry::new(static fn (): float => 50.0);
        $registry->apply(new SubAgentActivity('finished', 'run_old', 'explore', '', 2, '', parentCallId: 'call_1', outcome: 'complete'));
        $registry->apply(self::frame('started', 'run_new', 1, 'call_1'));

        self::assertSame(['run_new'], array_map(static fn (AgentLiveState $s): string => $s->id, $registry->forCall('call_1')));
    }

    public function testTheClockMovesOnlyOnAdvance(): void
    {
        $now = 10.0;
        $registry = AgentLiveRegistry::new(static function () use (&$now): float {
            return $now;
        });
        self::assertSame(10.0, $registry->now());
        self::assertSame(5, $registry->spinnerFrame(), '10 000 ms is frame 125, which is 5 of 10');

        $now = 10.08;
        self::assertSame(10.0, $registry->now(), 'reading never touches the clock');
        self::assertTrue($registry->advance());
        self::assertSame(10.08, $registry->now());
        self::assertSame(6, $registry->spinnerFrame());
        self::assertFalse($registry->advance(), 'nothing a frame shows moved');
    }

    public function testElapsedRunsFromTheChildsStartToTheParentsClock(): void
    {
        $registry = AgentLiveRegistry::new(static fn (): float => 100.0);
        $registry->apply(self::frame('progress', 'run_a', 2, 'tc_1', [], ['startedAt' => 88.0]));

        self::assertSame(12.0, $registry->get('run_a')?->elapsed($registry->now()));
    }

    public function testAV1FrameStillFoldsFromItsRunningTotals(): void
    {
        $registry = AgentLiveRegistry::new(static fn (): float => 100.0);
        $v1 = SubAgentActivity::fromArray(['op' => 'progress', 'id' => 'run_a', 'name' => 'explore', 'task' => '', 'seq' => 2, 'tail' => '-> Read', 'tokens' => 700, 'cost' => 0.5]);
        self::assertNotNull($v1);
        $registry->apply($v1);

        $run = $registry->get('run_a');
        self::assertSame(700, $run?->tokens());
        self::assertSame(0.5, $run?->costUsd);
        self::assertNull($run?->latest, 'no item was sent, so none is invented');
        self::assertSame([], $registry->forCall(''), 'a v1 frame names no Task row');
    }

    public function testTheOldestFinishedRunsAreForgottenPastTheCap(): void
    {
        $registry = AgentLiveRegistry::new(static fn (): float => 1.0);
        $registry->apply(self::frame('started', 'live', 1, 'tc_live'));
        for ($i = 0; $i <= AgentLiveRegistry::MAX_FINISHED; $i++) {
            $registry->apply(new SubAgentActivity('finished', "run_{$i}", 'explore', '', 1, '', parentCallId: "tc_{$i}", outcome: 'complete'));
        }

        self::assertNull($registry->get('run_0'));
        self::assertSame([], $registry->forCall('tc_0'));
        self::assertNotNull($registry->get('run_1'));
        self::assertNotNull($registry->get('live'), 'a running run is never evicted');
        self::assertCount(AgentLiveRegistry::MAX_FINISHED + 1, $registry->all());
    }

    public function testEachOwnerGetsItsOwnRegistryAndKeepsIt(): void
    {
        $a = new \ArrayObject();
        $b = new \ArrayObject();

        self::assertSame(AgentLiveRegistry::of($a), AgentLiveRegistry::of($a));
        self::assertNotSame(AgentLiveRegistry::of($a), AgentLiveRegistry::of($b));
    }

    public function testApplyBatchFoldsInOrder(): void
    {
        $registry = AgentLiveRegistry::new(static fn (): float => 1.0);
        $registry->applyBatch([
            self::frame('progress', 'run_a', 2, 'tc_1', [ActivityItem::toolStarted('c1', 'Read', 'a.php')]),
            self::frame('progress', 'run_a', 3, 'tc_1', [ActivityItem::toolFinished('c1', 'Read', true, 4)]),
        ]);

        $run = $registry->get('run_a');
        self::assertNull($run?->currentCall());
        self::assertSame(ActivityItem::TOOL_FINISHED, $run?->latest?->type);
        self::assertSame('a.php', $run?->latestSummary, 'the finished call keeps the summary its start carried');
    }

    /**
     * @param list<ActivityItem> $items
     * @param array<string, int|float> $stats
     */
    private static function frame(string $op, string $id, int $seq, string $call, array $items = [], array $stats = []): SubAgentActivity
    {
        return new SubAgentActivity($op, $id, 'explore', '', $seq, '', parentCallId: $call, items: $items, stats: $stats);
    }
}
