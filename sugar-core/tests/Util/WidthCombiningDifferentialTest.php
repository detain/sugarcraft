<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SugarCraft\Core\Util\Width;

/**
 * Standing differential test for the SC-1 width-table rework (2026-11).
 *
 * The one-off proof for this change was a full census: every codepoint
 * U+0000..U+10FFFF measured against Unicode 16.0.0 General_Category,
 * East_Asian_Width and Emoji_Presentation data under the class's declared
 * policy layer (see the WIDTH POLICY paragraph on the Width class docblock):
 * zero disagreements after the fix, 10,116 before it. A full sweep cannot
 * ride the suite without checked-in UCD data, so this test pins the
 * boundaries instead:
 *
 * - every run edge (lo-1, lo, hi, hi+1) of the COMBINING_RANGES table;
 * - every endpoint and three interior samples of every isWide() leg,
 *   including the 25 legs the SC-1 census added and the U+FF01 head fix;
 * - full membership of the combining table (all 1,761 codepoints score
 *   zero), walked from the parsed source text, not the runtime constant,
 *   so source-level hand-divergence is caught even where constant and
 *   behaviour would agree with each other;
 * - the named review claims that the data REFUTED (U+1D165..U+1D166 are
 *   Mc spacing marks, U+1CE9 is a letter, U+309B/U+309C are the fullwidth
 *   voicing marks) — pinned so nobody "refixes" them backwards.
 */
final class WidthCombiningDifferentialTest extends TestCase
{
    /**
     * Flat lo,hi integers parsed from the Width.php source text itself.
     *
     * @return list<int>
     */
    public static function sourceTableRuns(): array
    {
        $src = (string) \file_get_contents(\dirname(__DIR__, 2) . '/src/Util/Width.php');
        $open = \strpos($src, 'COMBINING_RANGES = [');
        self::assertIsInt($open, 'the combining table must exist by name');
        $close = \strpos($src, '];', $open);
        self::assertIsInt($close);
        $slice = \substr($src, $open, $close - $open);
        \preg_match_all('/0x([0-9A-Fa-f]{1,6})/', $slice, $m);
        $nums = [];
        foreach ($m[1] as $hex) {
            $nums[] = (int) \hexdec($hex);
        }

        return $nums;
    }

    public function testTheSourceTableMatchesTheRuntimeConstantAndIsWellFormed(): void
    {
        $nums = self::sourceTableRuns();
        $const = (new ReflectionClass(Width::class))->getConstant('COMBINING_RANGES');
        self::assertIsArray($const);
        self::assertSame($const, $nums, 'source text and runtime constant must be one table');
        self::assertCount(696, $nums, '348 merged runs emit two numbers each');

        $prevHi = -1;
        for ($k = 0; $k < count($nums); $k += 2) {
            self::assertGreaterThan($prevHi + 1, $nums[$k], "run at offset $k must not touch or overlap its predecessor");
            self::assertLessThanOrEqual($nums[$k + 1], $nums[$k], "run at offset $k must be ordered");
            $prevHi = $nums[$k + 1];
        }
    }

    public function testEveryCodepointInTheCombiningTableScoresZero(): void
    {
        $nums = self::sourceTableRuns();
        $visited = 0;
        for ($k = 0; $k < count($nums); $k += 2) {
            for ($cp = $nums[$k]; $cp <= $nums[$k + 1]; $cp++) {
                self::assertSame(0, Width::of(\mb_chr($cp)), 'U+' . \sprintf('%04X', $cp) . ' must be zero width');
                $visited++;
            }
        }
        self::assertSame(1761, $visited, 'the table must still cover 1,761 codepoints');
    }

    public function testThePolicyLayerAndLadderStillAnswer(): void
    {
        self::assertSame(4, Width::of("\t"), 'tab is TAB_WIDTH');
        self::assertSame(1, Width::of(' '), 'space stays one cell');
        self::assertSame(0, Width::of("\u{0300}"), 'ladder combining leg');
        self::assertSame(1, Width::of("\u{0370}"), 'ladder leg ends at U+036F');
        self::assertSame(0, Width::of("\u{20D0}"), 'ladder marks-for-symbols leg');
        self::assertSame(0, Width::of("\u{1AB0}"), 'ladder extended leg head');
        self::assertSame(0, Width::of("\u{1AFF}"), 'ladder extended leg tail (declared padding beyond assigned marks)');
        self::assertSame(0, Width::of("\u{20F0}"), 'ladder symbols leg tail');
        self::assertSame(1, Width::of("\u{2100}"), 'the padding legs end at U+20FF');
        self::assertSame(0, Width::of("\u{FE0F}"), 'ladder variation selector');
        self::assertSame(0, Width::of("\u{FE20}"), 'ladder half-marks leg head');
        self::assertSame(1, Width::of("\u{202A}"), 'bidi control Cf scores one by policy');
        self::assertSame(2, Width::of("\u{FF21}"), 'fullwidth latin A');
    }

    public function testReviewClaimsTheDataRefutedStayRefuted(): void
    {
        // The SC-1 findings sheet asked for these; Unicode 16.0.0 says no.
        self::assertSame(1, Width::of("\u{1D165}"), 'U+1D165 MUSICAL SYMBOL COMBINING STEM is Mc (spacing) — kept at one');
        self::assertSame(1, Width::of("\u{1D166}"), 'U+1D166 is Mc — kept at one');
        self::assertSame(0, Width::of("\u{1D167}"), 'U+1D167 COMBINING EUHOUIC is Mn — zero');
        self::assertSame(1, Width::of("\u{1CE9}"), 'U+1CE9 is a Cyrillic letter (Lo), not a mark');
        self::assertSame(2, Width::of("\u{309B}"), 'U+309B is the FULLWIDTH voicing mark (Sk, EAW W) — the halfwidth pair is U+FF9E');
        self::assertSame(2, Width::of("\u{309C}"), 'U+309C is FULLWIDTH too');
        self::assertSame(0, Width::of("\u{3099}"), 'U+3099 COMBINING is zero even inside the old wide block — zero-wins law');
        self::assertSame(0, Width::of("\u{309A}"), 'U+309A COMBINING is zero');
        self::assertSame(0, Width::of("\u{0962}"), 'U+0962 DEVANAGARI VOWEL VOCALIC L is Mn — zero, as the review claimed');
        self::assertSame(0, Width::of("\u{0963}"), 'U+0963 is Mn — zero');
        // Noncharacter holes inside the NEW exact legs stay narrow (no padding).
        self::assertSame(1, Width::of("\u{FE53}"), 'U+FE53 Cn hole — exact legs do not pad');
        self::assertSame(1, Width::of("\u{1AFF4}"), 'U+1AFF4 Cn between the exact Hangul-ext jamo legs');
        self::assertSame(1, Width::of("\u{1AFFC}"), 'U+1AFFC Cn between the exact legs');
        // The Kuhn head-off-by-one fix.
        self::assertSame(1, Width::of("\u{FF00}"), 'U+FF00 unassigned head slot is narrow (the leg is FF01..FF60)');
        self::assertSame(2, Width::of("\u{FF01}"), 'U+FF01 FULLWIDTH EXCLAMATION MARK is wide');
        self::assertSame(2, Width::of("\u{FF60}"), 'U+FF60 last wide slot');
        self::assertSame(1, Width::of("\u{FF61}"), 'U+FF61 HALFWIDTH IDEOGRAPHIC FULL STOP is halfwidth — the over-claim the review called out');
        self::assertSame(1, Width::of("\u{FFDC}"), 'U+FFDC last halfwidth Hangul slot');
        self::assertSame(1, Width::of("\u{FFDD}"), 'U+FFDD noncharacter stays narrow (no padding outside legs)');
    }

    public function testEveryGeneratedEdgeMatchesTheTable(): void
    {
        // Generated by the SC-1 lane from the post-fix class, which the
        // differential oracle census proved agrees with Unicode 16.0.0 data
        // at zero disagreements. Do not hand-edit: regenerate or re-prove.
        $edges = [
            0x0482 => 1, 0x0483 => 0, 0x0489 => 0, 0x048A => 1, 0x0590 => 1,
            0x0591 => 0, 0x05BD => 0, 0x05BE => 1, 0x05BF => 0, 0x05C0 => 1,
            0x05C1 => 0, 0x05C2 => 0, 0x05C3 => 1, 0x05C4 => 0, 0x05C5 => 0,
            0x05C6 => 1, 0x05C7 => 0, 0x05C8 => 1, 0x060F => 1, 0x0610 => 0,
            0x061A => 0, 0x061B => 1, 0x064A => 1, 0x064B => 0, 0x065F => 0,
            0x0660 => 1, 0x066F => 1, 0x0670 => 0, 0x0671 => 1, 0x06D5 => 1,
            0x06D6 => 0, 0x06DC => 0, 0x06DD => 1, 0x06DE => 1, 0x06DF => 0,
            0x06E4 => 0, 0x06E5 => 1, 0x06E6 => 1, 0x06E7 => 0, 0x06E8 => 0,
            0x06E9 => 1, 0x06EA => 0, 0x06ED => 0, 0x06EE => 1, 0x0710 => 1,
            0x0711 => 0, 0x0712 => 1, 0x072F => 1, 0x0730 => 0, 0x074A => 0,
            0x074B => 1, 0x07A5 => 1, 0x07A6 => 0, 0x07B0 => 0, 0x07B1 => 1,
            0x07EA => 1, 0x07EB => 0, 0x07F3 => 0, 0x07F4 => 1, 0x07FC => 1,
            0x07FD => 0, 0x07FE => 1, 0x0815 => 1, 0x0816 => 0, 0x0819 => 0,
            0x081A => 1, 0x081B => 0, 0x0823 => 0, 0x0824 => 1, 0x0825 => 0,
            0x0827 => 0, 0x0828 => 1, 0x0829 => 0, 0x082D => 0, 0x082E => 1,
            0x0858 => 1, 0x0859 => 0, 0x085B => 0, 0x085C => 1, 0x0896 => 1,
            0x0897 => 0, 0x089F => 0, 0x08A0 => 1, 0x08C9 => 1, 0x08CA => 0,
            0x08E1 => 0, 0x08E2 => 1, 0x08E3 => 0, 0x0902 => 0, 0x0903 => 1,
            0x0939 => 1, 0x093A => 0, 0x093B => 1, 0x093C => 0, 0x093D => 1,
            0x0940 => 1, 0x0941 => 0, 0x0948 => 0, 0x0949 => 1, 0x094C => 1,
            0x094D => 0, 0x094E => 1, 0x0950 => 1, 0x0951 => 0, 0x0957 => 0,
            0x0958 => 1, 0x0961 => 1, 0x0962 => 0, 0x0963 => 0, 0x0964 => 1,
            0x0980 => 1, 0x0981 => 0, 0x0982 => 1, 0x09BB => 1, 0x09BC => 0,
            0x09BD => 1, 0x09C0 => 1, 0x09C1 => 0, 0x09C4 => 0, 0x09C5 => 1,
            0x09CC => 1, 0x09CD => 0, 0x09CE => 1, 0x09E1 => 1, 0x09E2 => 0,
            0x09E3 => 0, 0x09E4 => 1, 0x09FD => 1, 0x09FE => 0, 0x09FF => 1,
            0x0A00 => 1, 0x0A01 => 0, 0x0A02 => 0, 0x0A03 => 1, 0x0A3B => 1,
            0x0A3C => 0, 0x0A3D => 1, 0x0A40 => 1, 0x0A41 => 0, 0x0A42 => 0,
            0x0A43 => 1, 0x0A46 => 1, 0x0A47 => 0, 0x0A48 => 0, 0x0A49 => 1,
            0x0A4A => 1, 0x0A4B => 0, 0x0A4D => 0, 0x0A4E => 1, 0x0A50 => 1,
            0x0A51 => 0, 0x0A52 => 1, 0x0A6F => 1, 0x0A70 => 0, 0x0A71 => 0,
            0x0A72 => 1, 0x0A74 => 1, 0x0A75 => 0, 0x0A76 => 1, 0x0A80 => 1,
            0x0A81 => 0, 0x0A82 => 0, 0x0A83 => 1, 0x0ABB => 1, 0x0ABC => 0,
            0x0ABD => 1, 0x0AC0 => 1, 0x0AC1 => 0, 0x0AC5 => 0, 0x0AC6 => 1,
            0x0AC7 => 0, 0x0AC8 => 0, 0x0AC9 => 1, 0x0ACC => 1, 0x0ACD => 0,
            0x0ACE => 1, 0x0AE1 => 1, 0x0AE2 => 0, 0x0AE3 => 0, 0x0AE4 => 1,
            0x0AF9 => 1, 0x0AFA => 0, 0x0AFF => 0, 0x0B00 => 1, 0x0B01 => 0,
            0x0B02 => 1, 0x0B3B => 1, 0x0B3C => 0, 0x0B3D => 1, 0x0B3E => 1,
            0x0B3F => 0, 0x0B40 => 1, 0x0B41 => 0, 0x0B44 => 0, 0x0B45 => 1,
            0x0B4C => 1, 0x0B4D => 0, 0x0B4E => 1, 0x0B54 => 1, 0x0B55 => 0,
            0x0B56 => 0, 0x0B57 => 1, 0x0B61 => 1, 0x0B62 => 0, 0x0B63 => 0,
            0x0B64 => 1, 0x0B81 => 1, 0x0B82 => 0, 0x0B83 => 1, 0x0BBF => 1,
            0x0BC0 => 0, 0x0BC1 => 1, 0x0BCC => 1, 0x0BCD => 0, 0x0BCE => 1,
            0x0BFF => 1, 0x0C00 => 0, 0x0C01 => 1, 0x0C03 => 1, 0x0C04 => 0,
            0x0C05 => 1, 0x0C3B => 1, 0x0C3C => 0, 0x0C3D => 1, 0x0C3E => 0,
            0x0C40 => 0, 0x0C41 => 1, 0x0C45 => 1, 0x0C46 => 0, 0x0C48 => 0,
            0x0C49 => 1, 0x0C4A => 0, 0x0C4D => 0, 0x0C4E => 1, 0x0C54 => 1,
            0x0C55 => 0, 0x0C56 => 0, 0x0C57 => 1, 0x0C61 => 1, 0x0C62 => 0,
            0x0C63 => 0, 0x0C64 => 1, 0x0C80 => 1, 0x0C81 => 0, 0x0C82 => 1,
            0x0CBB => 1, 0x0CBC => 0, 0x0CBD => 1, 0x0CBE => 1, 0x0CBF => 0,
            0x0CC0 => 1, 0x0CC5 => 1, 0x0CC6 => 0, 0x0CC7 => 1, 0x0CCB => 1,
            0x0CCC => 0, 0x0CCD => 0, 0x0CCE => 1, 0x0CE1 => 1, 0x0CE2 => 0,
            0x0CE3 => 0, 0x0CE4 => 1, 0x0CFF => 1, 0x0D00 => 0, 0x0D01 => 0,
            0x0D02 => 1, 0x0D3A => 1, 0x0D3B => 0, 0x0D3C => 0, 0x0D3D => 1,
            0x0D40 => 1, 0x0D41 => 0, 0x0D44 => 0, 0x0D45 => 1, 0x0D4C => 1,
            0x0D4D => 0, 0x0D4E => 1, 0x0D61 => 1, 0x0D62 => 0, 0x0D63 => 0,
            0x0D64 => 1, 0x0D80 => 1, 0x0D81 => 0, 0x0D82 => 1, 0x0DC9 => 1,
            0x0DCA => 0, 0x0DCB => 1, 0x0DD1 => 1, 0x0DD2 => 0, 0x0DD4 => 0,
            0x0DD5 => 1, 0x0DD6 => 0, 0x0DD7 => 1, 0x0E30 => 1, 0x0E31 => 0,
            0x0E32 => 1, 0x0E33 => 1, 0x0E34 => 0, 0x0E3A => 0, 0x0E3B => 1,
            0x0E46 => 1, 0x0E47 => 0, 0x0E4E => 0, 0x0E4F => 1, 0x0EB0 => 1,
            0x0EB1 => 0, 0x0EB2 => 1, 0x0EB3 => 1, 0x0EB4 => 0, 0x0EBC => 0,
            0x0EBD => 1, 0x0EC7 => 1, 0x0EC8 => 0, 0x0ECE => 0, 0x0ECF => 1,
            0x0F00 => 1, 0x0F17 => 1, 0x0F18 => 0, 0x0F19 => 0, 0x0F1A => 1,
            0x0F34 => 1, 0x0F35 => 0, 0x0F36 => 1, 0x0F37 => 0, 0x0F38 => 1,
            0x0F39 => 0, 0x0F3A => 1, 0x0F70 => 1, 0x0F71 => 0, 0x0F7E => 0,
            0x0F7F => 1, 0x0F80 => 0, 0x0F84 => 0, 0x0F85 => 1, 0x0F86 => 0,
            0x0F87 => 0, 0x0F88 => 1, 0x0F8C => 1, 0x0F8D => 0, 0x0F97 => 0,
            0x0F98 => 1, 0x0F99 => 0, 0x0FBC => 0, 0x0FBD => 1, 0x0FC5 => 1,
            0x0FC6 => 0, 0x0FC7 => 1, 0x102C => 1, 0x102D => 0, 0x1030 => 0,
            0x1031 => 1, 0x1032 => 0, 0x1037 => 0, 0x1038 => 1, 0x1039 => 0,
            0x103A => 0, 0x103B => 1, 0x103C => 1, 0x103D => 0, 0x103E => 0,
            0x103F => 1, 0x1057 => 1, 0x1058 => 0, 0x1059 => 0, 0x105A => 1,
            0x105D => 1, 0x105E => 0, 0x1060 => 0, 0x1061 => 1, 0x1070 => 1,
            0x1071 => 0, 0x1074 => 0, 0x1075 => 1, 0x1081 => 1, 0x1082 => 0,
            0x1083 => 1, 0x1084 => 1, 0x1085 => 0, 0x1086 => 0, 0x1087 => 1,
            0x108C => 1, 0x108D => 0, 0x108E => 1, 0x109C => 1, 0x109D => 0,
            0x109E => 1, 0x135C => 1, 0x135D => 0, 0x135F => 0, 0x1360 => 1,
            0x1711 => 1, 0x1712 => 0, 0x1714 => 0, 0x1715 => 1, 0x1731 => 1,
            0x1732 => 0, 0x1733 => 0, 0x1734 => 1, 0x1751 => 1, 0x1752 => 0,
            0x1753 => 0, 0x1754 => 1, 0x1771 => 1, 0x1772 => 0, 0x1773 => 0,
            0x1774 => 1, 0x17B3 => 1, 0x17B4 => 0, 0x17B5 => 0, 0x17B6 => 1,
            0x17B7 => 0, 0x17BD => 0, 0x17BE => 1, 0x17C5 => 1, 0x17C6 => 0,
            0x17C7 => 1, 0x17C8 => 1, 0x17C9 => 0, 0x17D3 => 0, 0x17D4 => 1,
            0x17DC => 1, 0x17DD => 0, 0x17DE => 1, 0x180A => 1, 0x180B => 0,
            0x180D => 0, 0x180E => 1, 0x180F => 0, 0x1810 => 1, 0x1884 => 1,
            0x1885 => 0, 0x1886 => 0, 0x1887 => 1, 0x18A8 => 1, 0x18A9 => 0,
            0x18AA => 1, 0x191F => 1, 0x1920 => 0, 0x1922 => 0, 0x1923 => 1,
            0x1926 => 1, 0x1927 => 0, 0x1928 => 0, 0x1929 => 1, 0x1931 => 1,
            0x1932 => 0, 0x1933 => 1, 0x1938 => 1, 0x1939 => 0, 0x193B => 0,
            0x193C => 1, 0x1A16 => 1, 0x1A17 => 0, 0x1A18 => 0, 0x1A19 => 1,
            0x1A1A => 1, 0x1A1B => 0, 0x1A1C => 1, 0x1A55 => 1, 0x1A56 => 0,
            0x1A57 => 1, 0x1A58 => 0, 0x1A5E => 0, 0x1A5F => 1, 0x1A60 => 0,
            0x1A61 => 1, 0x1A62 => 0, 0x1A63 => 1, 0x1A64 => 1, 0x1A65 => 0,
            0x1A6C => 0, 0x1A6D => 1, 0x1A72 => 1, 0x1A73 => 0, 0x1A7C => 0,
            0x1A7D => 1, 0x1A7E => 1, 0x1A7F => 0, 0x1A80 => 1, 0x1AFF => 0,
            0x1B00 => 0, 0x1B03 => 0, 0x1B04 => 1, 0x1B33 => 1, 0x1B34 => 0,
            0x1B35 => 1, 0x1B36 => 0, 0x1B3A => 0, 0x1B3B => 1, 0x1B3C => 0,
            0x1B3D => 1, 0x1B41 => 1, 0x1B42 => 0, 0x1B43 => 1, 0x1B6A => 1,
            0x1B6B => 0, 0x1B73 => 0, 0x1B74 => 1, 0x1B7F => 1, 0x1B80 => 0,
            0x1B81 => 0, 0x1B82 => 1, 0x1BA1 => 1, 0x1BA2 => 0, 0x1BA5 => 0,
            0x1BA6 => 1, 0x1BA7 => 1, 0x1BA8 => 0, 0x1BA9 => 0, 0x1BAA => 1,
            0x1BAB => 0, 0x1BAD => 0, 0x1BAE => 1, 0x1BE5 => 1, 0x1BE6 => 0,
            0x1BE7 => 1, 0x1BE8 => 0, 0x1BE9 => 0, 0x1BEA => 1, 0x1BEC => 1,
            0x1BED => 0, 0x1BEE => 1, 0x1BEF => 0, 0x1BF1 => 0, 0x1BF2 => 1,
            0x1C2B => 1, 0x1C2C => 0, 0x1C33 => 0, 0x1C34 => 1, 0x1C35 => 1,
            0x1C36 => 0, 0x1C37 => 0, 0x1C38 => 1, 0x1CCF => 1, 0x1CD0 => 0,
            0x1CD2 => 0, 0x1CD3 => 1, 0x1CD4 => 0, 0x1CE0 => 0, 0x1CE1 => 1,
            0x1CE2 => 0, 0x1CE8 => 0, 0x1CE9 => 1, 0x1CEC => 1, 0x1CED => 0,
            0x1CEE => 1, 0x1CF3 => 1, 0x1CF4 => 0, 0x1CF5 => 1, 0x1CF7 => 1,
            0x1CF8 => 0, 0x1CF9 => 0, 0x1CFA => 1, 0x20F0 => 0, 0x20FF => 0,
            0x2100 => 1, 0x2328 => 1, 0x2329 => 2, 0x232A => 2, 0x232B => 1,
            0x262F => 1, 0x2630 => 2, 0x2631 => 2, 0x2633 => 2, 0x2635 => 2,
            0x2637 => 2, 0x2638 => 1, 0x2689 => 1, 0x268A => 2, 0x268B => 2,
            0x268C => 2, 0x268D => 2, 0x268F => 2, 0x2690 => 1, 0x2CEE => 1,
            0x2CEF => 0, 0x2CF1 => 0, 0x2CF2 => 1, 0x2D7E => 1, 0x2D7F => 0,
            0x2D80 => 1, 0x2DDF => 1, 0x2DE0 => 0, 0x2DFF => 0, 0x2E00 => 1,
            0x2E7F => 1, 0x2E80 => 2, 0x2EEF => 2, 0x2F5F => 2, 0x2FCE => 2,
            0x3029 => 2, 0x302A => 0, 0x302D => 0, 0x302E => 2, 0x303E => 2,
            0x303F => 1, 0x3040 => 1, 0x3041 => 2, 0x3098 => 2, 0x3099 => 0,
            0x309A => 0, 0x309B => 2, 0x309C => 2, 0x3130 => 2, 0x3220 => 2,
            0x330F => 2, 0x33FF => 2, 0x3400 => 2, 0x3A6F => 2, 0x40DF => 2,
            0x474F => 2, 0x4DBF => 2, 0x4DC0 => 2, 0x4DCF => 2, 0x4DDF => 2,
            0x4DEF => 2, 0x4DFF => 2, 0x4E00 => 2, 0x627F => 2, 0x76FF => 2,
            0x8B7F => 2, 0x9FFF => 2, 0xA000 => 2, 0xA133 => 2, 0xA267 => 2,
            0xA39B => 2, 0xA4CF => 2, 0xA4D0 => 1, 0xA66E => 1, 0xA66F => 0,
            0xA672 => 0, 0xA673 => 1, 0xA674 => 0, 0xA67D => 0, 0xA67E => 1,
            0xA69D => 1, 0xA69E => 0, 0xA69F => 0, 0xA6A0 => 1, 0xA6EF => 1,
            0xA6F0 => 0, 0xA6F1 => 0, 0xA6F2 => 1, 0xA801 => 1, 0xA802 => 0,
            0xA803 => 1, 0xA805 => 1, 0xA806 => 0, 0xA807 => 1, 0xA80A => 1,
            0xA80B => 0, 0xA80C => 1, 0xA824 => 1, 0xA825 => 0, 0xA826 => 0,
            0xA827 => 1, 0xA82B => 1, 0xA82C => 0, 0xA82D => 1, 0xA8C3 => 1,
            0xA8C4 => 0, 0xA8C5 => 0, 0xA8C6 => 1, 0xA8DF => 1, 0xA8E0 => 0,
            0xA8F1 => 0, 0xA8F2 => 1, 0xA8FE => 1, 0xA8FF => 0, 0xA900 => 1,
            0xA925 => 1, 0xA926 => 0, 0xA92D => 0, 0xA92E => 1, 0xA946 => 1,
            0xA947 => 0, 0xA951 => 0, 0xA952 => 1, 0xA95F => 1, 0xA960 => 2,
            0xA967 => 2, 0xA96E => 2, 0xA975 => 2, 0xA97C => 2, 0xA97D => 1,
            0xA97F => 1, 0xA980 => 0, 0xA982 => 0, 0xA983 => 1, 0xA9B2 => 1,
            0xA9B3 => 0, 0xA9B4 => 1, 0xA9B5 => 1, 0xA9B6 => 0, 0xA9B9 => 0,
            0xA9BA => 1, 0xA9BB => 1, 0xA9BC => 0, 0xA9BD => 0, 0xA9BE => 1,
            0xA9E4 => 1, 0xA9E5 => 0, 0xA9E6 => 1, 0xAA28 => 1, 0xAA29 => 0,
            0xAA2E => 0, 0xAA2F => 1, 0xAA30 => 1, 0xAA31 => 0, 0xAA32 => 0,
            0xAA33 => 1, 0xAA34 => 1, 0xAA35 => 0, 0xAA36 => 0, 0xAA37 => 1,
            0xAA42 => 1, 0xAA43 => 0, 0xAA44 => 1, 0xAA4B => 1, 0xAA4C => 0,
            0xAA4D => 1, 0xAA7B => 1, 0xAA7C => 0, 0xAA7D => 1, 0xAAAF => 1,
            0xAAB0 => 0, 0xAAB1 => 1, 0xAAB2 => 0, 0xAAB4 => 0, 0xAAB5 => 1,
            0xAAB6 => 1, 0xAAB7 => 0, 0xAAB8 => 0, 0xAAB9 => 1, 0xAABD => 1,
            0xAABE => 0, 0xAABF => 0, 0xAAC0 => 1, 0xAAC1 => 0, 0xAAC2 => 1,
            0xAAEB => 1, 0xAAEC => 0, 0xAAED => 0, 0xAAEE => 1, 0xAAF5 => 1,
            0xAAF6 => 0, 0xAAF7 => 1, 0xABE4 => 1, 0xABE5 => 0, 0xABE6 => 1,
            0xABE7 => 1, 0xABE8 => 0, 0xABE9 => 1, 0xABEC => 1, 0xABED => 0,
            0xABEE => 1, 0xABFF => 1, 0xAC00 => 2, 0xB6E8 => 2, 0xC1D1 => 2,
            0xCCBA => 2, 0xD7A3 => 2, 0xD7A4 => 1, 0xF8FF => 1, 0xF900 => 2,
            0xF97F => 2, 0xF9FF => 2, 0xFA7F => 2, 0xFAFF => 2, 0xFB00 => 1,
            0xFB1D => 1, 0xFB1E => 0, 0xFB1F => 1, 0xFE0F => 0, 0xFE10 => 2,
            0xFE12 => 2, 0xFE14 => 2, 0xFE16 => 2, 0xFE19 => 2, 0xFE1A => 1,
            0xFE2F => 0, 0xFE30 => 2, 0xFE37 => 2, 0xFE3F => 2, 0xFE47 => 2,
            0xFE4F => 2, 0xFE50 => 2, 0xFE51 => 2, 0xFE52 => 2, 0xFE53 => 1,
            0xFE54 => 2, 0xFE58 => 2, 0xFE5D => 2, 0xFE61 => 2, 0xFE66 => 2,
            0xFE67 => 1, 0xFE68 => 2, 0xFE69 => 2, 0xFE6A => 2, 0xFE6B => 2,
            0xFE6C => 1, 0xFF00 => 1, 0xFF01 => 2, 0xFF18 => 2, 0xFF30 => 2,
            0xFF48 => 2, 0xFF60 => 2, 0xFF61 => 1, 0xFFDC => 1, 0xFFDD => 1,
            0xFFDF => 1, 0xFFE0 => 2, 0xFFE1 => 2, 0xFFE3 => 2, 0xFFE4 => 2,
            0xFFE6 => 2, 0xFFE7 => 1, 0x101FC => 1, 0x101FD => 0, 0x101FE => 1,
            0x102DF => 1, 0x102E0 => 0, 0x102E1 => 1, 0x10375 => 1, 0x10376 => 0,
            0x1037A => 0, 0x1037B => 1, 0x10A00 => 1, 0x10A01 => 0, 0x10A03 => 0,
            0x10A04 => 1, 0x10A05 => 0, 0x10A06 => 0, 0x10A07 => 1, 0x10A0B => 1,
            0x10A0C => 0, 0x10A0F => 0, 0x10A10 => 1, 0x10A37 => 1, 0x10A38 => 0,
            0x10A3A => 0, 0x10A3B => 1, 0x10A3E => 1, 0x10A3F => 0, 0x10A40 => 1,
            0x10AE4 => 1, 0x10AE5 => 0, 0x10AE6 => 0, 0x10AE7 => 1, 0x10D23 => 1,
            0x10D24 => 0, 0x10D27 => 0, 0x10D28 => 1, 0x10D68 => 1, 0x10D69 => 0,
            0x10D6D => 0, 0x10D6E => 1, 0x10EAA => 1, 0x10EAB => 0, 0x10EAC => 0,
            0x10EAD => 1, 0x10EFB => 1, 0x10EFC => 0, 0x10EFF => 0, 0x10F00 => 1,
            0x10F45 => 1, 0x10F46 => 0, 0x10F50 => 0, 0x10F51 => 1, 0x10F81 => 1,
            0x10F82 => 0, 0x10F85 => 0, 0x10F86 => 1, 0x11000 => 1, 0x11001 => 0,
            0x11002 => 1, 0x11037 => 1, 0x11038 => 0, 0x11046 => 0, 0x11047 => 1,
            0x1106F => 1, 0x11070 => 0, 0x11071 => 1, 0x11072 => 1, 0x11073 => 0,
            0x11074 => 0, 0x11075 => 1, 0x1107E => 1, 0x1107F => 0, 0x11081 => 0,
            0x11082 => 1, 0x110B2 => 1, 0x110B3 => 0, 0x110B6 => 0, 0x110B7 => 1,
            0x110B8 => 1, 0x110B9 => 0, 0x110BA => 0, 0x110BB => 1, 0x110C1 => 1,
            0x110C2 => 0, 0x110C3 => 1, 0x110FF => 1, 0x11100 => 0, 0x11102 => 0,
            0x11103 => 1, 0x11126 => 1, 0x11127 => 0, 0x1112B => 0, 0x1112C => 1,
            0x1112D => 0, 0x11134 => 0, 0x11135 => 1, 0x11172 => 1, 0x11173 => 0,
            0x11174 => 1, 0x1117F => 1, 0x11180 => 0, 0x11181 => 0, 0x11182 => 1,
            0x111B5 => 1, 0x111B6 => 0, 0x111BE => 0, 0x111BF => 1, 0x111C8 => 1,
            0x111C9 => 0, 0x111CC => 0, 0x111CD => 1, 0x111CE => 1, 0x111CF => 0,
            0x111D0 => 1, 0x1122E => 1, 0x1122F => 0, 0x11231 => 0, 0x11232 => 1,
            0x11233 => 1, 0x11234 => 0, 0x11235 => 1, 0x11236 => 0, 0x11237 => 0,
            0x11238 => 1, 0x1123D => 1, 0x1123E => 0, 0x1123F => 1, 0x11240 => 1,
            0x11241 => 0, 0x11242 => 1, 0x112DE => 1, 0x112DF => 0, 0x112E0 => 1,
            0x112E2 => 1, 0x112E3 => 0, 0x112EA => 0, 0x112EB => 1, 0x112FF => 1,
            0x11300 => 0, 0x11301 => 0, 0x11302 => 1, 0x1133A => 1, 0x1133B => 0,
            0x1133C => 0, 0x1133D => 1, 0x1133F => 1, 0x11340 => 0, 0x11341 => 1,
            0x11365 => 1, 0x11366 => 0, 0x1136C => 0, 0x1136D => 1, 0x1136F => 1,
            0x11370 => 0, 0x11374 => 0, 0x11375 => 1, 0x113BA => 1, 0x113BB => 0,
            0x113C0 => 0, 0x113C1 => 1, 0x113CD => 1, 0x113CE => 0, 0x113CF => 1,
            0x113D0 => 0, 0x113D1 => 1, 0x113D2 => 0, 0x113D3 => 1, 0x113E0 => 1,
            0x113E1 => 0, 0x113E2 => 0, 0x113E3 => 1, 0x11437 => 1, 0x11438 => 0,
            0x1143F => 0, 0x11440 => 1, 0x11441 => 1, 0x11442 => 0, 0x11444 => 0,
            0x11445 => 1, 0x11446 => 0, 0x11447 => 1, 0x1145D => 1, 0x1145E => 0,
            0x1145F => 1, 0x114B2 => 1, 0x114B3 => 0, 0x114B8 => 0, 0x114B9 => 1,
            0x114BA => 0, 0x114BB => 1, 0x114BE => 1, 0x114BF => 0, 0x114C0 => 0,
            0x114C1 => 1, 0x114C2 => 0, 0x114C3 => 0, 0x114C4 => 1, 0x115B1 => 1,
            0x115B2 => 0, 0x115B5 => 0, 0x115B6 => 1, 0x115BB => 1, 0x115BC => 0,
            0x115BD => 0, 0x115BE => 1, 0x115BF => 0, 0x115C0 => 0, 0x115C1 => 1,
            0x115DB => 1, 0x115DC => 0, 0x115DD => 0, 0x115DE => 1, 0x11632 => 1,
            0x11633 => 0, 0x1163A => 0, 0x1163B => 1, 0x1163C => 1, 0x1163D => 0,
            0x1163E => 1, 0x1163F => 0, 0x11640 => 0, 0x11641 => 1, 0x116AA => 1,
            0x116AB => 0, 0x116AC => 1, 0x116AD => 0, 0x116AE => 1, 0x116AF => 1,
            0x116B0 => 0, 0x116B5 => 0, 0x116B6 => 1, 0x116B7 => 0, 0x116B8 => 1,
            0x1171C => 1, 0x1171D => 0, 0x1171E => 1, 0x1171F => 0, 0x11720 => 1,
            0x11721 => 1, 0x11722 => 0, 0x11725 => 0, 0x11726 => 1, 0x11727 => 0,
            0x1172B => 0, 0x1172C => 1, 0x1182E => 1, 0x1182F => 0, 0x11837 => 0,
            0x11838 => 1, 0x11839 => 0, 0x1183A => 0, 0x1183B => 1, 0x1193A => 1,
            0x1193B => 0, 0x1193C => 0, 0x1193D => 1, 0x1193E => 0, 0x1193F => 1,
            0x11942 => 1, 0x11943 => 0, 0x11944 => 1, 0x119D3 => 1, 0x119D4 => 0,
            0x119D7 => 0, 0x119D8 => 1, 0x119D9 => 1, 0x119DA => 0, 0x119DB => 0,
            0x119DC => 1, 0x119DF => 1, 0x119E0 => 0, 0x119E1 => 1, 0x11A00 => 1,
            0x11A01 => 0, 0x11A0A => 0, 0x11A0B => 1, 0x11A32 => 1, 0x11A33 => 0,
            0x11A38 => 0, 0x11A39 => 1, 0x11A3A => 1, 0x11A3B => 0, 0x11A3E => 0,
            0x11A3F => 1, 0x11A46 => 1, 0x11A47 => 0, 0x11A48 => 1, 0x11A50 => 1,
            0x11A51 => 0, 0x11A56 => 0, 0x11A57 => 1, 0x11A58 => 1, 0x11A59 => 0,
            0x11A5B => 0, 0x11A5C => 1, 0x11A89 => 1, 0x11A8A => 0, 0x11A96 => 0,
            0x11A97 => 1, 0x11A98 => 0, 0x11A99 => 0, 0x11A9A => 1, 0x11C2F => 1,
            0x11C30 => 0, 0x11C36 => 0, 0x11C37 => 1, 0x11C38 => 0, 0x11C3D => 0,
            0x11C3E => 1, 0x11C3F => 0, 0x11C40 => 1, 0x11C91 => 1, 0x11C92 => 0,
            0x11CA7 => 0, 0x11CA8 => 1, 0x11CA9 => 1, 0x11CAA => 0, 0x11CB0 => 0,
            0x11CB1 => 1, 0x11CB2 => 0, 0x11CB3 => 0, 0x11CB4 => 1, 0x11CB5 => 0,
            0x11CB6 => 0, 0x11CB7 => 1, 0x11D30 => 1, 0x11D31 => 0, 0x11D36 => 0,
            0x11D37 => 1, 0x11D39 => 1, 0x11D3A => 0, 0x11D3B => 1, 0x11D3C => 0,
            0x11D3D => 0, 0x11D3E => 1, 0x11D3F => 0, 0x11D45 => 0, 0x11D46 => 1,
            0x11D47 => 0, 0x11D48 => 1, 0x11D8F => 1, 0x11D90 => 0, 0x11D91 => 0,
            0x11D92 => 1, 0x11D94 => 1, 0x11D95 => 0, 0x11D96 => 1, 0x11D97 => 0,
            0x11D98 => 1, 0x11EF2 => 1, 0x11EF3 => 0, 0x11EF4 => 0, 0x11EF5 => 1,
            0x11EFF => 1, 0x11F00 => 0, 0x11F01 => 0, 0x11F02 => 1, 0x11F35 => 1,
            0x11F36 => 0, 0x11F3A => 0, 0x11F3B => 1, 0x11F3F => 1, 0x11F40 => 0,
            0x11F41 => 1, 0x11F42 => 0, 0x11F43 => 1, 0x11F59 => 1, 0x11F5A => 0,
            0x11F5B => 1, 0x1343F => 1, 0x13440 => 0, 0x13441 => 1, 0x13446 => 1,
            0x13447 => 0, 0x13455 => 0, 0x13456 => 1, 0x1611D => 1, 0x1611E => 0,
            0x16129 => 0, 0x1612A => 1, 0x1612C => 1, 0x1612D => 0, 0x1612F => 0,
            0x16130 => 1, 0x16AEF => 1, 0x16AF0 => 0, 0x16AF4 => 0, 0x16AF5 => 1,
            0x16B2F => 1, 0x16B30 => 0, 0x16B36 => 0, 0x16B37 => 1, 0x16F4E => 1,
            0x16F4F => 0, 0x16F50 => 1, 0x16F8E => 1, 0x16F8F => 0, 0x16F92 => 0,
            0x16F93 => 1, 0x16FDF => 1, 0x16FE0 => 2, 0x16FE1 => 2, 0x16FE2 => 2,
            0x16FE3 => 2, 0x16FE4 => 0, 0x16FE5 => 1, 0x16FEF => 1, 0x16FF0 => 2,
            0x16FF1 => 2, 0x16FF2 => 1, 0x16FFF => 1, 0x17000 => 2, 0x175FD => 2,
            0x17BFB => 2, 0x181F9 => 2, 0x187F7 => 2, 0x187F8 => 1, 0x187FF => 1,
            0x18800 => 2, 0x18935 => 2, 0x18A6A => 2, 0x18B9F => 2, 0x18CD5 => 2,
            0x18CD6 => 1, 0x18CFE => 1, 0x18CFF => 2, 0x18D01 => 2, 0x18D03 => 2,
            0x18D05 => 2, 0x18D08 => 2, 0x18D09 => 1, 0x1AFEF => 1, 0x1AFF0 => 2,
            0x1AFF1 => 2, 0x1AFF2 => 2, 0x1AFF3 => 2, 0x1AFF4 => 1, 0x1AFF5 => 2,
            0x1AFF6 => 2, 0x1AFF8 => 2, 0x1AFF9 => 2, 0x1AFFB => 2, 0x1AFFC => 1,
            0x1AFFD => 2, 0x1AFFE => 2, 0x1AFFF => 1, 0x1B000 => 2, 0x1B048 => 2,
            0x1B091 => 2, 0x1B0D9 => 2, 0x1B122 => 2, 0x1B123 => 1, 0x1B14F => 1,
            0x1B150 => 2, 0x1B151 => 2, 0x1B152 => 2, 0x1B153 => 1, 0x1B163 => 1,
            0x1B164 => 2, 0x1B165 => 2, 0x1B166 => 2, 0x1B167 => 2, 0x1B168 => 1,
            0x1B16F => 1, 0x1B170 => 2, 0x1B1D2 => 2, 0x1B235 => 2, 0x1B298 => 2,
            0x1B2FB => 2, 0x1B2FC => 1, 0x1BC9C => 1, 0x1BC9D => 0, 0x1BC9E => 0,
            0x1BC9F => 1, 0x1CEFF => 1, 0x1CF00 => 0, 0x1CF2D => 0, 0x1CF2E => 1,
            0x1CF2F => 1, 0x1CF30 => 0, 0x1CF46 => 0, 0x1CF47 => 1, 0x1D165 => 1,
            0x1D166 => 1, 0x1D167 => 0, 0x1D169 => 0, 0x1D16A => 1, 0x1D17A => 1,
            0x1D17B => 0, 0x1D182 => 0, 0x1D183 => 1, 0x1D184 => 1, 0x1D185 => 0,
            0x1D18B => 0, 0x1D18C => 1, 0x1D1A9 => 1, 0x1D1AA => 0, 0x1D1AD => 0,
            0x1D1AE => 1, 0x1D241 => 1, 0x1D242 => 0, 0x1D244 => 0, 0x1D245 => 1,
            0x1D2FF => 1, 0x1D300 => 2, 0x1D315 => 2, 0x1D32B => 2, 0x1D340 => 2,
            0x1D356 => 2, 0x1D357 => 1, 0x1D35F => 1, 0x1D360 => 2, 0x1D365 => 2,
            0x1D36B => 2, 0x1D370 => 2, 0x1D376 => 2, 0x1D377 => 1, 0x1D9FF => 1,
            0x1DA00 => 0, 0x1DA36 => 0, 0x1DA37 => 1, 0x1DA3A => 1, 0x1DA3B => 0,
            0x1DA6C => 0, 0x1DA6D => 1, 0x1DA74 => 1, 0x1DA75 => 0, 0x1DA76 => 1,
            0x1DA83 => 1, 0x1DA84 => 0, 0x1DA85 => 1, 0x1DA9A => 1, 0x1DA9B => 0,
            0x1DA9F => 0, 0x1DAA0 => 1, 0x1DAA1 => 0, 0x1DAAF => 0, 0x1DAB0 => 1,
            0x1DFFF => 1, 0x1E000 => 0, 0x1E006 => 0, 0x1E007 => 1, 0x1E008 => 0,
            0x1E018 => 0, 0x1E019 => 1, 0x1E01A => 1, 0x1E01B => 0, 0x1E021 => 0,
            0x1E022 => 1, 0x1E023 => 0, 0x1E024 => 0, 0x1E025 => 1, 0x1E026 => 0,
            0x1E02A => 0, 0x1E02B => 1, 0x1E08E => 1, 0x1E08F => 0, 0x1E090 => 1,
            0x1E12F => 1, 0x1E130 => 0, 0x1E136 => 0, 0x1E137 => 1, 0x1E2AD => 1,
            0x1E2AE => 0, 0x1E2AF => 1, 0x1E2EB => 1, 0x1E2EC => 0, 0x1E2EF => 0,
            0x1E2F0 => 1, 0x1E4EB => 1, 0x1E4EC => 0, 0x1E4EF => 0, 0x1E4F0 => 1,
            0x1E5ED => 1, 0x1E5EE => 0, 0x1E5EF => 0, 0x1E5F0 => 1, 0x1E8CF => 1,
            0x1E8D0 => 0, 0x1E8D6 => 0, 0x1E8D7 => 1, 0x1E943 => 1, 0x1E944 => 0,
            0x1E94A => 0, 0x1E94B => 1, 0x1F2FF => 1, 0x1F300 => 2, 0x1F3D3 => 2,
            0x1F4A7 => 2, 0x1F57B => 2, 0x1F64F => 2, 0x1F650 => 1, 0x1F67F => 1,
            0x1F680 => 2, 0x1F69F => 2, 0x1F6BF => 2, 0x1F6DF => 2, 0x1F6FF => 2,
            0x1F700 => 1, 0x1F8FF => 1, 0x1F900 => 2, 0x1F93F => 2, 0x1F97F => 2,
            0x1F9BF => 2, 0x1F9FF => 2, 0x1FA00 => 1, 0x1FFFF => 1, 0x20000 => 2,
            0x23FFF => 2, 0x27FFE => 2, 0x2BFFD => 2, 0x2FFFD => 2, 0x2FFFE => 1,
            0x2FFFF => 1, 0x30000 => 2, 0x33FFF => 2, 0x37FFE => 2, 0x3BFFD => 2,
            0x3FFFD => 2, 0x3FFFE => 1, 0xE00FF => 1, 0xE0100 => 0, 0xE01EF => 0,
            0xE01F0 => 1,
            0x0009 => 4, 0x001F => 0, 0x0020 => 1, 0x007F => 0, 0x009F => 0,
            0x00A0 => 1, 0x0300 => 0, 0x036F => 0, 0x0370 => 1, 0x1DC0 => 0,
            0x2000 => 1, 0x202A => 1, 0x20D0 => 0, 0xFE00 => 0, 0xFE20 => 0,
        ];
        self::assertGreaterThan(1400, count($edges), 'the edge census covers every changed run');
        foreach ($edges as $cp => $expected) {
            self::assertSame($expected, Width::of(\mb_chr($cp)), 'U+' . \sprintf('%04X', $cp));
        }
    }
}
