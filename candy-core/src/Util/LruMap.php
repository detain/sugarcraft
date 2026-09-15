<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

use SugarCraft\Core\Lang;

/**
 * A least-recently-used map: a fixed-capacity cache that drops what was
 * touched longest ago when it runs out of room.
 *
 * Promoted from phlix-console-client `src/Store/LruMap.php` (poster/metadata
 * response cache). The cap, the promote-on-read behaviour and the
 * non-promoting {@see peek()} come over unchanged; the generic value type,
 * {@see touch()}, {@see remove()}, {@see evictions()} and the capacity growth
 * guard are what made it worth sharing rather than copying again.
 *
 * ## Why a size bound and not just a TTL
 *
 * TTL answers "is this stale?", capacity answers "how much RAM?". They are
 * orthogonal: a TTL-only cache grows without bound on a working set that never
 * expires, and an LRU-only cache keeps serving warm-but-stale entries. The two
 * are meant to sit together — {@see peek()} exists precisely so a caller can
 * read an entry, test its age without disturbing its recency, and only then
 * decide whether to claim it with {@see get()}.
 *
 * ## Iteration order
 *
 * `foreach`, {@see keys()} and {@see entries()} walk most-recently-used first,
 * which is the order a caller trimming or warming a cache wants. Internally the
 * recency is stored the other way round (PHP appends, so the MRU entry is the
 * last inserted and the eviction victim is simply the first), and the reversal
 * happens only at the read boundary.
 *
 * Unlike the immutable `with*()` value objects elsewhere in candy-core, this is
 * a live cache: identity matters, so {@see put()} et al. mutate the receiver and
 * {@see resize()} changes the cap in place rather than returning a new map.
 * `clone` is allowed and yields an independent snapshot — the entries array is
 * copied, but any object values are shared by reference, exactly as PHP's clone
 * semantics dictate. Unlike {@see Semaphore}, cloning a cache is safe: two maps
 * cannot double-evict or double-count against each other, they simply diverge.
 *
 * @template V
 *
 * @implements \IteratorAggregate<array-key, V>
 */
final class LruMap implements \Countable, \IteratorAggregate
{
    /**
     * Entries in insertion order: offset 0 is the eviction victim, the last
     * offset is the most recently used.
     *
     * `array-key` rather than `string` because PHP re-casts an integer-like
     * string key to int the moment it enters a hashtable — see {@see keys()}.
     *
     * @var array<array-key, V>
     */
    private array $data = [];

    /** Entries dropped by the capacity bound — overwrites and removes are not evictions. */
    private int $evictions = 0;

    private function __construct(
        private int $capacity,
        private readonly ?int $maxCapacity,
    ) {
    }

    /**
     * Create a map holding at most $capacity entries.
     *
     * @param int      $capacity    entries admitted before the LRU victim is dropped
     * @param ?int     $maxCapacity optional growth guard: the ceiling {@see resize()}
     *                              may never raise $capacity past
     * @return self<V>
     * @throws \InvalidArgumentException when $capacity is below 1, or when a
     *                                   guard is supplied that is smaller than
     *                                   the starting capacity — a map born above
     *                                   its own ceiling would have a guard it
     *                                   already violates, so there is no honest
     *                                   number to report from maxCapacity().
     */
    public static function new(int $capacity, ?int $maxCapacity = null): self
    {
        self::guard($capacity, $maxCapacity);

        return new self($capacity, $maxCapacity);
    }

    /** The current ceiling on entries held. */
    public function capacity(): int
    {
        return $this->capacity;
    }

    /** The growth guard set at construction, or null when resizing is unbounded. */
    public function maxCapacity(): ?int
    {
        return $this->maxCapacity;
    }

    /**
     * Change how many entries the map holds, evicting LRU-first if it now
     * exceeds the new cap.
     *
     * A shrink that sheds entries counts them in {@see evictions()}, because the
     * capacity bound is what dropped them — exactly as it is for an ordinary
     * `put()` against a full map. Growing never counts anything.
     *
     * Mutates in place: a cache whose identity changed on resize would strand
     * every holder of the old instance with an unbounded copy of it.
     *
     * @throws \InvalidArgumentException on a cap below 1 or past the growth guard.
     */
    public function resize(int $capacity): void
    {
        self::guard($capacity, $this->maxCapacity);

        $this->capacity = $capacity;
        $this->trimToCapacity();
    }

    /**
     * Read an entry and mark it most-recently-used.
     *
     * @return V|null null on a miss — values may legitimately be null, so use
     *                {@see has()} to tell a stored null from an absent key.
     */
    public function get(string $key): mixed
    {
        if (!array_key_exists($key, $this->data)) {
            return null;
        }

        $this->promote($key);

        return $this->data[$key];
    }

    /**
     * Read an entry WITHOUT disturbing recency.
     *
     * For validity checks (age, checksum) that must not resurrect an entry the
     * caller goes on to discard.
     *
     * @return V|null
     */
    public function peek(string $key): mixed
    {
        if (!array_key_exists($key, $this->data)) {
            return null;
        }

        return $this->data[$key];
    }

    /**
     * Mark an existing entry most-recently-used without reading its value.
     *
     * @return bool false when the key is absent — touching what is not there
     *              must not conjure an entry.
     */
    public function touch(string $key): bool
    {
        if (!array_key_exists($key, $this->data)) {
            return false;
        }

        $this->promote($key);

        return true;
    }

    /**
     * Store an entry, evicting the least-recently-used one if the map is full.
     *
     * Re-storing an existing key counts as a use and never evicts anything.
     *
     * @param V $value
     */
    public function put(string $key, mixed $value): void
    {
        // PHP keeps the original position of a re-assigned key, so a plain
        // overwrite would leave a hot entry stuck at the eviction end.
        unset($this->data[$key]);

        // Evict before inserting so the capacity test reads the occupancy that
        // excludes the newcomer: a full map then sheds exactly one entry to make
        // room. (The victim is still taken from the head, so the newcomer at the
        // tail is never itself the eviction — the ordering here is about the
        // count, not about which entry is chosen.)
        if (count($this->data) >= $this->capacity) {
            $this->evictOldest();
        }

        $this->data[$key] = $value;
    }

    /** Whether the key is present, regardless of recency (does not promote). */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * Drop one entry.
     *
     * @return bool false when the key was already absent, so a caller can tell
     *              a real removal from a no-op.
     */
    public function remove(string $key): bool
    {
        if (!array_key_exists($key, $this->data)) {
            return false;
        }

        unset($this->data[$key]);

        return true;
    }

    /**
     * Drop every entry.
     *
     * Recency order goes with them, but the {@see evictions()} counter does
     * not: it reports what the map did over its lifetime, which is what a
     * caller watches to decide whether the capacity is too small.
     */
    public function clear(): void
    {
        $this->data = [];
    }

    /**
     * Keys, most-recently-used first.
     *
     * Always strings, even for keys that look like integers: the returned list
     * casts every key with strval(), undoing PHP's int-key coercion. That makes
     * this the safe view for feeding keys back into {@see put()}/{@see remove()}
     * or into a `fn (string $key)` callback, where the raw int(42) that
     * {@see entries()} and iteration expose would otherwise be a type error.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        // Reverse for MRU-first, then cast: see the docblock for why strval().
        return array_map('strval', array_reverse(array_keys($this->data)));
    }

    /**
     * Key/value pairs, most-recently-used first.
     *
     * The keys here are whatever PHP's hashtable made of them, which is
     * `array-key` rather than `string`: an integer-like key such as `'42'` comes
     * back as `int(42)`, and a map keyed entirely by numeric strings from `0`
     * upward will make `json_encode()` emit a JSON array instead of an object.
     * {@see keys()} is the string-safe view; cast the keys yourself if this
     * output is going to a serialiser.
     *
     * @return array<array-key, V>
     */
    public function entries(): array
    {
        return array_reverse($this->data, true);
    }

    /** Entries dropped by the capacity bound since construction. */
    public function evictions(): int
    {
        return $this->evictions;
    }

    /** Entries currently held. */
    public function count(): int
    {
        return count($this->data);
    }

    /**
     * Iterate most-recently-used first, over a snapshot: mutating the map from
     * inside the loop cannot disturb the walk in progress.
     *
     * @return \Iterator<array-key, V>
     */
    public function getIterator(): \Iterator
    {
        return new \ArrayIterator($this->entries());
    }

    /**
     * Move an existing key to the most-recently-used end.
     */
    private function promote(string $key): void
    {
        /** @var V $value */
        $value = $this->data[$key];
        unset($this->data[$key]);
        $this->data[$key] = $value;
    }

    /**
     * Evict the least-recently-used entry (the head of internal order).
     *
     * Both callers prove the map is non-empty before getting here, so an empty
     * array means that reasoning broke — shed nothing silently, because the
     * symptom would be a cache over its capacity and an evictions counter that
     * quietly under-reports.
     */
    private function evictOldest(): void
    {
        $victim = array_key_first($this->data);

        if ($victim === null) {
            throw new \LogicException(Lang::t('lru_map.evict_from_empty'));
        }

        unset($this->data[$victim]);
        $this->evictions++;
    }

    /**
     * Shed entries until the cap is honoured, evicting LRU-first.
     */
    private function trimToCapacity(): void
    {
        while (count($this->data) > $this->capacity) {
            $this->evictOldest();
        }
    }

    /**
     * Reject an unusable capacity or one that starts above its own guard.
     */
    private static function guard(int $capacity, ?int $maxCapacity): void
    {
        if ($capacity < 1) {
            throw new \InvalidArgumentException(Lang::t('lru_map.capacity_invalid', [
                'capacity' => (string) $capacity,
            ]));
        }

        if ($maxCapacity !== null && $capacity > $maxCapacity) {
            throw new \InvalidArgumentException(Lang::t('lru_map.capacity_too_large', [
                'capacity' => (string) $capacity,
                'max' => (string) $maxCapacity,
            ]));
        }
    }
}
