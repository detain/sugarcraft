<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tests\Input;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Bits\Input\TextEdit;
use SugarCraft\Core\Util\Width;

final class TextEditTest extends TestCase
{
    private const UL = "\x1b[4m";
    private const UUL = "\x1b[24m";

    // ─── construction + coercion ─────────────────────────────────────

    public function testNewDefaultsToEmptyWithCaretZero(): void
    {
        $t = TextEdit::new();
        $this->assertSame('', $t->text());
        $this->assertSame(0, $t->caret());
        $this->assertSame(0, $t->length());
        $this->assertFalse($t->numeric());
    }

    public function testNullCaretPlacesCaretAtEndInClusters(): void
    {
        // 'é' as e + U+0301 is one cluster: 3 clusters, not 4 codepoints.
        $t = TextEdit::new("ae\u{0301}漢");
        $this->assertSame(3, $t->length());
        $this->assertSame(3, $t->caret());
    }

    public function testCaretClampsOnConstructionAndWithCaret(): void
    {
        $this->assertSame(3, TextEdit::new('abc', 99)->caret());
        $this->assertSame(0, TextEdit::new('abc', -4)->caret());
        $t = TextEdit::new('abc', 1);
        $this->assertSame(3, $t->withCaret(50)->caret());
        $this->assertSame(0, $t->withCaret(-1)->caret());
        $this->assertSame(2, $t->withCaret(2)->caret());
    }

    public function testWithersReturnNewInstances(): void
    {
        $t = TextEdit::new('abc', 1);
        $this->assertNotSame($t, $t->withCaret(2));
        $this->assertNotSame($t, $t->insert('x'));
        $this->assertNotSame($t, $t->withNumeric());
        $this->assertSame('abc', $t->text);
        $this->assertSame(1, $t->caret);
    }

    // ─── navigation ──────────────────────────────────────────────────

    public function testLeftRightHomeEndClamp(): void
    {
        $t = TextEdit::new('a漢b', 1);
        $this->assertSame(0, $t->left()->caret());
        $this->assertSame(0, $t->left()->left()->caret());
        $this->assertSame(2, $t->right()->caret());
        $this->assertSame(3, $t->right()->right()->right()->caret());
        $this->assertSame(0, $t->home()->caret());
        $this->assertSame(3, $t->end()->caret());
    }

    // ─── editing ─────────────────────────────────────────────────────

    public function testInsertAtCaretAdvancesCaret(): void
    {
        $t = TextEdit::new('ad', 1)->insert('bc');
        $this->assertSame('abcd', $t->text());
        $this->assertSame(3, $t->caret());
    }

    public function testInsertWideCharacterCountsOneCluster(): void
    {
        $t = TextEdit::new('ab', 1)->insert('漢');
        $this->assertSame('a漢b', $t->text());
        $this->assertSame(2, $t->caret());
    }

    public function testCombiningMarkMergesIntoPrecedingCluster(): void
    {
        $t = TextEdit::new('e')->insert("\u{0301}");
        $this->assertSame(1, $t->length());
        $this->assertSame(1, $t->caret());
    }

    public function testInsertEmptyIsNoOp(): void
    {
        $t = TextEdit::new('abc');
        $this->assertSame($t, $t->insert(''));
    }

    public function testNumericRejectsNonDigitsAsNoOp(): void
    {
        $t = TextEdit::new('12')->withNumeric();
        $this->assertTrue($t->numeric());
        $this->assertSame($t, $t->insert('a'));
        $this->assertSame($t, $t->insert(' '));
        $this->assertSame($t, $t->insert('-'));
        $this->assertSame($t, $t->insert('3x'));
        $this->assertSame($t, $t->insert('٣')); // Arabic-Indic digit: not ASCII
        $this->assertSame('123', $t->insert('3')->text());
        $this->assertSame('', TextEdit::new()->withNumeric()->insert('x')->text());
    }

    public function testNumericCanBeTurnedOff(): void
    {
        $t = TextEdit::new('1')->withNumeric()->withNumeric(false);
        $this->assertSame('1a', $t->insert('a')->text());
    }

    public function testBackspaceRemovesClusterBeforeCaret(): void
    {
        $t = TextEdit::new('a漢b', 2)->backspace();
        $this->assertSame('ab', $t->text());
        $this->assertSame(1, $t->caret());
    }

    public function testBackspaceRemovesWholeZwjEmoji(): void
    {
        $family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
        $t = TextEdit::new('x' . $family)->backspace();
        $this->assertSame('x', $t->text());
        $this->assertSame(1, $t->caret());
    }

    public function testBackspaceAtStartIsNoOp(): void
    {
        $t = TextEdit::new('abc', 0);
        $this->assertSame($t, $t->backspace());
    }

    public function testDeleteRemovesClusterUnderCaret(): void
    {
        $t = TextEdit::new('a漢b', 1)->delete();
        $this->assertSame('ab', $t->text());
        $this->assertSame(1, $t->caret());
    }

    public function testDeleteAtEndIsNoOp(): void
    {
        $t = TextEdit::new('abc');
        $this->assertSame($t, $t->delete());
    }

    public function testClearResetsTextAndCaret(): void
    {
        $t = TextEdit::new('abc')->withNumeric()->clear();
        $this->assertSame('', $t->text());
        $this->assertSame(0, $t->caret());
        $this->assertTrue($t->numeric());
    }

    // ─── view: snapshot bytes ────────────────────────────────────────

    public function testEmptyRendersUnderlinedSpace(): void
    {
        $this->assertSame(self::UL . ' ' . self::UUL, TextEdit::new()->view());
        $this->assertSame(self::UL . ' ' . self::UUL, TextEdit::new()->view(5));
    }

    public function testCaretAtStartMiddleAndEndUnlimited(): void
    {
        $this->assertSame(self::UL . 'a' . self::UUL . 'bc', TextEdit::new('abc', 0)->view());
        $this->assertSame('a' . self::UL . 'b' . self::UUL . 'c', TextEdit::new('abc', 1)->view());
        $this->assertSame('abc' . self::UL . ' ' . self::UUL, TextEdit::new('abc')->view());
    }

    public function testCaretUnderWideCjkWrapsExactlyThatCluster(): void
    {
        $this->assertSame(
            'a' . self::UL . '漢' . self::UUL . 'b',
            TextEdit::new('a漢b', 1)->view(),
        );
    }

    public function testCaretUnderCombiningClusterWrapsWholeCluster(): void
    {
        $this->assertSame(
            'a' . self::UL . "e\u{0301}" . self::UUL . 'b',
            TextEdit::new("ae\u{0301}b", 1)->view(),
        );
    }

    public function testNonPositiveLimitMeansUnlimited(): void
    {
        $long = str_repeat('x', 50);
        $this->assertSame($long . self::UL . ' ' . self::UUL, TextEdit::new($long)->view(0));
        $this->assertSame($long . self::UL . ' ' . self::UUL, TextEdit::new($long)->view(-3));
    }

    public function testTextThatFitsIsNotWindowed(): void
    {
        // 8 cells + 1 trailing caret cell = 9 = limit.
        $this->assertSame('abcdefgh' . self::UL . ' ' . self::UUL, TextEdit::new('abcdefgh')->view(9));
    }

    /**
     * Len 40, limit 9 ⇒ half = round(4.5) = 5. Expected windows are btop's
     * operator()(limit) output for caret 0/5/20/35/39 (hand-traced); the
     * end-caret case differs by design (caret cell charged to the budget).
     *
     * @return array<string, array{int, string, string, string}>
     */
    public static function windowProvider(): array
    {
        return [
            'caret 0 (head)'         => [0, '', 'a', 'bcdefghi'],
            'caret 5 (== half)'      => [5, 'abcde', 'f', 'ghi'],
            'caret 20 (middle)'      => [20, 'fghij', 'a', 'bcd'],
            'caret 35 (tail == half)' => [35, 'abcde', 'f', 'ghi'],
            'caret 39 (last char)'   => [39, 'bcdefghi', 'j', ''],
            'caret 40 (end)'         => [40, 'cdefghij', ' ', ''],
        ];
    }

    #[DataProvider('windowProvider')]
    public function testWindowHalfBudgetLaw(int $caret, string $before, string $under, string $after): void
    {
        $t = TextEdit::new(str_repeat('abcdefghij', 4), $caret);
        $out = $t->view(9);
        $this->assertSame($before . self::UL . $under . self::UUL . $after, $out);
        $this->assertSame(9, Width::string($out));
    }

    public function testWideWindowStaysWithinCellBudget(): void
    {
        $t = TextEdit::new(str_repeat('漢字', 5), 5); // 10 clusters, 20 cells
        $out = $t->view(9);
        // half = 5 cells → 2 wide clusters before the caret; 5 cells after → 2.
        $this->assertSame('字漢' . self::UL . '字' . self::UUL . '漢', $out);
        $this->assertLessThanOrEqual(9, Width::string($out));
    }

    public function testWideEndCaretWindow(): void
    {
        $out = TextEdit::new(str_repeat('漢', 10))->view(9);
        // 8 cells for the head (4 wide clusters) + 1 caret cell.
        $this->assertSame(str_repeat('漢', 4) . self::UL . ' ' . self::UUL, $out);
        $this->assertSame(9, Width::string($out));
    }

    public function testCaretClusterSurvivesDegenerateLimit(): void
    {
        $out = TextEdit::new('ab漢cd', 2)->view(1);
        $this->assertStringContainsString(self::UL . '漢' . self::UUL, $out);
    }

    /**
     * @return array<string, array{string, int, int, string}>
     */
    public static function narrowWideProvider(): array
    {
        return [
            'limit 3, head room 1'  => ['ab中cdefg', 2, 3, 'b'],
            'limit 2, wide at 2'    => ['ab中cd', 2, 2, ''],
            'limit 3, deep caret'   => ['abcd中efgh', 4, 3, 'd'],
            'limit 2, deep caret'   => ['abcd中efgh', 4, 2, ''],
        ];
    }

    #[DataProvider('narrowWideProvider')]
    public function testNarrowLimitWithWideCaretNeverOverruns(string $text, int $caret, int $limit, string $before): void
    {
        $out = TextEdit::new($text, $caret)->view($limit);
        $this->assertSame($before . self::UL . '中' . self::UUL, $out);
        $this->assertLessThanOrEqual($limit, Width::string($out));
    }

    public function testNewNumericFlagStripsNonDigitsAndKeepsCaretOnDigit(): void
    {
        $t = TextEdit::new('1a2b3', 3, true); // caret on 'b' → after "1a2" → 2 digits
        $this->assertTrue($t->numeric());
        $this->assertSame('123', $t->text());
        $this->assertSame(2, $t->caret());
    }

    public function testWithNumericStripsExistingText(): void
    {
        $t = TextEdit::new('x9漢8')->withNumeric();
        $this->assertSame('98', $t->text());
        $this->assertSame(2, $t->caret());
        $this->assertSame('98', $t->withNumeric(false)->text());
    }
}
