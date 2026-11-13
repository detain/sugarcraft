<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Middleware\Auth;

use SugarCraft\SshServer\Context;
use SugarCraft\SshServer\Middleware;
use SugarCraft\SshServer\Session;
use SugarCraft\SshServer\StreamHelper;

/**
 * Records an informational list of accepted authentication methods in
 * Context and echoes it to the client.
 *
 * **Informational only — this does NOT negotiate RFC 4252 auth.** Real
 * SSH authentication is a multi-round-trip exchange (the server
 * advertises methods, the client tries one, the server accepts or
 * replies with the remaining methods). By the time sugar-ssh-server
 * middleware run, that exchange has ALREADY completed inside the host
 * `sshd` (the ForceCommand deployment) — auth is done and this process
 * is the forced command. There is no live transport here on which to
 * offer or refuse methods, so this middleware cannot implement RFC 4252
 * semantics and must not be relied on as an auth gate. Its value is
 * purely descriptive:
 *
 *   1. Stores the method list in Context under the key
 *      `auth.methods` so later middleware (or logging) can record
 *      which methods the operator considers acceptable. This is the
 *      half that is always on.
 *   2. Optionally writes the list to STDOUT as a human-readable banner
 *      line, when constructed with `announce: true`:
 *
 *          SSH_AUTH_METHODS publickey password keyboard-interactive
 *
 * **The banner is opt-in, and off by default (audit MAJOR 7).** Under
 * `ForceCommand` stdout is the client's data channel (see the Logger
 * note on sinks), so a line printed here is not a protocol banner an
 * SSH client interprets — it is text the user sees before their shell
 * starts, on every session, in the middle of whatever the terminal is
 * doing. An sshd `Banner=` file or the MOTD is the surface that
 * belongs to that message. `AuthMethods` therefore records the
 * declaration and says nothing unless you ask it to; a stack that
 * wants the line for a deliberate banner-style program passes
 * `announce: true` and owns the placement.
 *
 * Enforcement of specific methods belongs to the host sshd config (the
 * real trust boundary) plus the credential-checking middleware
 * ({@see \SugarCraft\SshServer\Middleware\Auth}, {@see PasswordAuth}).
 */
final class AuthMethods implements Middleware
{
    /** @var list<string> */
    private array $methods;

    /** @var resource */
    private $stdout;

    private bool $announce;

    private const CONTEXT_KEY = 'auth.methods';
    private const BANNER_PREFIX = 'SSH_AUTH_METHODS';

    /**
     * @param list<string>  $methods  Allowed method names e.g. ['publickey', 'password', 'keyboard-interactive']
     * @param resource|null $stdout   Stream the opt-in banner is written to (default php://stdout)
     * @param bool          $announce Write the banner line? Off by default: under
     *                                ForceCommand that line lands in the user's
     *                                terminal, not in a protocol field.
     */
    public function __construct(array $methods, $stdout = null, bool $announce = false)
    {
        $this->methods  = $methods;
        $this->stdout   = StreamHelper::openOrValidate($stdout, 'php://stdout');
        $this->announce = $announce;
    }

    public function handle(Context $ctx, Session $session, callable $next): void
    {
        $derived = $ctx->withValue(self::CONTEXT_KEY, $this->methods);

        if ($this->announce) {
            $banner = self::BANNER_PREFIX . ' ' . implode(' ', $this->methods) . "\n";
            \fwrite($this->stdout, $banner);
        }

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
