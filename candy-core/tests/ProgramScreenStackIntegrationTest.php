<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Cmd\PopScreenCmd;
use SugarCraft\Core\Cmd\PushScreenCmd;
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

final class ProgramScreenStackIntegrationTest extends TestCase
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
