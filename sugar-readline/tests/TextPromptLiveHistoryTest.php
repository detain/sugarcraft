<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\History\InMemoryHistory;
use SugarCraft\Readline\Key;
use SugarCraft\Readline\TextPrompt;

/**
 * Finding 082: arrow navigation walked the private clone captured at
 * withHistory() time while Ctrl+R search read the LIVE store, so entries
 * pushed earlier in the same session were searchable but invisible to
 * Up/Down. The cure is one live handle for both read paths.
 */
final class TextPromptLiveHistoryTest extends TestCase
{
    public function testUpSeesEntriesPushedAfterAttachment(): void
    {
        $history = new InMemoryHistory();
        $history->push('older');

        $p = TextPrompt::new('> ')->withHistory($history);

        // Pushed AFTER the prompt attached: previously the clone froze at
        // 'older' and Up could never reach this entry on the live store.
        $history->push('mid');

        $p = $p->handleKey(Key::Up);
        $this->assertSame('mid', $p->value());

        $p = $p->handleKey(Key::Up);
        $this->assertSame('older', $p->value());
    }

    public function testNavigationAndSearchAgreeOnTheSameLiveEntry(): void
    {
        $history = new InMemoryHistory();
        $history->push('alpha');

        $start = TextPrompt::new('> ')->withHistory($history);
        $history->push('beta'); // newest, pushed after attachment

        $viaNavigation = $start->handleKey(Key::Up)->value();

        // Ctrl+R incremental search already read the live store (finding
        // 082's other half): both paths must answer with the same entry.
        $viaSearch = $start
            ->handleKey("\x12")   // enter search
            ->handleChar('b')
            ->handleChar('e')
            ->handleChar('t')     // query matches only 'beta'
            ->value();

        $this->assertSame('beta', $viaSearch);
        $this->assertSame($viaSearch, $viaNavigation);
    }

    public function testWithHistoryBindsOneLiveHandleForReadsAndPush(): void
    {
        $history = new InMemoryHistory();

        $p = TextPrompt::new('> ')->withHistory($history);

        $historyProp = new \ReflectionProperty(TextPrompt::class, 'history');
        $originalProp = new \ReflectionProperty(TextPrompt::class, 'historyOriginal');

        // Identity pins: navigation and search read THE SAME object the
        // caller holds; no frozen copy is minted at attach time.
        $this->assertSame($history, $historyProp->getValue($p));
        $this->assertSame($history, $originalProp->getValue($p));
    }
}
