<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Agents\Live;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\Live\ActivityItem;
use SugarCraft\Code\Agents\Live\AgentLiveRegistry;
use SugarCraft\Code\Agents\Live\RunLiveness;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Config\Settings\SessionSettings;
use SugarCraft\Code\Config\Settings\UiSettings;
use SugarCraft\Code\Events\SubAgentActivity;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * RS-AGENT-BACKLOG A1: the pure stall detector over live-registry rows.
 * Everything is answered from STORED timestamps against the registry's own
 * stored clock — no timers (E646), no wall-clock loops — so each pin drives an
 * injected clock and asks the question between explicit advances.
 *
 * The threshold-setting legs run under a HOME sandbox (the same shape
 * AgentLiveRegistryTest uses): an empty config answers the schema default,
 * and only the test that writes a value can move the answer. Every other
 * test passes its threshold explicitly and never touches config state.
 */
final class RunLivenessTest extends TestCase
{
    use HomeSandboxTrait;

    private string $home = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->home = sys_get_temp_dir() . '/crush-run-liveness-' . bin2hex(random_bytes(6));
        mkdir($this->home . '/.candy-code', 0o700, true);
        $this->useHomeSandbox($this->home);
        SessionSettings::reset();
        UiSettings::forget();
    }

    protected function tearDown(): void
    {
        SessionSettings::reset();
        UiSettings::forget();
        Bootstrap::useConfigPath(null);
        $this->restoreHomeSandbox();
        self::removeLivenessTree($this->home);

        parent::tearDown();
    }

    /**
     * The clamp law, pure: zero and below are OFF (a negative countdown is
     * never a threshold), a set value below the floor rises to it, and
     * anything at or above rides through untouched.
     */
    public function testTheThresholdClampsNonsenseToOffAndSmallValuesToTheFloor(): void
    {
        self::assertSame(0, RunLiveness::clampThreshold(0));
        self::assertSame(0, RunLiveness::clampThreshold(-5), 'a negative stored value means OFF, never a countdown');
        self::assertSame(30, RunLiveness::clampThreshold(1), 'the smallest set value rises to the floor');
        self::assertSame(30, RunLiveness::clampThreshold(29), 'one under the floor still rises');
        self::assertSame(30, RunLiveness::clampThreshold(RunLiveness::FLOOR_SECONDS), 'the floor itself rides unchanged');
        self::assertSame(31, RunLiveness::clampThreshold(31));
        self::assertSame(86401, RunLiveness::clampThreshold(86401), 'the clamp raises, never truncates');
    }

    /**
     * OFF is absolute: the longest silence the detector can see still answers
     * null while the threshold is 0 — the byte-identical-today default.
     */
    public function testTheDetectorAnswersNullForAnyGapWhileOff(): void
    {
        $now = 100.0;
        $registry = AgentLiveRegistry::new(static function () use (&$now): float {
            return $now;
        });
        $registry->apply(self::livenessFrame('progress', 'run_a', 2, 'tc_1', [ActivityItem::thinking()]));
        $now = 10_000.0;
        $registry->advance();

        self::assertSame(9900, RunLiveness::idleSeconds($registry, 'run_a'), 'the gap itself is still measured');
        self::assertNull(RunLiveness::stallSeconds($registry, 'run_a', RunLiveness::OFF), 'but the verdict stays silent while OFF');
    }

    /**
     * The edge is strictly-over: a run quiet for exactly N seconds with an
     * N-second threshold has not stalled yet; one second more has.
     */
    public function testTheVerdictFiresOnlyStrictlyPastTheThreshold(): void
    {
        $now = 100.0;
        $registry = AgentLiveRegistry::new(static function () use (&$now): float {
            return $now;
        });
        $registry->apply(self::livenessFrame('progress', 'run_a', 2, 'tc_1', [ActivityItem::thinking()]));

        $now = 130.0;
        $registry->advance();
        self::assertNull(RunLiveness::stallSeconds($registry, 'run_a', 30), 'at the threshold, still within budget');

        $now = 131.0;
        $registry->advance();
        self::assertSame(31, RunLiveness::stallSeconds($registry, 'run_a', 30), 'one over, and the row is stalling');
    }

    /**
     * Nothing to measure answers null: an unknown run, no run id at all, and
     * a finished run — its row stands as history, and time since its LAST
     * word is not a stall.
     */
    public function testRunsThatAreNotRunningAreNeverMeasured(): void
    {
        $registry = AgentLiveRegistry::new(static fn (): float => 100.0);
        self::assertNull(RunLiveness::idleSeconds($registry, 'run_ghost'));
        self::assertNull(RunLiveness::idleSeconds($registry, null), 'a row with no run id (a manager entry) carries no verdict');
        self::assertNull(RunLiveness::stallSeconds($registry, null, 30));

        $registry->apply(new SubAgentActivity('finished', 'run_done', 'explore', '', 1, '', parentCallId: 'tc_1', outcome: 'complete'));
        self::assertNotNull($registry->lastBeatAt('run_done'), 'the finished row keeps its last beat');
        self::assertNull(RunLiveness::idleSeconds($registry, 'run_done'), 'but a finished run answers no idle');
        self::assertNull(RunLiveness::stallSeconds($registry, 'run_done', 30));
    }

    /**
     * THE BEAT IS THE FOLD, NOT THE WIRE: a stale or replayed frame writes no
     * state and therefore records no beat — a flood of replays cannot keep a
     * silent run looking alive. An accepted fold moves the beat to the clock
     * of its own tick.
     */
    public function testOnlyAcceptedFoldsRefreshTheBeat(): void
    {
        $now = 100.0;
        $registry = AgentLiveRegistry::new(static function () use (&$now): float {
            return $now;
        });
        $registry->apply(self::livenessFrame('progress', 'run_a', 5, 'tc_1', [ActivityItem::thinking()]));
        self::assertSame(100.0, $registry->lastBeatAt('run_a'));

        $now = 200.0;
        $registry->advance();
        $registry->apply(self::livenessFrame('progress', 'run_a', 3, 'tc_1', [ActivityItem::toolStarted('c9', 'Bash', 'ls')]));
        $registry->apply(self::livenessFrame('progress', 'run_a', 5, 'tc_1', [ActivityItem::thinking()]));
        self::assertSame(100.0, $registry->lastBeatAt('run_a'), 'an older seq and an identical replay record no beat');
        self::assertSame(100, RunLiveness::idleSeconds($registry, 'run_a'));

        $registry->apply(self::livenessFrame('progress', 'run_a', 6, 'tc_1', [ActivityItem::thinking()]));
        self::assertSame(200.0, $registry->lastBeatAt('run_a'), 'the next accepted fold moves it');
        self::assertSame(0, RunLiveness::idleSeconds($registry, 'run_a'));
    }

    /**
     * A queued placeholder gives its row to the run proper; its beat must go
     * with it, so the beat map holds no ids the state map has forgotten.
     */
    public function testTheQueuedPlaceholderLosesItsBeatWithItsRow(): void
    {
        $registry = AgentLiveRegistry::new(static fn (): float => 100.0);
        $queued = SubAgentActivity::queuedId('tc_1');
        $registry->apply(new SubAgentActivity('queued', $queued, 'explore', 'map it', 1, '', parentCallId: 'tc_1', description: 'Map it'));
        self::assertSame(100.0, $registry->lastBeatAt($queued));

        $registry->apply(self::livenessFrame('started', 'run_a', 1, 'tc_1'));
        self::assertNull($registry->get($queued));
        self::assertNull($registry->lastBeatAt($queued), 'the placeholder beat retires with the placeholder');
        self::assertSame(100.0, $registry->lastBeatAt('run_a'));
    }

    /**
     * Eviction past MAX_FINISHED forgets the row and its beat together — the
     * same sweep the state map gets, keeping the two maps the same domain.
     */
    public function testEvictionSweepsTheBeatMapWithTheStateMap(): void
    {
        $registry = AgentLiveRegistry::new(static fn (): float => 1.0);
        for ($i = 0; $i <= AgentLiveRegistry::MAX_FINISHED; $i++) {
            $registry->apply(new SubAgentActivity('finished', "run_{$i}", 'explore', '', 1, '', parentCallId: "tc_{$i}", outcome: 'complete'));
        }

        self::assertNull($registry->get('run_0'), 'the oldest finished run is gone');
        self::assertNull($registry->lastBeatAt('run_0'), 'and its beat went with it');
        self::assertSame(1.0, $registry->lastBeatAt('run_1'), 'kept runs keep their beats');
    }

    /**
     * The settings door end-to-end: absent answers the schema default (OFF),
     * a nonsense stored value falls through to that default instead of
     * refusing the save, a set value under the floor rises to it, and a
     * real threshold reaches the verdict without any override parameter.
     */
    public function testTheSettingDrivesTheVerdictThroughTheSandboxedRead(): void
    {
        self::assertSame(0, RunLiveness::thresholdSeconds(), 'an empty config is the byte-identical today: OFF');

        $this->writeStallConfig(['agentStallSeconds' => 'nonsense']);
        self::assertSame(0, RunLiveness::thresholdSeconds(), 'an unparseable value costs the key, not the save');

        $this->writeStallConfig(['agentStallSeconds' => 10]);
        self::assertSame(30, RunLiveness::thresholdSeconds(), 'the clamp lives at the read, not the validator');

        $this->writeStallConfig(['agentStallSeconds' => 45]);
        self::assertSame(45, RunLiveness::thresholdSeconds());

        $now = 100.0;
        $registry = AgentLiveRegistry::new(static function () use (&$now): float {
            return $now;
        });
        $registry->apply(self::livenessFrame('progress', 'run_a', 2, 'tc_1', [ActivityItem::thinking()]));
        $now = 145.0;
        $registry->advance();
        self::assertNull(RunLiveness::stallSeconds($registry, 'run_a'), 'at the stored 45 the row is still within budget');
        $now = 146.0;
        $registry->advance();
        self::assertSame(46, RunLiveness::stallSeconds($registry, 'run_a'), 'one over the stored threshold and the setting-alone path fires');
    }

    /**
     * The consts the whole shape rests on: the key names the settings entry,
     * OFF is the schema default, and the floor is the documented 30.
     */
    public function testTheDetectorVocabularyIsTheConfiguredOne(): void
    {
        self::assertSame('agentStallSeconds', RunLiveness::SETTING_KEY);
        self::assertSame(0, RunLiveness::OFF);
        self::assertSame(30, RunLiveness::FLOOR_SECONDS);
    }

    /** @param array<string, mixed> $values */
    private function writeStallConfig(array $values): void
    {
        file_put_contents($this->home . '/.candy-code/config.json', json_encode($values, JSON_THROW_ON_ERROR));
        UiSettings::forget();
    }

    private static function removeLivenessTree(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            @unlink($dir);

            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeLivenessTree($dir . '/' . $entry);
            }
        }
        @rmdir($dir);
    }

    /**
     * @param list<ActivityItem> $items
     * @param array<string, int|float> $stats
     */
    private static function livenessFrame(string $op, string $id, int $seq, string $call, array $items = [], array $stats = []): SubAgentActivity
    {
        return new SubAgentActivity($op, $id, 'explore', '', $seq, '', parentCallId: $call, items: $items, stats: $stats);
    }
}
