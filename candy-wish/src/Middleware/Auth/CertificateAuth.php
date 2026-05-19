<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Middleware\Auth;

use SugarCraft\Wish\Context;
use SugarCraft\Wish\Lang;
use SugarCraft\Wish\Middleware;
use SugarCraft\Wish\Session;

/**
 * X.509 certificate authentication gate.
 *
 * Validates the peer certificate presented during a TLS or
 * SSH connection. The certificate is read from the `SSL_CLIENT_CERT`
 * environment variable (set by Apache/mod_ssl when `FakeBasicAuth`
 * is enabled) or from `SSH_CLIENT_CERT`.
 *
 * A custom validator callback receives the PEM-encoded certificate
 * text and the `Session`; it returns `true` to accept or `false`
 * to reject. If the validator rejects (or no certificate is present
 * and a certificate is required) a one-line message is written to
 * stderr and `$next` is not called.
 */
final class CertificateAuth implements Middleware
{
    /** @var callable(string, Session): bool */
    private $validate;

    /** @var bool */
    private bool $required;

    /** @var resource */
    private $stderr;

    /**
     * @param callable(string, Session): bool $validate Returns true for a valid cert
     * @param bool                             $required Fail if no cert is present
     * @param resource|null                    $stderr
     */
    public function __construct(callable $validate, bool $required = true, $stderr = null)
    {
        $this->validate = $validate;
        $this->required = $required;
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
        $pem = $this->certFromEnv();

        if ($pem === null || $pem === '') {
            if ($this->required) {
                fwrite($this->stderr, "Certificate required but none presented.\n");
                return;
            }
            $next($ctx, $session);
            return;
        }

        if (!($this->validate)($pem, $session)) {
            fwrite($this->stderr, "Certificate rejected.\n");
            return;
        }

        $next($ctx, $session);
    }

    private function certFromEnv(): ?string
    {
        foreach (['SSL_CLIENT_CERT', 'SSH_CLIENT_CERT', 'CERTIFICATE'] as $k) {
            $v = $_SERVER[$k] ?? getenv($k);
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }
        return null;
    }
}
