<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Middleware;

use SugarCraft\Wish\Context;
use SugarCraft\Wish\Lang;
use SugarCraft\Wish\Middleware;
use SugarCraft\Wish\Session;

/**
 * Username / public-key allowlist gate.
 *
 * Two complementary checks:
 *
 *   - `users` — exact-match list of acceptable `Session::$user`.
 *     Empty list = allow any user.
 *   - `keyFingerprints` — list of acceptable
 *     `SHA256:<base64>` fingerprints. The fingerprint is read
 *     from `\$_SERVER['SSH_USER_KEY_FINGERPRINT']` /
 *     `KEY_FINGERPRINT` (sshd writes one of these depending on
 *     version) as verbatim bare values. For `\$_SERVER['SSH_USER_AUTH']`
 *     (OpenSSH ExposeAuthInfo multi-line blob, e.g.
 *     `publickey ssh-ed25519 SHA256:AbC123=`), the fingerprint
 *     token is extracted via regex. Empty list = skip key check.
 *
 * On rejection writes a one-line `Unauthorized.` to STDERR and
 * returns without invoking `$next` — the connection ends cleanly.
 *
 * Rejection messages sanitize user-controlled values (username,
 * fingerprint) to strip ANSI escape sequences and C0/C1 control
 * characters before writing to stderr.
 */
final class Auth implements Middleware
{
    /** @var list<string> */
    private array $users;
    /** @var list<string> */
    private array $keyFingerprints;
    /** @var resource */
    private $stderr;

    /**
     * @param list<string>     $users           Allowed `Session::$user` values; [] = any
     * @param list<string>     $keyFingerprints Allowed `SHA256:...` fingerprints; [] = skip
     * @param resource|null    $stderr          Stream for the rejection notice
     */
    public function __construct(array $users = [], array $keyFingerprints = [], $stderr = null)
    {
        $this->users           = $users;
        $this->keyFingerprints = $keyFingerprints;
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
        if ($this->users !== [] && !in_array($session->user, $this->users, true)) {
            $this->reject('user not allowed: ' . $this->sanitize($session->user));
            return;
        }
        if ($this->keyFingerprints !== []) {
            $fp = $this->fingerprint();
            if ($fp === null || !in_array($fp, $this->keyFingerprints, true)) {
                $this->reject('key not allowed: ' . $this->sanitize($fp ?? '<missing>'));
                return;
            }
        }
        $next($ctx, $session);
    }

    private function fingerprint(): ?string
    {
        // SSH_USER_KEY_FINGERPRINT and KEY_FINGERPRINT are bare fingerprints
        // written verbatim by sshd.
        foreach (['SSH_USER_KEY_FINGERPRINT', 'KEY_FINGERPRINT'] as $k) {
            $v = $_SERVER[$k] ?? getenv($k);
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }
        // SSH_USER_AUTH is an OpenSSH ExposeAuthInfo multi-line blob.
        // Extract the first SHA256 (or MD5) fingerprint token from it.
        $blob = $_SERVER['SSH_USER_AUTH'] ?? getenv('SSH_USER_AUTH') ?: '';
        if ($blob === '') {
            return null;
        }
        if (preg_match('/\b(SHA256:[A-Za-z0-9+/=]+|MD5:[0-9a-f:]{32,})\b/', $blob, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Strip C0/C1 control characters and ANSI ESC sequences from a
     * string before it is written to a shared output stream (stderr).
     *
     * This prevents a malicious client from injecting ANSI escape
     * sequences into logs or terminal output via the username or
     * fingerprint fields.
     *
     * @param string $s Raw user-supplied value
     */
    private function sanitize(string $s): string
    {
        // Replace C0 (0x00–0x1F), DEL (0x7F), and C1 (0x80–0x9F) with '?'
        $s = preg_replace('/[\x00-\x1f\x7f-\x9f]/', '?', $s) ?: $s;
        // Strip ESC-based ANSI CSI sequences: ESC [ … letter
        // Covers sequences with any intermediate digits/semicolons
        // e.g. \x1b[0m, \x1b[31m, \x1b[1;2;3m, \x1b[0;1;31m
        $s = preg_replace('/\x1b\[[0-9;]*[a-zA-Z]/', '?', $s) ?: $s;
        return $s;
    }

    private function reject(string $reason): void
    {
        fwrite($this->stderr, "Unauthorized. ({$reason})\n");
    }
}
