<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui\Components;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Tui\Components\SettingsPane;
use SugarCraft\Crush\Tui\Components\SkillsPane;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Crush\Tui\Renderer as ShellRenderer;

/**
 * Covers the settings sidebar: that it reports LIVE configuration rather than
 * invented defaults, that it is honestly read-only, and that the shell
 * renderer actually reaches it — Pane::Settings previously had no renderer at
 * all, which is the "the Settings pane is empty" report this closes.
 */
final class SettingsPaneTest extends TestCase
{
    private ProviderInterface $provider;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(ProviderInterface::class);
        $this->provider->method('name')->willReturn('sglang');
        ShellRenderer::resetSizeCache();
    }

    protected function tearDown(): void
    {
        ShellRenderer::resetSizeCache();
    }

    private function app(Pane $pane = Pane::Settings): App
    {
        return App::new($this->provider, 'MiniMax-M2.7')->withPane($pane);
    }

    /** Visible width of the widest line in a rendered block. */
    private function blockWidth(string $block): int
    {
        $widest = 0;
        foreach (explode("\n", $block) as $line) {
            $widest = max($widest, \SugarCraft\Core\Util\Width::string($line));
        }

        return $widest;
    }

    /** @return array<string, string> label => value */
    private function map(App $a): array
    {
        $out = [];
        foreach (SettingsPane::settings($a) as [$label, $value]) {
            $out[$label] = $value;
        }

        return $out;
    }

    // ── settings() reads live state ──────────────────────────────────────

    public function testSettingsReportTheProviderAndModelTheAppIsRunning(): void
    {
        $rows = $this->map($this->app());

        $this->assertSame('sglang', $rows['Provider']);
        $this->assertSame('MiniMax-M2.7', $rows['Model']);
    }

    public function testRootFallsBackToTheProcessWorkingDirectoryForAnUnrootedApp(): void
    {
        // This App carries no root (App::$root is null), which is the shape
        // every test and embedder that never names one has. The process
        // directory is the honest answer there.
        $this->assertSame(getcwd(), $this->map($this->app())['Root']);
    }

    /**
     * The `--root` case: a rooted App must report ITS root, not the process
     * directory. This pane read `getcwd()` unconditionally until App carried
     * a root to read back, so it agreed with the (then wrong) environment
     * block rather than with the tools (crush_code.md Phase 0 item 6).
     */
    public function testRootReportsTheAppsConfiguredRootWhenItDivergesFromTheProcessDirectory(): void
    {
        $root = sys_get_temp_dir() . '/settings_pane_root_' . uniqid('', true);
        $this->assertNotSame(getcwd(), $root);

        $rows = $this->map($this->app()->withRoot($root));

        $this->assertSame($root, $rows['Root']);
    }

    public function testThemeAndSessionAreExplicitPlaceholdersWithoutAHostedChat(): void
    {
        $rows = $this->map($this->app());

        // A default theme name here would read as "the app is on 'dark'" when
        // no Chat exists to have a theme at all.
        $this->assertSame('(none)', $rows['Theme']);
        $this->assertSame('(none)', $rows['Session']);
        $this->assertSame('(none)', $rows['Streaming']);
    }

    public function testThemeIsReadBackFromTheHostedChat(): void
    {
        $chat = (new Chat())->withThemeName('light');

        $this->assertSame('light', $this->map($this->app()->withChat($chat))['Theme']);
    }

    public function testSessionIdIsReadBackFromTheHostedChatWhenTheShellHasNone(): void
    {
        $chat = (new Chat())->withCurrentSessionId('sess-42');

        $this->assertSame('sess-42', $this->map($this->app()->withChat($chat))['Session']);
    }

    public function testShellSessionIdWinsOverTheHostedChats(): void
    {
        $chat = (new Chat())->withCurrentSessionId('sess-chat');
        $app = $this->app()->withChat($chat)->withSessionId('sess-shell');

        $this->assertSame('sess-shell', $this->map($app)['Session']);
    }

    public function testStreamingReflectsTheHostedChatsSwitch(): void
    {
        $on = $this->app()->withChat((new Chat())->withStreaming(true));
        $off = $this->app()->withChat((new Chat())->withStreaming(false));

        $this->assertSame('on', $this->map($on)['Streaming']);
        $this->assertSame('off', $this->map($off)['Streaming']);
    }

    public function testMouseTogglesFollowTheEnvironmentSwitchesChatEnforces(): void
    {
        $this->assertSame('cell_motion', $this->map($this->app())['Mouse']);
        $this->assertSame('on', $this->map($this->app())['Mouse clicks']);

        putenv('SUGARCRUSH_DISABLE_MOUSE_CLICKS=1');

        try {
            $rows = $this->map($this->app());
            $this->assertSame('cell_motion', $rows['Mouse']);
            $this->assertSame('off', $rows['Mouse clicks']);
        } finally {
            putenv('SUGARCRUSH_DISABLE_MOUSE_CLICKS');
        }
    }

    // ── render() ─────────────────────────────────────────────────────────

    public function testRenderShowsEveryLabelAndValue(): void
    {
        $plain = Ansi::strip(SettingsPane::render($this->app(), 60, 30));

        $this->assertStringContainsString('settings', $plain);
        foreach (['Provider', 'Model', 'Theme', 'Root', 'Session', 'Mouse clicks', 'Streaming'] as $label) {
            $this->assertStringContainsString($label, $plain);
        }
        $this->assertStringContainsString('sglang', $plain);
        $this->assertStringContainsString('MiniMax-M2.7', $plain);
    }

    /**
     * The pane offers no control it cannot honour, and names the commands
     * that do work instead.
     */
    public function testRenderSaysItIsReadOnlyAndNamesTheWorkingCommands(): void
    {
        $plain = Ansi::strip(SettingsPane::render($this->app(), 60, 30));

        $this->assertStringContainsString('read-only', $plain);
        $this->assertStringContainsString('/theme', $plain);
        $this->assertStringContainsString('/model', $plain);
    }

    public function testFocusedBorderIsGreenAndUnfocusedIsPink(): void
    {
        $this->assertStringContainsString(
            "\x1b[38;2;0;255;170m",
            SettingsPane::render($this->app(Pane::Settings), 40, 20),
        );
        $this->assertStringContainsString(
            "\x1b[38;2;255;102;170m",
            SettingsPane::render($this->app(Pane::Chat), 40, 20),
        );
    }

    public function testRenderNeverExceedsItsRowOrColumnBudget(): void
    {
        $output = SettingsPane::render($this->app(), 30, 9);
        $lines = explode("\n", $output);

        $this->assertLessThanOrEqual(9, count($lines));

        // Style::width() sizes the CONTENT box, so the border (2) and the
        // horizontal padding (2) sit outside it -- the same +4 every other
        // sidebar pane produces, which is what Tui\Renderer measures with
        // blockWidth() when it hands the chat pane the leftover columns.
        $sibling = SkillsPane::render($this->app(), 30, 9);
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(
                $this->blockWidth($sibling),
                \SugarCraft\Core\Util\Width::string($line),
            );
        }
    }

    /** A long value keeps both ends: the tail of a path is what identifies it. */
    public function testAnOverlongValueIsMiddleTruncated(): void
    {
        $chat = (new Chat())->withCurrentSessionId(str_repeat('abcdefghij', 8));
        $plain = Ansi::strip(SettingsPane::render($this->app()->withChat($chat), 24, 30));

        $this->assertStringContainsString('…', $plain);
        $this->assertStringContainsString('abcde', $plain);
    }

    // ── shell wiring ─────────────────────────────────────────────────────

    /**
     * The regression the step exists for: focusing Pane::Settings used to
     * produce a frame with no settings text anywhere, because
     * Tui\Renderer::rightSidebar() had no arm for it.
     */
    public function testTheShellFrameActuallyContainsTheSettingsPane(): void
    {
        $frame = Ansi::strip(ShellRenderer::render($this->app(), 120, 30));

        $this->assertStringContainsString('settings', $frame);
        $this->assertStringContainsString('Provider', $frame);
        $this->assertStringContainsString('read-only', $frame);
    }

    /** And the bar advertises it, so Tab and the strip agree. */
    public function testTheTabStripAdvertisesSettings(): void
    {
        $bar = Ansi::strip(\SugarCraft\Crush\Tui\Components\MenuBar::render($this->app(), 200));

        $this->assertStringContainsString('[Settings]', $bar);
    }
}
