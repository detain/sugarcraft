<?php

declare(strict_types=1);

namespace SugarCraft\Crumbs\Tests;

use SugarCraft\Crumbs\{Closable, NavigationItem};
use PHPUnit\Framework\TestCase;

final class ClosableTest extends TestCase
{
    public function testNavigationItemImplementsClosable(): void
    {
        $item = new NavigationItem('Home');
        $this->assertInstanceOf(Closable::class, $item);
    }

    public function testOnEnterIsNoOpByDefault(): void
    {
        $item = new NavigationItem('Home');
        // Should not throw - no-op by default
        $item->onEnter();
        $this->assertSame('Home', $item->title());
    }

    public function testOnLeaveIsNoOpByDefault(): void
    {
        $item = new NavigationItem('Settings');
        // Should not throw - no-op by default
        $item->onLeave();
        $this->assertSame('Settings', $item->title());
    }

    public function testTitleReturnsTitle(): void
    {
        $item = new NavigationItem('Display');
        $this->assertSame('Display', $item->title());
    }

    public function testNavigationItemWithData(): void
    {
        $item = new NavigationItem('Settings', ['theme' => 'dark']);
        $this->assertSame('Settings', $item->title());
        $this->assertSame('dark', $item->data['theme']);
    }

    public function testNavigationItemNullData(): void
    {
        $item = new NavigationItem('Home');
        $this->assertNull($item->data);
    }

    public function testNavigationItemImplementsAllClosableMethods(): void
    {
        $item = new NavigationItem('Test');
        $this->assertTrue(\method_exists($item, 'onEnter'));
        $this->assertTrue(\method_exists($item, 'onLeave'));
        $this->assertTrue(\method_exists($item, 'title'));
    }
}
