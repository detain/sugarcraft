<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Channel;

use SugarCraft\Wish\Channel\Msg\BreakMsg;
use SugarCraft\Wish\Channel\Msg\EnvMsg;
use SugarCraft\Wish\Channel\Msg\ExecMsg;
use SugarCraft\Wish\Channel\Msg\PtyReqMsg;
use SugarCraft\Wish\Channel\Msg\ShellMsg;
use SugarCraft\Wish\Channel\Msg\SignalMsg;
use SugarCraft\Wish\Channel\Msg\WindowChangeMsg;
use SugarCraft\Wish\Session;

/**
 * Interface for handling SSH channel-level messages.
 *
 * Implementations receive channel messages one at a time and react
 * appropriately — allocating a PTY, updating dimensions, starting a
 * shell, delivering signals, etc.
 *
 * The transport layer ({@see \SugarCraft\Wish\Transport\InProcessTransport})
 * dispatches channel messages to the handler instead of handling them
 * inline.
 */
interface ChannelHandler
{
    /**
     * Handle a PTY request message.
     */
    public function handlePtyReq(PtyReqMsg $msg, Session $session): void;

    /**
     * Handle a window-change message.
     */
    public function handleWindowChange(WindowChangeMsg $msg, Session $session): void;

    /**
     * Handle a shell request message.
     */
    public function handleShell(ShellMsg $msg, Session $session): void;

    /**
     * Handle an exec request message.
     */
    public function handleExec(ExecMsg $msg, Session $session): void;

    /**
     * Handle a signal message.
     */
    public function handleSignal(SignalMsg $msg, Session $session): void;

    /**
     * Handle an environment variable request message.
     */
    public function handleEnv(EnvMsg $msg, Session $session): void;

    /**
     * Handle a break request message.
     */
    public function handleBreak(BreakMsg $msg, Session $session): void;
}
