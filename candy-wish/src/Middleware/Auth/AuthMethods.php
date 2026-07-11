<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Middleware\Auth;

use SugarCraft\Wish\Context;
use SugarCraft\Wish\Middleware;
use SugarCraft\Wish\Session;

/**
 * Records an informational list of accepted authentication methods in
 * Context and echoes it to the client.
 *
 * **Informational only — this does NOT negotiate RFC 4252 auth.** Real
 * SSH authentication is a multi-round-trip exchange (the server
 * advertises methods, the client tries one, the server accepts or
 * replies with the remaining methods). By the time candy-wish
 * middleware run, that exchange has ALREADY completed inside the host
 * `sshd` (the ForceCommand deployment) — auth is done and this process
 * is the forced command. There is no live transport here on which to
 * offer or refuse methods, so this middleware cannot implement RFC 4252
 * semantics and must not be relied on as an auth gate. Its value is
 * purely descriptive:
 *
 *   1. Stores the method list in Context under the key
 *      `auth.methods` so later middleware (or logging) can record
 *      which methods the operator considers acceptable.
 *   2. Writes the method list to STDOUT as a human-readable banner
 *      line so an interactive client/user can see what's configured.
 *
 * The banner format is:
 *
 *     SSH_AUTH_METHODS publickey password keyboard-interactive
 *
 * It is written once per session and then `next` is called. Enforcement
 * of specific methods belongs to the host sshd config (the real trust
 * boundary) plus the credential-checking middleware ({@see \SugarCraft\Wish\Middleware\Auth},
 * {@see PasswordAuth}).
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
     * Read the informational auth-methods list back from a Context
     * (convenience for downstream middleware / logging). This is the
     * list the operator declared via the constructor — it is NOT proof
     * that any method actually authenticated the session.
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
