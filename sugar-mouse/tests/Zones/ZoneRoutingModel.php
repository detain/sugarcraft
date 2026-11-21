<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Tests\Zones;

/**
 * A model that records which mouse message reached it, for the
 * Zones::anyInBoundsAndUpdate routing tests.
 *
 * Lives in its own autoloadable file because ManagerTest and ZonesTest both
 * use it. Declared inside ManagerTest.php it resolved in ZonesTest only when
 * ManagerTest.php had already been loaded: always under serial phpunit, which
 * loads every test file before running any, and not in a ParaTest worker,
 * which loads one file per dispatch ("Class not found").
 */
final class ZoneRoutingModel implements \SugarCraft\Core\Model
{
    public ?\SugarCraft\Mouse\Zones\MsgZoneInBounds $lastInBoundsHit  = null;
    public ?\SugarCraft\Core\Msg\MouseMsg    $lastPlainMouse   = null;

    public function init(): ?\Closure { return null; }

    public function update(\SugarCraft\Core\Msg $msg): array
    {
        $next = clone $this;
        if ($msg instanceof \SugarCraft\Mouse\Zones\MsgZoneInBounds) {
            $next->lastInBoundsHit = $msg;
        } elseif ($msg instanceof \SugarCraft\Core\Msg\MouseMsg) {
            $next->lastPlainMouse = $msg;
        }
        return [$next, null];
    }

    public function view(): string { return ''; }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}
