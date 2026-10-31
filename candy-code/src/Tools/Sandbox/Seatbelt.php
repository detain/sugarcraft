<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\Sandbox;

use SugarCraft\Code\Support\ProcessContainment;

/**
 * The macOS Seatbelt write-jail the `Bash` tool runs its commands in on
 * Darwin (the macOS arm of the `bashSandbox` setting, C-TOOL-4.b).
 *
 * WHAT IT IS. macOS ships its TrustedBSD sandbox as `/usr/bin/sandbox-exec`:
 * hand it a Seatbelt Profile Language (SBPL) policy and it execs the command
 * under that profile. The profile here is `(allow default)` with
 * `(deny file-write*)` on top and the writable paths re-allowed after it —
 * SBPL is LAST-MATCH-WINS, so the order of the lines below is the security:
 * the escape hatches ({@see JailPaths}) are DENIED after the root is allowed,
 * which is what makes a `.git/hooks` or `.mcp.json` planted inside the
 * writable root come out read-only anyway. Same reasoning, same lists, as
 * {@see Bubblewrap}: the jail is a WRITE jail (reads stay open), the
 * credential env scrub underneath it stays in force.
 *
 * WHAT IT CANNOT DO, SAID PLAINLY. `sandbox-exec` is deprecated by Apple yet
 * still ships, and it is what Claude Code and Codex sandbox on macOS today.
 * Unlike bwrap it creates NO private namespaces: there is no private `/tmp`
 * (the real `/private/tmp` stays shared and writable, which is why the git
 * and config hatches are re-denied rather than assumed unreachable), no PID
 * namespace (`--die-with-parent` has no equivalent — the timeout's group kill
 * is the backstop), and no `--chdir`: the child inherits the caller's
 * directory, so a $chdir other than the writable root is REFUSED, not
 * quietly dropped. A write denial answers `Operation not permitted`, not
 * `Read-only file system` — {@see describe()} says so because the model acts
 * on that sentence.
 *
 * THE NETWORK ARM. `no-network` denies `network*`, then re-allows LOCAL unix
 * sockets: denying them outright breaks ordinary commands whose stderr and
 * terminal traffic ride the window-server and `/dev/log`-style sockets, and
 * an IP-socket deny is still a real bound — macOS gives no per-uid socket
 * namespace for a jail to hide behind.
 *
 * @see https://developer.apple.com/library/archive/documentation/Security/Conceptual/SeatBeltGuide/
 */
final readonly class Seatbelt
{
    /** The OS's own copy of the runner — deprecation has not removed it. */
    public const SYSTEM_BINARY = '/usr/bin/sandbox-exec';

    /** Where macOS keeps the shared temp dirs (`/tmp` is a symlink to it). */
    public const PRIVATE_TMP = '/private/tmp';

    /** Where per-user temp dirs live (`$TMPDIR` values sit under it). */
    public const PRIVATE_FOLDERS = '/private/var/folders';

    private function __construct(
        public string $binary,
        public bool $network,
    ) {
    }

    /**
     * $binary '' resolves `sandbox-exec` on PATH, falling back to the
     * system location; a test or an embedder names its own.
     */
    public static function new(string $binary = '', bool $network = true): self
    {
        return new self($binary, $network);
    }

    /** The sandbox a `bashSandbox` value asks for on macOS, or null for `off`. */
    public static function fromSetting(mixed $value): ?self
    {
        return match (true) {
            $value === null, $value === false, $value === SandboxBackend::MODE_OFF => null,
            $value === true, $value === SandboxBackend::MODE_ON => self::new(),
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

    public function name(): string
    {
        return 'seatbelt';
    }

    /** The setting value this instance answers to. */
    public function mode(): string
    {
        return $this->network ? SandboxBackend::MODE_ON : SandboxBackend::MODE_NO_NETWORK;
    }

    /**
     * The binary to run, or '' when neither PATH nor the system location has
     * one. The system path is checked with `is_file` rather than trusted as a
     * constant: a profile handed to a missing binary must refuse, not spawn.
     */
    public function resolvedBinary(): string
    {
        if ($this->binary !== '') {
            return $this->binary;
        }
        $found = ProcessContainment::locateOnPath('sandbox-exec');
        if ($found !== '') {
            return $found;
        }

        return is_file(self::SYSTEM_BINARY) && is_executable(self::SYSTEM_BINARY)
            ? self::SYSTEM_BINARY
            : '';
    }

    /**
     * The sandbox-exec argv ending at its `-D` parameters — there is NO
     * trailing `--` because `sandbox-exec` takes the command immediately
     * after its options and would exec a literal `--`. Callers append the
     * command ({@see wrap()}) or their own argv tail (the PtyRun spawn
     * prefix) directly.
     *
     * $interactive is accepted and ignored: Seatbelt has no session detach —
     * the pty a pty-attached capture hands the child stays its controlling
     * terminal, which is exactly what the PtyRun jail needs.
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
        if ($chdir !== null && JailPaths::normalise($chdir) !== $root) {
            // Silently dropping a requested directory change would run the
            // command in a place its caller did not jail it for.
            throw new \InvalidArgumentException(
                'The Seatbelt sandbox cannot change directory: sandbox-exec has no chdir, '
                . 'so the jail root (' . $root . ') and the requested directory ('
                . JailPaths::normalise($chdir) . ') must be the same.',
            );
        }

        [$profile, $params] = $this->profile($root, $tmpDir, $readOnlyBinds);

        $argv = [$this->resolvedBinary(), '-p', $profile];
        foreach ($params as $name => $value) {
            array_push($argv, '-D', $name . '=' . $value);
        }

        return $argv;
    }

    /**
     * $shellCommand (an already-escaped `bash -c …` string) run inside the
     * profile, as one shell string — the shape both capture paths take.
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
     * The OS answer comes FIRST, before any binary lookup: on Linux this
     * refuses from the one fact that is true, deterministically, with no
     * dependence on what happens to be installed. Only on Darwin does the
     * probe exec — and it execs the EXACT profile shape every real command
     * rides, over `/usr/bin/true`, so "the probe passed" means "this profile
     * text is accepted by this OS", not merely "the binary exists".
     *
     * @param \Closure(string): array{exitCode: int, stderr: string} $run
     */
    public function probe(\Closure $run): ?string
    {
        static $verdicts = [];

        if (PHP_OS_FAMILY !== 'Darwin') {
            return 'seatbelt (sandbox-exec) is macOS-only and this host is ' . PHP_OS_FAMILY;
        }

        $binary = $this->resolvedBinary();
        if ($binary === '') {
            return 'sandbox-exec was not found on PATH or at ' . self::SYSTEM_BINARY;
        }

        $key = $binary . "\0" . ($this->network ? '1' : '0');
        if (!array_key_exists($key, $verdicts)) {
            $result = $run($this->wrap('/usr/bin/true', '/', false, ''));
            $verdicts[$key] = $result['exitCode'] === 0
                ? null
                : (trim($result['stderr']) !== ''
                    ? trim($result['stderr'])
                    : sprintf('%s exited %d', $binary, $result['exitCode']));
        }

        return $verdicts[$key];
    }

    /**
     * The sentence the tool's description gains while this sandbox is on —
     * truthful about the two ways Seatbelt differs from bwrap: no private
     * `/tmp`, and `Operation not permitted` as the denial error.
     */
    public function describe(string $writableRoot): string
    {
        return sprintf(
            'Commands run inside a macOS Seatbelt sandbox: filesystem writes are allowed only under %s, '
            . 'the shared /tmp and $TMPDIR, and the git hooks/config, .candy-code/ and .mcp.json paths '
            . 'stay unwritable even there%s. A write elsewhere fails with '
            . '"Operation not permitted"; do not try to work around it — tell the user.',
            JailPaths::normalise($writableRoot),
            $this->network ? '' : '; there is NO network access (local unix sockets stay open)',
        );
    }

    /**
     * The SBPL text and the `-D` parameters it reads, as [lines, params].
     *
     * Every path arrives as a parameter, never inlined: a repository root is
     * data, and quoting data into a policy language with its own `#"...#"`
     * literals is a parsing decision no path content should get to make.
     *
     * @param array<int, string> $readOnlyBinds
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function profile(string $root, ?string $tmpDir, array $readOnlyBinds): array
    {
        $params = ['ROOT' => $root];
        $lines = [
            '(version 1)',
            '(allow default)',
            // The write deny comes before every allow below — SBPL answers
            // with the LAST rule that matches, so order IS the design.
            '(deny file-write*)',
            '(allow file-write* (subpath (param "ROOT")))',
        ];

        // $TMPDIR must stay writable or every mktemp fails — but a temp dir
        // under the already-writable shared /tmp needs no rule of its own.
        $tmp = JailPaths::normalise($tmpDir ?? (string) getenv('TMPDIR'));
        if ($tmp !== '' && $tmp !== self::PRIVATE_TMP && $tmp !== '/tmp' && $tmp !== '/' && is_dir($tmp)) {
            $params['TMP'] = $tmp;
            $lines[] = '(allow file-write* (subpath (param "TMP")))';
        }
        $lines[] = '(allow file-write* (subpath "' . self::PRIVATE_TMP . '"))';
        $lines[] = '(allow file-write* (subpath "' . self::PRIVATE_FOLDERS . '"))';
        $lines[] = '(allow file-write* (literal "/dev/null"))';
        $lines[] = '(allow file-write* (regex #"^/dev/ttys"))';

        $hatch = 0;
        foreach (JailPaths::gitDirs($root) as $gitDir => $linked) {
            // A linked worktree's git directories sit outside every allow so
            // far, and without them every commit fails; the root's own `.git`
            // is already writable through ROOT and needs no allow.
            if ($linked) {
                $name = 'GIT_' . $hatch;
                $params[$name] = $gitDir;
                $lines[] = '(allow file-write* (subpath (param "' . $name . '")))';
                ++$hatch;
            }
            // The escape-hatch denies fire for EVERY git directory the jail
            // can write, whichever allow reached it — last match wins.
            foreach (JailPaths::PROTECTED_GIT_PATHS as $leaf) {
                $name = 'GITHATCH_' . $hatch;
                $params[$name] = $gitDir . '/' . $leaf;
                $lines[] = '(deny file-write* (subpath (param "' . $name . '")))';
                ++$hatch;
            }
        }
        foreach (JailPaths::PROTECTED_PATHS as $relative) {
            $name = 'PROTECT_' . $hatch;
            $params[$name] = $root . '/' . $relative;
            $lines[] = '(deny file-write* (subpath (param "' . $name . '")))';
            ++$hatch;
        }
        foreach (array_values($readOnlyBinds) as $i => $path) {
            $name = 'RO_' . $i;
            $params[$name] = JailPaths::normalise($path);
            $lines[] = '(deny file-write* (subpath (param "' . $name . '")))';
        }

        if (!$this->network) {
            array_push(
                $lines,
                '(deny network*)',
                // Local unix sockets carry terminal and system logging
                // traffic ordinary commands need; the deny that matters is
                // the IP one, and it stays denied.
                '(allow network-outbound (local unix-socket))',
                '(allow network-bind (local unix-socket))',
            );
        }

        return [implode("\n", $lines), $params];
    }
}
