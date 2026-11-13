<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Import;

use SugarCraft\Kv\Store;

/**
 * The shared commit step behind both importers (candy-kv-051).
 *
 * Formats parse; this is where a parsed document MEETS the live store, so
 * the clobber policy lives here exactly once. Both importers build the same
 * validated `$rows` shape and hand it over; a test pins that neither grows
 * a private copy of this loop (the old byte-identical twin closures were
 * copy-drift waiting to happen — one importer gaining a policy while the
 * other stayed silent is precisely the bug class).
 *
 * The policy check is a live read per row taken at write moment:
 *  - replace: store as-is; the row counts as overwritten when a live
 *    entry existed a moment before.
 *  - skip:    a live entry stays; the row counts as skipped.
 *  - fail:    the first collision aborts with a refusal naming the key —
 *    under the default atomic mode nothing commits at all, so the operator
 *    learns the whole conflict list by iterating; under --no-atomic the
 *    prefix already written is the documented price of that flag.
 *
 * Existence reads go through Store::entry() — the same escape-aware '@'
 * routing Store::set() will write through — so a key can never be judged
 * against one database and committed to another.
 */
final class ImportCommit
{
    public const POLICIES = ['replace', 'skip', 'fail'];

    /**
     * @param Store $store      destination store
     * @param list<array{0:string,1:string,2:bool,3:?int}> $rows validated rows [key, value, binary, ttl]
     * @param string $ifExists  one of POLICIES
     *
     * @throws \InvalidArgumentException on an unknown policy name
     * @throws \RuntimeException when policy is 'fail' and a key is already live
     */
    public static function run(Store $store, array $rows, string $ifExists): ImportReport
    {
        if (!\in_array($ifExists, self::POLICIES, true)) {
            throw new \InvalidArgumentException(
                \sprintf('Unknown import policy %s. Use skip, fail or replace.', self::quote($ifExists))
            );
        }

        $imported = 0;
        $overwritten = 0;
        $skipped = 0;

        foreach ($rows as [$key, $value, $binary, $ttl]) {
            $live = $store->entry($key);
            if ($live !== null) {
                if ($ifExists === 'fail') {
                    throw new \RuntimeException(
                        \sprintf(
                            'Cannot import %s: the entry already exists. Use --if-exists=replace to overwrite it or --if-exists=skip to keep the stored value.',
                            self::quote($key)
                        )
                    );
                }
                if ($ifExists === 'skip') {
                    $skipped++;
                    continue;
                }
                $overwritten++;
            }
            $store->set($key, $value, $binary, $ttl);
            $imported++;
        }

        return new ImportReport($imported, $overwritten, $skipped);
    }

    /** Single-quote a key for diagnostics, doubling any embedded quote. */
    private static function quote(string $text): string
    {
        return "'" . \str_replace("'", "''", $text) . "'";
    }
}
