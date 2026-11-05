<?php

declare(strict_types=1);

namespace SugarCraft\Code\Backend;

/**
 * Contract for backends that spawn command children and keep them registered
 * until they are reaped, so a quit or a hard mid-turn cancel can kill the
 * process tree that is still alive instead of orphaning it (CC-QA-2).
 *
 * Implementers satisfy the registry through {@see AbandonsActiveChildren};
 * the callers are Chat's Ctrl+C and double-Escape arms (async, loop still
 * running) and the bin/candy-code exit funnel (synchronous, loop already
 * stopped).
 */
interface TracksActiveChildren
{
    /**
     * Pids of children this backend spawned and has not yet reaped.
     *
     * @return list<int>
     */
    public function activeChildPids(): array;

    /**
     * Kill every still-registered child tree and clear the registry.
     *
     * @param bool $async true walks each tree over the event loop (a live
     *                    loop is driving the UI), false finishes the walk
     *                    synchronously (the post-run funnel, where no loop
     *                    is left to tick and killTreeAsync's shutdown
     *                    function would be the thing that pays for it)
     * @return int number of trees abandoned — also the announce count
     */
    public function abandonActiveChildren(bool $async = false): int;
}
