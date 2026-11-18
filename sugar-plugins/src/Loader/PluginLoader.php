<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Loader;

use SugarCraft\Core\Util\PathJail;
use SugarCraft\Plugins\Support\ErrorLine;
use SugarCraft\Plugins\Trust\PrivateBytes;
use SugarCraft\Plugins\Trust\QuarantineLedger;
use SugarCraft\Plugins\Trust\TrustList;

/**
 * The trust-gated loader for in-process plugin code: the ONLY place a host
 * executes PHP it did not ship, and the door is the narrowest the format allows.
 *
 * Four laws hold here:
 *
 * 1. NOTHING LOADS THAT NO MANIFEST NAMES. Files come exclusively from the
 *    manifest's contained entrypoints. No globbing, no sniffing.
 * 2. NOTHING LOADS THAT THE USER HAS NOT TRUSTED. A plugin's entrypoints load
 *    only when its name is on the trust list, matched exactly. An untrusted
 *    name never has its manifest read.
 * 3. FIRST SIGHT IS QUARANTINE. A trusted entrypoint is only LISTED on the
 *    launch that first reveals it. The file loads at the NEXT launch, so a
 *    manifest swap under a trusted name cannot smuggle code in silently.
 * 4. THE BYTES THEMSELVES MUST BE PRIVATE. Before `require_once`, the file and
 *    its parent directory must be owned by this process and not world-writable.
 *
 * The loader ANSWERS; it never announces. Every entry lands in exactly one
 * bucket (see {@see LoadResult}). A file that throws at require time is refused
 * `require-failed` with its diagnostic carried in the row. Collision refusal
 * (a name clashing with another accepted plugin) belongs to the registry, which
 * never overrides.
 *
 * Refusal reason words: manifest-refused, uncontained, contract-unsupported,
 * world-writable, foreign-owner, require-failed, class-missing,
 * class-not-declared, interface-mismatch.
 */
final class PluginLoader
{
    /**
     * Load every trusted plugin's entrypoints of $kinds into this process.
     *
     * One trust list, one quarantine ledger, one private-bytes door: the kind
     * only selects which manifest section is read. A path named under two kinds
     * is loaded, and recorded, exactly once.
     *
     * @param list<PluginCandidate>            $candidates    discovered plugins
     * @param QuarantineLedger                 $ledger        the seen-ledger backing quarantine
     * @param list<string>                     $kinds         manifest entrypoint kinds to read
     * @param array<string, class-string>      $kindInterfaces the interface each kind's class must implement
     * @param int                              $hostContract  the host's contract integer; a plugin declaring a higher one is refused
     *
     * @throws \InvalidArgumentException when a requested kind has no interface mapped
     */
    public static function load(
        array $candidates,
        TrustList $trust,
        QuarantineLedger $ledger,
        array $kinds,
        array $kindInterfaces,
        int $hostContract,
    ): LoadResult {
        foreach ($kinds as $kind) {
            if (!isset($kindInterfaces[$kind])) {
                // A kind with no interface has no contract to check a class
                // against, so the caller is wrong, not the plugin.
                throw new \InvalidArgumentException('No interface is mapped for plugin kind "' . $kind . '".');
            }
        }

        $loaded = [];
        $quarantined = [];
        $refusals = [];

        if ($trust->names() === []) {
            // No opt-in, no read: not even manifests get consulted.
            return new LoadResult($loaded, $quarantined, $refusals);
        }

        foreach ($candidates as $candidate) {
            if (!$trust->contains($candidate->name)) {
                continue; // trust is by exact name, never case-folded
            }

            $manifest = $candidate->manifest();
            if ($manifest === null) {
                // A trusted name whose manifest was refused (non-object, dot-name,
                // malformed contract) still owes every requested kind a row, or the
                // host would see the plugin in no bucket at all.
                foreach ($kinds as $kind) {
                    $refusals[] = ['bundle' => $candidate->name, 'path' => $candidate->dir, 'class' => '', 'kind' => $kind, 'reason' => 'manifest-refused'];
                }
                continue;
            }

            $contract = $manifest->contract();
            $unsupported = $contract !== null && $contract > $hostContract;
            $declared = $manifest->entrypoints();
            $seenPaths = [];

            foreach ($kinds as $kind) {
                foreach ($declared[$kind] ?? [] as $entry) {
                    if (isset($seenPaths[$entry->path])) {
                        continue; // one path, one load, however many kinds name it
                    }
                    $seenPaths[$entry->path] = true;

                    $where = ['bundle' => $candidate->name, 'path' => $entry->path, 'class' => $entry->class, 'kind' => $kind];

                    if (!PathJail::below($entry->path, $candidate->dir)) {
                        // The manifest reader already refused this; the require
                        // gets its own containment assertion, not a borrowed one.
                        $refusals[] = $where + ['reason' => 'uncontained'];
                        continue;
                    }

                    if ($unsupported) {
                        $refusals[] = $where + ['reason' => 'contract-unsupported'];
                        continue;
                    }

                    $private = PrivateBytes::refusal($entry->path);
                    if ($private !== null) {
                        $refusals[] = $where + ['reason' => $private];
                        continue;
                    }

                    if (!$ledger->seen($entry->path)) {
                        // Quarantine first: recorded before the require, so the
                        // NEXT launch loads it and this launch only lists it.
                        $ledger->record($entry->path);
                        $quarantined[] = $where;
                        continue;
                    }

                    try {
                        require_once $entry->path;
                    } catch (\Throwable $e) {
                        // One throwing plugin file must not abort the whole load;
                        // the diagnostic travels AS DATA in the row.
                        $refusals[] = $where + ['reason' => 'require-failed', 'error' => ErrorLine::of($e, 'plugin require ' . $entry->class)];
                        continue;
                    }

                    if ($entry->class === '') {
                        $refusals[] = $where + ['reason' => 'class-missing'];
                        continue;
                    }

                    if (!\class_exists($entry->class, false)) {
                        $refusals[] = $where + ['reason' => 'class-not-declared'];
                        continue;
                    }

                    if (!\is_a($entry->class, $kindInterfaces[$kind], true)) {
                        $refusals[] = $where + ['reason' => 'interface-mismatch'];
                        continue;
                    }

                    $loaded[] = $where;
                }
            }
        }

        return new LoadResult($loaded, $quarantined, $refusals);
    }
}
