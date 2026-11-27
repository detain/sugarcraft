<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests\Disclosure;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\KeyReleaseMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Style\Style;
use SugarCraft\Widgets\Disclosure\Disclosure;
use SugarCraft\Widgets\Disclosure\DisclosureToggledMsg;

/**
 * P2-3a: the standalone Disclosure, its immutable setters, toggle and the
 * toggled message.
 */
final class DisclosureTest extends TestCase
{
    private static function key(KeyType $type, string $rune = ''): KeyMsg
    {
        return new KeyMsg($type, $rune);
    }

    public function testNewStartsClosedAndBlurred(): void
    {
        $d = Disclosure::new('Details', 'body');
        self::assertFalse($d->isOpen());
        self::assertFalse($d->isFocused());
        self::assertSame('Details', $d->title());
        self::assertSame('body', $d->body());
        self::assertNull($d->init());
        self::assertNull($d->subscriptions());
    }

    public function testConstructorIsPrivate(): void
    {
        self::assertTrue((new \ReflectionClass(Disclosure::class))->getConstructor()?->isPrivate());
    }

    public function testWithOpenReturnsANewInstanceAndLeavesTheOriginal(): void
    {
        $closed = Disclosure::new('T');
        $open = $closed->withOpen(true);
        self::assertNotSame($closed, $open);
        self::assertTrue($open->isOpen());
        self::assertFalse($closed->isOpen());
        self::assertFalse($open->withOpen(false)->isOpen());
        self::assertTrue($closed->withOpen()->isOpen(), 'withOpen() defaults to open');
    }

    public function testToggleFlipsAndIsImmutable(): void
    {
        $d = Disclosure::new('T');
        $t = $d->toggle();
        self::assertNotSame($d, $t);
        self::assertTrue($t->isOpen());
        self::assertFalse($d->isOpen());
        self::assertFalse($t->toggle()->isOpen());
    }

    public function testPlanChainCompiles(): void
    {
        // The plan's call form: new($title, $body)->withOpen($b)->toggle()->update($msg).
        [$d, $cmd] = Disclosure::new('T', 'b')->withOpen(true)->toggle()->update(new WindowSizeMsg(80, 24));
        self::assertInstanceOf(Disclosure::class, $d);
        self::assertFalse($d->isOpen());
        self::assertNull($cmd);
    }

    public function testClosedViewShowsOnlyTheTitleLine(): void
    {
        self::assertSame('▸ Details', Disclosure::new('Details', "one\ntwo")->view());
    }

    public function testOpenViewIndentsTheBody(): void
    {
        $d = Disclosure::new('Details', "one\n\ntwo")->withOpen();
        self::assertSame("▾ Details\n  one\n\n  two", $d->view());
        self::assertSame($d->view(), (string) $d);
    }

    public function testOpenWithEmptyBodyShowsOnlyTheTitle(): void
    {
        self::assertSame('▾ T', Disclosure::new('T')->withOpen()->view());
    }

    public function testFocusedViewUsesTheFocusedStyle(): void
    {
        $d = Disclosure::new('T')->focus();
        self::assertSame(Style::new()->reverse()->render('▸ T'), $d->view());
        $custom = Style::new()->bold();
        self::assertSame($custom->render('▸ T'), $d->withFocusedStyle($custom)->view());
    }

    public function testTitleStyleAppliesWhileBlurred(): void
    {
        $s = Style::new()->underline();
        self::assertSame($s->render('▸ T'), Disclosure::new('T')->withTitleStyle($s)->view());
    }

    public function testWithIndicatorsAndIndent(): void
    {
        $d = Disclosure::new('T', 'b')->withIndicators('-', '+')->withIndent(4);
        self::assertSame('+ T', $d->view());
        self::assertSame("- T\n    b", $d->withOpen()->view());
        self::assertSame("- T\nb", $d->withOpen()->withIndent(-3)->view());
    }

    public function testWithTitleAndWithBody(): void
    {
        $d = Disclosure::new('A', 'x')->withTitle('B')->withBody("y\r\nz\rw")->withOpen();
        self::assertSame("▾ B\n  y\n  z\n  w", $d->view());
    }

    public function testTitleIsSanitizedToOneLine(): void
    {
        $d = Disclosure::new("Bad\x1b[31m\nTitle\t!");
        self::assertSame('Bad Title !', $d->title());
    }

    public function testBodyKeepsItsSgr(): void
    {
        $d = Disclosure::new('T', "\x1b[1mbold\x1b[0m")->withOpen();
        self::assertSame("▾ T\n  \x1b[1mbold\x1b[0m", $d->view());
    }

    public function testBodyDropsEveryEscapeButSgr(): void
    {
        $body = "\x1b[31mred\x1b[0m"
            . "\x1b]52;c;aGk=\x07"   // OSC 52 clipboard write
            . "\x1b[2J"              // clear screen
            . "\x9b2J"               // 8-bit CSI introducer
            . "\xC2\x9b2J"           // UTF-8 encoded C1 CSI
            . "\x07\x00end";
        $view = Disclosure::new('T', $body)->withOpen()->view();
        self::assertSame("▾ T\n  \x1b[31mred\x1b[0m2Jend", $view);

        $set = Disclosure::new('T')->withBody("\x1b]0;title\x07ok")->withOpen();
        self::assertSame("▾ T\n  ok", $set->view());
    }

    public function testBodyIsStrippedOfZoneSentinels(): void
    {
        $body = "\x1b[1mok\x1b[0m" . Sanitize::ZONE_SENTINEL_OPEN . 'forged'
            . Sanitize::ZONE_SENTINEL_CLOSE;
        $view = Disclosure::new('T', $body)->withOpen()->view();
        self::assertStringNotContainsString(Sanitize::ZONE_SENTINEL_OPEN, $view);
        self::assertStringNotContainsString(Sanitize::ZONE_SENTINEL_CLOSE, $view);
        self::assertSame("▾ T\n  \x1b[1mok\x1b[0mforged", $view);

        $set = Disclosure::new('T')->withBody(Sanitize::ZONE_SENTINEL_OPEN . 'x')->withOpen();
        self::assertSame("▾ T\n  x", $set->view());
    }

    public function testFocusAndBlur(): void
    {
        $d = Disclosure::new('T');
        self::assertTrue($d->focus()->isFocused());
        self::assertFalse($d->focus()->blur()->isFocused());
        self::assertTrue($d->withFocused()->isFocused());
    }

    public function testUpdateIgnoresKeysWhileBlurred(): void
    {
        $d = Disclosure::new('T');
        [$next, $cmd] = $d->update(self::key(KeyType::Enter));
        self::assertSame($d, $next);
        self::assertNull($cmd);
    }

    /** @return array<string, array{KeyMsg, bool, bool}> key, start open, end open */
    public static function toggleKeys(): array
    {
        return [
            'enter opens' => [self::key(KeyType::Enter), false, true],
            'enter closes' => [self::key(KeyType::Enter), true, false],
            'space opens' => [self::key(KeyType::Space), false, true],
            'right opens' => [self::key(KeyType::Right), false, true],
            'l opens' => [self::key(KeyType::Char, 'l'), false, true],
            'left closes' => [self::key(KeyType::Left), true, false],
            'h closes' => [self::key(KeyType::Char, 'h'), true, false],
        ];
    }

    #[DataProvider('toggleKeys')]
    public function testKeysChangeStateAndSendTheToggledMsg(KeyMsg $key, bool $from, bool $to): void
    {
        [$next, $cmd] = Disclosure::new('Section')->withOpen($from)->focus()->update($key);
        self::assertSame($to, $next->isOpen());
        self::assertInstanceOf(\Closure::class, $cmd);
        $msg = $cmd();
        self::assertInstanceOf(DisclosureToggledMsg::class, $msg);
        self::assertSame('Section', $msg->title);
        self::assertSame($to, $msg->open);
        self::assertNull($msg->index);
    }

    public function testKeyAskingForTheCurrentStateIsANoOp(): void
    {
        $open = Disclosure::new('T')->withOpen()->focus();
        [$next, $cmd] = $open->update(self::key(KeyType::Right));
        self::assertSame($open, $next);
        self::assertNull($cmd);

        $closed = Disclosure::new('T')->focus();
        [$next, $cmd] = $closed->update(self::key(KeyType::Left));
        self::assertSame($closed, $next);
        self::assertNull($cmd);
    }

    public function testUnrelatedModifiedAndReleasedKeysAreIgnored(): void
    {
        $d = Disclosure::new('T')->focus();
        foreach ([
            self::key(KeyType::Char, 'x'),
            new KeyMsg(KeyType::Enter, ctrl: true),
            new KeyMsg(KeyType::Char, 'l', alt: true),
            new KeyReleaseMsg(KeyType::Enter),
        ] as $key) {
            [$next, $cmd] = $d->update($key);
            self::assertSame($d, $next, $key->string());
            self::assertNull($cmd, $key->string());
        }
    }
}
