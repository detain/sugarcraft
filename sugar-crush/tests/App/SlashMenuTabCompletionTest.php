<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\Tui\Components\MenuBar;
use SugarCraft\Crush\Tui\KeyboardHandler;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Crush\Tui\Renderer as TuiRenderer;
use SugarCraft\Crush\Tui\SessionPicker;

/**
 * Bare Tab completes the "/" popup's highlighted command (W4, user report:
 * "hitting tab while typing a partial /command ... should expand your typed
 * command to the full command currently highlighted ... currently it switches
 * your active other window").
 *
 * Every test here drives keystrokes through {@see App::update()} — the SHELL —
 * and never calls Chat's Tab arm directly. That is the whole point: the defect
 * was a PRECEDENCE one. Chat's arm can be perfect and still never run, because
 * {@see KeyboardHandler} claimed unmodified Tab unconditionally for pane
 * cycling and returned before the key could fall through. A test that fed the
 * Tab straight to {@see Chat::update()} would pass on the broken build.
 *
 * @see KeyboardHandler::claims()
 * @see Chat::slashMenuMatches()
 */
final class SlashMenuTabCompletionTest extends TestCase
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
        // MenuBar's active menu is process-global static state, so a test that
        // opens it here would leak into every later test in the run.
        MenuBar::closeMenu();
    }

    private function shell(): App
    {
        return App::new($this->provider, 'test-model')->withChat(new Chat());
    }

    /**
     * Feed keystrokes to the shell root exactly as the Program would, one
     * update() per key.
     *
     * @param list<KeyMsg> $keys
     */
    private function press(App $app, array $keys): App
    {
        foreach ($keys as $key) {
            [$next] = $app->update($key);
            $this->assertInstanceOf(App::class, $next);
            $app = $next;
        }

        return $app;
    }

    /** The keystrokes that type $text, one printable rune at a time. */
    private function type(string $text): array
    {
        return array_map(
            static fn (string $char): KeyMsg => new KeyMsg(KeyType::Char, $char),
            str_split($text),
        );
    }

    private static function tab(): KeyMsg
    {
        return new KeyMsg(KeyType::Tab);
    }

    // =====================================================================
    // The reported bug
    // =====================================================================

    /**
     * The user's exact sequence: a partial "/comp" showing one match, then Tab.
     */
    public function testTabExpandsAPartialSlashCommandThroughTheShell(): void
    {
        $app = $this->press($this->shell(), $this->type('/comp'));

        // Precondition, or the assertion below would prove nothing: the popup
        // really is showing, and the draft really is still the partial name.
        $this->assertSame(['compact'], array_map(
            static fn (object $spec): string => $spec->name,
            $app->chat->slashMenuMatches(),
        ));
        $this->assertSame('/comp', $app->chat->inputBuf);

        $app = $this->press($app, [self::tab()]);

        $this->assertSame('/compact ', $app->chat->inputBuf);
        // ... and the pane did NOT cycle, which is what it used to do instead.
        $this->assertSame(Pane::Chat, $app->pane);
    }

    /**
     * The completion is the HIGHLIGHTED row, not the first one — "/c" matches
     * two commands, and one Down moves the highlight onto the second.
     */
    public function testTabCompletesTheHighlightedMatchNotTheFirst(): void
    {
        $app = $this->press($this->shell(), $this->type('/c'));

        $names = array_map(
            static fn (object $spec): string => $spec->name,
            $app->chat->slashMenuMatches(),
        );
        $this->assertGreaterThan(1, count($names), 'need an ambiguous prefix for this test');
        $this->assertSame('clear', $names[0]);
        $this->assertSame(0, $app->chat->slashMenuIndex());

        $app = $this->press($app, [new KeyMsg(KeyType::Down)]);
        $this->assertSame(1, $app->chat->slashMenuIndex());

        $app = $this->press($app, [self::tab()]);

        $this->assertSame('/' . $names[1] . ' ', $app->chat->inputBuf);
        $this->assertNotSame('/' . $names[0] . ' ', $app->chat->inputBuf);
    }

    /**
     * The trailing space is the documented choice, and it is load-bearing:
     * it is what closes the popup, so the NEXT Tab is a pane cycle again.
     */
    public function testTheCompletedDraftClosesThePopupSoTheNextTabCyclesPanes(): void
    {
        $app = $this->press($this->shell(), [...$this->type('/comp'), self::tab()]);

        $this->assertSame([], $app->chat->slashMenuMatches());

        $app = $this->press($app, [self::tab()]);

        $this->assertSame(Pane::Chat->next(), $app->pane);
        $this->assertSame('/compact ', $app->chat->inputBuf, 'Tab must never type into the draft');
    }

    // =====================================================================
    // The negative the user explicitly called "fine normally"
    // =====================================================================

    /** No popup showing: bare Tab still cycles panes, from an empty draft. */
    public function testTabStillCyclesPanesWithNoSlashMenuOpen(): void
    {
        $app = $this->press($this->shell(), [self::tab()]);

        $this->assertSame(Pane::Chat->next(), $app->pane);
        $this->assertSame('', $app->chat->inputBuf);
    }

    /**
     * A draft that is NOT slash-prefixed, and a slash-prefixed one that has
     * already passed a space (arguments being typed, popup gone): both are
     * ordinary pane cycles, and neither gains a literal tab byte.
     */
    public function testTabCyclesPanesForDraftsThePopupDoesNotClaim(): void
    {
        // '/zzzz' is the one that matters most: it is slash-prefixed and
        // space-free, so slashMenuPrefix() is NON-null and only the MATCH
        // list is empty. A predicate weakened from "has matches" to "has a
        // prefix" still passes the other two drafts.
        foreach (['hello', '/compact now', '/zzzz'] as $draft) {
            $app = $this->press($this->shell(), $this->type($draft));
            $this->assertSame([], $app->chat->slashMenuMatches(), $draft);

            $app = $this->press($app, [self::tab()]);

            $this->assertSame(Pane::Chat->next(), $app->pane, $draft);
            $this->assertSame($draft, $app->chat->inputBuf, $draft);
        }
    }

    /**
     * Modified Tabs are untouched by this change. Ctrl+Tab is Chat's session
     * cycling and was already excluded from the shell's claim; Alt/Shift+Tab
     * are claimed by neither half, so they fall through to Chat's default
     * no-op — and in NO case does a Tab put a "\t" in the draft, which
     * {@see \SugarCraft\Crush\Tests\KeyHelpTest} depends on in both directions.
     */
    public function testModifiedTabsNeitherCompleteNorTypeATabCharacter(): void
    {
        $modified = [
            'ctrl' => new KeyMsg(KeyType::Tab, ctrl: true),
            'alt' => new KeyMsg(KeyType::Tab, alt: true),
            'shift' => new KeyMsg(KeyType::Tab, shift: true),
        ];

        foreach ($modified as $label => $key) {
            $app = $this->press($this->press($this->shell(), $this->type('/comp')), [$key]);

            $this->assertSame('/comp', $app->chat->inputBuf, $label);
        }
    }

    /**
     * Chat's own arm ignores a bare Tab with no popup showing.
     *
     * Read directly rather than through the shell, and honestly so: the shell
     * claims Tab in exactly that state, and `bin/sugarcrush`'s root Model is
     * always {@see App}, so no live keystroke reaches this guard. It is not
     * decoration either — drop the `slashMenuMatches() !== []` conjunct from
     * the arm and this call indexes an EMPTY match list at -1 inside
     * `completeSlashMenuSelection()` instead of doing nothing.
     */
    public function testChatsOwnArmIgnoresBareTabWithNoPopupShowing(): void
    {
        [$next] = (new Chat())->update(self::tab());

        $this->assertInstanceOf(Chat::class, $next);
        $this->assertSame('', $next->inputBuf);
    }

    // =====================================================================
    // The predicate itself — read directly, because routing tests cannot see
    // a shell that yields on a condition Chat does not answer.
    // =====================================================================

    /**
     * The claim is dropped for exactly the state Chat's arm answers, and only
     * that state.
     *
     * @see KeyboardHandler::claims()
     */
    public function testTheShellYieldsBareTabOnlyWhileThePopupIsShowing(): void
    {
        $handler = new KeyboardHandler();

        $showing = $this->press($this->shell(), $this->type('/comp'));
        $this->assertNull(
            $handler->handleKeyMsg(self::tab(), $showing),
            'the shell must let Tab fall through to the popup',
        );

        $this->assertNotNull(
            $handler->handleKeyMsg(self::tab(), $this->shell()),
            'with no popup the shell still owns Tab',
        );

        // No chat hosted at all -- the pane shell is drivable without one.
        $this->assertNotNull(
            $handler->handleKeyMsg(self::tab(), App::new($this->provider, 'test-model')),
        );
    }

    // =====================================================================
    // The shell may only yield a key Chat actually BINDS -- the reachability
    // half of the predicate, which the first version of this change got
    // wrong: it matched Chat's ARM and not Chat's early returns, so with a
    // modal up the shell let Tab go and nobody took it.
    // =====================================================================

    /**
     * A Chat modal that returns from update() before the Tab arm must leave
     * Tab as the shell's pane cycle, not as a dead keystroke.
     *
     * Each state below is one of {@see Chat::update()}'s early returns that
     * swallows an unclaimed Tab, with a "/" draft behind it -- the popup's
     * data source is inputBuf alone, so `slashMenuMatches()` is still
     * non-empty in every one of them and a shell keyed on THAT yields.
     * Measured on the broken build: `/comp`, Ctrl+P, Tab left pane=chat,
     * inputBuf='/comp' and the palette open -- nothing happened at all,
     * where pre-W4 that Tab cycled the pane.
     */
    public function testTabIsNotADeadKeyWhileAChatModalOwnsTheKeyboard(): void
    {
        $palette = $this->press(
            $this->press($this->shell(), $this->type('/comp')),
            [new KeyMsg(KeyType::Char, 'p', ctrl: true)],
        );
        $this->assertNotNull($palette->chat->palette(), 'fixture: the palette must be open');

        $picker = $this->shell()->withChat(new Chat(
            inputBuf: '/comp',
            sessionPicker: SessionPicker::new([]),
        ));
        $reference = $this->shell()->withChat(new Chat(inputBuf: '/comp', keyHelp: 0));

        foreach (['palette' => $palette, 'sessionPicker' => $picker, 'keyHelp' => $reference] as $label => $app) {
            $this->assertNotSame([], $app->chat->slashMenuMatches(), "{$label}: fixture, the popup's data is there");
            $this->assertFalse($app->chat->slashMenuOwnsTab(), "{$label}: but Chat will not act on the Tab");

            $next = $this->press($app, [self::tab()]);

            $this->assertSame(Pane::Chat->next(), $next->pane, "{$label}: Tab was a dead key");
            $this->assertSame('/comp', $next->chat->inputBuf, "{$label}: and it must not have completed");
        }

        // The modal itself survived the Tab in the one case that is reachable
        // from real input -- Tab is a pane cycle here, not a dismiss.
        $this->assertNotNull($this->press($palette, [self::tab()])->chat->palette());
    }

    /**
     * Same rule for the blocking permission prompt, which is the modal a
     * mid-turn user is likeliest to have up.
     *
     * The prompt is raised through the REAL PreToolUse gate rather than by
     * constructing a {@see \SugarCraft\Crush\PermissionRequestMsg} here,
     * deliberately: `Renderer\KeyHelpTest::testTheGuardMutationDomainIsThe
     * FilesThatBuildAPermissionRequestMsg()` pins the set of files that
     * construct one at exactly four, because that set is the domain
     * `Chat::requestPermission()`'s mutation table was measured over. This
     * file constructing one would silently widen it.
     */
    public function testTabIsNotADeadKeyWhileAPermissionPromptIsUp(): void
    {
        $blocked = $this->shell()->withChat($this->chatWithAPromptUpBehindASlashDraft());

        $this->assertNotSame([], $blocked->chat->slashMenuMatches(), 'fixture: the popup data is there');
        $this->assertFalse($blocked->chat->slashMenuOwnsTab());

        $next = $this->press($blocked, [self::tab()]);

        $this->assertSame(Pane::Chat->next(), $next->pane, 'Tab was a dead key with the prompt up');
        $this->assertSame('/comp', $next->chat->inputBuf);
        $this->assertNotNull($next->chat->pendingPermission(), 'and the prompt is still waiting');
    }

    /**
     * The mid-turn case with NO modal, which is the other half of that
     * measurement and must still complete: {@see Chat::refuseWhileInFlight()}
     * has no bare-Tab arm, so an in-flight turn does not block the popup.
     */
    public function testTabStillCompletesMidTurn(): void
    {
        $chat = (new Chat(inputBuf: '', backend: new EchoBackend()))->withSize(100, 30);
        foreach ([...$this->type('hi'), new KeyMsg(KeyType::Enter)] as $key) {
            [$chat] = $chat->update($key);
        }
        $this->assertTrue($chat->inFlight, 'fixture: a turn must be running');

        $app = $this->press($this->shell()->withChat($chat), [...$this->type('/comp'), self::tab()]);

        $this->assertSame('/compact ', $app->chat->inputBuf);
        $this->assertSame(Pane::Chat, $app->pane);
    }

    // =====================================================================
    // Precedence against the shell's OWN keyboard-owning views
    // =====================================================================

    /**
     * The Tab rule sits AFTER {@see KeyboardHandler}'s shellOwnsKeyboard()
     * check on purpose, and that ordering is real behaviour: with the F10
     * menu, the Agents dashboard or the skill picker up, the popup is buried
     * and undrivable, so Tab stays the shell's pane cycle even though the
     * draft would otherwise complete. Moving the block above that check
     * changes all three and, until this test, changed nothing observable.
     */
    public function testTheShellKeepsTabWhileOneOfItsOwnViewsOwnsTheKeyboard(): void
    {
        $typed = $this->press($this->shell(), $this->type('/comp'));
        $this->assertTrue($typed->chat->slashMenuOwnsTab(), 'fixture: Chat would otherwise take this Tab');

        $states = [
            'agents dashboard' => static fn (App $a): App => $a->withPane(Pane::Agents),
            'skill picker' => static fn (App $a): App => $a
                ->withPane(Pane::Skills)
                ->withSkillPickerOptions(['one', 'two']),
            'F10 menu' => static function (App $a): App {
                MenuBar::openMenu(1);

                return $a;
            },
        ];

        foreach ($states as $label => $raise) {
            MenuBar::closeMenu();
            $app = $raise($typed);
            $handler = new KeyboardHandler();

            $this->assertNotNull(
                $handler->handleKeyMsg(self::tab(), $app),
                "{$label}: the shell must keep Tab while its own view owns the keyboard",
            );

            $next = $this->press($app, [self::tab()]);

            $this->assertSame($app->pane->next(), $next->pane, "{$label}: Tab must still cycle panes");
            $this->assertSame('/comp', $next->chat->inputBuf, "{$label}: and must not complete");
        }

        MenuBar::closeMenu();
    }

    /**
     * The deliberate opposite: from a SIDEBAR pane the completion wins.
     *
     * `Pane::Files`/`Pane::Tools` render a quarter-width sidebar beside the
     * chat ({@see \SugarCraft\Crush\Tui\Renderer::leftSidebar()}), the popup
     * is still on screen, and typing still reaches inputBuf -- so Tab
     * completes rather than cycling, even though Tab is the only pane-nav key
     * there. Recorded as a decision, not an accident.
     */
    public function testTabCompletesFromASidebarPaneToo(): void
    {
        $app = $this->press($this->shell(), $this->type('/comp'))->withPane(Pane::Files);

        $next = $this->press($app, [self::tab()]);

        $this->assertSame('/compact ', $next->chat->inputBuf);
        $this->assertSame(Pane::Files, $next->pane, 'the completion consumed the Tab');

        // ... and once the popup is closed the same pane cycles again.
        $this->assertSame(Pane::Files->next(), $this->press($next, [self::tab()])->pane);
    }

    /**
     * Shift+Tab is the shell's, in every state, and cycles panes BACKWARD.
     *
     * **This assertion was inverted, deliberately, and the reason it flipped
     * matters more than the new value.** It used to read "Shift+Tab is claimed
     * by neither half and cycles NO pane", guarding a real hazard: the shell's
     * Tab rule carries a `!$msg->shift` conjunct, and dropping it made the
     * rule answer "claim" for Shift+Tab (there is no popup to yield to) while
     * `KeyboardHandler::handle()` had no arm to match the resulting
     * `"shift+tab"` label. The key was CLAIMED AND THEN SWALLOWED -- it never
     * reached Chat and never moved a pane. That is what the old test pinned,
     * and it was right to.
     *
     * What changed is not the risk assessment but the code: `handle()` now has
     * a real `'shift+tab'` arm calling {@see Pane::previous()}, so claim and
     * action exist together and the swallow the old test guarded cannot
     * happen. The `!$msg->shift` conjunct still stands on the ORIGINAL Tab
     * rule -- Shift+Tab is claimed by its own rule beside it, not by widening
     * that one, so the popup-yield logic is untouched.
     *
     * Nothing is stolen from Chat by this. Measured: Chat's only bare-Tab arm
     * (`Chat.php`, the `slashMenuOwnsTab()` case) requires `!$msg->shift`, and
     * its two Shift+Tab-adjacent bindings both require `ctrl` for session
     * cycling, which `chatOwns()` claims before any of this runs. Before the
     * arm existed, Shift+Tab fell through to Chat and Chat did nothing with
     * it in any state, so the key was simply dead.
     *
     * @see \SugarCraft\Crush\Tests\Tui\PaneReverseCycleTest for the decode →
     *      claim → act chain end to end, including the `CSI Z` byte sequence
     *      without which none of this could fire from a real keyboard.
     */
    public function testShiftTabIsClaimedAndCyclesPanesBackwardWithOrWithoutThePopup(): void
    {
        $shiftTab = new KeyMsg(KeyType::Tab, shift: true);

        // Claimed in both states. The popup does not get a say: it binds only
        // the unmodified Tab, so yielding Shift+Tab to it would hand the key
        // to a surface with no arm for it -- the dead-keystroke failure the
        // shell's own Tab docblock warns about.
        $this->assertNotNull(
            (new KeyboardHandler())->handleKeyMsg($shiftTab, $this->shell()),
            'the shell did not claim Shift+Tab with no popup up',
        );
        $this->assertNotNull(
            (new KeyboardHandler())->handleKeyMsg(
                $shiftTab,
                $this->press($this->shell(), $this->type('/comp')),
            ),
            'the shell did not claim Shift+Tab with the popup up',
        );

        // Claimed AND acted on: Chat is the strip's first member, so one
        // Shift+Tab wraps to its last. Asserting the pane MOVED is what
        // separates this from the swallow the old test was written against.
        $empty = $this->press($this->shell(), [$shiftTab]);
        $this->assertSame(Pane::Chat->previous(), $empty->pane);
        $this->assertSame(Pane::Settings, $empty->pane, 'the reverse cycle did not wrap to the last tab');

        // ...and it never reaches the input box, popup showing or not.
        $this->assertSame('', $empty->chat->inputBuf);

        $showing = $this->press($this->press($this->shell(), $this->type('/comp')), [$shiftTab]);
        $this->assertSame(Pane::Settings, $showing->pane);
        $this->assertSame('/comp', $showing->chat->inputBuf, 'Shift+Tab typed a character into the draft');
    }

    /**
     * Chat's own arm ignores a bare Tab on a slash draft that MATCHES
     * NOTHING -- the state an "is a popup open?" guard weakened to "is a
     * slash name being typed?" gets wrong.
     *
     * `/zzzz` has a non-null {@see Chat::slashMenuPrefix()} and an empty
     * match list, so the weakened guard lets the completion run and it
     * indexes `$matches[min(0, count([]) - 1)]` = `$matches[-1]`. Read
     * directly for the same reason {@see testChatsOwnArmIgnoresBareTabWith
     * NoPopupShowing()} is: the shell claims Tab in this state, so no live
     * keystroke reaches the arm.
     */
    public function testChatsOwnArmIgnoresABareTabOnASlashDraftWithNoMatches(): void
    {
        $chat = new Chat(inputBuf: '/zzzz');
        $this->assertSame([], $chat->slashMenuMatches(), 'fixture: a prefix, but nothing matches it');

        [$next] = $chat->update(self::tab());

        $this->assertInstanceOf(Chat::class, $next);
        $this->assertSame('/zzzz', $next->inputBuf);
    }

    /**
     * A Chat with a live permission prompt AND a "/" draft behind it, built
     * through the real PreToolUse gate.
     *
     * The draft is typed MID-TURN (which W2 made possible) rather than before
     * Enter, because Enter on a "/" draft would run the command instead of
     * starting the turn this prompt needs.
     */
    private function chatWithAPromptUpBehindASlashDraft(): Chat
    {
        $asks = new class implements HookInterface {
            public function name(): string
            {
                return 'ask-every-tool';
            }

            public function event(): HookEvent
            {
                return HookEvent::PreToolUse;
            }

            public function matcher(): string
            {
                return '.*';
            }

            public function execute(HookContext $context): HookResult
            {
                return HookResult::ask('Run rm -rf build/?');
            }
        };
        $hooks = new HookManager(new HookRegistry());
        $hooks->register($asks);

        $chat = (new Chat(inputBuf: '', backend: new EchoBackend()))
            ->registerTool('bash', static fn (array $args): string => 'total 0')
            ->withHooks($hooks)
            ->withSize(100, 30);

        foreach ([...$this->type('hi'), new KeyMsg(KeyType::Enter), ...$this->type('/comp')] as $key) {
            [$chat] = $chat->update($key);
        }
        $this->assertSame('/comp', $chat->inputBuf, 'fixture: the draft must survive to the prompt');

        [$blocked] = $chat->update(new AssistantMsg(
            Message::assistant('running')->withToolCalls([
                new ToolCall('bash', ['cmd' => 'rm -rf build/'], 'call_1'),
            ]),
        ));
        $this->assertNotNull($blocked->pendingPermission(), 'fixture: the gate must suspend on a prompt');

        return $blocked;
    }
}
