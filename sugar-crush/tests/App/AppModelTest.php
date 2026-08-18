<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\BackgroundColorMsg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\RawMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Core\View;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\App\ErrorMsg;
use SugarCraft\Crush\App\OpenSkillPickerMsg;
use SugarCraft\Crush\App\SelectPaneMsg;
use SugarCraft\Crush\App\SelectSkillMsg;
use SugarCraft\Crush\App\StatusMsg;
use SugarCraft\Crush\App\ToolResultMsg;
use SugarCraft\Crush\App\UserInputMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tui\Commands\CommandPaletteCmd;
use SugarCraft\Crush\Tui\Commands\GroupInputCmd;
use SugarCraft\Crush\Tui\Commands\NewSessionCmd;
use SugarCraft\Crush\Tui\Commands\QuitAgentViewCmd;
use SugarCraft\Crush\Tui\Commands\StopAllAgentsCmd;
use SugarCraft\Crush\Tui\Components\MenuBar;
use SugarCraft\Crush\Tui\Components\MenuSelectedMsg;
use SugarCraft\Crush\Tui\Components\SkillsPane;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Crush\Tui\Renderer as TuiRenderer;
use SugarCraft\Crush\Tui\TerminalBackground;

/**
 * App as the pane shell's root Model, hosting a Chat
 * (crush_feat.md §5 E7, merge branch — W3.M1).
 *
 * @see App::init()
 * @see App::update()
 * @see App::view()
 * @see App::subscriptions()
 * @see App::withChat()
 * @see \SugarCraft\Crush\Tui\Components\ChatPane::render()
 */
final class AppModelTest extends TestCase
{
    private ProviderInterface $provider;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(ProviderInterface::class);
        $this->provider->method('name')->willReturn('stub');
        TuiRenderer::setSize(200, 60);
    }

    protected function tearDown(): void
    {
        TuiRenderer::resetSizeCache();
    }

    private function app(): App
    {
        return App::new($this->provider, 'test-model');
    }

    // =====================================================================
    // Model contract
    // =====================================================================

    public function testAppIsACoreModel(): void
    {
        $this->assertInstanceOf(Model::class, $this->app());
    }

    public function testChatDefaultsToNullAndIsThreadedThroughMutate(): void
    {
        $app = $this->app();
        $this->assertNull($app->chat);

        $chat = new Chat();
        $withChat = $app->withChat($chat);
        $this->assertSame($chat, $withChat->chat);
        $this->assertNull($app->chat, 'withChat() must not mutate the receiver');

        // The regression this guards: a field missing from mutate()'s
        // constructorProps map is silently dropped on the NEXT transition,
        // not on the wither itself.
        $afterOtherWither = $withChat->withPane(Pane::Skills)->withStatus('x')->withModel('m2');
        $this->assertSame($chat, $afterOtherWither->chat);
    }

    /**
     * init() no longer just forwards the hosted chat's Cmd: it batches the OSC
     * 11 background query in front of it (crush_code.md Phase 8 item 6), which
     * is what makes `TerminalBackground::observe()` reachable at all. The query
     * is unconditional — a shell with no hosted chat still asks.
     *
     * @see \SugarCraft\Crush\Tui\TerminalBackground
     */
    public function testInitAsksTheTerminalForItsBackgroundAndBatchesTheChatsOwnCmd(): void
    {
        $bare = $this->app()->init();
        $this->assertNotNull($bare, 'the shell now has a startup side effect of its own');

        $batch = $bare();
        $this->assertInstanceOf(BatchMsg::class, $batch);

        // Chat::init() is null today, and Cmd::batch() drops nulls, so the
        // query is the only member — asserting the count is what would catch a
        // null leaking through as a member and TypeError-ing scheduleCmd().
        $this->assertCount(1, $batch->cmds);

        $raw = ($batch->cmds[0])();
        $this->assertInstanceOf(RawMsg::class, $raw);
        $this->assertSame(Ansi::requestBackgroundColor(), $raw->bytes);
        $this->assertSame("\x1b]11;?\x07", $raw->bytes, 'OSC 11 query, BEL-terminated');
    }

    /**
     * The hosted chat's own startup Cmd is still run — it is batched, not
     * replaced. Chat::init() returns null today, so this drives a stub Model
     * whose init() is non-null to prove the batching rather than asserting on
     * a null that would pass either way.
     */
    public function testInitBatchesRatherThanReplacesAHostedChatsStartupCmd(): void
    {
        $chat = new Chat();
        $this->assertNull($chat->init(), 'guard: if Chat gains a startup Cmd, assert on it here');

        $batch = ($this->app()->withChat($chat)->init())();
        $this->assertInstanceOf(BatchMsg::class, $batch);
        $this->assertCount(1, $batch->cmds);
    }

    /**
     * The other half of the wiring: the reply App::init() asked for reaches
     * TerminalBackground through a real update() dispatch.
     *
     * Deliberately NOT named for the fall-through it also exercises — a
     * consuming arm (`return [$this, null]`) satisfies every assertion below
     * identically, because `Chat::update()` answers a message it does not claim
     * with exactly that pair. The fall-through is pinned separately by
     * {@see testTheBackgroundColorArmHandsTheMessageOnRatherThanConsumingIt()}.
     */
    public function testABackgroundColorReplyIsObservedByUpdate(): void
    {
        TerminalBackground::forget();

        try {
            $app = $this->app()->withChat(new Chat());

            [$next, $cmd] = $app->update(new BackgroundColorMsg(255, 255, 255));

            $this->assertFalse(
                TerminalBackground::observed(),
                'a white terminal is not dark, and update() is what recorded it',
            );
            $this->assertInstanceOf(App::class, $next);
            $this->assertNull($cmd);

            [, $cmd] = $app->update(new BackgroundColorMsg(0, 0, 0));
            $this->assertTrue(TerminalBackground::observed(), 'a later reply replaces the earlier one');
            $this->assertNull($cmd);
        } finally {
            // Process-scoped state in a shared-process suite: never leave it set.
            TerminalBackground::forget();
        }
    }

    /**
     * The arm hands the message on to the hosted chat instead of consuming it
     * — the claim {@see App::observeBackground()}'s docblock makes, and the one
     * property of that arm no behavioural test can reach.
     *
     * Structural rather than behavioural on purpose, and the reason is worth
     * writing down: {@see Chat} is `final`, so no recording stub can be hosted
     * in its place, and `Chat::update()` returns `[$this, null]` for every
     * message it does not claim — the same pair `observeBackground()` would
     * return if it swallowed the message, from the same `App` instance, with
     * the same null Cmd. Driving `update()` cannot distinguish them, which is
     * how a `return [$this, null];` mutation here passed all 155 tests in this
     * directory. Asserting on the method body is what is left, and it does fail
     * on that mutation.
     *
     * Replace this with the behavioural test it stands in for the moment `Chat`
     * grows a consumer for {@see BackgroundColorMsg} — at that point the
     * delegation has a real observable and this becomes redundant.
     */
    public function testTheBackgroundColorArmHandsTheMessageOnRatherThanConsumingIt(): void
    {
        $method = new \ReflectionMethod(App::class, 'observeBackground');
        $file = $method->getFileName();
        $this->assertIsString($file);

        $body = implode('', array_slice(
            file($file) ?: [],
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        $this->assertSame(
            1,
            preg_match_all('/\breturn\s+([^;]+);/', $body, $matches),
            'one exit only — a second one would be an unpinned path',
        );
        $this->assertSame(
            '$this->delegateToChat($msg)',
            trim($matches[1][0]),
            'the arm must return the delegation, not a pair of its own',
        );
        $this->assertStringContainsString(
            'TerminalBackground::observe($msg)',
            $body,
            'and it must still record the answer before delegating',
        );
    }

    /**
     * The point of asking at all: once the terminal has answered, `adaptive`
     * follows the answer instead of the environment. Pinned end-to-end from
     * App::update() rather than from TerminalBackground::observe() directly,
     * because the wiring is the part that was missing.
     */
    public function testAnObservedBackgroundSteersTheAdaptiveTheme(): void
    {
        // Theme::adaptive() reads the REAL environment (there is no seam to
        // inject one through view()), and an explicit override deliberately
        // outranks the observed answer — so on a machine that sets it, this
        // test is asserting the wrong precedence tier, not a regression.
        if (getenv(TerminalBackground::ENV_OVERRIDE) !== false) {
            $this->markTestSkipped(TerminalBackground::ENV_OVERRIDE . ' is set and outranks OSC 11 by design.');
        }

        TerminalBackground::forget();

        try {
            $app = $this->app();

            $app->update(new BackgroundColorMsg(0, 0, 0));
            $this->assertEquals(
                Theme::byName('dark')->markdown,
                Theme::adaptive()->markdown,
            );

            $app->update(new BackgroundColorMsg(255, 255, 255));
            $this->assertEquals(
                Theme::byName('light')->markdown,
                Theme::adaptive()->markdown,
            );
            $this->assertSame('adaptive', Theme::adaptive()->name, 'the name never collapses to the resolved side');
        } finally {
            TerminalBackground::forget();
        }
    }

    public function testSubscriptionsDelegateToHostedChat(): void
    {
        $this->assertNull($this->app()->subscriptions());

        $chat = new Chat();
        $this->assertSame(
            $chat->subscriptions(),
            $this->app()->withChat($chat)->subscriptions(),
        );
    }

    // =====================================================================
    // update(): shell messages first, everything else to Chat
    // =====================================================================

    public function testShellMessagesAreHandledBeforeDelegation(): void
    {
        $chat = new Chat();
        $app = $this->app()->withChat($chat);

        [$next, $cmd] = $app->update(new SelectPaneMsg(Pane::Agents));

        $this->assertInstanceOf(App::class, $next);
        $this->assertSame(Pane::Agents, $next->pane);
        $this->assertNull($cmd);
        // The chat must not have seen a shell message at all.
        $this->assertSame($chat, $next->chat);
    }

    public function testStatusMessageStillHandledByShell(): void
    {
        [$next] = $this->app()->withChat(new Chat())->update(new StatusMsg('working'));

        $this->assertSame('working', $next->status);
    }

    public function testUnknownMessageIsDelegatedToChatAndFoldedBack(): void
    {
        $chat = new Chat();
        $app = $this->app()->withChat($chat);

        [$next, $cmd] = $app->update(new KeyMsg(KeyType::Char, 'h'));

        $this->assertInstanceOf(App::class, $next);
        $this->assertNotSame($chat, $next->chat);
        $this->assertSame('h', $next->chat->inputBuf);
        $this->assertNull($cmd);
        // Everything else about the shell is untouched by delegation.
        $this->assertSame($app->pane, $next->pane);
        $this->assertSame($app->provider, $next->provider);
    }

    public function testDelegationPassesTheChatCmdStraightThrough(): void
    {
        $chat = new Chat([], 'hello');
        $app = $this->app()->withChat($chat);

        [$chatOnly, $chatCmd] = $chat->update(new KeyMsg(KeyType::Enter));
        [$next, $appCmd] = $app->update(new KeyMsg(KeyType::Enter));

        $this->assertNotNull($chatCmd, 'submitting a draft must produce a Cmd');
        $this->assertInstanceOf(\Closure::class, $appCmd);
        $this->assertSame($chatOnly->inputBuf, $next->chat->inputBuf);
        $this->assertSame($chatOnly->inFlight, $next->chat->inFlight);
    }

    public function testDelegationWithoutAHostedChatIsInert(): void
    {
        $app = $this->app();

        [$next, $cmd] = $app->update(new KeyMsg(KeyType::Char, 'h'));

        $this->assertSame($app, $next);
        $this->assertNull($cmd);
    }

    public function testNoOpChatUpdateKeepsTheSameAppInstance(): void
    {
        $chat = new Chat();
        $app = $this->app()->withChat($chat);

        // An anonymous Msg neither the shell nor Chat handles: Chat answers
        // with itself, so the App identity must not churn either.
        $msg = new class implements Msg {};
        [$next, $cmd] = $app->update($msg);

        $this->assertSame($app, $next);
        $this->assertNull($cmd);
    }

    // =====================================================================
    // view(): the shell frames the LIVE renderer's content
    // =====================================================================

    public function testViewRendersTheShellChrome(): void
    {
        $view = $this->app()->view();

        $this->assertInstanceOf(View::class, $view);
        $this->assertSame(TuiRenderer::render($this->app()), $view->body);
        $this->assertStringContainsString('Switch Pane', $view->body);
    }

    public function testChatPaneBodyComesFromTheLiveRenderer(): void
    {
        $chat = new Chat([Message::assistant('unmistakable-live-renderer-marker', 0)]);
        $frame = $this->frame($this->app()->withChat($chat));

        $this->assertStringContainsString('unmistakable-live-renderer-marker', $frame);

        // The discriminator against the OLD ChatPane, which built its own
        // "[ShortName] role: content" line: the live renderer wraps every
        // turn in a ROUNDED box (U+256D), a glyph the shell's own panes
        // (Border::normal(), U+250C) never emit. Against the pre-W3.M1
        // ChatPane this assertion fails outright.
        $this->assertStringContainsString("\u{256D}", $frame);
        $this->assertStringNotContainsString('[Message]', $frame);
    }

    public function testChatlessAppStillRendersItsOwnMessageList(): void
    {
        $frame = $this->frame($this->app());

        // No hosted chat -> the pane falls back to the App message list,
        // which the engine-state App (Runtime/EngineBackend) still populates.
        $this->assertStringContainsString('Welcome to SugarCrush!', $frame);
    }

    /**
     * The width half of the render invariant. It must be checked at the NARROW
     * sizes too: the menu bar is fixed-width chrome that only happens to fit a
     * 200-column terminal, so a single wide case cannot catch the overflow.
     *
     * @dataProvider terminalSizes
     */
    public function testShellFrameHasNoOverWideLines(int $cols, int $rows): void
    {
        $app = $this->sized($this->app()->withChat(new Chat()), $cols, $rows);

        foreach (explode("\n", $this->frame($app)) as $i => $line) {
            $this->assertLessThanOrEqual(
                $cols,
                Width::string($line),
                "shell frame line {$i} overflows the {$cols}x{$rows} terminal",
            );
        }
    }

    /** @dataProvider terminalSizes */
    public function testChatlessShellFrameHasNoOverWideLines(int $cols, int $rows): void
    {
        $app = $this->sized($this->app(), $cols, $rows);

        foreach (explode("\n", $this->frame($app)) as $i => $line) {
            $this->assertLessThanOrEqual(
                $cols,
                Width::string($line),
                "shell frame line {$i} overflows the {$cols}x{$rows} terminal",
            );
        }
    }

    /**
     * The hard render invariant: candy-core's Renderer repaints with an
     * ABSOLUTE cursorTo(), so anything past the last row is clamped onto it and
     * distinct logical rows collide. A hosted Chat renders a full-screen frame
     * of its own, which is exactly how the shell used to end up at rows+7.
     *
     * @dataProvider terminalSizes
     */
    public function testHostedShellFrameIsClippedToTheTerminalHeight(int $cols, int $rows): void
    {
        $app = $this->sized($this->app()->withChat(new Chat()), $cols, $rows);

        $this->assertLessThanOrEqual(
            $rows,
            substr_count($this->frame($app), "\n") + 1,
            "shell frame is taller than the {$cols}x{$rows} terminal",
        );
    }

    /** @dataProvider terminalSizes */
    public function testChatlessShellFrameIsClippedToTheTerminalHeight(int $cols, int $rows): void
    {
        $app = $this->sized($this->app(), $cols, $rows);

        $this->assertLessThanOrEqual(
            $rows,
            substr_count($this->frame($app), "\n") + 1,
            "shell frame is taller than the {$cols}x{$rows} terminal",
        );
    }

    /** @return array<string, array{0: int, 1: int}> */
    public static function terminalSizes(): array
    {
        return [
            '200x60' => [200, 60],
            '120x30' => [120, 30],
            '100x24' => [100, 24],
            '80x20' => [80, 20],
        ];
    }

    public function testWindowSizeIsRecordedOnTheShellAndTheHostedChat(): void
    {
        [$next] = $this->app()->withChat(new Chat())->update(new WindowSizeMsg(111, 41));

        $this->assertSame(41, $next->rows);
        $this->assertSame(111, $next->cols);
        // Both halves of the frame must agree, or the chrome and the content
        // lay out against two different terminals.
        $this->assertSame(41, $next->chat->rows());
        $this->assertSame(111, $next->chat->cols());
    }

    public function testViewSizesFromWindowSizeMsgNotTheCachedTerminalProbe(): void
    {
        // The static probe says 200x60; WindowSizeMsg says otherwise, and
        // WindowSizeMsg is the single source of truth.
        [$next] = $this->app()->withChat(new Chat())->update(new WindowSizeMsg(90, 22));

        $this->assertLessThanOrEqual(22, substr_count($this->frame($next), "\n") + 1);
    }

    public function testAMessageWiderThanTheOldPaneWidthSurvivesIntoTheFrame(): void
    {
        // 130 columns of content on a 200-column terminal. The pane is the
        // ~146 columns actually left after the sidebar, so this fits — but the
        // old pane hard-coded `max(40, cols - 80)` = 120 content columns and
        // truncated every line to it, so the tail vanished silently.
        $chat = new Chat([Message::assistant(str_repeat('w', 114) . ' TAIL-MARKER-XYZ', 0)]);

        $frame = $this->frame($this->app()->withChat($chat));

        $this->assertStringContainsString('TAIL-MARKER-XYZ', $frame);
    }

    public function testHostedChatIsSizedToThePaneNotTheTerminal(): void
    {
        $chat = new Chat();
        $app = $this->sized($this->app()->withChat($chat), 120, 30);

        // The pane's box has to close on the right: laying the chat out at the
        // full terminal width and then chopping it to the pane is what severed
        // the live renderer's rounded box mid-glyph.
        $frame = $this->frame($app);
        foreach (["\u{256D}", "\u{256E}", "\u{2570}", "\u{256F}"] as $corner) {
            $this->assertStringContainsString(
                $corner,
                $frame,
                'the hosted chat frame lost a box corner to pane truncation',
            );
        }
    }

    public function testHostedFrameHasExactlyOneInputBoxAndOneStatusBar(): void
    {
        $frame = $this->frame($this->app()->withChat(new Chat()));

        // The shell's placeholder input box and provider/model bar stand down
        // while the content model draws its own.
        $this->assertStringNotContainsString('Type your message...', $frame);
        $this->assertStringNotContainsString('Switch Pane', $frame);
        $this->assertStringContainsString('Ctrl+P', $frame);
    }

    public function testHostedShellStillSurfacesAShellLevelError(): void
    {
        $app = $this->app()->withChat(new Chat())->withError('engine exploded');

        $this->assertStringContainsString('engine exploded', $this->frame($app));
    }

    public function testEveryUpdateArmReturnsNullOrAClosure(): void
    {
        $chat = new Chat([], 'hello');
        $app = $this->app()->withChat($chat);

        $msgs = [
            new SelectPaneMsg(Pane::Agents),
            new StatusMsg('working'),
            new ErrorMsg('boom'),
            new UserInputMsg('hi'),
            new ToolResultMsg('call_1', 'out'),
            new OpenSkillPickerMsg(),
            new SelectSkillMsg('nope'),
            new WindowSizeMsg(120, 30),
            new KeyMsg(KeyType::Char, 'h'),
            new KeyMsg(KeyType::Enter),
        ];

        foreach ($msgs as $msg) {
            [, $cmd] = $app->update($msg);
            // candy-core's Program::scheduleCmd() is typed \Closure and is
            // called for every non-null Cmd - anything else TypeErrors and
            // kills the loop.
            $this->assertTrue(
                $cmd === null || $cmd instanceof \Closure,
                $msg::class . ' returned a Cmd the Program cannot schedule',
            );
        }
    }

    public function testHostedChatImagePlacementsRideOutOnTheView(): void
    {
        $chat = new Chat();
        $view = $this->app()->withChat($chat)->view();

        $this->assertInstanceOf(View::class, $view);
        // No images in this transcript, but the layer must be the hosted
        // chat's own - not silently discarded by the pane.
        $this->assertSame(Renderer::renderView($chat)->images, $view->images);
    }

    private function sized(App $app, int $cols, int $rows): App
    {
        [$next] = $app->update(new WindowSizeMsg($cols, $rows));

        return $next;
    }

    private function frame(App $app): string
    {
        $view = $app->view();

        return $view instanceof View ? $view->body : $view;
    }

    // =====================================================================
    // update(): shell keys first, every other key straight to Chat
    // (crush_feat.md section 5 E7, merge branch -- W3.M2)
    // =====================================================================

    public function testShellClaimedKeyNeverReachesTheHostedChat(): void
    {
        $chat = new Chat();
        $app = $this->app()->withChat($chat);

        [$next, $cmd] = $app->update(new KeyMsg(KeyType::Tab));

        $this->assertSame(Pane::Files, $next->pane);
        $this->assertNull($cmd);
        // Tab must not have been typed into, or otherwise seen by, the chat.
        $this->assertSame($chat, $next->chat);
        $this->assertSame('', $next->chat->inputBuf);
    }

    public function testUnclaimedKeyStillReachesTheHostedChat(): void
    {
        $app = $this->app()->withChat(new Chat());

        // 's' is a shell quick-action inside the agent view; in the chat pane
        // it is just a letter, and swallowing it here is exactly the failure
        // the fallthrough exists to prevent.
        [$next] = $app->update(new KeyMsg(KeyType::Char, 's'));

        $this->assertSame('s', $next->chat->inputBuf);
        $this->assertSame(Pane::Chat, $next->pane);
    }

    public function testChatOwnedChordBeatsTheShellBinding(): void
    {
        $app = $this->app()->withChat(new Chat());

        // Ctrl+P is KeyboardHandler's ProviderSelectCmd AND Chat's command
        // palette. Chat wins: the palette is the live, working binding.
        [$chatOnly] = $app->chat->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        [$next] = $app->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));

        $this->assertNotSame($app->chat, $next->chat, 'ctrl+p must have opened the palette');
        $this->assertEquals($chatOnly, $next->chat);
        $this->assertSame('', $next->chat->inputBuf, 'ctrl+p must not type a literal "p"');
        $this->assertSame(Pane::Chat, $next->pane);
    }

    public function testAgentViewKeysDriveTheShellNotTheChat(): void
    {
        $chat = new Chat();
        $app = $this->app()->withChat($chat)->withPane(Pane::Agents);

        [$next] = $app->update(new KeyMsg(KeyType::Char, 'q'));

        $this->assertSame(Pane::Chat, $next->pane);
        $this->assertSame($chat, $next->chat);
        $this->assertSame('', $next->chat->inputBuf);
    }

    public function testKeyRoutingWorksWithoutAHostedChat(): void
    {
        $app = $this->app();

        [$next, $cmd] = $app->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertSame($app, $next);
        $this->assertNull($cmd);

        [$shell] = $app->update(new KeyMsg(KeyType::Tab));
        $this->assertSame(Pane::Files, $shell->pane);
    }

    public function testDispatchKeyReportsTheShellCmdAndNullForUnclaimedKeys(): void
    {
        $app = $this->app();

        $handled = $app->dispatchKey(new KeyMsg(KeyType::Char, 'n', ctrl: true));
        $this->assertNotNull($handled);
        $this->assertInstanceOf(NewSessionCmd::class, $handled[1]);

        $quit = $app->withPane(Pane::Agents)->dispatchKey(new KeyMsg(KeyType::Char, 'q'));
        $this->assertNotNull($quit);
        $this->assertInstanceOf(QuitAgentViewCmd::class, $quit[1]);

        // Unclaimed: the caller must be able to tell this apart from a
        // claimed no-op, or it cannot know to fall through to Chat.
        $this->assertNull($app->dispatchKey(new KeyMsg(KeyType::Char, 'x')));
    }

    public function testShellKeyCmdIsNotForwardedToTheProgram(): void
    {
        // A KeyCmd is not a Closure(): ?Msg, so Program::scheduleCmd() would
        // TypeError on it -- update() must drop it. See App::handleKey().
        [, $cmd] = $this->app()->withChat(new Chat())->update(new KeyMsg(KeyType::Char, 'n', ctrl: true));

        $this->assertNull($cmd);
    }

    // =====================================================================
    // Consuming the shell Cmd objects (crush_feat.md section 5 E7 /
    // section 7 -- F.CMDCONSUME). Before this, App::handleKey() dropped
    // dispatchKey()'s element 1 on the floor, so Ctrl+S, menu Enter and the
    // skill picker all produced a command object nothing ever ran.
    // =====================================================================

    /** @return array{0: App, 1: \SugarCraft\Crush\Skills\Skill} */
    private function appWithOneUserInvocableSkill(): array
    {
        $skill = new Skill(
            name: 'audit',
            description: 'Audit the code',
            userInvocable: true,
            disableModelInvocation: false,
            allowedTools: null,
            disallowedTools: null,
            model: null,
            effort: 'medium',
            context: 'inline',
            paths: [],
            content: 'body',
            sourcePath: '',
        );
        $hidden = new Skill(
            name: 'internal',
            description: 'Not for humans',
            userInvocable: false,
            disableModelInvocation: false,
            allowedTools: null,
            disallowedTools: null,
            model: null,
            effort: 'medium',
            context: 'inline',
            paths: [],
            content: 'body',
            sourcePath: '',
        );

        $registry = new SkillRegistry();
        $registry->register(['audit' => $skill, 'internal' => $hidden]);

        return [$this->app()->withAvailableSkills($registry), $skill];
    }

    /**
     * The regression the step exists for: Ctrl+S used to produce a
     * SourceSkillCmd that handleKey() discarded, so the picker never opened.
     */
    public function testCtrlSOpensTheSkillPickerThroughUpdate(): void
    {
        [$app] = $this->appWithOneUserInvocableSkill();

        [$next, $cmd] = $app->update(new KeyMsg(KeyType::Char, 's', ctrl: true));

        $this->assertNull($cmd);
        $this->assertSame(Pane::Skills, $next->pane);
        $this->assertCount(1, $next->skillPickerOptions);
        $this->assertSame('audit', $next->skillPickerOptions[0]->name);
        $this->assertSame(0, $next->skillPickerIndex);
    }

    public function testOpenPickerMovesItsCursorAndWrapsBothWays(): void
    {
        [$app] = $this->appWithOneUserInvocableSkill();
        [$open] = $app->update(new KeyMsg(KeyType::Char, 's', ctrl: true));

        // One option, so both directions wrap back onto row 0 - the clamp in
        // withSkillPickerIndex() must not let it point past the last row.
        [$down] = $open->update(new KeyMsg(KeyType::Down));
        $this->assertSame(0, $down->skillPickerIndex);

        [$up] = $open->update(new KeyMsg(KeyType::Up));
        $this->assertSame(0, $up->skillPickerIndex);
    }

    /**
     * Enter on the picker enables the highlighted skill. This is the whole
     * "the skills pane cannot be selected from" report.
     */
    public function testEnterOnTheSkillPickerEnablesTheHighlightedSkill(): void
    {
        [$app, $skill] = $this->appWithOneUserInvocableSkill();
        [$open] = $app->update(new KeyMsg(KeyType::Char, 's', ctrl: true));

        [$next, $cmd] = $open->update(new KeyMsg(KeyType::Enter));

        $this->assertNull($cmd);
        $this->assertSame([$skill], $next->enabledSkills);
        $this->assertSame([], $next->skillPickerOptions);
        $this->assertSame("Enabled skill 'audit'.", $next->status);
    }

    public function testEscapeDismissesTheSkillPickerAndLeavesThePane(): void
    {
        [$app] = $this->appWithOneUserInvocableSkill();
        [$open] = $app->update(new KeyMsg(KeyType::Char, 's', ctrl: true));

        [$next] = $open->update(new KeyMsg(KeyType::Escape));

        $this->assertSame([], $next->skillPickerOptions);
        $this->assertSame(Pane::Chat, $next->pane);
    }

    /**
     * An open picker draws its cursor - a highlight the user can move is the
     * difference between a list and a picker.
     */
    public function testOpenPickerRendersACursorMarker(): void
    {
        [$app] = $this->appWithOneUserInvocableSkill();
        [$open] = $app->update(new KeyMsg(KeyType::Char, 's', ctrl: true));

        $this->assertStringContainsString('▸ audit', SkillsPane::render($open, 40, 10));
    }

    /**
     * Menu Enter used to emit MenuSelectedMsg('Session', '') - an item nobody
     * could dispatch. It now names the highlighted row and runs it through
     * Chat's own slash dispatch.
     */
    public function testMenuEnterDispatchesTheCommandItNames(): void
    {
        MenuBar::closeMenu();
        $app = $this->app()->withChat(new Chat());

        [$opened] = $app->update(new KeyMsg(KeyType::F10));
        $this->assertGreaterThan(0, MenuBar::getActiveMenu());

        // Row 0 of "Session" is /new, which is palette-only; move to a row
        // whose slash form Chat::submit() really dispatches.
        $items = MenuBar::getMenuItems('Session');
        $target = array_search('Switch session', $items, true);
        $this->assertIsInt($target);
        for ($i = 0; $i < $target; $i++) {
            [$opened] = $opened->update(new KeyMsg(KeyType::Down));
        }
        $this->assertSame($target, MenuBar::getActiveItem());

        [$next, $cmd] = $opened->update(new KeyMsg(KeyType::Enter));

        $this->assertTrue($cmd === null || $cmd instanceof \Closure);
        // Selecting closes the menu, and the command really ran: /sessions
        // appends its own response turn to the hosted chat.
        $this->assertSame(0, MenuBar::getActiveMenu());
        $this->assertNotNull($next->chat);
        $this->assertNotSame([], $next->chat->history);
        $this->assertSame('/sessions', $next->chat->history[0]->content);
        MenuBar::closeMenu();
    }

    public function testMenuSelectionWithoutAHostedChatReportsInsteadOfPretending(): void
    {
        [$next, $cmd] = $this->app()->consumeShellCmd(new MenuSelectedMsg('Session', 'Switch session'));

        $this->assertNull($cmd);
        $this->assertSame("No chat is hosted — 'sessions' was not dispatched.", $next->status);
    }

    public function testUnknownMenuItemIsAnErrorNotASilentNoOp(): void
    {
        [$next] = $this->app()->withChat(new Chat())->consumeShellCmd(new MenuSelectedMsg('Session', 'Nope'));

        $this->assertSame("No command matches menu item 'Nope'.", $next->error);
    }

    /**
     * Ctrl+K's CommandPaletteCmd is translated into the Ctrl+P keystroke Chat
     * already binds, rather than returned to a Program that cannot run it.
     */
    public function testCommandPaletteCmdOpensTheHostedChatsPalette(): void
    {
        [$next, $cmd] = $this->app()->withChat(new Chat())->consumeShellCmd(new CommandPaletteCmd());

        $this->assertNull($cmd);
        $this->assertNotNull($next->chat);
        $this->assertNotNull($next->chat->palette());
    }

    /**
     * A menu command must not be appended to a half-typed draft: the shell
     * backspaces the buffer empty before typing the command.
     */
    public function testDispatchingACommandClearsTheChatsDraftFirst(): void
    {
        $app = $this->app()->withChat(new Chat([], 'half written'));

        [$next] = $app->consumeShellCmd(new MenuSelectedMsg('Session', 'Switch session'));

        $this->assertNotNull($next->chat);
        $this->assertSame('', $next->chat->inputBuf);
        $this->assertSame(
            '/sessions',
            $this->lastUserMessage($next->chat),
            'the draft has to be GONE, not merely followed by the command',
        );
    }

    /**
     * The same, with the cursor parked in the middle of the draft.
     *
     * `clearInputKeys()` used to be `mb_strlen($inputBuf)` backspaces, which
     * was exactly right while the draft was an append-only string with no
     * cursor. Once the draft became a `candy-forms` TextArea
     * ({@see Chat::$input}), backspaces delete BEHIND the cursor, so this
     * draft kept its whole tail and the menu command was typed into the gap —
     * "half /sessionswritten". Half the clear is Deletes now, and only a
     * cursor that is not at the end can tell the two forms apart.
     */
    public function testDispatchingACommandClearsADraftTheCursorIsSittingInsideOf(): void
    {
        $chat = new Chat([], 'half written');
        foreach (array_fill(0, 8, new KeyMsg(KeyType::Left)) as $key) {
            [$chat] = $chat->update($key);
        }
        $this->assertSame(
            4,
            $chat->inputCursorOffset(),
            'fixture: eight characters of tail AHEAD of the cursor — which is the half a '
            . 'backspace-only clear leaves behind',
        );

        [$next] = $this->app()->withChat($chat)->consumeShellCmd(new MenuSelectedMsg('Session', 'Switch session'));

        $this->assertNotNull($next->chat);
        $this->assertSame('', $next->chat->inputBuf);
        $this->assertSame('/sessions', $this->lastUserMessage($next->chat));
    }

    /** The content of the newest Role::User message in $chat's history. */
    private function lastUserMessage(Chat $chat): ?string
    {
        for ($i = count($chat->history) - 1; $i >= 0; $i--) {
            if ($chat->history[$i]->role === \SugarCraft\Crush\Role::User) {
                return $chat->history[$i]->content;
            }
        }

        return null;
    }

    /**
     * The commands consumeShellCmd() deliberately leaves inert must stay
     * inert AND schedulable - no fabricated effect, no Cmd the loop chokes on.
     */
    public function testDeliberatelyInertCommandsAreNoOps(): void
    {
        $app = $this->app()->withChat(new Chat());

        foreach ([new GroupInputCmd(), new StopAllAgentsCmd(), new QuitAgentViewCmd()] as $inert) {
            [$next, $cmd] = $app->consumeShellCmd($inert);
            $this->assertNull($cmd);
            $this->assertSame($app, $next);
        }
    }
}
