<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use SugarCraft\Buffer\Buffer;
use SugarCraft\Buffer\Cell;
use SugarCraft\Buffer\Style;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Toast\{Alert, Position, SymbolSet, Toast, ToastType};
use PHPUnit\Framework\TestCase;

final class ToastRenderingTest extends TestCase
{
    private Toast $toast;

    protected function setUp(): void
    {
        $this->toast = Toast::new(50);
    }

    public function testAlertWithExpiryPath(): void
    {
        $t = $this->toast
            ->withDuration(10.0)
            ->alert(ToastType::Info, 'test message');

        $this->assertCount(1, $this->getQueue($t));
    }

    public function testProgressToastWithExpiryPath(): void
    {
        $t = $this->toast
            ->withDuration(5.0)
            ->progressToast(ToastType::Success, 'loading...', 0.5);

        $this->assertCount(1, $this->getQueue($t));
    }

    public function testToastTypeTryFromValid(): void
    {
        $type = ToastType::tryFrom('error');
        $this->assertSame(ToastType::Error, $type);
    }

    public function testToastTypeTryFromInvalid(): void
    {
        $type = ToastType::tryFrom('not_a_type');
        $this->assertNull($type);
    }

    public function testAlertWithStringType(): void
    {
        $t = $this->toast->alert('warning', 'a message');
        $this->assertCount(1, $this->getQueue($t));
    }

    public function testAlertWithInvalidStringTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->toast->alert('invalid_type', 'message');
    }

    public function testFillViewportFromStringBackground(): void
    {
        $buf = Buffer::new(50, 10);
        $lines = ["line one", "line two", "line three"];
        $result = $this->invokeFillViewportFromString($buf, $lines);

        $this->assertInstanceOf(Buffer::class, $result);
    }

    public function testRenderAlertToBuffer(): void
    {
        $alert = new Alert(ToastType::Info, 'Hello World');
        $buf = $this->invokeRenderAlertToBuffer($alert);

        $this->assertInstanceOf(Buffer::class, $buf);
        $this->assertGreaterThan(0, $buf->width());
        $this->assertGreaterThan(0, $buf->height());
    }

    public function testNextClusterOneByteUtf8(): void
    {
        $cluster = $this->invokeNextCluster('a', 0);
        $this->assertSame('a', $cluster);
    }

    public function testNextClusterTwoByteUtf8(): void
    {
        $cluster = $this->invokeNextCluster("\xc3\xa9", 0);
        $this->assertSame("\xc3\xa9", $cluster);
    }

    public function testNextClusterThreeByteUtf8(): void
    {
        $cluster = $this->invokeNextCluster("\xe4\xb8\x80", 0);
        $this->assertSame("\xe4\xb8\x80", $cluster);
    }

    public function testNextClusterFourByteUtf8(): void
    {
        $cluster = $this->invokeNextCluster("\xf0\x9f\x98\x80", 0);
        $this->assertSame("\xf0\x9f\x98\x80", $cluster);
    }

    public function testNextClusterGraphemeExtractFallback(): void
    {
        $cluster = $this->invokeNextCluster('abc', 0);
        $this->assertSame('a', $cluster);

        $cluster = $this->invokeNextCluster('abc', 1);
        $this->assertSame('b', $cluster);

        $cluster = $this->invokeNextCluster('abc', 2);
        $this->assertSame('c', $cluster);
    }

    public function testPlaceAnsiStringAtZeroWidthCell(): void
    {
        $buf = Buffer::new(20, 3);
        $ansiString = "\x1b[1mm\x1b[0m\xcc\x80";
        $result = $this->invokePlaceAnsiStringAt($buf, 0, 0, $ansiString);

        $this->assertInstanceOf(Buffer::class, $result);
    }

    public function testPlaceAnsiStringAtWideChar(): void
    {
        $buf = Buffer::new(20, 3);
        $result = $this->invokePlaceAnsiStringAt($buf, 0, 0, "\xe4\xb8\x80\xe4\xb8\x80\xe4\xb8\x80");

        $this->assertInstanceOf(Buffer::class, $result);
    }

    public function testSgrToBufferStyleBold(): void
    {
        $style = $this->invokeSgrToBufferStyle('1');
        $this->assertNotNull($style);
        $this->assertTrue($style->hasBold());
    }

    public function testSgrToBufferStyleForeground(): void
    {
        $style = $this->invokeSgrToBufferStyle('31');
        $this->assertNotNull($style);
        $this->assertNotNull($style->fg());
    }

    public function testSgrToBufferStyleBrightForeground(): void
    {
        $style = $this->invokeSgrToBufferStyle('91');
        $this->assertNotNull($style);
        $this->assertNotNull($style->fg());
    }

    public function testSgrToBufferStyleResetReturnsNull(): void
    {
        $style = $this->invokeSgrToBufferStyle('0');
        $this->assertNull($style);
    }

    public function testSgrToBufferStyleCombined(): void
    {
        $style = $this->invokeSgrToBufferStyle('1;31');
        $this->assertNotNull($style);
        $this->assertTrue($style->hasBold());
        $this->assertNotNull($style->fg());
    }

    public function testAnsiColorToRgbStandard(): void
    {
        $rgb = $this->invokeAnsiColorToRgb(0, false);
        $this->assertSame(0x000000, $rgb);
    }

    public function testAnsiColorToRgbBright(): void
    {
        // Slot 8 = candy-core ANSI16_RGB[8] = [127,127,127] (xterm bright black).
        $rgb = $this->invokeAnsiColorToRgb(0, true);
        $this->assertSame(0x7f7f7f, $rgb);
    }

    public function testAnsiColorToRgbOutOfRange(): void
    {
        // Out-of-range falls back to the canonical white slot 7 = [229,229,229].
        $rgb = $this->invokeAnsiColorToRgb(99, false);
        $this->assertSame(0xe5e5e5, $rgb);
    }

    public function testGraphemeWidthCombiningMark(): void
    {
        $w = $this->invokeGraphemeWidth("\xcc\x80");
        $this->assertSame(0, $w);
    }

    public function testGraphemeWidthWideEastAsian(): void
    {
        $w = $this->invokeGraphemeWidth("\xe4\xb8\x80");
        $this->assertSame(2, $w);
    }

    public function testGraphemeWidthEmpty(): void
    {
        $w = $this->invokeGraphemeWidth('');
        $this->assertSame(0, $w);
    }

    public function testViewWithActiveAlertOnBackground(): void
    {
        $t = $this->toast
            ->withPosition(Position::TopLeft)
            ->alert(ToastType::Info, 'Hello world');

        $bg = \str_repeat("background line\n", 10);
        $result = $t->View($bg, 80, 10);

        $this->assertIsString($result);
        $this->assertStringContainsString('Hello world', $result);
    }

    public function testNullMessageRendersEmptyHeaderInsteadOfTypeError(): void
    {
        // Finding 10 / Item 3.1: renderAlert()'s width probe fed the nullable
        // message straight into Width::string(), so ANY null-message alert
        // fatalled under strict_types before the `?? ''` header fallback ran.
        $t = $this->toast
            ->withPosition(Position::TopLeft)
            ->push(new Alert(ToastType::Info, null));

        $bg = \str_repeat("background line\n", 10);
        $result = $t->View($bg, 80, 10);

        $this->assertStringContainsString('╭', $result, 'the empty box must still render');
        $this->assertStringContainsString('background line', $result);
    }

    private function invokeFillViewportFromString(Buffer $buf, array $lines): Buffer
    {
        $ref = new \ReflectionClass($this->toast);
        $meth = $ref->getMethod('fillViewportFromString');
        $meth->setAccessible(true);
        return $meth->invoke($this->toast, $buf, $lines);
    }

    private function invokeRenderAlertToBuffer(Alert $alert): Buffer
    {
        $ref = new \ReflectionClass($this->toast);
        $meth = $ref->getMethod('renderAlertToBuffer');
        $meth->setAccessible(true);
        return $meth->invoke($this->toast, $alert, 50);
    }

    private function invokeNextCluster(string $s, int $i): string
    {
        $ref = new \ReflectionClass($this->toast);
        $meth = $ref->getMethod('nextCluster');
        $meth->setAccessible(true);
        return $meth->invoke($this->toast, $s, $i);
    }

    private function invokePlaceAnsiStringAt(Buffer $buf, int $col, int $row, string $s): Buffer
    {
        $ref = new \ReflectionClass($this->toast);
        $meth = $ref->getMethod('placeAnsiStringAt');
        $meth->setAccessible(true);
        return $meth->invoke($this->toast, $buf, $col, $row, $s);
    }

    private function invokeSgrToBufferStyle(string $sgr): ?Style
    {
        $ref = new \ReflectionClass($this->toast);
        $meth = $ref->getMethod('sgrToBufferStyle');
        $meth->setAccessible(true);
        return $meth->invoke($this->toast, $sgr);
    }

    private function invokeAnsiColorToRgb(int $idx, bool $bright): int
    {
        $ref = new \ReflectionClass($this->toast);
        $meth = $ref->getMethod('ansiColorToRgb');
        $meth->setAccessible(true);
        return $meth->invoke($this->toast, $idx, $bright);
    }

    private function invokeGraphemeWidth(string $g): int
    {
        $ref = new \ReflectionClass($this->toast);
        $meth = $ref->getMethod('graphemeWidth');
        $meth->setAccessible(true);
        return $meth->invoke($this->toast, $g);
    }

    // ------------------------------------------------------------------
    // Audit M7 — SGR fidelity. The pre-fix decoder read only 30-37/90-97
    // fg + bold, and mis-parsed 38;2;r;g;b as three separate codes (a
    // truecolor red silently became ANSI black). applySgr now folds each
    // sequence onto the previous state, like a terminal does.
    // ------------------------------------------------------------------

    public static function sgrFidelityCases(): iterable
    {
        yield 'truecolor fg' => ['38;2;10;20;30', ['fg' => 0x0A141E, 'bg' => null, 'attrs' => 0]];
        yield '256 fg cube' => ['38;5;196', ['fg' => 0xFF0000, 'bg' => null, 'attrs' => 0]];
        yield '256 fg slot-4 blue is canonical #0000EE' => ['38;5;4', ['fg' => 0x0000EE, 'bg' => null, 'attrs' => 0]];
        yield '256 fg grayscale ramp' => ['38;5;232', ['fg' => 0x080808, 'bg' => null, 'attrs' => 0]];
        yield '256 bg' => ['48;5;4', ['fg' => null, 'bg' => 0x0000EE, 'attrs' => 0]];
        yield 'truecolor bg' => ['48;2;30;58;95', ['fg' => null, 'bg' => 0x1E3A5F, 'attrs' => 0]];
        yield 'bg ansi' => ['41', ['fg' => null, 'bg' => 0xCD0000, 'attrs' => 0]];
        yield 'underline' => ['4', ['fg' => null, 'bg' => null, 'attrs' => Style::ATTR_UNDERLINE]];
        yield 'reverse' => ['7', ['fg' => null, 'bg' => null, 'attrs' => Style::ATTR_REVERSE]];
        yield 'faint' => ['2', ['fg' => null, 'bg' => null, 'attrs' => Style::ATTR_FAINT]];
        yield 'italic' => ['3', ['fg' => null, 'bg' => null, 'attrs' => Style::ATTR_ITALIC]];
        yield 'blink' => ['5', ['fg' => null, 'bg' => null, 'attrs' => Style::ATTR_BLINK]];
        yield 'invisible' => ['8', ['fg' => null, 'bg' => null, 'attrs' => Style::ATTR_INVISIBLE]];
        yield 'strike' => ['9', ['fg' => null, 'bg' => null, 'attrs' => Style::ATTR_STRIKE]];
        yield 'overline' => ['53', ['fg' => null, 'bg' => null, 'attrs' => Style::ATTR_OVERLINE]];
        yield 'all attrs at once' => ['1;2;3;4;5;7;8;9;53', [
            'fg' => null, 'bg' => null,
            'attrs' => Style::ATTR_BOLD | Style::ATTR_FAINT | Style::ATTR_ITALIC
                | Style::ATTR_UNDERLINE | Style::ATTR_BLINK | Style::ATTR_REVERSE
                | Style::ATTR_INVISIBLE | Style::ATTR_STRIKE | Style::ATTR_OVERLINE,
        ]];
        yield 'bright bg 100-107' => ['100', ['fg' => null, 'bg' => 0x7F7F7F, 'attrs' => 0]];
        // candy-buffer's emitter spells every style as an '0;…' preamble; a
        // re-decode of its own output must reproduce the style, not null it.
        yield 'reset preamble then bold survives' => ['0;1', ['fg' => null, 'bg' => null, 'attrs' => Style::ATTR_BOLD]];
        // Fail-soft: malformed extended payloads stop the walk without
        // inventing colours (old decoder turned 38;2;10;20;30 into black).
        yield 'malformed selector' => ['38;9', null];
        yield 'truncated rgb' => ['38;2;10;20', null];
        yield '256 out of range' => ['38;5;300', null];
        yield '256 truncated' => ['48;5', null];
        yield 'bare reset' => ['0', null];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sgrFidelityCases')]
    public function testSgrDecoderPreservesEverySgrFacet(string $sgr, ?array $expect): void
    {
        $style = $this->invokeApplySgr(null, $sgr);

        if ($expect === null) {
            $this->assertNull($style, "sequence {$sgr} must decode to no style");
            return;
        }
        $this->assertNotNull($style, "sequence {$sgr} must decode to a style");
        $this->assertSame($expect['fg'], $style->fg(), "fg for {$sgr}");
        $this->assertSame($expect['bg'], $style->bg(), "bg for {$sgr}");
        $this->assertSame($expect['attrs'], $style->attrs(), "attrs for {$sgr}");
    }

    public function testSgrOffCodesClearOnlyTheirOwnFacet(): void
    {
        $boldRed = $this->invokeApplySgr(null, '1;31;42');
        $this->assertNotNull($boldRed);

        $noBold = $this->invokeApplySgr($boldRed, '22');
        $this->assertNotNull($noBold);
        $this->assertSame(0, $noBold->attrs() & Style::ATTR_BOLD, '22 turns bold off');
        $this->assertSame(0x00CD00, $noBold->bg(), '22 leaves the bg alone');

        $noFg = $this->invokeApplySgr($boldRed, '39');
        $this->assertNotNull($noFg);
        $this->assertNull($noFg->fg(), '39 returns the default foreground');
        $this->assertNotNull($noFg->bg(), '39 leaves the bg');

        $noBg = $this->invokeApplySgr($boldRed, '49');
        $this->assertNotNull($noBg);
        $this->assertNull($noBg->bg(), '49 returns the default background');

        $noUnderline = $this->invokeApplySgr($this->invokeApplySgr(null, '4'), '24');
        $this->assertNull($noUnderline, '24 clears the only attribute → style collapses to null');
    }

    public function testSgrStateCarriesAcrossSequencesWhilePainting(): void
    {
        // The pre-fix decoder REPLACED the style per sequence, so a bold
        // opener followed by a colour reset each other. Terminals accumulate.
        $bold = $this->invokeApplySgr(null, '1');
        $this->assertNotNull($bold);
        $boldRed = $this->invokeApplySgr($bold, '31');
        $this->assertNotNull($boldRed);
        $this->assertSame(Style::ATTR_BOLD, $boldRed->attrs(), 'bold survives the later colour sequence');
        $this->assertSame(0xCD0000, $boldRed->fg());

        $buf = $this->invokePlaceAnsiStringAt(
            Buffer::new(4, 1), 0, 0, "\x1b[1mA\x1b[31mB\x1b[0mC"
        );
        $styleA = $buf->cellAt(0, 0)?->style;
        $styleB = $buf->cellAt(1, 0)?->style;
        $styleC = $buf->cellAt(2, 0)?->style;

        $this->assertNotNull($styleA);
        $this->assertSame(Style::ATTR_BOLD, $styleA->attrs());
        $this->assertNull($styleA->fg());
        $this->assertNotNull($styleB);
        $this->assertSame(Style::ATTR_BOLD, $styleB->attrs(), 'B keeps bold while gaining red');
        $this->assertSame(0xCD0000, $styleB->fg());
        $this->assertNull($styleC, 'explicit reset clears back to the default style');
    }

    public function testThinWrapperMatchesRawApplySgr(): void
    {
        // sgrToBufferStyle stays the from-nothing entry point of the fold.
        foreach (['1', '31', '91', '1;32', '0', '38;2;1;2;3', '38;9'] as $sgr) {
            $this->assertEquals(
                $this->invokeApplySgr(null, $sgr),
                $this->invokeSgrToBufferStyle($sgr),
                "wrapper drifted from applySgr(null, {$sgr})",
            );
        }
    }

    // ------------------------------------------------------------------
    // Audit M8 — width oracle. graphemeWidth used to be a private table
    // that forked candy-core's Width: emoji measured 1, so an emoji header
    // row overflowed its own box border by a cell.
    // ------------------------------------------------------------------

    public static function widthOracleTable(): iterable
    {
        yield 'ascii' => ['a', 1];
        yield 'latin1 precomposed' => ['é', 1];
        yield 'combining mark' => ["\u{0301}", 0];
        yield 'CJK wide' => ['あ', 2];
        yield 'emoji' => ['🎉', 2];
        yield 'box drawing' => ['─', 1];
        yield 'info glyph' => ['ℹ', 1];
        yield 'dingbat' => ['✨', 1];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('widthOracleTable')]
    public function testGraphemeWidthAgreesWithCanonicalOracle(string $glyph, int $expect): void
    {
        $canonical = Width::string($glyph);
        $this->assertSame($expect, $this->invokeGraphemeWidth($glyph), "table row for {$glyph} went stale");
        $this->assertSame(
            \min(2, \max(0, $canonical)),
            $this->invokeGraphemeWidth($glyph),
            "fork re-diverged from Core\Util\Width for {$glyph}",
        );
    }

    public function testGraphemeWidthTabChargesOneCell(): void
    {
        // Declared divergence: candy-core bills a tab TAB_WIDTH=4 (E69), but
        // a cell grid has no 4-wide Cell contract (widths are 0/1/2), so the
        // toast painter charges one cell.
        $this->assertSame(4, Width::string("\t"), 'the canonical oracle bills a tab four columns');
        $this->assertSame(1, $this->invokeGraphemeWidth("\t"), 'the cell painter charges one');
    }

    public function testEmojiHeaderRowStaysInsideTheBoxBorder(): void
    {
        // End-to-end M8 symptom: an emoji message used to render a row that
        // measured wider than the box (fork scored 🎉 as 1) and spilled past
        // the closing │. Pre-fix probe: header row measured 31 in a 30 box.
        $view = $this->toast
            ->withPosition(Position::TopLeft)
            ->alert(ToastType::Info, 'party 🎉')
            ->View(\str_repeat("line\n", 12), 80, 12);

        foreach (\explode("\n", $view) as $row) {
            $clean = \rtrim(Ansi::strip($row), ' ');
            if ($clean === '' || $clean[0] !== '│') {
                continue;
            }
            $this->assertSame(50, Width::string($clean), 'body rows measure exactly the box width');
            $this->assertStringEndsWith('│', $clean, 'row closes on its own border');
        }
        $this->assertStringContainsString('party 🎉', $view);
    }

    private function invokeApplySgr(?Style $base, string $sgr): ?Style
    {
        $meth = new \ReflectionMethod($this->toast, 'applySgr');
        $meth->setAccessible(true);
        return $meth->invoke($this->toast, $base, $sgr);
    }

    private function getQueue(Toast $t): array
    {
        $ref = (new \ReflectionClass($t))->getProperty('queue');
        $ref->setAccessible(true);
        return $ref->getValue($t);
    }
}
