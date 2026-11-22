<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Core\Util\Width;
use SugarCraft\Files\Entry;
use SugarCraft\Files\Manager;
use SugarCraft\Files\Manager\ManagerBuilder;
use SugarCraft\Files\Pane;
use SugarCraft\Files\Renderer;
use SugarCraft\Files\Support\Bidi;

/**
 * P2-2: the tab bar is drawn by the sugar-widgets Tabs library (label
 * padding 0, one-space divider, plain styles) and hit-tested through the
 * same bar's spans. The expected bytes below are the ones the hand-rolled
 * bar drew before the migration: `<digit><[ or space><label><] or space>`
 * cells joined by one space from column 1, each cell LRM-fenced in RTL
 * locales, and no bar at all for a single tab.
 */
final class RendererTabBarTest extends TestCase
{
    private string $priorLocale;

    protected function setUp(): void
    {
        $this->priorLocale = T::locale();
        T::setLocale('en');
    }

    protected function tearDown(): void
    {
        T::setLocale($this->priorLocale);
    }

    /** @return \Closure(string): (?list<Entry>) */
    private function fakeFs(): \Closure
    {
        return static fn(string $dir): ?array => [
            Entry::parent(),
            new Entry('notes.txt', false, 7, 0),
        ];
    }

    /** @param list<string> $dirs */
    private function manager(array $dirs, int $tabIndex): Manager
    {
        $tabs = [];
        foreach ($dirs as $dir) {
            $tabs[] = [
                'left' => Pane::open($dir, $this->fakeFs()),
                'right' => Pane::open($dir, $this->fakeFs()),
                'activeIdx' => 0,
            ];
        }
        return (new ManagerBuilder())
            ->withLeft(Pane::open($dirs[0], $this->fakeFs()))
            ->withRight(Pane::open($dirs[0], $this->fakeFs()))
            ->withTabs($tabs)
            ->withTabIndex($tabIndex)
            ->withLister($this->fakeFs())
            ->build();
    }

    private static function firstLine(Manager $m): string
    {
        return explode("\n", Renderer::render($m))[0];
    }

    public function testTwoTabBarIsByteIdenticalToTheLegacyCells(): void
    {
        $this->assertSame(
            bin2hex('1[/alpha] 2 /beta '),
            bin2hex(self::firstLine($this->manager(['/alpha', '/beta'], 0))),
        );
        $this->assertSame(
            bin2hex('1 /alpha  2[/beta]'),
            bin2hex(self::firstLine($this->manager(['/alpha', '/beta'], 1))),
        );
    }

    public function testTwoTabBarInAnRtlLocaleFencesEachCell(): void
    {
        T::setLocale('ar');
        $lrm = Bidi::LRM;
        $this->assertSame(
            bin2hex("{$lrm}1[/alpha]{$lrm} {$lrm}2 /beta {$lrm}"),
            bin2hex(self::firstLine($this->manager(['/alpha', '/beta'], 0))),
        );
    }

    public function testLongCwdLabelIsTailTruncatedInsideTheCell(): void
    {
        $long = '/very/long/directory/name/for/tabs';
        $line = self::firstLine($this->manager(['/a', $long], 0));
        $this->assertSame('1[/a] 2 …ctory/name/for/tabs ', $line);
    }

    public function testSingleTabDrawsNoBar(): void
    {
        $one = $this->manager(['/alpha'], 0);
        $flat = (new ManagerBuilder())
            ->withLeft(Pane::open('/alpha', $this->fakeFs()))
            ->withRight(Pane::open('/alpha', $this->fakeFs()))
            ->withLister($this->fakeFs())
            ->build();
        $this->assertSame(Renderer::render($flat), Renderer::render($one));
        $this->assertNotSame('tab', Renderer::hit($one, 2, 1)['kind'] ?? null, 'row 1 is the pane border, not a tab');
    }

    /**
     * Every cell of every drawn tab maps to that tab, and the separator and
     * the cells past the bar map to nothing. Columns come from the drawn
     * line itself (1-based), so the hit-test cannot drift from the draw.
     *
     * @return list<array{0:string, 1:int}>
     */
    public static function locales(): array
    {
        return [['en', 0], ['en', 2], ['ar', 1]];
    }

    #[DataProvider('locales')]
    public function testClickOnEachDrawnTabMapsToThatTab(string $locale, int $tabIndex): void
    {
        T::setLocale($locale);
        $m = $this->manager(['/alpha', '/beta', '/gamma'], $tabIndex);
        // U+200E advances zero columns on a terminal: the screen columns are
        // those of the drawn line with its fences removed.
        $screen = str_replace(Bidi::LRM, '', self::firstLine($m));

        $col = 1;
        foreach (['/alpha', '/beta', '/gamma'] as $i => $dir) {
            $cell = ($i + 1) . ($i === $tabIndex ? "[{$dir}]" : " {$dir} ");
            $pos = strpos($screen, $cell);
            $this->assertNotFalse($pos, "tab {$i} drawn");
            $start = Width::of(substr($screen, 0, $pos)) + 1;
            $this->assertSame($col, $start, "tab {$i} starts where the previous one and the divider end");
            $end = $start + Width::of($cell) - 1;
            for ($x = $start; $x <= $end; $x++) {
                $this->assertSame(['kind' => 'tab', 'tab' => $i], Renderer::hit($m, $x, 1), "column {$x}");
            }
            if ($i < 2) {
                $this->assertNull(Renderer::hit($m, $end + 1, 1), 'the divider is no tab');
            }
            $col = $end + 2;
        }
        $this->assertSame(Width::of($screen) + 1, $col - 1, 'the bar ends after the last tab');
        $this->assertNull(Renderer::hit($m, $col - 1, 1), 'past the bar is no tab');
    }

    public function testClickingADrawnTabSwitchesToIt(): void
    {
        $m = $this->manager(['/alpha', '/beta', '/gamma'], 0);
        $hit = Renderer::hit($m, strpos(self::firstLine($m), '3 /gamma') + 1, 1);
        $this->assertSame(['kind' => 'tab', 'tab' => 2], $hit);
        $this->assertSame(2, $m->switchTab($hit['tab'])->tabIndex);
    }
}
