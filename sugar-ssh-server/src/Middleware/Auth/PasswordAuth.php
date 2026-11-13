<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Middleware\Auth;

use SugarCraft\SshServer\Context;
use SugarCraft\SshServer\Lang;
use SugarCraft\SshServer\Middleware;
use SugarCraft\SshServer\Session;
use SugarCraft\SshServer\StreamHelper;

/**
 * Password-authentication gate.
 *
 * Validates `Session::$user` plus a password against a caller-
 * supplied callback. The callback receives `(string $user,
 * string $password)` and returns `true` to accept or `false` to
 * reject.
 *
 * **Password environment exposure.** The password is read from the
 * `SSH_PASSWORD` environment variable (via `$_SERVER` or `getenv`), which
 * makes it visible in `/proc/<pid>/environ`, inheritable by children, and
 * possible to find in a crash dump. This gate unsets the variable from both
 * sources the moment it has read it, which stops later reads in this process
 * and stops a child spawned afterwards from inheriting it. It is a partial
 * mitigation and says so: `putenv()` does not rewrite the kernel's copy of
 * this process's initial environment, so a ptrace or core dump taken during
 * the walk can still show the bytes. If that threat model matters to you,
 * do not put the secret in the environment at all - have the wrapper hand it
 * to the entry script over a pipe and inject it as a constructor argument.
 * (2026-11 audit)
 *
 * **Presence is not emptiness.** A session whose environment carries no
 * `SSH_PASSWORD` at all is refused without consulting the validator. An
 * empty string that was actually delivered still reaches it, because
 * "nobody sent a password" and "the password sent is empty" are different
 * facts and only the second is the caller's to judge. (2026-11 audit)
 *
 * On rejection writes a one-line message to stderr, marks the session
 * refused for the exit code (2026-11 audit), and returns without invoking
 * `$next`.
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
        $this->stderr   = StreamHelper::openOrValidate($stderr);
    }

    public function handle(Context $ctx, Session $session, callable $next): void
    {
        $password = self::readDeliveredCredential();

        if ($password === null) {
            // Nothing arrived: this is not a wrong guess, it is a missing
            // factor, and the validator has no opinion worth recording.
            $session->markRefused();
            fwrite($this->stderr, Lang::t('passwordauth.permission_denied') . "\n");
            return;
        }

        if (!($this->validate)($session->user, $password)) {
            $session->markRefused();
            fwrite($this->stderr, Lang::t('passwordauth.permission_denied') . "\n");
            return;
        }

        $next($ctx, $session);
    }

    /**
     * Read `SSH_PASSWORD` once and scrub it, distinguishing "absent" from
     * "delivered, and empty".
     *
     * @return string|null null when neither source carried the variable
     */
    private static function readDeliveredCredential(): ?string
    {
        if (array_key_exists('SSH_PASSWORD', $_SERVER)) {
            $password = (string) $_SERVER['SSH_PASSWORD'];
        } else {
            $fromEnv = getenv('SSH_PASSWORD');
            if ($fromEnv === false) {
                return null;
            }
            $password = $fromEnv;
        }

        unset($_SERVER['SSH_PASSWORD']);
        putenv('SSH_PASSWORD');

        return $password;
    }
}
