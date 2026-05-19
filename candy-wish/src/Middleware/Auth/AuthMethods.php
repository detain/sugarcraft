<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Middleware\Auth;

use SugarCraft\Wish\Context;
use SugarCraft\Wish\Middleware;
use SugarCraft\Wish\Session;

/**
 * Declares which authentication methods the server accepts and
 * records the active method in Context so downstream middleware can
 * inspect which one succeeded.
 *
 * SSH authentication is multi-round-trip: the server advertises
 * available methods, the client picks one and tries it, and the
 * server either accepts or rejects with a fresh list of remaining
 * methods. This middleware runs early in the chain before any
 * credential-checking middleware and:
 *
 *   1. Stores the method list in Context under the key
 *      `auth.methods` so any subsequent auth middleware can
 *      consult it.
 *   2. Writes the method list to STDOUT as an RFC 4252-style
 *      banner line so the SSH client knows what's available.
 *
 * The banner format is:
 *
 *     SSH_AUTH_METHODS publickey password keyboard-interactive
 *
 * It is written once per session and then `next` is called.
 */
final class AuthMethods implements Middleware
{
    /** @var list<string> */
    private array $methods;

    /** @var resource */
    private $stdout;

    private const CONTEXT_KEY = 'auth.methods';
    private const BANNER_PREFIX = 'SSH_AUTH_METHODS';

    /**
     * @param list<string>    $methods Allowed method names e.g. ['publickey', 'password', 'keyboard-interactive']
     * @param resource|null   $stdout
     */
    public function __construct(array $methods, $stdout = null)
    {
        $this->methods = $methods;
        if ($stdout === null) {
            $stream = fopen('php://stdout', 'w');
            if ($stream === false) {
                throw new \RuntimeException('cannot open php://stdout');
            }
            $this->stdout = $stream;
            return;
        }
        if (!is_resource($stdout)) {
            throw new \InvalidArgumentException('stdout must be a resource');
        }
        $this->stdout = $stdout;
    }

    public function handle(Context $ctx, Session $session, callable $next)
    {
        $derived = $ctx->withValue(self::CONTEXT_KEY, $this->methods);

        $banner = self::BANNER_PREFIX . ' ' . implode(' ', $this->methods) . "\n";
        fwrite($this->stdout, $banner);

        $next($derived, $session);
    }

    /**
     * Read the auth methods list from a Context (convenience for
     * downstream middleware).
     *
     * @return list<string>
     */
    public static function fromContext(Context $ctx): array
    {
        $v = $ctx->value(self::CONTEXT_KEY);
        if (!is_array($v)) {
            return [];
        }
        /** @var list<string> */
        return $v;
    }
}
