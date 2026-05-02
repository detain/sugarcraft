<?php

declare(strict_types=1);

namespace CandyCore\Wish\Middleware;

use CandyCore\Wish\Middleware;
use CandyCore\Wish\Session;

/**
 * Username / public-key allowlist gate.
 *
 * Two complementary checks:
 *
 *   - `users` — exact-match list of acceptable `Session::$user`.
 *     Empty list = allow any user.
 *   - `keyFingerprints` — list of acceptable
 *     `SHA256:<base64>` fingerprints. The fingerprint is read
 *     from `\$_SERVER['SSH_USER_AUTH']` /
 *     `\$_SERVER['SSH_USER_KEY_FINGERPRINT']` (sshd writes one of
 *     these depending on version) or from the `KEY_FINGERPRINT`
 *     env var. Empty list = skip key check.
 *
 * On rejection writes a one-line `Unauthorized.` to STDERR and
 * returns without invoking `$next` — the connection ends cleanly.
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
                throw new \RuntimeException('cannot open php://stderr');
            }
            $this->stderr = $stream;
            return;
        }
        if (!is_resource($stderr)) {
            throw new \InvalidArgumentException('stderr must be a resource');
        }
        $this->stderr = $stderr;
    }

    public function handle(Session $session, callable $next): void
    {
        if ($this->users !== [] && !in_array($session->user, $this->users, true)) {
            $this->reject('user not allowed: ' . $session->user);
            return;
        }
        if ($this->keyFingerprints !== []) {
            $fp = $this->fingerprint();
            if ($fp === null || !in_array($fp, $this->keyFingerprints, true)) {
                $this->reject('key not allowed: ' . ($fp ?? '<missing>'));
                return;
            }
        }
        $next($session);
    }

    private function fingerprint(): ?string
    {
        foreach (['SSH_USER_KEY_FINGERPRINT', 'SSH_USER_AUTH', 'KEY_FINGERPRINT'] as $k) {
            $v = $_SERVER[$k] ?? getenv($k);
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }
        return null;
    }

    private function reject(string $reason): void
    {
        fwrite($this->stderr, "Unauthorized. ({$reason})\n");
    }
}
