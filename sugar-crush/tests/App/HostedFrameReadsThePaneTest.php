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
     * passes Chat's Cmd straight up. Nothing in `src/` or `bin/` builds one,
     * which is the whole reason `Chat::selectPane()` answers pane clicks with
     * the keyboard's own entry points instead.
     *
     * This test reds the day someone wires it. That is the point: the
     * `selectPane()` docblock's ⚠️ paragraph becomes wrong on that same
     * commit and has to move with it.
     *
     * ## ⚠️ Why there are TWO assertions, and why the first one alone was a hole
     *
     * WHAT THIS USED TO DO: one regex,
     * `/\bnew\s+(\\[\w\\]+\\)?SelectPaneMsg\s*\(/`, over `src/*.php`.
     * The optional namespace group REQUIRES a leading backslash, so it sees
     * `new SelectPaneMsg(` and `new \Fully\Qualified\SelectPaneMsg(` and is
     * blind to `new App\SelectPaneMsg(` — the RELATIVE form, which is the one
     * `Chat.php` would use, since it sits in `SugarCraft\Crush` and imports no
     * `SelectPaneMsg`. Measured: adding
     * `Pane::Files => [$this, static fn () => new App\SelectPaneMsg(Pane::Files)]`
     * to `Chat::selectPane()`'s match left this test green while the seam was
     * genuinely wired — reflection on the mutated `selectPane('files')` really
     * did return a `SugarCraft\Crush\App\SelectPaneMsg`.
     *
     * WHAT IS TRUE NOW: the producer regex accepts any namespace prefix,
     * relative or absolute, and the scan covers `bin/` as well as `src/` — the
     * scope the entry in `docs/plans/crush_code_hardening_backlog.md` already
     * claimed.
     *
     * The `use … as` alias form is resolved rather than assumed away: a file
     * that imports the class under another name is scanned for `new <alias>(`
     * too. Measured — without that, `use …\SelectPaneMsg as PaneJump;` plus
     * `new PaneJump(Pane::Files)` in `Chat.php` survived BOTH assertions,
     * because `Chat.php` already mentions the symbol in a docblock and so was
     * already on the allowlist below.
     *
     * WHY THE SECOND ASSERTION STILL EARNS ITS PLACE: it catches the wiring
     * that lands in a file not on this list at all, whatever syntax it uses —
     * including the one form still outside the producer regex,
     * `$c = SelectPaneMsg::class; new $c(…)`. That form remains invisible in a
     * file already on the list. Stated rather than papered over: a textual
     * census has a floor, and this is where it is.
     */
    public function testNothingInSrcConstructsASelectPaneMsg(): void
    {
        $producers = [];
        $mentions = [];

        foreach ($this->productionFiles() as $relative => $path) {
            $body = (string) file_get_contents($path);
            if (!str_contains($body, 'SelectPaneMsg')) {
                continue;
            }

            $mentions[] = $relative;

            // Every name the class can be constructed under in this file: its
            // own, plus any `use … as` alias, which a regex looking only for
            // the class name would never see.
            $names = ['SelectPaneMsg'];
            if (preg_match('/\buse\s+[\\\\\w]*\bSelectPaneMsg\s+as\s+(\w+)\s*;/i', $body, $alias) === 1) {
                $names[] = $alias[1];
            }

            foreach ($names as $name) {
                // Any namespace prefix, relative or absolute, or none at all.
                if (preg_match('/\bnew\s+[\\\\\w]*\b' . preg_quote($name, '/') . '\s*\(/', $body) === 1) {
                    $producers[] = $relative;
                    break;
                }
            }
        }

        sort($producers);
        sort($mentions);

        $this->assertSame(
            [],
            $producers,
            'SelectPaneMsg now has a production producer. That is a fine thing to '
            . 'have built — but Chat::selectPane()\'s docblock still records it as a '
            . 'dormant seam, and this assertion exists to make the two move together.',
        );

        $this->assertSame(
            ['src/App/App.php', 'src/Chat.php'],
            $mentions,
            'The set of production files that so much as name SelectPaneMsg has '
            . 'changed. App.php declares it and answers it; Chat.php records it as a '
            . 'dormant seam in selectPane()\'s docblock. A third file — or an alias '
            . 'import the producer regex above cannot see — means the seam moved.',
        );
    }

    /**
     * Every shipped PHP file, keyed by its package-relative path.
     *
     * `bin/sugarcrush` has no extension and is the launcher the whole E76
     * correction turns on, so it is listed explicitly rather than discovered
     * by suffix.
     *
     * @return array<string, string> relative path => absolute path
     */
    private function productionFiles(): array
    {
        $root = \dirname(__DIR__, 2);
        $files = [];

        /** @var \SplFileInfo $file */
        foreach (
            new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS),
            ) as $file
        ) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $files['src/' . substr($file->getPathname(), strlen($root) + 5)] = $file->getPathname();
        }

        $files['bin/sugarcrush'] = $root . '/bin/sugarcrush';

        return $files;
    }

    private function hostedFrame(Pane $pane): string
    {
        $app = App::new($this->provider, 'test-model')
            ->withChat(new Chat())
            ->withPane($pane);

        return Ansi::strip(TuiRenderer::renderView($app, 120, 40)->body);
    }
}
