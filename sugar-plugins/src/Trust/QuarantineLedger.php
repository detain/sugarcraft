<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Trust;

/**
 * The record of entrypoint files an earlier launch has already seen.
 *
 * First sight quarantines: the file is recorded now and loads at the next
 * launch, so a newly trusted or swapped file is listed for one launch before
 * any of its code runs.
 */
interface QuarantineLedger
{
    /** Has an earlier launch already seen this entrypoint file? */
    public function seen(string $path): bool;

    /** Record the entrypoint file as seen. */
    public function record(string $path): void;
}
