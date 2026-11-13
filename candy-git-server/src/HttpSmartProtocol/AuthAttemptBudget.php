<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\HttpSmartProtocol;

/**
 * File-backed token budget for HTTP Basic authentication attempts
 * (finding 008).
 *
 * Every Basic-auth refusal deliberately pays a full bcrypt verify (the
 * decoy that closes the user-enumeration timing oracle), so an unbounded
 * attempt stream is an unbounded CPU bill: a trickle of garbage
 * credentials can saturate every seat with hashing and stall real clones.
 * This class answers one question before any hashing happens: may this
 * attempt be paid for at all? Two dimensions are charged together —
 * the peer (one client cannot spray across many usernames for free) and
 * the username (many clients cannot hammer one account for free). An
 * exhausted bucket refuses with a Retry-After, at zero bcrypt cost.
 *
 * WHY THE STATE LIVES ON DISK: the worker-per-request pool (owner
 * ruling 8b) serves every connection in a forked process that exits
 * when the connection ends, so an in-process table would reset per
 * request and police nothing. One small JSON file under the data
 * directory is the shared ledger every worker sees; a full exclusive
 * lock spans its read-modify-write because workers are concurrent
 * writers by construction.
 *
 * The annotation-era design pruned stale buckets from the listener's
 * 0.25 s sweep; under the worker architecture no such sweep outlives a
 * request, so pruning rides the write path instead: a bucket whose
 * tokens are back at capacity is deleted (absence means full), and the
 * whole file is capped at self::MAX_TRACKED buckets, oldest-updated
 * first out. State is therefore bounded and self-cleaning without a
 * daemon.
 *
 * FAIL-OPEN ON IO ERRORS: this budget is a hardening layer, not an
 * authentication mechanism — losing it to a full disk or a missing
 * directory must degrade to "server refuses to police guesses", never
 * to "nobody can log in". Every filesystem step below is therefore
 * error-suppressed and treated as "no signal".
 *
 * Lockout semantics are the standard trade-off of a username-keyed
 * budget: a peer who exhausts a username bucket also locks that account
 * out for the refill window (correct credentials are refused 429 without
 * being verified — the refusal must stay free of bcrypt for the DoS fix
 * to hold). The window is bounded by config and short by default.
 *
 * Known limit (disclosed): a distributed botnet rotating both source IP
 * and username sees a fresh pair of full buckets per proxy it owns;
 * absolute CPU under that shape is bounded only by the worker capacity
 * (64 seats). Defeating coordinated swarms is infrastructure work
 * (upstream proxy, fail2ban), not this in-server ledger.
 */
final class AuthAttemptBudget
{
    /**
     * Upper bound on tracked buckets. Each entry is a short fixed-shape
     * line; 4096 is ~100 KiB of state — generous against real traffic,
     * cheap against churn, and the eviction floor that keeps an attacker
     * minting fresh username keys from inflating the file without limit.
     */
    public const MAX_TRACKED = 4096;

    private readonly \Closure $clock;

    /**
     * @param string $stateFile   shared JSON ledger (created 0600 on first charge)
     * @param int    $capacity    tokens a fresh bucket carries (burst size)
     * @param int    $refillSeconds seconds for a spent bucket to regain ONE full capacity
     * @param (callable(): float)|null $clock test seam for the refill math
     */
    public function __construct(
        private readonly string $stateFile,
        private readonly int $capacity,
        private readonly int $refillSeconds,
        ?callable $clock = null,
    ) {
        $this->clock = $clock === null
            ? static fn (): float => \microtime(true)
            : \Closure::fromCallable($clock);
    }

    /**
     * Charge one authentication attempt against the peer AND username
     * buckets. Returns null when the attempt may proceed (tokens were
     * consumed), or the seconds the client must wait when either bucket
     * is empty — in which case nothing was consumed and nothing hashed.
     */
    public function charge(string $peer, string $username): ?int
    {
        return $this->transact(
            fn (array $buckets, float $now): array => $this->applyCharge($buckets, $now, $peer, $username)
        )['denied'];
    }

    /**
     * Give the token back after a verify actually succeeded: correct
     * credentials must never drain a bucket, or a long clone session
     * (which re-sends Basic auth per request) would rate-limit itself.
     * A verify that succeeded but yields no identity (deactivated
     * account) also counts as honest work paid for, and refunds.
     */
    public function refund(string $peer, string $username): void
    {
        $this->transact(
            fn (array $buckets, float $now): array => $this->applyRefund($buckets, $now, $peer, $username)
        );
    }

    /**
     * One locked read-modify-write. The mutator returns
     * [deniedResult, buckets, changed]; an IO failure anywhere returns
     * the fail-open shape (allowed, no state) instead of throwing —
     * see the class docblock.
     *
     * @param callable(array<array{0:float,1:float}>, float): array{0:?int,1:array,2:bool} $mutate
     * @return array{denied: ?int, ok: bool}
     */
    private function transact(callable $mutate): array
    {
        $failOpen = ['denied' => null, 'ok' => false];

        $dir = \dirname($this->stateFile);
        if (!\is_dir($dir) && !@\mkdir($dir, 0700, true) && !\is_dir($dir)) {
            return $failOpen;
        }

        $handle = @\fopen($this->stateFile, 'c+');
        if ($handle === false) {
            return $failOpen;
        }
        if (!@\flock($handle, \LOCK_EX)) {
            @\fclose($handle);

            return $failOpen;
        }

        try {
            $raw = \stream_get_contents($handle);
            $buckets = $this->decode($raw === false ? '' : $raw);
            [$denied, $buckets, $changed] = $mutate($buckets, ($this->clock)());

            if ($changed && !$this->write($handle, $buckets)) {
                return ['denied' => $denied, 'ok' => false];
            }

            return ['denied' => $denied, 'ok' => true];
        } finally {
            @\flock($handle, \LOCK_UN);
            @\fclose($handle);
        }
    }

    /**
     * @param array<array{0:float, 1:float}> $buckets
     * @return array{0: ?int, 1: array<array{0:float, 1:float}>, 2: bool}
     */
    private function applyCharge(array $buckets, float $now, string $peer, string $username): array
    {
        $keys = self::bucketKeys($peer, $username);

        $deniedFor = 0;
        $available = [];
        foreach ($keys as $key) {
            $tokens = $this->tokensAt($buckets, $key, $now);
            if ($tokens < 1.0) {
                // refillSeconds spans ONE full capacity, so one token is
                // worth refillSeconds/capacity seconds of waiting.
                $wait = (int) ceil((1.0 - $tokens) * $this->refillSeconds / $this->capacity);
                $deniedFor = \max($deniedFor, \max(1, $wait));
                continue;
            }
            $available[$key] = $tokens;
        }

        // A denial consumes nothing: the refused request never ran bcrypt,
        // so billing it would punish the NEXT honest attempt for the
        // attacker's timing.
        if ($deniedFor > 0) {
            return [$deniedFor, $buckets, false];
        }

        foreach ($keys as $key) {
            $buckets[$key] = [(float) $available[$key] - 1.0, $now];
        }

        return [null, $this->prune($buckets, $now), true];
    }

    /**
     * @param array<array{0:float, 1:float}> $buckets
     * @return array{0: ?int, 1: array<array{0:float, 1:float}>, 2: bool}
     */
    private function applyRefund(array $buckets, float $now, string $peer, string $username): array
    {
        $changed = false;
        foreach (self::bucketKeys($peer, $username) as $key) {
            $tokens = $this->tokensAt($buckets, $key, $now);
            if ($tokens >= $this->capacity) {
                // Already at (or above) full — a stale record can only
                // mean the bucket never fell; deleting it is the no-op
                // that keeps absence meaning "full".
                if (isset($buckets[$key])) {
                    unset($buckets[$key]);
                    $changed = true;
                }

                continue;
            }
            $buckets[$key] = [$tokens + 1.0, $now];
            $changed = true;
        }

        return [null, $this->prune($buckets, $now), $changed];
    }

    /**
     * Tokens a bucket holds right now: an absent bucket is full, a stored
     * bucket regains refillSeconds/capacity tokens per second elapsed
     * since its last write, capped at capacity.
     *
     * @param array<array{0:float, 1:float}> $buckets
     */
    private function tokensAt(array $buckets, string $key, float $now): float
    {
        if (!isset($buckets[$key])) {
            return (float) $this->capacity;
        }
        [$stored, $updated] = $buckets[$key];
        if (!\is_float($stored) || !\is_float($updated) || $updated > $now) {
            // Clock jump backwards (NTP, fork-time copy) or a hand-edited
            // file: treat as full rather than punish, the ledger is
            // advisory state, not a security boundary by itself.
            return (float) $this->capacity;
        }

        return \min((float) $this->capacity, $stored + ($now - $updated) * $this->capacity / $this->refillSeconds);
    }

    /**
     * Delete refilled-to-full buckets (absence IS full) and enforce the
     * tracked ceiling by evicting the least-recently-updated entries.
     *
     * @param array<array{0:float, 1:float}> $buckets
     * @return array<array{0:float, 1:float}>
     */
    private function prune(array $buckets, float $now): array
    {
        foreach ($buckets as $key => $entry) {
            if ($this->tokensAt($buckets, (string) $key, $now) >= $this->capacity) {
                unset($buckets[$key]);
            }
        }

        if (\count($buckets) <= self::MAX_TRACKED) {
            return $buckets;
        }

        // arsort on the updated field: keep the freshest MAX_TRACKED.
        \uasort($buckets, static fn (array $a, array $b): int => $b[1] <=> $a[1]);

        return \array_slice($buckets, 0, self::MAX_TRACKED, true);
    }

    /**
     * The two dimensions charged together. The username is hashed into
     * the key so a hostile Authorization header (arbitrary bytes, any
     * length) can never bloat the file past its fixed-width keys.
     *
     * @return array{0: string, 1: string}
     */
    private static function bucketKeys(string $peer, string $username): array
    {
        return [
            'peer:' . \strtolower($peer),
            'user:' . \hash('sha256', \strtolower($username)),
        ];
    }

    /**
     * @return array<array{0:float, 1:float}>
     */
    private function decode(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $decoded = \json_decode($raw, true);
        if (!\is_array($decoded)) {
            return []; // torn or hand-corrupted file: fresh start (fail-open)
        }

        $buckets = [];
        foreach ($decoded as $key => $entry) {
            if (
                \is_string($key) && \is_array($entry) && \array_key_exists(0, $entry) && \array_key_exists(1, $entry)
                && \is_numeric($entry[0]) && \is_numeric($entry[1])
            ) {
                $buckets[$key] = [(float) $entry[0], (float) $entry[1]];
            }
        }

        return $buckets;
    }

    /**
     * @param resource $handle locked, positioned after the read
     * @param array<array{0:float, 1:float}> $buckets
     */
    private function write($handle, array $buckets): bool
    {
        $json = \json_encode($buckets);
        if ($json === false) {
            return false;
        }
        if (@\ftruncate($handle, 0) === false || @\rewind($handle) === false) {
            return false;
        }
        if (@\fwrite($handle, $json) !== \strlen($json)) {
            return false;
        }
        @\fflush($handle);
        @\chmod($this->stateFile, 0600); // the ledger lists usernames and peers

        return true;
    }
}
