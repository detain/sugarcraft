<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui\Components;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tui\Components\AgentsPane;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Crush\Tui\Renderer as ShellRenderer;

/**
 * The sidebar's live-rows contract (replacing the old hardcoded stub): rows
 * come from AgentDashboardPane::entries(), so a Task-tool delegation mirrored
 * into the parent manager by a SubAgentActivity frame shows here with no
 * AgentsPane-specific knowledge — and "(no active agents)" survives only as
 * the honest empty state it was lying about before.
 *
 * The provider/agent/manager/app fixtures are byte-identical copies of the
 * AgentDashboardPaneTest helpers: same widget family, same fixtures, zero
 * drift surface.
 */
final class AgentsPaneLiveRowsTest extends TestCase
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

    private function app(?Chat $chat, Pane $pane = Pane::Agents): App
    {
        return App::new($this->provider, 'test-model')->withPane($pane)->withChat($chat);
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

    public function testAnIdleManagerStillRendersTheHonestEmptyState(): void
    {
        $out = AgentsPane::render($this->app(new Chat(agentManager: $this->manager([]))), 40, 12);

        $this->assertStringContainsString('(no active agents)', $out);
    }

    public function testAProjectedDelegationShowsAsALiveRowNotTheStub(): void
    {
        $manager = $this->manager([$this->agent('coder', isActive: false)]);
        $manager->projectRemoteSubAgent(self::beat(SubAgentActivity::OP_STARTED, 'cid-1', 'coder', 'Audit candy-core'));
        $manager->projectRemoteSubAgent(self::beat(SubAgentActivity::OP_PROGRESS, 'cid-1', 'coder', '', 2, '-> grep'));

        $out = AgentsPane::render($this->app(new Chat(agentManager: $manager)), 40, 20);

        $this->assertStringContainsString('coder', $out, 'the delegation the parent projected is on the sidebar');
        $this->assertStringNotContainsString('(no active agents)', $out, 'a working session must never claim emptiness');
    }

    public function testTheRowBudgetTruncatesWithAnExplicitOverflowTrailer(): void
    {
        $names = ['alpha', 'beta', 'gamma', 'delta', 'epsilon'];
        $agents = [];
        $manager = $this->manager([]);
        foreach ($names as $name) {
            $manager->register($this->agent($name, isActive: false));
            $agents[] = $name;
        }
        foreach ($agents as $i => $name) {
            $manager->projectRemoteSubAgent(self::beat(SubAgentActivity::OP_STARTED, 'cid-' . $i, $name, 'task', 1, 'running'));
        }

        // rows=4 → CHROME_ROWS=2 leaves a budget of 2 of the 5 entries.
        $out = AgentsPane::render($this->app(new Chat(agentManager: $manager)), 40, 4);

        $this->assertStringContainsString('alpha', $out);
        $this->assertStringContainsString('beta', $out);
        $this->assertStringNotContainsString('gamma', $out, 'over-budget rows do not silently displace the frame');
        $this->assertStringContainsString('+3 more', $out, 'the hidden count is stated, never dropped');
    }

    public function testANarrowSidebarTruncatesRowsWithoutFatal(): void
    {
        $manager = $this->manager([$this->agent('coder', isActive: false)]);
        $manager->projectRemoteSubAgent(self::beat(SubAgentActivity::OP_STARTED, 'cid-1', 'coder', 'a task long enough to overflow a twelve column sidebar without mercy'));

        $out = AgentsPane::render($this->app(new Chat(agentManager: $manager)), 12, 10);

        $this->assertStringContainsString('agents', $out, 'the frame title survives any content squeeze');
        $this->assertStringNotContainsString('(no active agents)', $out);
    }
}
