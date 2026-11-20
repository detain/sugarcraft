<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Loader;

use SugarCraft\Core\Util\PathJail;
use SugarCraft\Plugins\Manifest\PluginManifest;
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
 * 3. FIRST SIGHT IS QUARANTINE, KEYED BY PATH. A trusted entrypoint PATH is
 *    only LISTED on the launch that first reveals it, and the file loads at the
 *    NEXT launch. So a manifest swap that introduces a new entrypoint under a
 *    trusted name cannot run that file silently. The ledger records the path
 *    alone, never the content: a path already on the ledger is not quarantined
 *    again when its bytes change. So an in-place rewrite of a file whose path was
 *    already seen, by any account the private-bytes gate admits as a writer (the
 *    owner, or a group member when the file is group-writable), loads between
 *    launches without a quarantine stop. This is an open gap, carried over from
 *    the original loader and not closed here.
 * 4. THE BYTES THEMSELVES MUST BE PRIVATE. Before `require_once`, the file and
 *    its parent directory must not be writable by the world (the same gate
 *    candy-code applies; group write is accepted), and, when the posix extension
 *    is available, must be owned by this process. Without ext-posix the owner
 *    check is skipped and only the world-write check runs, so a file owned by
 *    another account loads as long as it is not world-writable. That policy gap
 *    is open.
 *
 * The loader ANSWERS; it never announces. Every entry lands in exactly one
 * bucket (see {@see LoadResult}). A file that throws at require time is refused
 * `require-failed` with its diagnostic carried in the row. Collision refusal
 * (a name clashing with another accepted plugin) belongs to the registry, which
 * never overrides.
 *
 * The interface a class must implement is NOT checked here: the loader answers
 * for the file and the class, and the caller that owns the channel contract
 * (candy-code's ChannelRegistry) judges the type it is handed.
 *
 * Refusal reason words: manifest-refused, uncontained, contract-unsupported,
 * world-writable, foreign-owner, require-failed, class-collision, class-missing,
 * class-not-declared.
 */
final class PluginLoader
{
    /**
     * Per QuarantineLedger instance: the paths that ledger's earlier load() calls
     * quarantined. A ledger instance is one launch, so a second call on it must
     * not find the first call's mark and require the file.
     *
     * @var ?\WeakMap<QuarantineLedger, array<string, true>>
     */
    private static ?\WeakMap $firstSightByLedger = null;

    /**
     * Load every trusted plugin's entrypoints of $kinds into this process.
     *
     * One trust list, one quarantine ledger, one private-bytes door: the kind
     * only selects which manifest section is read. Within one bundle, a path
     * named under two kinds yields ONE row, the first kind's, so a quarantine
     * notice or a refusal never counts the same file twice. Across trusted
     * candidates, a path is quarantined or required at most once per launch, so
     * the second bundle cannot run a file the first has only just seen.
     *
     * A ledger instance is one launch: the first sight is recorded once for it,
     * and any later call on the same instance lists that path as quarantined
     * again without recording it or requiring it. A later launch reads the marks
     * from disk through a fresh ledger, and only then does the file load.
     *
     * Two trusted names that reach one file (a symlinked plugin directory, or a
     * manifest nested under another) yield ONE LOADED ROW PER NAME, by design:
     * each name is a trusted plugin and the file is declared once. A host that
     * builds one instance per loaded row must dedupe on the class it was handed,
     * because the loader does not refuse the repeat.
     *
     * @param list<PluginCandidate>            $candidates    discovered plugins
     * @param QuarantineLedger                 $ledger        the seen-ledger backing quarantine
     * @param list<string>                     $kinds         manifest entrypoint kinds to read
     * @param array<string, class-string>      $kindInterfaces the interface each requested kind is mapped to; the
     *                                                         loader only requires that a mapping exists, and the
     *                                                         caller checks the type of what it is handed
     * @param int                              $hostContract  the host's contract integer; a declared contract must equal this integer exactly, so an unknown value is refused
     * @param ?int                             $processUid    the uid the bytes must belong to; null reads the process's own
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
        ?int $processUid = null,
    ): LoadResult {
        foreach ($kinds as $kind) {
            if (!isset($kindInterfaces[$kind])) {
                // A kind with no interface mapping is a caller error, not a plugin
                // refusal: the caller names the kinds it reads.
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

        // Launch-wide, not per candidate: two trusted names can reach one file,
        // and the second must not find the first's quarantine mark and require it.
        /** @var array<string, string> $pathState realpath => 'quarantined' | 'required' | 'failed' */
        $pathState = [];
        /** @var array<string, string> $requireErrors realpath => diagnostic of the failed require */
        $requireErrors = [];
        /** @var array<string, string> $collisions realpath => the type another file already declares */
        $collisions = [];

        self::$firstSightByLedger ??= new \WeakMap();
        /** @var array<string, true> $firstSight paths this ledger's earlier calls quarantined in this launch */
        $firstSight = self::$firstSightByLedger[$ledger] ?? [];

        foreach ($candidates as $candidate) {
            if (!$trust->contains($candidate->name)) {
                continue; // trust is by exact name, never case-folded
            }

            $manifest = $candidate->manifest();
            if ($manifest === null) {
                // No manifest file at all is not a refusal: discovery never listed
                // such a directory, so it owes no row. A manifest that is present
                // but refused still owes every requested kind a row, or the host
                // would see the plugin in no bucket at all.
                if (!PluginManifest::hasManifest($candidate->dir)) {
                    continue;
                }

                foreach ($kinds as $kind) {
                    $refusals[] = ['bundle' => $candidate->name, 'path' => $candidate->dir, 'class' => '', 'kind' => $kind, 'reason' => 'manifest-refused'];
                }
                continue;
            }

            $contract = $manifest->contract();
            $unsupported = $contract !== null && $contract !== $hostContract;
            $declared = $manifest->entrypoints();

            // Per candidate: one path, one row for this bundle, however many kinds
            // name it. The first kind in $kinds wins the row.
            $seenPaths = [];

            foreach ($kinds as $kind) {
                foreach ($declared[$kind] ?? [] as $entry) {
                    if (isset($seenPaths[$entry->path])) {
                        continue;
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

                    $private = PrivateBytes::refusal($entry->path, $processUid);
                    if ($private !== null) {
                        $refusals[] = $where + ['reason' => $private];
                        continue;
                    }

                    if (!isset($pathState[$entry->path])) {
                        if (isset($firstSight[$entry->path])) {
                            // An earlier call on this ledger already saw it this launch.
                            $pathState[$entry->path] = 'quarantined';
                        } elseif (!$ledger->seen($entry->path)) {
                            // Quarantine first: recorded before the require, so the
                            // NEXT launch loads it and this launch only lists it.
                            // A ledger that cannot persist must not abort the launch: the mark
                            // still holds for this launch, so the file is listed and never run.
                            try {
                                $ledger->record($entry->path);
                            } catch (\Throwable) {
                            }
                            $pathState[$entry->path] = 'quarantined';
                            $firstSight[$entry->path] = true;
                            self::$firstSightByLedger[$ledger] = $firstSight;
                        } elseif (($collision = DeclaredClasses::declaredElsewhere($entry->path)) !== null) {
                            // Checked before require_once: a redeclared type is a fatal
                            // compile error that no catch below can reach.
                            $pathState[$entry->path] = 'collision';
                            $collisions[$entry->path] = $collision;
                        } else {
                            try {
                                require_once $entry->path;
                                $pathState[$entry->path] = 'required';
                            } catch (\Throwable $e) {
                                // One throwing plugin file must not abort the whole load;
                                // the diagnostic travels AS DATA in the row.
                                $pathState[$entry->path] = 'failed';
                                $requireErrors[$entry->path] = ErrorLine::of($e, 'plugin require ' . $entry->class);
                            }
                        }
                    }

                    if ($pathState[$entry->path] === 'quarantined') {
                        $quarantined[] = $where;
                        continue;
                    }

                    if ($pathState[$entry->path] === 'failed') {
                        $refusals[] = $where + ['reason' => 'require-failed', 'error' => $requireErrors[$entry->path]];
                        continue;
                    }

                    if ($pathState[$entry->path] === 'collision') {
                        $refusals[] = $where + ['reason' => 'class-collision', 'error' => "'" . $collisions[$entry->path] . "' is already declared by another file"];
                        continue;
                    }

                    if ($entry->class === '') {
                        $refusals[] = $where + ['reason' => 'class-missing'];
                        continue;
                    }

                    // The class must exist AND be declared by this entry's file. A
                    // class another plugin's file declared is not this plugin's.
                    if (!\class_exists($entry->class, false)
                        || (new \ReflectionClass($entry->class))->getFileName() !== $entry->path) {
                        $refusals[] = $where + ['reason' => 'class-not-declared'];
                        continue;
                    }

                    $loaded[] = $where;
                }
            }
        }

        return new LoadResult($loaded, $quarantined, $refusals);
    }
}
