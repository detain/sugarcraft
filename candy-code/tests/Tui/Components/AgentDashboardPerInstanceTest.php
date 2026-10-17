<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui\Components;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Events\SubAgentActivity;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tests\Support\RosterAgent;
use SugarCraft\Code\Tui\Components\AgentDashboardPane;
use SugarCraft\Code\Tui\KeyboardHandler;
use SugarCraft\Code\Tui\Pane;

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

        // Roadmap P-C2: the run opens in the main area's Agent View, and the
        // dashboard's selection follows it to its own row.
        $this->assertSame(Pane::Chat, $app->pane);
        $this->assertSame('run-c', $app->agentViewTarget);
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
