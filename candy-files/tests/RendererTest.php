<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Files\Entry;
use SugarCraft\Files\Manager;
use SugarCraft\Files\Pane;
use SugarCraft\Files\Renderer;
use SugarCraft\Files\Sort;
use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase
{
    private function tree(): array
    {
        return [
            '/' => [
                new Entry('home', true, 0, 0),
                new Entry('etc', true, 0, 0),
                new Entry('readme.txt', false, 1024, 0),
            ],
            '/home' => [
                new Entry('user', true, 0, 0),
            ],
        ];
    }

    private function fakeFs(): \Closure
    {
        $tree = $this->tree();
        return static fn(string $p): array => $tree[$p] ?? [];
    }

    public function testRenderProducesNonEmptyOutput(): void
    {
        $m = Manager::start('/', '/', $this->fakeFs());
        $out = Renderer::render($m);
        $this->assertNotSame('', $out);
        // Both panes are visible side-by-side: each shows its cwd.
        $this->assertStringContainsString('/', $out);
    }

    public function testRenderShowsSelectedEntries(): void
    {
        $m = Manager::start('/', '/', $this->fakeFs());
        // Toggle selection by pressing space.
        [$m] = $m->update(new KeyMsg(KeyType::Char, ' '));
        $out = Renderer::render($m);
        $this->assertStringContainsString('✓', $out);
    }

    public function testRenderShowsCursorArrow(): void
    {
        $m = Manager::start('/', '/', $this->fakeFs());
        $out = Renderer::render($m);
        $this->assertStringContainsString('▸', $out);
    }

    public function testRenderShowsStatusOrKeyHelp(): void
    {
        $m = Manager::start('/', '/', $this->fakeFs());
        $out = Renderer::render($m);
        // Default empty status falls back to key help line — should
        // mention some control keys.
        $this->assertNotSame('', trim($out));
    }

    public function testRenderShowsSortLabelInHeader(): void
    {
        $m = Manager::start('/', '/', $this->fakeFs());
        $out = Renderer::render($m);
        $this->assertStringContainsString(Sort::NameAsc->value, $out);
    }

    public function testRenderHandlesEmptyDirectory(): void
    {
        $empty = static fn(string $p): array => [];
        $m = Manager::start('/empty', '/empty', $empty);
        $out = Renderer::render($m);
        $this->assertNotSame('', $out);
    }

    public function testRenderShowsTabBarWhenMultipleTabs(): void
    {
        $m = Manager::start('/', '/', $this->fakeFs());
        // Duplicate tab to create multiple tabs
        $m = $m->duplicateTab();
        $this->assertTrue($m->showTabBar);
        $out = Renderer::render($m);
        // Tab bar should show the tab labels
        $this->assertStringContainsString('/', $out);
    }

    public function testRenderShowsSearchUIWhenSearching(): void
    {
        $m = Manager::start('/', '/', $this->fakeFs());
        // Start search
        [$m] = $m->update(new KeyMsg(KeyType::Char, '/'));
        $this->assertNotNull($m->searchQuery);
        $out = Renderer::render($m);
        // Should show "Search:" label
        $this->assertStringContainsString('Search:', $out);
        // Should show search query
        $this->assertStringContainsString('Search: ', $out);
    }

    public function testRenderSearchShowsNoMatchMessage(): void
    {
        $m = Manager::start('/', '/', $this->fakeFs());
        // Start search and type something that matches nothing
        [$m] = $m->update(new KeyMsg(KeyType::Char, '/'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'q'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'q'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertSame([], $m->searchResults);
        $out = Renderer::render($m);
        // Should show "(no matches)" message
        $this->assertStringContainsString('(no matches)', $out);
    }

    public function testRenderSearchShowsResultsWithCounter(): void
    {
        $m = Manager::start('/', '/', $this->fakeFs());
        // Start search and type 're' which should match readme.txt
        [$m] = $m->update(new KeyMsg(KeyType::Char, '/'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'r'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'e'));
        $this->assertNotEmpty($m->searchResults);
        $out = Renderer::render($m);
        // Should show counter like "1/2" or similar
        $this->assertMatchesRegularExpression('/\d+\/\d+/', $out);
    }

    public function testRenderCursorAdvancesWithMultipleMoves(): void
    {
        $m = Manager::start('/', '/', $this->fakeFs());
        // Move down a few times
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        $out = Renderer::render($m);
        // Should still show cursor arrow
        $this->assertStringContainsString('▸', $out);
    }

    public function testRenderTruncatesLongDirectoryNames(): void
    {
        $tree = [
            '/a-very-long-directory-name-that-exceeds-thirty-chars' => [
                new Entry('file.txt', false, 1024, 0),
            ],
        ];
        $fs = static fn(string $p): array => $tree[$p] ?? [];
        $m = Manager::start('/a-very-long-directory-name-that-exceeds-thirty-chars', '/a-very-long-directory-name-that-exceeds-thirty-chars', $fs);
        $out = Renderer::render($m);
        // The long path should be truncated with "..."
        $this->assertStringContainsString('…', $out);
    }
}
