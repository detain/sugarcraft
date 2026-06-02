<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\PerfSchema;

/**
 * Change-tracking overlay for Performance Schema configuration.
 *
 * Wraps a collection of mutable PSR models (SetupInstruments, SetupConsumers, etc.)
 * and tracks the original vs current values, allowing detection of unsaved changes.
 *
 * Usage:
 *   $tracker = new ChangeTracker($instruments);
 *   $modified = $tracker->current('wait/io/file/sql/binlog')->withEnabled(false);
 *   $tracker->replace('wait/io/file/sql/binlog', $modified);
 *   $tracker->isDirty();  // true
 *   $tracker->diff();      // ['wait/io/file/sql/binlog']
 *   $tracker->commit();   // mark current as original
 *
 * @see Mirrors mysql-workbench wb_admin_performance_schema change_tracker
 */
final class ChangeTracker
{
    /** @var array<string, mixed> Original values (name => value) */
    private array $original = [];

    /** @var array<string, mixed> Current values (name => value) */
    private array $current = [];

    /**
     * @param array<string, SetupInstruments|SetupConsumers|SetupActors|SetupObjects> $items Initial items
     */
    public function __construct(array $items = []) {
        foreach ($items as $item) {
            $this->add($item);
        }
    }

    /**
     * Create a new tracker from SetupInstruments.
     *
     * @param list<SetupInstruments> $instruments
     * @return self
     */
    public static function fromInstruments(array $instruments): self
    {
        return new self($instruments);
    }

    /**
     * Create a new tracker from SetupConsumers.
     *
     * @param list<SetupConsumers> $consumers
     * @return self
     */
    public static function fromConsumers(array $consumers): self
    {
        return new self($consumers);
    }

    /**
     * Create a new tracker from SetupActors.
     *
     * @param list<SetupActors> $actors
     * @return self
     */
    public static function fromActors(array $actors): self
    {
        return new self($actors);
    }

    /**
     * Create a new tracker from SetupObjects.
     *
     * @param list<SetupObjects> $objects
     * @return self
     */
    public static function fromObjects(array $objects): self
    {
        return new self($objects);
    }

    /**
     * Add an item to the tracker.
     *
     * @param SetupInstruments|SetupConsumers|SetupActors|SetupObjects $item
     */
    public function add(object $item): void
    {
        $key = $this->getKey($item);
        $this->original[$key] = $this->copyItem($item);
        $this->current[$key] = $item;
    }

    /**
     * Get the original value of an item by key.
     *
     * @param string $key Item identifier
     * @return mixed Original value or null
     */
    public function original(string $key): mixed
    {
        return $this->original[$key] ?? null;
    }

    /**
     * Get the current value of an item by key.
     *
     * @param string $key Item identifier
     * @return mixed Current value or null
     */
    public function current(string $key): mixed
    {
        return $this->current[$key] ?? null;
    }

    /**
     * Replace an item's current value with a modified copy.
     *
     * @param string $key Item identifier
     * @param object $modified Modified item
     */
    public function replace(string $key, object $modified): void
    {
        if (isset($this->current[$key])) {
            $this->current[$key] = $modified;
        }
    }

    /**
     * Check if there are any unsaved changes.
     *
     * @return bool True if any item has been modified
     */
    public function isDirty(): bool
    {
        return $this->diff() !== [];
    }

    /**
     * Get the keys of all changed items.
     *
     * Compares original vs current values and returns keys where they differ.
     *
     * @return list<string> List of changed item keys
     */
    public function diff(): array
    {
        $changed = [];

        foreach ($this->current as $key => $currentItem) {
            $originalItem = $this->original[$key] ?? null;

            if ($originalItem === null) {
                $changed[] = $key;
                continue;
            }

            if (!$this->itemsEqual($originalItem, $currentItem)) {
                $changed[] = $key;
            }
        }

        return $changed;
    }

    /**
     * Reset all changes by reverting current values to original.
     *
     * After reset(), isDirty() returns false.
     */
    public function reset(): void
    {
        $this->current = [];
        foreach ($this->original as $key => $originalItem) {
            $this->current[$key] = $this->copyItem($originalItem);
        }
    }

    /**
     * Commit changes by marking current values as the new original.
     *
     * After commit(), isDirty() returns false.
     */
    public function commit(): void
    {
        $this->original = [];
        foreach ($this->current as $key => $currentItem) {
            $this->original[$key] = $this->copyItem($currentItem);
        }
    }

    /**
     * Get all current items.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->current;
    }

    /**
     * Get the key identifier for an item.
     *
     * @param object $item
     * @return string
     */
    private function getKey(object $item): string
    {
        return match (true) {
            $item instanceof SetupInstruments => $item->name,
            $item instanceof SetupConsumers => $item->name,
            $item instanceof SetupActors => "{$item->host}:{$item->user}:{$item->role}",
            $item instanceof SetupObjects => "{$item->objectType}:{$item->objectSchema}:{$item->objectName}",
            default => throw new \InvalidArgumentException('Unknown item type: ' . get_class($item)),
        };
    }

    /**
     * Create a copy of an item for storage.
     *
     * Uses clone for mutable objects or serialization for immutable.
     *
     * @param object $item
     * @return object
     */
    private function copyItem(object $item): object
    {
        if ($item instanceof SetupInstruments || $item instanceof SetupConsumers
            || $item instanceof SetupActors || $item instanceof SetupObjects) {
            return clone $item;
        }

        return $item;
    }

    /**
     * Compare two items for equality.
     *
     * @param object $a
     * @param object $b
     * @return bool
     */
    private function itemsEqual(object $a, object $b): bool
    {
        if ($a instanceof SetupInstruments && $b instanceof SetupInstruments) {
            return $a->name === $b->name
                && $a->enabled === $b->enabled
                && $a->timed === $b->timed
                && $a->properties === $b->properties
                && $a->flags === $b->flags;
        }

        if ($a instanceof SetupConsumers && $b instanceof SetupConsumers) {
            return $a->name === $b->name && $a->enabled === $b->enabled;
        }

        if ($a instanceof SetupActors && $b instanceof SetupActors) {
            return $a->host === $b->host
                && $a->user === $b->user
                && $a->role === $b->role
                && $a->enabled === $b->enabled;
        }

        if ($a instanceof SetupObjects && $b instanceof SetupObjects) {
            return $a->objectType === $b->objectType
                && $a->objectSchema === $b->objectSchema
                && $a->objectName === $b->objectName
                && $a->enabled === $b->enabled
                && $a->timed === $b->timed;
        }

        return false;
    }
}
