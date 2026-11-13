<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Middleware;

use SugarCraft\Core\Util\AtomicJsonFile;
use SugarCraft\SshServer\Context;
use SugarCraft\SshServer\Lang;
use SugarCraft\SshServer\Middleware;
use SugarCraft\SshServer\Session;
use SugarCraft\SshServer\StreamHelper;

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
 * **Fail-open vs fail-closed.** If the state file cannot be opened,
 * locked or decoded — or if the bucket being consulted has lost its
 * persisted shape (audit 039: a decoded JSON payload can be ANY shape,
 * and `refill()`'s "absent means full" default silently handed a fresh
 * burst to a garbage entry) — the limiter cannot make a decision. By
 * default it FAILS OPEN (allows the connection) so a transient
 * filesystem problem does not lock every user out — and it ANNOUNCES
 * the degradation on `$stderr`. Read that announcement for what it is:
 * under
 * `ForceCommand` the child's stderr is delivered back to the CONNECTING
 * CLIENT, not to the operator (see {@see \SugarCraft\SshServer\Middleware\Logger}'s
 * sink note), so the notice deliberately carries no path and no detail
 * beyond "degraded". Pass `$failClosed: true` to instead DENY on a
 * state-layer error (a security-conscious deployment that would rather
 * reject than serve un-throttled traffic).
 *
 * **Persistence and locking.** The state file is a JSON map of
 * `{ "ip:<host>"|"user:<name>": {"tokens":float,"last":float,"burst":int,"rate":float} }`.
 * The read-decide-write cycle is serialised by an exclusive `flock` on a
 * STABLE sidecar, `<state-path>.lock`, which is created on demand and
 * never deleted. The published file itself travels through
 * {@see AtomicJsonFile}'s temp-file + rename (0600 on the temp inode
 * before a payload byte is written, so a co-tenant can't read who has
 * been connecting). The two locks are deliberately different inodes:
 * locking the PAYLOAD inode and then renaming over it is the bug this
 * shape fixes — the rename replaces the inode, so a sibling that was
 * blocked on `flock()` wakes up holding a lock over a file nobody can
 * any longer reach, while the next newcomer locks the fresh inode
 * independently and sees a full bucket (audit K2: N parallel connects
 * each got the whole burst). Sidecar locks survive renames.
 *
 * Buckets are PRUNED: any bucket that is not the current session's own
 * and has refilled back to its burst is dropped when the map is next
 * persisted. Dropping a full bucket is behaviour-identical to keeping it
 * ({@see refill()} treats a missing entry as full), so an attacker
 * cycling source IPs cannot grow this file without bound (audit M4).
 * For genuinely high-volume deployments swap in Redis (this class is
 * intentionally dependency-light).
 */
final class RateLimit implements Middleware
{
    /**
     * The path-free state-error reason for a consulted bucket whose
     * persisted entry no longer has the bucket shape (audit 039). One
     * constant so take() and penalize() announce the same condition.
     */
    private const MALFORMED_WHY = 'malformed state entry for a consulted bucket';

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

    public function handle(Context $ctx, Session $session, callable $next): void
    {
        if (!$this->take($session)) {
            // Throttled is not served: say so on the session so the
            // process exits nonzero for the caller that retried too fast.
            $session->markRefused();
            fwrite($this->stderr, Lang::t('ratelimit.exceeded') . "\n");
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
        $this->underLock(function (array &$data) use ($specs, $tokens): bool {
            $now = \microtime(true);
            foreach ($specs as [$key, $burst, $rate]) {
                if (isset($data[$key]) && !self::validEntry($data[$key])) {
                    return $this->onStateError(self::MALFORMED_WHY);
                }
                $current = self::refill($data, $key, $burst, $rate, $now);
                $data[$key] = self::entry(\max(0.0, $current - $tokens), $now, $burst, $rate);
            }
            $this->pruneIdle($data, $specs, $now);
            return true;
        });
    }

    /**
     * Bucket specs — `[key, burst, ratePerSec]` — for a session: the
     * per-IP bucket always, plus a per-username bucket when `$userBurst`
     * is configured. IP is listed first so it is evaluated first.
     *
     * A session with no client address is not exempt: it lands in a single
     * shared `ip:_unknown` bucket together with every other such session,
     * which behind a proxy or on a transport that never learned the peer is
     * one global throttle, not a per-client one (audit MINOR 8).
     * The failure direction is worth naming: a shared bucket is strict, not
     * safe. One noisy client that arrived without an address can burn the
     * burst and lock out every other address-less client behind it, which is
     * an availability bug wearing a security costume. It is still the right
     * default - the alternative is to treat unattributed traffic as
     * unthrottled, which is worse - but `clientHost` must actually be
     * populated (see `Session::fromEnvironment()` and the
     * `SSH_CONNECTION`/`SSH_CLIENT` note in the README) for the limiter to
     * mean what its name says. The same naming rule applies to a blank
     * username when `$userBurst` is set.
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
        return $this->underLock(function (array &$data) use ($specs): bool {
            $now      = \microtime(true);
            $refilled = [];
            foreach ($specs as [$key, $burst, $rate]) {
                // Audit 039: a consulted entry that lost its shape is a
                // state-layer error with the SAME loud policy as an
                // undecodable file — refill()'s "absent means full"
                // default must not be allowed to read garbage as a fresh
                // burst. Malformed entries under never-consulted keys are
                // pruneIdle()'s jurisdiction (its is_array-skip keeps or
                // drops them as before); this guard only speaks for the
                // buckets this decision actually reads.
                if (isset($data[$key]) && !self::validEntry($data[$key])) {
                    return $this->onStateError(self::MALFORMED_WHY);
                }
                $refilled[$key] = self::refill($data, $key, $burst, $rate, $now);
            }
            // Reject if ANY bucket is exhausted. Persist the refilled
            // values (so 'last' advances) but do NOT consume — a full IP
            // bucket must not be drained by a throttled username, and
            // vice-versa.
            foreach ($refilled as $tokens) {
                if ($tokens < 1.0) {
                    foreach ($specs as [$key, $burst, $rate]) {
                        $data[$key] = self::entry($refilled[$key], $now, $burst, $rate);
                    }
                    $this->pruneIdle($data, $specs, $now);
                    return false;
                }
            }
            foreach ($specs as [$key, $burst, $rate]) {
                $data[$key] = self::entry($refilled[$key] - 1.0, $now, $burst, $rate);
            }
            $this->pruneIdle($data, $specs, $now);
            return true;
        });
    }

    /**
     * A persisted bucket: the live token count, the time it was last
     * touched, and the shape that produced it.
     *
     * `burst`/`rate` ride along because {@see pruneIdle()} must be able to
     * answer "has this bucket refilled?" for a key this process is not
     * consulting — which is only decidable against the limits that created
     * it, not the limits some sibling process happens to be configured with.
     *
     * @return array{tokens:float,last:float,burst:int,rate:float}
     */
    private static function entry(float $tokens, float $now, int $burst, float $rate): array
    {
        return ['tokens' => $tokens, 'last' => $now, 'burst' => $burst, 'rate' => $rate];
    }

    /**
     * Drop every bucket that is neither of the caller's own buckets nor
     * still constraining anything.
     *
     * A bucket that has refilled to its burst is, by the rule
     * {@see refill()} applies to absent keys, indistinguishable from a
     * bucket that was never written: keeping it saves nothing and dropping
     * it costs nothing. That makes the prune a pure memory-reclamation step
     * — an attacker cycling source IPs (each minting an `ip:` entry) no
     * longer grows the state file, and the file's rewrite cost stops
     * growing with it (audit M4: the 'bounded state' claim was only true
     * while nobody was looking).
     *
     * Entries written by an older build carry no `burst`/`rate`; they are
     * judged against this process's own limits for their key prefix, which
     * is exactly how the writer would have configured them in a
     * single-configuration deployment.
     *
     * @param array<string,array{tokens:float,last:float,burst?:int,rate?:float}> $data
     * @param list<array{0:string,1:int,2:float}>                                 $specs
     */
    private function pruneIdle(array &$data, array $specs, float $now): void
    {
        $mine = [];
        foreach ($specs as [$key, $burst, $rate]) {
            $mine[$key] = true;
        }
        foreach ($data as $key => $entry) {
            if (isset($mine[$key]) || !\is_array($entry)) {
                continue;
            }
            [$burst, $rate] = $this->limitsFor($key, $entry);
            if (self::refill($data, (string) $key, $burst, $rate, $now) >= (float) $burst) {
                unset($data[$key]);
            }
        }
    }

    /**
     * The burst/rate pair to judge an existing bucket against: the pair it
     * was written with when present, else this process's configured pair for
     * the key's prefix.
     *
     * @param array{tokens?:float,last?:float,burst?:int,rate?:float} $entry
     *
     * @return array{0:int,1:float}
     */
    private function limitsFor(string $key, array $entry): array
    {
        if (isset($entry['burst'], $entry['rate'])
            && \is_int($entry['burst']) && \is_numeric($entry['rate'])) {
            return [$entry['burst'], (float) $entry['rate']];
        }
        if (\str_starts_with($key, 'user:')) {
            return [$this->userBurst ?? $this->burst, $this->userRatePerSec ?? $this->ratePerSec];
        }
        return [$this->burst, $this->ratePerSec];
    }

    /**
     * Refill a bucket to `min(burst, tokens + elapsed * rate)` without
     * mutating `$data`.
     *
     * The "absent means full" default is the whole contract for a key
     * that was never written — and the whole hazard for a key written by
     * something that lost its shape, which is why consulted entries pass
     * {@see validEntry()} before they reach this method (audit 039).
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
     * The shape contract of a persisted bucket, as checked at the JSON
     * boundary (audit 039).
     *
     * The state file decodes to whatever JSON it contains: a consulted
     * entry that is not an array, or whose `tokens`/`last` (the fields
     * {@see refill()} reads) are not numeric, would be read by that
     * method's absent-means-full default as a freshly refilled bucket —
     * the silent restart M5 made whole-file corruption loud, smuggled
     * through at entry granularity. Such an entry is therefore a
     * state-layer error, decided by the same fail-open/fail-closed
     * policy. `burst`/`rate` need not be present: {@see limitsFor()}
     * already has the documented fallback for older writes.
     */
    private static function validEntry(mixed $entry): bool
    {
        return \is_array($entry)
            && \is_numeric($entry['tokens'] ?? null)
            && \is_numeric($entry['last'] ?? null);
    }

    /**
     * Run `$fn` against the persisted bucket map inside an exclusive lock,
     * then write the (possibly mutated) map back.
     *
     * `$fn` receives the decoded map by reference and returns the
     * accept/reject decision. On a state-layer error (lock, read or decode
     * failure) `$fn` never runs and the failClosed policy decides the return
     * value: fail-open returns true (allow), fail-closed returns false
     * (deny). Either way the degradation is announced.
     *
     * The lock is held across the WHOLE read-decide-publish cycle and rides
     * a stable sidecar inode — see the class docblock for why the payload
     * inode cannot.
     *
     * Audit 047 rename (was `withState`): this is not a `with*()` value-
     * object setter — it runs a decision UNDER the lock. The name now says
     * what the grep-for-name reader needs to know.
     *
     * @param callable(array<string,array{tokens:float,last:float,burst?:int,rate?:float}> &): bool $fn
     */
    private function underLock(callable $fn): bool
    {
        $lock = $this->acquireLock();
        if ($lock === false) {
            return $this->onStateError('cannot open the lock file');
        }
        if (!\flock($lock, LOCK_EX)) {
            $this->releaseLock($lock);
            return $this->onStateError('cannot take the exclusive lock');
        }
        try {
            try {
                $data = $this->store()->read();
            } catch (\Throwable $e) {
                // A present-but-corrupt map is LOUD (audit M5): the previous
                // `json_decode(...) ?: []` turned garbage into a full burst
                // for everyone, silently. The failClosed policy decides what
                // a corrupt file means; it is never "start over at full
                // speed".
                return $this->onStateError('state file unreadable: ' . \get_class($e));
            }
            /** @var array<string,array{tokens:float,last:float,burst?:int,rate?:float}> $data */
            $decision = $fn($data);
            $this->writeBack($data);
            return $decision;
        } finally {
            $this->releaseLock($lock);
        }
    }

    /**
     * The store for the configured path. AtomicJsonFile supplies the
     * missing-file-is-empty read, the loud corrupt read, the 0600-on-temp
     * write and the publishing rename; this class supplies the lock that
     * makes the whole decision atomic (and deliberately does NOT let
     * AtomicJsonFile's own hidden `.<basename>.lock` be the decision lock —
     * holding it here would self-deadlock the write below, so our sidecar is
     * a different inode).
     */
    private function store(): AtomicJsonFile
    {
        return AtomicJsonFile::new($this->statePath);
    }

    private function lockPath(): string
    {
        return $this->statePath . '.lock';
    }

    /**
     * @return resource|false  Handle to hold the lock on, false on failure
     */
    private function acquireLock()
    {
        $path = $this->lockPath();
        $dir  = \dirname($path);
        if (!\is_dir($dir) && !@\mkdir($dir, 0700, true) && !\is_dir($dir)) {
            return false;
        }
        // 'cb': create if absent, never truncate — an existing lock keeps
        // its identity (and its waiters). @-suppressed because an
        // uncreateable parent directory is an expected operational
        // condition handled by the caller (and phpunit runs failOnWarning).
        // Audit 038 (TOCTOU family): a planted symlink at the sidecar name
        // would make fopen CREATE — and the chmod below — act on the link's
        // target. The lock is pure machinery, so this degrades through the
        // existing state-error path (the policy decides the outcome).
        if (\is_link($path)) {
            return false;
        }
        $fh = @\fopen($path, 'cb');
        if ($fh === false) {
            return false;
        }
        // Mode on the inode we opened, never through the name (audit 038,
        // second half — same no-symlink-hop reasoning as the Logger sink).
        StreamHelper::chmodOpenedFile($fh, $path, 0600);
        return $fh;
    }

    /**
     * @param resource $handle
     */
    private function releaseLock($handle): void
    {
        \flock($handle, LOCK_UN);
        \fclose($handle);
    }

    /**
     * Announce a state-layer degradation and return the fail-open/closed
     * decision: allow (true) unless `$failClosed`, in which case deny
     * (false). This replaces the previous silent fail-open.
     *
     * Deliberately path-free (audit K3): under `ForceCommand` this stream
     * reaches the connecting client, and an unauthenticated prober has no
     * business learning where the bucket map lives.
     */
    private function onStateError(string $why): bool
    {
        $notice = $this->failClosed
            ? Lang::t('ratelimit.unavailable_denying', ['why' => $why])
            : Lang::t('ratelimit.degraded_allowing', ['why' => $why]);

        \fwrite($this->stderr, $notice . "\n");

        return !$this->failClosed;
    }

    /**
     * @param array<string,array{tokens:float,last:float,burst?:int,rate?:float}> $data
     */
    private function writeBack(array $data): void
    {
        // AtomicJsonFile sets 0600 on the temp inode BEFORE the payload and
        // before the publishing rename (sugar-core a32c4faae), so the bucket
        // map — which reveals who has been connecting — is never briefly
        // world/group readable the way chmod-after-write allowed. Mutual exclusion is this class's sidecar lock, held by the
        // caller across the read that produced $data.
        try {
            $this->store()->withPermissions(0600)->write($data);
        } catch (\Throwable $e) {
            // The auth decision the caller computed stands — but saying
            // nothing here is how a throttled deployment quietly becomes an
            // un-throttled one, since the next process never sees this
            // consumption. Announce it (path-free, same law as
            // onStateError) instead of muting it.
            \fwrite(
                $this->stderr,
                Lang::t('ratelimit.state_lost', ['why' => \get_class($e)]) . "\n",
            );
        }
    }
}
