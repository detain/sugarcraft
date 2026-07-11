<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Middleware;

use SugarCraft\Wish\Context;
use SugarCraft\Wish\Middleware;
use SugarCraft\Wish\Session;
use SugarCraft\Wish\StreamHelper;

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
 *
 * **Trust boundary — the fingerprint is NOT verified here.** The
 * fingerprint is read verbatim from environment variables
 * (`SSH_USER_KEY_FINGERPRINT` / `KEY_FINGERPRINT` / `SSH_USER_AUTH`).
 * In the intended deployment those are written by the host `sshd` AFTER
 * it has cryptographically verified the client's key — sshd is the real
 * trust boundary and this class is a post-hoc allowlist layered on top.
 * In any other arrangement (a middleware upstream that copies client-
 * supplied data into these vars, an in-process transport without a
 * verifying sshd) the values are FORGEABLE: matching the allowlist then
 * proves nothing. To actually verify the presented key, wire a
 * validator via the `$fingerprintValidator` constructor argument — it
 * runs in addition to the allowlist and can consult a real key store /
 * CA / agent. Do NOT rely on the allowlist alone as cryptographic proof
 * of identity.
 */
final class Auth implements Middleware
{
    /** @var list<string> */
    private array $users;
    /** @var list<string> */
    private array $keyFingerprints;
    /** @var resource */
    private $stderr;
    /** @var (callable(string, Session): bool)|null */
    private $fingerprintValidator;

    /**
     * @param list<string>                          $users                Allowed `Session::$user` values; [] = any
     * @param list<string>                          $keyFingerprints      Allowed `SHA256:...` fingerprints; [] = skip
     * @param resource|null                         $stderr               Stream for the rejection notice
     * @param (callable(string, Session): bool)|null $fingerprintValidator Optional real verifier: receives the
     *                                                                    presented fingerprint (or '' if none) plus
     *                                                                    the Session and returns true to accept. Runs
     *                                                                    in addition to the allowlist, closing the
     *                                                                    forgeable-fingerprint trust gap.
     */
    public function __construct(
        array $users = [],
        array $keyFingerprints = [],
        $stderr = null,
        ?callable $fingerprintValidator = null,
    ) {
        $this->users                = $users;
        $this->keyFingerprints      = $keyFingerprints;
        $this->stderr               = StreamHelper::openOrValidate($stderr);
        $this->fingerprintValidator = $fingerprintValidator;
    }

    public function handle(Context $ctx, Session $session, callable $next)
    {
        if ($this->users !== [] && !in_array($session->user, $this->users, true)) {
            $this->reject('user not allowed: ' . $this->sanitize($session->user));
            return;
        }
        if ($this->keyFingerprints !== [] || $this->fingerprintValidator !== null) {
            $fp = $this->fingerprint();
            if ($this->keyFingerprints !== []
                && ($fp === null || !in_array($fp, $this->keyFingerprints, true))
            ) {
                $this->reject('key not allowed: ' . $this->sanitize($fp ?? '<missing>'));
                return;
            }
            // The allowlist only proves the presented (forgeable) value
            // matches a known string. When a validator is wired, require
            // it to actually vouch for the key before admitting.
            if ($this->fingerprintValidator !== null
                && !($this->fingerprintValidator)($fp ?? '', $session)
            ) {
                $this->reject('key validation failed: ' . $this->sanitize($fp ?? '<missing>'));
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
        if (preg_match('#\b(SHA256:[A-Za-z0-9+/=]+|MD5:[0-9a-f:]{32,})\b#', $blob, $m)) {
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
