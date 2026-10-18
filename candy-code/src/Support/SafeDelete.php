<?php

declare(strict_types=1);

namespace SugarCraft\Code\Support;

/**
 * Shared guards for every sweep/prune/recursive-delete routine in the codebase.
 *
 * The 2026-10 root-run forensics (/tmp/opencode/fix-1017/rootscan/findings.md)
 * showed that each prune loop re-derived its own "is this safe to unlink"
 * answer, and every derivation missed at least one escape: an is_dir() that
 * followed a symlink out of the base, a root base that the uid check waved
 * through because root owns "/", a recursion with no depth bound. This class
 * is the single place those answers live. Routines call it; they do not
 * re-spell it.
 *
 * - baseIsSane() refuses '' and '/' and any base that is not a real
 *   directory strictly below the filesystem root, so a mis-resolved HOME or
 *   TMPDIR can never aim a sweep at the top of the tree.
 * - deletable() answers "may this entry be unlinked as part of sweeping
 *   base?" — symlinks are only deletable when their resolved target stays
 *   inside the base (a planted foreign link is the classic escape, and a
 *   dangling link resolves nowhere, so realpath() refuses it both ways).
 * - carriesSentinel() gates courtesy prunes: a loop that unlinks entries it
 *   did not create first checks for the marker file the creator stamped, so
 *   a re-pointed sandbox directory is left alone instead of emptied.
 *
 * The class deliberately performs no I/O other than stat() calls and the
 * creator-side touch(); it never unlinks anything itself — the routines do
 * the deleting, this decides what they may touch.
 */
final class SafeDelete
{
    /** Recursion ceiling for tree walks — deeper than any tree we create. */
    public const MAX_DEPTH = 64;

    /**
     * Marker file a creator writes into a directory it intends to courtesy-
     * prune later. Presence proves "we made this", absence proves nothing —
     * the pruner simply refuses to empty a directory it cannot vouch for.
     */
    public const SENTINEL = '.candy_code_sweep_sentinel';

    private function __construct()
    {
    }

    /**
     * Is $base a real directory strictly below the filesystem root?
     *
     * '/' is refused even though root runs own it (fileowner('/') === 0 ===
     * posix_geteuid() under root, so uid checks wave it through) and the
     * dirname comparison is belt-braces for any other self-contained base.
     */
    public static function baseIsSane(string $base): bool
    {
        if ($base === '') {
            return false;
        }

        $real = realpath($base);
        if ($real === false || $real === '/' || !is_dir($real)) {
            return false;
        }

        return dirname($real) !== $real;
    }

    /**
     * May $path be unlinked as part of sweeping $base?
     *
     * Both arms resolve through ContainedPath::within(), which realpaths
     * both sides: for a symlink that FOLLOWES to its target, so a foreign
     * escape (link → outside base) and a dangling link (no realpath at all)
     * both come back false. The regular-file arm additionally refuses
     * hardlinks to outside content? No — it cannot; link() creates a second
     * name with the same inode and realpath stays inside the base. That is
     * why this class never deletes and callers report survivors honestly:
     * unlinking the name is still the caller's semantics, and a hardlink
     * inside a base we created is ours by construction.
     */
    public static function deletable(string $path, string $base): bool
    {
        if (is_link($path)) {
            // The dangerous arm: is_dir() would have followed this into the
            // outside world. Only a link whose TARGET stays inside the base
            // may be swept.
            return ContainedPath::within($path, $base);
        }

        return ContainedPath::within($path, $base);
    }

    /**
     * Creator-side stamp: mark $base as ours so a later courtesy prune (see
     * carriesSentinel()) will touch its entries. Returns false when the base
     * is not sane — a refused stamp leaves the pruner locked out, which is
     * the fail-safe direction.
     */
    public static function stampSentinel(string $base): bool
    {
        if (!self::baseIsSane($base)) {
            return false;
        }

        return @touch(rtrim($base, '/') . '/' . self::SENTINEL);
    }

    /**
     * Does $base carry our creator sentinel? The sentinel itself must be a
     * plain file — a planted link named like the marker proves nothing.
     */
    public static function carriesSentinel(string $base): bool
    {
        if ($base === '') {
            return false;
        }

        $marker = rtrim($base, '/') . '/' . self::SENTINEL;

        return is_file($marker) && !is_link($marker);
    }
}
