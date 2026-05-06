<?php

declare(strict_types=1);

namespace CandyCore\Zone\Tests;

use CandyCore\Zone\Manager;
use CandyCore\Zone\Zones;
use PHPUnit\Framework\TestCase;

final class ZonesTest extends TestCase
{
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
        $this->assertStringNotContainsString('candyzone', $scanned);
        $this->assertStringContainsString('Hello World', $scanned);
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
}
