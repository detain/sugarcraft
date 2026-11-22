<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests\Help;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Keys\KeyBindings;
use SugarCraft\Core\Model;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\KeyReleaseMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Widgets\Help\Help;
use SugarCraft\Widgets\Help\HelpOverlay;
use SugarCraft\Widgets\Help\HelpOverlayClosedMsg;
use SugarCraft\Widgets\Key\KeyView;

/**
 * HelpOverlay: the generated full help as a scrolling TEA model, driven only
 * through update() and observed only through view() and its accessors.
 */
final class HelpOverlayTest extends TestCase
{
    /** Six actions → six help lines (a-f). */
    private static function registry(): KeyBindings
    {
        $keys = KeyBindings::new();
        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $key) {
            $keys = $keys->bind("act-$key", $key, "do $key");
        }

        return $keys;
    }

    private static function overlay(int $height = 3): HelpOverlay
    {
        return HelpOverlay::new(self::registry())->withHeight($height);
    }

    /** @return array{0: HelpOverlay, 1: ?\Closure} */
    private static function press(HelpOverlay $o, KeyType $type, string $rune = ''): array
    {
        return $o->update(new KeyMsg($type, $rune));
    }

    public function testIsAModelWithNoInitOrSubscriptions(): void
    {
        $o = self::overlay();

        self::assertInstanceOf(Model::class, $o);
        self::assertNull($o->init());
        self::assertNull($o->subscriptions());
        self::assertTrue($o->open);
    }

    public function testViewShowsTheFirstWindowOfTheGeneratedHelp(): void
    {
        self::assertSame("a do a\nb do b\nc do c", self::overlay()->view());
        self::assertSame(6, self::overlay()->lineCount());
        self::assertSame(3, self::overlay()->maxOffset());
    }

    public function testArrowAndVimKeysScrollOneRow(): void
    {
        [$o, $cmd] = self::press(self::overlay(), KeyType::Down);
        self::assertNull($cmd);
        self::assertSame("b do b\nc do c\nd do d", $o->view());

        [$o] = self::press($o, KeyType::Char, 'j');
        self::assertSame("c do c\nd do d\ne do e", $o->view());

        [$o] = self::press($o, KeyType::Char, 'k');
        [$o] = self::press($o, KeyType::Up);
        self::assertSame("a do a\nb do b\nc do c", $o->view());
        self::assertTrue($o->atTop());
    }

    public function testScrollingClampsAtBothEdges(): void
    {
        [$o] = self::press(self::overlay(), KeyType::Up);
        self::assertSame(0, $o->offset);

        foreach (range(1, 10) as $_) {
            [$o] = self::press($o, KeyType::Down);
        }
        self::assertSame(3, $o->offset);
        self::assertTrue($o->atBottom());
        self::assertSame("d do d\ne do e\nf do f", $o->view());
    }

    public function testPageKeysMoveAWindowAndHomeEndJump(): void
    {
        [$o] = self::press(self::overlay(2), KeyType::PageDown);
        self::assertSame("c do c\nd do d", $o->view());

        [$o] = self::press($o, KeyType::Space, ' ');
        self::assertSame("e do e\nf do f", $o->view());

        [$o] = self::press($o, KeyType::PageUp);
        self::assertSame("c do c\nd do d", $o->view());

        [$o] = self::press($o, KeyType::End);
        self::assertSame("e do e\nf do f", $o->view());

        [$o] = self::press($o, KeyType::Home);
        self::assertSame("a do a\nb do b", $o->view());
    }

    public function testMouseWheelScrolls(): void
    {
        [$o] = self::overlay()->update(new MouseWheelMsg(0, 0, MouseButton::WheelDown, MouseAction::Press));
        self::assertSame(1, $o->offset);

        [$o] = $o->update(new MouseWheelMsg(0, 0, MouseButton::WheelUp, MouseAction::Press));
        self::assertSame(0, $o->offset);
    }

    public function testCloseBindingClosesAndAnnouncesIt(): void
    {
        foreach ([[KeyType::Escape, ''], [KeyType::Char, 'q'], [KeyType::Char, '?']] as [$type, $rune]) {
            [$o, $cmd] = self::press(self::overlay(), $type, $rune);

            self::assertFalse($o->open);
            self::assertSame('', $o->view());
            self::assertNotNull($cmd);
            self::assertInstanceOf(HelpOverlayClosedMsg::class, $cmd());
        }
    }

    public function testAClosedOverlayIgnoresEveryMessageAndReopensWhereItWas(): void
    {
        [$o] = self::press(self::overlay(), KeyType::Down);
        $closed = $o->withOpen(false);

        [$same, $cmd] = self::press($closed, KeyType::Down);
        self::assertSame($closed, $same);
        self::assertNull($cmd);

        self::assertSame("b do b\nc do c\nd do d", $closed->withOpen(true)->view());
    }

    public function testUnboundMessagesAndReleasesAreInert(): void
    {
        $o = self::overlay();

        foreach ([
            new KeyMsg(KeyType::Char, 'x'),
            new KeyReleaseMsg(KeyType::Down),
            new KeyMsg(KeyType::Down, ctrl: true),
            new WindowSizeMsg(80, 24),
        ] as $msg) {
            [$same, $cmd] = $o->update($msg);
            self::assertSame($o, $same);
            self::assertNull($cmd);
        }
    }

    public function testShiftHeldOverANamedKeyStillNavigates(): void
    {
        [$o] = self::overlay(2)->update(new KeyMsg(KeyType::Down, shift: true));
        self::assertSame(1, $o->offset, 'Shift+Down scrolls like Down');

        [$o] = $o->update(new KeyMsg(KeyType::PageDown, shift: true));
        self::assertSame(3, $o->offset, 'Shift+PageDown pages like PageDown');

        [$o] = $o->update(new KeyMsg(KeyType::Up, shift: true));
        self::assertSame(2, $o->offset, 'Shift+Up scrolls like Up');

        [$same] = $o->update(new KeyMsg(KeyType::Down, shift: true, ctrl: true));
        self::assertSame($o, $same, 'Ctrl+Shift+Down is not folded');

        $remapped = $o->withKeys(HelpOverlay::defaultKeys()->withOverride('top', 'shift+down'));
        [$o] = $remapped->update(new KeyMsg(KeyType::Down, shift: true));
        self::assertSame(0, $o->offset, 'an exact shift+ binding wins over the fallback');
    }

    public function testNavigationIsRemappableThroughKeyBindings(): void
    {
        $keys = HelpOverlay::defaultKeys()->withOverride('scroll-down', 'ctrl+n');
        $o = self::overlay()->withKeys($keys);

        [$o] = self::press($o, KeyType::Down);
        self::assertSame(0, $o->offset, 'the old key no longer scrolls');

        [$o] = $o->update(new KeyMsg(KeyType::Char, 'n', ctrl: true));
        self::assertSame(1, $o->offset);
    }

    public function testAPartialKeyFileFoldedOnTheDefaultsKeepsTheOtherBindings(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sc-overlay-keys-');
        self::assertIsString($path);
        try {
            file_put_contents($path, '{"scroll-down": ["ctrl+n"]}');
            $o = self::overlay()->withKeys(KeyBindings::fromFile($path, HelpOverlay::defaultKeys()));
        } finally {
            unlink($path);
        }

        [$o] = $o->update(new KeyMsg(KeyType::Char, 'n', ctrl: true));
        self::assertSame(1, $o->offset, 'the file remaps scroll-down');

        [$o] = self::press($o, KeyType::Up);
        self::assertSame(0, $o->offset, 'an action the file does not name keeps its default');

        [$closed, $cmd] = self::press($o, KeyType::Escape);
        self::assertFalse($closed->open, 'close still answers to Escape');
        self::assertNotNull($cmd);
    }

    public function testShorterContentOrTallerWindowReclampsTheOffset(): void
    {
        [$o] = self::press(self::overlay(), KeyType::End);
        self::assertSame(3, $o->offset);

        self::assertSame(1, $o->withHeight(5)->offset);
        self::assertSame(0, $o->withKeyMap(KeyBindings::new()->bind('x', 'x', 'only'))->offset);
        self::assertSame('x only', $o->withKeyMap(KeyBindings::new()->bind('x', 'x', 'only'))->view());
    }

    public function testScrollHelpersAreImmutableAndClamped(): void
    {
        $o = self::overlay();

        self::assertSame(2, $o->scrollBy(2)->offset);
        self::assertSame(0, $o->offset);
        self::assertSame(3, $o->scrollTo(99)->offset);
        self::assertSame(0, $o->scrollTo(-5)->offset);
    }

    public function testTheRendererIsReplaceable(): void
    {
        $o = HelpOverlay::new(KeyView::new(self::registry())->withColumnSize(3), Help::new()->withKeyDescGap(': '));

        self::assertSame(3, $o->lineCount());
        self::assertSame('a: do a    d: do d', explode("\n", $o->view())[0]);
        self::assertSame('a - do a    d - do d', explode("\n", $o->withHelp(Help::new()->withKeyDescGap(' - '))->view())[0]);
    }

    public function testHeightMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::overlay()->withHeight(0);
    }
}
