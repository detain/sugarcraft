<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Middleware\Auth;

use SugarCraft\SshServer\Context;
use SugarCraft\SshServer\Lang;
use SugarCraft\SshServer\Middleware;
use SugarCraft\SshServer\Session;
use SugarCraft\SshServer\StreamHelper;

/**
 * X.509 certificate gate -- reads a certificate the deployment exports.
 *
 * Nothing in stock OpenSSH populates any of the names this class looks
 * at (see {@see self::ENV_KEYS}). `SSL_CLIENT_CERT` is an Apache
 * mod_ssl name, `CERTIFICATE` a generic one, and `SSH_CLIENT_CERT` is
 * not an sshd export either. A peer certificate reaches a ForceCommand
 * child only if the deployment puts it there: a wrapper that maps
 * `SSH_USER_AUTH` (sshd's `ExposeAuthInfo` blob) or its own
 * `authorized_keys` source into one of these names, or an `AcceptEnv`
 * line -- which makes the value peer-asserted, never proven, and is why
 * this gate is only as strong as whoever exported it.
 *
 * Both missing-certificate paths announce (2026-11 audit). Passing silently
 * when `required` is false made a security control that is a no-op on a
 * stock sshd look configured; denying at `required: true` with a bare
 * "none presented" gave no hint that the environment, not the client,
 * was the problem. So with no certificate present this middleware
 * writes the `certificateauth.env_missing` notice naming the variables
 * it looked for, then denies when `required` is true and passes -- with
 * the same notice -- when it is not. That notice travels on the stream
 * you hand it, which under ForceCommand is the connecting client's
 * extended-data channel (see the Logger sink note in the README); it
 * carries no paths.
 *
 * The validator receives the PEM text and the `Session` and returns
 * `true` to accept. A rejected certificate is announced and `$next` is
 * not called.
 */
final class CertificateAuth implements Middleware
{
    /**
     * Environment names probed, in order. None of them is set by a stock
     * OpenSSH; the class docblock explains what has to be true for one to
     * be present. Public so operators (and docs) can name the surface
     * without pasting a literal list.
     */
    public const ENV_KEYS = ['SSL_CLIENT_CERT', 'SSH_CLIENT_CERT', 'CERTIFICATE'];

    /** @var callable(string, Session): bool */
    private $validate;

    /** @var bool */
    private bool $required;

    /** @var resource */
    private $stderr;

    /**
     * @param callable(string, Session): bool $validate Returns true for a valid cert
     * @param bool                             $required Deny when no certificate is present
     * @param resource|null                    $stderr
     */
    public function __construct(callable $validate, bool $required = true, $stderr = null)
    {
        $this->validate = $validate;
        $this->required = $required;
        $this->stderr   = StreamHelper::openOrValidate($stderr);
    }

    public function handle(Context $ctx, Session $session, callable $next): void
    {
        $pem = $this->certFromEnv();

        if ($pem === null || $pem === '') {
            // Loud either way (2026-11 audit): an unconfigured environment must
            // not read as an authenticated connection, and a deny must say
            // why nothing could be checked.
            fwrite($this->stderr, Lang::t('certificateauth.env_missing', [
                'vars' => \implode(', ', self::ENV_KEYS),
            ]) . "\n");

            if ($this->required) {
                $session->markRefused();
                fwrite($this->stderr, Lang::t('certificateauth.required') . "\n");
                return;
            }
            $next($ctx, $session);
            return;
        }

        if (!($this->validate)($pem, $session)) {
            $session->markRefused();
            fwrite($this->stderr, Lang::t('certificateauth.rejected') . "\n");
            return;
        }

        $next($ctx, $session);
    }

    private function certFromEnv(): ?string
    {
        foreach (self::ENV_KEYS as $k) {
            $v = $_SERVER[$k] ?? getenv($k);
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }
        return null;
    }
}
