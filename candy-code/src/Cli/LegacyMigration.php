<?php

declare(strict_types=1);

namespace SugarCraft\Code\Cli;

use SugarCraft\Core\Util\LegacyEnv; // @rename-keep
use SugarCraft\Core\Util\LegacyPaths; // @rename-keep
use SugarCraft\Code\Support\HomeDirectory;
use SugarCraft\Code\Support\SafeDelete;

/**
 * First-launch compatibility with the pre-rename environment. // @rename-keep
 *
 * WHY THIS EXISTS. plan_rename.md Phase 8A item 3: the app was renamed
 * sugar-crush → candy-code, and users keep working out of state this rename // @rename-keep
 * did not move — environment variables exported under the old prefixes and
 * data directories sitting under the old names in their home. This class
 * folds both on the FIRST launch of the new binary, before anything else
 * reads env, resolves HOME, or opens `session.db`; every downstream reader
 * then only ever sees the new names and no call site needs dual-prefix
 * logic. The bin script calls {@see run()} immediately after the autoloader.
 *
 * WHY A WHOLE-DIRECTORY MOVE IS SAFE FOR THE SECURITY STATE. `rename()`
 * keeps the directory's owner and mode, so the private 0700 home survives
 * the move and every `HomeDirectory`/ownership check passes afterward
 * unchanged. `policy-snapshot.json` stores content FINGERPRINTS plus trusted
 * roots and hook identities — never config-dir paths — so a moved config
 * does not read as "policy changed while no process was watching" and
 * triggers neither the notice nor the project-hook quarantine; `mcp-trust.json`
 * pins hashed MCP entries, not the directory. `tests/Cli/LegacyMigrationTest`
 * asserts both halves (identical record after the move, fingerprints still
 * match the moved bytes) rather than trusting this paragraph.
 *
 * WHY A RUNNING SERVER BLOCKS THE MOVE. A live pre-rename server holds
 * `~/.sugar-crush/server/server.lock` via `flock` and has paths under the old  @rename-keep
 * directory open; moving the directory out from under it would split its
 * state across two trees. `LegacyPaths::migrate` never merges, so a second
 * partial launch would also be unrecoverable — refusing with an actionable
 * notice is the only safe answer, and it re-succeeds on the next launch
 * after the server exits.
 *
 * @see LegacyEnv   the shared env-prefix fold this delegates to
 * @see LegacyPaths the shared whole-directory move this delegates to
 */
final class LegacyMigration
{
    /**
     * Fold deprecated env vars, then move the home-level state directories.
     *
     * Every notice goes to $stderr directly rather than through
     * {@see RuntimeNoticeSink}: this runs BEFORE the TUI exists, and the
     * sink's inbox drops everything recorded while unarmed — stderr is the
     * only channel that provably reaches the person at the terminal at this
     * point in the launch.
     *
     * @param resource $stderr
     */
    public static function run($stderr): void
    {
        foreach (LegacyEnv::import(['SUGARCRUSH_' => 'CANDY_CODE_', 'SUGAR_CRUSH_' => 'CANDY_CODE_']) as $old) { // @rename-keep
            fwrite($stderr, "candy-code: env {$old} is deprecated, use the CANDY_CODE_ prefix\n"); // @rename-keep
        }

        // Same ownership gate Bootstrap::trustedConfigDirPath() uses — a
        // world-writable or foreign-owned HOME must never be migrated into.
        $owned = HomeDirectory::owned();
        if ($owned === null) {
            return;
        }

        // owned() already returns the rtrimmed directory.
        $home = $owned;

        // A running pre-rename server holds ~/.sugar-crush/server/server.lock // @rename-keep
        // (flock LOCK_EX|LOCK_NB, see Server\StateDir). Never move under it.
        if (self::serverRunning($home . '/.sugar-crush/server/server.lock')) { // @rename-keep
            fwrite($stderr, "candy-code: a sugarcrush server is still running; quit it, then relaunch to migrate ~/.sugar-crush\n"); // @rename-keep

            return;
        }

        foreach ([
            [$home . '/.sugar-crush', $home . '/.candy-code'], // @rename-keep
            [$home . '/.config/sugarcraft-crush', $home . '/.config/candy-code'], // @rename-keep
            [$home . '/.local/share/sugar-crush', $home . '/.local/share/candy-code'], // @rename-keep
        ] as [$from, $to]) {
            // ROOTFIX: a rename is a move of the NAME, and a legacy name that
            // is a symlink escaping the owned home is not ours to move —
            // replacing or relocating a foreign link (or following one into a
            // directory outside $home) is exactly the shape a planted
            // ~/.sugar-crush takes. '/' itself can no longer be $home at all
            // (HomeDirectory::owned() refuses it); this covers the rest.
            if ((is_link($from) && !SafeDelete::deletable($from, $home))
                || (is_link($to) && !SafeDelete::deletable($to, $home))
            ) {
                fwrite($stderr, "candy-code: legacy path '{$from}' involves a symlink outside the owned home; not migrated\n");

                continue;
            }
            if (($n = LegacyPaths::migrate($from, $to)) !== null) {
                fwrite($stderr, "candy-code: {$n}\n");
            }
        }
    }

    /**
     * Whether SOME process holds the pre-rename server lock right now.
     *
     * Probed by trying it, mirroring {@see \SugarCraft\Code\Server\StateDir::lockHeldElsewhere()}:
     * the lock file is opened only when it already exists, `flock(LOCK_EX |
     * LOCK_NB)` is attempted, and a lock this process COULD take is nobody's
     * and is released again at once. True only when the lock is demonstrably
     * held by someone else; an unreadable path, an absent file, and an open
     * that fails all answer false (the plan's rule — refuse only on proof of
     * a live holder, never on suspicion, because the suspicion case blocks
     * the migration of a directory that is provably nothing's).
     */
    public static function serverRunning(string $lockPath): bool
    {
        if (@\lstat($lockPath) === false) {
            return false;
        }

        $handle = @\fopen($lockPath, 'c');
        if ($handle === false) {
            return false;
        }

        $held = !\flock($handle, \LOCK_EX | \LOCK_NB);
        if (!$held) {
            \flock($handle, \LOCK_UN);
        }
        \fclose($handle);

        return $held;
    }
}
