<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
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
use SugarCraft\Crush\PermissionRequestMsg;
use SugarCraft\Crush\Providers\EchoProvider;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Session\SessionStore;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Mouse\Zone;

/**
 * The keyboard and the mouse must give the SAME answer to the same request.
 *
 * {@see Chat::update()} dispatches a `MouseMsg` above its
 * `if (!$msg instanceof KeyMsg)` early return, and every capture guard in that
 * method — the keybinding reference, the permission prompt, the palette, the
 * session picker — sits below it. So until {@see Chat::refuseMouseDispatch()}
 * they were all keyboard-only, and a click went through where the equivalent
 * key was refused. Measured at `995eb257`, with a prompt up and idle:
 *
 *   | click on      | did                            | keyboard, same state |
 *   |---------------|--------------------------------|----------------------|
 *   | `toolcall:*`  | toggled that tool body         | Ctrl+O: nothing      |
 *   | `pane:menu`   | opened the palette             | Ctrl+P: nothing      |
 *   | `pane:agents` | ran `/agents`, +2 history rows | Ctrl+A: nothing      |
 *   | `tab:<id>`    | switched session               | Ctrl+Tab: nothing    |
 *
 * (`Ctrl+Tab` — {@see \SugarCraft\Crush\Commands\KeyBindingRegistry} binds
 * `Ctrl+R` to "Open the session picker" and `Ctrl+Tab` to "Switch to the next
 * session", and switching sessions is what a tab click asks for. Two
 * revisions of this table named `Ctrl+R`; the row's answer held either way,
 * since both keys are refused by the prompt arm in this state, but the
 * counterpart was the wrong one.)
 *
 * Not a permission bypass: no surviving zone reaches
 * `Chat::handlePermissionKey()` or the deferred resolution, so a click could
 * never grant, deny or dismiss the prompt. The defect is the disagreement, and
 * the disagreement is what these tests assert — both halves of each pair, in
 * one state, rather than the click's answer on its own.
 *
 * THE ZONE SWEEP IS DELIBERATELY NOT A LIST. A fixture that names the four
 * zone ids above would pass a guard that happened to cover exactly those four
 * and miss the fifth someone adds next year. So the state-by-state test asks
 * the registry what the frame actually marked and clicks every one of them —
 * the fixture is shaped like the property, not like the bug.
 *
 * @see Chat::refuseMouseDispatch()
 * @see Chat::handleMouse()
 */
final class MouseModalGuardTest extends TestCase
{
    private const VARS = ['SUGARCRUSH_DISABLE_MOUSE', 'SUGARCRUSH_DISABLE_MOUSE_CLICKS'];

    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        foreach (self::VARS as $var) {
            putenv($var);
        }
        Renderer::scanner()->clear();
        $this->resetClickTracker();
    }

    protected function tearDown(): void
    {
        foreach (self::VARS as $var) {
            putenv($var);
        }
        Renderer::scanner()->clear();
        $this->resetClickTracker();

        foreach ($this->tempDirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
        $this->tempDirs = [];
    }

    // =========================================================================
    // The headline pair, through the production route
    // =========================================================================

    /**
     * The whole finding in one method, with the prompt built end to end by the
     * real gate — a `PreToolUse` hook returning `HookResult::ask()`, a real
     * Enter, a real `AssistantMsg` carrying a tool call — and then carried into
     * the idle-with-a-prompt state by a second real `AssistantMsg`, which is
     * the route {@see Chat::update()}'s own ordering comment names: its
     * `AssistantMsg` arm writes `'inFlight' => false` and does not clear
     * `pendingPermission`.
     *
     * That state matters because it is the one where the status bar still
     * paints its "Ctrl+P menu" hint, so `pane:menu` is still marked and still
     * clickable. Driven at `995eb257` this click opened the palette while
     * Ctrl+P in the same state did nothing.
     */
    public function testAClickUnderALivePromptIsRefusedExactlyAsTheKeyIs(): void
    {
        $idle = $this->promptUpAndIdleViaTheRealGate();

        $idle->view();
        $zone = Renderer::scanner()->get(Renderer::PANE_ZONE_PREFIX . 'menu');
        self::assertInstanceOf(
            Zone::class,
            $zone,
            'fixture: the bar keeps its menu hint at an idle prompt, so there is something to click',
        );

        [$clicked, $cmd] = $this->clickResult($idle, $zone);
        [$typed] = $idle->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));

        self::assertNull($cmd, 'a refused click runs nothing — the second element is part of the answer');
        self::assertNull($typed->palette(), 'Ctrl+P was always refused here — the prompt arm outranks it');
        self::assertNull($clicked->palette(), 'and the click must now be refused the same way');
        self::assertNotNull($clicked->pendingPermission(), 'the prompt is still the thing on screen');
        self::assertSame(
            count($idle->history),
            count($clicked->history),
            'and nothing was written under it',
        );
    }

    // =========================================================================
    // Every zone, every capture state
    // =========================================================================

    /**
     * For each state in which {@see Chat::update()} captures the keyboard, ask
     * the zone registry what the frame marked and click every single one of
     * them. Nothing observable may move, and no `Cmd` may come back either.
     *
     * THE PALETTE IS IN THIS SWEEP, with its own rows excluded by id — and it
     * was not, which cost the guard a surviving mutation. The palette is the
     * one capture state with a non-trivial rule (it OWNS the `picker-item:`
     * rows §8 E6 exists to make clickable, so those stay live and the frame
     * behind them does not), so leaving it out of the all-zones sweep left the
     * rule tested only where it says "yes". Measured against the excluded
     * version: widening the guard's whitelist from `'picker-item:'` to `'p'`
     * survived the whole 8772-test suite while being behavioural — a
     * `pane:agents` click under an open palette ran `/agents` and wrote two
     * history rows. With the palette in the sweep that mutation reds here.
     * The positive half — a real row still runs — stays in
     * {@see testThePaletteKeepsItsOwnRowsClickableWhileItSwallowsTheRest()},
     * which is also why the rows are skipped rather than asserted inert.
     *
     * THE SECOND ELEMENT IS ASSERTED, not destructured away. A refusal returns
     * `[$this, null]`; nothing pinned the `null`, so replacing it with
     * `Cmd::quit()` survived — at best it would have been caught as a hung
     * suite rather than as a failed assertion.
     *
     * @param 'keyHelp'|'prompt'|'picker'|'palette' $state
     *
     * @dataProvider capturingStates
     */
    public function testEveryZoneACapturingFrameStillMarksIsInertToAClick(string $state): void
    {
        $chat = $this->capturing($state);
        $chat->view();

        $ids = array_keys(Renderer::scanner()->all());
        sort($ids);
        self::assertNotSame(
            [],
            $ids,
            "fixture: the {$state} frame must still mark SOMETHING, or this test proves nothing",
        );

        $swept = 0;
        $before = $this->snapshot($chat);
        foreach ($ids as $id) {
            if (str_starts_with($id, Renderer::PALETTE_ITEM_ZONE_PREFIX)) {
                // The overlay's OWN rows, which are live by design.
                self::assertSame('palette', $state, "a {$state} frame must not mark palette rows");

                continue;
            }

            $zone = Renderer::scanner()->get($id);
            self::assertInstanceOf(Zone::class, $zone);

            [$after, $cmd] = $this->clickResult($chat, $zone);
            $swept++;

            self::assertSame(
                $before,
                $this->snapshot($after),
                "clicking '{$id}' changed state while {$state} was capturing the keyboard",
            );
            self::assertNull(
                $cmd,
                "clicking '{$id}' under {$state} handed back a Cmd, and a refusal runs nothing",
            );

            // The click consumed the frame's tracker state, so re-scan before
            // the next id rather than clicking against a spent registry.
            $this->resetClickTracker();
            Renderer::scanner()->clear();
            $chat->view();
        }

        self::assertGreaterThan(
            0,
            $swept,
            "fixture: the {$state} frame marked nothing but palette rows, so nothing was swept",
        );
    }

    /**
     * @return array<string, array{0:string}>
     */
    public static function capturingStates(): array
    {
        return [
            'keybinding reference' => ['keyHelp'],
            'permission prompt' => ['prompt'],
            'session picker' => ['picker'],
            'command palette' => ['palette'],
        ];
    }

    /**
     * The guard must not be wider than the capture it mirrors: the palette's
     * own rows are the one thing a click may still reach while it is up, which
     * is the whole of crush_feat.md §8 E6.
     */
    public function testThePaletteKeepsItsOwnRowsClickableWhileItSwallowsTheRest(): void
    {
        $chat = $this->capturing('palette');
        $chat->view();

        $row = Renderer::scanner()->get(Renderer::PALETTE_ITEM_ZONE_PREFIX . '0');
        self::assertInstanceOf(Zone::class, $row, 'fixture: the open palette must mark its first row');

        $picked = $this->click($chat, $row);
        self::assertNotSame(
            $chat->palette(),
            $picked->palette(),
            'a click on a palette row still runs that row - the guard covers the frame BEHIND the '
            . 'overlay, not the overlay itself',
        );

        // ...and the transcript underneath it is not reachable, which is the
        // answer Ctrl+O already gives while the palette owns the keyboard.
        $this->resetClickTracker();
        Renderer::scanner()->clear();
        $chat->view();
        $tool = Renderer::scanner()->get(Renderer::TOOL_CALL_ZONE_PREFIX . 'call_1');
        self::assertInstanceOf(Zone::class, $tool, 'fixture: the transcript still shows through');

        $behind = $this->click($chat, $tool);
        [$typed] = $chat->update(new KeyMsg(KeyType::Char, 'o', ctrl: true));

        self::assertSame([], $typed->expanded(), 'Ctrl+O is swallowed by the palette');
        self::assertSame([], $behind->expanded(), 'and so is the click that asks for the same thing');
    }

    /**
     * The other half of a guard: with nothing capturing, every one of those
     * same zones still does its job. Without this, narrowing the guard to
     * "always refuse" would read as a pass.
     */
    public function testWithNothingCapturingTheSameZonesStillDispatch(): void
    {
        $chat = $this->populated();
        $chat->view();

        $menu = Renderer::scanner()->get(Renderer::PANE_ZONE_PREFIX . 'menu');
        self::assertInstanceOf(Zone::class, $menu);
        self::assertNotNull($this->click($chat, $menu)->palette(), 'pane:menu still opens the palette');

        $this->rescan($chat);
        $tool = Renderer::scanner()->get(Renderer::TOOL_CALL_ZONE_PREFIX . 'call_1');
        self::assertInstanceOf(Zone::class, $tool);
        self::assertSame(['call_1' => true], $this->click($chat, $tool)->expanded());

        $this->rescan($chat);
        $tab = Renderer::scanner()->get(Renderer::SESSION_TAB_ZONE_PREFIX . 'session-a');
        self::assertInstanceOf(Zone::class, $tab);
        self::assertSame('session-a', $this->click($chat, $tab)->currentSessionId());

        $this->rescan($chat);
        $agents = Renderer::scanner()->get(Renderer::PANE_ZONE_PREFIX . 'agents');
        self::assertInstanceOf(Zone::class, $agents);
        self::assertGreaterThan(
            count($chat->history),
            count($this->click($chat, $agents)->history),
            'pane:agents still runs /agents when nothing is capturing',
        );
    }

    // =========================================================================
    // The wheel is deliberately NOT guarded
    // =========================================================================

    /**
     * Reading the transcript while deciding how to answer a prompt is
     * legitimate, and refusing the wheel would open a NEW divergence instead of
     * closing one: `PageUp`/`PageDown` sit ABOVE the prompt, palette and picker
     * guards in {@see Chat::update()} and below the reference's, and
     * {@see Chat::scrollTranscript()} already redirects the wheel onto the
     * reference when that is what is up. So in every capture state the two
     * devices already agreed about scrolling, and they still do.
     *
     * @param 'keyHelp'|'prompt'|'picker'|'palette' $state
     *
     * @dataProvider scrollableStates
     */
    public function testTheWheelStillScrollsInEveryCaptureState(string $state): void
    {
        $chat = $this->capturing($state, transcriptRows: 200);
        $chat->view();

        if ($state === 'keyHelp') {
            [$wheeled] = $chat->update($this->wheel(MouseButton::WheelDown));
            [$paged] = $chat->update(new KeyMsg(KeyType::PageDown));

            self::assertGreaterThan(0, $wheeled->keyHelp() ?? 0, 'the wheel drives the reference');
            self::assertGreaterThan(0, $paged->keyHelp() ?? 0, 'and so does PageDown');

            return;
        }

        [$wheeled] = $chat->update($this->wheel(MouseButton::WheelUp));
        [$paged] = $chat->update(new KeyMsg(KeyType::PageUp));

        self::assertGreaterThan(0, $wheeled->scrollOffset(), "the wheel still scrolls under {$state}");
        self::assertGreaterThan(0, $paged->scrollOffset(), "and PageUp always did under {$state}");
    }

    /**
     * @return array<string, array{0:string}>
     */
    public static function scrollableStates(): array
    {
        return [
            'keybinding reference' => ['keyHelp'],
            'permission prompt' => ['prompt'],
            'session picker' => ['picker'],
            'command palette' => ['palette'],
        ];
    }

    // =========================================================================
    // Mid-turn: not a capture state, so refused per action and VISIBLY
    // =========================================================================

    /**
     * `inFlight` is not a capture state on the keyboard — {@see Chat::update()}
     * refuses three named keys mid-turn and lets everything else run — so the
     * mouse is not blanket-guarded either. The two gestures that reach a
     * session change or a turn-starting command are refused the way the
     * keyboard refuses them: with a notice the user can see.
     */
    public function testMidTurnASessionTabClickIsRefusedWithTheKEYBOARDsOwnNotice(): void
    {
        $chat = $this->populated(inFlight: true);
        $chat->view();

        $tab = Renderer::scanner()->get(Renderer::SESSION_TAB_ZONE_PREFIX . 'session-a');
        self::assertInstanceOf(Zone::class, $tab, 'fixture: tabs are still marked mid-turn');

        $clicked = $this->click($chat, $tab);
        [$typed] = $chat->update(new KeyMsg(KeyType::Tab, ctrl: true));

        self::assertSame('session-b', $typed->currentSessionId(), 'Ctrl+Tab never switched mid-turn');
        self::assertSame('session-b', $clicked->currentSessionId(), 'and now neither does the click');
        self::assertSame(
            $this->lastLine($typed),
            $this->lastLine($clicked),
            'the same request refused the same way says the same thing',
        );
    }

    /**
     * The already-current tab is the case where a notice would be noise: the
     * click was going to be a no-op whichever way the turn was going, so the
     * refusal is checked after {@see Chat::selectSessionTab()}'s own validity
     * gates rather than before them.
     */
    public function testMidTurnAClickOnTheCurrentTabRefusesNothingAndSaysNothing(): void
    {
        $chat = $this->populated(inFlight: true);
        $chat->view();

        $tab = Renderer::scanner()->get(Renderer::SESSION_TAB_ZONE_PREFIX . 'session-b');
        self::assertInstanceOf(Zone::class, $tab);

        $clicked = $this->click($chat, $tab);

        self::assertSame('session-b', $clicked->currentSessionId());
        self::assertCount(count($chat->history), $clicked->history, 'no notice for a no-op');
    }

    public function testMidTurnAnAgentsPaneClickIsRefusedRatherThanRun(): void
    {
        $chat = $this->populated(inFlight: true);
        $chat->view();

        $agents = Renderer::scanner()->get(Renderer::PANE_ZONE_PREFIX . 'agents');
        self::assertInstanceOf(Zone::class, $agents, 'fixture: the agent header is still marked mid-turn');

        $clicked = $this->click($chat, $agents);
        [$typed] = $chat->update(new KeyMsg(KeyType::Char, 'a', ctrl: true));

        // Both refuse with exactly one line, and neither runs the listing.
        self::assertCount(count($chat->history) + 1, $clicked->history);
        self::assertCount(count($chat->history) + 1, $typed->history);
        self::assertStringNotContainsString('reviewer', $this->lastLine($clicked));
        self::assertStringNotContainsString('reviewer', $this->lastLine($typed));
        self::assertStringContainsString('in flight', $this->lastLine($clicked));
    }

    /**
     * And the gesture mid-turn that the keyboard DOES allow stays allowed:
     * Ctrl+O expands a tool body while a turn runs, so a click on that row
     * must too. A guard that swallowed this would be the same bug pointing the
     * other way.
     */
    public function testMidTurnAToolCallClickStillExpandsBecauseCtrlOStillDoes(): void
    {
        $chat = $this->populated(inFlight: true);
        $chat->view();

        $tool = Renderer::scanner()->get(Renderer::TOOL_CALL_ZONE_PREFIX . 'call_1');
        self::assertInstanceOf(Zone::class, $tool);

        $clicked = $this->click($chat, $tool);
        [$typed] = $chat->update(new KeyMsg(KeyType::Char, 'o', ctrl: true));

        self::assertSame(['call_1' => true], $typed->expanded(), 'Ctrl+O works mid-turn');
        self::assertSame(['call_1' => true], $clicked->expanded(), 'and so does the click');
    }

    // =========================================================================
    // Why the guard is at the dispatch point and not at the top
    // =========================================================================

    /**
     * The press/release tracker is STATIC state that outlives any modal: a
     * press with no matching release still pairs with an arbitrarily later
     * one. Driven at `995eb257`: press on `pane:menu`, skip the release
     * entirely, release again — the palette opens.
     *
     * That is why {@see Chat::refuseMouseDispatch()} runs AFTER
     * {@see Chat::clickTracker()} has resolved the pair rather than at the top
     * of {@see Chat::handleMouse()}. A guard that returned before the tracker
     * saw the release would leave the press armed for the life of the prompt
     * and fire it the moment the user answered.
     */
    public function testAPressInterruptedByAPromptCannotFireOnceThePromptIsGone(): void
    {
        $chat = $this->populated();
        $chat->view();

        $menu = Renderer::scanner()->get(Renderer::PANE_ZONE_PREFIX . 'menu');
        self::assertInstanceOf(Zone::class, $menu);

        // Press with nothing capturing...
        [$pressed] = $chat->update($this->press($menu->startCol, $menu->startRow));
        self::assertNull($pressed->palette(), 'a press alone opens nothing');

        // ...a prompt arrives, and the release lands under it. The tracker is
        // static, so the gesture spans these instances exactly as it spans the
        // frames of a running program.
        $blocked = $this->populated(prompt: $this->ask());
        [$underPrompt, $refusedCmd] = $blocked->update($this->release($menu->startCol, $menu->startRow));
        self::assertNull($underPrompt->palette(), 'the release is refused while the prompt is up');
        // The one gesture that still reaches {@see Chat::refuseMouseDispatch()}'s
        // own return value: a press made legally, released under a capture that
        // arrived in between. (A press made UNDER the capture is thrown away at
        // the press, so its release never resolves a pair at all.) Both elements
        // are asserted — returning `Cmd::quit()` from the refusal survived
        // everything while only the first was read.
        self::assertNull($refusedCmd, 'a refused click runs no Cmd');

        // The prompt is answered, and the abandoned gesture must be gone with
        // it - not waiting for the next release to pair with.
        $answered = $this->populated();
        [$later] = $answered->update($this->release($menu->startCol, $menu->startRow));

        self::assertNull(
            $later->palette(),
            'the press was consumed by the refused release, so nothing is left armed',
        );
    }

    /**
     * The MIRROR of the case above, which the placement argument did not cover
     * until this test: press UNDER the capture, release after it clears.
     *
     * The docblock on {@see Chat::refuseMouseDispatch()} rejects a
     * top-of-method guard because it "would leave that press armed for the
     * whole life of the prompt and fire it the moment the user answered" — and
     * measured before this landed, the SHIPPED placement did exactly that in
     * this direction: press `pane:menu` under a live prompt, answer it with
     * `n`, release, and the palette opened. The keyboard has no such window; a
     * key that arrives under a modal is consumed there and then. So the press
     * is consumed at the press, by {@see Chat::handleMouse()} handing the
     * tracker a null press zone.
     */
    public function testThePressMadeUnderAPromptIsGoneOnceThePromptIs(): void
    {
        $blocked = $this->populated(prompt: $this->ask());
        $blocked->view();

        $menu = Renderer::scanner()->get(Renderer::PANE_ZONE_PREFIX . 'menu');
        self::assertInstanceOf(
            Zone::class,
            $menu,
            'fixture: the bar keeps its menu hint at an idle prompt, so there is something to press',
        );

        [$pressed] = $blocked->update($this->press($menu->startCol, $menu->startRow));
        self::assertNull($pressed->palette(), 'a press under the prompt opens nothing on its own');

        // The user answers the prompt, and only then lets the button up. The
        // tracker is static, so the gesture spans these instances exactly as
        // it spans the frames of a running program.
        $answered = $this->populated();
        [$later, $cmd] = $answered->update($this->release($menu->startCol, $menu->startRow));

        self::assertNull(
            $later->palette(),
            'the press was thrown away under the prompt, so answering it cannot fire the press',
        );
        self::assertNull($cmd, 'and a gesture that dispatches nothing hands back no Cmd');
    }

    // =========================================================================
    // Mid-turn under an overlay: update()'s precedence, not the reverse
    // =========================================================================

    /**
     * `inFlight` is checked ABOVE the palette and the picker in
     * {@see Chat::update()}, so mid-turn with the palette open `Ctrl+Tab` does
     * not reach {@see Chat::handlePaletteKey()}: it is refused visibly and the
     * notice closes the palette. A first revision of
     * {@see Chat::refuseMouseDispatch()} ordered those two the other way
     * round, and the two devices diverged again in a quieter register —
     * measured against it, `Ctrl+Tab` wrote a line and closed the palette
     * while the `tab:` click under the same open palette did nothing at all
     * and said nothing. Refusing is not agreeing: this commit's standard is
     * that what the keyboard refuses VISIBLY, the mouse refuses visibly.
     */
    public function testMidTurnUnderAnOpenPaletteTheTabClickIsRefusedAsLOUDLYAsCtrlTab(): void
    {
        $chat = $this->openedWith(
            $this->populated(inFlight: true),
            new KeyMsg(KeyType::Char, 'p', ctrl: true),
        );
        self::assertNotNull($chat->palette(), 'fixture: the palette opens mid-turn — half the bug report');
        $chat->view();

        $tab = Renderer::scanner()->get(Renderer::SESSION_TAB_ZONE_PREFIX . 'session-a');
        self::assertInstanceOf(Zone::class, $tab, 'fixture: the tab strip shows through the overlay');

        $clicked = $this->click($chat, $tab);
        [$typed] = $chat->update(new KeyMsg(KeyType::Tab, ctrl: true));

        self::assertSame('session-b', $typed->currentSessionId(), 'Ctrl+Tab does not switch mid-turn');
        self::assertSame('session-b', $clicked->currentSessionId(), 'and neither does the click');
        self::assertSame(
            $this->lastLine($typed),
            $this->lastLine($clicked),
            'and both say so, in the same words — a silent refusal is a third answer',
        );
        self::assertNull($typed->palette(), 'the notice closes the overlay it was written under');
        self::assertNull($clicked->palette(), 'both of them');
    }

    /**
     * The same precedence for the other mid-turn refusal, under the other
     * overlay: `pane:agents` while the session picker is up.
     */
    public function testMidTurnUnderTheSessionPickerTheAgentsClickStillSaysWhyItRefused(): void
    {
        $chat = $this->openedWith(
            $this->populated(inFlight: true),
            new KeyMsg(KeyType::Char, 'r', ctrl: true),
        );
        self::assertNotNull($chat->sessionPicker(), 'fixture: Ctrl+R opens the picker mid-turn too');
        $chat->view();

        $agents = Renderer::scanner()->get(Renderer::PANE_ZONE_PREFIX . 'agents');
        self::assertInstanceOf(Zone::class, $agents, 'fixture: the bar shows through the picker');

        $clicked = $this->click($chat, $agents);

        self::assertCount(count($chat->history) + 1, $clicked->history, 'the refusal is written down');
        self::assertStringContainsString('in flight', $this->lastLine($clicked));
        self::assertStringNotContainsString('reviewer', $this->lastLine($clicked), 'and nothing was listed');
    }

    // =========================================================================
    // The one overlay pair real input CAN build, and who wins it
    // =========================================================================

    /**
     * A prompt over an OPEN OVERLAY is reachable through the front door, both
     * with the palette and with the session picker, and neither
     * {@see Chat::requestPermission()} nor anything else closes the overlay
     * first. It is reachable BECAUSE the mid-turn split landed: the palette
     * and the picker now open while a turn is running, and a turn running is
     * exactly the window in which a prompt appears.
     *
     * Driven, not narrated, because the claim it corrects was narrated —
     * {@see \SugarCraft\Crush\Tests\Renderer\KeyHelpTest::testTheOverlayChainPaintsInRoutingOrderRightDownTheChain()}
     * said of the four overlays' six pairs that "NONE of them is reachable
     * through the front door any more", having previously said "exactly ONE".
     * Measured over all six: two are reachable, and they are these.
     *
     * WHO WINS IS THE SAME ON BOTH DEVICES, which is this whole commit's
     * subject. {@see Chat::update()} tests `$pendingPermission` above the
     * palette and the picker, so a keystroke answers the prompt rather than
     * the overlay under it; {@see Chat::refuseMouseDispatch()}'s FIRST arm is
     * the same rule for the click, and it is not a hypothetical one — the
     * palette's rows were marked in the frame that was on screen when the
     * prompt arrived, so the registry still holds them.
     */
    public function testAPromptRaisedOverAnOpenOverlayOutranksItOnBothDevices(): void
    {
        $withPalette = $this->openedWith(
            $this->turnInFlightViaTheRealGate(),
            new KeyMsg(KeyType::Char, 'p', ctrl: true),
        );
        self::assertNotNull($withPalette->palette(), 'fixture: Ctrl+P opens the palette mid-turn');

        // The frame the user is looking at when the ask lands, rows and all.
        $withPalette->view();
        $row = Renderer::scanner()->get(Renderer::PALETTE_ITEM_ZONE_PREFIX . '0');
        self::assertInstanceOf(Zone::class, $row, 'fixture: the open palette marks its first row');

        $blocked = $this->promptRaisedOverATurnInFlight($withPalette);
        self::assertNotNull($blocked->palette(), 'the prompt does not close the palette under it');

        // The keyboard: the prompt takes the keystroke, the palette does not.
        [$typed] = $blocked->update(new KeyMsg(KeyType::Char, 'x'));
        self::assertSame(
            $blocked->palette()?->query,
            $typed->palette()?->query,
            'a rune under the prompt must not filter the buried palette',
        );

        // The mouse, on the very zone that frame left in the registry.
        [$clicked, $cmd] = $this->clickResult($blocked, $row);
        self::assertNull($cmd, 'the buried row runs nothing');
        self::assertSame(
            $blocked->palette()?->mode,
            $clicked->palette()?->mode,
            'and the row it points at is not confirmed either',
        );
        self::assertCount(count($blocked->history), $clicked->history, 'nothing was written under the prompt');

        // The same pair with the other overlay.
        $withPicker = $this->openedWith(
            $this->turnInFlightViaTheRealGate(),
            new KeyMsg(KeyType::Char, 'r', ctrl: true),
        );
        self::assertNotNull($withPicker->sessionPicker(), 'fixture: Ctrl+R opens the picker mid-turn');

        $overPicker = $this->promptRaisedOverATurnInFlight($withPicker);
        self::assertNotNull($overPicker->sessionPicker(), 'the prompt does not close the picker either');
        self::assertNotNull($overPicker->pendingPermission(), 'and both really are up at once');
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    /**
     * A chat carrying one of everything the renderer can mark: a completed
     * tool call, two sessions, and a registered agent.
     */
    private function populated(
        bool $inFlight = false,
        int $transcriptRows = 0,
        ?PermissionRequestMsg $prompt = null,
    ): Chat {
        $history = [];
        for ($i = 0; $i < $transcriptRows; $i++) {
            $history[] = Message::user("line {$i}");
        }
        $history[] = Message::assistant('')->withToolResults([ToolResult::ok('grep', "alpha\nbeta", 'call_1')]);

        $manager = new AgentManager(new EchoProvider(), new SkillRegistry());
        $manager->register(new Agent(
            name: 'reviewer',
            description: 'Reviews code for bugs',
            prompt: 'You are a reviewer.',
            model: 'echo-1',
            provider: 'echo',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: true,
        ));

        return new Chat(
            history: $history,
            inFlight: $inFlight,
            sessionStore: $this->storeWithTwoSessions(),
            currentSessionId: 'session-b',
            agentManager: $manager,
            pendingPermission: $prompt,
        );
    }

    /**
     * The same populated chat, put into one of the states in which
     * {@see Chat::update()} captures the keyboard.
     *
     * @param 'keyHelp'|'prompt'|'picker'|'palette' $state
     */
    private function capturing(string $state, int $transcriptRows = 0): Chat
    {
        if ($state === 'prompt') {
            return $this->populated(transcriptRows: $transcriptRows, prompt: $this->ask());
        }

        $chat = $this->populated(transcriptRows: $transcriptRows);

        return match ($state) {
            'keyHelp' => $chat->withKeyHelp(0),
            'picker' => $this->openedWith($chat, new KeyMsg(KeyType::Char, 'r', ctrl: true)),
            'palette' => $this->openedWith($chat, new KeyMsg(KeyType::Char, 'p', ctrl: true)),
        };
    }

    private function openedWith(Chat $chat, KeyMsg $key): Chat
    {
        [$opened] = $chat->update($key);

        return $opened;
    }

    /**
     * A live prompt with the turn already settled, built end to end: a real
     * `PreToolUse` ask hook, a real Enter, a real `AssistantMsg` with a tool
     * call, then a second real `AssistantMsg` whose arm clears `inFlight`
     * without clearing the prompt.
     */
    private function promptUpAndIdleViaTheRealGate(): Chat
    {
        $blocked = $this->promptRaisedOverATurnInFlight($this->turnInFlightViaTheRealGate());

        [$idle] = $blocked->update(new AssistantMsg(Message::assistant('done')));
        self::assertNotNull($idle->pendingPermission(), 'fixture: the prompt outlives its turn');
        self::assertFalse($idle->inFlight, 'fixture: which is the state that keeps the bar hint');

        return $idle;
    }

    /**
     * A real turn in flight: an `EchoBackend`, a `PreToolUse` hook that asks
     * about every tool, two runes typed and a real `Enter`.
     */
    private function turnInFlightViaTheRealGate(): Chat
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

        $chat = (new Chat(
            history: [],
            inputBuf: '',
            backend: new EchoBackend(),
            sessionStore: $this->storeWithTwoSessions(),
            currentSessionId: 'session-b',
        ))
            ->registerTool('bash', static fn(array $args): string => 'total 0')
            ->withHooks($hooks)
            ->withSize(100, 30);

        foreach (['h', 'i'] as $rune) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Char, $rune));
        }
        [$chat] = $chat->update(new KeyMsg(KeyType::Enter));
        self::assertTrue($chat->inFlight, 'fixture: Enter must put a real turn in flight');

        return $chat;
    }

    /**
     * The reply that carries a tool call, which the ask hook turns into a
     * prompt — {@see Chat::requestPermission()} through the front door.
     */
    private function promptRaisedOverATurnInFlight(Chat $inFlight): Chat
    {
        [$blocked] = $inFlight->update(new AssistantMsg(
            Message::assistant('running')->withToolCalls([
                new ToolCall('bash', ['cmd' => 'rm -rf build/'], 'call_1'),
            ]),
        ));
        self::assertNotNull($blocked->pendingPermission(), 'fixture: the ask hook must raise a prompt');
        self::assertTrue($blocked->inFlight, 'fixture: and it holds the turn in flight');

        return $blocked;
    }

    private function ask(): PermissionRequestMsg
    {
        return new PermissionRequestMsg(
            assistantMessage: Message::assistant(''),
            toolCall: new ToolCall('bash', ['command' => 'rm -rf build/'], 'call_ask'),
            prompt: 'Run rm -rf build/?',
        );
    }

    private function storeWithTwoSessions(): SessionStore
    {
        $dir = sys_get_temp_dir() . '/crush_mouse_modal_' . uniqid('', true);
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;

        $store = new SessionStore($dir . '/sessions.db');
        $store->createSession('session-a', 'echo', 'echo-1', null, 'Alpha');
        $store->createSession('session-b', 'echo', 'echo-1', null, 'Beta');

        return $store;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Everything a refused click must leave alone, as one comparable value.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Chat $chat): array
    {
        return [
            'palette' => $chat->palette()?->mode,
            'paletteQuery' => $chat->palette()?->query,
            'paletteIndex' => $chat->palette()?->selectedIndex,
            'sessionPicker' => $chat->sessionPicker() !== null,
            'session' => $chat->currentSessionId(),
            'expanded' => $chat->expanded(),
            'history' => count($chat->history),
            'prompt' => $chat->pendingPermission()?->prompt,
            'keyHelp' => $chat->keyHelp(),
            'scroll' => $chat->scrollOffset(),
        ];
    }

    /**
     * The last transcript line's text. Read by index rather than with `end()`,
     * which moves an internal pointer and so cannot be applied to a readonly
     * property.
     */
    private function lastLine(Chat $chat): string
    {
        return $chat->history[count($chat->history) - 1]->content;
    }

    private function click(Chat $chat, Zone $zone): Chat
    {
        return $this->clickResult($chat, $zone)[0];
    }

    /**
     * The click's WHOLE result, both elements. A refused click must hand back
     * `[$chat, null]`, and the second element is worth asserting: with the
     * suite destructuring `[$chat] = ...` everywhere, returning `Cmd::quit()`
     * from the refusal was a survivor.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function clickResult(Chat $chat, Zone $zone): array
    {
        [$chat] = $chat->update($this->press($zone->startCol, $zone->startRow));

        return $chat->update($this->release($zone->startCol, $zone->startRow));
    }

    private function rescan(Chat $chat): void
    {
        $this->resetClickTracker();
        Renderer::scanner()->clear();
        $chat->view();
    }

    private function press(int $col, int $row): MouseClickMsg
    {
        return new MouseClickMsg($col, $row, MouseButton::Left, MouseAction::Press);
    }

    private function release(int $col, int $row): MouseReleaseMsg
    {
        return new MouseReleaseMsg($col, $row, MouseButton::Left, MouseAction::Release);
    }

    private function wheel(MouseButton $button): MouseWheelMsg
    {
        return new MouseWheelMsg(5, 5, $button, MouseAction::Press);
    }

    private function resetClickTracker(): void
    {
        (new \ReflectionProperty(Chat::class, 'clickTracker'))->setValue(null, null);
    }
}
