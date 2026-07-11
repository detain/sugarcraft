<?php

declare(strict_types=1);

namespace SugarCraft\Crumbs\Tests;

use SugarCraft\Crumbs\{Breadcrumb, NavStack, NavigationItem, Shell};
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

    public function testViewWithItems(): void
    {
        $s = new NavStack();
        $s->push('Home')->push('Settings')->push('Display');

        $this->assertSame('Home > Settings > Display', $s->view());
    }

    public function testViewWithCustomSeparator(): void
    {
        $s = new NavStack();
        $s->push('Home')->push('Settings');

        $this->assertSame('Home / Settings', $s->view(' / '));
    }

    public function testViewWithEmptyStack(): void
    {
        $s = new NavStack();
        $this->assertSame('', $s->view());
    }

    public function testViewSanitizesControlSequenceInjection(): void
    {
        // A directory segment (Shell::pushDirectory) or URL-decoded segment
        // (Url::parse) is user-controlled and may smuggle an ANSI SGR sequence,
        // a raw NUL, and a newline into a title.
        $s = new NavStack();
        $s->push('Home');
        $s->push("Ev\x1b[31mil\x00\nName");

        $view = $s->view();

        // Nothing that could desync the terminal / frame-diff renderer survives.
        $this->assertStringNotContainsString("\x1b", $view);
        $this->assertStringNotContainsString("\x00", $view);
        $this->assertStringNotContainsString("\n", $view);
        // The escape sequence is stripped whole (params included), not just ESC.
        $this->assertStringNotContainsString('[31m', $view);
        // Printable text is preserved.
        $this->assertStringContainsString('Home', $view);
        $this->assertStringContainsString('Evil', $view);
    }

    public function testFilterMatchingTitle(): void
    {
        $s = new NavStack();
        $s->push('Home')->push('Settings')->push('Display')->push('Sound');

        $filtered = $s->filter('dis');
        $this->assertSame(1, $filtered->depth());
        $this->assertSame('Display', $filtered->items()[0]->title);
    }

    public function testFilterMatchingData(): void
    {
        $s = new NavStack();
        $s->push('Home', '/home');
        $s->push('Settings', '/settings/display');
        $s->push('Display', '/settings/display/resolution');

        $filtered = $s->filter('/display');
        $this->assertSame(2, $filtered->depth());
    }

    public function testFilterNonMatching(): void
    {
        $s = new NavStack();
        $s->push('Home')->push('Settings');

        $filtered = $s->filter('nonexistent');
        $this->assertSame(0, $filtered->depth());
    }

    public function testFilterCaseInsensitive(): void
    {
        $s = new NavStack();
        $s->push('Home')->push('Settings')->push('Display');

        $filtered = $s->filter('SET');
        $this->assertSame(1, $filtered->depth());
        $this->assertSame('Settings', $filtered->items()[0]->title);
    }

    public function testFilterWithArrayDataDoesNotFatal(): void
    {
        // The example pattern stores an array as item data. filter() casts data
        // to string for matching; a raw (string) cast on an array warns (fatal
        // under failOnWarning) — non-stringifiable data must be skipped, not cast.
        $s = new NavStack();
        $s->push('Dashboard', ['route' => '/dash', 'id' => 7]); // array data
        $s->push('Reports', '/reports');                        // scalar data

        // 'dash' matches the 'Dashboard' title; iterating the array-data item
        // must not trigger an Array-to-string conversion.
        $filtered = $s->filter('dash');
        $this->assertSame(1, $filtered->depth());
        $this->assertSame('Dashboard', $filtered->items()[0]->title);

        // A term that only appears in scalar data still matches (regression:
        // the guard must not disable scalar-data matching).
        $this->assertSame(1, $s->filter('reports')->depth());
    }

    public function testFilterMatchesStringableData(): void
    {
        $stringable = new class {
            public function __toString(): string
            {
                return '/settings/display';
            }
        };
        $s = new NavStack();
        $s->push('Settings', $stringable);

        $this->assertSame(1, $s->filter('display')->depth());
    }

    public function testPushDirectory(): void
    {
        $shell = Shell::new();
        $shell = $shell->pushDirectory('/home/user/projects/sugarcraft/src');

        $this->assertSame(5, $shell->stack->depth());
        $this->assertSame('home', $shell->stack->items()[0]->title);
        $this->assertSame('/home', $shell->stack->items()[0]->data);
        $this->assertSame('src', $shell->stack->items()[4]->title);
        $this->assertSame('/home/user/projects/sugarcraft/src', $shell->stack->items()[4]->data);
    }

    public function testPushDirectoryEmpty(): void
    {
        $shell = Shell::new();
        $shell = $shell->pushDirectory('');

        $this->assertSame(0, $shell->stack->depth());
    }

    public function testPushDirectoryNoLeadingSlash(): void
    {
        $shell = Shell::new();
        $shell = $shell->pushDirectory('home/user/projects');

        $this->assertSame(3, $shell->stack->depth());
        $this->assertSame('home', $shell->stack->items()[0]->title);
        $this->assertSame('/home', $shell->stack->items()[0]->data);
    }

    // ─── Step 5: ::new() factory ───────────────────────────────────────────────

    public function testNewFactory(): void
    {
        $s = NavStack::new();
        $s->push('Home');
        $this->assertSame(1, $s->depth());
        $this->assertFalse($s->isEmpty());
    }

    // ─── Step 7: canPop / popTo ───────────────────────────────────────────────

    public function testCanPop(): void
    {
        $s = NavStack::new();
        $this->assertFalse($s->canPop()); // 0 items

        $s->push('Only');
        $this->assertFalse($s->canPop()); // 1 item (root)

        $s->push('Child');
        $this->assertTrue($s->canPop()); // 2 items

        $s->push('Grandchild');
        $this->assertTrue($s->canPop()); // 3 items
    }

    public function testPopToTruncatesStack(): void
    {
        $s = NavStack::new();
        $s->push('Home')->push('Settings')->push('Display')->push('Resolution');

        // popTo(1) keeps items[0..1] → 2 items oldest-first
        $result = $s->popTo(1);
        $this->assertSame($s, $result); // mutates and returns $this
        $this->assertSame(2, $s->depth());
        $this->assertSame('Home', $s->items()[0]->title);
        $this->assertSame('Settings', $s->items()[1]->title);
    }

    public function testPopToClampsOutOfRange(): void
    {
        $s = NavStack::new();
        $s->push('Home')->push('Settings');

        // popTo(99) clamps to current depth (2)
        $s->popTo(99);
        $this->assertSame(2, $s->depth());

        // popTo(-5) clamps to 0 (empty stack)
        $s->popTo(-5);
        $this->assertSame(0, $s->depth());
    }

    public function testShellWithPopOnEmptyStackIsNoOp(): void
    {
        $shell = Shell::new();
        $this->assertSame(0, $shell->stack->depth());

        $popped = $shell->withPop();
        // withPop on empty stack creates a new NavStack but it's still empty
        $this->assertSame(0, $popped->stack->depth());
    }

    // ─── Step 8: Shell default breadcrumb construction ─────────────────────────

    public function testShellNewWithoutBreadcrumbUsesDefault(): void
    {
        // Shell::new() uses new Breadcrumb() as default
        // The default separator is ' › '
        $shell = Shell::new();
        $shell->stack->push('Home')->push('Settings');

        $rendered = $shell->renderBreadcrumb();
        $this->assertSame('Home › Settings', $rendered);
    }
}
