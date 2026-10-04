<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui\Components;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tests\Support\RosterAgent;
use SugarCraft\Crush\Tui\Components\AgentDashboardPane;
use SugarCraft\Crush\Tui\KeyboardHandler;
use SugarCraft\Crush\Tui\Pane;

/**
 * Roadmap P-B3: the dashboard lists INSTANCES — one row per delegated run,
 * keyed by its run id — so a batch of Task calls to one roster agent is a
 * row each, the `Alt+1…9` slots name concrete runs, and the live agents
 * strip can open the run it has focused on the row that is that run.
 */
final class AgentDashboardPerInstanceTest extends TestCase
{
    public function testEachRunOfOneRosterAgentIsItsOwnRowKeyedByItsRunId(): void
    {
        $app = $this->app();

        $entries = AgentDashboardPane::entries($app);

        $this->assertSame(['run-a', 'run-b', 'run-c'], array_map(static fn ($e): ?string => $e->key, $entries));
        $this->assertSame(['explore', 'explore', 'reviewer'], array_map(static fn ($e): string => $e->name, $entries));
    }

    public function testAJumpSlotSelectsThatRun(): void
    {
        $app = $this->app()->withPane(Pane::Agents);

        $result = (new KeyboardHandler())->handleKeyMsg(new KeyMsg(KeyType::Char, '2', alt: true), $app);

        $this->assertNotNull($result);
        $this->assertSame('run-b', AgentDashboardPane::entries($result[0])[$result[0]->selectedAgentIndex]->key);
    }

    public function testOpeningARunSelectsItsOwnRow(): void
    {
        $app = $this->app()->openAgent('run-c');

        $this->assertSame(Pane::Agents, $app->pane);
        $this->assertSame(2, $app->selectedAgentIndex);
    }

    private function app(): App
    {
        $provider = $this->createMock(ProviderInterface::class);
        $manager = new AgentManager($provider, new SkillRegistry());
        $manager->register(RosterAgent::named('explore'));
        $manager->register(RosterAgent::named('reviewer'));
        foreach ([['run-a', 'explore'], ['run-b', 'explore'], ['run-c', 'reviewer']] as $n => [$id, $name]) {
            $manager->projectRemoteSubAgent(new SubAgentActivity(
                SubAgentActivity::OP_STARTED,
                $id,
                $name,
                'task ' . $n,
                1,
                '',
                parentCallId: 'call_' . $n,
            ));
        }

        return App::new($provider, 'm')->withChat(new Chat(agentManager: $manager));
    }
}
