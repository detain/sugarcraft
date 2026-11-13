<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Channel;

use SugarCraft\SshServer\Channel\Msg\BreakMsg;
use SugarCraft\SshServer\Channel\Msg\EnvMsg;
use SugarCraft\SshServer\Channel\Msg\ExecMsg;
use SugarCraft\SshServer\Channel\Msg\PtyReqMsg;
use SugarCraft\SshServer\Channel\Msg\ShellMsg;
use SugarCraft\SshServer\Channel\Msg\SignalMsg;
use SugarCraft\SshServer\Channel\Msg\WindowChangeMsg;
use SugarCraft\SshServer\Session;

/**
 * Partial-override base for {@see ChannelHandler}: forward everything
 * to a delegate, then override only the verbs you actually care about.
 *
 * The customization door for audit 074. {@see DefaultChannelHandler}
 * is final — deliberately, it guards a mutation-prone surface — and
 * implementing all nine interface members to change one of them is the
 * class tax this base exists to cancel. A child declares only its
 * verbs:
 *
 * ```php
 * final class LoggingHandler extends DelegatingChannelHandler
 * {
 *     public function handleShell(ShellMsg $msg, Session $session): void
 *     {
 *         \error_log('shell from ' . $session->user);
 *         parent::handleShell($msg, $session); // forwards to the delegate
 *     }
 * }
 *
 * $handler = new LoggingHandler(new DefaultChannelHandler(/* ... *\/));
 * ```
 *
 * Every non-overridden method forwards verbatim, `cols()`/`rows()`
 * included — the transport sizes windows through the interface, so
 * dimensions must travel with the delegate that owns the child, not
 * with the decorator.
 *
 * Wrapping composes: a delegate may itself be another
 * DelegatingChannelHandler. Keep the outermost instance the object
 * whose verbs the caller means to run.
 */
abstract class DelegatingChannelHandler implements ChannelHandler
{
    public function __construct(protected readonly ChannelHandler $delegate)
    {
    }

    public function cols(): int
    {
        return $this->delegate->cols();
    }

    public function rows(): int
    {
        return $this->delegate->rows();
    }

    public function handlePtyReq(PtyReqMsg $msg, Session $session): void
    {
        $this->delegate->handlePtyReq($msg, $session);
    }

    public function handleWindowChange(WindowChangeMsg $msg, Session $session): void
    {
        $this->delegate->handleWindowChange($msg, $session);
    }

    public function handleShell(ShellMsg $msg, Session $session): void
    {
        $this->delegate->handleShell($msg, $session);
    }

    public function handleExec(ExecMsg $msg, Session $session): void
    {
        $this->delegate->handleExec($msg, $session);
    }

    public function handleSignal(SignalMsg $msg, Session $session): void
    {
        $this->delegate->handleSignal($msg, $session);
    }

    public function handleEnv(EnvMsg $msg, Session $session): void
    {
        $this->delegate->handleEnv($msg, $session);
    }

    public function handleBreak(BreakMsg $msg, Session $session): void
    {
        $this->delegate->handleBreak($msg, $session);
    }
}
