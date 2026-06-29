<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Middleware;

use SugarCraft\Wish\Context;
use SugarCraft\Wish\Middleware;
use SugarCraft\Wish\Middleware\Subsystem\SubsystemHandler;
use SugarCraft\Wish\Session;

/**
 * Middleware that dispatches SSH subsystem requests to a registered
 * {@see SubsystemHandler}.
 *
 * An SSH client requests a subsystem by sending `subsystem <name>`
 * as the original command. This middleware parses that prefix,
 * looks up the registered handler for `<name>`, invokes it, and
 * stops the middleware chain — subsystem handlers are terminal by
 * design.
 *
 * Non-subsystem requests pass through to `$next` unchanged.
 *
 * Example:
 * ```php
 * $subsystem = new Subsystem();
 * $subsystem->register('sftp', new SftpStub());
 *
 * Server::new()
 *     ->use(new Logger())
 *     ->use(new Auth(['alice', 'bob']))
 *     ->use($subsystem)
 *     ->use(new Spawn(fn (Session $s) => ['cmd' => ['/bin/bash', '-l']]))
 *     ->serve();
 * ```
 */
final class Subsystem implements Middleware
{
    /**
     * @var array<string, SubsystemHandler>
     */
    private array $handlers = [];

    /**
     * Register a handler for a named subsystem.
     *
     * @param string          $name    Subsystem name (e.g. "sftp")
     * @param SubsystemHandler $handler Handler to invoke
     */
    public function register(string $name, SubsystemHandler $handler): void
    {
        $this->handlers[$name] = $handler;
    }

    /**
     * Returns true when a handler is registered for the given name.
     */
    public function has(string $name): bool
    {
        return isset($this->handlers[$name]);
    }

    public function handle(Context $ctx, Session $session, callable $next)
    {
        $command = $session->command ?? '';

        if (!str_starts_with($command, 'subsystem ')) {
            $next($ctx, $session);
            return;
        }

        $name = strtok(trim(substr($command, 10)), " \t") ?: '';

        if ($name === '' || !isset($this->handlers[$name])) {
            $next($ctx, $session);
            return;
        }

        $this->handlers[$name]->handle($ctx, $session);
    }
}
