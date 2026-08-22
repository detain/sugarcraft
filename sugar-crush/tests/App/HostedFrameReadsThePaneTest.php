<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Crush\Tui\Renderer as TuiRenderer;

/**
 * `App::$pane` is read by the frame a real launch paints.
 *
 * ## Why this file exists
 *
 * {@see \SugarCraft\Crush\Chat::selectPane()}'s docblock asserted for several
 * rounds that `App::$pane` "belongs to the `App`/`Tui\Renderer` system that
 * nothing constructs" and that jumping it "would be a switch the user can
 * never see". Both halves were false — `bin/sugarcrush` ends in
 * `new Program(Bootstrap::app(...))` and `App::view()` calls
 * {@see TuiRenderer::renderView()} — and a false justification is exactly the
 * kind a later reader acts on. Backlog E76.
 *
 * The docblock has been rewritten. This pins the part of it that is a
 * behavioural claim, so the correction cannot rot back:
 *
 *  - the HOSTED frame (`$a->chat !== null`, which is every real launch, since
 *    {@see \SugarCraft\Crush\Cli\Bootstrap::app()} always calls `withChat()`)
 *    still changes when the pane changes, in BOTH sidebars and in the
 *    full-pane divert, and
 *  - `App\SelectPaneMsg` — the one channel by which a Cmd could move the host's
 *    pane — has no producer in `src/`.
 *
 * ## What is deliberately NOT re-asserted here
 *
 * That `Tui\Renderer::statusBar()` is dead on the live path. It is dead —
 * `renderView()` sets `$bottom = ''` whenever a chat is hosted — but
 * {@see AppModelTest::testHostedFrameHasExactlyOneInputBoxAndOneStatusBar()}
 * already pins it from the absent `Switch Pane` side, and
 * {@see AppModelTest::testViewRendersTheShellChrome()} pins the un-hosted side
 * that makes the first one mean something. A second copy here would drift
 * against those two rather than reinforce them.
 */
final class HostedFrameReadsThePaneTest extends TestCase
{
    private ProviderInterface $provider;

    protected function setUp(): void
    {
        parent::setUp();
        TuiRenderer::resetSizeCache();
        $this->provider = $this->createMock(ProviderInterface::class);
        $this->provider->method('name')->willReturn('TestProvider');
    }

    protected function tearDown(): void
    {
        TuiRenderer::resetSizeCache();
        parent::tearDown();
    }

    /**
     * The LEFT sidebar reads it: {@see TuiRenderer::leftSidebar()} branches on
     * `Pane::Files` / `Pane::Tools`.
     *
     * Asserted on the border TITLE rather than on pane contents, because an
     * empty Files pane and an empty Tools pane can otherwise look alike.
     */
    public function testTheHostedFramesLeftSidebarFollowsThePane(): void
    {
        $files = $this->hostedFrame(Pane::Files);
        $tools = $this->hostedFrame(Pane::Tools);

        $this->assertStringContainsString('╭ files ', $files);
        $this->assertStringNotContainsString('╭ tools ', $files);

        $this->assertStringContainsString('╭ tools ', $tools);
        $this->assertStringNotContainsString('╭ files ', $tools);
    }

    /**
     * The RIGHT sidebar reads it too: {@see TuiRenderer::rightSidebar()}
     * returns `''` for most panes and a real block for `Pane::Skills`.
     *
     * ⚠️ Asserted on the `skills` border title, NOT on "the two frames differ".
     * The whole-frame comparison was written first and MEASURED USELESS: with
     * `rightSidebar()`'s `Pane::Skills` arm deleted the frames still differ,
     * because {@see \SugarCraft\Crush\Tui\Components\MenuBar::render()} prints
     * `Currently: <pane>` on line 0 for every pane there is. A frame-level
     * difference therefore proves only that the MENU BAR read the pane, which
     * is not the claim.
     */
    public function testTheHostedFramesRightSidebarFollowsThePane(): void
    {
        $files = $this->hostedFrame(Pane::Files);
        $skills = $this->hostedFrame(Pane::Skills);

        $this->assertStringContainsString('╭ files ', $skills, 'the left sidebar should be unchanged');
        $this->assertStringContainsString(
            '╭ skills ',
            $skills,
            'rightSidebar() is no longer painting a Skills block for Pane::Skills.',
        );
        $this->assertStringNotContainsString('╭ skills ', $files);
    }

    /**
     * And `renderView()` itself reads it, BEFORE any sidebar exists:
     * `Pane::Agents` is diverted to the full-width dashboard, so the sidebars
     * of the other panes are not merely different — they are gone.
     */
    public function testPaneAgentsDivertsTheHostedFrameToTheFullWidthDashboard(): void
    {
        $agents = $this->hostedFrame(Pane::Agents);

        $this->assertStringContainsString('╭ agents ', $agents);
        $this->assertStringNotContainsString('╭ files ', $agents);
        $this->assertStringNotContainsString('┌ chat ', $agents);
    }

    /**
     * The dormant seam, pinned as dormant.
     *
     * `App\SelectPaneMsg` is handled by {@see App::update()} and would reach
     * the host from a Cmd this Chat returned — {@see App::delegateToChat()}
     * passes Chat's Cmd straight up. Nothing in `src/` builds one, which is
     * the whole reason `Chat::selectPane()` answers pane clicks with the
     * keyboard's own entry points instead.
     *
     * This test reds the day someone wires it. That is the point: the
     * `selectPane()` docblock's ⚠️ paragraph becomes wrong on that same
     * commit and has to move with it.
     */
    public function testNothingInSrcConstructsASelectPaneMsg(): void
    {
        $src = \dirname(__DIR__, 2) . '/src';
        $found = [];

        /** @var \SplFileInfo $file */
        foreach (
            new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            ) as $file
        ) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $body = (string) file_get_contents($file->getPathname());
            if (preg_match('/\bnew\s+(\\\\[\w\\\\]+\\\\)?SelectPaneMsg\s*\(/', $body) === 1) {
                $found[] = substr($file->getPathname(), strlen($src) + 1);
            }
        }

        $this->assertSame(
            [],
            $found,
            'SelectPaneMsg now has a production producer. That is a fine thing to '
            . 'have built — but Chat::selectPane()\'s docblock still records it as a '
            . 'dormant seam, and this assertion exists to make the two move together.',
        );
    }

    private function hostedFrame(Pane $pane): string
    {
        $app = App::new($this->provider, 'test-model')
            ->withChat(new Chat())
            ->withPane($pane);

        return Ansi::strip(TuiRenderer::renderView($app, 120, 40)->body);
    }
}
