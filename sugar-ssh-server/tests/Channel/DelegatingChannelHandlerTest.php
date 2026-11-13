<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Tests\Channel;

use PHPUnit\Framework\TestCase;
use SugarCraft\SshServer\Channel\ChannelHandler;
use SugarCraft\SshServer\Channel\DelegatingChannelHandler;
use SugarCraft\SshServer\Channel\DefaultChannelHandler;
use SugarCraft\SshServer\Channel\Msg\BreakMsg;
use SugarCraft\SshServer\Channel\Msg\EnvMsg;
use SugarCraft\SshServer\Channel\Msg\ExecMsg;
use SugarCraft\SshServer\Channel\Msg\PtyReqMsg;
use SugarCraft\SshServer\Channel\Msg\ShellMsg;
use SugarCraft\SshServer\Channel\Msg\SignalMsg;
use SugarCraft\SshServer\Channel\Msg\WindowChangeMsg;
use SugarCraft\SshServer\Session;

/**
 * Pins for the partial-override door (audit 074): a child overrides
 * its verbs, everything else — dimensions included — forwards verbatim
 * to the delegate, and DefaultChannelHandler stays final.
 */
final class DelegatingChannelHandlerTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];

    private function session(): Session
    {
        return new Session(
            user: 'alice', clientHost: '127.0.0.1', clientPort: 1, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm-256color', cols: 80, rows: 24, tty: '/dev/pts/0',
            command: null, lang: 'C.UTF-8',
        );
    }

    private function recordingDelegate(): ChannelHandler
    {
        $calls = &$this->calls;

        return new class($calls) implements ChannelHandler {
            /** @param list<string> $calls */
            public function __construct(private array &$calls)
            {
            }

            public function cols(): int
            {
                $this->calls[] = 'cols';
                return 132;
            }

            public function rows(): int
            {
                $this->calls[] = 'rows';
                return 47;
            }

            public function handlePtyReq(PtyReqMsg $msg, Session $session): void
            {
                $this->calls[] = 'pty-req';
            }

            public function handleWindowChange(WindowChangeMsg $msg, Session $session): void
            {
                $this->calls[] = 'window-change';
            }

            public function handleShell(ShellMsg $msg, Session $session): void
            {
                $this->calls[] = 'shell';
            }

            public function handleExec(ExecMsg $msg, Session $session): void
            {
                $this->calls[] = 'exec';
            }

            public function handleSignal(SignalMsg $msg, Session $session): void
            {
                $this->calls[] = 'signal';
            }

            public function handleEnv(EnvMsg $msg, Session $session): void
            {
                $this->calls[] = 'env';
            }

            public function handleBreak(BreakMsg $msg, Session $session): void
            {
                $this->calls[] = 'break';
            }
        };
    }

    public function testADelegateOnlyOverridesItsOwnVerbAndForwardsTheRest(): void
    {
        $child = new class($this->recordingDelegate()) extends DelegatingChannelHandler {
            /** @var list<string> */
            public array $own = [];

            public function handleShell(ShellMsg $msg, Session $session): void
            {
                $this->own[] = 'shell';
                // Deliberately NOT calling parent: the override replaces
                // the forwarding for this verb.
            }
        };

        $session = $this->session();
        $this->assertSame(132, $child->cols());
        $this->assertSame(47, $child->rows());
        $child->handlePtyReq(new PtyReqMsg(wantPty: true), $session);
        $child->handleWindowChange(new WindowChangeMsg(cols: 1, rows: 1), $session);
        $child->handleShell(new ShellMsg(wantShell: true), $session);
        $child->handleExec(new ExecMsg(command: 'ls'), $session);
        $child->handleSignal(new SignalMsg(signalName: 'WINCH'), $session);
        $child->handleEnv(new EnvMsg(name: 'LANG', value: 'C'), $session);
        $child->handleBreak(new BreakMsg(), $session);

        $this->assertSame(['shell'], $child->own, 'the overridden verb ran on the child');
        $this->assertSame(
            ['cols', 'rows', 'pty-req', 'window-change', 'exec', 'signal', 'env', 'break'],
            $this->calls,
            'every other member forwarded; the overridden verb never reached the delegate',
        );
    }

    public function testTheBaseCanWrapTheRealDefaultHandlerWithoutRewiring(): void
    {
        // The zero-rewrite promise: DefaultChannelHandler stays final,
        // and decorating it needs no knowledge of its constructor.
        $delegate = new DefaultChannelHandler();
        $child    = new class($delegate) extends DelegatingChannelHandler {
            /** @var list<string> */
            public array $seen = [];

            public function handleExec(ExecMsg $msg, Session $session): void
            {
                $this->seen[] = $msg->command;
                // Intercepted: the default's exec policy never sees it.
            }
        };

        $session = $this->session();
        $child->handlePtyReq(new PtyReqMsg(wantPty: true, term: 'xterm', cols: 100, rows: 30), $session);
        $this->assertSame([100, 30], [$child->cols(), $child->rows()], 'dimensions travel with the delegate that owns the session');

        $child->handleWindowChange(new WindowChangeMsg(cols: 120, rows: 40), $session);
        $this->assertSame([120, 40], [$child->cols(), $child->rows()], 'the forwarded verb mutated the real default handler');

        $child->handleExec(new ExecMsg(command: 'echo hi'), $session);
        $this->assertSame(['echo hi'], $child->seen);
    }

    public function testDefaultChannelHandlerRemainsFinal(): void
    {
        // The finding's rejected alternative (de-final) would widen the
        // surface SSHS-21 guarded; this door exists so nobody needs it.
        $this->assertTrue((new \ReflectionClass(DefaultChannelHandler::class))->isFinal());
        $this->assertTrue((new \ReflectionClass(DelegatingChannelHandler::class))->isAbstract());
    }
}
