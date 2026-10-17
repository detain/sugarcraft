<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

/**
 * Imports env vars set under a package's pre-rename prefix into its new prefix,
 * so a user's existing SUGARCRUSH_* exports keep working after the rename. // @rename-keep
 * Never overwrites a var the user already set under the new name.
 *
 * WHY THIS EXISTS. plan_rename.md step 0.5: renamed apps must keep working for
 * users who still export the old prefix. An app calls this at the very top of
 * its bin/ script — after the autoloader, before anything reads env — so every
 * downstream reader only ever sees the new name and no call site needs
 * dual-prefix logic. The return value feeds the per-app deprecation notice.
 *
 * Precedence, pinning the empty-string edge the step leaves implicit: "already
 * set" is decided with `getenv($new) === false`, so an explicit empty
 * `export CANDY_CODE_FOO=` still counts as set — the new name sits on top and
 * wins even when blank. Symmetrically, an OLD var whose value is the empty
 * string is imported as the empty string: a user who exported it meant it.
 *
 * Determinism: env vars are scanned in sorted-name order, so when two old
 * prefixes derive the same new name (`CANDY_CODE_MODE` and `CANDY_CODE_MODE`
 * both mapping onto `CANDY_CODE_MODE`) the byte-first old var wins the import
 * and the loser is not reported — the result is reproducible regardless of how
 * the environment was assembled. // @rename-keep
 *
 * @see LegacyPaths the directory-level sibling of this shim
 */
final class LegacyEnv
{
    /**
     * @param array<string,string> $prefixMap old prefix => new prefix, e.g. ['SUGARCRUSH_' => 'CANDY_CODE_'] // @rename-keep
     * @return list<string> the OLD var names that were imported (for a deprecation notice), in sorted order
     */
    public static function import(array $prefixMap): array
    {
        foreach ($prefixMap as $old => $new) {
            if ($old === '') {
                throw new \InvalidArgumentException(
                    'LegacyEnv::import(): an old prefix must not be the empty string — it would match every env var.'
                );
            }
        }

        $environment = getenv();
        ksort($environment, SORT_STRING);

        $imported = [];
        foreach ($environment as $name => $value) {
            foreach ($prefixMap as $old => $new) {
                if (!str_starts_with($name, (string) $old)) {
                    continue;
                }
                $target = $new . substr($name, strlen((string) $old));
                if (getenv($target) !== false) {
                    continue;
                }
                putenv("{$target}={$value}");
                $_ENV[$target] = $value;
                $_SERVER[$target] = $value;
                $imported[] = $name;
                break;
            }
        }
        sort($imported, SORT_STRING);

        return $imported;
    }
}
