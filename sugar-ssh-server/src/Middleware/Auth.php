<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Middleware;

use SugarCraft\SshServer\Context;
use SugarCraft\SshServer\Lang;
use SugarCraft\SshServer\Middleware;
use SugarCraft\SshServer\Session;
use SugarCraft\SshServer\StreamHelper;

/**
 * Username / public-key allowlist gate.
 *
 * Three checks, of which the third is the one that binds a key to a
 * person:
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
 *     **This list is a CROSS PRODUCT, not a pairing.** It is compared
 *     against the presented key only; `users` is compared against the
 *     requested name only. Two users in `users` and two keys in
 *     `keyFingerprints` admits all four combinations: with `users`
 *     `['alice','bob']` and `keyFingerprints` `['alice-key','bob-key']`
 *     BOB PRESENTING ALICE'S KEY IS ADMITTED AS alice. That is the
 *     audited defect (K4); it is behaviour, not a bug you can flip
 *     silently. Use `userKeys` below when the point of the allowlist is
 *     "whose key is this".
 *   - `userKeys` — map of `Session::$user` => list of that user's
 *     acceptable fingerprints. When non-empty this is the AUTHORITATIVE
 *     pairing: the requested name must be a key of the map, and the
 *     presented fingerprint must appear in that name's list. A name
 *     mapped to `[Auth::ANY_KEY]` accepts any key for that user (there is
 *     no key evidence to check against — see the trust boundary). The
 *     `users` and `keyFingerprints` lists still apply as additional gates,
 *     so combining them can only narrow.
 *
 * Membership of every fingerprint list is compared with `hash_equals()`
 * over the whole list rather than `in_array()`, so a miss costs the same
 * time whatever the position and no early-out leaks which entry matched.
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
 *
 * Pairing and openness are separate questions. Even a perfectly
 * configured `keyFingerprints` list admits any name in `users`; and an
 * EMPTY list is not 'no keys allowed' but 'nothing checked' — `new Auth()`
 * with the allowlist arguments unset lets every session through. That
 * default is deliberate (start-simple), but it means the gate has to be
 * armed explicitly, never left at its constructor default.
 */
final class Auth implements Middleware
{
    /**
     * Sentinel used as the sole entry of a `userKeys` list to mean "any
     * key, this name only". Named so a deployment can say it out loud
     * rather than shipping a bare `'*'` that a reader may take for a glob.
     */
    public const ANY_KEY = '*';

    /** @var list<string> */
    private array $users;
    /** @var list<string> */
    private array $keyFingerprints;
    /** @var array<string, list<string>> */
    private array $userKeys;
    /** @var resource */
    private $stderr;
    /** @var (callable(string, Session): bool)|null */
    private $fingerprintValidator;

    /**
     * @param list<string>                          $users                Allowed `Session::$user` values; [] = any
     * @param list<string>                          $keyFingerprints      Allowed `SHA256:...` fingerprints; [] = skip.
     *                                                                    NOT paired with $users — see class docblock
     *                                                                    (K4): prefer $userKeys for a real binding.
     * @param resource|null                         $stderr               Stream for the rejection notice
     * @param (callable(string, Session): bool)|null $fingerprintValidator Optional real verifier: receives the
     *                                                                    presented fingerprint (or '' if none) plus
     *                                                                    the Session and returns true to accept. Runs
     *                                                                    in addition to the allowlist, closing the
     *                                                                    forgeable-fingerprint trust gap.
     * @param array<string, list<string>>           $userKeys             Name => that name's allowed fingerprints.
     *                                                                    Non-empty makes the pairing authoritative:
     *                                                                    a key listed under alice admits alice only.
     *                                                                    `['*']` for a name = any key, that name only.
     *                                                                    Tail position to keep the older positional
     *                                                                    call sites working.
     */
    public function __construct(
        array $users = [],
        array $keyFingerprints = [],
        $stderr = null,
        ?callable $fingerprintValidator = null,
        array $userKeys = [],
    ) {
        $this->users                = $users;
        $this->keyFingerprints      = $keyFingerprints;
        $this->userKeys             = $userKeys;
        $this->stderr               = StreamHelper::openOrValidate($stderr);
        $this->fingerprintValidator = $fingerprintValidator;
    }

    public function handle(Context $ctx, Session $session, callable $next)
    {
        if ($this->users !== [] && !in_array($session->user, $this->users, true)) {
            $this->reject($session, 'user not allowed: ' . $this->sanitize($session->user));
            return;
        }
        if ($this->keyFingerprints !== [] || $this->fingerprintValidator !== null || $this->userKeys !== []) {
            $fp = $this->fingerprint();
            if ($this->keyFingerprints !== []
                && ($fp === null || !$this->listContains($fp, $this->keyFingerprints))
            ) {
                $this->reject($session, 'key not allowed: ' . $this->sanitize($fp ?? '<missing>'));
                return;
            }
            if ($this->userKeys !== [] && !$this->keyBelongsTo($fp, $session->user)) {
                // Distinct wording: the cross-product gate passed (or was
                // absent) but the name that owns this key is not the name
                // that asked. Without a separate reason an operator
                // reading the log cannot tell the two failures apart.
                $this->reject($session, 'key not paired with the requested user: ' . $this->sanitize($fp ?? '<missing>')
                    . ' as ' . $this->sanitize($session->user));
                return;
            }
            // The allowlist only proves the presented (forgeable) value
            // matches a known string. When a validator is wired, require
            // it to actually vouch for the key before admitting.
            if ($this->fingerprintValidator !== null
                && !($this->fingerprintValidator)($fp ?? '', $session)
            ) {
                $this->reject($session, 'key validation failed: ' . $this->sanitize($fp ?? '<missing>'));
                return;
            }
        }
        $next($ctx, $session);
    }

    /**
     * The `userKeys` pairing gate: the requested name must be in the map
     * and the presented key must be one of that name's own keys.
     *
     * @param string|null $fp    Presented fingerprint, if any
     * @param string      $user  Name the client asked to log in as
     */
    private function keyBelongsTo(?string $fp, string $user): bool
    {
        if (!array_key_exists($user, $this->userKeys)) {
            return false;
        }
        $allowed = $this->userKeys[$user];
        if ($allowed === [self::ANY_KEY]) {
            // Declared "any key for this name"; the users gate above has
            // already bounded which names reach here.
            return true;
        }
        return $fp !== null && $this->listContains($fp, $allowed);
    }

    /**
     * Constant-work membership test for an allowlist of secrets.
     *
     * `in_array()` short-circuits on the first match, so the position of
     * the winner is measurable; `hash_equals()` additionally compares
     * without an early byte-difference return. Every entry is visited
     * regardless of outcome, which is the point.
     *
     * @param list<string> $allowed
     */
    private function listContains(string $presented, array $allowed): bool
    {
        $found = false;
        foreach ($allowed as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            if (hash_equals($candidate, $presented)) {
                $found = true;
            }
        }
        return $found;
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

    private function reject(Session $session, string $reason): void
    {
        // The notice goes to whoever can see it (audit K3: under
        // ForceCommand that is the peer); the mark goes to the exit code,
        // which is what a cron entry or CI step reads (audit MAJOR 9).
        $session->markRefused();
        fwrite($this->stderr, Lang::t('auth.unauthorized', ['reason' => $reason]) . "\n");
    }
}
