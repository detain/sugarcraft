<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Middleware\Subsystem;

use SugarCraft\Wish\Context;
use SugarCraft\Wish\Session;

/**
 * QUARANTINED EXAMPLE — demo wiring only, never for production use.
 *
 * Stub demonstrating {@see SubsystemHandler} wiring for the SFTP
 * subsystem.
 *
 * This is NOT a real SFTP implementation — it shows how a handler is
 * registered and invoked, and its {@see wasCalled()} accessor exists
 * purely so {@see \SugarCraft\Wish\Tests\Middleware\SubsystemTest} can
 * observe dispatch. A production implementation would speak
 * the SFTP protocol over the session's stdin/stdout after this
 * handler is called; swapping one in means implementing
 * SubsystemHandler yourself, not extending this class.
 *
 * Wiring example:
 * ```php
 * $subsystem = new Subsystem();
 * $subsystem->register('sftp', new SftpStub());
 *
 * Server::new()
 *     ->use($subsystem)
 *     ->use(new Spawn(fn (Session $s) => ['cmd' => ['/bin/bash', '-l']]))
 *     ->serve();
 * ```
 *
 * When a client sends `subsystem sftp`, the Subsystem middleware
 * extracts "sftp", finds this handler, and calls its `handle()`.
 */
final class SftpStub implements SubsystemHandler
{
    private bool $called = false;

    public function handle(Context $ctx, Session $session): void
    {
        $this->called = true;
    }

    public function wasCalled(): bool
    {
        return $this->called;
    }
}
