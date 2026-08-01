<?php

declare(strict_types=1);

namespace SugarCraft\Zone\Tests;

use SugarCraft\Mouse\Sentinel;
use SugarCraft\Zone\Manager;
use SugarCraft\Zone\Zones;
use PHPUnit\Framework\TestCase;

final class ZonesTest extends TestCase
{
    private function click(int $x, int $y): \SugarCraft\Core\Msg\MouseMsg
    {
        return new \SugarCraft\Core\Msg\MouseMsg(
            $x, $y,
            \SugarCraft\Core\MouseButton::Left,
            \SugarCraft\Core\MouseAction::Press
        );
    }

    protected function tearDown(): void
    {
        Zones::setDefaultManager(null);
    }

    public function testDefaultManagerLazyConstructed(): void
    {
        $a = Zones::defaultManager();
        $b = Zones::defaultManager();
        $this->assertSame($a, $b);
    }

    public function testSetDefaultManagerOverrides(): void
    {
        $custom = Manager::newPrefix('test-');
        Zones::setDefaultManager($custom);
        $this->assertSame($custom, Zones::defaultManager());
    }

    public function testMarkScanRoundTripViaFacade(): void
    {
        $marked = Zones::mark('hero', 'Hello World');
        $scanned = Zones::scan($marked);
        // scan() must strip every PUA sentinel tag, leaving display-ready text.
        $this->assertStringNotContainsString(Sentinel::OPEN, $scanned);
        $this->assertStringNotContainsString(Sentinel::CLOSE, $scanned);
        $this->assertSame('Hello World', $scanned);
        $zone = Zones::get('hero');
        $this->assertNotNull($zone);
    }

    public function testClearRemovesAllByDefault(): void
    {
        Zones::scan(Zones::mark('a', 'a-text') . Zones::mark('b', 'b-text'));
        $this->assertNotNull(Zones::get('a'));
        Zones::clear();
        $this->assertNull(Zones::get('a'));
        $this->assertNull(Zones::get('b'));
    }

    public function testClearTargetedDropsOneZone(): void
    {
        Zones::scan(Zones::mark('a', 'aa') . Zones::mark('b', 'bb'));
        Zones::clear('a');
        $this->assertNull(Zones::get('a'));
        $this->assertNotNull(Zones::get('b'));
    }

    public function testSetEnabledTogglesDefaultManager(): void
    {
        Zones::setEnabled(false);
        $this->assertFalse(Zones::isEnabled());
        // Disabled manager: mark() returns content verbatim (no markers).
        $this->assertSame('hi', Zones::mark('x', 'hi'));
        Zones::setEnabled(true);
        $this->assertTrue(Zones::isEnabled());
    }

    public function testCloseDisablesIdempotently(): void
    {
        Zones::close();
        $this->assertFalse(Zones::isEnabled());
        Zones::close();
        $this->assertFalse(Zones::isEnabled());
    }

    public function testNewPrefixReturnsFreshManager(): void
    {
        $a = Zones::newPrefix('a-');
        $b = Zones::newPrefix('b-');
        $this->assertNotSame($a, $b);
        $this->assertSame('a-', $a->prefix());
        $this->assertSame('b-', $b->prefix());
        // Doesn't affect the default manager.
        $this->assertNotSame($a, Zones::defaultManager());
    }

    public function testAnyInBoundsReturnsHitZone(): void
    {
        Zones::scan(Zones::mark('btn', 'OK'));
        $hit = Zones::anyInBounds($this->click(1, 1));
        $this->assertNotNull($hit);
        $this->assertSame('btn', $hit->id);
    }

    public function testAnyInBoundsReturnsNullForMiss(): void
    {
        Zones::scan(Zones::mark('btn', 'OK'));
        $this->assertNull(Zones::anyInBounds($this->click(50, 50)));
    }

    public function testAnyInBoundsReturnsNullForNonMouseMsg(): void
    {
        Zones::scan(Zones::mark('btn', 'OK'));
        $this->assertNull(Zones::anyInBounds(
            new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Char, 'a')
        ));
    }

    /**
     * Zones::anyInBoundsAndUpdate routes a hit to the model via MsgZoneInBounds.
     */
    public function testAnyInBoundsAndUpdateRoutesHitToModel(): void
    {
        Zones::scan(Zones::mark('btn', 'OK'));
        $model = new ZoneRoutingModel();
        [$next] = Zones::anyInBoundsAndUpdate($model, $this->click(1, 1));
        $this->assertInstanceOf(ZoneRoutingModel::class, $next);
        $this->assertNotNull($next->lastInBoundsHit);
        $this->assertSame('btn', $next->lastInBoundsHit->zone->id);
    }

    /**
     * Zones::anyInBoundsAndUpdate passes through on miss.
     */
    public function testAnyInBoundsAndUpdatePassesThroughOnMiss(): void
    {
        Zones::scan(Zones::mark('btn', 'OK'));
        $model = new ZoneRoutingModel();
        $miss = $this->click(50, 50);
        [$next] = Zones::anyInBoundsAndUpdate($model, $miss);
        $this->assertNull($next->lastInBoundsHit);
        $this->assertNotNull($next->lastPlainMouse);
    }
}
