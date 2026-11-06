<?php

declare(strict_types=1);

namespace SugarCraft\Code\Agents;

use RuntimeException;
use SugarCraft\Code\Agents\Live\AgentRunCards;
use SugarCraft\Code\Agents\Live\SubAgentTranscriptLog;
use SugarCraft\Code\Support\AtomicFileWriter;
use SugarCraft\Code\Support\ContainedPath;
use SugarCraft\Code\Support\HomeDirectory;

use function array_values;
use function dirname;
use function in_array;
use function is_dir;
use function is_link;
use function str_ends_with;
use function strcmp;
use function substr;
use function usort;

/**
 * The home-store for published {@see CapabilityCard} manifests — the local,
 * cross-session discovery surface of a2a-style agent cards (RS-AGENT-C4 v1).
 *
 * Layout: `~/.candy-code/capabilities/<session-segment>/<name>.json`, one
 * manifest file per agent. The root is derived as the sibling of the run
 * mailbox root (dirname of SubAgentTranscriptLog::defaultRoot()), NOT a
 * directory inside mailboxes/<session>/ — a capabilities/ subdir there would
 * impersonate a teammate id in AgentInbox's walk of that directory. As a
 * top-level neighbor it follows the same pinned-home test seam the cards
 * use and interferes with zero existing readers.
 *
 * Safety posture mirrors the run-card machinery it parallels:
 *  - files 0600, directories 0700, both via AtomicFileWriter (mode is set
 *    on the temp inode before the publish rename — never a hand-rolled
 *    chmod, never a world-readable window);
 *  - discovery is a NAMES-ONLY walk: directory names and file names first,
 *    content read only for regular non-link .json files under the root,
 *    size-capped, and every parsed manifest must identify itself — a card
 *    whose name disagrees with its file name is refused (identity law, same
 *    shape as AgentRunCards skipping a card whose runId disagrees with its
 *    directory);
 *  - publish is best-effort per card: an unwritable or planted-link target
 *    is skipped, never fatal — a missing manifest costs discovery, not the
 *    session;
 *  - absent store discovers nothing: every pre-existing path stays
 *    byte-identical when nobody publishes (opt-out by non-use).
 *
 * V1 SCOPE: local + cross-session only. This class touches no network and
 * nothing read here is trusted — see CapabilityCard's data-not-permission
 * law.
 */
final class CapabilityCardStore
{
    /** Directory name under ~/.candy-code holding the per-session buckets. */
    public const DIR_NAME = 'capabilities';

    /** A manifest larger than this is junk by construction, not by taste. */
    public const MAX_MANIFEST_BYTES = 65536;

    private function __construct()
    {
    }

    /**
     * Home-derived store root, or null when no owned home exists. The
     * explicit HomeDirectory::owned() consultation is load-bearing for the
     * ReadPathCensus OWNED_HOME claim below and fails the rest of this
     * class closed when the home is untrusted.
     */
    public static function defaultRoot(): ?string
    {
        if (HomeDirectory::owned() === null) {
            return null;
        }
        $logs = SubAgentTranscriptLog::defaultRoot();

        return $logs === null ? null : dirname($logs) . '/' . self::DIR_NAME;
    }

    /**
     * Write (or refresh) this session's capability manifests.
     *
     * @param list<CapabilityCard> $cards
     *
     * @return list<string> names actually written, input order
     */
    public static function publish(string $sessionScope, array $cards, ?string $root = null): array
    {
        $root ??= self::defaultRoot();
        if ($root === null || $sessionScope === '') {
            return [];
        }

        $dir = $root . '/' . AgentRunCards::segment($sessionScope);
        if (is_link($dir)) {
            // A planted bucket stands in for our store; refusing to write
            // through it is the whole containment story for publish.
            return [];
        }

        $written = [];
        foreach ($cards as $card) {
            $path = $dir . '/' . $card->name . '.json';
            if (is_link($path)) {
                continue;
            }
            try {
                AtomicFileWriter::write($path, $card->serialize(), 0600);
            } catch (RuntimeException) {
                continue;
            }
            $written[] = $card->name;
        }

        return $written;
    }

    /**
     * Read every discoverable manifest under the root.
     *
     * @param list<string> $liveNames names in the caller's current roster;
     *                                when non-empty, an entry in the CALLER's
     *                                own bucket that is not named here is
     *                                surfaced with stale=true (decided:
     *                                surface-and-label, because a vanished
     *                                capability is itself useful knowledge —
     *                                pinned by test).
     *
     * @return list<array{session:string,name:string,card:CapabilityCard,current:bool,stale:bool}>
     *         sorted by (session, name)
     */
    public static function discover(?string $sessionScope = null, array $liveNames = [], ?string $root = null): array
    {
        $root ??= self::defaultRoot();
        if ($root === null) {
            return [];
        }

        $ownSegment = ($sessionScope === null || $sessionScope === '')
            ? null
            : AgentRunCards::segment($sessionScope);

        $rows = [];
        // An absent store is the ordinary opt-out state (nobody has ever run
        // the publish verb), so it answers quietly; the false-arm below only
        // catches a directory removed between the door-check and the scan.
        if (!is_dir($root)) {
            return [];
        }
        $sessions = scandir($root);
        if ($sessions === false) {
            return [];
        }
        foreach ($sessions as $sessionDir) {
            if ($sessionDir === '.' || $sessionDir === '..') {
                continue;
            }
            $dir = $root . '/' . $sessionDir;
            if (is_link($dir) || !is_dir($dir)) {
                continue;
            }
            $files = scandir($dir);
            if ($files === false) {
                continue;
            }
            foreach ($files as $fileName) {
                if (!str_ends_with($fileName, '.json')) {
                    continue;
                }
                $path = $dir . '/' . $fileName;
                if (is_link($path) || !ContainedPath::below($path, $root)) {
                    continue;
                }
                $size = filesize($path);
                if ($size === false || $size > self::MAX_MANIFEST_BYTES) {
                    continue;
                }
                $contents = file_get_contents($path);
                if ($contents === false) {
                    continue;
                }
                $card = CapabilityCard::fromJson($contents);
                $stem = substr($fileName, 0, -5);
                if ($card === null || $card->name !== $stem) {
                    continue;
                }

                $current = $ownSegment !== null && $sessionDir === $ownSegment;
                $rows[] = [
                    'session' => $sessionDir,
                    'name' => $card->name,
                    'card' => $card,
                    'current' => $current,
                    'stale' => $current && $liveNames !== [] && !in_array($card->name, $liveNames, true),
                ];
            }
        }

        usort($rows, static function (array $a, array $b): int {
            $bySession = strcmp($a['session'], $b['session']);

            return $bySession !== 0 ? $bySession : strcmp($a['name'], $b['name']);
        });

        return array_values($rows);
    }
}
