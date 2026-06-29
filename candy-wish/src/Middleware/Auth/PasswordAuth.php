<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Middleware\Auth;

use SugarCraft\Wish\Context;
use SugarCraft\Wish\Lang;
use SugarCraft\Wish\Middleware;
use SugarCraft\Wish\Session;

/**
 * Password-authentication gate.
 *
 * Validates `Session::$user` plus a password against a caller-
 * supplied callback. The callback receives `(string $user,
 * string $password)` and returns `true` to accept or `false` to
 * reject.
 *
 * **Password environment exposure.** The password is read from the
 * `SSH_PASSWORD` environment variable (via `$_SERVER` or `getenv`).
 * This makes it visible via `/proc/<pid>/environ`, inherited by child
 * processes, and potentially appearing in crash dumps. This middleware
 * mitigates this by unsetting `SSH_PASSWORD` from both `$_SERVER` and
 * the process environment immediately after reading it, before the
 * validation result is returned.
 *
 * On rejection writes a one-line message to stderr and returns
 * without invoking `$next`.
 */
final class PasswordAuth implements Middleware
{
    /** @var callable(string, string): bool */
    private $validate;

    /** @var resource */
    private $stderr;

    /**
     * @param callable(string, string): bool $validate Returns true for valid credentials
     * @param resource|null                 $stderr
     */
    public function __construct(callable $validate, $stderr = null)
    {
        $this->validate = $validate;
        if ($stderr === null) {
            $stream = fopen('php://stderr', 'w');
            if ($stream === false) {
                throw new \RuntimeException(Lang::t('middleware.cannot_open_stderr'));
            }
            $this->stderr = $stream;
            return;
        }
        if (!is_resource($stderr)) {
            throw new \InvalidArgumentException(Lang::t('middleware.stderr_not_resource'));
        }
        $this->stderr = $stderr;
    }

    public function handle(Context $ctx, Session $session, callable $next)
    {
        $password = isset($_SERVER['SSH_PASSWORD']) && $_SERVER['SSH_PASSWORD'] !== ''
            ? $_SERVER['SSH_PASSWORD']
            : (getenv('SSH_PASSWORD') ?: '');

        // Clear the plaintext password from the environment immediately
        // after reading so it stops appearing in /proc/<pid>/environ
        // and is not inherited by child processes.
        unset($_SERVER['SSH_PASSWORD']);
        putenv('SSH_PASSWORD');

        if (!($this->validate)($session->user, $password)) {
            fwrite($this->stderr, "Permission denied.\n");
            return;
        }

        $next($ctx, $session);
    }
}
