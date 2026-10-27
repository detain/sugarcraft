<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Agents\Live;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\Live\AgentLiveRegistry;
use SugarCraft\Code\Agents\Live\AgentLiveState;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Events\SubAgentActivity;
use SugarCraft\Code\Tui\AgentStrip;

/**
 * P-AGENT.a: the whole view is the store's answer; a session view is a read
 * through the attribution the frames already carry. `SubAgentActivity` ships
 * `parentSessionId` on every v2 frame, so the fold rides it onto the state and
 * {@see AgentLiveRegistry::forSession()} filters on it — while `null` keeps
 * answering everything, the shape the serve/SessionHost readers need (they
 * host every session's runs in one process). A frame from before the
 * attribute — or one that never carried it — folds to the empty string and is
 * visible only under `null`, never under a scoped id.
 *
 * {@see AgentStrip::itemsFor()} is the strip's door onto the same law, and
 * under `null` it must answer byte-for-byte what {@see AgentStrip::items()}
 * answers, because the TUI's existing call-sites and the serve path both keep
 * calling `items()` today (the renderer-wide switch is P-TABS.g).
 */
final class AgentLiveSessionScopeTest extends TestCase
{
    public function testTheNullSessionAnswersEveryRunInTheSameOrderAsAll(): void
    {
        $live = AgentLiveRegistry::new(static fn (): float => 50.0);
        $live->apply(self::openInSession('run_a', 'explore', 'ses_a'));
        $live->apply(self::openInSession('run_b', 'reviewer', 'ses_b'));
        $live->apply(self::openUnattributed('run_c', 'worker'));

        self::assertSame(
            ['run_a', 'run_b', 'run_c'],
            self::runIds($live->forSession(null)),
            'null is the whole view — every run, attributed or not',
        );
        self::assertSame($live->all(), $live->forSession(null), 'the null answer IS all() — the same state objects, not copies');
    }

    public function testEachSessionViewSeesOnlyItsOwnRuns(): void
    {
        $live = AgentLiveRegistry::new(static fn (): float => 50.0);
        $live->apply(self::openInSession('run_a', 'explore', 'ses_a'));
        $live->apply(self::openInSession('run_b', 'reviewer', 'ses_b'));
        $live->apply(self::openInSession('run_c', 'scout', 'ses_a'));

        self::assertSame(['run_a', 'run_c'], self::runIds($live->forSession('ses_a')));
        self::assertSame(['run_b'], self::runIds($live->forSession('ses_b')));
    }

    public function testAnUnattributedRunBelongsToNoSessionViewAndOnlyToTheWholeOne(): void
    {
        $live = AgentLiveRegistry::new(static fn (): float => 50.0);
        $live->apply(self::openInSession('run_a', 'explore', 'ses_a'));
        $live->apply(self::openUnattributed('run_legacy', 'worker'));

        self::assertSame(['run_a'], self::runIds($live->forSession('ses_a')));
        self::assertSame([], $live->forSession('ses_other'), 'a scoped view is never the fallback for runs that name no session');
        self::assertSame(['run_a', 'run_legacy'], self::runIds($live->forSession(null)));
        self::assertSame('', $live->get('run_legacy')?->parentSessionId, 'the legacy fold lands the empty attribution, not a fabricated one');
    }

    public function testTheScopedItemListViewDismissesTheSameWayAndMirrorsUnscopedNull(): void
    {
        $live = AgentLiveRegistry::new(static fn (): float => 50.0);
        $live->apply(self::openInSession('run_a', 'explore', 'ses_a'));
        $live->apply(self::openInSession('run_b', 'reviewer', 'ses_b'));
        $live->apply(self::openInSession('run_c', 'scout', 'ses_a'));

        self::assertSame(
            ['run_a', 'run_c'],
            self::runIds(AgentStrip::itemsFor($live, [], 'ses_a')),
        );
        self::assertSame(
            ['run_c'],
            self::runIds(AgentStrip::itemsFor($live, ['run_a' => true], 'ses_a')),
            'dismissal still applies inside a scoped view',
        );
        self::assertSame(
            AgentStrip::items($live),
            AgentStrip::itemsFor($live, [], null),
            'the serve law: null mirrors items() exactly',
        );
    }

    public function testTheBuildTimeColorResolverAnswersTheFoldAndIsStickyOnceSettled(): void
    {
        $answers = ['explore' => 'red'];
        $live = AgentLiveRegistry::new(static fn (): float => 60.0);
        $live->withColorResolver(static fn (string $name): ?string => $answers[$name] ?? null);

        $live->apply(self::openInSession('run_a', 'explore', 'ses_a'));
        self::assertSame('red', $live->get('run_a')?->color, 'the fold asked the manager-side resolver by the frame name');

        $answers = [];
        $live->apply(new SubAgentActivity(
            'progress', 'run_a', 'explore', '', 2, '', parentCallId: 'tc_a', parentSessionId: 'ses_a',
        ));
        self::assertSame('red', $live->get('run_a')?->color, 'the colour is sticky once answered — the roster going quiet does not clear a run mid-flight');

        $unseeded = AgentLiveRegistry::new(static fn (): float => 60.0);
        $unseeded->apply(self::openInSession('run_b', 'explore', 'ses_b'));
        self::assertNull($unseeded->get('run_b')?->color, 'without a resolver the state simply has no colour — nothing is invented');
    }

    public function testAFinishedFrameThatOmitsTheAttributeKeepsTheRunsSession(): void
    {
        $live = AgentLiveRegistry::new(static fn (): float => 70.0);
        $live->apply(self::openInSession('run_a', 'explore', 'ses_a'));
        $live->apply(new SubAgentActivity(
            'finished', 'run_a', 'explore', '', 2, 'done',
            parentCallId: 'tc_a', outcome: 'complete',
        ));

        self::assertSame('ses_a', $live->get('run_a')?->parentSessionId, 'a frame that omits the attribute does not clear what an earlier frame carried');
        self::assertSame(['run_a'], self::runIds($live->forSession('ses_a')), 'the finished run is still listable in its own session view for the linger');
    }

    /**
     * The seed itself, pinned at the launch (the lane-ab pool-constitution
     * shape): a real `Bootstrap::workspace()` — not a test-side construction
     * — must hand the folded roster its colour resolver. Every unit pin
     * above arms the resolver by hand, so without this one the withColorResolver
     * line could be deleted from the production seed and the whole suite
     * would stay green while every live run paints unaccented.
     */
    public function testTheProductionLaunchSeedsTheRegistryWithAColorResolver(): void
    {
        $dir = sys_get_temp_dir() . '/crush-agent-live-seed-' . bin2hex(random_bytes(6));
        mkdir($dir . '/home', 0o700, true);
        mkdir($dir . '/repo', 0o700, true);

        $home = getenv('HOME');
        $serverHome = $_SERVER['HOME'] ?? null;
        putenv('HOME=' . $dir . '/home');
        $_SERVER['HOME'] = $dir . '/home';

        try {
            $registry = Bootstrap::workspace($dir . '/repo')->service(AgentLiveRegistry::class);
            self::assertInstanceOf(AgentLiveRegistry::class, $registry);

            $resolver = (new \ReflectionProperty(AgentLiveRegistry::class, 'colorResolver'))->getValue($registry);
            self::assertInstanceOf(\Closure::class, $resolver, 'the workspace seed carries the preset-colour resolver through to the folded roster');
        } finally {
            $home === false ? putenv('HOME') : putenv('HOME=' . $home);
            if ($serverHome === null) {
                unset($_SERVER['HOME']);
            } else {
                $_SERVER['HOME'] = $serverHome;
            }
            exec('rm -rf ' . escapeshellarg($dir) . ' 2>&1');
        }
    }

    /**
     * @param list<AgentLiveState> $states
     *
     * @return list<string>
     */
    private static function runIds(array $states): array
    {
        return array_map(static fn (AgentLiveState $state): string => $state->id, $states);
    }

    private static function openInSession(string $id, string $name, string $session): SubAgentActivity
    {
        return new SubAgentActivity(
            'started', $id, $name, 'a task', 1, '',
            parentCallId: 'tc_' . $id, parentSessionId: $session,
        );
    }

    private static function openUnattributed(string $id, string $name): SubAgentActivity
    {
        return new SubAgentActivity('started', $id, $name, 'a task', 1, '', parentCallId: 'tc_' . $id);
    }
}
