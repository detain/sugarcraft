<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Core\Util\Width;
use SugarCraft\Files\Entry;
use SugarCraft\Files\Lang;
use SugarCraft\Files\Manager;
use SugarCraft\Files\Manager\ManagerBuilder;
use SugarCraft\Files\Pane;
use SugarCraft\Files\Renderer;
use SugarCraft\Files\Sort;
use SugarCraft\Files\Support\LaunchDir;

/**
 * CF-16 (closes audit 025, 056, 060): chrome that used to bypass the
 * catalogues — the raw 'Search: ' prompt, raw 'DIR'/'LINK' size tokens
 * sitting next to a translated search.type_dir, and the enum wire value
 * printed as the sort header — now renders through Lang, and the bin's
 * directory-refusal prose follows.
 */
final class ChromeLangTest extends TestCase
{
    private string $priorLocale;

    protected function setUp(): void
    {
        $this->priorLocale = T::locale();
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
            new Entry('docs', true, 0, 0),
            new Entry('notes.txt', false, 7, 0),
        ];
    }

    private function germanFrame(): string
    {
        T::setLocale('de');
        $m = (new ManagerBuilder())
            ->withLeft(Pane::open('/', $this->fakeFs()))
            ->withRight(Pane::open('/', $this->fakeFs()))
            ->withSearchQuery('')
            ->withLister($this->fakeFs())
            ->build();

        return Renderer::render($m);
    }

    public function testTheGermanFrameLocalizesPromptSizeAndSort(): void
    {
        $out = $this->germanFrame();

        $this->assertStringContainsString('Suchen:', $out, 'search prompt (025)');
        $this->assertStringContainsString('VERZ', $out, 'dir size token (056)');
        $this->assertStringContainsString('[Name ↑', $out, 'sort header (060)');
        $this->assertStringNotContainsString('Search:', $out);
        $this->assertStringNotContainsString('name-asc', $out);
    }

    public function testTheEnglishFrameKeepsTheLegacyTokensByteIdentical(): void
    {
        T::setLocale('en');
        $dirEntry  = new Entry('docs', true, 0, 0);
        $linkEntry = new Entry('cur', false, 0, 0, true);

        $this->assertSame('DIR', $dirEntry->displaySize());
        $this->assertSame('LINK', $linkEntry->displaySize());
    }

    public function testTheSizeColumnAbsorbsLongLocalizedTokens(): void
    {
        // ko size.dir '폴더' is 8 display cells — wider than the legacy
        // 7-cell field. The column widens, the name column gives up the
        // cells, and every entry row ends at the SAME composed cell
        // count (a drifting size column is the 056 failure shape).
        T::setLocale('ko');
        $m = Manager::start('/', '/', $this->fakeFs());
        $out = Renderer::render($m);

        $this->assertStringContainsString('폴더', $out);
        $entryRows = array_values(array_filter(
            explode("\n", $out),
            static fn(string $line): bool => str_contains($line, 'docs') || str_contains($line, 'notes.txt'),
        ));
        $this->assertNotEmpty($entryRows, 'the pane rows must carry the listing');
        // Right-pad math: both rows fill name(→nameCol) + size(→sizeCol)
        // fields; trailing padding inside the size field is stripped by
        // the pane border only if widths match, so composed widths agree.
        $widths = [];
        foreach ($entryRows as $row) {
            $widths[] = Width::of($row);
        }
        $this->assertCount(1, array_unique($widths), 'entry rows share one composed width: ' . json_encode($widths));
    }

    public function testTheLaunchDirReasonIsLocalized(): void
    {
        T::setLocale('de');
        $this->assertSame('existiert nicht', LaunchDir::refusal('/definitely/not/here-cf16'));

        T::setLocale('en');
        $this->assertSame('does not exist', LaunchDir::refusal('/definitely/not/here-cf16'));
    }

    public function testSortLabelsAndDirectionCoverTheCycle(): void
    {
        $cycle = [
            Sort::NameAsc, Sort::NameDesc, Sort::MtimeAsc,
            Sort::MtimeDesc, Sort::SizeAsc, Sort::SizeDesc,
        ];
        T::setLocale('en');
        $expect = ['Name', 'Name', 'Date', 'Date', 'Size', 'Size'];
        foreach ($cycle as $i => $sort) {
            $this->assertSame($expect[$i], $sort->label(), $sort->value);
            $this->assertSame($i % 2 === 0, $sort->isAscending(), $sort->value);
        }
    }

    /**
     * CF-37 (closes 076): the Italian catalogue shipped status.focus as a
     * byte-identical English copy while cs/pl/fr/ja all adapted the label —
     * pin that it is genuinely localized.
     */
    public function testTheItalianFocusLabelIsLocalized(): void
    {
        T::setLocale('en');
        $english = Lang::t('status.focus', ['side' => 'left']);

        T::setLocale('it');
        $italian = Lang::t('status.focus', ['side' => 'sinistro']);

        $this->assertStringContainsString('attivo:', $italian);
        $this->assertNotSame(str_replace('left', 'sinistro', $english), $italian, 'not the English frame re-skinned');
        $this->assertStringNotContainsString('focus:', $italian, 'the English label must be gone');
    }
}
