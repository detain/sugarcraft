<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Tests\Border;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Border\TitleAnchor;
use SugarCraft\Sprinkles\Style;

/**
 * btop createBox / update_clock embed law (`─┐title┌─`) and the seam
 * junction matrix.
 */
final class EmbeddedTitleTest extends TestCase
{
    /** @return list<string> */
    private static function lines(Border $b, int $width, string $body = 'x'): array
    {
        return explode("\n", Style::new()->border($b)->width($width)->render($body));
    }

    // ── snapshot byte ─────────────────────────────────────────────────────

    public function testTopLeftEmbedRoundedInsetsOneRuneAndSquaresJunctions(): void
    {
        $l = self::lines(Border::rounded()->withEmbeddedTitle('cpu'), 12);
        $this->assertSame('╭─┐cpu┌──────╮', $l[0]);
        $this->assertSame('╰────────────╯', $l[2]);
    }

    public function testNormalFamilyUsesSameSquareJunctions(): void
    {
        $l = self::lines(Border::normal()->withEmbeddedTitle('cpu'), 12);
        $this->assertSame('┌─┐cpu┌──────┐', $l[0]);
    }

    public function testThickAndDoubleFamiliesKeepTheirJunctionFamily(): void
    {
        $this->assertSame('┏━┓ab┏━━┓', self::lines(Border::thick()->withEmbeddedTitle('ab'), 7)[0]);
        $this->assertSame('╔═╗ab╔══╗', self::lines(Border::double()->withEmbeddedTitle('ab'), 7)[0]);
    }

    public function testBottomEmbedUsesDownJunctions(): void
    {
        $l = self::lines(Border::rounded()->withEmbeddedTitle('mem', TitleAnchor::BottomLeft), 12);
        $this->assertSame('╭────────────╮', $l[0]);
        $this->assertSame('╰─┘mem└──────╯', $l[2]);
    }

    public function testRightEmbedMirrorsTheInset(): void
    {
        $l = self::lines(Border::rounded()->withEmbeddedTitle('bat', TitleAnchor::TopRight), 12);
        $this->assertSame('╭──────┐bat┌─╮', $l[0]);
    }

    public function testLeftTitleAndRightClockShareOneEdge(): void
    {
        $b = Border::rounded()
            ->withEmbeddedTitle('cpu')
            ->withEmbeddedTitle('12:00', TitleAnchor::TopRight);
        $this->assertSame('╭─┐cpu┌──────┐12:00┌─╮', self::lines($b, 20)[0]);
    }

    public function testAdjacentEmbedsOnOneAnchorAreGluedByAnEdgeRune(): void
    {
        $b = Border::rounded()
            ->withEmbeddedTitle('mem', TitleAnchor::BottomLeft)
            ->withEmbeddedTitle('disk', TitleAnchor::BottomLeft);
        $this->assertSame('╰─┘mem└─┘disk└───────╯', self::lines($b, 20)[2]);
    }

    /**
     * btop update_clock: open junction at floor(run/2) - floor(len/2).
     *
     * @return iterable<string, array{int, string, string}>
     */
    public static function centerCases(): iterable
    {
        // run 8, len 2 → lead 3, trail 1 (btop's one-right lean on even/even)
        yield 'even run, even text' => [8, 'ab', '╭───┐ab┌─╮'];
        // run 9, len 2 → lead 3, trail 2
        yield 'odd run, even text' => [9, 'ab', '╭───┐ab┌──╮'];
        // run 9, len 3 → lead 3, trail 1
        yield 'odd run, odd text' => [9, 'abc', '╭───┐abc┌─╮'];
        // run 8, len 3 → lead 3, trail 0
        yield 'even run, odd text' => [8, 'abc', '╭───┐abc┌╮'];
    }

    #[DataProvider('centerCases')]
    public function testCenterEmbedFollowsBtopClockPlacement(int $width, string $text, string $expected): void
    {
        $b = Border::rounded()->withEmbeddedTitle($text, TitleAnchor::TopCenter);
        $this->assertSame($expected, self::lines($b, $width)[0]);
    }

    public function testExplicitJunctionsOverrideTheDerivedPair(): void
    {
        $b = Border::rounded()->withEmbeddedTitle('cpu', TitleAnchor::TopLeft, '┤', '├');
        $this->assertSame('╭─┤cpu├──────╮', self::lines($b, 12)[0]);
    }

    public function testJunctionsTakeEdgeColourAndTextTakesTitleColour(): void
    {
        $red = Color::hex('#ff0000');
        $line = explode("\n", Style::new()
            ->colorProfile(ColorProfile::TrueColor)
            ->border(Border::normal()->withEmbeddedTitle('ab'))
            ->borderForeground($red)
            ->width(6)
            ->render('x'))[0];
        $sgr = $red->toFg(ColorProfile::TrueColor);
        $reset = "\x1b[0m";
        $this->assertSame($sgr . '┌─┐' . $sgr . 'ab' . $reset . $sgr . '┌─┐' . $reset, $line);
    }

    public function testPlainAndEmbeddedTitlesMixOnOneAnchor(): void
    {
        $b = Border::normal()->withTitle('a')->withEmbeddedTitle('b');
        // plain 'a' carries its default ' ' separator; the embed has no inset
        // because it is not the first title on the anchor.
        $this->assertSame('┌a ┐b┌───┐', self::lines($b, 8)[0]);
    }

    // ── coercion ──────────────────────────────────────────────────────────

    public function testEmptyTitleIsByteIdenticalToUntitledBorder(): void
    {
        $plain = Style::new()->border(Border::rounded())->width(10)->render('x');
        $embed = Style::new()->border(Border::rounded()->withEmbeddedTitle(''))->width(10)->render('x');
        $this->assertSame($plain, $embed);
        $this->assertSame([], Border::rounded()->withEmbeddedTitle('')->titles());
    }

    public function testOverflowShrinksTextWithEllipsisAndKeepsBothJunctions(): void
    {
        $l = self::lines(Border::normal()->withEmbeddedTitle('a very long title'), 10);
        $this->assertSame('┌─┐a very…┌┐', $l[0]);
        $this->assertSame(12, Width::string($l[0]));
    }

    public function testOverflowAtExactlyOneTextCellIsBareEllipsis(): void
    {
        // run 4 = inset + ┐ + 1 cell + ┌
        $this->assertSame('┌─┐…┌┐', self::lines(Border::normal()->withEmbeddedTitle('abc'), 4)[0]);
    }

    public function testEmbedWithNoRoomIsDroppedLeavingAPlainEdge(): void
    {
        $this->assertSame('┌───┐', self::lines(Border::normal()->withEmbeddedTitle('abc'), 3)[0]);
    }

    public function testRightClockDroppedWhenLeftTitleFillsTheEdge(): void
    {
        $b = Border::normal()
            ->withEmbeddedTitle('cpu')
            ->withEmbeddedTitle('12:00', TitleAnchor::TopRight);
        $this->assertSame('┌─┐cpu┌─┐', self::lines($b, 7)[0]);
    }

    public function testSecondEmbedOnAnchorDroppedWhenNoRoom(): void
    {
        $b = Border::normal()->withEmbeddedTitle('ab')->withEmbeddedTitle('cd');
        // inset + ┐ab┌ = 5; glue + ┐…┌ needs 4 more → only fits at run ≥ 9
        $this->assertSame('┌─┐ab┌───┐', self::lines($b, 8)[0]);
        $this->assertSame('┌─┐ab┌─┐…┌┐', self::lines($b, 9)[0]);
    }

    public function testWideCharTitleTruncatesOnCellBoundaryAndKeepsEdgeWidth(): void
    {
        $l = self::lines(Border::double()->withEmbeddedTitle('界界界'), 7);
        // text budget 4 → '界' (2) + '…' (1); the spare cell becomes edge fill
        $this->assertSame('╔═╗界…╔═╗', $l[0]);
        $this->assertSame(Width::string($l[1]), Width::string($l[0]));
    }

    public function testEmbeddedTitleIsImmutable(): void
    {
        $base = Border::rounded();
        $titled = $base->withEmbeddedTitle('cpu');
        $this->assertNotSame($base, $titled);
        $this->assertSame([], $base->titles());
        $t = $titled->titles()['TopLeft'][0];
        $this->assertTrue($t->embedded);
        $this->assertNull($t->open);
        $this->assertNull($t->close);
    }

    public function testPlainWithTitleIsNotEmbedded(): void
    {
        $this->assertFalse(Border::rounded()->withTitle('x')->titles()['TopLeft'][0]->embedded);
    }

    public function testEmbedJunctionsPerFamily(): void
    {
        $this->assertSame(['┐', '┌'], Border::rounded()->embedJunctions());
        $this->assertSame(['┘', '└'], Border::rounded()->embedJunctions(true));
        $this->assertSame(['┓', '┏'], Border::thick()->embedJunctions());
        $this->assertSame(['╝', '╚'], Border::double()->embedJunctions(true));
        $this->assertSame(['+', '+'], Border::ascii()->embedJunctions());
    }

    // ── review round: junction width, full-run centring, styled overflow ──

    /** @return iterable<string, array{?string, ?string}> */
    public static function badJunctions(): iterable
    {
        yield 'two-cell open'   => ['<<', null];
        yield 'empty close'     => [null, ''];
        yield 'wide-char open'  => ['界', null];
        yield 'two-cell close'  => [null, '>>'];
    }

    #[DataProvider('badJunctions')]
    public function testNonSingleCellJunctionIsRejected(?string $open, ?string $close): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Border::normal()->withEmbeddedTitle('ab', TitleAnchor::TopCenter, $open, $close);
    }

    public function testCenterEmbedCentresOnFullRunBesideALeftTitle(): void
    {
        // btop: clock open junction at box col floor(22/2) - floor(5/2) = 9,
        // regardless of the cpu title sharing the edge.
        $b = Border::normal()
            ->withEmbeddedTitle('cpu')
            ->withEmbeddedTitle('12:00', TitleAnchor::TopCenter);
        $line = self::lines($b, 20)[0];
        $this->assertSame('┌─┐cpu┌──┐12:00┌─────┐', $line);
        $this->assertSame(9, mb_strpos($line, '┐12:00'));
    }

    public function testCenterFallsBackToLeftoverSpaceWhenFullRunWouldOverlap(): void
    {
        $b = Border::normal()
            ->withEmbeddedTitle('averylongleft')
            ->withEmbeddedTitle('ck', TitleAnchor::TopCenter);
        $line = self::lines($b, 20)[0];
        $this->assertSame('┌─┐averylongleft┌┐ck┌┐', $line);
        $this->assertSame(22, Width::string($line));
    }

    public function testMultipleCenterEmbedsAreCentredAsOneGroup(): void
    {
        // group ┐a┌─┐b┌ spans 7 → inner len 5 → lead floor(20/2) - floor(5/2) = 8
        $b = Border::normal()
            ->withEmbeddedTitle('a', TitleAnchor::TopCenter)
            ->withEmbeddedTitle('b', TitleAnchor::TopCenter);
        $this->assertSame('┌────────┐a┌─┐b┌─────┐', self::lines($b, 20)[0]);
    }

    public function testBottomCenterAndBottomRightEmbeds(): void
    {
        $b = Border::normal()
            ->withEmbeddedTitle('up', TitleAnchor::BottomCenter)
            ->withEmbeddedTitle('bat', TitleAnchor::BottomRight);
        $l = self::lines($b, 20);
        $this->assertSame('┌────────────────────┐', $l[0]);
        $this->assertSame('└─────────┘up└─┘bat└─┘', $l[2]);
    }

    public function testAsciiFamilyRendersPlusJunctions(): void
    {
        $b = Border::ascii()
            ->withEmbeddedTitle('cpu')
            ->withEmbeddedTitle('m', TitleAnchor::BottomLeft);
        $l = self::lines($b, 20);
        $this->assertSame('+-+cpu+--------------+', $l[0]);
        $this->assertSame('+-+m+----------------+', $l[2]);
    }

    public function testPreStyledOverflowKeepsEllipsisInsideTheStyledSpan(): void
    {
        $styled = "\x1b[1mabcdefgh\x1b[0m";
        $line = self::lines(Border::normal()->withEmbeddedTitle($styled), 8)[0];
        $this->assertSame("┌─┐\x1b[1mabcd…\x1b[0m┌┐", $line);
    }

    // ── seam matrix ───────────────────────────────────────────────────────

    /** @return iterable<string, array{bool, bool, bool, bool, string}> */
    public static function seamCases(): iterable
    {
        //                       left   right  up     down
        yield 'none'          => [false, false, false, false, ' '];
        yield 'left'          => [true,  false, false, false, '─'];
        yield 'right'         => [false, true,  false, false, '─'];
        yield 'up'            => [false, false, true,  false, '│'];
        yield 'down'          => [false, false, false, true,  '│'];
        yield 'left+right'    => [true,  true,  false, false, '─'];
        yield 'up+down'       => [false, false, true,  true,  '│'];
        yield 'right+down'    => [false, true,  false, true,  '┌'];
        yield 'left+down'     => [true,  false, false, true,  '┐'];
        yield 'right+up'      => [false, true,  true,  false, '└'];
        yield 'left+up'       => [true,  false, true,  false, '┘'];
        yield 'l+r+down'      => [true,  true,  false, true,  '┬'];
        yield 'l+r+up'        => [true,  true,  true,  false, '┴'];
        yield 'u+d+right'     => [false, true,  true,  true,  '├'];
        yield 'u+d+left'      => [true,  false, true,  true,  '┤'];
        yield 'all'           => [true,  true,  true,  true,  '┼'];
    }

    #[DataProvider('seamCases')]
    public function testSeamMatrix(bool $left, bool $right, bool $up, bool $down, string $expected): void
    {
        $this->assertSame($expected, Border::normal()->seam($left, $right, $up, $down));
    }

    public function testSeamKeepsBorderFamily(): void
    {
        $this->assertSame('┳', Border::thick()->seam(true, true, false, true));
        $this->assertSame('╩', Border::double()->seam(true, true, true, false));
        $this->assertSame('╭', Border::rounded()->seam(false, true, false, true));
        $this->assertSame('+', Border::ascii()->seam(true, true, true, true));
    }
}
