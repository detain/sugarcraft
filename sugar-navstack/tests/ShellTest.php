<?php

declare(strict_types=1);

namespace SugarCraft\NavStack\Tests;

use SugarCraft\NavStack\{Breadcrumb, NavStack, Shell};
use PHPUnit\Framework\TestCase;

final class ShellTest extends TestCase
{
    // ─── Factory ───────────────────────────────────────────────────────────────

    public function testNewFactory(): void
    {
        $shell = Shell::new();
        $this->assertSame(0, $shell->stack->depth());
        $this->assertInstanceOf(NavStack::class, $shell->stack);
        $this->assertInstanceOf(Breadcrumb::class, $shell->breadcrumb);
    }

    public function testNewWithCustomBreadcrumb(): void
    {
        $bc = (new Breadcrumb())->setSeparator(' > ');
        $shell = Shell::new($bc);

        $this->assertSame($bc, $shell->breadcrumb);
    }

    public function testNewWithNullBreadcrumb(): void
    {
        $shell = Shell::new(null);
        $this->assertInstanceOf(Breadcrumb::class, $shell->breadcrumb);
    }

    // ─── Properties ───────────────────────────────────────────────────────────

    public function testStackProperty(): void
    {
        $shell = Shell::new()->withPush('Home')->withPush('Settings');
        $this->assertSame(2, $shell->stack->depth());
        $this->assertSame('Settings', $shell->stack->current()->title);
    }

    public function testBreadcrumbProperty(): void
    {
        $bc = (new Breadcrumb())->setSeparator(' / ');
        $shell = Shell::new($bc);
        $this->assertSame($bc, $shell->breadcrumb);
    }

    // ─── withPush() ───────────────────────────────────────────────────────────

    public function testWithPushAddsItem(): void
    {
        $shell = Shell::new()->withPush('Home');

        $this->assertSame(1, $shell->stack->depth());
        $this->assertSame('Home', $shell->stack->current()->title);
    }

    public function testWithPushReturnsNewInstance(): void
    {
        $original = Shell::new();
        $pushed = $original->withPush('Home');

        $this->assertNotSame($original, $pushed);
        $this->assertSame(0, $original->stack->depth());
        $this->assertSame(1, $pushed->stack->depth());
    }

    public function testWithPushWithData(): void
    {
        $shell = Shell::new()->withPush('Settings', ['theme' => 'dark']);

        $this->assertSame('Settings', $shell->stack->current()->title);
        $this->assertSame('dark', $shell->stack->current()->data['theme']);
    }

    public function testWithPushChaining(): void
    {
        $shell = Shell::new()
            ->withPush('Home')
            ->withPush('Settings')
            ->withPush('Display');

        $this->assertSame(3, $shell->stack->depth());
        $this->assertSame('Display', $shell->stack->current()->title);
    }

    // ─── withPop() ───────────────────────────────────────────────────────────

    public function testWithPopRemovesTopItem(): void
    {
        $shell = Shell::new()
            ->withPush('Home')
            ->withPush('Settings')
            ->withPop();

        $this->assertSame(1, $shell->stack->depth());
        $this->assertSame('Home', $shell->stack->current()->title);
    }

    public function testWithPopReturnsNewInstance(): void
    {
        $original = Shell::new()->withPush('Home')->withPush('Settings');
        $popped = $original->withPop();

        $this->assertNotSame($original, $popped);
        $this->assertSame(2, $original->stack->depth());
        $this->assertSame(1, $popped->stack->depth());
    }

    public function testWithPopOnEmptyStackIsNoOp(): void
    {
        $shell = Shell::new();
        $result = $shell->withPop();

        $this->assertSame(0, $result->stack->depth());
    }

    public function testWithPopOnSingleItemEmptiesStack(): void
    {
        $shell = Shell::new()->withPush('Only');
        $result = $shell->withPop();

        // Pop on single root item removes it, leaving empty stack
        $this->assertSame(0, $result->stack->depth());
    }

    // ─── withPopTo() ─────────────────────────────────────────────────────────

    public function testWithPopToTruncatesStack(): void
    {
        $shell = Shell::new()
            ->withPush('Home')
            ->withPush('Settings')
            ->withPush('Display')
            ->withPush('Resolution')
            ->withPopTo(1);

        $this->assertSame(2, $shell->stack->depth());
        $this->assertSame('Home', $shell->stack->items()[0]->title);
        $this->assertSame('Settings', $shell->stack->items()[1]->title);
    }

    public function testWithPopToReturnsNewInstance(): void
    {
        $original = Shell::new()->withPush('Home')->withPush('Settings');
        $truncated = $original->withPopTo(0);

        $this->assertNotSame($original, $truncated);
        $this->assertSame(2, $original->stack->depth());
        $this->assertSame(1, $truncated->stack->depth()); // index 0 = keep first item only
    }

    public function testWithPopToClampsOutOfRange(): void
    {
        $shell = Shell::new()->withPush('Home')->withPush('Settings');

        // popTo(99) clamps to current depth
        $result = $shell->withPopTo(99);
        $this->assertSame(2, $result->stack->depth());
    }

    public function testWithPopToNegativeIndexThrows(): void
    {
        $shell = Shell::new()->withPush('Home')->withPush('Settings');

        // NS-03: withPopTo inherits popTo's boundary refusal (disclosed
        // flip of the old clamp-to-empty pin).
        $this->expectException(\InvalidArgumentException::class);
        $shell->withPopTo(-5);
    }

    // ─── renderBreadcrumb() ───────────────────────────────────────────────────

    public function testRenderBreadcrumbEmptyStack(): void
    {
        $shell = Shell::new();
        $this->assertSame('', $shell->renderBreadcrumb());
    }

    public function testRenderBreadcrumbSingleItem(): void
    {
        $shell = Shell::new()->withPush('Home');
        $this->assertSame('Home', $shell->renderBreadcrumb());
    }

    public function testRenderBreadcrumbMultipleItems(): void
    {
        $shell = Shell::new()
            ->withPush('Home')
            ->withPush('Settings')
            ->withPush('Display');

        $this->assertSame('Home › Settings › Display', $shell->renderBreadcrumb());
    }

    public function testRenderBreadcrumbWithCustomSeparator(): void
    {
        $bc = (new Breadcrumb())->setSeparator(' > ');
        $shell = Shell::new($bc)
            ->withPush('Home')
            ->withPush('Settings');

        $this->assertSame('Home > Settings', $shell->renderBreadcrumb());
    }

    public function testRenderBreadcrumbWithTruncation(): void
    {
        $bc = (new Breadcrumb())->setMaxWidth(20);
        $shell = Shell::new($bc)
            ->withPush('Very Long Root Item')
            ->withPush('Medium Parent')
            ->withPush('Current Page');

        $rendered = $shell->renderBreadcrumb();
        $this->assertLessThanOrEqual(20, \SugarCraft\Core\Util\Width::string($rendered));
    }

    // ─── pushDirectory() ─────────────────────────────────────────────────────

    public function testPushDirectoryWithLeadingSlash(): void
    {
        $shell = Shell::new()->pushDirectory('/home/user/projects');

        $this->assertSame(3, $shell->stack->depth());
        $this->assertSame('home', $shell->stack->items()[0]->title);
        $this->assertSame('/home', $shell->stack->items()[0]->data);
        $this->assertSame('user', $shell->stack->items()[1]->title);
        $this->assertSame('/home/user', $shell->stack->items()[1]->data);
        $this->assertSame('projects', $shell->stack->items()[2]->title);
        $this->assertSame('/home/user/projects', $shell->stack->items()[2]->data);
    }

    public function testPushDirectoryWithoutLeadingSlash(): void
    {
        $shell = Shell::new()->pushDirectory('home/user/projects');

        $this->assertSame(3, $shell->stack->depth());
        $this->assertSame('home', $shell->stack->items()[0]->title);
        $this->assertSame('/home', $shell->stack->items()[0]->data);
    }

    public function testPushDirectoryEmpty(): void
    {
        $shell = Shell::new()->pushDirectory('');
        $this->assertSame(0, $shell->stack->depth());
    }

    public function testPushDirectorySingleSlash(): void
    {
        $shell = Shell::new()->pushDirectory('/');
        $this->assertSame(0, $shell->stack->depth());
    }

    public function testPushDirectoryReturnsNewInstance(): void
    {
        $original = Shell::new();
        $pushed = $original->pushDirectory('/home');

        $this->assertNotSame($original, $pushed);
        $this->assertSame(0, $original->stack->depth());
        $this->assertSame(1, $pushed->stack->depth());
    }

    public function testPushDirectoryChainingWithWithPush(): void
    {
        $shell = Shell::new()
            ->pushDirectory('/home/user')
            ->withPush('projects');

        $this->assertSame(3, $shell->stack->depth());
        $this->assertSame('user', $shell->stack->items()[1]->title);
        $this->assertSame('projects', $shell->stack->current()->title);
    }

    public function testPushDirectoryDoesNotDecodeSegments(): void
    {
        // pushDirectory does NOT URL-decode; segments are kept as-is
        $shell = Shell::new()->pushDirectory('/My%20Documents/Work%20Files');

        $this->assertSame(2, $shell->stack->depth());
        $this->assertSame('My%20Documents', $shell->stack->items()[0]->title);
        $this->assertSame('Work%20Files', $shell->stack->items()[1]->title);
    }

    // ─── Immutability ─────────────────────────────────────────────────────────

    public function testWithPushPreservesOriginal(): void
    {
        $original = Shell::new()->withPush('Home');
        $modified = $original->withPush('Settings');

        $this->assertSame(1, $original->stack->depth());
        $this->assertSame(2, $modified->stack->depth());
    }

    public function testWithPopPreservesOriginal(): void
    {
        $original = Shell::new()->withPush('Home')->withPush('Settings');
        $modified = $original->withPop();

        $this->assertSame(2, $original->stack->depth());
        $this->assertSame(1, $modified->stack->depth());
    }

    public function testWithPopToPreservesOriginal(): void
    {
        $original = Shell::new()->withPush('Home')->withPush('Settings')->withPush('Display');
        $modified = $original->withPopTo(1);

        $this->assertSame(3, $original->stack->depth());
        $this->assertSame(2, $modified->stack->depth());
    }

    public function testPushDirectoryPreservesOriginal(): void
    {
        $original = Shell::new();
        $modified = $original->pushDirectory('/home');

        $this->assertSame(0, $original->stack->depth());
        $this->assertSame(1, $modified->stack->depth());
    }

    // ─── Honest immutability: owned Breadcrumb copies + stack side door ─────

    public function testDerivedShellsGetTheirOwnBreadcrumb(): void
    {
        $base = Shell::new();
        $derived = $base->withPush('Anywhere')->withPush('Deeper');

        // NS-02 closed the shared-mutable side-door: each derived Shell
        // carries its OWN copy, so configuration cannot leak sideways
        // (deliberate pin flip — the old shared-identity behavior was the
        // hazard: a proven cross-Shell separator bleed).
        $this->assertNotSame($base->breadcrumb, $derived->breadcrumb);

        $derived->breadcrumb->setSeparator(' | ');
        $this->assertSame('Anywhere | Deeper', $derived->renderBreadcrumb());
        $this->assertSame('', $base->renderBreadcrumb(), 'ancestor unaffected');

        $base2 = Shell::new()->withPush('Home');
        $deep  = $base2->withPush('Settings');
        $deep->breadcrumb->setMaxWidth(4);
        $this->assertSame('Home', $base2->renderBreadcrumb(), 'ancestor breadcrumb carries no cap');
        // A later derivation from the ancestor copies the UNCONFIGURED bar:
        $wide = $base2->withPush('Also')->withPush('More');
        $this->assertSame('Home › Also › More', $wide->renderBreadcrumb(), 'cap never leaked sideways');
    }

    public function testWithBreadcrumbHonoursDeliberateSharing(): void
    {
        // The opt-in door stays honest: an explicitly passed bar IS shared.
        $shared = new Breadcrumb();
        $shell  = Shell::new()->withPush('Home')->withBreadcrumb($shared);
        $this->assertSame($shared, $shell->breadcrumb);

        $shared->setSeparator(' / ');
        $this->assertSame('Home', $shell->renderBreadcrumb());
        $fork = $shell->withPush('Deeper');
        $this->assertSame($shared, $shell->breadcrumb);
        $this->assertNotSame($shell->breadcrumb, $fork->breadcrumb, 'with* copies again');
    }

    public function testPublicStackPropertyIsALiveSideDoor(): void
    {
        // readonly protects the property slot, not mutation through the object.
        // Pinned so the documented escape hatch cannot silently become a copy.
        $shell = Shell::new();
        $shell->stack->push('ThroughTheDoor');

        $this->assertSame(1, $shell->stack->depth());
        $this->assertSame('ThroughTheDoor', $shell->stack->current()?->title);
    }

    public function testPushDirectoryKeepsNumericZeroSegment(): void
    {
        // array_filter() without a callback used to drop '0' segments — '/0/1'
        // must yield two crumbs, not one.
        $shell = Shell::new()->pushDirectory('/0/1');

        $this->assertSame(
            ['0', '1'],
            array_map(static fn ($i): string => $i->title, $shell->stack->items())
        );
        $this->assertSame('/0', $shell->stack->items()[0]->data);
    }

    public function testPushDirectoryNormalizesDotsLexically(): void
    {
        $shell = Shell::new()->pushDirectory('/a/b/../c/.');

        $this->assertSame(
            ['a', 'c'],
            array_map(static fn ($i): string => $i->title, $shell->stack->items())
        );
        $this->assertSame('/a', $shell->stack->items()[0]->data);
        $this->assertSame('/a/c', $shell->stack->items()[1]->data);
    }

    public function testPushDirectoryClimbsPastCallStartAreNoOps(): void
    {
        // '..' only cancels segments pushed by the same call — the pre-existing
        // stack is never unwound.
        $before = Shell::new()->pushDirectory('/keep');
        $shell = $before->pushDirectory('/../etc');

        $this->assertSame(['keep', 'etc'], array_map(static fn ($i): string => $i->title, $shell->stack->items()));
        $this->assertSame('/etc', $shell->stack->items()[1]->data);

        $cancel = Shell::new()->pushDirectory('/a/b/..');
        $this->assertSame(['a'], array_map(static fn ($i): string => $i->title, $cancel->stack->items()));
    }
}
