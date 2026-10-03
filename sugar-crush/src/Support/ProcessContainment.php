<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support;

/**
 * THE ONE CONTAINMENT CHOKE POINT for every `proc_open()` in this package.
 *
 * Three questions, answered once, so every spawn site answers them the same:
 *
 *  1. WHAT does the child exec? — {@see spawnSpec()} wraps any command in a
 *     proven `setsid -w` so the child starts a NEW SESSION with no
 *     controlling terminal. `/dev/tty` then fails at OPEN (ENXIO), which is
 *     the only lever that reaches sudo/ssh/credential prompts/pagers: they
 *     refuse loudly onto the captured stderr instead of painting over the
 *     TUI frame or hanging on a prompt no human can see. E672 routed the
 *     remaining sites (hooks, workers, MCP/LSP servers, backends, providers,
 *     commands, status line) here; {@see \SugarCraft\Crush\Tools\Concerns\CapturesProcessOutput}
 *     (Phase 9 layer A) was the first consumer and still is the exemplar.
 *
 *  2. WITH WHAT ENVIRONMENT? — {@see env()} copies the inherited block,
 *     forces {@see NONINTERACTIVE_ENV} over it and removes
 *     {@see STRIPPED_ENV_NAMES}. GPG_TTY joined the strip list under E674:
 *     detach removes the CONTROLLING terminal but a stale GPG_TTY is a PATH
 *     opened by name, so a child of a TUI could still `open("/dev/pts/N")`
 *     onto the user's real terminal and scribble outside the frame — or
 *     block on a pinentry the human never sees. An unset GPG_TTY costs gpg
 *     a loopback/askpass fallback, which is the loud refusal containment
 *     asks for; an inherited one is a tty handle containment cannot revoke.
 *     The spawns whose output the MODEL reads (Bash, Grep, script hooks)
 *     take {@see scrubbedEnv()} instead, which also drops credentials
 *     (audit F-E1).
 *
 *  3. HOW DOES IT DIE? — {@see terminate()} kills the child's WHOLE process
 *     GROUP when the child leads one (the case for every setsid-wrapped
 *     spawn: the wrapper is the session leader and the real command runs in
 *     its group). Without this, detaching would be a leak dressed as a fix:
 *     `proc_terminate()` reaches only the wrapper's pid, and a wrapper that
 *     has already exec'd past signal forwarding — or is SIGKILLed itself,
 *     which cannot be forwarded — would orphan the grandchild with the
 *     terminal removed from its reach. E673: a container terminate must
 *     reach descendants; {@see groupId()} is the safe discriminator (it
 *     answers non-null ONLY when the child's pgid equals its own pid, so a
 *     group-kill can never fire against the PHP process's own group).
 *
 * NOTHING HERE SPAWNS ON THE TTY-PATH FALLBACK. When no usable `setsid(1)`
 * exists (macOS ships none) {@see spawnSpec()} returns the command
 * unchanged and {@see env()} still applies — fail-fast half, attached
 * spawn, exactly the fallback CapturesProcessOutput measured. {@see
 * terminate()} then simply finds no owned group and falls back to
 * `proc_terminate()`, which is the pre-containment behaviour — correct for
 * an unwrapped child, because without the wrapper the direct child IS the
 * command.
 */
final class ProcessContainment
{
    /**
     * Fail-fast environment forced on every child this package spawns. These
     * are not askpass (the askpass route was rejected as a credential-entry
     * surface driven by model output) — they are how a command refuses LOUDLY
     * on the captured stderr instead of hanging on an invisible prompt.
     *
     * GIT_TERMINAL_PROMPT=0: git's own switch for refusing terminal prompts.
     * GIT_ASKPASS=/bin/false: an executed /bin/false fails every credential
     * prompt as a refusal git explains on stderr; an EMPTY value would point
     * git at the default askpass path and, where one exists, feed it an empty
     * secret — a wrong-credential ATTEMPT where a refusal was asked for.
     * SSH_ASKPASS=echo (not /bin/false, because ssh 3-times a succeeding
     * askprogram before dying "Permission denied" and an EMPTY reply is the
     * legible outcome there): with no controlling terminal, OpenSSH >= 8.4
     * uses the askprogram at all, so pinning it pins the refusal path.
     * DEBIAN_FRONTEND=noninteractive: apt's config/overwrite prompts.
     * PAGER / GIT_PAGER: kill the pager class of hang.
     * SYSTEMD_PAGER=/bin/cat — not the documented EMPTY spelling, because
     * PHP's proc_open env array drops empty-string values, and a bare UNSET
     * is worse than the hang it prevents: systemctl then falls back to its
     * built-in `less`. /bin/cat streams and exits, and unlike empty it also
     * defeats a user-exported SYSTEMD_PAGER=less rather than reviving it.
     */
    public const NONINTERACTIVE_ENV = [
        'GIT_TERMINAL_PROMPT' => '0',
        'GIT_ASKPASS' => '/bin/false',
        'SSH_ASKPASS' => 'echo',
        'DEBIAN_FRONTEND' => 'noninteractive',
        'PAGER' => 'cat',
        'GIT_PAGER' => 'cat',
        'SYSTEMD_PAGER' => '/bin/cat',
    ];

    /**
     * Inherited names REMOVED from every child environment. SUDO_ASKPASS per
     * the plan's option (A) ("unset + sudo -n"): a user-wide askpass helper
     * must not be handed to a child that has no terminal to refuse it on.
     * GPG_TTY added under E674 — see the class docblock, question 2: a stale
     * GPG_TTY path can be opened BY NAME from outside a session, which is
     * precisely the channel detach cannot revoke.
     */
    public const STRIPPED_ENV_NAMES = ['SUDO_ASKPASS', 'GPG_TTY'];

    /**
     * Name shapes {@see scrubbedEnv()} treats as credentials (audit F-E1),
     * matched with `fnmatch()` on the UPPER-CASED name.
     *
     * WHY THE MODEL-VISIBLE PATHS DROP THEM: a Bash command's output and a
     * hook's stdout both reach the transcript, and from there the provider.
     * `env | grep API_KEY` was measured returning the session's own provider
     * key and an unrelated third-party key from the operator's shell — so an
     * injected instruction could read a secret and, with any egress tool, send
     * it out. Shapes rather than a list because the operator's shell holds
     * credentials for services this package has never heard of; `AWS_*`
     * whole because the AWS chain spreads one credential over several names
     * (`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_SESSION_TOKEN`).
     */
    public const SECRET_ENV_PATTERNS = ['*_API_KEY', '*_TOKEN', '*_SECRET', 'AWS_*'];

    /**
     * The credentials this package itself reads (docs/ENVIRONMENT.md,
     * "Provider credential variables"). Every one also matches
     * {@see SECRET_ENV_PATTERNS} today; they are named anyway for the
     * allowlist rule in {@see secretEnvAllowed()}: only an EXACT allowlist
     * entry releases one of these, so `*_TOKEN` written to pass `GITHUB_TOKEN`
     * through to `gh` cannot also hand the model `ANTHROPIC_AUTH_TOKEN`.
     */
    public const PROVIDER_SECRET_ENV_NAMES = [
        'ANTHROPIC_API_KEY',
        'ANTHROPIC_AUTH_TOKEN',
        'OPENAI_API_KEY',
        'SGLANG_API_KEY',
        'CUSTOM_PROVIDER_API_KEY',
    ];

    /**
     * The operator's `secretEnvAllowlist` setting, installed once per process
     * by {@see \SugarCraft\Crush\Cli\Bootstrap}. Process state, like the
     * setsid memo above, because the readers are a trait method and a hook
     * built from a config file — neither has a constructor the launch could
     * thread a value through without every intermediate factory learning it —
     * and forks inherit it, which is exactly the reach the setting needs.
     *
     * @var list<string>
     */
    private static array $secretEnvAllowlist = [];

    /**
     * Path of a `setsid(1)` proven to forward its child's exit status
     * through `-w`, or '' when this host offers no usable detach.
     *
     * The probe RUNS the claim it makes — `setsid -w -- /bin/sh -c 'exit
     * 7'` must come back 7 — because the two real failure modes are silent:
     * a binary predating `-w` (or a busybox lacking it) execs, the wrapper
     * exits 0 immediately, and every wrapped spawn on the box reports
     * success for commands that failed. Trading a hang for a lie is not
     * containment. Located by a stat walk, never by spawning `setsid`
     * itself when it may be missing (PHP warns on a missing proc_open
     * target and phpunit.xml sets failOnWarning).
     *
     * Memoized PER PROCESS now: the probe used to run once per trait-host
     * class; with one choke point the honest question ("does THIS host have
     * a usable setsid?") has one answer per process, so the memo moved to
     * class scope — which only a Support class may declare (the trait hosts
     * are `final readonly` and PHP refuses a readonly class composing a
     * trait that declares any property).
     */
    public static function detachedSpawnBinary(): string
    {
        static $resolved = null;

        if ($resolved !== null) {
            return $resolved;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return $resolved = '';
        }

        $path = self::locateOnPath('setsid');
        if ($path === '') {
            return $resolved = '';
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $probe = @\proc_open([$path, '-w', '--', '/bin/sh', '-c', 'exit 7'], $descriptors, $pipes);
        if (!\is_resource($probe)) {
            return $resolved = '';
        }
        \fclose($pipes[0]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        return $resolved = \proc_close($probe) === 7 ? $path : '';
    }

    /**
     * The command to hand `proc_open()`: a `setsid -w` wrapped spec when the
     * host offers a usable detach, the original command otherwise.
     *
     * Array commands wrap as `[$setsid, '-w', '--', ...$argv]` — direct
     * exec, no shell, PHP's own argument escaping intact. String commands
     * wrap as `[$setsid, '-w', '--', '/bin/sh', '-c', $command]`, which is
     * exactly the shell PHP's string form runs. `-w` keeps `proc_close()`
     * reporting the COMMAND's status, not the wrapper's, and forwards
     * received TERM/INT to the child (the kernel-proof floor for signal
     * delivery stays {@see terminate()}'s group kill).
     */
    public static function spawnSpec(string|array $command): string|array
    {
        $setsid = self::detachedSpawnBinary();
        if ($setsid === '') {
            return $command;
        }

        return \is_array($command)
            ? [$setsid, '-w', '--', ...\array_values($command)]
            : [$setsid, '-w', '--', '/bin/sh', '-c', $command];
    }

    /**
     * The inherited environment with {@see NONINTERACTIVE_ENV} forced over
     * it and {@see STRIPPED_ENV_NAMES} removed, then $overrides applied last
     * so a site's own required keys (API tokens, hook context) still win.
     *
     * Built from a getenv() COPY, not a replacement: proc_open's $env is the
     * child's WHOLE environment, so a hand-rolled array would strip PATH
     * (every `bash -c` lookup dies) and HOME (git refuses to find its
     * config). Rebuilt per call, never cached: putenv() in this process —
     * tests and supervisors do it — must reach the next child, not be frozen
     * out by a stale snapshot.
     *
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    public static function env(array $overrides = []): array
    {
        $env = \getenv();
        foreach (self::STRIPPED_ENV_NAMES as $name) {
            unset($env[$name]);
        }

        return \array_merge($env, self::NONINTERACTIVE_ENV, $overrides);
    }

    /**
     * {@see env()} with the credentials removed — the environment for the
     * spawn paths whose output the MODEL reads: Bash and Grep (through
     * {@see \SugarCraft\Crush\Tools\Concerns\CapturesProcessOutput}) and
     * script hooks ({@see \SugarCraft\Crush\Hooks\ScriptHook}). Audit F-E1.
     *
     * A name is removed when it matches {@see SECRET_ENV_PATTERNS} or is one
     * of {@see PROVIDER_SECRET_ENV_NAMES}, unless {@see secretEnvAllowed()}
     * lets it through. $overrides are applied AFTER the scrub, so a site's
     * own required keys (a hook's `CRUSH_*` payload, EnvironmentBlock's git
     * lock switch) are never caught by a pattern.
     *
     * env() ITSELF STAYS UNSCRUBBED, deliberately: LSP servers, the
     * claude-code client and the command backends are processes the OPERATOR
     * configured to authenticate as them, and their output is a protocol this
     * package parses, not text handed to the model verbatim. MCP stdio
     * servers USED to be on that list and no longer are — see
     * {@see mcpEnv()} (item 0.14-b, decision D3).
     *
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    public static function scrubbedEnv(array $overrides = []): array
    {
        $env = self::env();
        foreach (\array_keys($env) as $name) {
            if (self::isSecretEnvName((string) $name) && !self::secretEnvAllowed((string) $name)) {
                unset($env[$name]);
            }
        }

        return \array_merge($env, $overrides);
    }

    /**
     * The environment for an MCP stdio server (item 0.14-b, decision D3):
     * {@see scrubbedEnv()} with the server's own `.mcp.json` `env` block —
     * `${VAR}` already resolved — applied LAST.
     *
     * WHY MCP MOVED OFF THE UNSCRUBBED LIST. The command is chosen by the
     * REPOSITORY, not the operator: `.mcp.json` is checked-in content, trust
     * is granted per root, and a server the user approved for one purpose
     * inherited every credential in the shell — the GitHub token, the cloud
     * keys, the provider keys — whether it needed them or not, and a tool
     * result is text the model reads. Least privilege inverts the default:
     * a server gets the credentials its entry DECLARES
     * (`"env": {"GITHUB_TOKEN": "${GITHUB_TOKEN}"}`) or that the operator's
     * `secretEnvAllowlist` releases, and nothing else credential-shaped.
     * Everything not credential-shaped (PATH, HOME, LANG, proxies) is still
     * inherited, so an `npx` launcher keeps working.
     *
     * A declared name wins even when it is credential-shaped, because the
     * declaration IS the grant; {@see strippedSecretEnvNames()} names what was
     * withheld so the launch can say so instead of a server failing to
     * authenticate in silence.
     *
     * @param array<string, string> $declared the entry's resolved `env` block
     * @return array<string, string>
     */
    public static function mcpEnv(array $declared = []): array
    {
        return self::scrubbedEnv($declared);
    }

    /**
     * The inherited credential-shaped names {@see mcpEnv()} withholds for a
     * server declaring $declared — sorted, for a launch notice. Names only,
     * never values.
     *
     * @param array<string, string> $declared
     * @return list<string>
     */
    public static function strippedSecretEnvNames(array $declared = []): array
    {
        $stripped = [];
        foreach (\array_keys(self::env()) as $name) {
            $name = (string) $name;
            if (\array_key_exists($name, $declared)) {
                continue;
            }
            if (self::isSecretEnvName($name) && !self::secretEnvAllowed($name)) {
                $stripped[] = $name;
            }
        }
        \sort($stripped, \SORT_STRING);

        return $stripped;
    }

    /**
     * Whether $name is credential-shaped: one of
     * {@see PROVIDER_SECRET_ENV_NAMES}, or a match for a
     * {@see SECRET_ENV_PATTERNS} shape. Case-insensitive, because a lower-case
     * `github_token` is just as much a token and an over-eager scrub costs a
     * script one variable while an under-eager one costs the credential.
     */
    public static function isSecretEnvName(string $name): bool
    {
        $upper = \strtoupper($name);
        if (\in_array($upper, self::PROVIDER_SECRET_ENV_NAMES, true)) {
            return true;
        }
        foreach (self::SECRET_ENV_PATTERNS as $pattern) {
            if (\fnmatch($pattern, $upper)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the installed allowlist lets credential-shaped $name through.
     *
     * Entries are names or `fnmatch()` globs, compared upper-cased like the
     * patterns. A {@see PROVIDER_SECRET_ENV_NAMES} member needs an EXACT
     * entry: a glob is how an operator says "my GitHub tooling needs its
     * token", and reading it as also releasing the session's own provider
     * key would be the leak this scrub exists to close, reopened by the
     * setting that was meant to be the narrow exception.
     */
    public static function secretEnvAllowed(string $name): bool
    {
        $upper = \strtoupper($name);
        $provider = \in_array($upper, self::PROVIDER_SECRET_ENV_NAMES, true);
        foreach (self::$secretEnvAllowlist as $entry) {
            $entry = \strtoupper($entry);
            if ($entry === $upper || (!$provider && \fnmatch($entry, $upper))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Install the operator's `secretEnvAllowlist` for every later
     * {@see scrubbedEnv()} in this process (and its forks). Replaces, never
     * appends, so a re-read config cannot accumulate stale entries; blank and
     * non-string entries are dropped, and `[]` restores the full scrub.
     *
     * @param array<mixed> $entries
     */
    public static function useSecretEnvAllowlist(array $entries): void
    {
        $clean = [];
        foreach ($entries as $entry) {
            if (\is_string($entry) && \trim($entry) !== '') {
                $clean[] = \trim($entry);
            }
        }
        self::$secretEnvAllowlist = \array_values(\array_unique($clean));
    }

    /**
     * The allowlist {@see useSecretEnvAllowlist()} installed.
     *
     * @return list<string>
     */
    public static function secretEnvAllowlist(): array
    {
        return self::$secretEnvAllowlist;
    }

    /**
     * The `cd DIR || exit 1` guard line every `/bin/sh -c` script that must
     * run inside DIR starts with — ONE spelling for Bash and
     * {@see interactiveSpawnCommand()} (audit F-E3, then F-E5 for the copy
     * that kept the old form).
     *
     * Never `cd DIR && COMMAND`: `&&` binds tighter than `;`, so
     * `cd DIR && a; b` parses as `(cd DIR && a); b`, and when DIR is gone (a
     * removed worktree, a renamed checkout) `b` runs in the PHP process's cwd
     * — possibly the main checkout an isolated teammate was meant never to
     * touch. Exiting the shell guards every list, pipeline and compound the
     * command may contain. The NEWLINE (not `;`) ends the guard so it stays a
     * complete command whatever the command's first line is, and an empty
     * command is a no-op rather than a dangling-`&&` syntax error. The shell's
     * own "cd: DIR: No such file or directory" reaches the caller on stderr.
     */
    public static function cdGuard(string $dir): string
    {
        return 'cd ' . \escapeshellarg($dir) . " || exit 1\n";
    }

    /**
     * The process-group id a containment-aware kill should target, or null
     * when the child does not lead its own group.
     *
     * A setsid-wrapped child IS the session (and process-group) leader, and
     * everything the command forks runs in that group, so `kill(-pgid)`
     * reaches wrapper and grandchild alike. An UNWRAPPED child inherits the
     * PHP process's group — answering null here — and firing a group kill
     * would signal this very process. That is why the discriminator is
     * measured from the kernel (`posix_getpgid(child) === child`) rather
     * than tracked from the wrapper: a stale bookkeeping flag is how a
     * containment mechanism grows into a self-kill.
     *
     * @param resource|mixed $process the value a proc_open() call returned
     */
    public static function groupId(mixed $process): ?int
    {
        if (!\is_resource($process) || !\function_exists('posix_getpgid')) {
            return null;
        }

        $status = \proc_get_status($process);
        $pid = (int) ($status['pid'] ?? 0);
        if ($pid <= 0) {
            return null;
        }

        return \posix_getpgid($pid) === $pid ? $pid : null;
    }

    /**
     * Signal a (possibly wrapped) child in a way that reaches its
     * descendants: group kill when the child leads a group,
     * `proc_terminate()` otherwise.
     *
     * Signals are INTEGER LITERALS, never the SIGTERM/SIGKILL constants:
     * those come from ext-pcntl and this runs on teardown paths that must
     * not themselves fatal on a build without it (same rule as
     * {@see ProcessReaper}).
     */
    public static function terminate(mixed $process, int $signal = 15): bool
    {
        if (!\is_resource($process)) {
            return false;
        }

        $groupId = self::groupId($process);
        if ($groupId !== null && \function_exists('posix_kill') && @\posix_kill(-$groupId, $signal)) {
            return true;
        }

        return \proc_terminate($process, $signal);
    }

    /**
     * The memo behind {@see interactiveAvailable()} — null until the first
     * probe, then the verdict for the life of the process (or until the
     * testing reset clears it).
     */
    private static ?bool $interactiveAvailableMemo = null;

    /**
     * Whether this host can run a child attached to a REAL pty — the gate
     * the layer-C opt-in consults before promising anything.
     *
     * An `interactive: true` request on a host that cannot honour it (no
     * ext-ffi, /dev/ptmx sealed, Windows, or candy-pty simply absent) is
     * refused, not deferred: a mode that silently degrades to a pipe would
     * hand the model a tty-shaped lie. Every check is a STAT, never a
     * spawn — same discipline as {@see locateOnPath()} answering "might the
     * binary be missing", because a probe that opened a pty to prove pty
     * availability would leak descriptors on exactly the failure paths this
     * method exists to survive. The class_exists check is the vendor-closure
     * half: sugar-crush requires candy-pty, but a trimmed install or a
     * mislinked vendor must answer "unavailable", not fatal mid-tool.
     * Memoized per process like the setsid probe — the honest question is
     * "can this host", and that answer does not change within a process.
     * The memo lives at class scope (not a function-local `static`) so
     * {@see resetInteractiveAvailabilityForTesting()} can clear it: the
     * promise is that the ANSWER does not change, not that a test may never
     * re-ask — pinning both shapes of the gate (spawn and refusal) on one
     * host requires a re-derive the production memo would refuse.
     */
    public static function interactiveAvailable(): bool
    {
        if (self::$interactiveAvailableMemo !== null) {
            return self::$interactiveAvailableMemo;
        }

        if (PHP_OS_FAMILY === 'Windows' || !\extension_loaded('ffi')) {
            return self::$interactiveAvailableMemo = false;
        }

        if (!@\is_readable('/dev/ptmx') || !@\is_writable('/dev/ptmx')) {
            return self::$interactiveAvailableMemo = false;
        }

        return self::$interactiveAvailableMemo = \class_exists(\SugarCraft\Pty\Pty::class);
    }

    /**
     * Discard the memoized host verdict so the next
     * {@see interactiveAvailable()} re-derives it from the same STATs.
     * Testing seam only — production callers never reset a per-process
     * fact mid-run; the reset exists so one host can pin both the
     * interactive-spawn branch and the 126-refusal branch instead of
     * leaving whichever the box does not match as dead code.
     */
    public static function resetInteractiveAvailabilityForTesting(): void
    {
        self::$interactiveAvailableMemo = null;
    }

    /**
     * The argv for a pty spawn: $command through /bin/sh, entered in $cwd
     * when one is given.
     *
     * There is deliberately NO setsid wrapper here, and that is not a
     * containment gap: layer A's detach exists to REMOVE the controlling
     * terminal, while the entire point of an interactive run is that the
     * child HAS one — the pty this app allocated and captures from, never
     * the user's screen. Wrapping with setsid would opt in and then defeat
     * the opt-in. The fail-fast env ({@see env()}) still applies verbatim:
     * interactive means "gets a terminal", never "accepts secrets" — the
     * standing no-askpass design rule says the pty takes no password at
     * all, so GIT_TERMINAL_PROMPT/GIT_ASKPASS/SSH_ASKPASS forcing and the
     * SUDO_ASKPASS/GPG_TTY strips ride both paths identically.
     *
     * $cwd is entered through {@see cdGuard()} (audit F-E5): this used to
     * prefix `cd DIR && `, the form F-E3 retired from Bash, so a vanished
     * $cwd ran every command after the first `;` in the PHP process's own
     * directory.
     *
     * @return non-empty-list<string>
     */
    public static function interactiveSpawnCommand(string $command, ?string $cwd = null): array
    {
        $script = ($cwd === null || $cwd === '' ? '' : self::cdGuard($cwd)) . $command;

        return ['/bin/sh', '-c', $script];
    }

    /**
     * Signal a child by PID, reaching its group when it leads one and
     * itself otherwise — the proc_open-free twin of {@see terminate()}.
     *
     * The pty path holds a candy-pty handle, not a proc_open resource, so
     * there is nothing for terminate()'s is_resource gate to accept. The
     * group-first order still matters: the shell is not the program, and
     * killing only the shell would leave the actual child painting on a
     * terminal nobody reads anymore. Signals stay INTEGER LITERALS for the
     * same ext-pcntl-free teardown reason as terminate().
     */
    public static function terminatePid(int $pid, int $signal = 15): bool
    {
        if ($pid <= 0 || !\function_exists('posix_kill')) {
            return false;
        }

        if (\function_exists('posix_getpgid') && \posix_getpgid($pid) === $pid && @\posix_kill(-$pid, $signal)) {
            return true;
        }

        return @\posix_kill($pid, $signal);
    }

    /**
     * Upper bound on {@see killTree()}'s freeze walk: passes × poll (plus one
     * /proc snapshot per pass) is how long a teardown may sit on the caller's
     * thread while a tree that will not hold still is chased. A quiet tree
     * settles in two or three passes. {@see killTreeAsync()} shares the bound but
     * spends it one pass per loop tick, which is why the TUI's Escape-Escape
     * paths use that spelling (audit R3).
     */
    private const TREE_FREEZE_MAX_PASSES = 50;

    private const TREE_FREEZE_POLL_US = 5000;

    /**
     * KILL $pid AND EVERYTHING IT STARTED — the public group-kill entry point
     * for any site that tears down a forked child of this package (audit
     * B2/F-E2: Runtime's parallel deadline, AgentWorkerPool, the custom-command
     * `!` runner). A site that runs inside an event-loop callback —
     * EngineBackend's turn teardown and Chat's cancel sites — calls
     * {@see killTreeAsync()} instead, which runs this same sequence one loop
     * tick per pass (audit R3).
     *
     * WHY `posix_kill($pid, 9)` WAS NOT ENOUGH. Every command a tool runs is
     * `setsid -w`-wrapped ({@see spawnSpec()}), so it leads its OWN session and
     * process group. SIGKILLing the forked PHP child that ran it leaves
     * `/bin/sh -c …` and its children reparented to init, still running — a
     * cancelled `rm` loop, migration or `git push` finished anyway. And a
     * parallel Task sub-agent is a fork BELOW the killed child, exempt from the
     * group deadline, so it carried on (with its own Bash groups and parallel
     * forks) until its next progress check noticed the parent was gone.
     *
     * FREEZE, WALK, KILL — in that order, because the order is what makes the
     * walk sound:
     *  1. SIGSTOP the root, then repeatedly scan /proc for its descendants and
     *     SIGSTOP each new one, until two consecutive passes find nothing new
     *     and every collected process is stopped (or already dead). A stopped
     *     process cannot fork, so the set stops growing; and while the root is
     *     alive its children stay ITS children — once it dies they reparent to
     *     init and the tree can no longer be recovered. So the walk always
     *     runs BEFORE any kill. Bounded ({@see TREE_FREEZE_MAX_PASSES}): a
     *     process stuck in an uninterruptible wait cannot stop, and the kill
     *     below still goes out.
     *  2. For every distinct process group a collected member is in, signal
     *     the whole group — this reaches a setsid'd command's members that
     *     already left the tree (a backgrounded `&` job whose shell exited).
     *     NEVER the caller's own group: forks of this process inherit its
     *     pgrp (the TUI's), so those get per-pid kills only.
     *  3. Signal every collected pid and the root individually.
     *
     * $termGraceSeconds > 0 sends 15 first (with SIGCONT, so a stopped process
     * can act on it), waits up to the grace for the set to go, then sends 9 to
     * whatever is left. The default 0.0 is straight to 9, which is what both
     * live callers want: they were already SIGKILLing the root, and a stopped
     * tree has nothing left to do but die.
     *
     * Does NOT reap: the root stays the caller's to `waitpid()` (EngineBackend's
     * bounded reapChild(), Runtime's reapKilled()); descendants are reparented
     * to init by the kill and reaped there.
     *
     * LINUX-ONLY TREE HALF. Without a readable /proc ({@see ProcessTree}) or
     * without ext-posix's getpgrp this degrades to exactly the pre-fix
     * behaviour, `posix_kill($pid, 9)` on the root alone.
     *
     * Refuses $pid <= 0 and the caller's own pid (a kill(0)/kill(-1)/self-kill
     * through this method is never what a teardown meant).
     */
    public static function killTree(int $pid, float $termGraceSeconds = 0.0): void
    {
        if ($pid <= 0 || !\function_exists('posix_kill')) {
            return;
        }
        $self = \function_exists('posix_getpid') ? \posix_getpid() : \getmypid();
        if ($pid === $self) {
            return;
        }

        if (!\function_exists('posix_getpgrp') || !ProcessTree::available()) {
            if ($termGraceSeconds <= 0.0) {
                @\posix_kill($pid, 9);
            } else {
                ProcessReaper::escalate(
                    static function (int $signal) use ($pid): void {
                        @\posix_kill($pid, $signal);
                    },
                    static fn(): bool => !self::alive($pid),
                    $termGraceSeconds,
                );
            }

            return;
        }

        // SIGSTOP/SIGCONT numbers differ by architecture (19/18 on x86 and
        // arm, 17/19 on some others), unlike 9 and 15, so they come from
        // ext-pcntl when it is loaded and fall back to the Linux x86/arm
        // values only when it is not.
        $stop = \defined('SIGSTOP') ? \SIGSTOP : 19;
        $cont = \defined('SIGCONT') ? \SIGCONT : 18;
        $ownGroup = \posix_getpgrp();

        $members = self::freezeTree($pid, $self, $stop);
        $deliver = self::treeDeliverer($members, $ownGroup, $cont);

        if ($termGraceSeconds <= 0.0) {
            $deliver(9);

            return;
        }

        ProcessReaper::escalate(
            $deliver,
            static function () use ($members): bool {
                foreach ($members as $member) {
                    if (self::alive($member)) {
                        return false;
                    }
                }

                return true;
            },
            $termGraceSeconds,
        );
    }

    /**
     * {@see killTree()} WITHOUT HOLDING THE CALLER'S THREAD — the spelling for
     * a teardown that runs inside an event-loop callback (audit R3: the
     * Escape-Escape cancel of an engine turn, a Chat tool fan-out or a
     * forked hook chain). Resolves once signal 9 has gone out to the whole
     * tree; like killTree() it never reaps.
     *
     * WHERE THE ~110 ms WENT. MEASURED on this host (1,155 processes in
     * /proc): one {@see ProcessTree::snapshot()} costs ~33 ms, a quiet tree
     * needs three of them to settle (a pass that finds the children, then two
     * clean passes), plus the 5 ms polls between them — ~90-100 ms inside
     * killTree() and ~5 ms in the caller's reap. Every millisecond of it was
     * spent on the TUI's loop thread, so Escape froze the spinner, the
     * keyboard and the stream it had just cancelled. The bounded REAP was the
     * small half; the freeze walk was the big one.
     *
     * WHAT MOVES AND WHAT DOES NOT. The freeze, the walk and the kill are the
     * same code killTree() runs ({@see freezePass()}, {@see treeDeliverer()}),
     * in the same order and to the same bound: the root is SIGSTOPped HERE,
     * synchronously, so from this call on it cannot fork and its children stay
     * its children; then each walk pass runs in its own loop tick, the waits
     * between passes are a timer instead of usleep(), and the kill goes out in
     * the tick the walk settles in. The loop now blocks for at most one pass
     * (one snapshot) at a time instead of for the whole walk.
     *
     * WHY THIS IS SAFE TO SPREAD OVER TICKS. Nothing about the walk relied on
     * being uninterrupted: it already re-scans until two consecutive passes
     * find nothing new, precisely because a scan is not atomic, and a stopped
     * member cannot fork while the loop runs something else. The root is the
     * caller's unreaped child at every call site (EngineBackend's turn child,
     * Chat's tool and hook forks), so its pid cannot be recycled while the
     * walk runs. What a deferred kill DOES add is a way to never happen: a loop
     * that stops (Ctrl+C right after Escape) would leave a SIGSTOPped tree
     * behind forever. So every walk in flight is registered, and a shutdown
     * function owned by the registering pid finishes it synchronously — the
     * exact killTree() sequence — before this process exits. A fork of this
     * process inherits the registry but never acts on it (the owner check in
     * {@see finishPendingTreeKills()}), and every forked child here exits via
     * {@see ForkedChild::exitNow()}, which skips shutdown functions anyway.
     *
     * $termGraceSeconds > 0 is killTree()'s SIGTERM-first ladder on the same
     * terms (audit R3 residual: AgentWorkerPool's cancel, which asks a stage
     * to wind down rather than shooting it): once the walk settles the frozen
     * set gets 15 (and SIGCONT, so a stopped member can act on it), and a
     * timer — not ProcessReaper::escalate()'s usleep() — polls until every
     * member is gone or the grace is spent, when 9 goes to whatever is left.
     * The promise resolves after that 9 (or as soon as the set is gone), so it
     * still means "the tree has been dealt with". A shutdown that interrupts
     * the grace finishes with 9 at once: a process that is exiting has no
     * grace left to give.
     *
     * Same degradations as killTree(): without /proc or ext-posix's getpgrp
     * this is the direct `posix_kill($pid, 9)`, resolved at once — or, with a
     * grace, the same 15-then-9 ladder against the root alone, on the timer.
     *
     * @return \React\Promise\PromiseInterface<null>
     */
    public static function killTreeAsync(int $pid, ?\React\EventLoop\LoopInterface $loop = null, float $termGraceSeconds = 0.0): \React\Promise\PromiseInterface
    {
        if ($pid <= 0 || !\function_exists('posix_kill')) {
            return \React\Promise\resolve(null);
        }
        $self = \function_exists('posix_getpid') ? \posix_getpid() : \getmypid();
        if ($pid === $self) {
            return \React\Promise\resolve(null);
        }

        if (!\function_exists('posix_getpgrp') || !ProcessTree::available()) {
            if ($termGraceSeconds <= 0.0) {
                @\posix_kill($pid, 9);

                return \React\Promise\resolve(null);
            }

            return self::graceThenKill(
                static function (int $signal) use ($pid): void {
                    @\posix_kill($pid, $signal);
                },
                [$pid],
                $termGraceSeconds,
                $loop ?? \React\EventLoop\Loop::get(),
            );
        }

        $stop = \defined('SIGSTOP') ? \SIGSTOP : 19;
        $cont = \defined('SIGCONT') ? \SIGCONT : 18;
        $ownGroup = \posix_getpgrp();
        $loop ??= \React\EventLoop\Loop::get();
        $deferred = new \React\Promise\Deferred();

        // Step 1 starts NOW, not on the first tick: the root stops forking
        // the moment the caller asked for the kill.
        @\posix_kill($pid, $stop);
        $walk = ['members' => [$pid => true], 'stable' => 0, 'passes' => 0];
        $timer = null;
        $done = false;
        // Set once the walk has settled and SIGTERM went out: the deliverer
        // for the frozen set and the moment its grace runs out.
        $grace = null;

        $finish = static function () use (&$walk, &$timer, &$done, &$grace, $pid, $self, $stop, $cont, $ownGroup, $loop): bool {
            if ($done) {
                return false;
            }
            $done = true;
            if ($timer !== null) {
                $loop->cancelTimer($timer);
                $timer = null;
            }
            unset(self::$pendingTreeKills[$pid]);
            if ($grace !== null) {
                // In (or at the end of) the SIGTERM grace: the walk is done
                // and the set is fixed, so only the 9 is left.
                ($grace['deliver'])(9);

                return true;
            }
            // A walk cut short (the shutdown sweep) runs its remaining passes
            // here, to the same bound killTree() uses; one that already
            // settled or hit the bound goes straight to the kill.
            while ($walk['stable'] < 2 && $walk['passes'] < self::TREE_FREEZE_MAX_PASSES) {
                if (self::freezePass($walk, $pid, $self, $stop)) {
                    break;
                }
                \usleep(self::TREE_FREEZE_POLL_US);
            }
            self::treeDeliverer(\array_keys($walk['members']), $ownGroup, $cont)(9);

            return true;
        };

        self::armPendingTreeKillSweep();
        self::$pendingTreeKills[$pid] = $finish;

        $timer = $loop->addPeriodicTimer(
            self::TREE_FREEZE_POLL_US / 1_000_000,
            static function () use (&$walk, &$done, &$timer, &$grace, $pid, $self, $stop, $cont, $ownGroup, $termGraceSeconds, $finish, $deferred, $loop): void {
                if ($done) {
                    return;
                }
                // An inherited copy of this timer in a fork that runs a loop
                // must not walk (or kill) on the parent's behalf, nor keep
                // that fork's loop alive.
                if ($self !== self::currentPid()) {
                    if ($timer !== null) {
                        $loop->cancelTimer($timer);
                    }

                    return;
                }
                if ($grace !== null) {
                    $gone = true;
                    foreach ($grace['members'] as $member) {
                        if (self::alive($member)) {
                            $gone = false;

                            break;
                        }
                    }
                    if ($gone) {
                        // Every member acted on SIGTERM: nothing is left for
                        // a 9, so settle without sending one (escalate()'s
                        // early return).
                        $done = true;
                        $loop->cancelTimer($timer);
                        $timer = null;
                        unset(self::$pendingTreeKills[$pid]);
                        $deferred->resolve(null);

                        return;
                    }
                    if (\microtime(true) >= $grace['deadline'] && $finish()) {
                        $deferred->resolve(null);
                    }

                    return;
                }
                if (!self::freezePass($walk, $pid, $self, $stop) && $walk['passes'] < self::TREE_FREEZE_MAX_PASSES) {
                    return;
                }
                if ($termGraceSeconds > 0.0) {
                    $members = \array_keys($walk['members']);
                    $grace = [
                        'members' => $members,
                        'deliver' => self::treeDeliverer($members, $ownGroup, $cont),
                        'deadline' => \microtime(true) + $termGraceSeconds,
                    ];
                    ($grace['deliver'])(15);

                    return;
                }
                if ($finish()) {
                    $deferred->resolve(null);
                }
            },
        );

        return $deferred->promise();
    }

    /**
     * The degraded (no /proc, or no getpgrp) grace ladder of
     * {@see killTreeAsync()}: 15 now, poll on a timer until every pid is gone
     * or the grace is spent, then 9. Registered with the shutdown sweep like
     * a walk, so a loop that stops mid-grace still sends the 9.
     *
     * @param \Closure(int): void $deliver
     * @param list<int> $pids
     * @return \React\Promise\PromiseInterface<null>
     */
    private static function graceThenKill(\Closure $deliver, array $pids, float $graceSeconds, \React\EventLoop\LoopInterface $loop): \React\Promise\PromiseInterface
    {
        $deliver(15);
        $deferred = new \React\Promise\Deferred();
        $deadline = \microtime(true) + $graceSeconds;
        $self = self::currentPid();
        $key = $pids[0];
        $timer = null;
        $done = false;

        $finish = static function (bool $kill) use (&$timer, &$done, $deliver, $key, $loop): bool {
            if ($done) {
                return false;
            }
            $done = true;
            if ($timer !== null) {
                $loop->cancelTimer($timer);
                $timer = null;
            }
            unset(self::$pendingTreeKills[$key]);
            if ($kill) {
                $deliver(9);
            }

            return true;
        };

        self::armPendingTreeKillSweep();
        self::$pendingTreeKills[$key] = static fn(): bool => $finish(true);

        $timer = $loop->addPeriodicTimer(
            self::TREE_FREEZE_POLL_US / 1_000_000,
            static function () use (&$timer, &$done, $pids, $deadline, $self, $finish, $deferred, $loop): void {
                if ($done) {
                    return;
                }
                if ($self !== self::currentPid()) {
                    if ($timer !== null) {
                        $loop->cancelTimer($timer);
                    }

                    return;
                }
                $gone = true;
                foreach ($pids as $pid) {
                    if (self::alive($pid)) {
                        $gone = false;

                        break;
                    }
                }
                if (!$gone && \microtime(true) < $deadline) {
                    return;
                }
                if ($finish(!$gone)) {
                    $deferred->resolve(null);
                }
            },
        );

        return $deferred->promise();
    }

    /**
     * Collect already-signalled children of this process on a loop timer
     * instead of a usleep() loop: one WNOHANG pass now, then one every
     * $pollSeconds until every pid is collected or $budgetSeconds is spent.
     * Resolves with the pids it could NOT collect (empty when all were), so
     * a caller with a straggler list can keep them for a later sweep.
     *
     * The async twin of the bounded WNOHANG windows the kill sites used
     * after {@see killTree()} (EngineBackend::reapChild(),
     * Chat::reapKilledToolChildren()): same budget, same "-1 is terminal"
     * rule, but the loop turns between polls (audit R3). Never a blanket
     * `pcntl_waitpid(-1, ...)` — other owners in this process wait on their
     * own pids and branch on the answer.
     *
     * @param list<int> $pids
     * @return \React\Promise\PromiseInterface<list<int>>
     */
    public static function reapAsync(array $pids, float $budgetSeconds, float $pollSeconds = 0.005, ?\React\EventLoop\LoopInterface $loop = null): \React\Promise\PromiseInterface
    {
        if (!\function_exists('pcntl_waitpid')) {
            return \React\Promise\resolve(\array_values($pids));
        }

        $poll = static function (array $pending): array {
            $status = 0;
            foreach ($pending as $slot => $pid) {
                if (\pcntl_waitpid($pid, $status, \WNOHANG) !== 0) {
                    unset($pending[$slot]);
                }
            }

            return $pending;
        };

        $pending = $poll(\array_values($pids));
        if ($pending === [] || $budgetSeconds <= 0.0) {
            return \React\Promise\resolve(\array_values($pending));
        }

        $loop ??= \React\EventLoop\Loop::get();
        $deferred = new \React\Promise\Deferred();
        $deadline = \microtime(true) + $budgetSeconds;
        $timer = null;
        $timer = $loop->addPeriodicTimer(
            \max(0.001, $pollSeconds),
            static function () use (&$pending, &$timer, $poll, $deadline, $loop, $deferred): void {
                $pending = $poll($pending);
                if ($pending !== [] && \microtime(true) < $deadline) {
                    return;
                }
                $loop->cancelTimer($timer);
                $deferred->resolve(\array_values($pending));
            },
        );

        return $deferred->promise();
    }

    /**
     * Walks {@see killTreeAsync()} has started and not yet finished, keyed by
     * root pid; each value finishes its walk synchronously and kills.
     *
     * @var array<int, \Closure(): bool>
     */
    private static array $pendingTreeKills = [];

    /** The pid that registered {@see $pendingTreeKills}' shutdown sweep. */
    private static ?int $pendingTreeKillOwner = null;

    private static function currentPid(): int
    {
        return \function_exists('posix_getpid') ? \posix_getpid() : (int) \getmypid();
    }

    /**
     * Register the shutdown sweep once per process that starts an async kill.
     * A fork inherits the flag but not the registration's meaning: the owner
     * is re-stamped when a forked process starts a walk of its own.
     */
    private static function armPendingTreeKillSweep(): void
    {
        $me = self::currentPid();
        if (self::$pendingTreeKillOwner === $me) {
            return;
        }
        // A fork that starts its own walk drops the parent's: those trees are
        // the parent's to finish, and it still will.
        self::$pendingTreeKills = [];
        self::$pendingTreeKillOwner = $me;
        \register_shutdown_function(static function (): void {
            self::finishPendingTreeKills();
        });
    }

    /**
     * Finish every {@see killTreeAsync()} walk still in flight, synchronously
     * — the shutdown backstop, so a loop that stopped mid-walk cannot leave a
     * SIGSTOPped tree behind. Only in the process that started them.
     */
    private static function finishPendingTreeKills(): void
    {
        if (self::$pendingTreeKillOwner !== self::currentPid()) {
            return;
        }
        foreach (self::$pendingTreeKills as $finish) {
            $finish();
        }
        self::$pendingTreeKills = [];
    }

    /**
     * Steps 2 and 3 of {@see killTree()}: the signal sender for a frozen
     * member set — every member's process group (never the caller's own, and
     * never pgid 0/1), then every member pid, with SIGCONT after any signal
     * other than 9 so a stopped process can act on it.
     *
     * @param list<int> $members
     * @return \Closure(int): void
     */
    private static function treeDeliverer(array $members, int $ownGroup, int $cont): \Closure
    {
        $groups = [];
        foreach ($members as $member) {
            $stat = ProcessTree::stat($member);
            if ($stat !== null && $stat['pgid'] > 1 && $stat['pgid'] !== $ownGroup) {
                $groups[$stat['pgid']] = true;
            }
        }
        $groups = \array_keys($groups);

        return static function (int $signal) use ($groups, $members, $cont): void {
            foreach ($groups as $group) {
                @\posix_kill(-$group, $signal);
            }
            foreach ($members as $member) {
                @\posix_kill($member, $signal);
            }
            if ($signal !== 9) {
                foreach ($groups as $group) {
                    @\posix_kill(-$group, $cont);
                }
                foreach ($members as $member) {
                    @\posix_kill($member, $cont);
                }
            }
        };
    }

    /**
     * Step 1 of {@see killTree()}: stop $root and every descendant until the
     * set holds still, returning root + descendants (never $self).
     *
     * @return list<int>
     */
    private static function freezeTree(int $root, int $self, int $stop): array
    {
        @\posix_kill($root, $stop);
        $walk = ['members' => [$root => true], 'stable' => 0, 'passes' => 0];

        while ($walk['passes'] < self::TREE_FREEZE_MAX_PASSES) {
            if (self::freezePass($walk, $root, $self, $stop)) {
                break;
            }

            \usleep(self::TREE_FREEZE_POLL_US);
        }

        return \array_keys($walk['members']);
    }

    /**
     * ONE pass of the freeze walk, shared by {@see freezeTree()} (back to
     * back, usleep between) and {@see killTreeAsync()} (one per loop tick):
     * scan, SIGSTOP every descendant not yet seen, and report whether the set
     * has now held still for two consecutive passes. Counts itself in
     * $walk['passes'] so both callers share the one bound.
     *
     * @param array{members: array<int, true>, stable: int, passes: int} $walk
     */
    private static function freezePass(array &$walk, int $root, int $self, int $stop): bool
    {
        $walk['passes']++;
        $snapshot = ProcessTree::snapshot() ?? [];
        $grew = false;
        foreach (ProcessTree::descendants($root, $snapshot) as $descendant) {
            if ($descendant === $self || isset($walk['members'][$descendant])) {
                continue;
            }
            @\posix_kill($descendant, $stop);
            $walk['members'][$descendant] = true;
            $grew = true;
        }

        $allHeld = true;
        foreach (\array_keys($walk['members']) as $member) {
            $state = $snapshot[$member]['state'] ?? 'X';
            // T stopped, t tracing-stop, Z zombie, X dead/absent: none of
            // these can fork again.
            if (!\in_array($state, ['T', 't', 'Z', 'X'], true)) {
                $allHeld = false;

                break;
            }
        }

        // TWO clean passes, not one: a scan is not atomic, so a member
        // that forked after the directory listing and stopped before its
        // own stat was read looks "held" with a child the listing missed.
        // A second pass that starts after everything was seen stopped
        // cannot miss anything — nothing in the set can fork any more.
        $walk['stable'] = (!$grew && $allHeld) ? $walk['stable'] + 1 : 0;

        return $walk['stable'] >= 2;
    }

    /**
     * Whether $pid still names a running (non-zombie) process.
     */
    private static function alive(int $pid): bool
    {
        $stat = ProcessTree::stat($pid);
        if ($stat !== null) {
            return $stat['state'] !== 'Z' && $stat['state'] !== 'X';
        }

        return !ProcessTree::available() && @\posix_kill($pid, 0);
    }

    /**
     * Mark $stream's descriptor FD_CLOEXEC so no command spawned afterwards
     * inherits it; true when the flag is now set.
     *
     * WHY THIS IS NEEDED: proc_open() only marks its OWN pipe ends
     * close-on-exec. A stream_socket_pair() end, a plain fopen() handle — any
     * other descriptor this process holds — walks into every child it
     * spawns, and a child holding a socket's write end keeps the reader from
     * ever seeing EOF (audit B3: a backgrounded `sleep` held a turn's frame
     * socket open until it exited). PHP exposes neither the fd number nor
     * fcntl() for a stream, so the fd is found by matching the stream's
     * dev+ino against every `/proc/self/fd/N`, and the flag is set through
     * FFI's libc `fcntl(F_SETFD, FD_CLOEXEC)`.
     *
     * Best effort by contract and SILENT on every miss: no /proc, no ext-ffi,
     * FFI disabled by `ffi.enable`, no matching fd — each answers false and
     * changes nothing, because this runs on fork and spawn paths where a
     * warning (phpunit's failOnWarning) or a fatal would be worse than the
     * leak. It replaces the descriptor's flags with FD_CLOEXEC alone, which
     * is safe because FD_CLOEXEC is the only fd flag Linux defines.
     *
     * Only an exec boundary honours the flag: a pcntl_fork() child still
     * inherits the descriptor, so a caller that must notice a forked holder
     * dying needs its own liveness check (EngineBackend polls the pid).
     *
     * @param resource|mixed $stream
     */
    public static function closeOnExec(mixed $stream): bool
    {
        if (!\is_resource($stream) || !\extension_loaded('ffi') || !\is_dir('/proc/self/fd')) {
            return false;
        }

        $fd = self::descriptorNumber($stream);
        if ($fd === null) {
            return false;
        }

        try {
            $libc = \FFI::cdef('int fcntl(int fd, int cmd, ...);');

            // 2 = F_SETFD, 1 = FD_CLOEXEC on every Linux ABI.
            return $libc->fcntl($fd, 2, 1) === 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The fd number behind $stream, found by dev+ino match in
     * `/proc/self/fd`, or null when no single entry matches.
     *
     * @param resource $stream
     */
    private static function descriptorNumber($stream): ?int
    {
        $own = @\fstat($stream);
        if (!\is_array($own) || ($own['ino'] ?? 0) === 0) {
            return null;
        }

        $match = null;
        foreach (@\scandir('/proc/self/fd') ?: [] as $entry) {
            if (!\ctype_digit($entry) || (int) $entry <= 2) {
                continue;
            }
            $candidate = @\stat('/proc/self/fd/' . $entry);
            if (\is_array($candidate) && $candidate['ino'] === $own['ino'] && $candidate['dev'] === $own['dev']) {
                if ($match !== null) {
                    // Two descriptors for one file (a dup): which one this
                    // stream owns is unknowable from here, so refuse.
                    return null;
                }
                $match = (int) $entry;
            }
        }

        return $match;
    }

    /**
     * First executable named $binary on the process PATH, or '' when absent.
     *
     * An empty PATH element is skipped, not treated as '.': answering a
     * containment question from whatever the working directory happens to be
     * is the nondeterminism DetectsCapabilities exists to avoid, same rule.
     */
    public static function locateOnPath(string $binary): string
    {
        foreach (\explode(':', (string) \getenv('PATH')) as $dir) {
            if ($dir === '') {
                continue;
            }
            $candidate = \rtrim($dir, '/') . '/' . $binary;
            if (\is_file($candidate) && \is_executable($candidate)) {
                return $candidate;
            }
        }

        return '';
    }
}
