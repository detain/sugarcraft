<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests\History;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;
use SugarCraft\Readline\History\FileHistory;
use SugarCraft\Readline\History\HistoryInterface;
use SugarCraft\Readline\History\InMemoryHistory;
use SugarCraft\Readline\History\SearchDirection;
use SugarCraft\Readline\History\SearchHit;
use SugarCraft\Readline\TextPrompt;

/**
 * S-17 contract pins (findings 014, 091): the storage-reading surface
 * (entries()/count()), the SearchDirection polarity home, the typed
 * SearchHit result — and the autosuggest rewrite that replaced the
 * clone-and-replay-the-whole-history-every-repaint pattern with a bounded
 * read over entries().
 */

/** History over an explicit list with a shared probe of read/clone traffic. */
final class ProbingHistory implements HistoryInterface
{
    /** @param string[] $items newest-first storage order */
    public function __construct(public array $items = [], public readonly stdClass $probe = new stdClass())
    {
    }

    public function push(string $entry): void
    {
        array_unshift($this->items, $entry);
    }

    public function previous(): ?string
    {
        return $this->items[0] ?? null;
    }

    public function next(): ?string
    {
        return $this->items[0] ?? null;
    }

    public function reset(): void
    {
    }

    public function clear(): void
    {
        $this->items = [];
    }

    public function search(string $query, int $fromIndex, SearchDirection|int $direction): ?SearchHit
    {
        $polarity = $direction instanceof SearchDirection ? $direction->value : $direction;
        $step = $polarity >= 0 ? 1 : -1;
        for ($i = $fromIndex; $i >= 0 && $i < count($this->items); $i += $step) {
            if ($query === '' || str_contains($this->items[$i], $query)) {
                return SearchHit::new($i, $this->items[$i]);
            }
        }
        return null;
    }

    public function entries(): array
    {
        $this->probe->entryCalls = ($this->probe->entryCalls ?? 0) + 1;

        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function __clone(): void
    {
        $this->probe->clones = ($this->probe->clones ?? 0) + 1;
    }
}

final class HistoryContractTest extends TestCase
{
    private string $tmpDir = '';

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '') {
            foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tmpDir);
            $this->tmpDir = '';
        }
    }

    // -----------------------------------------------------------------
    // entries()/count() on the storage (014)
    // -----------------------------------------------------------------

    public function testInMemoryEntriesAndCount(): void
    {
        $history = new InMemoryHistory();
        $history->push('first');
        $history->push('second');

        $this->assertSame(2, $history->count());
        $this->assertSame(['second', 'first'], $history->entries(), 'newest first, the order previous() walks');

        // The returned list is a read view: writing to it must not reach back
        // into the store (CoW copy semantics).
        $view = $history->entries();
        $view[] = 'mutant';
        $this->assertCount(2, $history->entries());
    }

    public function testFileHistoryWindowMatchesItsStore(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/srl_contract_' . uniqid();
        mkdir($this->tmpDir, 0700);
        $path = $this->tmpDir . '/history.txt';
        file_put_contents($path, "one\ntwo\nthree\n");

        $history = new FileHistory($path, 2);
        $this->assertSame(2, $history->count(), 'window is capped by maxHistory');
        $this->assertSame(['three', 'two'], $history->entries());
    }

    // -----------------------------------------------------------------
    // SearchDirection home (091)
    // -----------------------------------------------------------------

    public function testEnumCarriesTheLegacyPolarityValues(): void
    {
        $this->assertSame(1, SearchDirection::Reverse->value);
        $this->assertSame(-1, SearchDirection::Forward->value);
        $this->assertSame(SearchDirection::Reverse, TextPrompt::SEARCH_REVERSE);
        $this->assertSame(SearchDirection::Forward, TextPrompt::SEARCH_FORWARD);
    }

    public function testDeprecatedConstsCarryTheRemovalMarker(): void
    {
        $reflection = new ReflectionClass(TextPrompt::class);
        foreach (['SEARCH_REVERSE', 'SEARCH_FORWARD'] as $name) {
            $doc = (string) $reflection->getReflectionConstant($name)->getDocComment();
            $this->assertStringContainsString('@deprecated', $doc, "{$name} must be marked deprecated");
            $this->assertStringContainsString('REMOVED-IN: 2.0.0', $doc, "{$name} must carry the removal marker");
        }
    }

    public function testStartHistorySearchStillAcceptsLegacyInts(): void
    {
        $history = new InMemoryHistory();
        $history->push('git init');

        $reverse = (new TextPrompt('> '))->withHistory($history)->startHistorySearch(1);
        $plain = $reverse->view();
        $this->assertStringContainsString('reverse-i-search', $plain, 'int 1 still means reverse');

        $forward = (new TextPrompt('> '))->withHistory($history)->startHistorySearch(-1);
        $this->assertStringContainsString('i-search', $forward->view());
        $this->assertStringNotContainsString('reverse-i-search', $forward->view(), 'int -1 still means forward');

        // The legacy step law treated 0 as non-negative → Reverse.
        $zero = (new TextPrompt('> '))->withHistory($history)->startHistorySearch(0);
        $this->assertStringContainsString('reverse-i-search', $zero->view());
    }

    public function testStepSearchAdvancesByTheEnumValue(): void
    {
        $history = new ProbingHistory(items: ['newest', 'mid1', 'mid2', 'oldest']);
        $prompt = (new TextPrompt('> '))->withHistory($history)->startHistorySearch(SearchDirection::Reverse);
        $this->assertSame('', $prompt->value(), 'entering search starts with an empty live buffer');

        $one = $prompt->handleKey('ctrl_r');
        $this->assertSame('newest', $one->value(), 'the first step seeds at index 0 (newest)');
        $two = $one->handleKey('ctrl_r');
        $this->assertSame('mid1', $two->value(), 'next step moves index 0 -> 1 by the enum value');
        $three = $two->handleKey('ctrl_r');
        $this->assertSame('mid2', $three->value(), 'and again to index 2');
    }

    // -----------------------------------------------------------------
    // SearchHit shape (091)
    // -----------------------------------------------------------------

    public function testSearchHitIsAReadOnlyPair(): void
    {
        $hit = SearchHit::new(3, 'git rebase');
        $this->assertSame(3, $hit->index);
        $this->assertSame('git rebase', $hit->entry);

        $this->expectException(\Error::class);
        $hit->index = 4;
    }

    // -----------------------------------------------------------------
    // Autosuggest reads bounded, never clones the store (014)
    // -----------------------------------------------------------------

    public function testAutoSuggestNeverClonesTheHistory(): void
    {
        $probe = new stdClass();
        $history = new ProbingHistory(items: ['hello world', 'hi there'], probe: $probe);
        $prompt = (new TextPrompt('> '))->withHistory($history)->withAutoSuggest(true);
        // withHistory keeps one working copy at ATTACH time (navigation
        // isolation, by design); the finding 014 contract is that repaints
        // themselves never clone again.
        $probe->clones = 0;
        foreach (['h', 'i', ' '] as $ch) {
            $prompt = $prompt->handleChar($ch);
        }

        $view = $prompt->view();
        $this->assertStringContainsString('there', $view, 'the completion tail still suggests');

        $prompt->view();
        $this->assertSame(0, $probe->clones ?? 0, 'repaints must not clone the history any more');
        $this->assertGreaterThanOrEqual(2, $probe->entryCalls ?? 0, 'the tail rides entries() reads');
    }

    public function testAutoSuggestScanWindowIsBounded(): void
    {
        $items = array_fill(0, 250, 'filler');
        $items[205] = 'ZZold match';
        $history = new ProbingHistory(items: $items);
        $prompt = (new TextPrompt('> '))->withHistory($history)->withAutoSuggest(true);
        foreach (['Z', 'Z', 'o'] as $ch) {
            $prompt = $prompt->handleChar($ch);
        }
        $this->assertStringNotContainsString('match', $prompt->view(), 'entries older than the window stop competing');

        $items[199] = 'ZZnear match';
        $near = (new TextPrompt('> '))->withHistory(new ProbingHistory(items: $items))->withAutoSuggest(true);
        foreach (['Z', 'Z', 'n'] as $ch) {
            $near = $near->handleChar($ch);
        }
        $this->assertStringContainsString('match', $near->view(), 'the newest match inside the window still suggests');
    }
}
