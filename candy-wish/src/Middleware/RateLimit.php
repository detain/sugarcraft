<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Middleware;

use SugarCraft\Wish\Context;
use SugarCraft\Wish\Middleware;
use SugarCraft\Wish\Session;
use SugarCraft\Wish\StreamHelper;

/**
 * Connection rate-limiter using token-buckets persisted to a file.
 *
 * By default each unique `Session::$clientHost` (source IP) gets its
 * own bucket; a bucket starts full at `$burst` tokens and refills at
 * `$ratePerSec` tokens/second. Each connect attempt costs one token —
 * when a bucket is empty the session is rejected with a one-line
 * message on stderr and `$next` is not invoked.
 *
 * **Per-username throttling (opt-in).** Pass `$userBurst` (and
 * optionally `$userRatePerSec`) to additionally throttle per
 * `Session::$user`, so a single account can't be brute-forced across
 * many source IPs. When enabled, a connect must obtain a token from
 * BOTH its IP bucket and its username bucket; a reject on either denies
 * the connection and does NOT consume the other bucket's token.
 *
 * **Auth-failure penalty API.** {@see penalize()} drains extra tokens
 * from a session's buckets. Wire it into an auth middleware's reject
 * path (bad password / unknown key) so repeated credential failures
 * from one IP or account exhaust the limiter faster than mere connects.
 *
 * **Ordering.** Place `RateLimit` BEFORE the auth middleware (`Auth`,
 * `PasswordAuth`, …) in the stack so each connection is counted before
 * credentials are checked; then wire {@see penalize()} into the auth
 * middleware's failure path. Within a single check the IP bucket is
 * evaluated before the username bucket.
 *
 * **Fail-open vs fail-closed.** If the state file cannot be opened or
 * locked, the limiter cannot make a decision. By default it FAILS OPEN
 * (allows the connection) so a transient filesystem problem does not
 * lock every user out — but it now LOGS the degradation to stderr
 * instead of failing silently. Pass `$failClosed: true` to instead DENY
 * on a state-layer error (a security-conscious deployment that would
 * rather reject than serve un-throttled traffic).
 *
 * The persistence file is a JSON map of
 * `{ "ip:<host>"|"user:<name>": [tokens, last_ts] }` — `flock(LOCK_EX)`
 * serialises concurrent updates from sibling sshd-spawned processes.
 * It is written 0600 (owner-only) via a temp-file + atomic rename, so a
 * co-tenant can't read who has been connecting. For high-volume
 * deployments swap in Redis (this class is intentionally
 * dependency-light).
 */
final class RateLimit implements Middleware
{
    /** @var resource */
    private $stderr;

    /**
     * @param string        $statePath      File the bucket map lives in
     * @param int           $burst          Max simultaneous per-IP tokens
     * @param float         $ratePerSec     Per-IP refill rate
     * @param resource|null $stderr
     * @param bool          $failClosed     Deny (rather than allow) when the
     *                                      state file can't be read/locked
     * @param int|null      $userBurst      Max per-username tokens; null disables
     *                                      per-username throttling
     * @param float|null    $userRatePerSec Per-username refill rate; defaults to
     *                                      `$ratePerSec` when `$userBurst` is set
     */
    public function __construct(
        private readonly string $statePath,
        private readonly int    $burst = 5,
        private readonly float  $ratePerSec = 0.5,
        $stderr = null,
        private readonly bool   $failClosed = false,
        private readonly ?int   $userBurst = null,
        private readonly ?float $userRatePerSec = null,
    ) {
        $this->stderr = StreamHelper::openOrValidate($stderr);
    }

    public function handle(Context $ctx, Session $session, callable $next)
    {
        if (!$this->take($session)) {
            fwrite($this->stderr, "Rate limit exceeded. Try again later.\n");
            return;
        }
        $next($ctx, $session);
    }

    /**
     * Charge an extra `$tokens` against a session's buckets without the
     * accept/reject semantics of a connect. Intended to be called from
     * an auth-failure hook so repeated bad credentials drain the limiter
     * faster than connection attempts alone.
     *
     * Never throws on a state error — a penalty is best-effort.
     */
    public function penalize(Session $session, float $tokens = 1.0): void
    {
        if ($tokens <= 0.0) {
            return;
        }
        $specs = $this->bucketsFor($session);
        $this->withState(static function (array &$data) use ($specs, $tokens): bool {
            $now = \microtime(true);
            foreach ($specs as [$key, $burst, $rate]) {
                $current = self::refill($data, $key, $burst, $rate, $now);
                $data[$key] = ['tokens' => \max(0.0, $current - $tokens), 'last' => $now];
            }
            return true;
        });
    }

    /**
     * Bucket specs — `[key, burst, ratePerSec]` — for a session: the
     * per-IP bucket always, plus a per-username bucket when `$userBurst`
     * is configured. IP is listed first so it is evaluated first.
     *
     * @return list<array{0:string,1:int,2:float}>
     */
    private function bucketsFor(Session $session): array
    {
        $host  = $session->clientHost === '' ? '_unknown' : $session->clientHost;
        $specs = [['ip:' . $host, $this->burst, $this->ratePerSec]];
        if ($this->userBurst !== null) {
            $user    = $session->user === '' ? '_unknown' : $session->user;
            $specs[] = ['user:' . $user, $this->userBurst, $this->userRatePerSec ?? $this->ratePerSec];
        }
        return $specs;
    }

    private function take(Session $session): bool
    {
        $specs = $this->bucketsFor($session);
        return $this->withState(static function (array &$data) use ($specs): bool {
            $now      = \microtime(true);
            $refilled = [];
            foreach ($specs as [$key, $burst, $rate]) {
                $refilled[$key] = self::refill($data, $key, $burst, $rate, $now);
            }
            // Reject if ANY bucket is exhausted. Persist the refilled
            // values (so 'last' advances) but do NOT consume — a full IP
            // bucket must not be drained by a throttled username, and
            // vice-versa.
            foreach ($refilled as $tokens) {
                if ($tokens < 1.0) {
                    foreach ($refilled as $k => $t) {
                        $data[$k] = ['tokens' => $t, 'last' => $now];
                    }
                    return false;
                }
            }
            foreach ($refilled as $key => $tokens) {
                $data[$key] = ['tokens' => $tokens - 1.0, 'last' => $now];
            }
            return true;
        });
    }

    /**
     * Refill a bucket to `min(burst, tokens + elapsed * rate)` without
     * mutating `$data`.
     *
     * @param array<string,array{tokens:float,last:float}> $data
     */
    private static function refill(array $data, string $key, int $burst, float $rate, float $now): float
    {
        $entry  = $data[$key] ?? ['tokens' => $burst, 'last' => $now];
        $tokens = (float) ($entry['tokens'] ?? $burst);
        $last   = (float) ($entry['last'] ?? $now);
        return \min((float) $burst, $tokens + ($now - $last) * $rate);
    }

    /**
     * Run `$fn` against the persisted bucket map inside an exclusive
     * lock, then write the (possibly mutated) map back.
     *
     * `$fn` receives the decoded map by reference and returns the
     * accept/reject decision. On a state-layer error (open/lock failure)
     * `$fn` never runs and the failClosed policy decides the return
     * value: fail-open returns true (allow), fail-closed returns false
     * (deny). Either way the degradation is logged to stderr.
     *
     * @param callable(array<string,array{tokens:float,last:float}> &): bool $fn
     */
    private function withState(callable $fn): bool
    {
        // @-suppressed: a missing/locked state file is an expected
        // operational condition handled below, not a programmer error —
        // and phpunit runs with failOnWarning.
        $fh = @\fopen($this->statePath, 'c+');
        if ($fh === false) {
            return $this->onStateError('cannot open state file: ' . $this->statePath);
        }
        if (!\flock($fh, LOCK_EX)) {
            \fclose($fh);
            return $this->onStateError('cannot lock state file: ' . $this->statePath);
        }
        try {
            $raw  = \stream_get_contents($fh);
            $data = \is_string($raw) && $raw !== '' ? \json_decode($raw, true) : [];
            if (!\is_array($data)) {
                $data = [];
            }
            /** @var array<string,array{tokens:float,last:float}> $data */
            $decision = $fn($data);
            $this->writeBack($fh, $data);
            return $decision;
        } finally {
            \flock($fh, LOCK_UN);
            \fclose($fh);
        }
    }

    /**
     * Log a state-layer degradation and return the fail-open/closed
     * decision: allow (true) unless `$failClosed`, in which case deny
     * (false). This replaces the previous silent fail-open.
     */
    private function onStateError(string $why): bool
    {
        \fwrite(
            $this->stderr,
            ($this->failClosed
                ? 'Rate limiter unavailable, denying connection: '
                : 'Rate limiter degraded, allowing connection (fail-open): ')
            . $why . "\n",
        );
        return !$this->failClosed;
    }

    /**
     * @param resource                                     $fh
     * @param array<string,array{tokens:float,last:float}> $data
     */
    private function writeBack($fh, array $data): void
    {
        $payload = json_encode($data, JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return;
        }
        // Atomic write: write to a temp file in the same directory,
        // chmod it owner-only, then rename over the original. rename() is
        // atomic on POSIX filesystems, so readers either see the old
        // complete content or the new complete content — never an
        // empty/truncated file — and the final inode is 0600 regardless
        // of the process umask.
        $dir = \dirname($this->statePath);
        $tmp = $dir . '/.ratelimit_tmp_' . \bin2hex(\random_bytes(8));
        try {
            if (@file_put_contents($tmp, $payload) === false) {
                return;
            }
            // Restrict to owner before publishing so the bucket map (which
            // reveals who has been connecting) isn't world/group readable.
            @\chmod($tmp, 0600);
            // The lock is still held from withState(), so rename is safe.
            if (!\rename($tmp, $this->statePath)) {
                @\unlink($tmp);
            }
        } finally {
            // Clean up temp file if rename somehow failed mid-operation.
            if (\file_exists($tmp)) {
                @\unlink($tmp);
            }
        }
    }
}
