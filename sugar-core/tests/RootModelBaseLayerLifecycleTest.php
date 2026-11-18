<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\ScreenStackPushedMsg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\RootModelWithScreenStack;
use SugarCraft\Core\Screen;
use SugarCraft\Core\Subscriptions;

/**
 * A model whose init() is observable and whose subscriptions are declared:
 * the two lifecycle hooks Program must run for the base layer and for every
 * pushed screen, not only for a root that owns the stack.
 */
final class LifecycleProbeModel implements Model
{
    /** @param \ArrayObject<string, int> $probe */
    public function __construct(
        private readonly \ArrayObject $probe,
        private readonly ?\Closure $startCmd = null,
        private readonly ?string $tickId = null,
    ) {
    }

    public function init(): ?\Closure
    {
        $this->probe['init'] = ($this->probe['init'] ?? 0) + 1;
        return $this->startCmd;
    }

    public function update(Msg $msg): array
    {
        return [$this, null];
    }

    public function view(): string
    {
        return 'probe';
    }

    public function subscriptions(): ?Subscriptions
    {
        if ($this->tickId === null) {
            return null;
        }
        return (new Subscriptions())->withTick(
            $this->tickId,
            1.0,
            static fn (): Msg => new QuitMsg(),
        );
    }
}

final class RootModelBaseLayerLifecycleTest extends TestCase
{
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

    private function options($in, $out, StreamSelectLoop $loop): ProgramOptions
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

    /** @return list<string> ids of the subscriptions Program currently wants */
    private function wantedIds(Program $program): array
    {
        $method = new \ReflectionMethod(Program::class, 'wantedSubscriptions');
        $wanted = $method->invoke($program);
        $this->assertNotNull($wanted);
        return array_map(static fn ($sub): string => $sub->id, $wanted->all());
    }

    public function testRootInitReturnsTheBaseLayersStartupCmdWhenNoScreenIsPushed(): void
    {
        $startCmd = static fn (): null => null;
        $probe = new \ArrayObject();
        $root = new RootModelWithScreenStack(currentModel: new LifecycleProbeModel($probe, $startCmd));

        $this->assertSame($startCmd, $root->init());
    }

    public function testRootInitIsNullWhileAScreenIsOnTop(): void
    {
        $probe = new \ArrayObject();
        $root = (new RootModelWithScreenStack(currentModel: new LifecycleProbeModel($probe, static fn (): null => null)))
            ->update(new ScreenStackPushedMsg(new Screen(new LifecycleProbeModel($probe), title: 'Top')))[0];

        $this->assertNull($root->init(), 'the pushed screen owns startup, not the base layer');
    }

    public function testAPushedScreensStartupCmdIsReturnedFromThePushUpdate(): void
    {
        $startCmd = static fn (): null => null;
        $probe = new \ArrayObject();
        $root = new RootModelWithScreenStack();

        [$next, $cmd] = $root->update(
            new ScreenStackPushedMsg(new Screen(new LifecycleProbeModel($probe, $startCmd), title: 'Top')),
        );

        $this->assertSame($startCmd, $cmd, 'the push must hand the screen\'s init Cmd to Program');
        $this->assertCount(1, $next->screens()->breadcrumb());
    }

    public function testProgramRunsTheBaseLayersInitWhenNoScreenIsPushed(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        $probe = new \ArrayObject();

        $program = new Program(
            new RootModelWithScreenStack(currentModel: new LifecycleProbeModel($probe)),
            $this->options($in, $out, $loop),
        );
        $loop->addTimer(0.05, static fn () => $program->quit());
        $loop->addTimer(2.0, static fn () => $loop->stop());
        $program->run();

        $this->assertSame(1, $probe['init'] ?? 0, 'Program must run the base layer init() once at startup');

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testProgramStartsTheBaseLayersSubscriptionsWhenNoScreenIsPushed(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $program = new Program(
            new RootModelWithScreenStack(currentModel: new LifecycleProbeModel(new \ArrayObject(), tickId: 'base-tick')),
            $this->options($in, $out, $loop),
        );

        $this->assertSame(['base-tick'], $this->wantedIds($program));

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testAPushedScreensSubscriptionsStartAtThePushAndStopAtThePop(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        $probe = new \ArrayObject();

        $program = new Program(
            new RootModelWithScreenStack(currentModel: new LifecycleProbeModel($probe, tickId: 'base-tick')),
            $this->options($in, $out, $loop),
        );
        $activeProp = new \ReflectionProperty(Program::class, 'activeSubscriptions');
        $dispatch = new \ReflectionMethod(Program::class, 'dispatch');

        $dispatch->invoke($program, new ScreenStackPushedMsg(
            new Screen(new LifecycleProbeModel($probe, tickId: 'screen-tick'), title: 'Top'),
        ));
        $this->assertSame(['screen-tick'], array_keys($activeProp->getValue($program)),
            'the pushed screen\'s subscription must start at the push, not on the next Msg');

        $dispatch->invoke($program, new \SugarCraft\Core\Msg\ScreenStackPoppedMsg());
        $this->assertSame(['base-tick'], array_keys($activeProp->getValue($program)),
            'the popped screen\'s subscription must be cancelled at the pop, and the base layer\'s restarted');

        fclose($writer);
        fclose($in);
        fclose($out);
    }
}
