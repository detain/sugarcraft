<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Screen;
use SugarCraft\Core\ScreenStack;
use SugarCraft\Core\SubscriptionCapable;
use PHPUnit\Framework\TestCase;

final class DummyScreenModel implements Model
{
    use SubscriptionCapable;

    public function __construct(public readonly string $id)
    {
    }

    public function init(): ?\Closure
    {
        return null;
    }
    public function update(Msg $msg): array
    {
        return [$this, null];
    }
    public function view(): string
    {
        return "screen: {$this->id}";
    }
}

final class ScreenStackTest extends TestCase
{
    public function testEmptyStackIsEmpty(): void
    {
        $stack = new ScreenStack();
        $this->assertTrue($stack->isEmpty());
        $this->assertSame(0, $stack->count());
        $this->assertSame([], $stack->breadcrumb());
    }

    public function testCurrentThrowsOnEmptyStack(): void
    {
        $stack = new ScreenStack();
        $this->expectException(\RuntimeException::class);
        $stack->current();
    }

    public function testPushAddsScreenAndReturnsNewStack(): void
    {
        $screen = new Screen(
            new DummyScreenModel('A'),
            title: 'Screen A',
            onEnter: static function (): void { /* lifecycle handled by caller */
            },
        );

        $stack = (new ScreenStack())->push($screen);

        $this->assertFalse($stack->isEmpty());
        $this->assertSame(1, $stack->count());
        $this->assertSame($screen, $stack->current());
    }

    public function testBreadcrumbReturnsTitlesInOrder(): void
    {
        $stack = (new ScreenStack())
            ->push(new Screen(new DummyScreenModel('A'), title: 'First'))
            ->push(new Screen(new DummyScreenModel('B'), title: 'Second'))
            ->push(new Screen(new DummyScreenModel('C'), title: 'Third'));

        $this->assertSame(['First', 'Second', 'Third'], $stack->breadcrumb());
    }

    public function testBreadcrumbSkipsNullTitles(): void
    {
        $stack = (new ScreenStack())
            ->push(new Screen(new DummyScreenModel('A'), title: 'First'))
            ->push(new Screen(new DummyScreenModel('B')))  // no title
            ->push(new Screen(new DummyScreenModel('C'), title: 'Third'));

        $this->assertSame(['First', 'Third'], $stack->breadcrumb());
    }

    public function testPopReturnsNewStackWithoutFiringOnExit(): void
    {
        $exited = false;
        $screen = new Screen(
            new DummyScreenModel('A'),
            onExit: static function () use (&$exited): void {
                $exited = true;
            },
        );

        $stack = (new ScreenStack())->push($screen);
        $popped = $stack->pop();

        // onExit is NOT fired by ScreenStack - caller handles lifecycle.
        $this->assertFalse($exited);
        $this->assertNotSame($stack, $popped);
        $this->assertTrue($popped->isEmpty());
        // Original stack is unchanged (immutable).
        $this->assertFalse($stack->isEmpty());
    }

    public function testPopOnEmptyStackReturnsSelf(): void
    {
        $stack = new ScreenStack();
        $popped = $stack->pop();
        $this->assertSame($stack, $popped);
    }

    public function testMultiplePushPop(): void
    {
        $stack = (new ScreenStack())
            ->push(new Screen(new DummyScreenModel('A'), title: 'A'))
            ->push(new Screen(new DummyScreenModel('B'), title: 'B'))
            ->push(new Screen(new DummyScreenModel('C'), title: 'C'));

        $this->assertSame('C', $stack->current()->model->id);

        $stack = $stack->pop(); // pop C
        $this->assertSame('B', $stack->current()->model->id);

        $stack = $stack->pop(); // pop B
        $this->assertSame('A', $stack->current()->model->id);

        $stack = $stack->pop(); // pop A
        $this->assertTrue($stack->isEmpty());
    }

    public function testScreenModelAccess(): void
    {
        $model = new DummyScreenModel('test');
        $screen = new Screen($model, title: 'Test Screen');

        $this->assertSame($model, $screen->model);
        $this->assertSame('Test Screen', $screen->title);
    }
}
