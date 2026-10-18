<?php

declare(strict_types=1);

namespace SugarCraft\Code\Support;

/**
 * The suite's refusal of a root launch and of a temp root, as ONE pure decision.
 *
 * WHY THIS IS A CLASS IN `src/` AND NOT THREE LINES IN `tests/bootstrap.php`:
 * the decision has to be unit-testable at both polarities, and the euid that
 * decides it cannot be manufactured inside a test that itself must not run as
 * root — a `posix_geteuid() === 0` written inline in the bootstrap is a branch
 * no suite run can ever take. Extracted here, the same inputs the real launch
 * produces (an euid, a temp directory, an escape-hatch value) can be fed to it
 * directly, and `tests/Support/RootRunGuardTest.php` pins every polarity the
 * bootstrap cannot reach.
 *
 * WHAT IT REFUSES, and why each half is a refusal rather than a warning:
 *
 *  - A `TMPDIR` of `/`. The suite's sandbox is `sys_get_temp_dir() .
 *    '/sc_suite_tmp_<euid>'`, so a `/`-rooted temp makes the suite build its
 *    per-uid tree — and then its HOME store, its audit dir and its IPC sweep
 *    base — at the TOP LEVEL of the filesystem as root, where its 0700
 *    subdirectories are litter no other prune will ever reach. This half is
 *    NOT escapable: the escape hatch below says "I understand root", not "I
 *    understand wanting my root directory reorganised".
 *  - An effective uid of 0 without `CANDY_CODE_ALLOW_ROOT_TESTS=1`. The
 *    2026-10 root-run forensics (`/tmp/opencode/fix-1017/rootscan/findings.md`)
 *    measured why: every gate this suite's prune loops and trust anchors lean
 *    on is a permission gate, and permission gates do not bind uid 0 —
 *    `fileowner('/') === 0 === posix_geteuid()` makes even {@see
 *    HomeDirectory::owned()}'s ownership clause a no-op for root (the `/`-
 *    refusal added alongside this guard closes that specific one), the sticky
 *    bit inside `/` that protects `/bin` from every other uid is waved through,
 *    and the link-following {@see \SugarCraft\Code\Agents\WorktreeManager}
 *    traversal of the pre-fix era had DELETED a target directory's CONTENTS
 *    through a planted link when measured. Non-root masks every defect in that
 *    family into silence; root makes each one loud and unrecoverable. A suite
 *    that cannot tell the difference between those two worlds is not a suite
 *    worth running as root, so the default is a refusal and the hatch is an
 *    opt-in with the hazard named in the message.
 *
 * `posix_geteuid()` missing (a Windows build) reads as -1 here rather than
 * "0-suspect": the refusal exists for POSIX uid semantics, and a platform
 * without them is not the platform this hazard was measured on.
 *
 * @internal The bootstrap's own refusal block is what runs this; nothing in
 *           production calls it.
 */
final class RootRunGuard
{
    /** The only value that lets the suite run at uid 0 is exactly this one. */
    public const ALLOW_ENV = 'CANDY_CODE_ALLOW_ROOT_TESTS';

    /**
     * The pure decision: a refusal message, or null when the launch may run.
     *
     * @param int          $euid    the caller's effective uid (-1 = unknown platform)
     * @param string       $tempDir the temp directory the launch resolves to
     * @param string|null  $escape  the raw {@see ALLOW_ENV} value, null when unset
     */
    public static function refusalReason(int $euid, string $tempDir, ?string $escape): ?string
    {
        if ($tempDir === '/') {
            return "candy-code test suite refuses to run with TMPDIR=/:\n"
                . "the suite's sandbox is built as <tmp>/sc_suite_tmp_<uid>, which under a /-rooted\n"
                . "temp puts the suite's HOME store, audit directory and IPC sweep base at the TOP\n"
                . "LEVEL of the filesystem — the exact /-litter shape the 2026-10 root-run forensics\n"
                . "recorded. Point TMPDIR at a real directory instead; this half of the refusal is\n"
                . "not escapable with " . self::ALLOW_ENV . ".\n";
        }

        if ($euid === 0 && $escape !== '1') {
            return "candy-code test suite refuses to run as root (euid 0):\n"
                . "this suite's prune loops and trust anchors are permission gates, and uid 0 is\n"
                . "the account no permission gate binds — measured in the 2026-10 forensics, where\n"
                . "a root run of this family had the pre-fix WorktreeManager link traversal delete\n"
                . "a directory's contents THROUGH a planted symlink while HomeDirectory::owned()\n"
                . "accepted '/' as a home authority. Re-run as an unprivileged user, or set\n"
                . self::ALLOW_ENV . "=1 if you understand exactly what that means.\n";
        }

        return null;
    }

    /**
     * The decision for THIS process, reading the two environment inputs here.
     *
     * The reads live in `src/` deliberately: the escape hatch is then visible
     * to the environment roster's oracle (`EnvRosterDriftTest` derives the
     * documented set from `getenv()` calls in `src/`), which is what forces its
     * `docs/ENVIRONMENT.md` row to exist and to stay true.
     *
     * The temp directory is taken from `getenv('TMPDIR')` before falling back
     * to `sys_get_temp_dir()` so that calling this from the top of
     * `tests/bootstrap.php` does not resolve — and thereby CACHE — the temp
     * directory on an interpreter where nothing has asked yet; the bootstrap's
     * resolve-then-export ordering contract (`SuiteTempSandboxContractTest`)
     * owns that resolution.
     */
    public static function suiteRefusal(): ?string
    {
        $tempDir = getenv('TMPDIR');
        $escape = getenv(self::ALLOW_ENV);

        return self::refusalReason(
            \function_exists('posix_geteuid') ? posix_geteuid() : -1,
            $tempDir === false || $tempDir === '' ? sys_get_temp_dir() : $tempDir,
            $escape === false ? null : $escape,
        );
    }
}
