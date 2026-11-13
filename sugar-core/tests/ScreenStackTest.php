<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use React\EventLoop\StreamSelectLoop;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\ScreenStackPushedMsg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\RootModelWithScreenStack;
use SugarCraft\Core\Screen;
use SugarCraft\Core\ScreenStack;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Core\View;
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

/**
 * A screen that renders a rich View — the sugar-core-008 shape the old
 * RootModelWithScreenStack::view() (string) cast fatal'd every frame.
 */
final class ViewScreenModel implements Model
{
    use SubscriptionCapable;

    public function init(): ?\Closure
    {
        return null;
    }
    public function update(Msg $msg): array
    {
        return [$this, null];
    }
    public function view(): string|View
    {
        return new View(body: 'rich-view-body-008', windowTitle: 'rich-title-008');
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

    // ─── sugar-core-008 (sc-11): View-returning screens render ───────────

    public function testAPushedViewScreenDelegatesUncast(): void
    {
        $stack = (new ScreenStack())->push(new Screen(new ViewScreenModel(), title: 'V'));
        $root  = new RootModelWithScreenStack(screens: $stack);

        // The old (string) cast here threw: View has no __toString.
        $rendered = $root->view();

        $this->assertInstanceOf(View::class, $rendered);
        $this->assertSame('rich-view-body-008', $rendered->body);
        $this->assertSame('rich-title-008', $rendered->windowTitle);
    }

    public function testABaseModelViewStillDelegatesUncast(): void
    {
        $root = new RootModelWithScreenStack(currentModel: new ViewScreenModel());

        $rendered = $root->view();

        $this->assertInstanceOf(View::class, $rendered, 'the currentModel leg carried the same (string) trap');
        $this->assertSame('rich-view-body-008', $rendered->body);
    }

    public function testTheRenderPathPaintsAPushedViewScreenWithoutFatal(): void
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($sockets);
        [$in, $writer] = $sockets;
        $out = fopen('php://memory', 'w+');
        $this->assertNotFalse($out);
        $loop = new StreamSelectLoop();

        $program = new Program(
            new RootModelWithScreenStack(),
            new ProgramOptions(
                useAltScreen: false,
                catchInterrupts: false,
                hideCursor: false,
                input: $in,
                output: $out,
                loop: $loop,
            ),
        );

        $dispatch = new \ReflectionMethod(Program::class, 'dispatch');
        $dispatch->invoke($program, new ScreenStackPushedMsg(
            new Screen(new ViewScreenModel(), title: 'V')
        ));

        // One deterministic frame — no loop entry needed. Pre-cure this
        // died in view()'s cast before a single byte reached the wire.
        (new \ReflectionMethod(Program::class, 'renderFrame'))->invoke($program);

        rewind($out);
        $bytes = stream_get_contents($out);
        $this->assertStringContainsString('rich-view-body-008', $bytes, 'the View body must reach the terminal');
        $this->assertStringContainsString('rich-title-008', $bytes, 'View side effects ride the same frame');

        fclose($writer);
        fclose($in);
        fclose($out);
    }
}
