<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

/**
 * A user class that exists in the parent but is NOT in the worker response
 * allow-list. Returning one from a task is the sc-33 probe: the child can
 * serialize it, but the parent's unserialize can only construct an
 * incomplete instance — which the pool must now reject BY NAME
 * (worker.incomplete_result) instead of handing back a half-object.
 */
final class WorkerPoolPrivateObject
{
    public int $x = 7;
}
