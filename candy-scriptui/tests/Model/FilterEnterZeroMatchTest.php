<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Model;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\ScriptUi\Command\FilterCommand;
use SugarCraft\ScriptUi\Model\FilterModel;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * scriptui-11 (findings 003 + 012, gate G2 default posture): Enter must
 * always end a filter session. On a zero-match result set it submits the
 * EMPTY selection with exit 0 — never the silent freeze of the non-fuzzy
 * arm, never the fabricated pick of the fuzzy arm (a typed query with no
 * scored matches painted "No matches." while Enter printed allItems[0]).
 */
final class FilterEnterZeroMatchTest extends TestCase
{
    public function testNonFuzzyZeroMatchEnterSubmitsEmpty(): void
    {
        $m = FilterModel::fromOptions(['apple', 'banana', 'cherry']);
        foreach (str_split('xyzzy') as $c) {
            [$m, ] = $m->update(new KeyMsg(KeyType::Char, $c));
        }
        $this->assertSame([], $m->list->visibleItems());

        [$m, $cmd] = $m->update(new KeyMsg(KeyType::Enter));
        $this->assertTrue($m->isSubmitted());
        $this->assertNotNull($cmd, 'the session must end, not freeze');
        $this->assertNull($m->selected());
    }

    public function testFuzzyZeroMatchEnterSubmitsEmpty(): void
    {
        $m = FilterModel::fromOptions(['apple', 'banana', 'cherry'], fuzzy: true);
        // Smith-Waterman scores any single shared character (minScore 1),
        // so a zero-match typed filter needs a query with no character in
        // common with any candidate: 'zzz' matches nothing.
        foreach (str_split('zzz') as $c) {
            [$m, ] = $m->update(new KeyMsg(KeyType::Char, $c));
        }
        // The paint channel already says "No matches."; the guard used to
        // consult fuzzyVisibleItems(), whose empty-results fallback is the
        // FULL list — Enter printed 'apple' for a query that highlighted
        // nothing (finding 012's fabricated pick).
        $this->assertStringContainsString('No matches.', $m->view());
        $this->assertSame([], $m->fuzzyResults);

        [$m, $cmd] = $m->update(new KeyMsg(KeyType::Enter));
        $this->assertTrue($m->isSubmitted());
        $this->assertNotNull($cmd);
        $this->assertNull($m->selected());
    }

    public function testFuzzyZeroMatchRenderResultExitsZeroWithEmptyData(): void
    {
        $m = FilterModel::fromOptions(['apple', 'banana', 'cherry'], fuzzy: true);
        foreach (str_split('zzz') as $c) {
            [$m, ] = $m->update(new KeyMsg(KeyType::Char, $c));
        }
        [$m, ] = $m->update(new KeyMsg(KeyType::Enter));

        $output = new BufferedOutput();
        $status = FilterCommand::renderResult($m, $output, "\n", printQuery: false);
        $this->assertSame(0, $status);
        $this->assertSame("\n", $output->fetch(), 'empty selection is one empty data line');
    }

    public function testFuzzyEmptyQueryEnterSubmitsCursorRow(): void
    {
        // Control: with no filter typed the full list is painted; Enter
        // submits the highlighted row (the cursor row, not a blind
        // allItems[0]) and the data channel carries it.
        $m = FilterModel::fromOptions(['apple', 'banana', 'cherry'], fuzzy: true);
        [$m, ] = $m->update(new KeyMsg(KeyType::Down));
        [$m, ] = $m->update(new KeyMsg(KeyType::Enter));
        $this->assertTrue($m->isSubmitted());
        $this->assertSame('banana', $m->selected());
    }

    public function testFuzzyWithMatchesEnterSubmitsHighlightedMatch(): void
    {
        // Control: a scored query keeps the old contract — Enter submits
        // the painted, highlighted match.
        $m = FilterModel::fromOptions(['banana', 'cherry'], fuzzy: true);
        foreach (str_split('bna') as $c) {
            [$m, ] = $m->update(new KeyMsg(KeyType::Char, $c));
        }
        $this->assertNotEmpty($m->fuzzyResults);
        [$m, ] = $m->update(new KeyMsg(KeyType::Enter));
        $this->assertTrue($m->isSubmitted());
        $this->assertSame('banana', $m->selected());
    }

    public function testMultiZeroMatchEnterSubmitsEmptySelection(): void
    {
        // Multi arm of the same posture: nothing ticked, zero matches,
        // Enter ends the session and the data channel is empty.
        $m = FilterModel::fromOptions(['apple', 'banana'], noLimit: true);
        foreach (str_split('zzz') as $c) {
            [$m, ] = $m->update(new KeyMsg(KeyType::Char, $c));
        }
        [$m, ] = $m->update(new KeyMsg(KeyType::Enter));
        $this->assertTrue($m->isSubmitted());
        $this->assertSame([], $m->selectedAll());

        $output = new BufferedOutput();
        $this->assertSame(0, FilterCommand::renderResult($m, $output, "\n", printQuery: false));
        $this->assertSame("\n", $output->fetch());
    }
}
