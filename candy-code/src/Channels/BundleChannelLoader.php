<?php

declare(strict_types=1);

namespace SugarCraft\Code\Channels;

use SugarCraft\Code\Bundles\Bundle;
use SugarCraft\Code\Server\ErrorLog;
use SugarCraft\Code\Support\ContainedPath;

/**
 * The trust-gated loader for in-process channel code (ruling 2a+c).
 *
 * This is the ONLY place candy-code executes PHP it did not ship, and the
 * door is deliberately the narrowest one the format allows. Four laws hold
 * here, each inherited from a precedent the codebase already pays for:
 *
 * 1. NOTHING LOADS THAT NO MANIFEST NAMES. Files come exclusively from
 *    {@see Bundle::entrypoints()}, the reader that refuses un-kinded lists
 *    and paths escaping the bundle directory. No globbing, no sniffing.
 * 2. NOTHING LOADS THAT THE USER HAS NOT TRUSTED. A bundle's channel
 *    entrypoints load only when the bundle's NAME appears in
 *    Bootstrap::trustedBundleChannelNames() — the user-tier, non-layered,
 *    frozen-at-launch list (C-SEC-3.b). A checked-out project can never
 *    widen it; safe mode answers the empty list before anything reads this
 *    class, so `--safe-mode` loads zero plugin code by construction.
 * 3. FIRST SIGHT IS QUARANTINE. Even a trusted entrypoint is only LISTED
 *    on the launch that first reveals it — the hooks law's
 *    quarantine-first-launch semantics, copied honestly: the operator sees
 *    the notice, and the file loads at the NEXT launch. The seen-ledger
 *    lives in {@see ChannelsStore} (0600, user-owned). A manifest swap
 *    under a trusted name therefore cannot smuggle code in silently:
 *    every new path costs a launch of visible listing first.
 * 4. THE BYTES THEMSELVES MUST BE PRIVATE. Before `require_once`, the
 *    entrypoint file AND its parent directory must be owned by this
 *    process's uid and not world-writable — the same refusal
 *    Bootstrap::requirePrivatePolicyFile() applies to the settings that
 *    grant trust, re-asserted here because a trusted name says who opted
 *    in, not that the file is intact.
 *
 * The loader ANSWERS; it never announces. Every entry lands in exactly one
 * bucket — loaded, quarantined, or refused with a machine reason word — so
 * the caller renders notices through Lang in any locale. `class-missing`
 * and `class-not-declared` are refusals too: the manifest must name a class
 * the file actually declares, and a guessed class name is never honoured. An
 * entrypoint whose file THROWS at require time is refused `require-failed` with
 * its diagnostic carried in the row for the registry to log — an answer, never
 * an announcement.
 *
 * Collision refusal (a channel name or class clashing with a built-in or
 * another accepted bundle) is NOT here: that is the registry's job at
 * register time (ruling 2c), and the registry never overrides. This class
 * only decides what may become a class constant in this process at all.
 */
final class BundleChannelLoader
{
    /**
     * Load every trusted bundle's entrypoints of $kinds into this process.
     *
     * The GAP-3 subscriber entries ride the SAME gate as channel entries —
     * one trust list, one quarantine ledger, one private-bytes door; the
     * kind only selects which manifest section is read. A path named under
     * two kinds is still loaded (and recorded) exactly once.
     *
     * @param list<Bundle> $bundles        discovered bundles (any tier)
     * @param list<string> $trustedNames   exact origin names from the user-tier list
     * @param ChannelsStore $store         the seen-ledger backing quarantine
     * @param list<string> $kinds          manifest entrypoint kinds to read
     *
     * @return array{loaded: list<array{bundle: string, path: string, class: string, kind: string}>, quarantined: list<array{bundle: string, path: string, class: string, kind: string}>, refusals: list<array{bundle: string, path: string, class: string, kind: string, reason: string, error?: string}>}
     */
    public static function load(array $bundles, array $trustedNames, ChannelsStore $store, array $kinds = ['channel']): array
    {
        $loaded = [];
        $quarantined = [];
        $refusals = [];

        if ($trustedNames === []) {
            // No opt-in, no read: not even manifests get consulted. Safe mode
            // and the deny-all default both arrive here.
            return ['loaded' => $loaded, 'quarantined' => $quarantined, 'refusals' => $refusals];
        }

        foreach ($bundles as $bundle) {
            if (!\in_array($bundle->name, $trustedNames, true)) {
                continue; // trust is by exact name, never case-folded
            }

            $seenPaths = [];

            foreach ($kinds as $kind) {
                foreach ($bundle->entrypoints()[$kind] ?? [] as $entry) {
                    if (isset($seenPaths[$entry['path']])) {
                        continue; // one path, one load, however many kinds name it
                    }
                    $seenPaths[$entry['path']] = true;

                    $where = ['bundle' => $bundle->name, 'path' => $entry['path'], 'class' => $entry['class'], 'kind' => $kind];

                    if (!ContainedPath::below($entry['path'], $bundle->dir)) {
                        // Belt over braces: the reader already refused this, but a
                        // require_once gets its own containment assertion, not a
                        // borrowed one (this compare is what the census measures).
                        $refusals[] = $where + ['reason' => 'uncontained'];
                        continue;
                    }

                    $private = self::refuseLooseBytes($entry['path']);
                    if ($private !== null) {
                        $refusals[] = $where + ['reason' => $private];
                        continue;
                    }

                    if (!$store->seenEntrypoint($entry['path'])) {
                        // Quarantine first: recorded now so the NEXT launch loads
                        // it; this launch only lists it. A refusal after loading
                        // would still have executed the file, which is why the
                        // ledger is written BEFORE the require and why a loaded
                        // file that then fails the class check stays recorded —
                        // honest bookkeeping for the notice that follows.
                        $store->recordEntrypoint($entry['path']);
                        $quarantined[] = $where;
                        continue;
                    }

                    try {
                        require_once $entry['path'];
                    } catch (\Throwable $e) {
                        // m04: one throwing bundle file used to abort the whole
                        // boot, dimming every sibling. The diagnostic travels AS
                        // DATA in the row — the loader still only answers.
                        $refusals[] = $where + ['reason' => 'require-failed', 'error' => ErrorLog::line($e, 'channel require ' . $entry['class'])];

                        continue;
                    }

                    if ($entry['class'] === '') {
                        $refusals[] = $where + ['reason' => 'class-missing'];
                        continue;
                    }

                    if (!\class_exists($entry['class'], false)) {
                        $refusals[] = $where + ['reason' => 'class-not-declared'];
                        continue;
                    }

                    $loaded[] = $where;
                }
            }
        }

        return ['loaded' => $loaded, 'quarantined' => $quarantined, 'refusals' => $refusals];
    }

    /**
     * The private-bytes gate: the file and its directory must be ours and not
     * world-open. Returns a reason word or null when private. It guards class
     * entrypoints AND trusted-bundle hook data files — name-trust on the
     * manifest does not extend to bytes another account can rewrite, which is
     * why the channel trust gate alone is never the whole check (ruling D1's
     * reversal rides this gate for both surfaces, code-chat §10).
     */
    public static function refuseLooseBytes(string $path): ?string
    {
        foreach ([$path, \dirname($path)] as $target) {
            if (!\file_exists($target)) {
                continue; // a vanished file fails the require path, not this gate
            }

            if ((@\fileperms($target) & 0002) !== 0) {
                return 'world-writable';
            }

            if (\function_exists('posix_geteuid') && @\fileowner($target) !== false && @\fileowner($target) !== \posix_geteuid()) {
                return 'foreign-owner';
            }
        }

        return null;
    }
}
