<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui\Components;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Renderer as LiveRenderer;
use SugarCraft\Crush\Sessions\BackgroundSession;
use SugarCraft\Crush\Sessions\BackgroundSessionStatus;
use SugarCraft\Crush\Sessions\BackgroundSupervisor;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tui\AgentViewMode;
use SugarCraft\Crush\Tui\Components\AgentDashboardPane;
use SugarCraft\Crush\Tui\KeyboardHandler;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Crush\Tui\Renderer as ShellRenderer;

/**
 * Covers the full-pane agent dashboard (crush_feat.md §5 E5): its stable
 * slot ordering, status grouping, height budget, the Space peek overlay, the
 * Alt+1..9 jump chords, and the shell wiring that makes it reachable.
 */
final class AgentDashboardPaneTest extends TestCase
{
    private ProviderInterface $provider;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(ProviderInterface::class);
        ShellRenderer::resetSizeCache();
    }

    protected function tearDown(): void
    {
        ShellRenderer::resetSizeCache();
    }

    // ── fixtures ─────────────────────────────────────────────────────────

    private function agent(string $name = 'reviewer', bool $isActive = true): Agent
    {
        return new Agent(
            name: $name,
            description: 'Reviews code for bugs',
            prompt: 'You are a reviewer.',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: $isActive,
        );
    }

    /** @param list<Agent> $agents */
    private function manager(array $agents): AgentManager
    {
        $manager = new AgentManager($this->provider, new SkillRegistry());
        foreach ($agents as $agent) {
            $manager->register($agent);
        }

        return $manager;
    }

    private function session(
        string $id,
        string $name,
        BackgroundSessionStatus $status = BackgroundSessionStatus::Running,
        string $output = '',
    ): BackgroundSession {
        return new BackgroundSession(
            id: $id,
            name: $name,
            agent: $this->agent('bg-' . $id),
            task: 'port the renderer',
            workingDirectory: '/tmp',
            status: $status,
            output: $output,
        );
    }

    private function app(?Chat $chat, Pane $pane = Pane::Agents): App
    {
        return App::new($this->provider, 'test-model')->withPane($pane)->withChat($chat);
    }

    // ── entries(): ordering + telemetry ──────────────────────────────────

    public function testEntriesIsEmptyWithoutAHostedChat(): void
    {
        $this->assertSame([], AgentDashboardPane::entries($this->app(null)));
    }

    public function testEntriesIsEmptyWhenChatHasNeitherManagerNorSupervisor(): void
    {
        $this->assertSame([], AgentDashboardPane::entries($this->app(new Chat())));
    }

    public function testEntriesListsAgentsFirstThenSessionsInStableOrder(): void
    {
        $supervisor = new BackgroundSupervisor();
        $supervisor->addSession($this->session('s1', 'first-bg'));
        $supervisor->addSession($this->session('s2', 'second-bg'));

        $app = $this->app(new Chat(
            agentManager: $this->manager([$this->agent('alpha'), $this->agent('beta')]),
            backgroundSupervisor: $supervisor,
        ));

        $names = array_map(static fn($e) => $e->name, AgentDashboardPane::entries($app));

        $this->assertSame(['alpha', 'beta', 'first-bg', 'second-bg'], $names);
        // Stability: a second call in the same state must yield the same slots.
        $this->assertSame($names, array_map(static fn($e) => $e->name, AgentDashboardPane::entries($app)));
    }

    public function testEntriesCarryRealAgentTelemetryRatherThanZeros(): void
    {
        $manager = $this->manager([$this->agent('alpha')]);
        $subAgent = $manager->createSubAgent('alpha', 'do the thing');
        $subAgent->startedAt = new \DateTimeImmutable('@' . (time() - 90));
        $subAgent->completedAt = new \DateTimeImmutable('@' . time());
        $subAgent->tokensUsed = 1234;
        $subAgent->costUsd = 0.42;

        $entry = AgentDashboardPane::entries($this->app(new Chat(agentManager: $manager)))[0];

        $this->assertSame(90, $entry->elapsedSeconds);
        $this->assertSame(1234, $entry->tokensUsed);
        $this->assertSame(0.42, $entry->costUsd);
    }

    public function testEntriesCarryABoundedOutputTailForSessions(): void
    {
        $supervisor = new BackgroundSupervisor();
        // 400 lines: far more than the peek window, and long enough that an
        // unbounded read would show the first line rather than the last.
        $supervisor->addSession($this->session(
            's1',
            'noisy',
            output: implode("\n", array_map(static fn(int $i) => "line {$i}", range(1, 400))),
        ));

        $entry = AgentDashboardPane::entries($this->app(new Chat(backgroundSupervisor: $supervisor)))[0];

        $this->assertLessThanOrEqual(8, count($entry->outputBuffer));
        $this->assertSame('line 400', $entry->outputBuffer[array_key_last($entry->outputBuffer)]);
    }

    // ── untrusted-text boundary ──────────────────────────────────────────

    /**
     * A background session's output is byte-for-byte whatever the daemonised
     * child wrote to its IPC socket — i.e. raw model and tool output. The
     * shell reaches this pane without passing through the chat renderer's
     * sanitiser, so the pane itself must hold the boundary.
     */
    public function testHostileSessionOutputAndNamesNeverReachTheFrame(): void
    {
        $supervisor = new BackgroundSupervisor();
        $supervisor->addSession(new BackgroundSession(
            id: 's1',
            name: "n\x1b[5mBLINK\u{E000}zone",
            agent: $this->agent('bg-s1'),
            task: "task\x1b]0;TITLE\x07",
            workingDirectory: '/tmp',
            status: BackgroundSessionStatus::Running,
            output: "safe line\n\x1b]0;PWNED\x07\x1b[2J\x1b[31mINJECTED\x1b[0m\u{E000}",
        ));

        $app = $this->app(new Chat(backgroundSupervisor: $supervisor))
            ->withAgentViewMode(AgentViewMode::Peek)
            ->withSelectedAgentIndex(0);

        $frame = AgentDashboardPane::render($app, 100, 20);

        $this->assertStringNotContainsString("\x1b]0;", $frame, 'OSC title-set survived');
        $this->assertStringNotContainsString("\x1b[2J", $frame, 'erase-display survived');
        $this->assertStringNotContainsString("\x1b[5m", $frame, 'blink SGR survived');
        $this->assertStringNotContainsString("\u{E000}", $frame, 'private-use sentinel survived');
        // The visible text still gets through — this is a sanitiser, not a censor.
        $this->assertStringContainsString('INJECTED', $frame);
        $this->assertStringContainsString('BLINK', $frame);
    }

    public function testHostileOutputCannotSmuggleARowPastTheHeightBudget(): void
    {
        $supervisor = new BackgroundSupervisor();
        // Bare CRs and LFs inside ONE tailed line would each cost a row the
        // budget never reserved, or return the cursor over the pane border.
        $supervisor->addSession($this->session(
            's1',
            'noisy',
            output: "a\rb\rc" . str_repeat("\nx", 3),
        ));

        $entries = AgentDashboardPane::entries($this->app(new Chat(backgroundSupervisor: $supervisor)));

        foreach ($entries[0]->outputBuffer as $line) {
            $this->assertStringNotContainsString("\r", $line);
            $this->assertStringNotContainsString("\n", $line);
        }
    }

    // ── group() ──────────────────────────────────────────────────────────

    /**
     * @dataProvider statusGroups
     */
    public function testGroupMapsStatusKeywords(string $status, string $expected): void
    {
        $this->assertSame($expected, AgentDashboardPane::group($status));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function statusGroups(): array
    {
        return [
            'working'   => ['working', 'working'],
            'streaming' => ['streaming', 'working'],
            'waiting'   => ['waiting', 'needs-input'],
            'pending'   => ['pending', 'ready'],
            'completed' => ['completed', 'completed'],
            'stopped'   => ['stopped', 'completed'],
            'failed'    => ['failed', 'completed'],
            'unknown'   => ['who-knows', 'completed'],
            'cased'     => ['  WORKING ', 'working'],
        ];
    }

    public function testSessionStatusesLandInTheExpectedGroups(): void
    {
        $supervisor = new BackgroundSupervisor();
        $supervisor->addSession($this->session('s1', 'run', BackgroundSessionStatus::Running));
        $supervisor->addSession($this->session('s2', 'stall', BackgroundSessionStatus::Stalled));
        $supervisor->addSession($this->session('s3', 'queued', BackgroundSessionStatus::Pending));
        $supervisor->addSession($this->session('s4', 'late', BackgroundSessionStatus::TimedOut));

        $entries = AgentDashboardPane::entries($this->app(new Chat(backgroundSupervisor: $supervisor)));
        $groups = array_map(static fn($e) => AgentDashboardPane::group($e->status), $entries);

        $this->assertSame(['working', 'needs-input', 'ready', 'completed'], $groups);
    }

    // ── indexForSlot() ───────────────────────────────────────────────────

    public function testIndexForSlotResolvesOneBasedSlotsToZeroBasedIndices(): void
    {
        $app = $this->app(new Chat(agentManager: $this->manager([
            $this->agent('alpha'),
            $this->agent('beta'),
        ])));

        $this->assertSame(0, AgentDashboardPane::indexForSlot($app, 1));
        $this->assertSame(1, AgentDashboardPane::indexForSlot($app, 2));
    }

    public function testIndexForSlotRejectsSlotsOutsideTheRoster(): void
    {
        $app = $this->app(new Chat(agentManager: $this->manager([$this->agent('alpha')])));

        $this->assertSame(-1, AgentDashboardPane::indexForSlot($app, 0));
        $this->assertSame(-1, AgentDashboardPane::indexForSlot($app, 2));
        $this->assertSame(-1, AgentDashboardPane::indexForSlot($app, 10));
    }

    // ── render() ─────────────────────────────────────────────────────────

    public function testRenderShowsThePlaceholderBoxWhenThereAreNoAgents(): void
    {
        $out = AgentDashboardPane::render($this->app(new Chat()), 60, 12);

        $this->assertStringContainsString('(no active agents)', $out);
        $this->assertStringContainsString('agents', $out);
    }

    public function testRenderGroupsRowsAndLabelsEachSlot(): void
    {
        $supervisor = new BackgroundSupervisor();
        $supervisor->addSession($this->session('s1', 'stalled-one', BackgroundSessionStatus::Stalled));

        $out = AgentDashboardPane::render(
            $this->app(new Chat(
                agentManager: $this->manager([$this->agent('alpha')]),
                backgroundSupervisor: $supervisor,
            )),
            100,
            14,
        );

        $this->assertStringContainsString('Working (1)', $out);
        $this->assertStringContainsString('Needs input (1)', $out);
        // Slot labels are what Alt+1/Alt+2 address.
        $this->assertStringContainsString('[1]', $out);
        $this->assertStringContainsString('[2]', $out);
        $this->assertStringContainsString('alpha', $out);
        $this->assertStringContainsString('stalled-one', $out);
    }

    public function testRenderNeverExceedsTheRowBudget(): void
    {
        $supervisor = new BackgroundSupervisor();
        for ($i = 1; $i <= 40; $i++) {
            $supervisor->addSession($this->session("s{$i}", "bg-{$i}"));
        }

        foreach ([6, 10, 24] as $rows) {
            $out = AgentDashboardPane::render(
                $this->app(new Chat(backgroundSupervisor: $supervisor)),
                100,
                $rows,
            );

            $this->assertLessThanOrEqual(
                $rows,
                substr_count($out, "\n") + 1,
                "dashboard overflowed its {$rows}-row budget",
            );
        }
    }

    public function testRenderPrintsAMoreTrailerWhenRowsAreClipped(): void
    {
        $supervisor = new BackgroundSupervisor();
        for ($i = 1; $i <= 20; $i++) {
            $supervisor->addSession($this->session("s{$i}", "bg-{$i}"));
        }

        $out = AgentDashboardPane::render($this->app(new Chat(backgroundSupervisor: $supervisor)), 100, 8);

        $this->assertStringContainsString('more', $out);
    }

    // ── peekOverlay() ────────────────────────────────────────────────────

    public function testPeekOverlayIsEmptyOutsidePeekMode(): void
    {
        $app = $this->app(new Chat(agentManager: $this->manager([$this->agent('alpha')])))
            ->withSelectedAgentIndex(0);

        $this->assertSame('', AgentDashboardPane::peekOverlay($app, 100, 20));
    }

    public function testPeekOverlayIsEmptyWhenTheSelectionNoLongerResolves(): void
    {
        $app = $this->app(new Chat(agentManager: $this->manager([$this->agent('alpha')])))
            ->withAgentViewMode(AgentViewMode::Peek)
            ->withSelectedAgentIndex(7);

        $this->assertSame('', AgentDashboardPane::peekOverlay($app, 100, 20));
    }

    public function testPeekOverlayShowsTheSelectedSessionsLatestOutput(): void
    {
        $supervisor = new BackgroundSupervisor();
        $supervisor->addSession($this->session('s1', 'porter', output: "older line\nWaiting on your answer"));

        $app = $this->app(new Chat(backgroundSupervisor: $supervisor))
            ->withAgentViewMode(AgentViewMode::Peek)
            ->withSelectedAgentIndex(0);

        $overlay = AgentDashboardPane::peekOverlay($app, 100, 20);

        $this->assertStringContainsString('porter', $overlay);
        $this->assertStringContainsString('Waiting on your answer', $overlay);
    }

    public function testRenderCompositesThePeekOverlayOverTheList(): void
    {
        $supervisor = new BackgroundSupervisor();
        $supervisor->addSession($this->session('s1', 'porter', output: 'Waiting on your answer'));

        $app = $this->app(new Chat(backgroundSupervisor: $supervisor))
            ->withAgentViewMode(AgentViewMode::Peek)
            ->withSelectedAgentIndex(0);

        $out = AgentDashboardPane::render($app, 100, 20);

        $this->assertStringContainsString('Waiting on your answer', $out);
        $this->assertLessThanOrEqual(20, substr_count($out, "\n") + 1);
    }

    // ── keyboard: Alt+1..9 and Space ─────────────────────────────────────

    private function key(string $rune, bool $alt = false): KeyMsg
    {
        return new KeyMsg(KeyType::Char, $rune, alt: $alt);
    }

    public function testAltDigitJumpsToThatSlot(): void
    {
        $app = $this->app(new Chat(agentManager: $this->manager([
            $this->agent('alpha'),
            $this->agent('beta'),
            $this->agent('gamma'),
        ])));

        $handled = (new KeyboardHandler())->handleKeyMsg($this->key('3', alt: true), $app);

        $this->assertNotNull($handled);
        $this->assertSame(2, $handled[0]->selectedAgentIndex);
    }

    public function testAltDigitForAnEmptySlotIsAClaimedNoOp(): void
    {
        $app = $this->app(new Chat(agentManager: $this->manager([$this->agent('alpha')])));

        $handled = (new KeyboardHandler())->handleKeyMsg($this->key('9', alt: true), $app);

        // Claimed (not null) so the digit cannot leak into the chat input
        // behind the dashboard, but the selection is untouched.
        $this->assertNotNull($handled);
        $this->assertSame(-1, $handled[0]->selectedAgentIndex);
    }

    public function testSpaceOpensThePeekOnTheSelectedAgent(): void
    {
        $app = $this->app(new Chat(agentManager: $this->manager([$this->agent('alpha')])))
            ->withSelectedAgentIndex(0);

        $handled = (new KeyboardHandler())->handleKeyMsg(new KeyMsg(KeyType::Space, ''), $app);

        $this->assertNotNull($handled);
        $this->assertSame(AgentViewMode::Peek, $handled[0]->agentViewMode);
    }

    public function testSpaceWithoutASelectionDoesNotOpenThePeek(): void
    {
        $app = $this->app(new Chat(agentManager: $this->manager([$this->agent('alpha')])));

        $handled = (new KeyboardHandler())->handleKeyMsg(new KeyMsg(KeyType::Space, ''), $app);

        $this->assertNotNull($handled);
        $this->assertSame(AgentViewMode::List, $handled[0]->agentViewMode);
    }

    // ── shell wiring: reachable from Tui\Renderer ────────────────────────

    public function testShellRendersTheDashboardFullPaneAndDropsTheSidebars(): void
    {
        $app = $this->app(new Chat(agentManager: $this->manager([$this->agent('alpha')])));

        $frame = ShellRenderer::renderView($app, 120, 30)->body;

        $this->assertStringContainsString('Working (1)', $frame);
        $this->assertStringContainsString('[1]', $frame);
        // FilesPane is the always-on left sidebar of the chat layout; the
        // full-pane dashboard replaces the whole band, so it must be gone.
        $this->assertStringNotContainsString('files', $frame);
        $this->assertLessThanOrEqual(30, substr_count($frame, "\n") + 1);
    }

    public function testShellStillRendersTheChatLayoutForOtherPanes(): void
    {
        $app = $this->app(new Chat(agentManager: $this->manager([$this->agent('alpha')])), Pane::Chat);

        $frame = ShellRenderer::renderView($app, 120, 30)->body;

        $this->assertStringContainsString('files', $frame);
        $this->assertStringNotContainsString('Working (1)', $frame);
    }

    public function testDashboardFrameClearsTheHostedChatsStaleClickZones(): void
    {
        $chat = new Chat(agentManager: $this->manager([$this->agent('alpha')]));

        // Render the chat layout first so the live renderer records zones and
        // a non-zero origin, then switch to the dashboard.
        ShellRenderer::renderView($this->app($chat, Pane::Chat), 120, 30);
        ShellRenderer::renderView($this->app($chat, Pane::Agents), 120, 30);

        $this->assertSame([0, 0], LiveRenderer::zoneOrigin());
        $this->assertNull(LiveRenderer::scanner()->hit(1, 1));
    }
}
