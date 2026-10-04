<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\Sandbox;

use SugarCraft\Crush\Support\ProcessContainment;

/**
 * The optional bubblewrap write-jail the `Bash` tool runs its commands in on
 * Linux (roadmap 5.12, the `bashSandbox` setting).
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
 * time the USER runs git), the project's `.sugar-crush/` (settings, hooks and
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
final readonly class Bubblewrap
{
    /** The `bashSandbox` vocabulary, default first. */
    public const MODE_OFF = 'off';
    public const MODE_ON = 'on';
    public const MODE_NO_NETWORK = 'no-network';
    public const MODES = [self::MODE_OFF, self::MODE_ON, self::MODE_NO_NETWORK];

    /**
     * Paths under the writable root that are re-bound read-only, relative to
     * that root. See the class doc-block for why each one is an escape hatch.
     */
    public const PROTECTED_PATHS = ['.sugar-crush', '.mcp.json'];

    /**
     * Paths re-bound read-only inside EVERY git directory the jail can write —
     * the root's own `.git`, or a linked worktree's gitdir and common dir —
     * relative to that git directory. One list for both shapes, so a worktree
     * cannot end up with a different set of escape hatches than a checkout.
     */
    public const PROTECTED_GIT_PATHS = ['hooks', 'config'];

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
    public function argv(string $writableRoot, bool $interactive = false, ?string $tmpDir = null): array
    {
        $root = self::normalise($writableRoot);
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
        $tmp = self::normalise($tmpDir ?? (string) getenv('TMPDIR'));
        if ($tmp !== '' && $tmp !== '/tmp' && $tmp !== '/') {
            if (str_starts_with($tmp, '/tmp/')) {
                array_push($argv, '--dir', $tmp);
            } elseif (is_dir($tmp)) {
                array_push($argv, '--bind', $tmp, $tmp);
            }
        }

        array_push($argv, '--bind', $root, $root);
        foreach (self::gitDirs($root) as $gitDir => $linked) {
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
        array_push($argv, '--chdir', $root, '--');

        return $argv;
    }

    /**
     * $shellCommand (an already-escaped `bash -c …` string) run inside the
     * jail, as one shell string — the shape both capture paths take.
     */
    public function wrap(string $shellCommand, string $writableRoot, bool $interactive = false, ?string $tmpDir = null): string
    {
        return implode(' ', array_map('escapeshellarg', $this->argv($writableRoot, $interactive, $tmpDir)))
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
     * The sentence the tool's description gains while this sandbox is on, so
     * the model knows why a write outside the root fails instead of retrying it.
     */
    public function describe(string $writableRoot): string
    {
        return sprintf(
            'Commands run inside a bubblewrap sandbox: the filesystem is read-only except %s, '
            . 'a private /tmp and $TMPDIR (and .git/hooks, .git/config, .sugar-crush/ and '
            . '.mcp.json stay read-only even there)%s. A write elsewhere fails with '
            . '"Read-only file system"; do not try to work around it — tell the user.',
            self::normalise($writableRoot),
            $this->network ? '' : '; there is NO network access',
        );
    }

    /**
     * The git directories a command in $root writes through: the root's own
     * `.git` directory for an ordinary checkout; for a LINKED worktree, whose
     * `.git` is a `gitdir:` file, that gitdir and the common dir above it.
     * [] outside a repository.
     *
     * THE `.git` FILE IS REPOSITORY CONTENT, so it is not believed on its own
     * word: a checkout committing `gitdir: /home/me` would otherwise have this
     * jail bind the operator's home WRITABLE. A linked gitdir is accepted only
     * when git's own back-pointer inside it (`<gitdir>/gitdir`) names this
     * root's `.git` — a file a clone cannot plant outside its own tree — and
     * the common dir only when it is the directory git keeps the registration
     * under (`<common>/worktrees/<name>`), never wherever `commondir` says.
     *
     * Keyed by directory; the value says whether it is a linked worktree's,
     * i.e. outside the root bind and in need of its own.
     *
     * @return array<string, bool>
     */
    private static function gitDirs(string $root): array
    {
        $dotGit = rtrim($root, '/') . '/.git';
        if (is_dir($dotGit)) {
            return [self::normalise($dotGit) => false];
        }
        if (!is_file($dotGit)) {
            return [];
        }
        $text = @file_get_contents($dotGit, length: 4096);
        if ($text === false || preg_match('/^gitdir:\s*(.+)$/m', $text, $m) !== 1) {
            return [];
        }
        $gitDir = self::absolute(trim($m[1]), $root);
        $backPointer = @file_get_contents($gitDir . '/gitdir', length: 4096);
        if (!is_dir($gitDir)
            || $backPointer === false
            || self::absolute(trim($backPointer), $gitDir) !== self::normalise($dotGit)
        ) {
            return [];
        }

        $commonDir = self::normalise(\dirname($gitDir, 2));
        if (basename(\dirname($gitDir)) !== 'worktrees' || !is_file($commonDir . '/HEAD')) {
            return [$gitDir => true];
        }

        return [$gitDir => true, $commonDir => true];
    }

    private static function absolute(string $path, string $base): string
    {
        $joined = str_starts_with($path, '/') ? $path : rtrim($base, '/') . '/' . $path;
        $real = realpath($joined);

        return self::normalise($real === false ? $joined : $real);
    }

    private static function normalise(string $path): string
    {
        if ($path === '') {
            return '';
        }
        $real = realpath($path);
        $path = $real === false ? $path : $real;

        return $path === '/' ? '/' : rtrim($path, '/');
    }
}
