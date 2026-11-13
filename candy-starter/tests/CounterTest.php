<?php

declare(strict_types=1);

namespace App\Tests;

use App\Counter;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use PHPUnit\Framework\TestCase;

final class CounterTest extends TestCase
{
    /**
     * Byte-exact golden frame for new Counter(0)->view() — captured once
     * from the shipped Style::new()->border(Border::rounded())->padding(1, 2)
     * render (456 bytes, no trailing newline), then pinned. Any change to
     * border glyphs, padding, hint text, or spacing must redden
     * testTheFrameIsThePinnedGoldenBytes (candy-starter-034: this is the
     * refutable snapshot the twice-call "stability" check could not be).
     */
    private const GOLDEN_FRAME =
        '╭─────────────────────────────────────────╮' . "\n" .
        '│                                         │' . "\n" .
        '│  count: 0                               │' . "\n" .
        '│  ↑ ↓ to change · q / Esc / Ctrl+C quit  │' . "\n" .
        '│                                         │' . "\n" .
        '╰─────────────────────────────────────────╯';

    // -------------------------------------------------------------------------
    // Core state tests
    // -------------------------------------------------------------------------

    public function testStartsAtZero(): void
    {
        $this->assertSame(0, (new Counter())->n);
    }

    public function testConstructorAcceptsCustomInitialValue(): void
    {
        $counter = new Counter(99);
        $this->assertSame(99, $counter->n);
    }

    public function testUpIncrementsCount(): void
    {
        [$next, $cmd] = (new Counter())->update(new KeyMsg(KeyType::Up, ''));
        $this->assertInstanceOf(Counter::class, $next);
        $this->assertSame(1, $next->n);
        $this->assertNull($cmd);
    }

    public function testDownDecrementsCount(): void
    {
        [$next, $cmd] = (new Counter(5))->update(new KeyMsg(KeyType::Down, ''));
        $this->assertSame(4, $next->n);
        $this->assertNull($cmd);
    }

    // -------------------------------------------------------------------------
    // Quit key combinations
    // -------------------------------------------------------------------------

    public function testQuitDispatchesQuitCmd(): void
    {
        [$next, $cmd] = (new Counter(7))->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertInstanceOf(Counter::class, $next);
        $this->assertSame(7, $next->n, 'quit must not mutate count');
        // Cmd::quit() is a pure closure whose dispatch produces QuitMsg —
        // assertNotNull would also pass on a Tick closure or any stray
        // callable; pin the shape the harness actually feeds (034).
        $this->assertInstanceOf(\Closure::class, $cmd, 'quit returns Cmd::quit()');
        $this->assertInstanceOf(QuitMsg::class, $cmd(), 'the quit Cmd must dispatch a QuitMsg');
    }

    public function testCtrlCDispatchesQuitCmd(): void
    {
        [$next, $cmd] = (new Counter(7))->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));
        $this->assertInstanceOf(Counter::class, $next);
        $this->assertSame(7, $next->n, 'ctrl+c must not mutate count');
        $this->assertInstanceOf(\Closure::class, $cmd, 'ctrl+c returns Cmd::quit()');
        $this->assertInstanceOf(QuitMsg::class, $cmd(), 'the ctrl+c Cmd must dispatch a QuitMsg');
    }

    public function testEscDispatchesQuitCmd(): void
    {
        [$next, $cmd] = (new Counter(7))->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertInstanceOf(Counter::class, $next);
        $this->assertSame(7, $next->n, 'Esc must not mutate count');
        $this->assertInstanceOf(\Closure::class, $cmd, 'Esc returns Cmd::quit()');
        $this->assertInstanceOf(QuitMsg::class, $cmd(), 'the Esc Cmd must dispatch a QuitMsg');
    }

    public function testCtrlEscStillQuitsBecauseTheEscapeArmIgnoresModifiers(): void
    {
        // candy-starter-035 matrix cell the docblock advertised but the
        // suite never pinned: `Esc under any modifier` — ctrl+Esc quits.
        [$next, $cmd] = (new Counter(7))->update(new KeyMsg(KeyType::Escape, '', ctrl: true));
        $this->assertSame(7, $next->n);
        $this->assertInstanceOf(QuitMsg::class, $cmd());
    }

    public function testCtrlAltQDoesNotQuitBecauseCtrlDisqualifiesTheQArm(): void
    {
        // The third advertised modifier cell, pinned to the CODE truth:
        // the q-arm requires ctrl === false, so ctrl strips q of its quit
        // power even with alt riding along. (The review finding sketched
        // this cell as quitting; the shipped guard and its docblock both
        // say otherwise, and disk governs.)
        [$next, $cmd] = (new Counter(7))->update(new KeyMsg(KeyType::Char, 'q', alt: true, ctrl: true));
        $this->assertSame(7, $next->n);
        $this->assertNull($cmd, 'ctrl must disqualify the q arm under any modifier mix');
    }

    public function testAltShiftQQuitsUnderTheAnyModifierExceptCtrlRule(): void
    {
        [$next, $cmd] = (new Counter(7))->update(new KeyMsg(KeyType::Char, 'q', alt: true, shift: true));
        $this->assertSame(7, $next->n);
        $this->assertInstanceOf(QuitMsg::class, $cmd(), 'q with alt+shift and no ctrl must quit');
    }

    public function testNonCharCtrlCDoesNotQuitAfterSymmetricGuard(): void
    {
        // candy-starter-022: the ctrl+c arm once omitted the Char-type
        // conjunct, so a decoder that populates runes on named keys could
        // hand it KeyMsg(Enter, 'c', ctrl: true) and the demo would quit
        // on Enter. With the symmetric guard the misfire is dead — and
        // THIS is the pin that keeps it dead.
        [$next, $cmd] = (new Counter(7))->update(new KeyMsg(KeyType::Enter, 'c', ctrl: true));
        $this->assertSame(7, $next->n);
        $this->assertNull($cmd, 'the quit path must require the Char type on both rune arms');
    }

    // -------------------------------------------------------------------------
    // Non-quit character keys
    // -------------------------------------------------------------------------

    public function testCharCWithoutCtrlDoesNotQuit(): void
    {
        [$next, $cmd] = (new Counter(5))->update(new KeyMsg(KeyType::Char, 'c'));
        $this->assertSame(5, $next->n, 'plain c must not change count');
        $this->assertNull($cmd, 'plain c must not dispatch quit');
    }

    public function testCtrlQDoesNotQuit(): void
    {
        // Only ctrl+c triggers quit, not ctrl+q
        [$next, $cmd] = (new Counter(5))->update(new KeyMsg(KeyType::Char, 'q', ctrl: true));
        $this->assertSame(5, $next->n, 'ctrl+q must not change count');
        $this->assertNull($cmd, 'ctrl+q must not dispatch quit');
    }

    public function testPlainCharKeysDoNotChangeCount(): void
    {
        // Any non-q char with no modifiers should not change state
        foreach (['a', 'b', 'x', 'z', '1', ' '] as $rune) {
            $counter = new Counter(5);
            [$next, $cmd] = $counter->update(new KeyMsg(KeyType::Char, $rune));
            $this->assertSame(5, $next->n, "char '$rune' must not change count");
            $this->assertNull($cmd, "char '$rune' must not dispatch cmd");
        }
    }

    public function testCtrlAPlusrDoesNotQuit(): void
    {
        // ctrl+a is not the quit combination
        [$next, $cmd] = (new Counter(5))->update(new KeyMsg(KeyType::Char, 'a', ctrl: true));
        $this->assertSame(5, $next->n);
        $this->assertNull($cmd);
    }

    // -------------------------------------------------------------------------
    // Non-quit navigation/function keys
    // -------------------------------------------------------------------------

    public function testLeftKeyDoesNotChangeCount(): void
    {
        [$next, $cmd] = (new Counter(5))->update(new KeyMsg(KeyType::Left, ''));
        $this->assertSame(5, $next->n);
        $this->assertNull($cmd);
    }

    public function testRightKeyDoesNotChangeCount(): void
    {
        [$next, $cmd] = (new Counter(5))->update(new KeyMsg(KeyType::Right, ''));
        $this->assertSame(5, $next->n);
        $this->assertNull($cmd);
    }

    public function testEnterKeyDoesNotChangeCount(): void
    {
        [$next, $cmd] = (new Counter(5))->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame(5, $next->n);
        $this->assertNull($cmd);
    }

    public function testTabKeyDoesNotChangeCount(): void
    {
        [$next, $cmd] = (new Counter(5))->update(new KeyMsg(KeyType::Tab, ''));
        $this->assertSame(5, $next->n);
        $this->assertNull($cmd);
    }

    public function testSpaceKeyDoesNotChangeCount(): void
    {
        [$next, $cmd] = (new Counter(5))->update(new KeyMsg(KeyType::Space, ''));
        $this->assertSame(5, $next->n);
        $this->assertNull($cmd);
    }

    public function testBackspaceDoesNotChangeCount(): void
    {
        [$next, $cmd] = (new Counter(5))->update(new KeyMsg(KeyType::Backspace, ''));
        $this->assertSame(5, $next->n);
        $this->assertNull($cmd);
    }

    public function testDeleteDoesNotChangeCount(): void
    {
        [$next, $cmd] = (new Counter(5))->update(new KeyMsg(KeyType::Delete, ''));
        $this->assertSame(5, $next->n);
        $this->assertNull($cmd);
    }

    public function testHomeKeyDoesNotChangeCount(): void
    {
        [$next, $cmd] = (new Counter(5))->update(new KeyMsg(KeyType::Home, ''));
        $this->assertSame(5, $next->n);
        $this->assertNull($cmd);
    }

    public function testEndKeyDoesNotChangeCount(): void
    {
        [$next, $cmd] = (new Counter(5))->update(new KeyMsg(KeyType::End, ''));
        $this->assertSame(5, $next->n);
        $this->assertNull($cmd);
    }

    public function testPageUpDoesNotChangeCount(): void
    {
        [$next, $cmd] = (new Counter(5))->update(new KeyMsg(KeyType::PageUp, ''));
        $this->assertSame(5, $next->n);
        $this->assertNull($cmd);
    }

    public function testPageDownDoesNotChangeCount(): void
    {
        [$next, $cmd] = (new Counter(5))->update(new KeyMsg(KeyType::PageDown, ''));
        $this->assertSame(5, $next->n);
        $this->assertNull($cmd);
    }

    public function testFunctionKeysDoNotChangeCount(): void
    {
        foreach ([KeyType::F1, KeyType::F2, KeyType::F12] as $keyType) {
            $counter = new Counter(5);
            [$next, $cmd] = $counter->update(new KeyMsg($keyType, ''));
            $this->assertSame(5, $next->n, "{$keyType->value} must not change count");
            $this->assertNull($cmd);
        }
    }

    // -------------------------------------------------------------------------
    // Non-key messages
    // -------------------------------------------------------------------------

    public function testNonKeyMessageIgnored(): void
    {
        [$next, $cmd] = (new Counter(3))->update(new WindowSizeMsg(80, 24));
        $this->assertSame(3, $next->n);
        $this->assertNull($cmd);
    }

    // -------------------------------------------------------------------------
    // Model contract methods
    // -------------------------------------------------------------------------

    public function testInitReturnsNoCmd(): void
    {
        $this->assertNull((new Counter())->init());
    }

    public function testSubscriptionsReturnsNull(): void
    {
        $this->assertNull((new Counter())->subscriptions());
    }

    // -------------------------------------------------------------------------
    // View rendering
    // -------------------------------------------------------------------------

    public function testViewContainsCount(): void
    {
        $view = (new Counter(42))->view();
        $this->assertStringContainsString('42', $view);
        $this->assertStringContainsString('q / Esc / Ctrl+C quit', $view);
    }

    public function testViewRendersStyledBorder(): void
    {
        $view = (new Counter(42))->view();
        // Rounded border corner glyphs must be present
        $this->assertStringContainsString("\u{256d}", $view, 'top-left corner ╭ missing');
        $this->assertStringContainsString("\u{2570}", $view, 'bottom-left corner ╰ missing');
        $this->assertStringContainsString('42', $view);
    }

    public function testTheFrameIsThePinnedGoldenBytes(): void
    {
        // candy-starter-034: the byte-exact replacement for the twice-call
        // "stability" check, which could not disagree with anything — two
        // calls of a deterministic function agree even when both are
        // wrong. This one disagrees with every drift in border glyphs,
        // padding, hint text, spacing, or a newly-introduced colour:
        // flip any corner byte and the suite reddens.
        $this->assertSame(self::GOLDEN_FRAME, (new Counter(0))->view());
    }

    public function testTheFrameDecorationIsMemoizedNotRebuiltPerFrame(): void
    {
        // CALIBER [bug:candy-starter-style-rebuild] closed 2026-11-03:
        // Style is immutable, so a per-frame `Style::new()->…` chain in
        // the most-copied function taught the anti-pattern its own ledger
        // disowned. Exactly one construction site, behind the static ??=.
        $source = (string) file_get_contents(dirname(__DIR__) . '/src/Counter.php');

        $this->assertSame(
            1,
            substr_count($source, 'Style::new()'),
            'view() must render through the memoized frameStyle() — no per-frame Style rebuild may return',
        );
        $this->assertStringContainsString('self::$frameStyle ??=', $source, 'the memoization arm is the point of the pattern');
    }

    public function testViewContainsNavigationHints(): void
    {
        $view = (new Counter())->view();
        $this->assertStringContainsString('↑', $view, 'view must show up arrow hint');
        $this->assertStringContainsString('↓', $view, 'view must show down arrow hint');
    }

    public function testViewContainsQuitHint(): void
    {
        $view = (new Counter())->view();
        $this->assertStringContainsString('q / Esc / Ctrl+C quit', $view, 'the hint must enumerate every shipped exit path, not just one');
    }

    // -------------------------------------------------------------------------
    // Immutability
    // -------------------------------------------------------------------------

    public function testUpdateIsPure(): void
    {
        $start = new Counter(10);
        $start->update(new KeyMsg(KeyType::Up, ''));
        $this->assertSame(10, $start->n, 'original Counter must remain unchanged');
    }

    public function testMultipleUpdatesProduceDistinctInstances(): void
    {
        $c1 = new Counter(0);
        [$c2, ] = $c1->update(new KeyMsg(KeyType::Up, ''));
        [$c3, ] = $c2->update(new KeyMsg(KeyType::Up, ''));
        [$c4, ] = $c3->update(new KeyMsg(KeyType::Down, ''));

        $this->assertNotSame($c1, $c2, 'each update returns a new instance');
        $this->assertNotSame($c2, $c3);
        $this->assertNotSame($c3, $c4);

        $this->assertSame(0, $c1->n);
        $this->assertSame(1, $c2->n);
        $this->assertSame(2, $c3->n);
        $this->assertSame(1, $c4->n);
    }

    // -------------------------------------------------------------------------
    // Edge cases: large and negative values
    // -------------------------------------------------------------------------

    public function testLargePositiveCount(): void
    {
        $counter = new Counter(1_000_000);
        [$next, ] = $counter->update(new KeyMsg(KeyType::Up, ''));
        $this->assertSame(1_000_001, $next->n);
    }

    public function testLargeNegativeCount(): void
    {
        $counter = new Counter(-1_000_000);
        [$next, ] = $counter->update(new KeyMsg(KeyType::Down, ''));
        $this->assertSame(-1_000_001, $next->n);
    }

    public function testZeroCountCanGoNegative(): void
    {
        $counter = new Counter(0);
        [$next, ] = $counter->update(new KeyMsg(KeyType::Down, ''));
        $this->assertSame(-1, $next->n);
    }

    public function testZeroCountCanIncrement(): void
    {
        $counter = new Counter(0);
        [$next, ] = $counter->update(new KeyMsg(KeyType::Up, ''));
        $this->assertSame(1, $next->n);
    }

    // -------------------------------------------------------------------------
    // Quit-adjacent modifier keys (the ctrl-excluded / rune-excluded combos —
    // note alt+q and any-modifier Esc DO quit, per the arms below)
    // -------------------------------------------------------------------------

    public function testAltQTriggersQuit(): void
    {
        // alt+q triggers quit because the model only excludes ctrl, not alt
        [$next, $cmd] = (new Counter(5))->update(new KeyMsg(KeyType::Char, 'q', alt: true));
        $this->assertSame(5, $next->n, 'alt+q must not mutate count');
        $this->assertNotNull($cmd, 'alt+q must dispatch quit');
    }

    public function testShiftQDoesNotQuit(): void
    {
        // Shift+q produces 'Q' which is not 'q'
        [$next, $cmd] = (new Counter(5))->update(new KeyMsg(KeyType::Char, 'Q', shift: true));
        $this->assertSame(5, $next->n);
        $this->assertNull($cmd);
    }

    public function testCtrlCharThatIsNotCOrQ(): void
    {
        // Only ctrl+a through ctrl+z map to ASCII 1-26, but only ctrl+c (ASCII 3) triggers quit
        foreach (['d', 'e', 'f', 'n', 'x', 'z'] as $rune) {
            $counter = new Counter(5);
            [$next, $cmd] = $counter->update(new KeyMsg(KeyType::Char, $rune, ctrl: true));
            $this->assertSame(5, $next->n, "ctrl+$rune must not change count");
            $this->assertNull($cmd, "ctrl+$rune must not dispatch quit");
        }
    }

    // -------------------------------------------------------------------------
    // Additional edge cases and key combinations
    // -------------------------------------------------------------------------

    public function testCtrlSpaceDoesNotQuit(): void
    {
        // ctrl+space is not a quit combination
        [$next, $cmd] = (new Counter(5))->update(new KeyMsg(KeyType::Char, ' ', ctrl: true));
        $this->assertSame(5, $next->n);
        $this->assertNull($cmd);
    }

    public function testCtrlEnterDoesNotQuit(): void
    {
        // ctrl+enter is not a quit combination
        [$next, $cmd] = (new Counter(5))->update(new KeyMsg(KeyType::Enter, '', ctrl: true));
        $this->assertSame(5, $next->n);
        $this->assertNull($cmd);
    }

    public function testCtrlBackspaceDoesNotQuit(): void
    {
        [$next, $cmd] = (new Counter(5))->update(new KeyMsg(KeyType::Backspace, '', ctrl: true));
        $this->assertSame(5, $next->n);
        $this->assertNull($cmd);
    }

    public function testCtrlTabDoesNotQuit(): void
    {
        [$next, $cmd] = (new Counter(5))->update(new KeyMsg(KeyType::Tab, '', ctrl: true));
        $this->assertSame(5, $next->n);
        $this->assertNull($cmd);
    }

    public function testAltEnterDoesNotQuit(): void
    {
        [$next, $cmd] = (new Counter(5))->update(new KeyMsg(KeyType::Enter, '', alt: true));
        $this->assertSame(5, $next->n);
        $this->assertNull($cmd);
    }

    public function testAltEscapeStillQuits(): void
    {
        // shouldQuit() matches Escape regardless of modifiers, so Alt+Escape quits
        [$next, $cmd] = (new Counter(5))->update(new KeyMsg(KeyType::Escape, '', alt: true));
        $this->assertSame(5, $next->n, 'alt+escape should not change count');
        $this->assertNotNull($cmd, 'alt+escape still triggers quit via escape');
    }

    public function testShiftEscapeStillQuits(): void
    {
        // same Escape arm, other modifier — Shift+Escape quits too
        [$next, $cmd] = (new Counter(5))->update(new KeyMsg(KeyType::Escape, '', shift: true));
        $this->assertSame(5, $next->n);
        $this->assertNotNull($cmd, 'escape alone still triggers quit');
    }

    public function testUpDownAlternation(): void
    {
        $counter = new Counter(0);
        [$counter, ] = $counter->update(new KeyMsg(KeyType::Up, ''));
        $this->assertSame(1, $counter->n);
        [$counter, ] = $counter->update(new KeyMsg(KeyType::Down, ''));
        $this->assertSame(0, $counter->n);
        [$counter, ] = $counter->update(new KeyMsg(KeyType::Down, ''));
        $this->assertSame(-1, $counter->n);
        [$counter, ] = $counter->update(new KeyMsg(KeyType::Up, ''));
        $this->assertSame(0, $counter->n);
    }

    public function testMultipleQuitKeysReturnSameInstance(): void
    {
        $counter = new Counter(5);
        [$next1, $cmd1] = $counter->update(new KeyMsg(KeyType::Char, 'q'));
        [$next2, $cmd2] = $next1->update(new KeyMsg(KeyType::Escape, ''));
        // Both return Cmd::quit() but instance should be preserved
        $this->assertNotNull($cmd1);
        $this->assertNotNull($cmd2);
        $this->assertSame(5, $next1->n);
        $this->assertSame(5, $next2->n);
    }

    public function testViewOutputContainsCountInMultiplePositions(): void
    {
        $view = (new Counter(42))->view();
        // Count appears in the body of the view
        $this->assertStringContainsString('42', $view);
    }

    public function testViewContainsBothArrowCharacters(): void
    {
        $view = (new Counter())->view();
        // Contains up arrow
        $this->assertStringContainsString('↑', $view);
        // Contains down arrow
        $this->assertStringContainsString('↓', $view);
    }

    public function testViewDoesNotContainOtherArrowDirections(): void
    {
        $view = (new Counter())->view();
        // Left and right arrows should not appear in default view
        $this->assertStringNotContainsString('←', $view);
        $this->assertStringNotContainsString('→', $view);
    }

    public function testIntegerBoundaryPositive(): void
    {
        // PHP_INT_MAX - 1 to stay one step inside the boundary: overflow
        // would yield a float, and storing it in the promoted int property
        // under strict_types is an uncaught TypeError, not a silent decay
        // (CALIBER [gotcha:candy-starter-overflow-teaching]). The assertSame
        // already pins the int — a trailing assertIsInt could only echo it
        // (candy-starter-034), so the boundary's real teaching lives in the
        // one-step-over child-process pin below.
        $counter = new Counter(PHP_INT_MAX - 1);
        [$next, ] = $counter->update(new KeyMsg(KeyType::Up, ''));
        $this->assertSame(PHP_INT_MAX, $next->n);
    }

    public function testIntegerBoundaryNegative(): void
    {
        // PHP_INT_MIN + 1 to stay one step inside the boundary: the
        // decrement would overflow to float and TypeError against the
        // promoted int property long before any silent decay.
        $counter = new Counter(PHP_INT_MIN + 1);
        [$next, ] = $counter->update(new KeyMsg(KeyType::Down, ''));
        $this->assertSame(PHP_INT_MIN, $next->n);
    }

    public function testSteppingOverTheBoundaryFatalsInTheChildProcess(): void
    {
        // candy-starter-034's refutable half: one step past PHP_INT_MAX
        // must be a loud TypeError against the promoted int property — a
        // clamp or a silent float decay would make this child exit 0 and
        // the suite reddens. It runs as a child because an uncaught fatal
        // in-process would take the test runner down with the evidence.
        $script = sys_get_temp_dir() . '/st-overflow-' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($script, "<?php\ndeclare(strict_types=1);\nrequire "
            . var_export(realpath(__DIR__ . '/../vendor/autoload.php'), true) . ";\n"
            . '$counter = new App\Counter(PHP_INT_MAX);'
            . '$counter->update(new SugarCraft\Core\Msg\KeyMsg(SugarCraft\Core\KeyType::Up, ""));'
            . "echo 'unreachable\n';\n");

        $out = [];
        $status = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr ' . escapeshellarg($script) . ' 2>&1', $out, $status);
        @unlink($script);

        $message = implode("\n", $out);
        $this->assertSame(255, $status, 'stepping over the boundary must fatal, not return: ' . $message);
        $this->assertStringContainsString('TypeError', $message);
        $this->assertStringNotContainsString('unreachable', $message, 'the fatal must land before the next statement');
    }

    public function testViewRenderingDoesNotAffectState(): void
    {
        $counter = new Counter(5);
        $view1 = $counter->view();
        $view2 = $counter->view();
        $view3 = $counter->view();
        // Multiple view calls should not mutate state
        $this->assertSame($view1, $view2);
        $this->assertSame($view2, $view3);
        [$next, ] = $counter->update(new KeyMsg(KeyType::Up, ''));
        $this->assertSame(5, $counter->n, 'view() must not mutate counter');
        $this->assertSame(6, $next->n);
    }

    public function testEmptyRuneWithCharType(): void
    {
        // Char type with empty rune should not trigger quit or change count
        [$next, $cmd] = (new Counter(5))->update(new KeyMsg(KeyType::Char, ''));
        $this->assertSame(5, $next->n);
        $this->assertNull($cmd);
    }

    public function testNumbersAsRunes(): void
    {
        // Number keys should not change count
        foreach (['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'] as $rune) {
            $counter = new Counter(5);
            [$next, $cmd] = $counter->update(new KeyMsg(KeyType::Char, $rune));
            $this->assertSame(5, $next->n, "digit '$rune' must not change count");
            $this->assertNull($cmd);
        }
    }

    public function testSymbolRunes(): void
    {
        // Symbol keys should not change count
        foreach (['!', '@', '#', '$', '%', '^', '&', '*', '(', ')', '-', '=', '+', '[', ']', '{', '}', '|', ';', ':', "'", '"', ',', '.', '/', '?', '<', '>'] as $rune) {
            $counter = new Counter(5);
            [$next, $cmd] = $counter->update(new KeyMsg(KeyType::Char, $rune));
            $this->assertSame(5, $next->n, "symbol '$rune' must not change count");
            $this->assertNull($cmd);
        }
    }
}
