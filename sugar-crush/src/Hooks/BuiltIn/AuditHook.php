<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Hooks\BuiltIn;

use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookResult;

/**
 * One appended line per finished tool call: when, which session, which tool,
 * what it was given, and the first 200 bytes of what it produced.
 *
 * THE DEFAULT PATH IS THE PRODUCTION PATH, WHICH IS WHY IT GETS THIS MUCH
 * ATTENTION. {@see \SugarCraft\Crush\Hooks\HookManager::registerBuiltIns()}
 * registers `new BuiltIn\AuditHook()` with no argument, and
 * `Cli\Bootstrap::hooks()` (private) and
 * {@see \SugarCraft\Crush\Backend\EngineBackend} both call it — so an
 * ordinary run writes every tool call it makes to whatever
 * {@see defaultLogFile()} answers. It is not a fallback nothing reaches.
 */
final readonly class AuditHook implements HookInterface
{
    /**
     * Leaf-name stem of the per-user directory {@see defaultLogFile()} lives
     * in. The effective uid is appended to it.
     */
    private const DEFAULT_DIRECTORY_STEM = 'sugar-crush-audit-';

    /** Leaf name of the default log inside that directory. */
    private const DEFAULT_LOG_LEAF = 'audit.log';

    /**
     * Mode the default directory is CREATED with — owner only.
     *
     * Set at creation rather than by a `chmod()` afterwards, deliberately:
     * a create-then-chmod leaves a window in which the directory exists
     * group- and other-readable, and the whole point of the directory is that
     * no window exists.
     *
     * THIS IS THE CREATE MODE ONLY. What an ALREADY-EXISTING directory has to
     * satisfy is {@see FOREIGN_ACCESS_BITS}, which is the weaker "nobody else
     * can reach it" rather than this exact number — see
     * {@see directoryIsOurs()} for why those are two requirements and not one.
     */
    private const DEFAULT_DIRECTORY_MODE = 0o700;

    /**
     * The bits that make a directory or a log reachable by somebody other than
     * its owner: group and other, `rwx` each.
     *
     * The accept path tests `mode & FOREIGN_ACCESS_BITS === 0` rather than
     * `mode === DEFAULT_DIRECTORY_MODE`, because the property being defended
     * is that no other user can get in — a 0500 or 0600 directory an operator
     * tightened FURTHER than this class does satisfies that and would fail an
     * equality test for no reason.
     */
    private const FOREIGN_ACCESS_BITS = 0o077;

    private string $logFile;

    /**
     * True when no caller named a path, so this class chose one and is
     * answerable for what is at the other end of it.
     *
     * A caller-supplied path is the CALLER'S choice and is written the way it
     * always was. The guards below apply only to the path this class invented,
     * because refusing a path an embedder deliberately passed — a symlink into
     * a log-rotation directory is an ordinary thing to want — would be this
     * class overruling its own API.
     */
    private bool $ownsPath;

    public function __construct(?string $logFile = null)
    {
        $this->logFile = $logFile ?? self::defaultLogFile();
        $this->ownsPath = $logFile === null;
    }

    /**
     * Where this hook writes when nobody says.
     *
     * WHAT THE JUSTIFICATION FOR THIS PATH SAID (E298, and the inventory row
     * in `tests/Support/ProcessUniqueTempNameTest.php` that carried it): the
     * default was `sys_get_temp_dir() . '/sugar-crush-audit.log'`, one fixed
     * name, and the fixed name was the INTENDED behaviour — "an audit log that
     * moves every run is not an audit log, and a caller who wants a private
     * one passes it in". What was wrong was a test that drove the production
     * default, wrote it and unlinked it.
     *
     * WHAT IS TRUE NOW (E328). That argument is still right and is kept: the
     * name is still fixed, so `tail -f` on it works across runs. What it did
     * not account for is WHO ELSE can reach the name. `sys_get_temp_dir()` is
     * world-writable and its leaf was shared by every user on the box, so a
     * local user could pre-create it as a symlink and have this hook append
     * through it, and — MEASURED on PHP 8.3.6 under the ordinary umask 0002 —
     * the file this hook creates is mode 0664, i.e. every other user could
     * READ a log carrying every tool's arguments and 200 bytes of its output.
     * Neither is a race. The fixed leaf now sits inside a per-user directory
     * this process creates 0700 and refuses to use if it is not its own, so
     * the name is still stable for the user who owns it and unreachable for
     * anyone else.
     *
     * WHAT WAS *NOT* THE HAZARD, stated because it was recorded as one and a
     * reader who fixes it will change the wrong line. Two `sugarcrush`
     * processes appending here neither truncate nor split each other's
     * records: {@see execute()} writes with `FILE_APPEND | LOCK_EX`, and
     * MEASURED on PHP 8.3.6 — 8 concurrent processes, 200 records each, 9000
     * bytes per record (well past both `PIPE_BUF` and one page), three takes —
     * all 1600 lines came back intact with the byte total exact and nothing
     * interleaved. Cross-process APPENDING was already safe. Cross-user
     * REACHABILITY was not.
     *
     * WHY THIS IS A STATIC METHOD RATHER THAN A CONSTANT: the uid is a
     * property of the running process, so there is nothing to declare.
     */
    public static function defaultLogFile(): string
    {
        return self::defaultLogDirectory() . '/' . self::DEFAULT_LOG_LEAF;
    }

    /**
     * The per-user directory {@see defaultLogFile()} sits in.
     *
     * Scoped by the EFFECTIVE uid, matching
     * {@see \SugarCraft\Crush\Support\ToolIpcFiles::sweep()}: the effective
     * uid is the one the kernel checks when the `mkdir()` and the append
     * actually run, so under setuid the real uid would name a directory this
     * process then cannot use.
     *
     * ON A POSIX-LESS BUILD THERE IS NO UID TO ASK FOR and the scope falls
     * back to the literal below. That is a real loss — every such user on one
     * box shares one directory again — and it is preferred to the
     * alternatives: `getmyuid()` answers the SCRIPT FILE's owner rather than
     * the process's, which for a shared installation is one answer for
     * everybody and a wrong one, and inventing a name from the environment
     * puts an attacker-supplied string in a path.
     *
     * WHAT KEEPS THAT SHARED DIRECTORY SAFE, CORRECTED. This paragraph said
     * "the 0700 creation and the OWNERSHIP REFUSAL in `append()` still hold on
     * that build". The ownership half is exactly backwards:
     * {@see directoryIsOurs()} has no uid to compare on a posix-less build and
     * SKIPS the comparison rather than failing it — there is no ownership
     * refusal there at all. WHAT IS TRUE: the mode is what holds. The first
     * user to arrive creates the directory {@see DEFAULT_DIRECTORY_MODE}, and
     * a second user reaches a directory that is a real directory, is not a
     * symlink and carries no {@see FOREIGN_ACCESS_BITS} — so it is ACCEPTED,
     * and the `file_put_contents()` in {@see append()} then fails on the
     * kernel's own permission check. WHY THE CONCLUSION SURVIVES THE
     * CORRECTION: the outcome is unchanged — the second user loses their audit
     * log rather than reading the first's — but a reader who believed the old
     * mechanism would have gone looking for a uid comparison to fix and found
     * none.
     */
    public static function defaultLogDirectory(): string
    {
        return self::directoryFor(\function_exists('posix_geteuid') ? \posix_geteuid() : null);
    }

    /**
     * {@see defaultLogDirectory()}'s name for a given effective uid, or for a
     * build that has none.
     *
     * A SEAM WITH ONE JOB: making the `null` arm reachable from a test. Every
     * build this suite runs on HAS `posix_geteuid()`, so with the lookup inline
     * the fallback scope was prose only — MEASURED at round 49, renaming the
     * literal was a mutation the entire `AuditHook` suite survived, and the
     * paragraph above explaining what that name costs was the sole record that
     * the arm existed. It is private because the shared-scope name is not
     * something a caller should be choosing.
     */
    private static function directoryFor(?int $uid): string
    {
        return \sys_get_temp_dir() . '/' . self::DEFAULT_DIRECTORY_STEM
            . ($uid === null ? 'noposix' : (string) $uid);
    }

    public function name(): string
    {
        return 'audit';
    }

    public function event(): HookEvent
    {
        return HookEvent::PostToolUse;
    }

    public function matcher(): string
    {
        return '.*';
    }

    public function execute(HookContext $context): HookResult
    {
        $entry = sprintf(
            "[%s] %s %s %s => %s\n",
            date('Y-m-d H:i:s'),
            $context->sessionId,
            $context->toolName,
            $context->toolInput,
            substr($context->toolOutput, 0, 200)
        );

        $this->append($entry);

        return HookResult::allow();
    }

    /**
     * Append $entry to the log, or decline to, answering which happened.
     *
     * THE RETURN VALUE IS DISCARDED BY {@see execute()} AND THAT IS THE
     * DECISION, not an oversight. This is a PostToolUse hook: the tool has
     * already run and its result is already on its way to the model, so there
     * is no verdict left for a failed audit write to influence, and a
     * `HookResult::deny()` here would be a lie about a call that happened.
     * Throwing would take down the run over a log line. What is left is
     * best-effort, and it is split into its own method so the refusal arms
     * have a name and a test can drive them
     * ({@see \SugarCraft\Crush\Tests\Hooks\AuditHookTest}).
     *
     * That the refusal is SILENT is a real cost and is recorded as such: an
     * operator whose audit directory has been squatted learns nothing until
     * they look. The alternative on the table was a line on stderr, which on a
     * squatted box would be one line per tool call for the whole run.
     */
    private function append(string $entry): bool
    {
        if ($this->ownsPath && !self::directoryIsOurs(\dirname($this->logFile))) {
            return false;
        }

        // A SYMLINK AT THE LOG'S OWN NAME, checked separately from the
        // directory because the two are planted by different opportunities:
        // the directory check answers "did somebody else get here first", and
        // this answers "is the leaf inside our own directory still a file".
        // PHP has no `O_NOFOLLOW`, so the refusal has to be explicit; it is
        // race-free in the case that matters because a 0700 directory this
        // uid owns is one nobody else can create an entry in.
        if ($this->ownsPath && \is_link($this->logFile)) {
            return false;
        }

        // AND THE LEAF ITSELF IS CREATED OWNER-ONLY, by narrowing the umask
        // around the create rather than by a `chmod()` after it — the same
        // pattern and the same reason as
        // {@see \SugarCraft\Crush\Support\ToolIpcFiles::write()}: a
        // chmod-afterwards leaves a window with the bytes on disk and readable.
        // `file_put_contents()` creates at 0666 minus the ambient umask, i.e.
        // 0664 on an ordinary box (MEASURED, PHP 8.3.6, umask 0002), and the
        // bytes are every tool's arguments and 200 bytes of its output.
        //
        // BELT AND BRACES RATHER THAN THE ONLY GUARD, stated so it is not read
        // as the load-bearing one: the directory this leaf sits in is refused
        // above unless it is unreachable by anybody else, and a mode on a file
        // nobody can traverse to is already moot. It matters when the leaf
        // outlives the directory's mode — a directory loosened after the file
        // was made, or the file moved.
        //
        // GATED ON $ownsPath like the two refusals above, for the same reason:
        // a caller-supplied path is written the way it always was, and
        // silently narrowing an embedder's own log file is this class making a
        // decision about a path it was handed.
        //
        // The umask is process-global, so this is not safe against a thread or
        // a concurrently-running fork of this process changing it. That is the
        // same exposure `ToolIpcFiles::write()` has and it is accepted for the
        // same reason: PHP has no per-open mode argument, and the alternative
        // (create, chmod) is the window this avoids.
        $previous = $this->ownsPath ? \umask(self::FOREIGN_ACCESS_BITS) : null;

        try {
            return @\file_put_contents($this->logFile, $entry, \FILE_APPEND | \LOCK_EX) !== false;
        } finally {
            if ($previous !== null) {
                \umask($previous);
            }
        }
    }

    /**
     * True when $directory is a real directory this process's effective user
     * owns, creating it {@see DEFAULT_DIRECTORY_MODE} when nothing is there.
     *
     * `is_link()` BEFORE `is_dir()`, because `is_dir()` follows symlinks and
     * would answer true for a planted link pointing at a directory — which is
     * exactly the shape being refused.
     *
     * The uid comparison is skipped, not failed, when the build has no
     * `posix_geteuid()`: same reasoning as
     * {@see \SugarCraft\Crush\Support\ToolIpcFiles::sweep()}, where the uid
     * filter is a courtesy on a shared temp dir rather than the safety
     * property. Here the safety property is the mode, which is checked on
     * every build.
     *
     * THE MODE IS CHECKED ON THE ACCEPT PATH AND NOT ONLY SET ON THE CREATE
     * PATH, and the two arms are not the same arm. WHAT THIS GUARD DID WHEN IT
     * LANDED (E328): `mkdir()` at {@see DEFAULT_DIRECTORY_MODE}, then owner,
     * and nothing looked at the mode of a directory that already existed.
     * MEASURED on PHP 8.3.6 under umask 0002, against this exact method: a
     * pre-existing `0777` directory owned by this euid was ACCEPTED, was not
     * repaired, and {@see append()} then created the log inside it at 0664.
     * The create arm runs once in the life of a machine; the accept arm runs
     * on every tool call of every run after that, so it was the whole of the
     * real exposure. An operator's own `mkdir -p` under umask 0022, a
     * container image, a restore from a backup and a group-shared `TMPDIR` all
     * arrive here and never touched the create arm.
     *
     * WHY IT REFUSES RATHER THAN REPAIRING, which is the tempting fix and is
     * wrong. A repair is a `chmod()` on `dirname($this->logFile)`, and this
     * method is handed whatever directory its caller names — the suite already
     * calls it with `sys_get_temp_dir()` itself. MEASURED on this box: `/tmp`
     * is mode 1777 and owned by root, so on any run whose euid is root the
     * ownership check above passes and a repair arm would `chmod('/tmp', 0700)`
     * — a machine-wide outage traded for a log line. Tightening is not safe
     * merely because it is tightening. Refusal is also what every other arm
     * here does (symlink, non-directory, foreign owner), and the cost is the
     * one {@see append()} already documents: the write is silently skipped.
     */
    private static function directoryIsOurs(string $directory): bool
    {
        \clearstatcache(true, $directory);

        if (\is_link($directory)) {
            return false;
        }

        if (!\is_dir($directory)) {
            if (\file_exists($directory)) {
                return false;
            }
            if (!@\mkdir($directory, self::DEFAULT_DIRECTORY_MODE, true) && !\is_dir($directory)) {
                return false;
            }
            \clearstatcache(true, $directory);
        }

        $uid = \function_exists('posix_geteuid') ? \posix_geteuid() : null;
        if ($uid !== null) {
            $owner = @\fileowner($directory);
            if ($owner === false || $owner !== $uid) {
                return false;
            }
        }

        $mode = @\fileperms($directory);

        return $mode !== false && ($mode & self::FOREIGN_ACCESS_BITS) === 0;
    }
}
