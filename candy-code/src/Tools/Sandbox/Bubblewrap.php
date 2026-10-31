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
 * NESTED USER NAMESPACES ARE BLOCKED BY DEFAULT. A mount jail is only as good
 * as its last mount: the classic way back out is for the jailed command to
 * create a FRESH user namespace, which grants it `CAP_SYS_ADMIN` over its own
 * view of the tree. Where the host's bwrap accepts it (bwrap ≥ 0.8), argv()
 * therefore adds `--unshare-user --disable-userns` ({@see HARDENED_FLAGS}) so a
 * command cannot create one at all. That is the hardened flag SET, and the
 * probe decides whether this host supports it: it runs the hardened set first
 * and, only if that fails, the plain set, and the memo records the answer — so
 * an older or setuid build still jails (unhardened, and {@see describe()} says
 * so) rather than refusing. The user-tier `bashSandboxNestedUserns` setting is
 * the one thing that drops the two flags, for an operator who deliberately
 * runs rootless podman or a Chromium sandbox inside `Bash`.
 *
 * THE `--config` FILE IS JAIL READING MATERIAL. A `--config <file>` that sits
 * UNDER the writable root would otherwise be a file a jailed command can
 * rewrite before the next launch — and that file can carry `bashSandbox: off`,
 * the one setting that removes this class. {@see SandboxFactory} hands the
 * override path to {@see withProtectedPaths()} and argv() re-binds it read-only
 * when it falls under the root; a file outside it is untouched, because the
 * `--ro-bind / /` over the whole filesystem already keeps it read-only. The
 * same is true of `~/.candy-code` itself, which `PROTECTED_PATHS` re-binds
 * whenever the root is `$HOME`.
 *
 * FAIL CLOSED. A host can carry `bwrap` and still refuse to run it — Ubuntu
 * 24.04's AppArmor `apparmor_restrict_unprivileged_userns` makes every
 * unprivileged call die with "setting up uid map: Permission denied". So
 * presence is not capability: {@see probe()} runs the exact flag set once per
 * process over `/bin/true` (the hardened set first, then the plain set only if
 * that fails), and a configured sandbox that cannot start makes the tool REFUSE
 * the command rather than run it unconfined (nanobot's rule: "a configured
 * backend that cannot start must fail, not silently execute without
 * isolation"). The fallback is DOWNWARD to a weaker jail, never OUT to no jail.
 *
 * SHELL STARTUP FILES ARE NOT A HOLE. `Bash` runs a non-login, non-interactive
 * `bash -c`, which reads no profile and no rc file (the only file the jail
 * would have to protect is the one `$BASH_ENV` names, and the bash that
 * sources it is already the jailed one).
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

    /**
     * The two flags that stop a jailed command from creating a fresh user
     * namespace — the usual route back out of a mount jail (see the class
     * doc-block). Shipped as one constant because three places read the SAME
     * pair: {@see argv()}'s hardened arm, the probe's first stage, and the
     * tests that pin both. bwrap gained `--disable-userns` in 0.8; an older or
     * setuid build rejects the pair, which is what the probe's second stage is
     * for.
     *
     * @var list<string>
     */
    public const HARDENED_FLAGS = ['--unshare-user', '--disable-userns'];

    /**
     * The verdict memo: probe key → [why the jail cannot start (null when it
     * can), whether THIS host accepts {@see HARDENED_FLAGS}]. A property of the
     * host and the binary, never of one command, which is why {@see argv()}
     * may read it and why an unanswered key is read as supported — the
     * hardened set is the safer default, and production always probes (through
     * `Bash::sandboxRefusal()`) before it wraps.
     *
     * It is a function-local static behind a by-reference accessor rather than
     * a class property because `final readonly class` makes every declared
     * property readonly, and a readonly static cannot carry a default — the
     * memo needs to start empty and be written by {@see probe()}.
     *
     * @return array<string, array{0: ?string, 1: bool}>
     */
    private static function &probeVerdicts(): array
    {
        static $verdicts = [];

        return $verdicts;
    }

    private function __construct(
        public string $binary,
        public bool $network,
        public bool $nestedUserns,
        public array $protectedPaths,
    ) {
    }

    /**
     * $binary '' resolves `bwrap` on PATH at call time; a test or an embedder
     * names its own. $nestedUserns is `bashSandboxNestedUserns`: true lets the
     * jailed command create user namespaces, so {@see HARDENED_FLAGS} are
     * dropped. $protectedPaths are absolute paths — typically the `--config`
     * file — that must stay read-only when they fall under the writable root.
     *
     * @param list<string> $protectedPaths
     */
    public static function new(
        string $binary = '',
        bool $network = true,
        bool $nestedUserns = false,
        array $protectedPaths = [],
    ): self {
        return new self($binary, $network, $nestedUserns, $protectedPaths);
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
        return new self($this->binary, $network, $this->nestedUserns, $this->protectedPaths);
    }

    public function withBinary(string $binary): self
    {
        return new self($binary, $this->network, $this->nestedUserns, $this->protectedPaths);
    }

    /**
     * This jail with `bashSandboxNestedUserns` applied: true DROPS
     * {@see HARDENED_FLAGS}, so a jailed command may create its own user
     * namespace again (rootless podman, a Chromium sandbox). False — the
     * default, and the only value a project file could ever want — keeps them.
     */
    public function withNestedUserns(bool $nestedUserns): self
    {
        return new self($this->binary, $this->network, $nestedUserns, $this->protectedPaths);
    }

    /**
     * This jail with extra paths that must stay read-only inside the root,
     * {@see argv()}'s $readOnlyBinds by another route: the `--config` file is
     * decided once at launch, while the binds are applied per command, and a
     * path this jail carries must reach the pty spawn prefix too (which calls
     * {@see argv()} directly and passes no per-command binds).
     *
     * A path is only ever bound when it falls under the root it is jailed to —
     * {@see argv()} applies that test, so handing it a path outside the root is
     * harmless by construction, not by caller discipline.
     *
     * @param list<string> $protectedPaths
     */
    public function withProtectedPaths(array $protectedPaths): self
    {
        return new self($this->binary, $this->network, $this->nestedUserns, $protectedPaths);
    }

    /**
     * Whether THIS jail runs the hardened flag set on this host: opted out by
     * `bashSandboxNestedUserns`, or told not to by the probe. Unknown means
     * yes — the hardened set is the safer reading, and production probes before
     * it wraps (the same memo `Bash::sandboxRefusal()` fills).
     */
    public function hardened(): bool
    {
        if ($this->nestedUserns) {
            return false;
        }

        $verdicts = self::probeVerdicts();

        return $verdicts[$this->probeKey()][1] ?? true;
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
        return $this->jailArgv($writableRoot, $interactive, $tmpDir, $chdir, $readOnlyBinds, $this->hardened());
    }

    /**
     * The argv builder, with the hardened-flag decision taken as an argument.
     *
     * The probe needs BOTH flag sets from the same instance — it asks whether
     * this host accepts the hardened one by running it, then whether the jail
     * works at all by running the plain one — and a decision read from the memo
     * mid-ladder would answer its own question. Everything else is what
     * {@see argv()} has always built.
     *
     * @param list<string> $readOnlyBinds
     *
     * @return list<string>
     */
    private function jailArgv(
        string $writableRoot,
        bool $interactive,
        ?string $tmpDir,
        ?string $chdir,
        array $readOnlyBinds,
        bool $hardened,
    ): array {
        $root = JailPaths::normalise($writableRoot);
        $argv = [$this->resolvedBinary(), '--die-with-parent', '--unshare-all'];
        if ($hardened) {
            array_push($argv, ...self::HARDENED_FLAGS);
        }
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
        foreach (array_values($this->protectedPaths) as $path) {
            // The `--config` file, when this jail's root contains it. Outside
            // the root it is already read-only under `--ro-bind / /`, and
            // binding the root itself would undo the jail.
            $under = JailPaths::normalise($path);
            if ($under === '' || $under === $root || ($root !== '/' && !str_starts_with($under, $root . '/'))) {
                continue;
            }
            array_push($argv, '--ro-bind-try', $under, $under);
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
        return $this->jailWrap($shellCommand, $writableRoot, $interactive, $tmpDir, $chdir, $readOnlyBinds, $this->hardened());
    }

    /**
     * @param list<string> $readOnlyBinds
     */
    private function jailWrap(
        string $shellCommand,
        string $writableRoot,
        bool $interactive,
        ?string $tmpDir,
        ?string $chdir,
        array $readOnlyBinds,
        bool $hardened,
    ): string {
        return implode(' ', array_map('escapeshellarg', $this->jailArgv($writableRoot, $interactive, $tmpDir, $chdir, $readOnlyBinds, $hardened)))
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
     * TWO STAGES, DOWNWARD ONLY. The hardened flag set is tried first, because
     * it is the jail this build wants; only when it fails is the plain set
     * tried, and a plain-set failure is the refusal the tool prints — so an
     * old or setuid bwrap runs unhardened and says so, while a host where
     * bubblewrap cannot start at all still refuses every command. The verdict
     * is the same whichever arm a command would have run: the plain set working
     * is implied by the hardened set working.
     *
     * @param \Closure(string): array{exitCode: int, stderr: string} $run
     */
    public function probe(\Closure $run): ?string
    {
        // The OS fact answers before any binary lookup, per the interface
        // sentence and the Seatbelt twin — on a host where bubblewrap can
        // never exist, "Linux-only" is the true reason, and a PATH search
        // for it would be the wrong question first.
        if (PHP_OS_FAMILY !== 'Linux') {
            return 'bubblewrap is Linux-only and this host is ' . PHP_OS_FAMILY;
        }

        $binary = $this->resolvedBinary();
        if ($binary === '') {
            return 'bwrap was not found on PATH';
        }

        $key = $this->probeKey();
        $verdicts = &self::probeVerdicts();
        if (!array_key_exists($key, $verdicts)) {
            $hardened = $run($this->jailWrap('/bin/true', '/', false, '', null, [], true));
            if ($hardened['exitCode'] === 0) {
                $verdicts[$key] = [null, true];
            } else {
                $plain = $run($this->jailWrap('/bin/true', '/', false, '', null, [], false));
                $verdicts[$key] = $plain['exitCode'] === 0
                    ? [null, false]
                    : [trim($plain['stderr']) !== ''
                        ? trim($plain['stderr'])
                        : sprintf('%s exited %d', $binary, $plain['exitCode']), false];
            }
        }

        return $verdicts[$key][0];
    }

    /**
     * The memo slot this instance's host fact lives under: the binary and the
     * network flag, which are the two things that change the argv the probe
     * runs. Deliberately NOT $nestedUserns — whether a host ACCEPTS
     * {@see HARDENED_FLAGS} is the host's answer, and two instances that
     * disagree about wanting them must not probe (or refuse) twice.
     */
    private function probeKey(): string
    {
        return $this->resolvedBinary() . "\0" . ($this->network ? '1' : '0');
    }

    /**
     * The commonest Linux death, in the words the refusal has always used:
     * the binary is present, Ubuntu's kernel restricts the namespaces it
     * needs. Kept byte-stable — BashSandboxTest pins the whole sentence.
     */
    public function refusalRemedy(): string
    {
        return 'on Ubuntu, unprivileged user namespaces are restricted by AppArmor';
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
     *
     * The nested-usersns clause is stated only when it is TRUE OF THIS HOST:
     * the flags were wanted (the setting did not opt out) and the probe found
     * this bwrap cannot run them. A host nobody has probed yet, and an operator
     * who asked for nested namespaces, both read as the plain sentence — a
     * description that guesses at a host fact would be a claim the jail cannot
     * back up.
     */
    public function describe(string $writableRoot): string
    {
        $unhardened = !$this->nestedUserns && !$this->hardened()
            ? '; this host\'s bwrap cannot block nested user namespaces, so a jailed command can still create one'
            : '';

        return sprintf(
            'Commands run inside a bubblewrap sandbox: the filesystem is read-only except %s, '
            . 'a private /tmp and $TMPDIR (and .git/hooks, .git/config, .candy-code/ and '
            . '.mcp.json stay read-only even there)%s%s. A write elsewhere fails with '
            . '"Read-only file system"; do not try to work around it — tell the user.',
            JailPaths::normalise($writableRoot),
            $this->network ? '' : '; there is NO network access',
            $unhardened,
        );
    }
}
