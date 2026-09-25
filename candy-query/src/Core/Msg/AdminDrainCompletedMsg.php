<?php

declare(strict_types=1);

namespace SugarCraft\Query\Core\Msg;

use SugarCraft\Core\Msg;

/**
 * Dispatched when a drain-only admin fetch settles: the page-registered
 * pending queries (SHOW FULL PROCESSLIST, sys reports, availability probes)
 * have landed in AdminQueryCache, while the status/server variable caches
 * were intentionally NOT re-fetched (still inside their TTL window).
 *
 * Deliberately distinct from AdminDataLoadedMsg: that message carries the
 * status/server arrays into the model, and folding empty arrays through it
 * after a drain would wipe the cached vars.
 */
final readonly class AdminDrainCompletedMsg implements Msg {}
