<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Layout\Support;

use SugarCraft\Dashboard\State\Persistence;

/**
 * Shared collapsed-address persistence for the layout engines.
 *
 * Finding sugar-dashboard-143: Boxer and StackedGrid carried byte-identical
 * persistState()/restoreState() copies of the same collapsed-panel contract.
 * The pair now shares this trait. FocusManager intentionally does NOT: it
 * persists a focused id plus a focus map and repairs them on restore
 * (D-08 fail-soft), so its state contract is a different shape.
 *
 * Note: Item content is not directly serializable. Callers persist the
 * identifiers of collapsed panels through this hook; the items themselves
 * must be re-built by the application on restore.
 */
trait PersistState
{
    /**
     * Persist the layout engine's collapsed-panel addresses to disk.
     *
     * @param list<string> $collapsedAddresses Addresses of collapsed panels.
     */
    public function persistState(Persistence $persistence, string $path, array $collapsedAddresses = []): void
    {
        $persistence->save($path, [
            'collapsedAddresses' => $collapsedAddresses,
        ]);
    }

    /**
     * Restore the persisted collapsed-addresses list.
     *
     * @return list<string> Addresses that were collapsed.
     */
    public function restoreState(Persistence $persistence, string $path): array
    {
        $data = $persistence->load($path);
        if ($data === null) {
            return [];
        }

        return $data['collapsedAddresses'] ?? [];
    }
}
