<?php

declare(strict_types=1);

namespace SugarCraft\QuickPick;

/**
 * @deprecated Renamed to {@see QuickPickHost} (-044: the bare word `Model`
 *             collided with sugar-core's house `SugarCraft\Core\Model`, a
 *             foreign shape). This alias keeps old type-hints compiling; it
 *             declares no members of its own, so `instanceof Model` and
 *             parameter/return positions against the name still resolve.
 *             NOTE: IMPLEMENTORS of the old single-value contract are NOT
 *             source-compatible — the update() shape changed in the same
 *             step (-006); a legacy host must adopt the tuple.
 *
 *     The interface lives in its own file because PSR-4 autoloads one
 *     top-level class/interface per file keyed on the FQN — declaring
 *     QuickPickHost in here would make it un-loadable from a cold cache.
 */
interface Model extends QuickPickHost
{
}
