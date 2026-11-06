<?php

declare(strict_types=1);

namespace SugarCraft\Code\Workflows;

use SugarCraft\Code\Support\PrivateDir;
use SugarCraft\Code\Support\TimedFileLock;

/**
 * O3: host-resource locks for workflow stages.
 *
 * One exclusive flock per named resource, under a shared directory beside
 * the registry's workflows path (production: `~/.candy-code/locks`). GPUs,
 * benchmark batteries, anything a host has exactly one of, becomes
 * MECHANICAL serialization instead of convention: a second stage asking
 * for the same resource waits a bounded time through the package's one
 * {@see TimedFileLock} and then fails loudly, feeding the engine's ordinary
 * failed-stage handling — retry, pause and resume machinery included.
 *
 * Lock file names are a readable slug plus a sha1 fingerprint: two resource
 * names that collapse to the same slug ('gpu A' and 'gpu/A') still get
 * distinct files, and nobody has to invent machine ids to keep them apart.
 *
 * Release is TimedFileLock's own unlock plus fclose: the flock rides the
 * open file description, so a dead process's kernel bookkeeping means a
 * crashed run never strands the lock. (A forked worker that INHERITED the
 * descriptor shares the description and can extend its life until it exits
 * — which is why these locks are taken and released by the engine process,
 * around the dispatch, never inside workers.)
 */
final class StageResourceLocks
{
    private const FINGERPRINT_CHARS = 12;

    public function __construct(private readonly string $lockDir)
    {
    }

    /**
     * The lock path a resource name maps to. Public for the reason every
     * house lock helper is testable: a contender that wants to hold the
     * lock OUTSIDE the engine — a test, an operator's battery script — must
     * take exactly this file, not a lookalike.
     */
    public static function pathFor(string $lockDir, string $resource): string
    {
        $slug = preg_replace('/[^A-Za-z0-9._-]+/', '-', $resource) ?? $resource;

        return $lockDir . '/' . trim($slug, '-.')
            . '-' . substr(sha1($resource), 0, self::FINGERPRINT_CHARS) . '.lock';
    }

    /**
     * Take the exclusive lock for $resource, waiting at most $waitSeconds
     * (0 asks once and gives up — the loud fail-fast).
     *
     * @return resource the held handle; pass it back to {@see release()}
     *
     * @throws \RuntimeException the resource is still held after the wait
     *         (the message names the resource and the bound) or the lock
     *         file could not be opened at all
     */
    public function acquire(string $resource, float $waitSeconds)
    {
        PrivateDir::ensure($this->lockDir, 'workflow resource locks');

        $handle = fopen(self::pathFor($this->lockDir, $resource), 'c+');
        if ($handle === false) {
            throw new \RuntimeException(
                "Could not open the resource lock for '{$resource}' — the run cannot claim it",
            );
        }

        try {
            TimedFileLock::acquire($handle, \LOCK_EX, "workflow resource '{$resource}'", $waitSeconds);
        } catch (\Throwable $e) {
            fclose($handle);

            throw $e;
        }

        return $handle;
    }

    /**
     * Give the lock up. Idempotent by shape: a value that is no longer a
     * resource releases nothing twice.
     */
    public function release(mixed $handle): void
    {
        if (is_resource($handle)) {
            TimedFileLock::release($handle);
            fclose($handle);
        }
    }
}
