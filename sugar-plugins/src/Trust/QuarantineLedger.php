<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Trust;

/**
 * The record of entrypoint files an earlier launch has already seen.
 *
 * First sight quarantines: a newly seen entrypoint path is recorded now and
 * loads at the next launch, so a newly trusted file is listed for one launch
 * before any of its code runs. The ledger is keyed by path alone, so a rewrite
 * of a path already recorded is not listed again: a rewrite of such a file (in
 * place, or by replace-by-rename where the private-bytes gate allows it) loads
 * without a quarantine stop. That gap is open; see PluginLoader law 3.
 */
interface QuarantineLedger
{
    /** Has an earlier launch already seen this entrypoint file? */
    public function seen(string $path): bool;

    /** Record the entrypoint file as seen. */
    public function record(string $path): void;
}
