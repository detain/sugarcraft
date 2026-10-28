<?php

declare(strict_types=1);

namespace SugarCraft\Code\Attachments;

use SugarCraft\Code\Support\ContainedPath;

/**
 * The memoized non-git file listing behind `fs.search` (plan_comparison
 * C-WEB-7.c, source WEB-5-P3).
 *
 * Before this class the listing was rebuilt by walking the tree — up to
 * {@see MAX_ENTRIES} directory entries read, synchronously on the serve loop —
 * on EVERY `fs.search`. Typing `@` one character at a time in a large non-git
 * root paid that walk per keystroke and stalled every other client on the
 * loop. The listing is now this class's ONE product:
 * {@see listing()} returns every regular file among the first {@see MAX_ENTRIES}
 * entries read, in walk order, unfiltered, and memoizes it per
 * (realpath, showHidden) for {@see TTL_SECONDS}. {@see \SugarCraft\Code\Protocol\Methods\FsMethods::walk()}
 * screens that listing by needle and takes its first candidates — the same
 * result set the early-stopping walk produced (see its docblock for the
 * equivalence argument), now from a memo.
 *
 * The 2 s TTL is a deliberate staleness bound, not a bug: a file the agent
 * just wrote can take up to 2 s to surface under `@`. A caller that wants the
 * fresh tree (a turn end, for instance) calls {@see forget()}.
 *
 * Process-global statics, cleared by {@see forget()}, exactly like
 * {@see MentionIndex}: one serve process, one workspace discipline.
 */
final class WorkspaceWalk
{
    /** Entries (directories and files) one listing reads at most. */
    public const MAX_ENTRIES = 20_000;

    /** Directory levels below the root the walk descends at most. */
    public const MAX_DEPTH = 24;

    /** How long one root's listing stays memoized, in seconds. */
    public const TTL_SECONDS = 2.0;

    /** Root/listing pairs held at once; the first-in entry gives way. */
    public const MAX_ROOTS = 8;

    /**
     * Memoized listings keyed by `realpath|showHidden`, insertion-ordered.
     *
     * @var array<string, array{listing: list<string>, stamp: float}>
     */
    private static array $listings = [];

    /** The clock seam: null reads `microtime(true)` (tests pin a fake). */
    private static ?\Closure $clock = null;

    private function __construct()
    {
    }

    /**
     * Every regular file under $root among the first {@see MAX_ENTRIES}
     * entries read, root-relative, in walk order, unfiltered.
     *
     * A memo younger than {@see TTL_SECONDS} answers without touching the
     * filesystem, so keystrokes inside the window share one walk.
     *
     * @return list<string>
     */
    public static function listing(string $root, bool $showHidden): array
    {
        $key = self::key($root, $showHidden);
        $memo = self::$listings[$key] ?? null;
        if ($memo !== null && self::now() - $memo['stamp'] < self::TTL_SECONDS) {
            return $memo['listing'];
        }

        $listing = self::collect($root, $showHidden);
        self::$listings[$key] = ['listing' => $listing, 'stamp' => self::now()];
        while (\count(self::$listings) > self::MAX_ROOTS) {
            \array_shift(self::$listings);
        }

        return $listing;
    }

    /**
     * Drop the memo: one root's keys (both hidden modes), or every root when
     * $root is null. The seam a later turn-end refresh can call alongside
     * {@see MentionIndex::forget()}.
     */
    public static function forget(?string $root = null): void
    {
        if ($root === null) {
            self::$listings = [];

            return;
        }
        $real = \realpath($root);
        if ($real === false) {
            return;
        }
        unset(self::$listings[$real . '|0'], self::$listings[$real . '|1']);
    }

    /** Test seam: a fixed clock so TTL edges are driven, not waited for. */
    public static function setClockForTesting(?\Closure $clock): void
    {
        self::$clock = $clock;
    }

    private static function now(): float
    {
        $clock = self::$clock;

        return $clock === null ? \microtime(true) : (float) $clock();
    }

    private static function key(string $root, bool $showHidden): string
    {
        return ((\realpath($root) ?: $root) . '|' . ($showHidden ? '1' : '0'));
    }

    /**
     * The walk itself — containment re-checks, link skips and the depth cap
     * moved verbatim from the pre-memo `FsMethods::walk()`.
     *
     * @return list<string>
     */
    private static function collect(string $root, bool $showHidden): array
    {
        $paths = [];
        $read = 0;

        /** @var list<array{0: string, 1: int}> $stack absolute directory, depth */
        $stack = [[$root, 0]];
        while ($stack !== []) {
            /** @var array{0: string, 1: int} $frame */
            $frame = \array_pop($stack);
            [$dir, $depth] = $frame;
            // Every frame is built by concatenating non-link components onto
            // the resolved root (links are skipped at readdir below), so it is
            // inside by construction; the re-check is the browser's own
            // discipline stated out loud, guarding a future edit that lets a
            // component become attacker-influenced.
            if (!ContainedPath::within($dir, $root)) {
                continue;
            }
            $handle = @\opendir($dir);
            if ($handle === false) {
                continue;
            }
            while (($entry = \readdir($handle)) !== false) {
                if (++$read > self::MAX_ENTRIES) {
                    \closedir($handle);

                    return $paths;
                }
                if ($entry === '.' || $entry === '..' || ($entry[0] === '.' && !$showHidden)) {
                    continue;
                }
                $absolute = $dir . '/' . $entry;
                if (\is_link($absolute)) {
                    continue;
                }
                if (\is_dir($absolute)) {
                    if ($depth < self::MAX_DEPTH) {
                        $stack[] = [$absolute, $depth + 1];
                    }

                    continue;
                }
                if (!\is_file($absolute)) {
                    continue;
                }
                $paths[] = \substr($absolute, \strlen($root) + 1);
            }
            \closedir($handle);
        }

        return $paths;
    }
}
