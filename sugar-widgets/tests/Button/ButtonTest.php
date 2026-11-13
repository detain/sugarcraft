<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests\Button;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Mouse\Sentinel;
use SugarCraft\Mouse\Zones\Manager;
use SugarCraft\Mouse\Zones\MsgZoneInBounds;
use SugarCraft\Mouse\Zones\Zone;
use SugarCraft\Style\Style;
use SugarCraft\Widgets\Button\Button;
use SugarCraft\Widgets\Button\ButtonPressedMsg;

final class ButtonTest extends TestCase
{
    private function zoneMsg(string $id): MsgZoneInBounds
    {
        return new MsgZoneInBounds(
            new Zone($id, 0, 0, 8, 0),
            new MouseMsg(2, 0, MouseButton::Left, MouseAction::Press),
        );
    }

    /** @return ?ButtonPressedMsg */
    private function fire(?\Closure $cmd): ?ButtonPressedMsg
    {
        if ($cmd === null) {
            return null;
        }
        $m = $cmd();
        return $m instanceof ButtonPressedMsg ? $m : null;
    }

    public function testIsAModelAndInitIsNull(): void
    {
        $b = Button::new('Save');
        self::assertInstanceOf(Model::class, $b);
        self::assertNull($b->init());
    }

    public function testViewGoldenBytes(): void
    {
        self::assertSame('[ Save ]', Button::new('Save')->withStyles(Style::new(), Style::new(), Style::new())->view());
        self::assertSame("\x1b[7m[ Save ]\x1b[0m", Button::new('Save')->focus()->view());
        self::assertSame("\x1b[2m[ Save ]\x1b[0m", Button::new('Save')->withDisabled()->view());
        self::assertSame('[ Save ]', (string) Button::new('Save'));
    }

    public function testWithersAreImmutable(): void
    {
        $b = Button::new('A');
        $f = $b->withFocused();
        self::assertFalse($b->focused);
        self::assertTrue($f->focused);
        self::assertFalse($f->blur()->focused);
        self::assertSame('B', $b->withLabel('B')->label);
        self::assertTrue($b->withDisabled()->disabled);
        self::assertFalse($b->withDisabled()->withDisabled(false)->disabled);
        self::assertSame('x', $b->withZoneId('x')->zoneId);
    }

    public function testWithStylesKeepsUnspecifiedStates(): void
    {
        $b = Button::new('A');
        $bold = Style::new()->bold();
        $c = $b->withStyles(normal: $bold);
        self::assertSame($bold, $c->style);
        self::assertSame($b->focusedStyle, $c->focusedStyle);
        self::assertSame($b->disabledStyle, $c->disabledStyle);
    }

    public function testEnterAndSpaceActivateWhenFocused(): void
    {
        $b = Button::new('Save')->focus();
        foreach ([new KeyMsg(KeyType::Enter), new KeyMsg(KeyType::Space, rune: ' ')] as $key) {
            [$next, $cmd] = $b->update($key);
            self::assertSame($b, $next);
            $msg = $this->fire($cmd);
            self::assertNotNull($msg);
            self::assertSame('Save', $msg->label);
        }
    }

    public function testKeysAreInertWhenBlurredOrDisabledOrOther(): void
    {
        [, $cmd] = Button::new('S')->update(new KeyMsg(KeyType::Enter));
        self::assertNull($cmd, 'blurred');
        [, $cmd] = Button::new('S')->focus()->withDisabled()->update(new KeyMsg(KeyType::Enter));
        self::assertNull($cmd, 'disabled');
        [, $cmd] = Button::new('S')->focus()->update(new KeyMsg(KeyType::Char, rune: 'x'));
        self::assertNull($cmd, 'other key');
    }

    public function testClickOnItsZoneActivatesEvenWhenBlurred(): void
    {
        $b = Button::new('Go')->withZoneId('go');
        [, $cmd] = $b->update($this->zoneMsg('p:go'));
        self::assertNotNull($this->fire($cmd));
        self::assertSame('go', $this->fire($cmd)?->zoneId);
    }

    public function testClickOnForeignZoneOrWhileDisabledIsInert(): void
    {
        $b = Button::new('Go')->withZoneId('go');
        [, $cmd] = $b->update($this->zoneMsg('other'));
        self::assertNull($cmd);
        [, $cmd] = $b->withDisabled()->update($this->zoneMsg('go'));
        self::assertNull($cmd);
    }

    public function testZoneIsMarkedAndScannedToTheButtonCells(): void
    {
        $m = Manager::newPrefix('t');
        $b = Button::new('Hi')->withZoneManager($m)->withZoneId('hi');
        self::assertStringContainsString(Sentinel::OPEN, $b->view());
        $clean = $m->scan($b->view());
        self::assertSame('[ Hi ]', preg_replace('/\x1b\[[0-9;]*m/', '', $clean));
        $zone = $m->get('hi');
        self::assertNotNull($zone);
        self::assertSame(1, $zone->startCol);
        self::assertSame(6, $zone->endCol);
    }

    public function testDisabledButtonRegistersNoZoneAndDetachWorks(): void
    {
        $m = Manager::newPrefix('t');
        $b = Button::new('Hi')->withZoneManager($m);
        self::assertStringNotContainsString(Sentinel::OPEN, $b->withDisabled()->view());
        self::assertStringNotContainsString(Sentinel::OPEN, $b->withZoneManager(null)->view());
    }

    public function testLabelIsSanitisedAgainstForgedSentinelsAndControls(): void
    {
        $b = Button::new("a\x1b[31mb" . Sentinel::OPEN . "c\x07");
        self::assertStringNotContainsString(Sentinel::OPEN, $b->label);
        self::assertStringNotContainsString("\x07", $b->label);
        self::assertStringNotContainsString("\x1b", $b->label);
    }

    public function testAnnouncementIsLocalised(): void
    {
        self::assertSame('Save activated', (new ButtonPressedMsg('Save', 'button'))->announcement());
    }
}
