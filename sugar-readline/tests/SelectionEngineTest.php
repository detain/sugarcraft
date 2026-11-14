<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\MultiSelectPrompt;
use SugarCraft\Readline\SelectionPrompt;

/**
 * S-21 (finding 019): the list engine is defined once in
 * SelectionEngine and shared by both selection prompts. Pins:
 * byte-stable views through the extraction, structural alias-set equality
 * between the two surfaces, and the additive withChoices() seam.
 */
final class SelectionEngineTest extends TestCase
{
    // =========================================================================
    // Golden renderings (captured from the pre-extraction implementations)
    // =========================================================================

    public function testSelectionPromptViewGolden(): void
    {
        $view = SelectionPrompt::new('Pick: ', ['alpha', 'beta', 'gamma'])
            ->withPageSize(2)
            ->view();

        $this->assertSame(
            "\x1b[1;36mPick: \x1b[0m\n"
            . "[filter] \n"
            . "\x1b[7m❯ alpha\x1b[0m\n"
            . "  beta\n"
            . 'Page 1/2',
            $view,
        );
    }

    public function testSelectionPromptFilteredViewGolden(): void
    {
        $view = SelectionPrompt::new('Pick: ', ['alpha', 'beta', 'gamma'])
            ->withPageSize(2)
            ->handleChar('a')->handleChar('l')
            ->view();

        $this->assertSame(
            "\x1b[1;36mPick: \x1b[0m\n"
            . "[filter] al (1 match)\n"
            . "\x1b[7m❯ alpha\x1b[0m",
            $view,
        );
    }

    public function testSelectionPromptNoMatchesGolden(): void
    {
        $view = SelectionPrompt::new('Pick: ', ['alpha', 'beta', 'gamma'])
            ->withPageSize(2)
            ->withFilter('zzz')
            ->view();

        $this->assertSame(
            "\x1b[1;36mPick: \x1b[0m\n"
            . "[filter] zzz (0 matches)\n"
            . '(no matches)',
            $view,
        );
    }

    public function testMultiSelectPromptViewGolden(): void
    {
        $p = MultiSelectPrompt::new('Many: ', ['one', 'two', 'three'])->withPageSize(2);
        $p = $p->handleKey(' ')->handleKey('down')->handleKey(' ');

        $this->assertSame(
            "\x1b[1;36mMany: \x1b[0m\n"
            . "[multi-select] selected: 2\n"
            . "[filter] \n"
            . "◉ one\n"
            . "\x1b[7m◉ two\x1b[0m\n"
            . 'Page 1/2',
            $p->view(),
        );
    }

    public function testFilterBarIsByteIdenticalAcrossSurfaces(): void
    {
        // One engine, two renderers: the shared bar must not fork.
        $sel = SelectionPrompt::new('L: ', ['x'])->withFilter('q');
        $mul = MultiSelectPrompt::new('L: ', ['x'])->withFilter('q');

        $selBar = explode("\n", $sel->view())[1];
        $mulBar = explode("\n", $mul->view())[2]; // multi keeps its hint row first

        $this->assertSame($selBar, $mulBar);
        $this->assertSame('[filter] q (0 matches)', $selBar);
    }

    // =========================================================================
    // Alias-set equality: both surfaces answer every spelling identically
    // =========================================================================

    /** @return array<string, array{0: string, 1: string}> [alias key, canonical key it must equal] */
    public static function movementAliasPairs(): array
    {
        return [
            'ctrl_p' => ['ctrl_p', 'up'],
            'ctrl_n' => ['ctrl_n', 'down'],
            'ctrl_a' => ['ctrl_a', 'home'],
            'ctrl_e' => ['ctrl_e', 'end'],
        ];
    }

    #[DataProvider('movementAliasPairs')]
    public function testMovementAliasesBehaveLikeCanonicalKeysOnBothSurfaces(string $alias, string $canonical): void
    {
        foreach (['single', 'multi'] as $surface) {
            $build = static fn(): object => $surface === 'single'
                ? SelectionPrompt::new('L: ', ['a', 'b', 'c', 'd'])->withPageSize(2)
                : MultiSelectPrompt::new('L: ', ['a', 'b', 'c', 'd'])->withPageSize(2);

            // Drive to a known non-edge cursor first (index 1) so every
            // movement has somewhere to go.
            $start = $build()->handleKey('down');
            $this->assertSame(1, $start->cursor(), "seed cursor on {$surface}");

            $viaAlias = $start->handleKey($alias)->cursor();
            $viaCanonical = $start->handleKey($canonical)->cursor();

            $this->assertSame(
                $viaCanonical,
                $viaAlias,
                "{$alias} must move the {$surface} cursor exactly like {$canonical}",
            );
            $this->assertNotSame($viaAlias, $start->cursor(), "{$alias} must not be inert where {$canonical} is not");
        }
    }

    public function testCtrlUClearsFilterOnBothSurfacesAndPageUpIsShared(): void
    {
        $sel = SelectionPrompt::new('L: ', ['ab', 'cd'])->handleChar('a');
        $this->assertSame(1, $sel->filteredCount());
        $this->assertSame(2, $sel->handleKey('ctrl_u')->filteredCount());

        $mul = MultiSelectPrompt::new('L: ', ['ab', 'cd'])->handleChar('a');
        $this->assertSame(1, $mul->filteredCount());
        $this->assertSame(2, $mul->handleKey('ctrl_u')->filteredCount());

        // PageDown/Up arms shared: both surfaces land on page 1 -> 2 -> 1.
        $big = static fn(): object => MultiSelectPrompt::new('L: ', ['a', 'b', 'c'])->withPageSize(1);
        $p2 = $big()->handleKey('pagedown');
        $this->assertSame(1, $p2->currentPage());
        $this->assertSame(0, $p2->handleKey('pageup')->currentPage());
    }

    // =========================================================================
    // withChoices — additive, preserving
    // =========================================================================

    public function testWithChoicesReappliesFilterAndPreservesCursorByDefault(): void
    {
        $p = SelectionPrompt::new('L: ', ['apple', 'banana', 'cherry']);
        $p = $p->handleChar('a'); // filter 'a' -> apple, banana
        $p = $p->handleKey('down'); // cursor 1 (banana)

        $q = $p->withChoices(['avocado', 'apricot', 'banana', 'kiwi']);

        // Filter 'a' over the new list: avocado, apricot, banana -> cursor 1 kept.
        $this->assertSame(['avocado', 'apricot', 'banana'], $q->currentPageItems());
        $this->assertSame(1, $q->cursor());
        $this->assertSame('apricot', $q->selectedValue());
    }

    public function testWithChoicesClampsStaleCursor(): void
    {
        $p = SelectionPrompt::new('L: ', ['a', 'b', 'c', 'd'])->handleKey('end');
        $this->assertSame(3, $p->cursor());

        $q = $p->withChoices(['only']);
        $this->assertSame(0, $q->cursor());
        $this->assertSame(1, $q->filteredCount());
    }

    public function testWithChoicesResetOptionHomesTheView(): void
    {
        $p = SelectionPrompt::new('L: ', ['a', 'b', 'c', 'd'])
            ->withPageSize(2)
            ->handleKey('end'); // cursor 3, page 1
        $this->assertSame(1, $p->currentPage());

        $q = $p->withChoices(['x', 'y', 'z', 'w', 'v'], preserveCursor: false);
        $this->assertSame(0, $q->cursor());
        $this->assertSame(0, $q->currentPage());
    }

    public function testWithChoicesAcceptsTraversables(): void
    {
        $gen = (static function (): \Generator {
            yield 'one';
            yield 'two';
        })();

        $p = SelectionPrompt::new('L: ', [])->withChoices($gen);
        $this->assertSame(['one', 'two'], $p->currentPageItems());
    }

    public function testMultiSelectWithChoicesResetsMarks(): void
    {
        $p = MultiSelectPrompt::new('L: ', ['a', 'b', 'c'])->handleKey(' ');
        $this->assertSame(['a'], $p->selectedValues());

        $q = $p->withChoices(['x', 'y', 'z']);
        $this->assertSame([], $q->selectedValues());
        $this->assertSame(0, $q->selectionCount());
        // New list is fully selectable again.
        $this->assertSame(['x'], $q->handleKey(' ')->selectedValues());
    }

    public function testWithChoicesPreservesTerminalState(): void
    {
        $p = SelectionPrompt::new('L: ', ['a', 'b'])->submit();
        $q = $p->withChoices(['z']);
        $this->assertTrue($q->isSubmitted(), 'a submitted surface stays submitted');
        $this->assertSame(['z'], $q->currentPageItems());
    }

    public function testHandleCharAfterSubmitStillRejected(): void
    {
        // Guard order preserved through the trait move.
        $p = SelectionPrompt::new('L: ', ['a', 'b'])->handleKey('enter');
        $this->assertTrue($p->isSubmitted());
        $this->assertSame(2, $p->handleChar('a')->filteredCount());
    }

    public function testSpaceSubmitsOnSelectionSurfaceOnly(): void
    {
        // onSpace() forks the one genuinely different binding: Space commits
        // the single-choice list and toggles on the multi-select.
        $sel = SelectionPrompt::new('L: ', ['a', 'b'])->handleKey(' ');
        $this->assertTrue($sel->isSubmitted(), 'Space submits the single-choice surface');

        $raw = SelectionPrompt::new('L: ', ['a', 'b'])->handleChar(' ');
        $this->assertTrue($raw->isSubmitted(), 'a raw-space char routes through the Space binding, not the needle');

        $mul = MultiSelectPrompt::new('L: ', ['a', 'b'])->handleKey(' ');
        $this->assertFalse($mul->isSubmitted(), 'Space toggles on multi-select and never submits');
        $this->assertSame(['a'], $mul->selectedValues());
    }
}
