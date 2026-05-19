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

        if (!($this->validate)($session->user, $password)) {
            fwrite($this->stderr, "Permission denied.\n");
            return;
        }

        $next($ctx, $session);
    }
}
