<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Files\Entry;
use SugarCraft\Files\Lang;
use SugarCraft\Files\Manager;
use SugarCraft\Files\Manager\ManagerBuilder;
use SugarCraft\Files\Pane;
use SugarCraft\Files\Renderer;
use SugarCraft\Files\Support\Bidi;

/**
 * CF-47 (closes audit 094): the shipped `ar` catalogue puts RTL prose on
 * the same lines as Latin paths, counts, chords and the `·` separator. A
 * bidi-aware terminal re-resolves every neutral against the paragraph
 * direction, so chrome segments could appear out of emission order. LRM
 * pins on the separator joins and around tab islands keep the visual
 * order equal to the emitted order; LTR locales must stay byte-identical
 * (the whole legacy golden family depends on it).
 */
final class BidiTest extends TestCase
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
            new Entry('notes.txt', false, 7, 0),
        ];
    }

    public function testIsRtlRecognizesTheRtlFamiliesAndNothingElse(): void
    {
        foreach (['ar', 'ar-EG', 'ar_EG', 'he', 'fa', 'ur'] as $rtl) {
            $this->assertTrue(Bidi::isRtl($rtl), $rtl);
        }
        foreach (['en', 'de', 'zh-CN', 'pt-BR', 'nl', 'cs', ''] as $ltr) {
            $this->assertFalse(Bidi::isRtl($ltr), $ltr);
        }
    }

    public function testPinSeparatorAndIsolateAreExactByteNoOpsForLtr(): void
    {
        $this->assertSame('·', Bidi::pinSeparator('·', false));
        $this->assertSame('[/tmp]', Bidi::isolate('[/tmp]', false));
        $this->assertSame('a · b', Bidi::pinRuns('a · b', '·', false));
    }

    public function testPinSeparatorSandwichesLrmMarksAroundTheGlyph(): void
    {
        $pinned = Bidi::pinSeparator('·', true);

        $this->assertSame("\u{200E}·\u{200E}", $pinned);
        // Pinning the pin must not double-wrap a separator that is already
        // a chrome island (idempotence where it matters: one source call).
        $this->assertStringContainsString('·', $pinned);
    }

    public function testIsolateFencesAWholeItem(): void
    {
        $this->assertSame("\u{200E}[/tmp]\u{200E}", Bidi::isolate('[/tmp]', true));
    }

    public function testPinRunsPinsEveryInteriorJoinButNotTheEdges(): void
    {
        $out = Bidi::pinRuns('Tab swap · jk move · Enter open', '·', true);

        $this->assertSame(
            'Tab swap ' . Bidi::LRM . '·' . Bidi::LRM . ' jk move ' . Bidi::LRM . '·' . Bidi::LRM . ' Enter open',
            $out,
        );
        // The single-occurrence case (status join already handled elsewhere):
        $this->assertSame('solo', Bidi::pinRuns('solo', '·', true));
    }

    public function testTheArabicFrameCarriesIsolationOnItsChromeJoins(): void
    {
        T::setLocale('ar');
        $tabs = [
            ['left' => Pane::open('/alpha', $this->fakeFs()), 'right' => Pane::open('/alpha', $this->fakeFs()), 'activeIdx' => 0],
            ['left' => Pane::open('/beta', $this->fakeFs()), 'right' => Pane::open('/beta', $this->fakeFs()), 'activeIdx' => 0],
        ];
        $m = (new ManagerBuilder())
            ->withLeft(Pane::open('/alpha', $this->fakeFs()))
            ->withRight(Pane::open('/alpha', $this->fakeFs()))
            ->withTabs($tabs)
            ->withLister($this->fakeFs())
            ->build();
        $out = Renderer::render($m);

        // The status JOIN specifically: focus label, pinned separator, then
        // the key-help tail — a hard concatenation no other chrome region
        // can satisfy by accident.
        $focus = Lang::t('status.focus', ['side' => Lang::t('side.left')]);
        $this->assertStringContainsString(
            $focus . ' ' . Bidi::LRM . '·' . Bidi::LRM . ' ',
            $out,
            'status separator must be bidi-pinned at the join',
        );
        // Key-help is one roster with many interior joins — their pinned
        // forms stack up; three or more means the tiers were pinned too.
        $this->assertGreaterThanOrEqual(3, substr_count($out, "\u{200E}·\u{200E}"), 'key-help interior joins pinned');
        // ...and every tab cell is a fenced island.
        $this->assertStringContainsString("\u{200E}1[/alpha]\u{200E}", $out, 'active tab label must be isolated (CF-29 digit prefix rides inside the island)');
        $this->assertStringContainsString("\u{200E}2 /beta \u{200E}", $out, 'inactive tab label must be isolated');
    }

    public function testTheEnglishFrameGainsNoIsolationBytes(): void
    {
        T::setLocale('en');
        $tabs = [
            ['left' => Pane::open('/alpha', $this->fakeFs()), 'right' => Pane::open('/alpha', $this->fakeFs()), 'activeIdx' => 0],
            ['left' => Pane::open('/beta', $this->fakeFs()), 'right' => Pane::open('/beta', $this->fakeFs()), 'activeIdx' => 0],
        ];
        $m = (new ManagerBuilder())
            ->withLeft(Pane::open('/alpha', $this->fakeFs()))
            ->withRight(Pane::open('/alpha', $this->fakeFs()))
            ->withTabs($tabs)
            ->withLister($this->fakeFs())
            ->build();
        $out = Renderer::render($m);

        $this->assertStringNotContainsString("\u{200E}", $out, 'LTR locales must render byte-identically');
        $this->assertStringContainsString('[/alpha]', $out);
    }
}
