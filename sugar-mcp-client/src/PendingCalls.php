<?php

declare(strict_types=1);

namespace SugarCraft\McpClient;

/**
 * The in-flight request table of a JSON-RPC client: register a settle
 * callback under an id, settle it when the reply arrives, fail the whole
 * table when the transport dies.
 *
 * Promise-library-neutral on purpose: the callback is
 * `fn(?\Throwable $failure, mixed $result): void`, so a ReactPHP consumer
 * wraps a Deferred, a synchronous one stores the outcome. The transport and
 * the unknown-id / duplicate-id policy stay the consumer's.
 *
 * Every settle path removes the entry BEFORE invoking the callback, so a
 * callback that re-enters (registers, settles, fails) sees a consistent
 * table, and nothing settles twice.
 */
final class PendingCalls
{
    /** @var array<string, array{0: \Closure(?\Throwable, mixed): void, 1: bool}> */
    private array $calls = [];

    /**
     * @param \Closure(?\Throwable, mixed): void $settle
     * @param bool $preserving caller's flag, read back by {@see isPreserving()}
     *        (e.g. "decode the result as objects"); never interpreted here
     *
     * @throws \LogicException when $id is already in flight — a silently
     *         replaced entry would leave the first caller waiting forever
     */
    public function register(string $id, \Closure $settle, bool $preserving = false): void
    {
        if (isset($this->calls[$id])) {
            throw new \LogicException("request id {$id} is already pending");
        }
        $this->calls[$id] = [$settle, $preserving];
    }

    public function has(string $id): bool
    {
        return isset($this->calls[$id]);
    }

    public function isPreserving(string $id): bool
    {
        return $this->calls[$id][1] ?? false;
    }

    public function count(): int
    {
        return count($this->calls);
    }

    /** Deliver a successful result; false when $id is not pending. */
    public function settle(string $id, mixed $result): bool
    {
        return $this->finish($id, null, $result);
    }

    /** Deliver a failure for one id; false when $id is not pending. */
    public function fail(string $id, \Throwable $failure): bool
    {
        return $this->finish($id, $failure, null);
    }

    /**
     * Transport death: fail every pending call with $failure (a string
     * becomes a \RuntimeException) and empty the table first, so callbacks
     * that register a retry land in a fresh table. Returns how many failed.
     */
    public function failAll(\Throwable|string $failure): int
    {
        if (is_string($failure)) {
            $failure = new \RuntimeException($failure);
        }
        $calls = $this->calls;
        $this->calls = [];
        foreach ($calls as [$settle]) {
            $settle($failure, null);
        }

        return count($calls);
    }

    private function finish(string $id, ?\Throwable $failure, mixed $result): bool
    {
        if (!isset($this->calls[$id])) {
            return false;
        }
        [$settle] = $this->calls[$id];
        unset($this->calls[$id]);
        $settle($failure, $result);

        return true;
    }
}
