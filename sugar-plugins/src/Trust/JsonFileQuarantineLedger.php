<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Trust;

use SugarCraft\Core\Util\AtomicJsonFile;

/**
 * A {@see QuarantineLedger} kept under one key of a JSON file, written 0600.
 *
 * The file is shared with other writers (a host keeps credentials and policy
 * in the same bytes), so the write is a fresh-read merge: the mark joins
 * whatever the disk holds at that moment and no other key is touched.
 *
 * Corruption fails closed: a record that cannot be read is never overwritten.
 * The mark stays in memory for this launch's dedupe, the broken bytes stay on
 * disk for the operator, and {@see readFailure()} says why.
 */
final class JsonFileQuarantineLedger implements QuarantineLedger
{
    /** @var array<string, mixed> the record as of the most recent read */
    private array $data;

    /** @var null|string why the most recent read or write was refused; null while reads succeed */
    private ?string $readFailure = null;

    private function __construct(
        private readonly string $path,
        private readonly string $key,
    ) {
        // A broken record never takes the launch down at construction: every
        // entrypoint answers "unseen", so this launch quarantines, and record()
        // re-reads at write time and refuses while the disk refuses to parse.
        try {
            $this->data = $this->read();
        } catch (\Throwable $e) {
            $this->data = [];
            $this->readFailure = $e->getMessage();
        }
    }

    public static function new(string $path, string $key = 'seenEntrypoints'): self
    {
        return new self($path, $key);
    }

    public function seen(string $path): bool
    {
        $seen = $this->data[$this->key] ?? null;

        return \is_array($seen) && true === ($seen[$path] ?? false);
    }

    public function record(string $path): void
    {
        $seen = \is_array($this->data[$this->key] ?? null) ? $this->data[$this->key] : [];
        $seen[$path] = true;

        try {
            $record = $this->read();
        } catch (\Throwable $e) {
            // Never write a record we cannot read back: the broken bytes stay
            // on disk for repair, and the mark lives on for this launch only.
            $this->readFailure = $e->getMessage();
            $this->data[$this->key] = $seen;

            return;
        }

        $fresh = \is_array($record[$this->key] ?? null) ? $record[$this->key] : [];
        $record[$this->key] = $seen + $fresh;
        $this->data = $record;
        $this->readFailure = null;

        AtomicJsonFile::new($this->path)->withPermissions(0600)->write($this->data);
    }

    /** Why the most recent read failed, or null while reads succeed. */
    public function readFailure(): ?string
    {
        return $this->readFailure;
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        // A missing file is the day-one empty state; garbage is an error that
        // names the file, so the diagnostic points at the thing to repair.
        try {
            return AtomicJsonFile::new($this->path)->read();
        } catch (\Throwable $e) {
            throw new \RuntimeException('quarantine ledger at ' . $this->path . ' is unreadable: ' . $e->getMessage(), 0, $e);
        }
    }
}
