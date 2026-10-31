<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\Sandbox;

use SugarCraft\Code\Support\ProcessContainment;

/**
 * The optional bubblewrap write-jail the `Bash` tool runs its commands in on
 * Linux (roadmap 5.12, the `bashSandbox` setting) — the Linux answer to the
 * {@see SandboxBackend} seam; {@see SandboxFactory} routes macOS to
 * {@see Seatbelt} instead, and the shared escape-hatch lists and git-dir
 * trust logic live in {@see JailPaths}, one copy for both backends.
 *
 * WHAT IT CONFINES, AND WHAT IT DOES NOT. The whole filesystem is bound
 * READ-ONLY and only the working root (the jail root of a worktree-isolated
 * teammate, the project root otherwise), a private `/tmp` and `$TMPDIR` are
 * writable — so a command can read anything this user can, but can only
 * change the tree it was asked to work on. It is a WRITE jail, not a secrecy
 * one: `~/.ssh` is still readable, which is why the credential env scrub
 * ({@see ProcessContainment::scrubbedEnv()}) stays in force underneath it.
 * Every namespace bwrap can unshare is unshared (`--unshare-all`); the network
 * is shared back unless the setting is `no-network`.
 *
 * ESCAPE HATCHES INSIDE THE WRITABLE ROOT are re-bound read-only over it
 * ("later binds win"): `.git/hooks` and `.git/config` (a hook or a
 * `core.fsmonitor`/`core.hooksPath` written there runs unsandboxed the next
 * time the USER runs git), the project's `.candy-code/` (settings, hooks and
 * agents a session could otherwise grant itself) and `.mcp.json`. Commits
 * still work: objects, refs and the index stay writable. In a linked worktree
 * `.git` is a file, so its gitdir and the common dir above it are bound
 * writable with the same two paths protected — otherwise every `git commit`
 * from a worktree-isolated teammate would fail on a read-only index — but only
 * once git's own back-pointer confirms the pairing ({@see gitDirs()}).
 *
 * FAIL CLOSED. A host can carry `bwrap` and still refuse to run it — Ubuntu
 * 24.04's AppArmor `apparmor_restrict_unprivileged_userns` makes every
 * unprivileged call die with "setting up uid map: Permission denied". So
 * presence is not capability: {@see probe()} runs the exact flag set once per
 * process over `/bin/true`, and a configured sandbox that cannot start makes
 * the tool REFUSE the command rather than run it unconfined (nanobot's rule:
 * "a configured backend that cannot start must fail, not silently execute
 * without isolation").
 *
 * PROCESS GROUP. The prefix runs INSIDE the capture's `setsid -w` group, so
 * the timeout's group kill still lands on `bwrap`; `--die-with-parent` plus
 * the unshared PID namespace then take everything the command started with
 * it, even though `--new-session` put that tree in a session of its own.
 *
 * Mirrors zed-industries/zed.sandbox.build_bwrap_args_with_sandbox_paths.
 */
final readonly class Bubblewrap implements SandboxBackend
{
    /**
     * The kernel interface Ubuntu 24.04 gates unprivileged user namespaces
     * behind — the sysctl named in the class doc-block, spelled out so
     * {@see appArmorRestrictsUserns()} and the doctor row read ONE constant
     * rather than each carrying their own copy of the path.
     */
    public const APPARMOR_RESTRICT_USERNS_PATH = '/proc/sys/kernel/apparmor_restrict_unprivileged_userns';

    /**
     * Paths under the writable root that are re-bound read-only, relative to
     * that root. Aliases onto {@see JailPaths} — the lists are the jail's
     * safety and every backend shares them; these names stay because the
     * bubblewrap argv below (and its tests) read them here.
     */
    public const PROTECTED_PATHS = JailPaths::PROTECTED_PATHS;

    /**
     * Paths re-bound read-only inside every git directory the jail can
     * write. Alias onto {@see JailPaths}; see its constant for the law.
     */
    public const PROTECTED_GIT_PATHS = JailPaths::PROTECTED_GIT_PATHS;

    private function __construct(
        public string $binary,
        public bool $network,
    ) {
    }

    /**
     * $binary '' resolves `bwrap` on PATH at call time; a test or an embedder
     * names its own.
     */
    public static function new(string $binary = '', bool $network = true): self
    {
        return new self($binary, $network);
    }

    /**
     * The sandbox a `bashSandbox` value asks for, or null for `off`.
     *
     * Null and `off` (and `false`) mean no sandbox, `true` is accepted as
     * `on`. ANY OTHER VALUE IS READ AS THE STRICTEST MODE, never as off: a
     * mistyped `"no-netwrok"` silently read as `off` would be the one typo
     * that switches a security control off without a word, while one read as
     * `no-network` fails loudly on the first command that needs the network.
     * (The settings editor refuses such a value before it is ever saved.)
     */
    public static function fromSetting(mixed $value): ?self
    {
        return match (true) {
            $value === null, $value === false, $value === self::MODE_OFF => null,
            $value === true, $value === self::MODE_ON => self::new(),
            default => self::new('', false),
        };
    }

    public function withNetwork(bool $network): self
    {
        return new self($this->binary, $network);
    }

    public function withBinary(string $binary): self
    {
        return new self($binary, $this->network);
    }

    /** The setting value this instance answers to. */
    public function mode(): string
    {
        return $this->network ? self::MODE_ON : self::MODE_NO_NETWORK;
    }

    public function name(): string
    {
        return 'bubblewrap';
    }

    /** The binary to run, or '' when none is configured or on PATH. */
    public function resolvedBinary(): string
    {
        return $this->binary !== '' ? $this->binary : ProcessContainment::locateOnPath('bwrap');
    }

    /**
     * The bwrap argv up to and including the `--` that precedes the command.
     *
     * $interactive drops `--new-session`: a pty-attached program needs the
     * pty as its controlling terminal, and a new session has none. The
     * TIOCSTI injection `--new-session` exists to stop cannot reach anything
     * there — that pty is private to the capture, and nothing reads it but
     * the transcript.
     *
     * @return list<string>
     */
    public function argv(
        string $writableRoot,
        bool $interactive = false,
        ?string $tmpDir = null,
        ?string $chdir = null,
        array $readOnlyBinds = [],
    ): array {
        $root = JailPaths::normalise($writableRoot);
        $argv = [$this->resolvedBinary(), '--die-with-parent', '--unshare-all'];
        if ($this->network) {
            $argv[] = '--share-net';
        }
        if (!$interactive) {
            $argv[] = '--new-session';
        }
        array_push($argv, '--ro-bind', '/', '/', '--dev', '/dev', '--proc', '/proc', '--tmpfs', '/tmp');

        // $TMPDIR must exist inside the jail or every mktemp fails: under
        // /tmp it is recreated in the private tmpfs, elsewhere it is bound
        // writable — the operator's own environment named it, not the
        // repository, and a temp dir that refuses writes is no temp dir.
        $tmp = JailPaths::normalise($tmpDir ?? (string) getenv('TMPDIR'));
        if ($tmp !== '' && $tmp !== '/tmp' && $tmp !== '/') {
            if (str_starts_with($tmp, '/tmp/')) {
                array_push($argv, '--dir', $tmp);
            } elseif (is_dir($tmp)) {
                array_push($argv, '--bind', $tmp, $tmp);
            }
        }

        array_push($argv, '--bind', $root, $root);
        foreach (JailPaths::gitDirs($root) as $gitDir => $linked) {
            // A linked worktree's git directories sit outside the root and
            // would otherwise stay read-only — with them every commit.
            if ($linked) {
                array_push($argv, '--bind', $gitDir, $gitDir);
            }
            foreach (self::PROTECTED_GIT_PATHS as $leaf) {
                array_push($argv, '--ro-bind-try', $gitDir . '/' . $leaf, $gitDir . '/' . $leaf);
            }
        }
        foreach (self::PROTECTED_PATHS as $relative) {
            $path = rtrim($root, '/') . '/' . $relative;
            array_push($argv, '--ro-bind-try', $path, $path);
        }
        foreach (array_values($readOnlyBinds) as $path) {
            array_push($argv, '--ro-bind-try', JailPaths::normalise($path), JailPaths::normalise($path));
        }
        // bwrap defaults to the caller's directory; the jail has always
        // pinned it to the root, and $chdir lets a later caller pin elsewhere.
        array_push($argv, '--chdir', $chdir === null ? $root : JailPaths::normalise($chdir), '--');

        return $argv;
    }

    /**
     * $shellCommand (an already-escaped `bash -c …` string) run inside the
     * jail, as one shell string — the shape both capture paths take.
     */
    public function wrap(
        string $shellCommand,
        string $writableRoot,
        bool $interactive = false,
        ?string $tmpDir = null,
        ?string $chdir = null,
        array $readOnlyBinds = [],
    ): string {
        return implode(' ', array_map('escapeshellarg', $this->argv($writableRoot, $interactive, $tmpDir, $chdir, $readOnlyBinds)))
            . ' ' . $shellCommand;
    }

    /**
     * Null when this sandbox starts on this host, else the reason it does not.
     *
     * $run spawns a shell string and returns its exit code and stderr — the
     * caller's own capture path, so the probe adds no spawn site of its own.
     * Memoised per binary and network flag for the life of the process: the
     * answer is a property of the host, and paying a spawn per command for it
     * would double every Bash call.
     *
     * @param \Closure(string): array{exitCode: int, stderr: string} $run
     */
    public function probe(\Closure $run): ?string
    {
        static $verdicts = [];

        $binary = $this->resolvedBinary();
        if ($binary === '') {
            return 'bwrap was not found on PATH';
        }
        if (PHP_OS_FAMILY !== 'Linux') {
            return 'bubblewrap is Linux-only and this host is ' . PHP_OS_FAMILY;
        }

        $key = $binary . "\0" . ($this->network ? '1' : '0');
        if (!array_key_exists($key, $verdicts)) {
            $result = $run($this->wrap('/bin/true', '/', false, ''));
            $verdicts[$key] = $result['exitCode'] === 0
                ? null
                : (trim($result['stderr']) !== ''
                    ? trim($result['stderr'])
                    : sprintf('%s exited %d', $binary, $result['exitCode']));
        }

        return $verdicts[$key];
    }

    /**
     * Whether this host restricts unprivileged user namespaces — the commonest
     * reason a PRESENT bwrap still refuses (the class doc-block names Ubuntu
     * 24.04's AppArmor default). Null when the file cannot be read: a kernel
     * without the interface answers "unknown", never "no".
     *
     * $path is the test seam over the kernel interface; the default is the
     * real one. The read is bounded because a sysctl value is a digit and a
     * newline, and an unreadable file must not become a hung doctor row.
     */
    public static function appArmorRestrictsUserns(string $path = self::APPARMOR_RESTRICT_USERNS_PATH): ?bool
    {
        $raw = @file_get_contents($path, length: 16);

        return $raw === false ? null : trim($raw) === '1';
    }

    /**
     * The sentence the tool's description gains while this sandbox is on, so
     * the model knows why a write outside the root fails instead of retrying it.
     */
    public function describe(string $writableRoot): string
    {
        return sprintf(
            'Commands run inside a bubblewrap sandbox: the filesystem is read-only except %s, '
            . 'a private /tmp and $TMPDIR (and .git/hooks, .git/config, .candy-code/ and '
            . '.mcp.json stay read-only even there)%s. A write elsewhere fails with '
            . '"Read-only file system"; do not try to work around it — tell the user.',
            JailPaths::normalise($writableRoot),
            $this->network ? '' : '; there is NO network access',
        );
    }
}
