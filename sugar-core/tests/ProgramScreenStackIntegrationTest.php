<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Cmd\PopScreenCmd;
use SugarCraft\Core\Cmd\PushScreenCmd;
use SugarCraft\Core\Exception\TerminalException;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\ScreenStackPushedMsg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\RootModelWithScreenStack;
use SugarCraft\Core\Screen;
use SugarCraft\Core\ScreenStack;
use SugarCraft\Core\ScreenStackCapable;
use SugarCraft\Core\SubscriptionCapable;
use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;

final class ScreenStackRecordingModel implements Model, ScreenStackCapable
{
    use SubscriptionCapable;

    /** @var list<Msg> */
    public array $log = [];

    public function __construct(
        public ScreenStack $screens = new ScreenStack(),
        public readonly int $quitAfter = PHP_INT_MAX,
        public readonly string $id = 'root',
    ) {
    }

    public function screens(): ScreenStack
    {
        return $this->screens;
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        $next = clone $this;
        $next->log = [...$this->log, $msg];

        if ($msg instanceof KeyMsg && $msg->rune === 'q') {
            return [$next, Cmd::quit()];
        }

        if ($msg instanceof QuitMsg || count($next->log) >= $this->quitAfter) {
            return [$next, Cmd::quit()];
        }

        return [$next, null];
    }

    public function view(): string
    {
        $active = $this->screens->isEmpty() ? null : $this->screens->current()->model;
        $id = $active?->id ?? 'none';
        $crumb = implode(' > ', $this->screens->breadcrumb());
        return "active: {$id} | breadcrumb: [{$crumb}]";
    }
}

/**
 * A child screen that folds '+' keystrokes into a counter — the exact
 * shape lib-reviews-1102 C6 proved was silently discarded.
 */
final class CountingScreenModel implements Model
{
    use SubscriptionCapable;

    public function __construct(public readonly int $n = 0) {}

    public function init(): ?\Closure { return null; }

    public function update(Msg $msg): array
    {
        if ($msg instanceof KeyMsg && $msg->rune === '+') {
            return [new self($this->n + 1), null];
        }
        return [$this, null];
    }

    public function view(): string { return "n={$this->n}"; }
}

/** Root with a writable public stack and NO withScreens() — property route. */
final class PropertyWriteRoot implements Model, ScreenStackCapable
{
    use SubscriptionCapable;

    public function __construct(public ScreenStack $screens = new ScreenStack()) {}

    public function screens(): ScreenStack { return $this->screens; }

    public function init(): ?\Closure { return null; }

    public function update(Msg $msg): array
    {
        if ($msg instanceof ScreenStackPushedMsg) {
            return [new self($this->screens->push($msg->screen)), null];
        }
        return [$this, null];
    }

    public function view(): string
    {
        return $this->screens->isEmpty() ? 'base' : (string) $this->screens->current()->model->view();
    }
}

/** Root that refuses both write channels — the fail-loud contract. */
final class UnwritableRoot implements Model, ScreenStackCapable
{
    use SubscriptionCapable;

    public function __construct(private readonly ScreenStack $screens = new ScreenStack()) {}

    public function screens(): ScreenStack { return $this->screens; }

    public function init(): ?\Closure { return null; }

    public function update(Msg $msg): array
    {
        if ($msg instanceof ScreenStackPushedMsg) {
            return [new self($this->screens->push($msg->screen)), null];
        }
        return [$this, null];
    }

    public function view(): string
    {
        return $this->screens->isEmpty() ? 'base' : (string) $this->screens->current()->model->view();
    }
}

/** Root with NO stack capability: stack msgs here used to vanish (sugar-core-034). */
final class PlainNoStackRoot implements Model
{
    use SubscriptionCapable;

    /** @var list<Msg> */
    public array $log = [];

    public function init(): ?\Closure { return null; }

    public function update(Msg $msg): array
    {
        $next = clone $this;
        $next->log = [...$this->log, $msg];
        return [$next, null];
    }

    public function view(): string { return 'plain'; }
}

final class ProgramScreenStackIntegrationTest extends TestCase
{
    /** Drive one dispatch synchronously without entering the loop. */
    private function sendThroughDispatch(Program $program, Msg $msg): void
    {
        $dispatch = new \ReflectionMethod(Program::class, 'dispatch');
        $dispatch->invoke($program, $msg);
    }

    private function dispatchTo(Program $program): Model
    {
        $prop = new \ReflectionProperty(Program::class, 'model');
        return $prop->getValue($program);
    }

    /**
     * C6 keystone (lib-reviews-1102): the ux-designer probe — push a
     * counting screen, feed it three keys, and the child's folded state
     * must be what the runtime keeps, not a frozen snapshot.
     */
    public function testAPushedScreenKeepsItsOwnUpdatedModelAcrossDispatches(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $root = new RootModelWithScreenStack();
        $program = new Program($root, $this->makeOptions($in, $out, $loop));

        $this->sendThroughDispatch($program, new ScreenStackPushedMsg(
            new Screen(new CountingScreenModel(), title: 'Count')
        ));

        $this->sendThroughDispatch($program, new KeyMsg(\SugarCraft\Core\KeyType::Char, '+'));
        $this->sendThroughDispatch($program, new KeyMsg(\SugarCraft\Core\KeyType::Char, '+'));
        $this->sendThroughDispatch($program, new KeyMsg(\SugarCraft\Core\KeyType::Char, '+'));

        $finalRoot = $this->dispatchTo($program);
        $this->assertInstanceOf(RootModelWithScreenStack::class, $finalRoot);
        $active = $finalRoot->screens()->current()->model;
        $this->assertInstanceOf(CountingScreenModel::class, $active);
        $this->assertSame(3, $active->n, 'pushed screen state must persist across updates (C6)');
        $this->assertSame('n=3', (string) $active->view());

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    /** The public-writable-property root route persists too. */
    public function testAPropertyStyleRootAlsoPersistsChildState(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $program = new Program(new PropertyWriteRoot(), $this->makeOptions($in, $out, $loop));
        $this->sendThroughDispatch($program, new ScreenStackPushedMsg(
            new Screen(new CountingScreenModel(), title: 'Count')
        ));
        $this->sendThroughDispatch($program, new KeyMsg(\SugarCraft\Core\KeyType::Char, '+'));
        $this->sendThroughDispatch($program, new KeyMsg(\SugarCraft\Core\KeyType::Char, '+'));

        $root = $this->dispatchTo($program);
        $this->assertInstanceOf(PropertyWriteRoot::class, $root);
        $this->assertSame(2, $root->screens()->current()->model->n);

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    /** A root with NO write channel fails loudly instead of dropping silently. */
    public function testARootWithoutAWriteChannelRefusesLoudly(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $program = new Program(new UnwritableRoot(), $this->makeOptions($in, $out, $loop));
        $this->sendThroughDispatch($program, new ScreenStackPushedMsg(
            new Screen(new CountingScreenModel(), title: 'Count')
        ));

        $this->expectException(\LogicException::class);
        $this->sendThroughDispatch($program, new KeyMsg(\SugarCraft\Core\KeyType::Char, '+'));

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    /** Screen/ScreenStack value-object copy semantics. */
    public function testReplaceCurrentModelPreservesTitleHooksAndLeavesLowerScreens(): void
    {
        $onEnter = static function (): void {};
        $stack = (new ScreenStack())
            ->push(new Screen(new CountingScreenModel(1), title: 'bottom'))
            ->push(new Screen(new CountingScreenModel(2), title: 'top', onEnter: $onEnter));

        $replaced = $stack->replaceCurrentModel(new CountingScreenModel(9));

        $this->assertNotSame($stack, $replaced, 'replacement must be a copy');
        $this->assertSame(2, $stack->current()->model->n, 'original stack untouched');
        $this->assertSame(9, $replaced->current()->model->n);
        $this->assertSame('top', $replaced->current()->title, 'title preserved');
        $this->assertSame($onEnter, $replaced->current()->onEnter, 'hooks preserved');
        $this->assertSame(1, $replaced->pop()->current()->model->n, 'lower screens untouched');
        $live = new ScreenStack();
        $this->assertSame(
            $live,
            $live->replaceCurrentModel(new CountingScreenModel(0)),
            'empty stack no-ops (same object)',
        );
    }

    /**
     * sugar-core-034 (RUN-9): a stack message reaching a NON-capable root must
     * surface as a named refusal on the ExceptionMsg channel, never vanish.
     */
    public function testAStackMsgToANonCapableRootRefusesByNamedException(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $program = new Program(new PlainNoStackRoot(), $this->makeOptions($in, $out, $loop));
        $this->sendThroughDispatch($program, new ScreenStackPushedMsg(
            new Screen(new CountingScreenModel(), title: 'Count')
        ));

        $root = $this->dispatchTo($program);
        $this->assertInstanceOf(PlainNoStackRoot::class, $root);
        $this->assertCount(1, $root->log, 'only the refusal reaches the root — the stack msg must not');
        $refusal = $root->log[0];
        $this->assertInstanceOf(\SugarCraft\Core\Msg\ExceptionMsg::class, $refusal);
        $this->assertInstanceOf(TerminalException::class, $refusal->exception);
        $this->assertStringContainsString(PlainNoStackRoot::class, $refusal->exception->getMessage());
        $this->assertStringContainsString(ScreenStackPushedMsg::class, $refusal->exception->getMessage());

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    /** The same named refusal covers pops. */
    public function testAPopMsgToANonCapableRootRefusesByNamedException(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $program = new Program(new PlainNoStackRoot(), $this->makeOptions($in, $out, $loop));
        $this->sendThroughDispatch($program, new \SugarCraft\Core\Msg\ScreenStackPoppedMsg());

        $root = $this->dispatchTo($program);
        $this->assertInstanceOf(PlainNoStackRoot::class, $root);
        $this->assertCount(1, $root->log);
        $this->assertInstanceOf(\SugarCraft\Core\Msg\ExceptionMsg::class, $root->log[0]);

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    /** @return array{0:resource, 1:resource, 2:resource} */
    private function pipes(): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($sockets);
        [$reader, $writer] = $sockets;
        $output = fopen('php://memory', 'w+');
        $this->assertNotFalse($output);
        return [$reader, $output, $writer];
    }

    private function makeOptions($in, $out, $loop): ProgramOptions
    {
        return new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            hideCursor: false,
            input: $in,
            output: $out,
            loop: $loop,
        );
    }

    public function testPushThreeScreensPopTwoVerifiesStateAndBreadcrumb(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $model = new RootModelWithScreenStack();

        $program = new Program($model, $this->makeOptions($in, $out, $loop));

        // Push three screens via direct dispatch (simulating what Program does internally)
        // This tests the ScreenStack type and RootModelWithScreenStack directly
        $model = $model->update(new ScreenStackPushedMsg(
            new Screen(new ScreenStackRecordingModel(id: 'screen-A'), title: 'Screen A')
        ))[0];
        $model = $model->update(new ScreenStackPushedMsg(
            new Screen(new ScreenStackRecordingModel(id: 'screen-B'), title: 'Screen B')
        ))[0];
        $model = $model->update(new ScreenStackPushedMsg(
            new Screen(new ScreenStackRecordingModel(id: 'screen-C'), title: 'Screen C')
        ))[0];
        // Pop twice
        $model = $model->update(new \SugarCraft\Core\Msg\ScreenStackPoppedMsg())[0];
        $model = $model->update(new \SugarCraft\Core\Msg\ScreenStackPoppedMsg())[0];

        // Verify state
        $this->assertSame(['screen-A', 'screen-B', 'screen-C'], $model->pushedIds);
        $this->assertSame(['screen-C', 'screen-B'], $model->poppedIds);
        $this->assertSame(['Screen A'], $model->screens->breadcrumb());
        $this->assertSame('screen-A', $model->screens->current()->model->id);

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testScreenStackCapableModelRoutesUpdateToActiveScreen(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        // Root model has an empty stack initially
        $root = new RootModelWithScreenStack();
        $program = new Program($root, $this->makeOptions($in, $out, $loop));

        // Push one screen
        $program->send(new ScreenStackPushedMsg(
            new Screen(new ScreenStackRecordingModel(id: 'inner-screen'), title: 'Inner')
        ));

        $loop->addTimer(0.05, static fn () => $program->quit());
        $loop->addTimer(2.0, static fn () => $loop->stop());

        $final = $program->run();

        // The active model in the screen should have received the startup msgs
        // (WindowSize, Env, ColorProfile) — those were dispatched before init.
        // Check that ScreenStackPushedMsg was received by root model.
        $this->assertSame(['inner-screen'], $final->pushedIds);
        $this->assertSame(['Inner'], $final->screens->breadcrumb());

        fclose($writer);
        fclose($in);
        fclose($out);
    }
}
