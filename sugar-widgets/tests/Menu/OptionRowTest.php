<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests\Menu;

use PHPUnit\Framework\TestCase;
use SugarCraft\Widgets\Input\TextEdit;
use SugarCraft\Widgets\Menu\OptionRow;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Style;

final class OptionRowTest extends TestCase
{
    private const B = "\x1b[1m";
    private const R = "\x1b[0m";
    private const SEL = "\x1b[38;2;255;255;255m\x1b[48;2;17;34;51m";

    private static function selBg(): Style
    {
        return Style::new()->bg('#112233');
    }

    private static function selFg(): Style
    {
        return Style::new()->fg('#ffffff');
    }

    /**
     * Visible cluster at display column $col of an ANSI string.
     */
    private static function at(string $line, int $col): string
    {
        $s = Ansi::strip($line);
        $x = 0;
        for ($i = 0, $n = \strlen($s); $i < $n;) {
            $g = Width::nextCluster($s, $i);
            if ($x === $col) {
                return $g;
            }
            $x += Width::string($g);
            $i += \strlen($g);
        }
        return '';
    }

    /** @return array{string, string} */
    private static function lines(string $out): array
    {
        $l = explode("\n", $out);
        self::assertCount(2, $l);
        return [$l[0], $l[1]];
    }

    // ─── golden pairs ────────────────────────────────────────────────

    public function testUnselectedGoldenPair(): void
    {
        $out = OptionRow::render('Update ms', '2000', false, false, true);
        $this->assertSame(
            self::B . str_repeat(' ', 10) . 'Update ms' . str_repeat(' ', 10) . self::R . "\n"
            . str_repeat(' ', 13) . '2000' . str_repeat(' ', 12),
            $out,
        );
    }

    public function testSelectedGoldenPairWithArrowsAndShiftedEnter(): void
    {
        $out = OptionRow::render(
            'Update ms', '2000', true, false, true,
            selBg: self::selBg(), selFg: self::selFg(), editable: true,
        );
        $plain = static fn(string $s): string => self::SEL . $s . self::R;
        $bold = static fn(string $s): string => self::B . self::SEL . $s . self::R;
        $this->assertSame(
            $bold(str_repeat(' ', 10) . 'Update ms' . str_repeat(' ', 10)) . "\n"
            . $plain(' ') . $bold('←')
            . $plain(str_repeat(' ', 11) . '2000' . str_repeat(' ', 8))
            . $bold('↵') . $plain(' ') . $bold('→') . $plain(' '),
            $out,
        );
    }

    // ─── geometry ────────────────────────────────────────────────────

    public function testBothLinesSpanTheDefaultColumns(): void
    {
        foreach ([[false, false], [true, false], [true, true]] as [$sel, $edit]) {
            [$a, $b] = self::lines(OptionRow::render('Name', TextEdit::new('v'), $sel, $edit, true, editable: true));
            $this->assertSame(29, Width::string($a));
            $this->assertSame(29, Width::string($b));
        }
    }

    public function testArrowColumnsAndEnterAtValueColWhenArrowsShow(): void
    {
        [, $b] = self::lines(OptionRow::render('X', '1', true, false, true, editable: true));
        $this->assertSame('←', self::at($b, 1));
        $this->assertSame('↵', self::at($b, 25));
        $this->assertSame('→', self::at($b, 27));
    }

    public function testArrowsHiddenWhenUnselectedOrNotTwoD(): void
    {
        [, $b] = self::lines(OptionRow::render('X', '1', false, false, true, editable: true));
        $this->assertStringNotContainsString('←', $b);
        $this->assertStringNotContainsString('↵', $b);
        [, $b] = self::lines(OptionRow::render('X', '1', true, false, false));
        $this->assertStringNotContainsString('←', $b);
        $this->assertStringNotContainsString('→', $b);
    }

    public function testEnterWithoutArrowsSitsAtColumn27(): void
    {
        [, $b] = self::lines(OptionRow::render('X', 'text', true, false, false, editable: true));
        $this->assertSame('↵', self::at($b, 27));
    }

    public function testTtyModeUsesE(): void
    {
        [, $b] = self::lines(OptionRow::render('X', 'text', true, false, false, editable: true, tty: true));
        $this->assertSame('E', self::at($b, 27));
        $this->assertStringNotContainsString('↵', $b);
    }

    public function testEditingRendersTextEditInValueColMinusOneAndHidesArrows(): void
    {
        $out = OptionRow::render('Update ms', TextEdit::new('2000'), true, true, true, editable: true);
        [, $b] = self::lines($out);
        // view(24) of "2000" + end caret = 5 cells, centred in 25 (10 | 10).
        $this->assertSame(
            str_repeat(' ', 12) . "2000\x1b[4m \x1b[24m" . str_repeat(' ', 10)
            . self::B . '↵' . self::R . ' ',
            $b,
        );
        $this->assertStringNotContainsString('←', $b);
        $this->assertStringNotContainsString('→', $b);
    }

    public function testEditingIgnoredOnUnselectedRow(): void
    {
        [, $b] = self::lines(OptionRow::render('X', TextEdit::new('abc'), false, true, true));
        $this->assertStringNotContainsString("\x1b[4m", $b);
        $this->assertSame(Ansi::strip($b), $b);
    }

    public function testCentringPutsOddColumnOnTheLeft(): void
    {
        // btop cjust: ceil(gap/2) left. 'ab' in 29 → 14 | 13.
        [$a] = self::lines(OptionRow::render('ab', '', false, false, false));
        $this->assertSame(str_repeat(' ', 14) . 'ab' . str_repeat(' ', 13), Ansi::strip($a));
    }

    public function testWideNameCentresByCellsAndTruncates(): void
    {
        [$a] = self::lines(OptionRow::render('漢字', 'v', false, false, false));
        $this->assertSame(str_repeat(' ', 13) . '漢字' . str_repeat(' ', 12), Ansi::strip($a));
        [$a] = self::lines(OptionRow::render(str_repeat('漢', 20), 'v', false, false, false));
        $this->assertLessThanOrEqual(29, Width::string($a));
    }

    public function testEnterOverlayOnWideClusterKeepsColumns(): void
    {
        // A value wide enough to reach column 25: the straddling wide
        // cluster is replaced by a space + glyph so later columns don't move.
        $value = str_repeat('漢', 11); // 22 cells, starts at col 4 → last 漢 spans 24-25
        [, $b] = self::lines(OptionRow::render('X', $value, true, false, true, editable: true));
        $this->assertSame(' ', self::at($b, 24));
        $this->assertSame('漢', self::at($b, 22));
        $this->assertSame('↵', self::at($b, 25));
        $this->assertSame('→', self::at($b, 27));
        $this->assertSame(29, Width::string($b));
    }

    public function testCustomColumns(): void
    {
        [$a, $b] = self::lines(OptionRow::render('N', 'v', true, false, true, nameCol: 14, valueCol: 10, editable: true));
        $this->assertSame(14, Width::string($a));
        $this->assertSame(14, Width::string($b));
        $this->assertSame('←', self::at($b, 1));
        $this->assertSame('↵', self::at($b, 10));
        $this->assertSame('→', self::at($b, 12));
    }

    public function testUnselectedTitleAndMainStylesApply(): void
    {
        $out = OptionRow::render(
            'N', 'v', false, false, false,
            title: Style::new()->fg('#ff0000'), main: Style::new()->fg('#00ff00'),
        );
        [$a, $b] = self::lines($out);
        $this->assertStringStartsWith(self::B . "\x1b[38;2;255;0;0m", $a);
        $this->assertStringStartsWith("\x1b[38;2;0;255;0m", $b);
    }

    // ─── one-line mode (renderRow) ───────────────────────────────────

    public function testRenderRowUnselectedIsLabelLeftValueRight(): void
    {
        $this->assertSame(
            '   ' . self::B . 'Update ms' . str_repeat(' ', 9) . self::R . '2000    ',
            OptionRow::renderRow('Update ms', '2000', false, false, true),
        );
    }

    public function testRenderRowSelectedIsOneBlockWithArrowsAndEnterSlots(): void
    {
        $plain = static fn(string $s): string => self::SEL . $s . self::R;
        $bold = static fn(string $s): string => self::B . self::SEL . $s . self::R;
        $this->assertSame(
            $plain(' ') . $bold('←') . $plain(' ')
            . $bold('Update ms' . str_repeat(' ', 9))
            . $plain('2000') . $bold('↵') . $plain(' ') . $bold('→') . $plain(' '),
            OptionRow::renderRow(
                'Update ms', '2000', true, false, true,
                selBg: self::selBg(), selFg: self::selFg(), editable: true,
            ),
        );
    }

    public function testRenderRowEnterOnlyWithoutArrowsSitsInLastSlot(): void
    {
        $out = OptionRow::renderRow(
            'N', 'v', true, false, false,
            selBg: self::selBg(), selFg: self::selFg(), editable: true, tty: true,
        );
        $this->assertSame(' ', self::at($out, 1));
        $this->assertSame(' ', self::at($out, 25), 'the shifted ↵ slot is blank without arrows');
        $this->assertSame('E', self::at($out, 27), 'tty substitutes E for ↵');
        $this->assertSame(29, Width::string($out));
    }

    public function testRenderRowEditingCarriesTheCaretInlineAndHidesArrows(): void
    {
        $out = OptionRow::renderRow(
            'Presets', TextEdit::new('cpu'), true, true, true,
            selBg: self::selBg(), selFg: self::selFg(), editable: true,
        );
        $this->assertSame(29, Width::string($out));
        $this->assertSame(' ', self::at($out, 1), 'no ← while editing');
        $this->assertSame(' ', self::at($out, 25), 'the shifted slot stays blank without arrows');
        $this->assertSame('↵', self::at($out, 27), 'the confirm glyph keeps field - 2');
        $this->assertStringContainsString('cpu' . "\x1b[4m", $out, 'caret box rides the value slot');
    }

    public function testRenderRowWideFieldsAndClipping(): void
    {
        $out = OptionRow::renderRow('Presets', 'cpu:1:default,proc:0:default', false, false, true, field: 43);
        $this->assertSame(43, Width::string($out));
        $this->assertSame('   Presets    cpu:1:default,proc:0:defa    ', Ansi::strip($out));

        // Tight band: the label keeps ROW_LABEL_MIN cells (6) before the value clips.
        $tight = OptionRow::renderRow(str_repeat('L', 24), str_repeat('v', 30), false, false, false, field: 29);
        $this->assertSame(29, Width::string($tight));
        $this->assertSame('   LLLLLL  ' . str_repeat('v', 14) . '    ', Ansi::strip($tight));

        // The 16-cell floor still renders (band 9: label 1 + value 6 min).
        $tiny = OptionRow::renderRow('Name', 'value', true, false, true, selBg: self::selBg(), selFg: self::selFg());
        $this->assertSame(29, Width::string($tiny));
        $tiny = OptionRow::renderRow('Name', 'value', false, false, false, field: 10, title: Style::new(), main: Style::new());
        $this->assertSame(16, Width::string($tiny), 'field floors at 16');
    }
}
