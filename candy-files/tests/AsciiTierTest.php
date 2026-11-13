<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Files\Entry;
use SugarCraft\Files\Manager;
use SugarCraft\Files\Manager\ManagerBuilder;
use SugarCraft\Files\Pane;
use SugarCraft\Files\Renderer;

/**
 * CF-35 (closes audit 048): on a terminal that doubles East Asian
 * Ambiguous glyphs (CJK-locale xterm/vte, the Linux console, legacy
 * CJK codepages) the frame must be composed of strictly-narrow chrome
 * and every line must fit termCols with the A=1 arithmetic — which on
 * those terminals is the TRUE width once the tier is ascii.
 */
final class AsciiTierTest extends TestCase
{
    private ?string $priorTier;

    protected function setUp(): void
    {
        $this->priorTier = isset($_SERVER['CANDY_FILES_GLYPHS'])
            ? (string) $_SERVER['CANDY_FILES_GLYPHS']
            : null;
    }

    protected function tearDown(): void
    {
        if ($this->priorTier === null) {
            unset($_SERVER['CANDY_FILES_GLYPHS']);
        } else {
            $_SERVER['CANDY_FILES_GLYPHS'] = $this->priorTier;
        }
    }

    /** @return \Closure(string): (?list<Entry>) */
    private function fakeFs(): \Closure
    {
        // A CJK filename rides along: the tier must never rewrite data.
        return static fn(string $dir): ?array => $dir === '/'
            ? [
                Entry::parent(),
                new Entry('docs', true, 0, 0),
                new Entry('日本語.txt', false, 7, 0),
            ]
            : null;
    }

    private function asciiFrame(): string
    {
        $_SERVER['CANDY_FILES_GLYPHS'] = 'ascii';
        $m = (new ManagerBuilder())
            ->withLeft(Pane::open('/', $this->fakeFs()))
            ->withRight(Pane::open('/', $this->fakeFs()))
            ->withLister($this->fakeFs())
            ->withTermCols(80)
            ->withTermRows(12)
            ->build();

        return Renderer::render($m);
    }

    public function testTheAsciiFrameCarriesNoAmbiguousChrome(): void
    {
        $out = $this->asciiFrame();

        foreach (['▸', '✓', '─', '↑', '↓', '·'] as $ambiguous) {
            $this->assertStringNotContainsString($ambiguous, $out, "tier glyph $ambiguous leaked into the frame");
        }
        $this->assertStringContainsString('>', $out, 'cursor rides the tier');
        $this->assertStringContainsString('+-', $out, 'borders ride the ascii border set');
        $this->assertStringContainsString('|', $out, 'ascii side borders render');
        $this->assertStringContainsString('日本語.txt', $out, 'file data keeps its bytes on any tier');
    }

    public function testEveryAsciiFrameLineFitsTheTerminal(): void
    {
        $out = $this->asciiFrame();

        foreach (explode("\n", $out) as $i => $line) {
            $this->assertLessThanOrEqual(
                80,
                Width::of($line),
                'ascii-tier line ' . $i . ' overflows the terminal: ' . $line,
            );
        }
    }

    public function testTheUnicodeFrameIsTheHistoricalBytes(): void
    {
        // Belt for the tier refactor: with the override pinned to unicode
        // the frame must contain the exact legacy chrome glyphs again.
        $_SERVER['CANDY_FILES_GLYPHS'] = 'unicode';
        $m = Manager::start('/', '/', $this->fakeFs());
        $out = Renderer::render($m);

        $this->assertStringContainsString('▸', $out);
        $this->assertStringContainsString('─', $out);
        $this->assertStringContainsString('·', $out);
        $this->assertStringContainsString('日本語.txt', $out);
    }
}
