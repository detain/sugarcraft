<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\InputReader;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Tui\Components\MenuBar;
use SugarCraft\Crush\Tui\KeyboardHandler;
use SugarCraft\Crush\Tui\Pane;

/**
 * Shift+Tab walks the pane strip backwards.
 *
 * Tab could only ever go forward: {@see Pane} had `next()` and nothing else,
 * and {@see KeyboardHandler} bound no Shift+Tab anywhere, so overshooting a
 * pane cost five more presses to come back round. The cycle is now a
 * `candy-focus` {@see \SugarCraft\Focus\FocusRing}, which supplies both
 * directions from one declared traversal order.
 *
 * Three separate things have to be true for the binding to work, and each is
 * pinned below, because two of them can be true while the feature is dead:
 *   1. the terminal's backtab byte sequence decodes to a shifted Tab,
 *   2. the shell CLAIMS that key rather than letting it fall through, and
 *   3. the claim moves the pane the other way.
 *
 * @see Pane::previous()
 * @see KeyboardHandler::handle()
 */
final class PaneReverseCycleTest extends TestCase
{
    /** An App in $pane, with a stub provider it never calls. */
    private function appOn(Pane $pane): App
    {
        return App::new($this->createMock(ProviderInterface::class), 'gpt-4')->withPane($pane);
    }

    /**
     * A shell on Pane::Chat with a REAL Chat whose "/" completion popup is
     * open on a partial command.
     *
     * `appOn()` is not enough for that: `App::new()` leaves `$chat` null, so
     * `chatIsCompletingSlashCommand()` short-circuits false on its first
     * conjunct and no popup exists to yield to. The state has to be driven in
     * the way the user reaches it — host a Chat, then type the draft one
     * keystroke at a time through `update()`, exactly as the Program would.
     */
    private function shellWithAnOpenSlashPopup(): App
    {
        $app = App::new($this->createMock(ProviderInterface::class), 'gpt-4')
            ->withPane(Pane::Chat)
            ->withChat(new Chat());

        foreach (str_split('/comp') as $char) {
            [$next] = $app->update(new KeyMsg(KeyType::Char, $char));
            $this->assertInstanceOf(App::class, $next);
            $app = $next;
        }

        return $app;
    }

    // =========================================================================
    // 1. Decoding — the byte sequence a real terminal sends
    // =========================================================================

    /**
     * `ESC [ Z` must decode to Tab-with-shift.
     *
     * This is the half that made the whole feature unreachable: `CSI Z` is how
     * every xterm-family terminal spells Shift+Tab (the modifier rides in the
     * final byte, not in a `;<mod>` parameter, so it is the one modified key
     * the generic parameter strip cannot handle), and it hit `default => null`
     * in the CSI decoder. A `KeyboardHandler` arm for Shift+Tab would have been
     * correct code that no keypress could ever reach.
     */
    public function testTheBacktabSequenceDecodesToAShiftedTab(): void
    {
        $msgs = (new InputReader())->parse("\x1b[Z");

        $this->assertCount(1, $msgs);
        $msg = $msgs[0];
        $this->assertInstanceOf(KeyMsg::class, $msg);
        $this->assertSame(KeyType::Tab, $msg->type);
        $this->assertTrue($msg->shift, 'backtab decoded without the shift modifier');
        $this->assertFalse($msg->ctrl);
        $this->assertFalse($msg->alt);
        // The label the handler's string-keyed arm actually matches on.
        $this->assertSame('shift+tab', $msg->string());
    }

    /**
     * The unmodified Tab byte is untouched by the new arm.
     *
     * Guards the obvious way to break decoding while "adding" Shift+Tab: 0x09
     * must stay a bare Tab, or every forward cycle and every "/" completion
     * turns into a backward cycle.
     */
    public function testThePlainTabByteStillDecodesUnshifted(): void
    {
        $msgs = (new InputReader())->parse("\x09");

        $this->assertCount(1, $msgs);
        $this->assertInstanceOf(KeyMsg::class, $msgs[0]);
        $this->assertSame(KeyType::Tab, $msgs[0]->type);
        $this->assertFalse($msgs[0]->shift, 'a plain Tab came back shifted');
    }

    // =========================================================================
    // 2. + 3. Claiming and acting
    // =========================================================================

    /**
     * Shift+Tab moves the pane BACKWARD through the strip.
     *
     * Driven through `handleKeyMsg()`, not `handle()`, so it exercises the
     * claim rule as well as the arm: a handler that acts correctly but is
     * never claimed returns null here and the assertion fails, which is the
     * failure mode a `handle('shift+tab')` test would not see.
     */
    public function testShiftTabWalksThePaneStripBackwards(): void
    {
        $handler = new KeyboardHandler();
        $app = $this->appOn(Pane::Chat);
        $shiftTab = new KeyMsg(KeyType::Tab, shift: true);

        // Chat is the strip's first member, so one Shift+Tab wraps to the last.
        $result = $handler->handleKeyMsg($shiftTab, $app);
        $this->assertNotNull($result, 'the shell did not claim Shift+Tab');
        $this->assertSame(Pane::Settings, $result[0]->pane);

        $result = $handler->handleKeyMsg($shiftTab, $result[0]);
        $this->assertNotNull($result);
        $this->assertSame(Pane::Agents, $result[0]->pane);
    }

    /**
     * Shift+Tab is the exact inverse of Tab — over the ring, and only there.
     *
     * The domain qualifier is the whole point. Fold-back maps three non-member
     * panes onto Chat, so it is not injective and no round-trip identity can
     * hold for them under any rule anyone might choose. Stating the identity
     * over `tabCycle()` says something true; stating it over `Pane::cases()`
     * would be false, and stating it without a domain at all is this project's
     * most-repeated defect.
     */
    public function testEveryRingMemberRoundTripsThroughNextThenPrevious(): void
    {
        foreach (Pane::tabCycle() as $pane) {
            $this->assertSame($pane, $pane->next()->previous(), $pane->value . ' next→previous');
            $this->assertSame($pane, $pane->previous()->next(), $pane->value . ' previous→next');
        }
    }

    /**
     * The reverse walk visits the strip in reverse display order and wraps.
     */
    public function testPreviousWalksTheStripInReverseAndWraps(): void
    {
        $this->assertSame(Pane::Settings, Pane::Chat->previous());
        $this->assertSame(Pane::Agents, Pane::Settings->previous());
        $this->assertSame(Pane::Skills, Pane::Agents->previous());
        $this->assertSame(Pane::Tools, Pane::Skills->previous());
        $this->assertSame(Pane::Files, Pane::Tools->previous());
        $this->assertSame(Pane::Chat, Pane::Files->previous());
    }

    /**
     * The three off-strip panes fold back to Chat in BOTH directions.
     *
     * Recorded as a decision, not as an accident of the ring's construction:
     * an unregistered id is a documented no-op for `FocusRing::focus()`, so
     * without the explicit guard in `Pane::step()` these would return whatever
     * position a freshly built ring happened to start on. The rule is "off the
     * strip, either direction, land on the anchor that always draws" — which
     * is what the user pressing Tab on a blank pane is asking for.
     */
    public function testTheOffStripPanesFoldBackToChatInBothDirections(): void
    {
        foreach ([Pane::Input, Pane::Help, Pane::Menu] as $pane) {
            $this->assertSame(Pane::Chat, $pane->next(), $pane->value . '->next()');
            $this->assertSame(Pane::Chat, $pane->previous(), $pane->value . '->previous()');
        }
    }

    /**
     * Shift+Tab is NOT yielded to the "/" completion popup, unlike plain Tab.
     *
     * Plain Tab falls through while the popup shows matches because the popup
     * binds it as the completion key. Chat binds Shift+Tab nowhere — only the
     * CTRL variants, for session cycling — so yielding it would make it a dead
     * keystroke rather than a completion.
     *
     * The two rules are compared on ONE state, and that state is built here
     * rather than assumed. An earlier draft of this test claimed the
     * comparison while running on `appOn(Pane::Chat)`, where `App::new()`
     * leaves `$chat` null: `chatIsCompletingSlashCommand()` returns false on
     * its first conjunct, no popup is on screen, and the test was a strict
     * duplicate of the first half of
     * {@see testShiftTabWalksThePaneStripBackwards()} — one mutation killed
     * both, which is the tell. The plain-Tab yield is therefore asserted
     * directly below as a PRECONDITION: if the popup ever stops owning Tab in
     * this state, this test fails loudly instead of quietly going vacuous
     * again.
     */
    public function testShiftTabIsClaimedEvenWhereThePopupTakesPlainTab(): void
    {
        $handler = new KeyboardHandler();
        $app = $this->shellWithAnOpenSlashPopup();

        // Precondition: this really is a state where the popup owns plain Tab.
        $this->assertNotNull($app->chat);
        $this->assertTrue(
            $app->chat->slashMenuOwnsTab(),
            'the "/" popup is not up, so there is nothing for Shift+Tab to be contrasted against',
        );
        $this->assertNull(
            $handler->handleKeyMsg(new KeyMsg(KeyType::Tab), $app),
            'plain Tab was claimed by the shell instead of being yielded to the popup',
        );

        // The contrast: same App, same keystroke but for the shift flag.
        $claimed = $handler->handleKeyMsg(new KeyMsg(KeyType::Tab, shift: true), $app);
        $this->assertNotNull($claimed, 'Shift+Tab was yielded to the popup, which binds no arm for it');
        $this->assertNotSame(Pane::Chat, $claimed[0]->pane, 'Shift+Tab was claimed but did nothing');
    }

    /**
     * Ctrl+Shift+Tab still belongs to Chat, not to the pane strip.
     *
     * The two chords differ only by the ctrl flag and mean entirely different
     * things — session cycling vs pane cycling — so the new arm must not
     * swallow the one `chatOwns()` already claims. `handleKeyMsg()` returning
     * null IS the "fall through to Chat" contract.
     */
    public function testCtrlShiftTabStillFallsThroughToChat(): void
    {
        $handler = new KeyboardHandler();
        $app = $this->appOn(Pane::Chat);

        $this->assertNull(
            $handler->handleKeyMsg(new KeyMsg(KeyType::Tab, ctrl: true, shift: true), $app),
        );
    }

    // =========================================================================
    // Drift guard
    // =========================================================================

    /**
     * The traversal order and the tab strip that advertises it must match.
     *
     * `Pane::tabCycle()` and `MenuBar::PANE_TABS` are two hand-maintained lists
     * of the same six panes, declared in different files, with nothing but this
     * test connecting them. That is exactly how Settings came to be advertised
     * as a tab while Tab refused to stop on it. Read through reflection because
     * `PANE_TABS` is private and giving it visibility purely for a test would
     * widen the class's API to describe the test rather than the program.
     */
    public function testTheTabCycleMatchesTheStripTheMenuBarAdvertises(): void
    {
        $advertised = (new \ReflectionClass(MenuBar::class))->getConstant('PANE_TABS');

        $this->assertIsArray($advertised);
        $this->assertSame(
            Pane::tabCycle(),
            $advertised,
            'Pane::tabCycle() and MenuBar::PANE_TABS have drifted apart',
        );
    }
}
