<?php

declare(strict_types=1);

namespace SugarCraft\Query\Core\Msg;

use SugarCraft\Core\Msg;

/**
 * Dispatched after AdminDataLoadedMsg to trigger async report reload.
 * ReportsPage handles this by calling loadCurrentReport() which queues
 * the report query via CachedConnection for the next tick.
 */
final readonly class ReloadReportMsg implements Msg
{
}
