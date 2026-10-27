<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\Agents\Live\AgentLiveRegistry;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Events\SubAgentActivity;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Theme;
use SugarCraft\Code\Tui\AgentActivityLine;
use SugarCraft\Code\Tui\AgentStatusBar;
use SugarCraft\Code\Tui\AgentStrip;
use SugarCraft\Code\Tui\AgentViewHeader;
use SugarCraft\Code\Tui\Components\AgentDashboardPane;
use SugarCraft\Code\Tui\Components\AgentOutputState;
use SugarCraft\Code\Tui\Pane;
use SugarCraft\Code\Tui\Renderer as ShellRenderer;
use SugarCraft\Code\Tui\Window\AgentColor;
use SugarCraft\Style\Style;

/**
 * P-AGENT.a: a preset's declared `color` paints four live surfaces — the
 * strip chip, the activity line, the Agent View header and the dashboard
 * row — through {@see AgentColor::resolve()} (this lane's own vocabulary
 * law: eight theme-token words or a contrast-passing `#rrggbb`, anything
 * else answers null and the status colour stands). The pins below compare
 * against fragments built the same way the surfaces build them, and every
 * unaccented render must stay byte-identical to the status-coloured one it
 * always was — the accent may only ADD paint, never move or restyle text.
 */
final class AgentColorPaintTest extends TestCase
{
    private Theme $theme;

    protected function setUp(): void
    {
        $this->theme = Theme::default();
        ShellRenderer::resetSizeCache();
    }

    protected function tearDown(): void
    {
        ShellRenderer::resetSizeCache();
    }

    public function testTheAccentDoorAnswersTheDeclaredColourOrTheStatusColourItWasGiven(): void
    {
        $status = $this->theme->assistantLabel;

        self::assertSame($status, AgentActivityLine::accent(null, $this->theme, $status), 'absent word keeps the status colour, identity and all');
        self::assertSame($status, AgentActivityLine::accent('', $this->theme, $status));
        self::assertSame($status, AgentActivityLine::accent('scarlet', $this->theme, $status), 'outside the vocabulary there is no accent');

        $orange = AgentActivityLine::accent('orange', $this->theme, $status);
        self::assertNotSame($status, $orange);
        self::assertSame($this->theme->userLabel?->toHex(), $orange->toHex(), 'the orange word means the theme userLabel token');
    }

    public function testTheStripPaintsADeclaredRunAndLeavesTheOthersOnTheirStatusColour(): void
    {
        $live = AgentLiveRegistry::new(static fn (): float => 1000.0);
        $live->withColorResolver(static fn (string $name): ?string => $name === 'explore' ? 'orange' : null);
        $live->apply(self::openFrame('run_a', 'explore'));
        $live->apply(self::openFrame('run_b', 'reviewer'));

        $accent = AgentColor::resolve('orange', $this->theme);
        self::assertNotNull($accent);
        $row = AgentStrip::render(AgentStrip::items($live), 120, $this->theme, 0);

        self::assertStringContainsString(Style::new()->foreground($accent)->render('⠋ explore'), $row, 'the declared chip wears the declared colour');
        self::assertStringContainsString(
            Style::new()->foreground($this->theme->assistantLabel)->render('⠋ reviewer'),
            $row,
            'the undeclared chip stays byte-identical to the status-coloured one it always was',
        );
    }

    public function testTheActivityLineTintsItsGlyphThroughTheSameDoor(): void
    {
        $live = AgentLiveRegistry::new(static fn (): float => 1000.0);
        $live->withColorResolver(static fn (string $name): ?string => $name === 'explore' ? 'orange' : 'scarlet');
        $live->apply(self::openFrame('run_a', 'explore'));
        $live->apply(self::openFrame('run_b', 'reviewer'));

        $accent = AgentColor::resolve('orange', $this->theme);
        $declared = AgentActivityLine::render($live->get('run_a') ?? throw new \RuntimeException('no state'), 100, $this->theme, 0, 1000.0);
        self::assertStringContainsString(Style::new()->foreground($accent)->render('⠋'), $declared);

        $illegible = AgentActivityLine::render($live->get('run_b') ?? throw new \RuntimeException('no state'), 100, $this->theme, 0, 1000.0);
        self::assertStringContainsString(
            Style::new()->foreground($this->theme->assistantLabel)->render('⠋'),
            $illegible,
            'an illegible word is no accent — the running glyph keeps the assistant label exactly as before',
        );
    }

    public function testTheHeaderPaintsItsNameBoldAndAccentedAndStaysPlainBoldOtherwise(): void
    {
        $accent = AgentColor::resolve('orange', $this->theme);

        $live = AgentLiveRegistry::new(static fn (): float => 1000.0);
        $live->withColorResolver(static fn (string $name): ?string => 'orange');
        $live->apply(self::openFrame('run_a', 'explore'));
        $tinted = AgentViewHeader::render('explore', $live->get('run_a'), [], 100, $this->theme, 0, 1000.0);
        self::assertStringContainsString(Style::new()->bold()->foreground($accent)->render('explore'), $tinted);

        $plain = AgentLiveRegistry::new(static fn (): float => 1000.0);
        $plain->withColorResolver(static fn (string $name): ?string => 'scarlet');
        $plain->apply(self::openFrame('run_b', 'explore'));
        $untinted = AgentViewHeader::render('explore', $plain->get('run_b'), [], 100, $this->theme, 0, 1000.0);
        self::assertStringContainsString(Style::new()->bold()->render('explore'), $untinted, 'illegible word: the byte-identical plain bold of the era before this step');

        $noState = AgentViewHeader::render('explore', null, [], 100, $this->theme, 0, 1000.0);
        self::assertStringContainsString(Style::new()->bold()->render('explore'), $noState);
    }

    public function testTheDashboardRowPaintsDotAndNameAndNothingElseWithTheAccent(): void
    {
        [$app, $entry, $accent] = $this->dashboardHosting('orange');
        self::assertNotNull($accent);
        $frame = AgentDashboardPane::render($app, 100, 20);

        self::assertStringContainsString(Style::new()->foreground($accent)->render("\u{25CF}"), $frame, 'the dot takes the accent');
        self::assertStringContainsString(Style::new()->bold()->foreground($accent)->render('alpha'), $frame, 'the name takes the accent under its usual bold');
        self::assertStringContainsString(
            Style::new()->foreground(AgentStatusBar::statusColor($entry->status, $this->theme))->render('[pending]'),
            $frame,
            'the status segment keeps its status colour',
        );
        self::assertStringNotContainsString(
            Style::new()->foreground(AgentStatusBar::statusColor($entry->status, $this->theme))->render("\u{25CF}"),
            $frame,
            'the status dot itself moved to the accent — the old dot byte is gone from this row',
        );
    }

    public function testTheUnaccentedDashboardRowIsTheStatusBarLineByteForByte(): void
    {
        [$app, $entry] = $this->dashboardHosting(null);
        $frame = AgentDashboardPane::render($app, 100, 20);

        self::assertStringContainsString(AgentStatusBar::renderAgentLine($entry, $this->theme), $frame, 'no live colour means the exact line AgentStatusBar always rendered');
    }

    public function testTheTintedRowCarriesTheSameVisibleTextAsThePlainOne(): void
    {
        [$accentedApp] = $this->dashboardHosting('orange');
        [$plainApp] = $this->dashboardHosting(null);

        self::assertSame(
            self::plainCells(AgentDashboardPane::render($plainApp, 100, 20)),
            self::plainCells(AgentDashboardPane::render($accentedApp, 100, 20)),
            'the accent is paint only — not one visible character moved',
        );
    }

    /**
     * @return array{0:App, 1:AgentOutputState, 2:?\SugarCraft\Core\Util\Color}
     */
    private function dashboardHosting(?string $declaredColor): array
    {
        $provider = $this->createMock(ProviderInterface::class);
        $manager = new AgentManager($provider, new SkillRegistry());
        $manager->register(new Agent(
            name: 'alpha',
            description: 'A',
            prompt: 'P',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: true,
        ));
        $run = $manager->createSubAgent('alpha', 'do the thing');

        $chat = new Chat(agentManager: $manager);
        $live = $chat->agentLive();
        if ($declaredColor !== null) {
            $live->withColorResolver(static fn (string $name): ?string => $name === 'alpha' ? $declaredColor : null);
        }
        $live->apply(new SubAgentActivity('started', $run->id, 'alpha', 'do the thing', 1, '', parentCallId: 'tc_1'));

        $app = App::new($provider, 'test-model')->withPane(Pane::Agents)->withChat($chat);

        return [$app, AgentDashboardPane::entries($app)[0], AgentColor::resolve($declaredColor, $this->theme)];
    }

    private static function openFrame(string $id, string $name): SubAgentActivity
    {
        return new SubAgentActivity('started', $id, $name, 'a task', 1, '', parentCallId: 'tc_' . $id);
    }

    private static function plainCells(string $frame): string
    {
        return (string) preg_replace('/\e\[[0-9;]*m/', '', $frame);
    }
}
