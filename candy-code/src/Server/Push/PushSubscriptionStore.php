<?php

declare(strict_types=1);

namespace SugarCraft\Code\Server\Push;

use SugarCraft\Code\Server\StateDir;
use SugarCraft\Code\Support\Log;
use SugarCraft\Code\Support\PrivateRetainedDir;

/**
 * The Web Push subscription rows (C-WEB-5.d): `push.json` in the server's
 * state directory, written through the house {@see PrivateRetainedDir}
 * discipline — lstat the `.partial`, umask 077 so the mode settles 0600
 * BEFORE the rename publishes — exactly like {@see Auth\SessionFileStore}
 * keeps `sessions.json`.
 *
 * THE ENDPOINT IS A BEARER SECRET. Anyone holding a push URL can post
 * ciphertext to that browser subscription, which is why the file carries
 * 0600 owner-only like every other credential in the state dir, and why the
 * rows are keyed by `sha256(endpoint)`: the map key a future audit or repair
 * pass sees is a digest, and the endpoint itself appears only inside the
 * encrypted-at-rest-by-file-mode body, never as a path, filename, or log
 * line this class writes.
 *
 * OWNED BY THE TOKEN PRINCIPAL. Each row records the {@see \SugarCraft\Code\Protocol\Client}
 * principal class that subscribed it (`bearer`, `cookie`, ...). A row is only
 * ever removed or pruned for its own principal — `push.unsubscribe` from one
 * credential class can never silently drop another's rows, and
 * {@see prunePrincipal()} is the store side of "revoking a token drops its
 * rows": whoever retires a credential calls this with the class it held.
 * Today every write-scope credential is an owner peer, so the trigger fan-out
 * spans all rows; the per-row principal is what makes a later multi-user
 * serve able to narrow that without a schema change.
 *
 * RE-VALIDATED AT LOAD. A row whose endpoint or keys no longer satisfy
 * {@see PushSubscription::new} — tampered, truncated, or written by a future
 * shape this build refuses — is dropped on load, never dialed. Like the
 * session store, a state directory that cannot be verified or a file that
 * cannot be parsed costs only durability: reads answer empty and writes
 * answer false. The server keeps serving; subscriptions re-establish from
 * the browser's own retry (the protocol's `push.subscribe` is idempotent by
 * key).
 */
final class PushSubscriptionStore
{
    private const LABEL = 'server state';

    private const KEY_ROWS = 'subscriptions';

    /**
     * Rows the store will hold. One browser subscription per tab of every
     * device the owner signs up is legitimate; an unbounded push.json is
     * neither — it grows the fan-out of every trigger and rewrites a bigger
     * file on every put. Eviction is oldest-registration-first: a replaced
     * endpoint keeps its position (registration order, not last-use), and
     * the policy is honest because the class docblock's own recovery path
     * covers it — a browser whose row was evicted re-subscribes on its own,
     * `push.subscribe` being idempotent by key. Newest intent survives;
     * abandoned registrations are what goes.
     */
    private const MAX_ROWS = 64;

    /** @var array<string, array{endpoint: string, p256dh: string, auth: string, principal: string, name: bool}>|null loaded rows, dirty only through this instance's writes */
    private ?array $rows = null;

    /**
     * @param (\Closure(): string) $pathResolver the file path, throwing RuntimeException when the dir is unusable
     * @param (\Closure(string): void) $log one-line sink for degradations the client never sees
     */
    private function __construct(
        private readonly \Closure $pathResolver,
        private readonly \Closure $log,
    ) {
    }

    /** @param (\Closure(string): void)|null $log */
    public static function forStateDir(string $dir, ?\Closure $log = null): self
    {
        return new self(
            static fn (): string => PrivateRetainedDir::verified($dir, self::LABEL) . '/' . StateDir::PUSH_STORE_FILE,
            $log ?? static function (string $line): void { Log::raw($line); },
        );
    }

    /** The map key of an endpoint: its sha256, hex. A public function so the unsubscribe side and the file agree without ever storing the raw endpoint as a key. */
    public static function keyHash(string $endpoint): string
    {
        return \hash('sha256', $endpoint);
    }

    /**
     * Store (or replace) $subscription's row under $principal. True only when
     * the disk now holds it; an unusable state directory costs durability,
     * never the connection — the client's own retry re-subscribes.
     *
     * There is deliberately no write coalescing here: the answer "the disk
     * now holds it" is what makes the method worth calling, and deferring
     * the rewrite would turn every success line into a crash-window lie.
     * The per-put rewrite is paid for by {@see self::MAX_ROWS} keeping the
     * file small instead.
     */
    public function put(PushSubscription $subscription, string $principal, bool $withName): bool
    {
        $rows = $this->load();
        $rows[self::keyHash($subscription->endpoint)] = [
            ...$subscription->toArray(),
            'principal' => $principal,
            'name' => $withName,
        ];
        while (\count($rows) > self::MAX_ROWS) {
            unset($rows[\array_key_first($rows)]);
        }

        return $this->persist($rows);
    }

    /**
     * Drop the row for $endpoint, but ONLY when it belongs to $principal —
     * one credential class unregistering an endpoint can never drop another
     * class's row (a cross-principal delete is the leak-shaped bug this gate
     * exists to refuse). True when a row was removed.
     */
    public function remove(string $endpoint, string $principal): bool
    {
        $rows = $this->load();
        $key = self::keyHash($endpoint);
        if (($rows[$key]['principal'] ?? null) !== $principal) {
            return false;
        }
        unset($rows[$key]);

        return $this->persist($rows);
    }

    /**
     * Drop every row owned by $principal and report how many went — the
     * store side of token revocation. Called with a principal whose
     * credential is retired (rotated, logged out, revoked), it leaves that
     * class nothing to notify.
     */
    public function prunePrincipal(string $principal): int
    {
        $rows = $this->load();
        $kept = array_filter($rows, static fn (array $row): bool => $row['principal'] !== $principal);
        $dropped = \count($rows) - \count($kept);
        if ($dropped === 0) {
            return 0;
        }

        return $this->persist($kept) ? $dropped : 0;
    }

    /**
     * Every live row, each re-validated by {@see PushSubscription::new} at
     * load. Rows a later trigger sends to are exactly these.
     *
     * @return array<string, array{subscription: PushSubscription, principal: string, withName: bool}>
     */
    public function entries(): array
    {
        $out = [];
        foreach ($this->load() as $key => $row) {
            try {
                $subscription = PushSubscription::new($row['endpoint'], $row['p256dh'], $row['auth']);
            } catch (\InvalidArgumentException) {
                continue;
            }
            $out[$key] = [
                'subscription' => $subscription,
                'principal' => $row['principal'],
                'withName' => $row['name'] === true,
            ];
        }

        return $out;
    }

    /**
     * The rows on disk: parsed and stripped of anything malformed. A file
     * that disagrees with its own shape is treated as absent.
     *
     * @return array<string, array{endpoint: string, p256dh: string, auth: string, principal: string, name: bool}>
     */
    private function load(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        try {
            $path = ($this->pathResolver)();
        } catch (\RuntimeException) {
            $this->rows = [];

            return $this->rows;
        }

        $raw = @\lstat($path) !== false && \is_file($path) && !\is_link($path)
            ? (string) @\file_get_contents($path)
            : '';
        /** @var array<string, mixed>|null $decoded */
        $decoded = $raw === '' ? null : \json_decode($raw, true, 8);
        if (!\is_array($decoded) || !\is_array($decoded[self::KEY_ROWS] ?? null)) {
            $this->rows = [];

            return $this->rows;
        }

        $rows = [];
        $dropped = 0;
        foreach ($decoded[self::KEY_ROWS] as $key => $row) {
            if (!\is_string($key) || !\preg_match('/^[0-9a-f]{64}$/', $key) || !\is_array($row)
                || !(isset($row['endpoint']) && isset($row['p256dh']) && isset($row['auth']) && isset($row['principal']) && isset($row['name']))
                || !\is_string($row['endpoint']) || !\is_string($row['p256dh'])
                || !\is_string($row['auth']) || !\is_string($row['principal'])
                || \hash('sha256', $row['endpoint']) !== $key) {
                $dropped++;
                continue;
            }
            // The shape gate is the VO's own: an endpoint or key this build
            // would refuse to dial has no business sitting in a loaded row.
            try {
                PushSubscription::new($row['endpoint'], $row['p256dh'], $row['auth']);
            } catch (\InvalidArgumentException) {
                $dropped++;
                continue;
            }
            $rows[$key] = [
                'endpoint' => $row['endpoint'],
                'p256dh' => $row['p256dh'],
                'auth' => $row['auth'],
                'principal' => $row['principal'],
                'name' => $row['name'] === true,
            ];
        }
        if ($dropped > 0) {
            $this->persist($rows);
        }
        $this->rows = $rows;

        return $rows;
    }

    /**
     * Publish $rows and answer whether the disk now holds them. Swallows the
     * RuntimeExceptions of an unusable state directory, like the session
     * store: durability is a courtesy, and a push subscription the browser
     * will re-establish is worth less than a request that fails on an I/O
     * accident.
     *
     * @param array<string, array{endpoint: string, p256dh: string, auth: string, principal: string, name: bool}> $rows
     */
    private function persist(array $rows): bool
    {
        try {
            $dir = \dirname(($this->pathResolver)());
            $encoded = \json_encode([self::KEY_ROWS => $rows], \JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                return false;
            }
        } catch (\RuntimeException) {
            return false;
        }

        try {
            PrivateRetainedDir::write($dir, StateDir::PUSH_STORE_FILE, $encoded . "\n", self::LABEL);
        } catch (\RuntimeException $failure) {
            $this->logLine('push durability lost: ' . $failure->getMessage());

            return false;
        }

        $this->rows = $rows;

        return true;
    }

    private function logLine(string $line): void
    {
        ($this->log)($line);
    }
}
