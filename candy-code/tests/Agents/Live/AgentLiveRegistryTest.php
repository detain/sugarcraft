<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Agents\Live;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Style\Style;
use SugarCraft\Code\Agents\Live\ActivityItem;
use SugarCraft\Code\Agents\Live\AgentLiveRegistry;
use SugarCraft\Code\Agents\Live\AgentLiveState;
use SugarCraft\Code\Agents\Live\AgentLiveStatus;
use SugarCraft\Code\Agents\Live\AgentRunCards;
use SugarCraft\Code\Agents\Live\RunCardStatus;
use SugarCraft\Code\Events\SubAgentActivity;

/**
 * Roadmap P-B2: the parent's fold of the v2 activity frames — one state per
 * run, the runs hung under their Task call, the queued placeholder handed
 * over to the real run, and a clock that moves only when update() says so.
 */
final class AgentLiveRegistryTest extends TestCase
{
    /**
     * a11y-F5: under the ambient Ascii colour profile the spinner cycle is
     * the four-stroke `|/-\`, and the stored (mod 10) frame index wraps on
     * the ASCII set's own count. A null ambient (pipes, goldens) and any
     * richer profile keep the braille dots byte-identically.
     */
    public function testTheAsciiProfileSpinsTheAsciiCycleAndTheOthersStayBraille(): void
    {
        $previous = Style::getDefaultProfile();

        try {
            Style::setDefaultProfile(ColorProfile::Ascii);
            self::assertSame(AgentLiveRegistry::SPINNER_ASCII, AgentLiveRegistry::spinnerFrames());
            self::assertSame(['|', '/', '-', '\\'], array_map(
                static fn (int $f): string => AgentLiveRegistry::spinnerGlyph($f),
                [0, 1, 2, 3],
            ));
            self::assertSame('|', AgentLiveRegistry::spinnerGlyph(4), 'the cycle wraps on the ASCII count');
            self::assertSame('/', AgentLiveRegistry::spinnerGlyph(5), 'the mod-10 stored index maps down safely');

            Style::setDefaultProfile(ColorProfile::Ansi256);
            self::assertSame(AgentLiveRegistry::SPINNER, AgentLiveRegistry::spinnerFrames());
            self::assertSame('⠋', AgentLiveRegistry::spinnerGlyph(0));

            Style::setDefaultProfile(null);
            self::assertSame('⠙', AgentLiveRegistry::spinnerGlyph(1), 'the default ambient is the braille cycle — goldens unchanged');
        } finally {
            Style::setDefaultProfile($previous);
        }
    }

    /**
     * The glyph route is the ONLY allowed reader shape (a11y-F5): indexing the
     * const directly would skip the profile gate. Fail-closed census over the
     * transcript renderer.
     */
    public function testTheTranscriptRendererNeverIndexesTheSpinnerConstDirectly(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 3) . '/src/Renderer.php');

        self::assertSame(0, substr_count($source, 'AgentLiveRegistry::SPINNER['), 'every spinner reader must go through spinnerGlyph()');
    }

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
     * rv-a11y-4: with the static spinner engaged, every frame resolves to
     * index 0 no matter how far the clock runs — one glyph, zero churn.
     * (50.48 s and 500.48 s are chosen so the cycling computation lands on
     * frames 1 and 6: without the gate this test is red, not vacuous.)
     */
    public function testTheStaticSpinnerPinsEveryFrameToZeroWhenEngaged(): void
    {
        self::assertFalse(AgentLiveRegistry::staticSpinner());

        AgentLiveRegistry::setStaticSpinner(true);

        try {
            $clock = 50.48;
            $registry = AgentLiveRegistry::new(static function () use (&$clock): float {
                return $clock;
            });
            self::assertSame(0, $registry->spinnerFrame(), 'the ctor resolves frame 0 even past the first cycle');

            $clock = 500.48;
            $registry->advance();
            self::assertSame(0, $registry->spinnerFrame(), 'twelve seconds later, still frame 0');
        } finally {
            AgentLiveRegistry::setStaticSpinner(false);
        }

        self::assertFalse(AgentLiveRegistry::staticSpinner());
    }

    /**
     * v4-F15: the overlay state and the card status each have ONE
     * vocabulary — the constants that cross into out-of-scope readers and
     * the enum cases that name it canonically must move together or this
     * reddens. statusCase() is the parsed entry point; a finished card's
     * outcome passes through untouched.
     */
    public function testTheLiveAndCardStatusVocabulariesStayInLockstep(): void
    {
        self::assertSame(
            [AgentLiveState::STATUS_QUEUED, AgentLiveState::STATUS_RUNNING, AgentLiveState::STATUS_FINISHED],
            array_map(static fn (AgentLiveStatus $case): string => $case->value, AgentLiveStatus::cases()),
            'queued/running/finished in both spellings',
        );
        self::assertSame(
            [AgentRunCards::STATUS_RUNNING, AgentRunCards::STATUS_LOST],
            array_map(static fn (RunCardStatus $case): string => $case->value, RunCardStatus::cases()),
            'running/lost are the only statuses a card mints itself',
        );

        $registry = AgentLiveRegistry::new(static fn (): float => 50.0);
        $registry->apply(self::frame('queued', 'run_lockstep', 1, 'tc_9'));
        self::assertSame(AgentLiveStatus::Queued, $registry->get('run_lockstep')?->statusCase());
        $registry->apply(self::frame('finished', 'run_lockstep', 2, 'tc_9'));
        self::assertSame(AgentLiveStatus::Finished, $registry->get('run_lockstep')?->statusCase());

        self::assertSame('complete', AgentRunCards::statusOf(['status' => 'complete']), 'an outcome passes through as the string tools read');
    }

    /**
     * P-AGENT.a: the v2 frames carry their parent session and the workspace
     * seeds a name-keyed colour resolver — both ride onto the folded state so
     * scoped views and painted surfaces have something to read. A frame
     * without the attribute lands the empty attribution, never a guess.
     */
    public function testTheFoldRidesTheFramesSessionAndTheSeedColorsIt(): void
    {
        $registry = AgentLiveRegistry::new(static fn (): float => 50.0);
        $registry->withColorResolver(static fn (string $name): ?string => $name === 'explore' ? 'red' : null);

        $registry->apply(new SubAgentActivity('started', 'run_s1', 'explore', 'a task', 1, '', parentCallId: 'tc_9', parentSessionId: 'ses_top'));
        self::assertSame('ses_top', $registry->get('run_s1')?->parentSessionId);
        self::assertSame('red', $registry->get('run_s1')?->color);

        $registry->apply(new SubAgentActivity('started', 'run_s2', 'explore', 'a task', 1, '', parentCallId: 'tc_10'));
        self::assertSame('', $registry->get('run_s2')?->parentSessionId, 'an unattributed fold is the empty string, visible only to the whole view');
        self::assertCount(2, $registry->all());
        self::assertSame(['run_s1'], array_values(array_map(static fn ($s): string => $s->id, $registry->forSession('ses_top'))));
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
