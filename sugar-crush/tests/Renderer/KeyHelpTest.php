<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\CommandRegistry;
use SugarCraft\Crush\Commands\KeyBindingRegistry;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Tui\SessionPicker;

/**
 * The in-app keybinding reference (crush_code.md Phase 8 item 2): how it is
 * opened, how it is dismissed, and the two render invariants a frame in this
 * app may never break — no line wider than the terminal, no frame taller than
 * it, because candy-core's renderer repaints with an absolute cursorTo() and
 * paints exactly one logical line per physical row.
 *
 * @see Renderer::renderKeyHelp()
 * @see Chat::keyHelp()
 */
final class KeyHelpTest extends TestCase
{
    private const TITLE = 'keyboard shortcuts';

    private function chat(string $input = '', int $cols = 100, int $rows = 30): Chat
    {
        return (new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            inputBuf: $input,
            backend: new EchoBackend(),
        ))->withSize($cols, $rows);
    }

    private function body(Chat $chat): string
    {
        $view = $chat->view();

        return is_string($view) ? $view : $view->body;
    }

    // ── opening ──────────────────────────────────────────────────────────

    public function testTheReferenceIsNotDrawnUntilItIsAskedFor(): void
    {
        $this->assertNull($this->chat()->keyHelp());
        $this->assertStringNotContainsString(self::TITLE, $this->body($this->chat()));
    }

    public function testQuestionMarkOnAnEmptyInputLineOpensIt(): void
    {
        [$next] = $this->chat()->update(new KeyMsg(KeyType::Char, '?'));

        $this->assertSame(0, $next->keyHelp());
        $this->assertStringContainsString(self::TITLE, $this->body($next));
    }

    /**
     * The whole reason "?" is guarded rather than bound outright: it is a
     * printable character, and a user typing a question must still get one.
     */
    public function testQuestionMarkIsTypedIntoANonEmptyInputLine(): void
    {
        [$next] = $this->chat('why')->update(new KeyMsg(KeyType::Char, '?'));

        $this->assertNull($next->keyHelp());
        $this->assertSame('why?', $next->inputBuf);
    }

    public function testSlashKeysOpensIt(): void
    {
        [$next] = $this->chat('/keys')->update(new KeyMsg(KeyType::Enter));

        $this->assertSame(0, $next->keyHelp());
        $this->assertSame('', $next->inputBuf, 'the command must not be left in the draft');
        $this->assertSame([], array_slice($next->history, 2), 'and must not be sent to the model');
    }

    public function testSlashHelpIsAcceptedAsAnAlias(): void
    {
        [$next] = $this->chat('/help')->update(new KeyMsg(KeyType::Enter));

        $this->assertSame(0, $next->keyHelp());
    }

    /**
     * "/help me name this variable" is a prompt, not a request for the
     * shortcut list — which is why the two spellings match exactly rather
     * than by prefix like the argument-taking commands.
     */
    public function testAPromptThatMerelyStartsWithHelpIsStillAPrompt(): void
    {
        [$next] = $this->chat('/help me name this variable')->update(new KeyMsg(KeyType::Enter));

        $this->assertNull($next->keyHelp());
    }

    public function testTheSlashPopupListsKeys(): void
    {
        $this->assertSame(['keys'], self::popup('keys'));
    }

    /**
     * Both spellings `Chat::submit()` accepts have to be discoverable, not just
     * the one: `/help` worked when typed in full and appeared in no popup,
     * which is the same registry-vs-behaviour drift {@see CommandRegistry}
     * exists to close.
     */
    public function testTheSlashPopupListsHelpToo(): void
    {
        $this->assertSame(['help'], self::popup('help'));
    }

    /**
     * @return list<string>
     */
    private static function popup(string $prefix): array
    {
        return array_map(
            static fn($spec): string => $spec->name,
            CommandRegistry::filter($prefix),
        );
    }

    // ── dismissal + key routing ──────────────────────────────────────────

    /**
     * @return list<array{0: string, 1: KeyMsg}>
     */
    public static function dismissKeys(): array
    {
        return [
            ['Escape', new KeyMsg(KeyType::Escape)],
            ['Enter', new KeyMsg(KeyType::Enter)],
            ['q', new KeyMsg(KeyType::Char, 'q')],
            ['?', new KeyMsg(KeyType::Char, '?')],
        ];
    }

    public function testEveryDocumentedDismissKeyCloses(): void
    {
        foreach (self::dismissKeys() as [$label, $key]) {
            [$open] = $this->chat()->update(new KeyMsg(KeyType::Char, '?'));
            [$closed] = $open->update($key);

            $this->assertNull($closed->keyHelp(), $label . ' must close the reference');
        }
    }

    public function testAStrayLetterCannotTypeIntoTheInputBoxBehindIt(): void
    {
        [$open] = $this->chat()->update(new KeyMsg(KeyType::Char, '?'));
        [$after] = $open->update(new KeyMsg(KeyType::Char, 'z'));

        $this->assertSame('', $after->inputBuf);
        $this->assertSame(0, $after->keyHelp(), 'and the reference stays up');
    }

    public function testCtrlCStillQuitsWithTheReferenceUp(): void
    {
        [$open] = $this->chat()->update(new KeyMsg(KeyType::Char, '?'));
        [, $cmd] = $open->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));

        $this->assertNotNull($cmd, 'quitting must never require dismissing the modal first');
    }

    public function testArrowsAndPagesScrollAndStopAtTheEnd(): void
    {
        [$chat] = $this->chat()->update(new KeyMsg(KeyType::Char, '?'));
        $chat->view();

        [$down] = $chat->update(new KeyMsg(KeyType::Down));
        $this->assertSame(1, $down->keyHelp());

        [$up] = $down->update(new KeyMsg(KeyType::Up));
        $this->assertSame(0, $up->keyHelp());

        $down->view();
        [$paged] = $down->update(new KeyMsg(KeyType::PageDown));
        $this->assertGreaterThan(1, $paged->keyHelp());

        // Held down past the end: the offset must stop at the last screen
        // rather than running away and leaving the next Up press feeling dead.
        for ($i = 0; $i < 200; $i++) {
            $chat->view();
            [$chat] = $chat->update(new KeyMsg(KeyType::Down));
        }
        $chat->view();
        $this->assertSame(Renderer::keyHelpMaxOffset(), $chat->keyHelp());
        $this->assertGreaterThan(0, Renderer::keyHelpMaxOffset());
    }

    // ── content ──────────────────────────────────────────────────────────

    /**
     * Scrolling from top to bottom must surface every live row. This is the
     * render-side half of the anti-drift contract: a row added to
     * {@see KeyBindingRegistry} that the renderer drops (or a column layout
     * that truncates one away) fails here.
     */
    public function testScrollingThroughItShowsEveryLiveBinding(): void
    {
        [$chat] = $this->chat('', 120, 40)->update(new KeyMsg(KeyType::Char, '?'));

        $seen = '';
        for ($i = 0; $i < 200; $i++) {
            $seen .= $this->body($chat) . "\n";
            [$chat] = $chat->update(new KeyMsg(KeyType::Down));
        }

        foreach (KeyBindingRegistry::live() as $binding) {
            $this->assertStringContainsString(
                $binding->keys,
                $seen,
                $binding->id . ' is documented but never painted',
            );
            $this->assertStringContainsString(
                $binding->description,
                $seen,
                $binding->id . "'s description is never painted in full",
            );
        }
    }

    public function testDormantBindingsAreNotAdvertised(): void
    {
        [$chat] = $this->chat('', 120, 40)->update(new KeyMsg(KeyType::Char, '?'));

        $seen = '';
        for ($i = 0; $i < 200; $i++) {
            $seen .= $this->body($chat) . "\n";
            [$chat] = $chat->update(new KeyMsg(KeyType::Down));
        }

        foreach (KeyBindingRegistry::dormant() as $binding) {
            $this->assertStringNotContainsString(
                $binding->description,
                $seen,
                $binding->id . ' has no effect yet and must not be promised',
            );
        }
    }

    public function testContextHeadingsAreShown(): void
    {
        [$chat] = $this->chat('', 120, 40)->update(new KeyMsg(KeyType::Char, '?'));
        $seen = '';
        for ($i = 0; $i < 200; $i++) {
            $seen .= $this->body($chat) . "\n";
            [$chat] = $chat->update(new KeyMsg(KeyType::Down));
        }

        foreach (array_keys(KeyBindingRegistry::grouped()) as $context) {
            $this->assertStringContainsString($context, $seen);
        }
    }

    public function testTheFooterAdvertisesScrollingOnlyWhenThereIsMoreToSee(): void
    {
        [$tall] = $this->chat('', 120, 40)->update(new KeyMsg(KeyType::Char, '?'));
        $this->assertStringContainsString('Esc closes', $this->body($tall));
        $this->assertStringContainsString('scroll', $this->body($tall));
    }

    // ── render invariants ────────────────────────────────────────────────

    /**
     * The size sweep: 15 widths × 12 heights = **180** sizes, each rendered by
     * {@see testTheOverlayBoxItselfFitsTheTerminal()} and again by
     * {@see testTheReferenceNeverOverflowsTheTerminal()}. On top of that,
     * {@see testItStaysWithinTheTerminalWhileScrolledToTheEnd()} drives 4 sizes
     * through 100 scroll steps each.
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function terminalSizes(): array
    {
        $sizes = [];
        foreach ([4, 5, 8, 12, 20, 24, 30, 40, 55, 72, 80, 100, 120, 160, 200] as $cols) {
            // Down to 3 rows: the box is bounded by rows - 2 so that it cannot
            // land on the status bar, and the bound has no floor — see
            // testItIsNotDrawnAtAllWhenNothingCanFit().
            foreach ([3, 4, 5, 6, 8, 10, 14, 20, 24, 30, 40, 60] as $rows) {
                $sizes[] = [$cols, $rows];
            }
        }

        return $sizes;
    }

    /**
     * The overlay's OWN box, measured before it is composited, must fit the
     * terminal in both directions.
     *
     * Measured separately from the whole frame because the frame comparison
     * has to tolerate the status bar's pre-existing overflow on a very narrow
     * terminal, and that tolerance is wide enough to hide a box that is itself
     * too big. It was: the height bound used to carry a floor that let the box
     * grow to the full frame height on a 3–4 row terminal, land on the status
     * bar, and leave the bar's un-truncated tail hanging past the box's right
     * edge — an over-wide frame line, which is the one thing a renderer that
     * repaints with an absolute cursorTo() may never emit.
     *
     * The sweep is {@see terminalSizes()}'s 15 widths × 12 heights = 180 sizes.
     * 140 of them clear the documented 5×5 floor, and the count of boxes
     * actually produced is asserted against that number rather than against a
     * loose lower bound: "the box got too small to find" and "the box stopped
     * being drawn" are both real regressions, and a `greaterThan` guard reports
     * neither.
     */
    public function testTheOverlayBoxItselfFitsTheTerminal(): void
    {
        $eligible = 0;
        foreach (self::terminalSizes() as [$cols, $rows]) {
            if ($cols >= 5 && $rows >= 5) {
                $eligible++;
            }
        }
        $this->assertSame(140, $eligible, '15 widths x 12 heights, 140 of them at or above the 5x5 floor');

        $measured = 0;
        foreach (self::terminalSizes() as [$cols, $rows]) {
            $box = $this->overlay($this->chat('', $cols, $rows)->withKeyHelp(0));
            if ($box === []) {
                continue;
            }
            $measured++;

            $this->assertLessThanOrEqual(
                $rows - 2,
                count($box),
                "the box must leave the last two rows alone at {$cols}x{$rows}",
            );
            foreach ($box as $line) {
                $this->assertLessThanOrEqual(
                    $cols,
                    Width::of($line),
                    "box line wider than the terminal at {$cols}x{$rows}",
                );
            }
        }

        $this->assertSame(
            $eligible,
            $measured,
            'the box must be drawn at every size the 5x5 floor admits, and at no other',
        );
    }

    /**
     * Below five rows or five columns nothing legible fits, so no box is
     * painted — and the reference stays OPEN, because the state is the user's
     * and only the room to draw it is missing. Growing the terminal reveals it.
     *
     * Stated as "the transcript region is byte-identical to the closed one"
     * rather than "no box was found", so it cannot pass merely because the box
     * got too narrow to recognise. The one line that MUST differ is the status
     * bar: an open reference swallows every keystroke, and a frame that is
     * pixel-for-pixel a closed one while input vanishes reads as a hung app.
     */
    public function testItIsNotDrawnAtAllWhenNothingCanFit(): void
    {
        foreach ([[80, 4], [80, 3], [4, 30], [3, 30]] as [$cols, $rows]) {
            $closed = $this->chat('', $cols, $rows);
            $open = $closed->withKeyHelp(0);

            $closedLines = explode("\n", $this->body($closed));
            $openLines = explode("\n", $this->body($open));

            $this->assertSame(
                array_slice($closedLines, 0, -1),
                array_slice($openLines, 0, -1),
                "nothing may be painted over the transcript at {$cols}x{$rows}",
            );
            $this->assertStringNotContainsString(self::TITLE, $this->body($open));
            $this->assertSame(0, $open->keyHelp(), 'and the reference stays open');

            $bar = end($openLines);
            $this->assertNotSame(end($closedLines), $bar, "the bar must say something at {$cols}x{$rows}");
            $this->assertStringContainsString('window too small', (string) $bar);
            $this->assertLessThanOrEqual(
                Width::of((string) end($closedLines)),
                Width::of((string) $bar),
                'the cue may not be wider than the bar it replaces — the bar is never truncated',
            );
        }

        // One row taller / one column wider than the smallest refusal, it does
        // appear — so the bound is a bound, not the feature quietly never
        // rendering at all.
        foreach ([[80, 5], [5, 30]] as [$cols, $rows]) {
            $closed = $this->chat('', $cols, $rows);

            $this->assertNotSame(
                $this->body($closed),
                $this->body($closed->withKeyHelp(0)),
                "the reference must appear at {$cols}x{$rows}",
            );
        }
    }

    /**
     * The overlay's OWN lines, as `Renderer::renderKeyHelp()` returns them —
     * before Veil composites them onto anything. Empty when nothing is drawn.
     *
     * Taken by reflection rather than carved back out of the finished frame.
     * The extractor this replaced walked the composited frame looking for the
     * title and stopping at the first row starting `╰`, which isolates the box
     * only while the box happens to span the full frame width: measured at
     * rows=30 against a real box 28 rows tall, it returned 28 rows at cols ≤ 68
     * and **4** from cols=69 up — the run was terminating on the input box's
     * border instead — so `assertLessThanOrEqual($rows - 2, count($box))` was
     * comparing 4 against 28 at every ordinary terminal width, 80 and 120
     * included. The bound it is guarding really does hold; the guard did not
     * bite.
     *
     * @return list<string>
     */
    private function overlay(Chat $chat): array
    {
        $rendered = (new \ReflectionMethod(Renderer::class, 'renderKeyHelp'))
            ->invoke(null, $chat, $chat->theme());

        return $rendered === '' ? [] : explode("\n", $rendered);
    }

    /**
     * The reference must never be the thing that makes a frame overflow. The
     * comparison is against the SAME chat with the reference closed, because
     * the status bar is already allowed to exceed a very narrow terminal
     * (pre-existing, {@see Renderer::renderStatusBar()} does not truncate);
     * what this pins is that opening the overlay does not widen the frame
     * past the terminal.
     */
    public function testTheReferenceNeverOverflowsTheTerminal(): void
    {
        foreach (self::terminalSizes() as [$cols, $rows]) {
            $closed = $this->chat('', $cols, $rows);
            $open = $closed->withKeyHelp(0);

            $lines = explode("\n", $this->body($open));

            $this->assertCount($rows, $lines, "frame height at {$cols}x{$rows}");

            $widest = 0;
            foreach ($lines as $line) {
                $widest = max($widest, Width::of($line));
            }

            $baseline = 0;
            foreach (explode("\n", $this->body($closed)) as $line) {
                $baseline = max($baseline, Width::of($line));
            }

            $this->assertLessThanOrEqual(
                max($cols, $baseline),
                $widest,
                "frame width at {$cols}x{$rows}",
            );
        }
    }

    public function testItStaysWithinTheTerminalWhileScrolledToTheEnd(): void
    {
        // Every width here is >= 54, the narrowest at which the status bar
        // itself fits (it is not width-truncated — a pre-existing property of
        // renderStatusBar(), and the reason the sweep above has to allow slack
        // on a narrower terminal). So here $cols is the plain bound.
        foreach ([[54, 10], [55, 6], [80, 24], [120, 40]] as [$cols, $rows]) {
            $chat = $this->chat('', $cols, $rows)->withKeyHelp(0);
            for ($i = 0; $i < 100; $i++) {
                $chat->view();
                [$chat] = $chat->update(new KeyMsg(KeyType::Down));
            }

            $lines = explode("\n", $this->body($chat));
            $this->assertCount($rows, $lines, "frame height at {$cols}x{$rows}, scrolled");
            foreach ($lines as $line) {
                // The plain terminal width, with none of the narrow-terminal
                // slack testTheReferenceNeverOverflowsTheTerminal() has to
                // allow: every size here is wide enough that the status bar
                // fits, so nothing legitimately exceeds $cols.
                $this->assertLessThanOrEqual(
                    $cols,
                    Width::of($line),
                    "frame width at {$cols}x{$rows}, scrolled",
                );
            }

            // Scrolled to the very end, the box still respects the bound that
            // keeps it off the status bar.
            $this->assertLessThanOrEqual($rows - 2, count($this->overlay($chat)));
        }
    }

    /**
     * The overlay slot goes to whichever modal `Chat::update()` routes the next
     * keystroke to — and that is the reference, which is checked immediately
     * after Ctrl+C and therefore ahead of the permission prompt (Chat::update()
     * order, measured: keyHelp → pendingPermission → palette → sessionPicker).
     * Painting the prompt here would put a modal on screen that the keyboard is
     * not driving, which is the one thing the chain in `Renderer::renderView()`
     * exists to prevent.
     *
     * What the precedence may NOT do is hide the prompt in silence: it is a
     * blocking modal that holds the turn `inFlight` and whose own `y`/`n`/`a`
     * are swallowed while the reference is up, so the status bar has to say it
     * is there — {@see testTheBarAnnouncesAPromptTheReferenceIsCovering()}.
     */
    public function testTheReferenceOutranksAPermissionPromptBecauseItOwnsTheKeyboard(): void
    {
        $blocked = $this->blockedOnPermission();

        $frame = $this->body($blocked->withKeyHelp(0));

        $this->assertStringContainsString(self::TITLE, $frame);
        $this->assertStringNotContainsString('permission required', $frame);

        // With the reference closed the prompt has the slot back, so this is a
        // statement about precedence rather than about the prompt never drawing.
        $this->assertStringContainsString('permission required', $this->body($blocked));
    }

    /**
     * A Chat suspended on a permission prompt.
     *
     * Stamped with no generation on purpose: an unstamped ASK is applied
     * whatever the current turn is, which is what every internal caller relies
     * on. {@see testASupersededAskNeverPutsUpAPrompt()} covers the stamped one.
     */
    private function blockedOnPermission(?int $generation = null): Chat
    {
        [$blocked] = $this->chat()->update(new \SugarCraft\Crush\PermissionRequestMsg(
            Message::assistant(''),
            new \SugarCraft\Crush\ToolCall('Bash', ['description' => 'rm'], 'call_1'),
            'Run rm -rf build/?',
            $generation,
        ));
        $this->assertNotNull($blocked->pendingPermission(), 'fixture: the prompt must be up');

        return $blocked;
    }

    /**
     * The cue the precedence above owes the user. The reference wins the slot
     * AND the keyboard, so without this the prompt is invisible, its keys do
     * nothing, and the turn stays `inFlight` — the same "invisible AND silent"
     * state {@see Renderer::KEY_HELP_TOO_SMALL} exists to avoid, reached a
     * different way.
     *
     * The width bound is asserted, not assumed: the bar is the one line the
     * renderer never truncates, so a cue wider than what it replaces would make
     * a narrow terminal overflow by MORE than it already does.
     */
    public function testTheBarAnnouncesAPromptTheReferenceIsCovering(): void
    {
        $blocked = $this->blockedOnPermission();

        foreach ([[100, 30], [80, 24], [54, 10], [20, 30], [5, 30]] as [$cols, $rows]) {
            $sized = $blocked->withSize($cols, $rows);

            $this->assertStringContainsString(
                'permission waiting',
                $this->body($sized->withKeyHelp(0)),
                "the frame must say a prompt is waiting at {$cols}x{$rows}",
            );

            // The bar itself, not the frame's last line: Veil widens the
            // backdrop to the overlay's width, so a composited frame's last
            // line carries padding this comparison is not about.
            $bar = $this->statusBar($sized->withKeyHelp(0));
            $replaced = $this->statusBar($sized);

            $this->assertNotSame($replaced, $bar);
            $this->assertLessThanOrEqual(
                Width::of($replaced),
                Width::of($bar),
                "the cue may not be wider than the bar it replaces at {$cols}x{$rows}"
                . ' — the bar is never truncated',
            );
        }

        // And it is the reference that triggers it: with the reference closed
        // the prompt is on screen and the bar goes back to its readouts.
        $this->assertStringNotContainsString('permission waiting', $this->body($blocked));
    }

    /**
     * The two cues collide in a real state, and the documented priority is
     * which one wins: too-small outranks over-a-prompt.
     *
     * Reachable, not hypothetical — `cols < 5` or `rows < 5` with a prompt
     * pending, which is `renderStatusBar()`'s own condition for the first cue
     * and this fixture's for the second. Unpinned, swapping the two branches
     * changed nothing anywhere in the suite: a 4-column terminal would have
     * announced "permission waiting" while painting no reference at all, which
     * is the less urgent of the two facts and the one the user cannot act on.
     *
     * Domain: the four sizes below, all of them under the documented 5x5 floor,
     * each with a prompt pending AND the reference open — the only state in
     * which both cue conditions hold at once.
     */
    public function testTheTooSmallCueOutranksThePromptCue(): void
    {
        $blocked = $this->blockedOnPermission();

        foreach ([[4, 30], [3, 30], [80, 4], [80, 3]] as [$cols, $rows]) {
            $open = $blocked->withSize($cols, $rows)->withKeyHelp(0);

            // Fixture: both conditions really do hold here, so this is a
            // precedence assertion rather than one cue simply not applying.
            $this->assertNotNull($open->keyHelp(), "fixture: the reference is open at {$cols}x{$rows}");
            $this->assertNotNull($open->pendingPermission(), "fixture: a prompt pends at {$cols}x{$rows}");
            $this->assertSame([], $this->overlay($open), "fixture: nothing fits at {$cols}x{$rows}");

            $bar = $this->statusBar($open);
            $this->assertStringContainsString(
                'window too small',
                $bar,
                "the more urgent cue must win at {$cols}x{$rows}: nothing is painted at all, and a bar "
                . 'that says only "permission waiting" describes a modal the user cannot even see is there',
            );
            $this->assertStringNotContainsString(
                'permission waiting',
                $bar,
                'the bar is one un-wrappable line — two messages on it would not fit, which is why this '
                . 'is a priority and not a concatenation',
            );
        }

        // And with room to paint, the prompt cue is the one that shows — so
        // this is a precedence, not the too-small cue swallowing the other.
        $roomy = $this->statusBar($blocked->withSize(100, 30)->withKeyHelp(0));
        $this->assertStringContainsString('permission waiting', $roomy);
        $this->assertStringNotContainsString('window too small', $roomy);
    }

    /**
     * The status bar as `Renderer` assembles it, with its invisible click-zone
     * sentinels stripped so a width comparison measures what is on screen.
     */
    private function statusBar(Chat $chat): string
    {
        $bar = (new \ReflectionMethod(Renderer::class, 'renderStatusBar'))->invoke(null, $chat);

        return (string) (new \ReflectionMethod(Renderer::class, 'stripZoneMarkers'))->invoke(null, $bar);
    }

    /**
     * `Chat::requestPermission()`'s generation guard, driven.
     *
     * Domain — and it is narrower than this test used to claim: a HAND-BUILT
     * `PermissionRequestMsg`. It has to be. Measured, both internal producers
     * (`beginToolCalls()`, `answerPermission()`) stamp the generation that is
     * current on the object they then call, so no producer in `src/` can emit
     * the stale ASK this covers, and `grep 'new PermissionRequestMsg' src/`
     * finds only those two. Deleting the guard outright turns exactly this one
     * test red — measured across the 252 tests in the three files that build a
     * `PermissionRequestMsg` at all, which is every test that can observe the
     * guard, since the only other way in is a real turn where the comparison is
     * false by construction. That is the honest statement of what this pins:
     * the guard's LOGIC, not a live path.
     *
     * That makes it a dormant-defence test, and worth keeping as one: the engine
     * path `PermissionRequestMsg`'s docblock names is the producer it is for,
     * and the unstamped case below is the one that must keep working when that
     * path lands. What protects a real user from the reference-over-prompt
     * state is not this guard but the status-bar cue —
     * {@see testTheBarAnnouncesAPromptTheReferenceIsCovering()}.
     */
    public function testASupersededAskNeverPutsUpAPrompt(): void
    {
        [$sent] = $this->chat('hello')->update(new KeyMsg(KeyType::Enter));
        $this->assertTrue($sent->inFlight, 'fixture: a turn must be in flight');

        $ask = static fn(?int $generation): \SugarCraft\Crush\PermissionRequestMsg
            => new \SugarCraft\Crush\PermissionRequestMsg(
                Message::assistant(''),
                new \SugarCraft\Crush\ToolCall('Bash', ['description' => 'rm'], 'call_1'),
                'Run rm -rf build/?',
                $generation,
            );

        // Generation 0 is the turn the Enter above superseded.
        [$stale] = $sent->update($ask(0));
        $this->assertNull($stale->pendingPermission(), 'an ASK from a superseded turn is dropped');

        [$current] = $sent->update($ask(1));
        $this->assertNotNull($current->pendingPermission(), 'the current turn is still asked about');

        [$unstamped] = $sent->update($ask(null));
        $this->assertNotNull($unstamped->pendingPermission(), 'an unstamped ASK still applies');
    }

    /**
     * The reference and the palette CAN both be open, so the chain's order
     * matters for this pair too — it is not the unreachable case.
     *
     * The route is the mouse: the box is bounded off the last two rows, so the
     * status bar's `pane:` click zone stays live underneath it, and clicking
     * "Ctrl+P menu" opens the palette with `keyHelp` untouched. The reference
     * then keeps both the slot and the keyboard, which is the invariant
     * {@see Renderer::renderView()}'s chain exists to hold; before the chain was
     * ordered by routing, the palette painted while the reference ate the keys.
     */
    public function testAMouseOpenedPaletteDoesNotTakeTheSlotFromTheReference(): void
    {
        [$open] = $this->chat()->update(new KeyMsg(KeyType::Char, '?'));
        $open->view();

        $zone = null;
        for ($row = 1; $row <= 30 && $zone === null; $row++) {
            for ($col = 1; $col <= 100; $col++) {
                if (Chat::zoneAt($col, $row)?->id === Renderer::PANE_ZONE_PREFIX . 'menu') {
                    $zone = [$col, $row];
                    break;
                }
            }
        }
        $this->assertNotNull($zone, 'fixture: the status bar keeps its pane zone under the overlay');

        [$col, $row] = $zone;
        [$clicked] = $open->update(new MouseClickMsg($col, $row, MouseButton::Left, MouseAction::Press));
        [$clicked] = $clicked->update(new MouseReleaseMsg($col, $row, MouseButton::Left, MouseAction::Release));

        $this->assertNotNull($clicked->palette(), 'the click opened the palette');
        $this->assertSame(0, $clicked->keyHelp(), 'and left the reference open');

        $frame = $this->body($clicked);
        $this->assertStringContainsString(self::TITLE, $frame, 'the reference keeps the slot');
        // renderPalette()'s query line is the palette's own signature.
        $this->assertStringNotContainsString('🔍', $frame, 'and the palette does not paint under it');
    }

    /**
     * The whole overlay chain in {@see Renderer::renderView()}, walked end to
     * end: reference → permission prompt → palette → session picker.
     *
     * Four links make SIX pairs. Two of them are reachable and have tests of
     * their own above; the other four — reference+picker, prompt+palette,
     * prompt+picker, palette+picker — are fixed precedence between modals that
     * cannot coexist, which `Chat::update()` states outright of the last pair
     * ("even though they cannot both be open"). Dormant is not the same as
     * unpinned: a documented order nothing reads back is an order that gets
     * reshuffled by the next person to touch the `if` chain, and swapping the
     * palette and the picker used to change nothing anywhere in this suite.
     *
     * Domain, stated because it is not a user-reachable state: ONE
     * hand-assembled Chat with all four overlays set at 100x30, walked by
     * removing the winner four times. It pins the ordering, not the
     * reachability — the two reachable pairs are what
     * {@see testAMouseOpenedPaletteDoesNotTakeTheSlotFromTheReference()} and
     * {@see testTheReferenceOutranksAPermissionPromptBecauseItOwnsTheKeyboard()}
     * cover through the front door.
     */
    public function testTheOverlayChainPaintsInRoutingOrderRightDownTheChain(): void
    {
        $picker = SessionPicker::new([[
            'sessionId' => 'sess-a',
            'sessionName' => 'alpha',
            'summary' => 'the first one',
            'gitBranch' => 'master',
            'lastActivity' => '2026-01-01T00:00:00+00:00',
        ]]);

        [$withPalette] = $this->chat()->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        $palette = $withPalette->palette();
        $this->assertNotNull($palette, 'fixture: a real palette state to graft on');

        $all = self::graft($this->blockedOnPermission(), [
            'palette' => $palette,
            'sessionPicker' => $picker,
        ])->withKeyHelp(0);

        // Every link is present, so each assertion below is about precedence
        // rather than about the loser not existing.
        $this->assertSame(0, $all->keyHelp());
        $this->assertNotNull($all->pendingPermission());
        $this->assertNotNull($all->palette());
        $this->assertNotNull($all->sessionPicker());

        // Each needle must be unique to the overlay that paints it. The
        // picker's own title is NOT: the reference screen documents a row
        // reading "Open the session picker", so matching on its title would
        // report the picker as painted on every frame the reference wins. Its
        // controls line is the signature that only the picker emits.
        $signature = [
            'the reference' => self::TITLE,
            'the permission prompt' => 'permission required',
            'the palette' => '🔍',
            'the session picker' => '↑↓ browse',
        ];
        $order = array_keys($signature);

        foreach ($order as $position => $winner) {
            $frame = $this->body($all);
            $this->assertStringContainsString(
                $signature[$winner],
                $frame,
                "{$winner} must hold the overlay slot at link {$position} of the chain",
            );
            foreach (array_slice($order, $position + 1) as $loser) {
                $this->assertStringNotContainsString(
                    $signature[$loser],
                    $frame,
                    "{$loser} must not paint while {$winner} holds the slot — Renderer::renderView()'s "
                    . 'chain is Chat::update()\'s routing order, and an overlay the keyboard is not '
                    . 'driving misrepresents what the next key does',
                );
            }

            // Drop the winner and go round again, so the next link is exercised
            // against the same fully-populated state.
            $all = match ($winner) {
                'the reference' => $all->withKeyHelp(null),
                'the permission prompt' => self::graft($all, ['pendingPermission' => null]),
                'the palette' => self::graft($all, ['palette' => null]),
                default => $all,
            };
        }
    }

    /**
     * A Chat with overlay state set DIRECTLY, bypassing the routing that would
     * normally refuse to put two of these up at once.
     *
     * Only justified for pinning a documented precedence between modals that
     * cannot coexist — see
     * {@see testTheOverlayChainPaintsInRoutingOrderRightDownTheChain()}. Every
     * other test in this file goes through the front door.
     *
     * @param array<string, mixed> $overlays
     */
    private static function graft(Chat $chat, array $overlays): Chat
    {
        $mutate = new \ReflectionMethod(Chat::class, 'mutate');

        return $mutate->invoke($chat, $overlays);
    }

    /**
     * The scroll ceiling {@see Chat::withKeyHelp()} clamps against belongs to
     * the LAST painted reference, so it has to be cleared by any frame that
     * does not paint one — including a frame where a different overlay has the
     * slot. Reaching `renderKeyHelp()` first in the chain is what guarantees
     * that; this is the property, not the ordering.
     */
    public function testTheScrollCeilingDoesNotSurviveAFrameWithoutTheReference(): void
    {
        [$open] = $this->chat()->update(new KeyMsg(KeyType::Char, '?'));
        $open->view();
        $this->assertGreaterThan(0, Renderer::keyHelpMaxOffset(), 'fixture: the list must overflow');

        [$closed] = $open->update(new KeyMsg(KeyType::Escape));
        [$palette] = $closed->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        $this->assertNotNull($palette->palette(), 'fixture: another overlay must hold the slot');
        $palette->view();

        $this->assertSame(0, Renderer::keyHelpMaxOffset());
    }

    /**
     * The narrow-box column split, which was documented backwards and untested:
     * the KEY is truncated so the description keeps a column, not the other way
     * round. Deleting `renderKeyHelp()`'s `min($keyCol, max(1, $width - 2))`
     * leaves the box the same size — `Style::width()` truncates the assembled
     * line rather than wrapping it — so nothing else in this file notices; only
     * the split changes, and this is what reads it.
     *
     * Expected values are derived from the registry rather than spelled out, so
     * a reworded first row does not make this fail for the wrong reason.
     */
    public function testANarrowBoxTruncatesTheKeyToKeepAColumnOfDescription(): void
    {
        $first = KeyBindingRegistry::live()[0];

        // cols=8: content width 4 (8 minus the box's own 4 columns of chrome),
        // so the key field is capped at 2 and the description gets 1.
        $box = $this->overlay($this->chat('', 8, 30)->withKeyHelp(0));
        $this->assertNotSame([], $box, 'fixture: the box must be drawn at 8 columns');

        $rows = array_map(
            static fn(string $line): string => (string) preg_replace('/\e\[[0-9;]*m/', '', $line),
            $box,
        );

        $key = Width::truncate($first->keys, 2);
        $desc = Width::truncate($first->description, 1);
        $this->assertContains(
            '│ ' . $key . ' ' . $desc . ' │',
            $rows,
            'the key must lose characters while the description keeps its column',
        );

        // Wide enough for both in full, as the same row read at 100 columns.
        $wide = implode("\n", $this->overlay($this->chat('', 100, 40)->withKeyHelp(0)));
        $this->assertStringContainsString($first->keys, $wide);
        $this->assertStringContainsString($first->description, $wide);
    }

    /**
     * {@see Renderer::KEY_HELP_CHROME_COLS}'s stated derivation — 2 border
     * columns plus the 2 of `padding(0, 1)` — made checkable: the box the
     * renderer draws is exactly that much wider than the content width
     * `keyHelpGeometry()` hands `Style::width()`.
     *
     * Raising the constant to 6 fails here (the box comes out 4 wider than a
     * content width computed with 6), which is what the old `> 50` style of
     * bound could not catch.
     */
    public function testTheBoxGrowsPastItsContentWidthByExactlyTheDeclaredChrome(): void
    {
        $chrome = (new \ReflectionClass(Renderer::class))->getConstant('KEY_HELP_CHROME_COLS');
        $max = (new \ReflectionClass(Renderer::class))->getConstant('KEY_HELP_COLS');
        $this->assertIsInt($chrome);
        $this->assertIsInt($max);

        foreach ([20, 40, 68, 80, 120, 200] as $cols) {
            $box = $this->overlay($this->chat('', $cols, 40)->withKeyHelp(0));
            $this->assertNotSame([], $box, "fixture: a box at {$cols} columns");

            $widest = 0;
            foreach ($box as $line) {
                $widest = max($widest, Width::of($line));
            }

            $this->assertSame(
                min($max, $cols - $chrome) + $chrome,
                $widest,
                "the box at {$cols} columns must be its content width plus exactly {$chrome}",
            );
        }
    }

    public function testWithKeyHelpClampsANegativeOffsetToTheTop(): void
    {
        $this->assertSame(0, $this->chat()->withKeyHelp(-5)->keyHelp());
    }

    /**
     * A clamped press that cannot move hands back the SAME Chat, the way
     * `scrollBy()` does — otherwise holding Down at the end of the list
     * allocates a model and diffs a frame per dead notch.
     */
    public function testADeadPressAtTheEndDoesNotAllocateANewChat(): void
    {
        [$chat] = $this->chat()->update(new KeyMsg(KeyType::Char, '?'));
        for ($i = 0; $i < 200; $i++) {
            $chat->view();
            [$chat] = $chat->update(new KeyMsg(KeyType::Down));
        }
        $chat->view();
        $this->assertSame(Renderer::keyHelpMaxOffset(), $chat->keyHelp(), 'fixture: parked at the end');

        [$again] = $chat->update(new KeyMsg(KeyType::Down));
        $this->assertSame($chat, $again);
    }

    /**
     * The wheel drives the reference while it is up, not the transcript behind
     * it: scrolling something the user cannot see is the same defect
     * `handleKeyHelpKey()` swallows stray letters to avoid.
     */
    public function testTheWheelScrollsTheReferenceAndNotTheHiddenTranscript(): void
    {
        $history = [];
        for ($i = 0; $i < 200; $i++) {
            $history[] = Message::user('line ' . $i);
        }
        $chat = (new Chat(history: $history, backend: new EchoBackend()))->withSize(100, 30);
        [$open] = $chat->update(new KeyMsg(KeyType::Char, '?'));
        $open->view();

        [$down] = $open->update(new MouseWheelMsg(1, 1, MouseButton::WheelDown, MouseAction::Press));
        $this->assertGreaterThan(0, $down->keyHelp(), 'wheel-down moves further into the list');
        $this->assertSame(0, $down->scrollOffset(), 'and leaves the hidden transcript alone');

        $down->view();
        [$up] = $down->update(new MouseWheelMsg(1, 1, MouseButton::WheelUp, MouseAction::Press));
        $this->assertSame(0, $up->keyHelp(), 'wheel-up comes back towards the first row');
        $this->assertSame(0, $up->scrollOffset());

        // With the reference closed the wheel is the transcript's again.
        $chat->view();
        [$scrolled] = $chat->update(new MouseWheelMsg(1, 1, MouseButton::WheelUp, MouseAction::Press));
        $this->assertGreaterThan(0, $scrolled->scrollOffset());
    }
}
