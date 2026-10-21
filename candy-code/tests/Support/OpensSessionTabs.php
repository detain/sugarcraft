<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use SugarCraft\Code\Tui\SessionTab;
use SugarCraft\Code\Tui\SessionTabs;

/**
 * Builds {@see SessionTabs} models for tests of the ISS-102 open-tab strip.
 *
 * The tab strip, Ctrl+Tab cycling, and the click route all read
 * `Chat::openTabs()` now — a store listing alone opens nothing. Tests that
 * want a multi-tab fixture pass one of these in the constructor's
 * `tabState:` fold (`new SessionTabState(tabs: ...)`, v4 split-plan S3)
 * instead of seeding extra `sessions` rows.
 */
trait OpensSessionTabs
{
    /**
     * A tab per id, in order; $names maps id => label ('' where unset, which
     * the strip shows as the untitled label). The first id — or $activeId —
     * is the active tab.
     *
     * @param list<string>             $ids
     * @param array<string, string>    $names
     */
    private static function openTabsFor(array $ids, array $names = [], ?string $activeId = null): SessionTabs
    {
        $tabsById = [];
        foreach ($ids as $id) {
            $tabsById[$id] = new SessionTab(id: $id, sessionName: $names[$id] ?? '');
        }

        return (new SessionTabs($tabsById, $ids))->setActiveTab($activeId ?? ($ids[0] ?? ''));
    }

    /**
     * Open a tab for every row in $store, in list order, named by row name —
     * the migration shim for fixtures that used to drive the strip straight
     * off the store listing (pre-ISS-102 shape).
     */
    private static function tabsFromStore(object $store, ?string $activeId = null): SessionTabs
    {
        $ids = [];
        $names = [];
        foreach ($store->listSessions() as $row) {
            $id = (string) $row['id'];
            $ids[] = $id;
            $names[$id] = (string) ($row['name'] ?? '');
        }

        return self::openTabsFor($ids, $names, activeId: $activeId);
    }
}
