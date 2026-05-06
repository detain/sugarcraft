<?php

declare(strict_types=1);

namespace CandyCore\Crumbs\Tests;

use CandyCore\Crumbs\{Breadcrumb, NavStack, NavigationItem, Shell};
use PHPUnit\Framework\TestCase;

final class NavStackTest extends TestCase
{
    public function testPushAndPop(): void
    {
        $s = new NavStack();
        $s->push('Home');
        $s->push('Settings');

        $this->assertSame(2, $s->depth());
        $this->assertSame('Settings', $s->current()->title);

        $popped = $s->pop();
        $this->assertSame('Settings', $popped->title);
        $this->assertSame(1, $s->depth());
        $this->assertSame('Home', $s->current()->title);
    }

    public function testPopEmptyReturnsNull(): void
    {
        $s = new NavStack();
        $this->assertNull($s->pop());
    }

    public function testParent(): void
    {
        $s = new NavStack();
        $s->push('Home');
        $s->push('Settings');
        $s->push('Display');

        $this->assertSame('Settings', $s->parent()->title);
    }

    public function testParentOnEmptyOrSingle(): void
    {
        $s = new NavStack();
        $this->assertNull($s->parent());

        $s->push('Only');
        $this->assertNull($s->parent());
    }

    public function testIsEmpty(): void
    {
        $s = new NavStack();
        $this->assertTrue($s->isEmpty());

        $s->push('Item');
        $this->assertFalse($s->isEmpty());
    }

    public function testClear(): void
    {
        $s = new NavStack();
        $s->push('a')->push('b');
        $s->clear();
        $this->assertTrue($s->isEmpty());
    }

    public function testItemsReturnsAllItems(): void
    {
        $s = new NavStack();
        $s->push('a')->push('b')->push('c');
        $items = $s->items();

        $this->assertCount(3, $items);
        $this->assertSame('a', $items[0]->title);
        $this->assertSame('c', $items[2]->title);
    }

    public function testUpdateTop(): void
    {
        $s = new NavStack();
        $s->push('Settings', ['theme' => 'dark']);
        $s->push('Display', ['contrast' => 'high']);

        $s->updateTop(['contrast' => 'low', 'brightness' => 80]);
        $this->assertSame('Display', $s->current()->title);
        $this->assertSame('low', $s->current()->data['contrast']);
        $this->assertSame(80, $s->current()->data['brightness']);
    }

    public function testNavigationItemData(): void
    {
        $item = new NavigationItem('Settings', ['key' => 'value']);
        $this->assertSame('Settings', $item->title);
        $this->assertSame('value', $item->data['key']);
    }

    public function testBreadcrumbRender(): void
    {
        $s = new NavStack();
        $s->push('Home')->push('Settings')->push('Display');

        $bc = new Breadcrumb();
        $result = $bc->render($s);

        $this->assertSame('Home › Settings › Display', $result);
    }

    public function testBreadcrumbCustomSeparator(): void
    {
        $s = new NavStack();
        $s->push('Home')->push('Settings');

        $bc = (new Breadcrumb())->setSeparator(' / ');
        $this->assertSame('Home / Settings', $bc->render($s));
    }

    public function testBreadcrumbTruncate(): void
    {
        $s = new NavStack();
        $s->push('Very Long Root Navigation Item')
          ->push('Medium Length Parent Item')
          ->push('Current Page Title');

        $bc = (new Breadcrumb())->setMaxWidth(30);
        $result = $bc->render($s);

        $this->assertLessThanOrEqual(30, \strlen($result));
        $this->assertStringContainsString('Current Page Title', $result);
    }

    public function testBreadcrumbEmptyStack(): void
    {
        $s = new NavStack();
        $bc = new Breadcrumb();
        $this->assertSame('', $bc->render($s));
    }

    public function testBreadcrumbTruncateWithTruncator(): void
    {
        $bc = (new Breadcrumb())->setTruncator('…')->setMaxWidth(20);
        $titles = ['Root', 'Parent', 'Child', 'CurrentItem'];
        $result = $bc->renderTitles($titles);

        $this->assertStringContainsString('…', $result);
    }

    public function testBreadcrumbCustomItemRenderer(): void
    {
        $s = new NavStack();
        $s->push('Home')->push('Settings');

        $bc = (new Breadcrumb())->setItemRenderer(
            fn($item, $i) => "[{$i}] {$item->title}"
        );

        $this->assertSame('[0] Home › [1] Settings', $bc->render($s));
    }

    public function testShellPushPop(): void
    {
        $shell = Shell::new()
            ->withPush('Home')
            ->withPush('Settings')
            ->withPush('Display');

        $this->assertSame('Display', $shell->stack->current()->title);
        $this->assertSame(3, $shell->stack->depth());

        $popped = $shell->stack->pop();
        $this->assertSame('Display', $popped->title);
    }

    public function testShellBreadcrumbRendering(): void
    {
        $shell = Shell::new((new Breadcrumb())->setSeparator(' > '))
            ->withPush('Root')
            ->withPush('Child');

        $this->assertSame('Root > Child', $shell->renderBreadcrumb());
    }
}
