<?php

declare(strict_types=1);

namespace App\Tests;

use App\Counter;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use PHPUnit\Framework\TestCase;

final class CounterTest extends TestCase
{
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
        $this->assertNotNull($cmd, 'quit returns Cmd::quit()');
    }

    public function testCtrlCDispatchesQuitCmd(): void
    {
        [$next, $cmd] = (new Counter(7))->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));
        $this->assertInstanceOf(Counter::class, $next);
        $this->assertSame(7, $next->n, 'ctrl+c must not mutate count');
        $this->assertNotNull($cmd, 'ctrl+c returns Cmd::quit()');
    }

    public function testEscDispatchesQuitCmd(): void
    {
        [$next, $cmd] = (new Counter(7))->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertInstanceOf(Counter::class, $next);
        $this->assertSame(7, $next->n, 'Esc must not mutate count');
        $this->assertNotNull($cmd, 'Esc returns Cmd::quit()');
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

    public function testViewReturnsString(): void
    {
        $view = (new Counter())->view();
        $this->assertIsString($view);
    }

    public function testViewContainsCount(): void
    {
        $view = (new Counter(42))->view();
        $this->assertStringContainsString('42', $view);
        $this->assertStringContainsString('q to quit', $view);
    }

    public function testViewRendersStyledBorder(): void
    {
        $view = (new Counter(42))->view();
        // Rounded border corner glyphs must be present
        $this->assertStringContainsString("\u{256d}", $view, 'top-left corner ╭ missing');
        $this->assertStringContainsString("\u{2570}", $view, 'bottom-left corner ╰ missing');
        $this->assertStringContainsString('42', $view);
    }

    public function testViewOutputStable(): void
    {
        // Calling view() multiple times returns consistent output
        $counter = new Counter(10);
        $view1 = $counter->view();
        $view2 = $counter->view();
        $this->assertSame($view1, $view2, 'view() output must be deterministic');
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
        $this->assertStringContainsString('q to quit', $view, 'view must show quit hint');
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
    // Quit with modifier keys (alt/ctrl combos that should NOT quit)
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

    public function testAltEscapeDoesNotQuit(): void
    {
        // Alt+Escape is not a quit combination (Escape alone quits)
        [$next, $cmd] = (new Counter(5))->update(new KeyMsg(KeyType::Escape, '', alt: true));
        $this->assertSame(5, $next->n, 'alt+escape should not change count');
        $this->assertNotNull($cmd, 'alt+escape still triggers quit via escape');
    }

    public function testShiftEscapeDoesNotQuit(): void
    {
        // Shift+Escape is not a quit combination
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
        // PHP_INT_MAX - 1 to avoid overflow (PHP integer math wraps to float on overflow)
        $counter = new Counter(PHP_INT_MAX - 1);
        [$next, ] = $counter->update(new KeyMsg(KeyType::Up, ''));
        $this->assertSame(PHP_INT_MAX, $next->n);
        $this->assertIsInt($next->n);
    }

    public function testIntegerBoundaryNegative(): void
    {
        // PHP_INT_MIN + 1 to avoid overflow
        $counter = new Counter(PHP_INT_MIN + 1);
        [$next, ] = $counter->update(new KeyMsg(KeyType::Down, ''));
        $this->assertSame(PHP_INT_MIN, $next->n);
        $this->assertIsInt($next->n);
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
