<?php

declare(strict_types=1);

namespace SugarCraft\Top\Config;

/**
 * Outcome of reading a config file.
 *
 * Mirrors aristocratos/btop Config::load's out-params: `load_warnings`
 * (shown to the user at startup; each rejected value kept its default) and
 * `write_new` (the file is missing, from another version, or held invalid
 * values, so the next save should rewrite it in full).
 */
final class LoadResult
{
    /** @param list<string> $warnings */
    public function __construct(
        public readonly Config $config,
        public readonly array $warnings,
        public readonly bool $needsRewrite,
    ) {
    }
}
