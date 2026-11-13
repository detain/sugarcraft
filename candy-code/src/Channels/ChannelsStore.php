<?php

declare(strict_types=1);

namespace SugarCraft\Code\Channels;

use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Core\Util\AtomicJsonFile;

/**
 * The on-disk record for plugin channels — the file ruling 4b named.
 *
 * `~/.candy-code/channels.json`, written only through
 * {@see SugarCraft\Core\Util\AtomicJsonFile} with permissions `0600`: the store
 * holds channel credentials and policy, never world-readable by construction.
 * The settings file carries exactly one channel concern — the
 * `trustedBundleChannels` name list — because a setting is something the doc
 * surface and the schema generator both render; secrets have no business
 * there. Everything else about channels lives here.
 *
 * Scope law (ruling D6): per-channel enablement, per-account configuration and
 * per-session rules all grow INTO this file. Nothing in it assumes one global
 * policy; the first shape minted is deliberately the smallest — the
 * quarantine ledger `BundleChannelLoader` consults, a set of entrypoint
 * paths already seen by an earlier launch, so a channel file new to this
 * machine is listed and held for one launch before any of its code executes.
 * The second grown-in shape is the `channels` map addressed by
 * {@see ChannelToolPolicyService}: per-channel tool policy with per-group and
 * per-sender overrides, keyed by the registry's own `bundle|name` granularity.
 * A policy layer reads the record fresh at every admission, so editing the
 * file lands on the next run without a restart.
 *
 * Corruption law (M-1, ChannelToolScope's fail-CLOSED rule at the source):
 * a malformed or unreadable record is NEVER degraded to an empty one. Every
 * policy read through {@see all()} throws with the file named, the resolver
 * turns that into a refusingAll scope for every channel session, and the
 * boot ledger write stays suppressed while the disk refuses to parse — a
 * record we cannot read is a record we must not overwrite. The door is
 * re-checked against disk at EVERY write, not frozen at construction (M-6).
 * A MISSING file is still the legitimate day-one empty state.
 *
 * One file, two writers (M-6): the Discord plugin's CredentialStore owns
 * pairing and credentials in these same bytes, so the boot ledger write is
 * a fresh-read merge — the quarantined path joins whatever the disk holds
 * at that moment, the discipline that store's mutate() promises. A pairing
 * row or policy edit landing between store construction and the boot write
 * SURVIVES it. The residual window — a write racing between this class's
 * read and its atomic rename — stays the loud-repair class the plugin
 * documents, never a silent widening of access. The lstat, ownership and
 * version guards themselves live plugin-side; this reader is loud on parse
 * and IO failure.
 */
final class ChannelsStore
{
    public const FILE_NAME = 'channels.json';

    /** @var \Closure():string resolves the store path without touching HOME mid-run */
    private \Closure $configDir;

    /** @var array<string, mixed> the record as of the most recent read() */
    private array $data;

    /** @var null|string why the most recent read failed; null while reads succeed */
    private ?string $readFailure = null;

    /**
     * @param (callable():string)|null $configDir overrides the directory the
     *                                            record lives in (tests point it at a temp tree)
     */
    private function __construct(?callable $configDir)
    {
        $this->configDir = null === $configDir
            ? static fn (): string => Bootstrap::trustedConfigDirPath()
            : \Closure::fromCallable($configDir);

        // A broken record never takes the whole launch down at construction —
        // the boot path has one job with these bytes (the quarantine ledger)
        // and it does that job FAIL CLOSED from an empty in-memory view:
        // every entrypoint answers "unseen", so this launch quarantines, and
        // recordEntrypoint() re-reads at write time and refuses while the
        // disk refuses to parse (M-6 — the door rides the write, not the
        // constructor), so the operator's corrupt bytes survive to be
        // repaired. The loud half
        // of the contract is all(): every policy read throws until the file
        // parses again, and {@see ChannelToolPolicyService} turns that throw
        // into a refusingAll scope — corruption restricts nothing, ever.
        try {
            $this->data = $this->read();
        } catch (\Throwable $e) {
            $this->data = [];
            $this->readFailure = $e->getMessage();
        }
    }

    public static function new(?callable $configDir = null): self
    {
        return new self($configDir);
    }

    /** @deprecated use {@see new()}; kept so out-of-tree callers keep working. */
    public static function create(?callable $configDir = null): self
    {
        return self::new($configDir);
    }

    /** Has an earlier launch already seen this entrypoint file? */
    public function seenEntrypoint(string $path): bool
    {
        $seen = $this->data['seenEntrypoints'] ?? null;

        return \is_array($seen) && true === ($seen[$path] ?? false);
    }

    /**
     * Record the entrypoint as seen. Called when it is FIRST noticed — the
     * quarantine holds it this launch and the next launch finds it here.
     * The mark is merged into the FRESH record on disk (M-6, one file two
     * writers): keys another writer landed since construction — pairing
     * rows, credentials, policy edits — survive this write; nothing but
     * the merged seen-map is ever written.
     */
    public function recordEntrypoint(string $path): void
    {
        $seen = \is_array($this->data['seenEntrypoints'] ?? null) ? $this->data['seenEntrypoints'] : [];
        $seen[$path] = true;

        try {
            $record = $this->read();
        } catch (\Throwable $e) {
            // The permanizing-write door (M-1), re-checked against disk at
            // write time (M-6): we never write a record we cannot currently
            // read — not one torn at construction and not one that rotted
            // since. The boot mark stays in memory for this launch's dedup,
            // the broken bytes stay on disk for the operator.
            $this->readFailure = $e->getMessage();
            $this->data['seenEntrypoints'] = $seen;

            return;
        }

        $fresh = \is_array($record['seenEntrypoints'] ?? null) ? $record['seenEntrypoints'] : [];
        $record['seenEntrypoints'] = $seen + $fresh;
        $this->data = $record;
        $this->readFailure = null;

        $this->write();
    }

    /** Why the most recent read failed, or null while reads succeed. */
    public function readFailure(): ?string
    {
        return $this->readFailure;
    }

    /**
     * The whole record, re-read from disk on every call so an edit to
     * channels.json lands on the next admission without a restart — the
     * property the class docblock promises and the policy resolver wired
     * through {@see ChannelToolPolicyService::forStore()} relies on.
     * Admissions are cold-path (one call per gate computation), so this
     * extra stat-and-read never rides a hot loop.
     *
     * @throws \RuntimeException When the record is present but unreadable or
     *                           malformed — the policy reader must hear about
     *                           it (fail closed), never receive a silent `[]`
     *                           that reads as "no policy unrestricted".
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        try {
            $this->data = $this->read();
        } catch (\Throwable $e) {
            $this->readFailure = $e->getMessage();

            throw $e;
        }

        $this->readFailure = null;

        return $this->data;
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        $path = $this->path();
        if (!\is_string($path)) {
            return [];
        }

        // AtomicJsonFile's loud read: a MISSING file is the day-one empty
        // state ([]), but garbage is an error. The wrapper guarantees every
        // failure names the file, so the diagnostic an operator or a refusal
        // message carries points at the thing to repair.
        try {
            return AtomicJsonFile::new($path)->read();
        } catch (\Throwable $e) {
            throw new \RuntimeException(self::FILE_NAME . ' at ' . $path . ' is unreadable: ' . $e->getMessage(), 0, $e);
        }
    }

    private function write(): void
    {
        $path = $this->path();
        if (!\is_string($path)) {
            return;
        }

        AtomicJsonFile::new($path)->withPermissions(0600)->write($this->data);
    }

    private function path(): ?string
    {
        $dir = ($this->configDir)();

        return '' === $dir ? null : $dir . '/' . self::FILE_NAME;
    }
}
