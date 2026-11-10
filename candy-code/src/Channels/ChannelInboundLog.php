<?php

declare(strict_types=1);

namespace SugarCraft\Code\Channels;

use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Memory\SecretRedactor;
use SugarCraft\Core\Util\AtomicJsonFile;

/**
 * The durable inbound-before-dispatch ledger for plugin channels — the
 * core guarantee spec §9.5 Track B names: "inbound-before-dispatch becomes
 * a core guarantee for channels exactly as SessionAdmission already provides
 * it for session.send".
 *
 * A channel that accepts an inbound event must never be able to lose it to a
 * crash, and must never be able to act on it twice. Both halves live here:
 *
 * - **Persist first.** {@see record()} returns RECORDED only after the row is
 *   on disk as pending (the same "durable events are written FIRST, so a
 *   broadcast can never get ahead of the log a replay reads" law
 *   {@see \SugarCraft\Code\Host\EventLog} enforces per session). A crash
 *   between persist and dispatch is replay-safe: the next boot finds the row
 *   pending and delivers it exactly once more.
 * - **Dedupe by key.** The caller's platform message id, namespaced by
 *   channel name (`channel|key`), is the idempotency key — the
 *   `TurnTicket::idempotencyKey` / IdempotencyCache posture applied to
 *   inbound traffic. First delivery wins: a re-send of an already-acked or
 *   still-pending id answers DUPLICATE, and a re-send of a dead-lettered one
 *   answers POISON, so a poisoned message can never hot-loop the dispatcher
 *   (dead-letter posture: {@see MAX_ATTEMPTS} attempts or
 *   {@see RETENTION_SECONDS} of age, whichever comes first).
 *
 * The store is `channels.inbound.json` beside {@see ChannelsStore}'s record —
 * 0600 via {@see AtomicJsonFile}, whose whole-file atomic rename means a
 * crash shows readers either the old or the new file, never a torn one. A
 * file that is present but unreadable is QUARANTINED (renamed with a stamped
 * suffix) and the ledger answers the RESET shape — the two-shapes law from
 * the subscribe/replay field fact: a consumer whose history cannot be
 * trusted must resync from zero and may re-deliver (at-least-once), but a
 * hole is never silently cursor-skipped as if it were a replay.
 *
 * Expiry math is stamp-compare, never a timer: like
 * {@see \SugarCraft\Code\Server\Auth\ExpiringSecrets} the class takes an
 * injectable clock closure and consults it lazily inside calls, so this file
 * arms nothing on the event loop (E646-clean — nothing here can become a
 * wall-clock bound on a serving path).
 *
 * Redaction-before-storage (§9.4): every string leaf of a payload passes the
 * core {@see SecretRedactor} plus a URL-credential sweep BEFORE any size cut
 * or write — the order law {@see \SugarCraft\Code\Hooks\HookLogEntry} learned
 * the hard way (cut first lets a secret straddling the boundary survive the
 * cut whole). Sign-in URL fragments, query credentials and the known secret
 * grammars never enter this file or any line it logs; the sweep is
 * defense-in-depth, and a channel still owns redacting its own payloads
 * before they arrive here.
 */
final class ChannelInboundLog
{
    public const FILE_NAME = 'channels.inbound.json';

    /** Dead-letter posture from spec §9.5: eight attempts, then the row is poison. */
    public const MAX_ATTEMPTS = 8;

    /** ...or twenty-four hours of age (stamp-compare), and it is dead-lettered. Also the dedupe window. */
    public const RETENTION_SECONDS = 86400;

    /** Total rows kept (pending rows are never pruned — see the saturation refusal). */
    public const MAX_RECORDS = 512;

    /** Serialized cap of one stored payload, applied AFTER redaction, never before. */
    public const MAX_PAYLOAD_BYTES = 16_384;

    /** record() outcomes. Only RECORDED means "dispatch now". */
    public const RECORDED = 'recorded';
    public const DUPLICATE = 'duplicate';
    public const POISON = 'poison';
    public const REFUSED_OVERSIZE = 'refused-oversize';
    public const REFUSED_SATURATED = 'refused-saturated';

    /** pending() shapes: the ledger is trusted, or the consumer must resync. */
    public const SHAPE_REPLAY = 'replay';
    public const SHAPE_RESET = 'reset';

    public const STATE_PENDING = 'pending';
    public const STATE_ACKED = 'acked';
    public const STATE_DEAD = 'dead';

    /** Addressable name/key bound — long enough for any platform message id, short enough to keep the file bounded. */
    public const MAX_PART_CHARS = 192;

    /**
     * @var \Closure():string resolves the ledger directory without touching HOME mid-run
     */
    private \Closure $configDir;

    /**
     * @var \Closure():int the stamp clock (injectable; consulted lazily, never on a timer)
     */
    private \Closure $clock;

    /**
     * @var array<string, array<string, mixed>> rows keyed by "channel|key"
     */
    private array $records = [];

    /**
     * @var \Closure(string):string cached redactor — one instance, pure function
     */
    private \Closure $redactLeaf;

    /** Set for the whole process life once a corrupt ledger has been moved aside. */
    private ?int $quarantinedAt = null;

    /**
     * @param (callable():string)|null $configDir overrides the directory the ledger lives in (tests point it at a temp tree)
     * @param (\Closure():int)|null    $clock     overrides the stamp clock for expiry math
     */
    private function __construct(?callable $configDir, ?\Closure $clock)
    {
        $this->configDir = null === $configDir
            ? static fn (): string => Bootstrap::trustedConfigDirPath()
            : \Closure::fromCallable($configDir);
        $this->clock = $clock ?? static fn (): int => \time();
        $redactor = SecretRedactor::new();
        $this->redactLeaf = static fn (string $leaf): string => self::sweepUrlCredentials($redactor->redact($leaf));
        $this->load();
    }

    public static function create(?callable $configDir = null, ?\Closure $clock = null): self
    {
        return new self($configDir, $clock);
    }

    /**
     * Persist an inbound event BEFORE the caller dispatches it.
     *
     * Returns RECORDED only after the pending row is on disk; every other
     * answer means "do not dispatch this copy" — DUPLICATE (first delivery
     * already owns this id), POISON (it dead-lettered and must never
     * hot-loop), REFUSED_* (the store stays bounded, loudly). Structural
     * garbage (empty or oversized name/key, non-array payload) throws —
     * that is a caller bug, and a silent false would read as a duplicate.
     *
     * @param array<string, mixed> $payload the channel's inbound body; redacted and size-capped before storage
     */
    public function record(string $channel, string $key, array $payload): string
    {
        $this->requireAddressable($channel, 'channel');
        $this->requireAddressable($key, 'key');

        $this->sweep();
        $id = self::id($channel, $key);
        $existing = $this->records[$id] ?? null;
        if (\is_array($existing)) {
            $state = $existing['state'] ?? self::STATE_PENDING;

            return self::STATE_DEAD === $state ? self::POISON : self::DUPLICATE;
        }

        $stored = $this->redactTree($payload);
        $encoded = json_encode($stored, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if (\strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
            return self::REFUSED_OVERSIZE;
        }

        if (self::pendingCount() >= self::MAX_RECORDS) {
            return self::REFUSED_SATURATED;
        }

        $now = $this->stamp();
        $this->records[$id] = [
            'channel' => $channel,
            'key' => $key,
            'state' => self::STATE_PENDING,
            'attempts' => 0,
            'firstSeen' => $now,
            'updatedAt' => $now,
            'payload' => $stored,
        ];
        // The guarantee itself: bytes land here, before this call can answer
        // RECORDED. A write failure throws and the caller never dispatches —
        // losing an event loudly beats losing it silently OR double-acting.
        $this->write();

        return self::RECORDED;
    }

    /**
     * The dispatch accepted this row. Terminal — a later noteFailure on the
     * same id cannot undo an ack (first terminal state wins).
     */
    public function markAcked(string $channel, string $key): void
    {
        $id = self::id($channel, $key);
        $row = $this->records[$id] ?? null;
        if (!\is_array($row) || self::STATE_PENDING !== ($row['state'] ?? null)) {
            return;
        }

        $row['state'] = self::STATE_ACKED;
        $row['ackedAt'] = $this->stamp();
        $row['updatedAt'] = $row['ackedAt'];
        $this->records[$id] = $row;
        $this->write();
    }

    /**
     * One delivery attempt of this row failed. The attempt count grows here
     * and the dead-letter door is checked here (stamps, never timers):
     * {@see MAX_ATTEMPTS} failures or {@see RETENTION_SECONDS} of total age
     * turn the row to dead, and POISON answers every later re-send.
     */
    public function noteFailure(string $channel, string $key): string
    {
        $id = self::id($channel, $key);
        $row = $this->records[$id] ?? null;
        if (!\is_array($row)) {
            return self::STATE_DEAD;
        }

        $state = $row['state'] ?? self::STATE_PENDING;
        if (self::STATE_PENDING !== $state) {
            return \is_string($state) ? $state : self::STATE_ACKED;
        }

        $now = $this->stamp();
        $attempts = (int) ($row['attempts'] ?? 0) + 1;
        $row['attempts'] = $attempts;
        $row['updatedAt'] = $now;
        if ($attempts >= self::MAX_ATTEMPTS || $now - (int) ($row['firstSeen'] ?? $now) >= self::RETENTION_SECONDS) {
            $row['state'] = self::STATE_DEAD;
            $row['deadAt'] = $now;
        }
        $this->records[$id] = $row;
        $this->write();

        return $row['state'];
    }

    /**
     * What a restart must deliver: shape + pending rows in arrival order.
     *
     * SHAPE_REPLAY means the ledger survived intact and the set is complete.
     * SHAPE_RESET means an earlier corrupt file was quarantined this boot —
     * rows listed after it are honest, but the pre-quarantine pending set is
     * gone with it, so a consumer must resync from zero (accept platform
     * re-delivery through the dedupe door) and must NEVER treat the missing
     * history as a cursor it may skip forward over. Two shapes, one law.
     *
     * @return array{shape: string, records: list<array<string, mixed>>}
     */
    public function pending(): array
    {
        $this->sweep();

        $rows = [];
        foreach ($this->records as $row) {
            if (self::STATE_PENDING === ($row['state'] ?? null)) {
                $rows[] = $row;
            }
        }
        \usort($rows, static fn (array $a, array $b): int => [(int) $a['firstSeen'], (string) $a['key']] <=> [(int) $b['firstSeen'], (string) $b['key']]);

        return [
            'shape' => null === $this->quarantinedAt ? self::SHAPE_REPLAY : self::SHAPE_RESET,
            'records' => $rows,
        ];
    }

    /**
     * The stamp of a quarantine that happened THIS boot, or null when the
     * ledger loaded intact. Sticky for the process life on purpose: the
     * incomplete-history fact does not heal mid-boot, and the announce rides
     * it exactly once.
     */
    public function quarantinedAt(): ?int
    {
        return $this->quarantinedAt;
    }

    public function path(): string
    {
        return $this->directory() . '/' . self::FILE_NAME;
    }

    /** @return array<string, array<string, mixed>> the raw rows (test/introspection surface) */
    public function rows(): array
    {
        return $this->records;
    }

    // ---- internals ----

    /** Load the ledger; an unreadable file is quarantined, never read as empty. */
    private function load(): void
    {
        $file = $this->file();
        if (!$file->exists()) {
            return;
        }

        try {
            $decoded = $file->read();
        } catch (\Throwable) {
            $this->quarantine();

            return;
        }

        $records = $decoded['records'] ?? null;
        if (1 !== ($decoded['version'] ?? null) || !\is_array($records)) {
            $this->quarantine();

            return;
        }

        // Rows that fail the shape check are dropped silently ON PURPOSE: a
        // whole-file failure quarantines loudly above, but a row with no
        // string channel/key is not addressable — there is no id to name in a
        // loud refusal — and its absence from the replay set is where the
        // drop stays visible to the consumer (the EventLog precedent).
        foreach ($records as $id => $row) {
            if (\is_string($id) && \is_array($row) && \is_string($row['channel'] ?? null) && \is_string($row['key'] ?? null)) {
                $this->records[$id] = $row;
            }
        }
    }

    /**
     * Move a corrupt ledger aside under a stamped name. The bytes stay
     * available for an operator; this process starts from an honest empty
     * set and answers RESET forever after. A pre-existing corral at the same
     * stamp is never overwritten — the corrupt bytes are preserved with a
     * distinct suffix, because renaming onto an existing file would destroy
     * the forensic copy an earlier quarantine (or a clock collision) left.
     */
    private function quarantine(): void
    {
        $this->records = [];
        $this->quarantinedAt = $this->stamp();
        $path = $this->path();
        $corral = \dirname($path) . '/' . self::FILE_NAME . '.corral-' . $this->quarantinedAt;
        $suffix = 1;
        while (\file_exists($corral)) {
            ++$suffix;
            $corral = \dirname($path) . '/' . self::FILE_NAME . '.corral-' . $this->quarantinedAt . '.' . $suffix;
        }
        @rename($path, $corral);
    }

    /**
     * Lazy maintenance on call, never on a timer (E646): age out pending
     * rows to dead, prune acked/dead rows past the window, and shed the
     * oldest terminal rows past the cap — pending rows are never dropped,
     * which is what keeps "never lose" true while the file stays bounded.
     */
    private function sweep(): void
    {
        $now = $this->stamp();
        $changed = false;

        foreach ($this->records as $id => $row) {
            $state = $row['state'] ?? null;
            if (self::STATE_PENDING === $state && $now - (int) ($row['firstSeen'] ?? $now) >= self::RETENTION_SECONDS) {
                $row['state'] = self::STATE_DEAD;
                $row['deadAt'] = $now;
                $this->records[$id] = $row;
                $changed = true;

                continue;
            }
            $since = self::STATE_ACKED === $state
                ? $now - (int) ($row['ackedAt'] ?? $row['updatedAt'] ?? $now)
                : (self::STATE_DEAD === $state ? $now - (int) ($row['deadAt'] ?? $row['updatedAt'] ?? $now) : -1);
            if ($since >= self::RETENTION_SECONDS) {
                unset($this->records[$id]);
                $changed = true;
            }
        }

        if (\count($this->records) > self::MAX_RECORDS) {
            foreach ([self::STATE_ACKED, self::STATE_DEAD] as $shed) {
                \uasort($this->records, static fn (array $a, array $b): int => (int) ($a['updatedAt'] ?? 0) <=> (int) ($b['updatedAt'] ?? 0));
                foreach (array_keys($this->records) as $id) {
                    if (\count($this->records) <= self::MAX_RECORDS) {
                        break 2;
                    }
                    if ($shed === ($this->records[$id]['state'] ?? null)) {
                        unset($this->records[$id]);
                        $changed = true;
                    }
                }
            }
        }

        if ($changed) {
            $this->write();
        }
    }

    /** @return int the number of currently-pending rows */
    private function pendingCount(): int
    {
        $count = 0;
        foreach ($this->records as $row) {
            if (self::STATE_PENDING === ($row['state'] ?? null)) {
                ++$count;
            }
        }

        return $count;
    }

    private function write(): void
    {
        $this->file()->withPermissions(0600)->write([
            'version' => 1,
            'records' => $this->records,
        ]);
    }

    private function file(): AtomicJsonFile
    {
        return AtomicJsonFile::new($this->path());
    }

    private function directory(): string
    {
        $dir = ($this->configDir)();

        if ('' === $dir) {
            // Same posture as ChannelsStore's guard: a launch with no trusted
            // config dir has nowhere durable to write, and pretending
            // otherwise would make record() answer RECORDED about bytes that
            // exist nowhere. Fail loudly instead.
            throw new \RuntimeException('channel inbound log needs a trusted config directory');
        }

        return $dir;
    }

    private function stamp(): int
    {
        return (int) ($this->clock)();
    }

    private function requireAddressable(string $value, string $what): void
    {
        if ('' === trim($value)) {
            throw new \InvalidArgumentException('channel inbound key needs a non-empty ' . $what);
        }
        if (\strlen($value) > self::MAX_PART_CHARS) {
            throw new \InvalidArgumentException('channel inbound ' . $what . ' exceeds ' . self::MAX_PART_CHARS . ' characters');
        }
    }

    private static function id(string $channel, string $key): string
    {
        return $channel . '|' . $key;
    }

    /**
     * Redact every string leaf recursively. Runs before the size cap, in
     * this order, never the other way round.
     *
     * @param array<array-key, mixed> $tree
     *
     * @return array<array-key, mixed>
     */
    private function redactTree(array $tree): array
    {
        foreach ($tree as $leaf => $value) {
            if (\is_array($value)) {
                /** @var array<array-key, mixed> $value */
                $tree[$leaf] = $this->redactTree($value);
            } elseif (\is_string($value)) {
                $tree[$leaf] = ($this->redactLeaf)($value);
            }
        }

        return $tree;
    }

    /**
     * Sweep the URL-shaped credential carriers the secret-grammar pass
     * cannot pattern-match: a login code rides the fragment of a sign-in
     * URL by design ("the log gets the address, never the sign-in code"),
     * and query parameters named code/token/key/secret/password carry
     * values that must never land on disk.
     */
    private static function sweepUrlCredentials(string $leaf): string
    {
        $swept = \preg_replace('#(https?://[^\s"\'<>\#]*)\#[^\s"\'<>]*#', '$1#' . SecretRedactor::MARKER, $leaf);
        $leaf = \is_string($swept) ? $swept : $leaf;

        $swept = \preg_replace('~([?&])(code|token|key|secret|password|passwd|pwd)=[^&#\s]*~i', '$1$2=' . SecretRedactor::MARKER, $leaf);

        return \is_string($swept) ? $swept : $leaf;
    }
}
