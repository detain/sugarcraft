<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Crush\Tui\Renderer as ShellRenderer;

/**
 * CL-2 FIX 2 — one agent listing per frame. The under-input transcript
 * footer ({@see \SugarCraft\Crush\Renderer::renderAgentView()}) and the
 * docked Agents sidebar are the same listing in two places; the shell now
 * tells the transcript renderer, per paint, that the sidebar owns the frame
 * and the footer stands down. Undocked the footer remains the sole display.
 *
 * The fixture family carries deliberately distinct helper names from the
 * AgentsPaneLiveRowsTest twins (DuplicatedTestHelperDrift discipline).
 */
final class AgentsPaneDockedFooterTest extends TestCase
{
    private const LISTING_MARK = 'Zebra checkmark probe';

    private ProviderInterface $footerProvider;

    protected function setUp(): void
    {
        $this->footerProvider = $this->createMock(ProviderInterface::class);
        ShellRenderer::resetSizeCache();
    }

    protected function tearDown(): void
    {
        ShellRenderer::resetSizeCache();
    }

    private function rosterMember(string $name): Agent
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
            isActive: false,
        );
    }

    private function managerListingOneRun(): AgentManager
    {
        $manager = new AgentManager($this->footerProvider, new SkillRegistry());
        $manager->register($this->rosterMember('reviewer'));
        $manager->projectRemoteSubAgent(new SubAgentActivity(
            SubAgentActivity::OP_STARTED,
            'rid-zebra',
            'reviewer',
            self::LISTING_MARK,
            1,
            '',
        ));

        return $manager;
    }

    private function sizedApp(bool $agentsDocked): App
    {
        $app = App::new($this->footerProvider, 'test-model')
            ->withChat(new Chat(agentManager: $this->managerListingOneRun()))
            ->update(new WindowSizeMsg(200, 60))[0];

        return $agentsDocked ? $app->togglePaneDocking(Pane::Agents) : $app;
    }

    private function frameText(App $app): string
    {
        $view = $app->view();

        return (string) preg_replace(
            '/\x{E000}[^\x{E001}]*\x{E001}|[\x{E000}-\x{F8FF}]/u',
            '',
            Ansi::strip(\is_string($view) ? $view : $view->body),
        );
    }

    public function testTheDockedSidebarIsTheFramesOnlyAgentListing(): void
    {
        $frame = $this->frameText($this->sizedApp(agentsDocked: true));

        $this->assertStringContainsString(self::LISTING_MARK, $frame, 'the docked sidebar still lists the run');
        $this->assertSame(
            1,
            substr_count($frame, self::LISTING_MARK),
            'the transcript footer stands down while the Agents pane is docked',
        );
    }

    public function testTheFooterRemainsTheSoleListingWhileThePaneIsUndocked(): void
    {
        $frame = $this->frameText($this->sizedApp(agentsDocked: false));

        $this->assertStringContainsString(self::LISTING_MARK, $frame, 'focus on Chat with no sidebar: the footer paints');
        // The undocked footer block lists the run on its status row and its
        // dashboard row — two rows of ONE listing, and no sidebar column.
        // The docked/undocked 1-vs-2 contrast is the suppression proof.
        $this->assertSame(
            2,
            substr_count($frame, self::LISTING_MARK),
            'both hits are the footer block itself; no sidebar adds a third',
        );
    }

    public function testTheDockedFlagLivesExactlyAsLongAsThePaint(): void
    {
        $app = $this->sizedApp(agentsDocked: true);
        $this->frameText($app);

        // A standalone hosted render after a hosted frame must not inherit a
        // stale suppression — the finally-reset is the whole safety story.
        $standalone = \SugarCraft\Crush\Renderer::render($app->chat);
        $this->assertStringContainsString(
            self::LISTING_MARK,
            Ansi::strip($standalone),
            'the footer returns for non-composited renders',
        );
    }
}
