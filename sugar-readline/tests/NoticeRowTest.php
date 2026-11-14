<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\History\InMemoryHistory;
use SugarCraft\Readline\Key;
use SugarCraft\Readline\MultiSelectPrompt;
use SugarCraft\Readline\TextareaPrompt;
use SugarCraft\Readline\TextPrompt;

/**
 * Findings 033/084 (S-31): the transient notice row. Every silent refusal
 * in the shipped surfaces announces on a dim line that lives exactly one
 * input beat — the FIFO eviction at the selection cap, Ctrl+R over an
 * empty history store, and the newline a max-lines bound refuses.
 */
final class NoticeRowTest extends TestCase
{
    public function testNoticePaintsAsDimRowUnderTheBody(): void
    {
        $p = TextPrompt::new('> ')->withHistory(new InMemoryHistory())
            ->handleKey(Key::CtrlR);

        self::assertSame(
            "\x1b[1;36m> \x1b[0m\x1b[7m \x1b[0m\n\x1b[90mHistory is empty\x1b[0m",
            $p->view(),
            'the notice rides the dim palette under the prompt line',
        );
    }

    public function testNoticeDiesOnTheNextKey(): void
    {
        $p = TextPrompt::new('> ')->withHistory(new InMemoryHistory())
            ->handleKey(Key::CtrlR);
        self::assertStringContainsString('History is empty', $p->view());

        $after = $p->handleChar('a');

        self::assertStringNotContainsString('History is empty', $after->view());
        self::assertStringContainsString('a', $after->value());
    }

    public function testNoNoticeWhenNoHistoryIsWiredAtAll(): void
    {
        // The null-history door stays silent by design (finding 084 scoped
        // the refusal announcement to a wired-but-empty store); there is
        // no capability to promise here, not one being refused.
        $p = TextPrompt::new('> ')->handleKey(Key::CtrlR);

        self::assertFalse($p->isSearching());
        self::assertStringNotContainsString('History is empty', $p->view());
    }

    public function testTextareaRefusalAnnouncesAndTheNextKeystrokeClears(): void
    {
        $p = TextareaPrompt::new('Body')->withMaxLines(1)
            ->handleChar('x')->handleKey(Key::CtrlJ);

        self::assertSame(['x'], $p->lines());
        self::assertStringContainsString("\x1b[90mCannot add a line: maximum 1 reached\x1b[0m", $p->view());

        $typed = $p->handleChar('!');
        self::assertSame(['x!'], $typed->lines());
        self::assertStringNotContainsString('maximum 1 reached', $typed->view());

        // Positive control: the bound itself does not wedge newlines.
        $grown = TextareaPrompt::new('Body')->withMaxLines(2)
            ->handleChar('x')->handleKey(Key::CtrlJ);
        self::assertCount(2, $grown->lines());
    }

    public function testFifoEvictionAtTheSelectionCapAnnouncesTheVictim(): void
    {
        $p = MultiSelectPrompt::new('Picks', ['apple', 'banana', 'cherry'])
            ->withMaxSelections(2)
            ->handleKey(Key::Space)                 // apple
            ->handleKey(Key::Down)->handleKey(Key::Space)   // banana
            ->handleKey(Key::Down)->handleKey(Key::Space);  // cherry evicts apple

        self::assertSame(['banana', 'cherry'], $p->selectedValues());
        self::assertStringContainsString(
            "\x1b[90mun-marked apple to respect the limit of 2\x1b[0m",
            $p->view(),
        );

        $next = $p->handleKey(Key::Down);
        self::assertStringNotContainsString('un-marked', $next->view());
        self::assertSame(['banana', 'cherry'], $next->selectedValues());
    }

    public function testListNoticeAlsoDiesOnTypedFilterInput(): void
    {
        // The notice funnel is shared between handleKey and handleChar, so
        // type-to-filter clears it just like an arrow key would.
        $p = MultiSelectPrompt::new('Picks', ['apple', 'banana', 'cherry'])
            ->withMaxSelections(1)
            ->handleKey(Key::Space)
            ->handleKey(Key::Down)->handleKey(Key::Space);
        self::assertStringContainsString('un-marked apple', $p->view());

        $typed = $p->handleChar('b');
        self::assertStringNotContainsString('un-marked apple', $typed->view());
    }
}
