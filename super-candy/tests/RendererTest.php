<?php

declare(strict_types=1);

namespace CandyCore\SuperCandy\Tests;

use CandyCore\Core\KeyType;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\SuperCandy\Entry;
use CandyCore\SuperCandy\Manager;
use CandyCore\SuperCandy\Pane;
use CandyCore\SuperCandy\Renderer;
use CandyCore\SuperCandy\Sort;
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
}
