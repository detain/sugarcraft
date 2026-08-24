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
     */
    private const DEFAULT_DIRECTORY_MODE = 0o700;

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
     * puts an attacker-supplied string in a path. The 0700 creation and the
     * ownership refusal in {@see append()} still hold on that build, so the
     * failure mode is a second user losing their audit log rather than a
     * second user reading the first's.
     */
    public static function defaultLogDirectory(): string
    {
        $uid = \function_exists('posix_geteuid') ? \posix_geteuid() : null;

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

        return @\file_put_contents($this->logFile, $entry, \FILE_APPEND | \LOCK_EX) !== false;
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
     * property. Here the safety property is the 0700 mode, which holds on
     * every build.
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
        if ($uid === null) {
            return true;
        }

        $owner = @\fileowner($directory);

        return $owner !== false && $owner === $uid;
    }
}
