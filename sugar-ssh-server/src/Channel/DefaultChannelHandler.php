<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Channel;

use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\SshServer\Channel\Msg\BreakMsg;
use SugarCraft\SshServer\Channel\Msg\EnvMsg;
use SugarCraft\SshServer\Channel\Msg\ExecMsg;
use SugarCraft\SshServer\Channel\Msg\PtyReqMsg;
use SugarCraft\SshServer\Channel\Msg\ShellMsg;
use SugarCraft\SshServer\Channel\Msg\SignalMsg;
use SugarCraft\SshServer\Channel\Msg\WindowChangeMsg;
use SugarCraft\SshServer\Lang;
use SugarCraft\SshServer\Session;
use SugarCraft\SshServer\StreamHelper;
use SugarCraft\SshServer\Transport\ChildSpawner;

/**
 * Default channel handler wiring the common case: PTY + shell.
 *
 * Tracks per-session channel state (PTY allocation, env vars, dims)
 * and drives a {@see ChildSpawner} when the shell or exec channel
 * request arrives. Signal and window-change messages are forwarded
 * into the active PTY master.
 *
 * Concrete transports inject themselves as a {@see ChildSpawner} at
 * construction time so the handler can call {@see runChild()} when
 * the shell or exec request fires.
 *
 * **The exec path is client-chosen, so it is default-closed.** An exec
 * request names the program the server should run — the peer picks it,
 * not the operator. `handleExec()` therefore spawns nothing unless the
 * request passes {@see execAllowed()}: the program's exact path must be
 * in `$allowedExec`, and the optional `$execGate` must not veto. An
 * empty `$allowedExec` (the default) refuses every exec request, which
 * is the safe shape for a deployment that has not thought about it:
 * nothing here can be reached by an SSH client that was never given
 * permission. Refusals are announced on `$stderr` — and under
 * ForceCommand that stream is the SSH extended-data channel, i.e. the
 * client that just asked (see the sink note on
 * {@see \SugarCraft\SshServer\Middleware\Logger}), so the notice is
 * deliberately one line naming the program and nothing about the policy
 * behind it.
 *
 * **Shell requests are not gated**, and that asymmetry is intentional:
 * a shell request names no program. The interpreter comes from the
 * configured shell, then the server-side `SHELL`, then
 * {@see FALLBACK_SHELL} — a client-supplied `SHELL` is stripped (it is
 * in {@see DANGEROUS_VARS}) so a peer cannot choose what it gets exec'd
 * into ({@see resolveShell()}).
 *
 * **Nothing feeds these messages from the wire today (audit K1).** The
 * only {@see ChannelMsg} this repository ever constructs in production
 * is {@see WindowChangeMsg}, from a SIGWINCH inside
 * {@see \SugarCraft\SshServer\Transport\InProcessTransport}; the
 * exec/shell/pty-req/env/signal/break requests are minted by tests and
 * by embedders that dispatch them themselves. The handler is a live
 * object with a dormant input, and the exec contract a client expects
 * from `ssh host some-command` is served elsewhere — by the
 * `SSH_ORIGINAL_COMMAND` a forced script receives, not by
 * `handleExec()`. Read the Status section and the exec-policy note in
 * `docs/admin/sugar-ssh-server.md` before planning around channel
 * dispatch.
 */
final class DefaultChannelHandler implements ChannelHandler
{
    private ?bool $ptyAllocated = null;

    private int $cols = 80;

    private int $rows = 24;

    /** @var array<string,string> */
    private array $envVars = [];

    /** @var list<string> */
    private array $pendingCommand = [];

    private bool $shellRequested = false;

    private bool $execRequested = false;

    /** @var resource */
    private $stderr;

    /**
     * Policy gate consulted after `$allowedExec` admits the program;
     * native-typed `?callable` at the constructor parameter (audit 077 —
     * a non-callable is refused by the engine at construction). Declared
     * `mixed` because PHP forbids `callable` as a property type: the
     * parameter keeps the static safety, the slot keeps the legality.
     */
    private readonly mixed $execGate;

    /**
     * Names the child never learns from the peer, even when an operator
     * lists them in `$acceptEnv` (audit MINOR 5).
     *
     * This is a deny-list, not a threat model, and it is deliberately
     * boring: every entry is a variable whose VALUE changes what code runs,
     * which library gets loaded, or where a program looks for one - so a
     * client that could set it would be configuring the server's child
     * rather than describing its own terminal.
     *
     * - `LD_PRELOAD`, `LD_LIBRARY_PATH`, `LD_AUDIT` (glibc),
     *   `DYLD_INSERT_LIBRARIES`, `DYLD_LIBRARY_PATH` (macOS dyld): loader
     *   hooks and search paths. `LD_AUDIT` is the one people forget, and it
     *   is the strongest of the three: an audited library receives
     *   callbacks before the program's own entry point.
     * - `GCONV_PATH`: where glibc finds locale conversion modules, i.e. a
     *   code path to arbitrary `.so` files.
     * - `BASH_ENV`, `ENV`: files bash and sh source before running
     *   anything. `PROMPT_COMMAND`: shell code executed on the interactive
     *   side, which is exactly what a shell request hands the peer.
     * - `IFS`: word-splitting semantics for every later expansion.
     * - `PATH`, `SHELL`: the interpreter and the search path themselves.
     * - `HOSTALIASES`: a name-lookup table, so a hostname need not resolve
     *   the way the operator configured.
     * - `POSIXLY_CORRECT`: silently flips behaviour across the coreutils a
     *   session then runs.
     *
     * @var list<string>
     */
    private const DANGEROUS_VARS = [
        'LD_PRELOAD',
        'LD_LIBRARY_PATH',
        'LD_AUDIT',
        'DYLD_INSERT_LIBRARIES',
        'DYLD_LIBRARY_PATH',
        'GCONV_PATH',
        'BASH_ENV',
        'ENV',
        'PROMPT_COMMAND',
        'IFS',
        'PATH',
        'SHELL',
        'HOSTALIASES',
        'POSIXLY_CORRECT',
    ];

    /**
     * Store-time budget for peer-supplied environment entries (audit 048).
     *
     * Every peer-facing surface in this library is bounded — the auth
     * gate echoes at most {@see \SugarCraft\SshServer\Middleware\Auth::MAX_ECHOED_BYTES}
     * bytes, keyboard-interactive reads at most 4 KiB per response — and
     * this map is named by the class docblock as THE seam a wire decoder
     * feeds, so the day dispatch lands it must not be the one surface an
     * unauthenticated peer can spray. The numbers are generous against
     * real terminals and tight against payloads: 64 entries, 256-byte
     * names, 4 KiB values. Overshooting drops the ENTRY (counted), never
     * earlier ones, and the first drop announces itself once on the
     * refusal stream — announce, don't swallow.
     */
    public const ENV_MAX_ENTRIES = 64;

    public const ENV_MAX_NAME_BYTES = 256;

    public const ENV_MAX_VALUE_BYTES = 4096;

    /** Peer-supplied env entries refused by the budget (announce-don't-swallow). */
    private int $envDrops = 0;

    private bool $envNoticeSent = false;

    /**
     * Last-resort interpreter for shell requests when neither an explicit
     * configured shell nor a `SHELL` environment variable is available:
     * `/bin/sh` is the POSIX-guaranteed path — unlike
     * the historical hard-coded `/bin/bash` it exists on every host this
     * library claims to run on; systems whose login shell is bash reach
     * it earlier in the resolution order (sshd exports `SHELL`).
     */
    public const FALLBACK_SHELL = '/bin/sh';

    /**
     * @param ChildSpawner|null $spawner   injected so `handleShell` can call `runChild()`
     * @param Session|null      $session   used to seed initial cols/rows when they're non-zero
     * @param list<string>      $acceptEnv allowlist of client env-var names that may be
     *                                     passed to the child; empty (the default) means
     *                                     accept none beyond the floor vars
     * @param string|null       $shell     explicit login shell for shell requests;
     *                                     blank/null falls through to `getenv('SHELL')`
     *                                     and then to {@see FALLBACK_SHELL} (see `spawnShell()`)
     * @param list<string>      $allowedExec exact program paths an exec request may run.
     *                                     Empty (the default) refuses EVERY exec request —
     *                                     the exec program is chosen by the peer, so it is
     *                                     not granted by silence.
     * @param null|callable     $execGate  `fn(Session, list<string> $argv): bool`, natively
     *                                     typed — anything not callable is refused by the
     *                                     engine at construction (audit 077). Consulted
     *                                     only after `$allowedExec` has admitted the program;
     *                                     use it for argument- and user-level policy. Null
     *                                     adds no condition beyond the path list.
     * @param resource|null     $stderr    where exec refusals are announced; null opens
     *                                     `php://stderr`, which under ForceCommand is the
     *                                     connecting client.
     */
    public function __construct(
        private readonly ?ChildSpawner $spawner = null,
        ?Session $session = null,
        private readonly array $acceptEnv = [],
        private readonly ?string $shell = null,
        private readonly array $allowedExec = [],
        ?callable $execGate = null,
        $stderr = null,
    ) {
        $this->execGate = $execGate;
        $this->stderr = StreamHelper::openOrValidate($stderr);
        if ($session !== null && $session->cols > 0) {
            $this->cols = $session->cols;
        }
        if ($session !== null && $session->rows > 0) {
            $this->rows = $session->rows;
        }
    }

    public function handlePtyReq(PtyReqMsg $msg, Session $session): void
    {
        $this->ptyAllocated = $msg->wantPty;
        if ($msg->wantPty) {
            $this->cols = $msg->cols > 0 ? $msg->cols : ($session->cols > 0 ? $session->cols : 80);
            $this->rows = $msg->rows > 0 ? $msg->rows : ($session->rows > 0 ? $session->rows : 24);
        }
    }

    /**
     * Apply a peer's window change. A zero in either dimension means the
     * sender declared nothing for it, and the current size is KEPT — the
     * pty-req leg already resolved the session/default fallback once, and
     * a mid-session non-declaration must not demote a known terminal to
     * the 80×24 floor (audit 081).
     */
    public function handleWindowChange(WindowChangeMsg $msg, Session $session): void
    {
        if ($msg->cols > 0) {
            $this->cols = $msg->cols;
        }
        if ($msg->rows > 0) {
            $this->rows = $msg->rows;
        }
    }

    public function handleShell(ShellMsg $msg, Session $session): void
    {
        $this->shellRequested = $msg->wantShell;
        if ($msg->wantShell && $this->spawner !== null) {
            $this->spawnShell($session);
        }
    }

    public function handleExec(ExecMsg $msg, Session $session): void
    {
        $this->execRequested = true;
        $argv = self::decodeCommandString($msg->command);
        $this->pendingCommand = $argv;
        if ($argv === []) {
            // Nothing to run — an empty request is not a refusal worth a
            // notice. The README exit contract resolves "the walk finished
            // with nothing to spawn" to `0`, and a request that decodes to
            // zero tokens is indistinguishable from an empty command
            // string, so it exits 0 like any other nothing-to-do session;
            // it is not markRefused'd.
            return;
        }
        if (!$this->execAllowed($session, $argv)) {
            $this->refuseExec($session, $argv);
            return;
        }
        if ($this->spawner !== null) {
            $this->spawnWithExec($session, $argv);
        }
    }

    /**
     * May this decoded request be spawned?
     *
     * Two conditions, both required: the program is one of the
     * operator's `$allowedExec` paths (exact string match on argv[0] —
     * no prefix rules, so `/bin/ls` never authorises `/bin/lsblk`), and
     * the optional `$execGate` returns exactly `true`. Anything else —
     * an empty allowlist, a gate that throws, a gate that returns a
     * truthy non-bool — refuses. The default-deny direction is the whole
     * point: a mistake in policy configuration must not become an open
     * remote-exec primitive.
     *
     * @param list<string> $argv
     */
    public function execAllowed(Session $session, array $argv): bool
    {
        $program = $argv[0] ?? '';
        if ($program === '' || !\in_array($program, $this->allowedExec, true)) {
            return false;
        }
        if ($this->execGate === null) {
            return true;
        }

        try {
            return ($this->execGate)($session, $argv) === true;
        } catch (\Throwable) {
            // A policy that cannot answer has not given permission.
            return false;
        }
    }

    /**
     * Announce a refused exec request, briefly and path-free.
     *
     * The stream defaults to the one the connecting client reads (see
     * the class docblock), so the line names only what the peer already
     * knows — the program it asked for — never the allowlist it failed
     * to be on, and never a server path. Control characters in the
     * program name are the peer's, so they are neutralised before they
     * reach any stream.
     *
     * @param list<string> $argv
     */
    /**
     * Deny an exec request: latch the refusal, then announce it.
     *
     * The mark is the exit contract — the other deny sites (the auth
     * gates, `RateLimit`, the transport-side
     * {@see \SugarCraft\SshServer\Transport\DispatchesMiddlewareStack})
     * each run {@see Session::markRefused()} once at the deny site, and
     * {@see \SugarCraft\SshServer\Server::resolveStatus()} maps that latch
     * to `EXIT_DENIED`. This method joins them: a refused exec is a
     * refusal, not a successful nothing. The mark comes BEFORE the stderr
     * guard on purpose — the refusal is a fact about the session, while
     * the notice is best-effort and drops silently when the stream is
     * closed.
     *
     * @param non-empty-list<string> $argv
     */
    private function refuseExec(Session $session, array $argv): void
    {
        $session->markRefused();

        if (!\is_resource($this->stderr)) {
            return;
        }

        \fwrite(
            $this->stderr,
            Lang::t('channel.exec_refused', ['program' => self::printable($argv[0])]) . "\n",
        );
    }

    /**
     * Strip everything non-printable without breaking valid UTF-8.
     *
     * A byte-wise `[\x7f-\x9f]` class would eat continuation bytes of
     * real multibyte characters, so this walks codepoints (//u) and
     * falls back to a fixed token when the input is not UTF-8 at all.
     * Newlines are folded to spaces BEFORE the strip (audit 040): the
     * exemption the class used to carry for `\n` let a peer-named
     * program forge a second line into whatever notice embeds this
     * value. The cap counts characters, not bytes, cut on the same
     * codepoint prefix idiom as {@see \SugarCraft\SshServer\Middleware\Auth::sanitize()}
     * — the old byte `substr` could halve a multibyte character,
     * breaking exactly what this method promises.
     */
    private static function printable(string $value): string
    {
        $clean = \preg_replace('/[^\P{C}]+/u', '', \str_replace(["\r\n", "\n", "\r"], ' ', $value));
        if ($clean === false || $clean === '') {
            return '<unreadable>';
        }

        if (\preg_match('/^.{0,128}/us', $clean, $cut) === 1 && \strlen($cut[0]) < \strlen($clean)) {
            return $cut[0] . "\u{2026}";
        }

        return $clean;
    }

    public function handleSignal(SignalMsg $msg, Session $session): void
    {
        // Map RFC-4254 signal names to PHP SIG* constants. The
        // constants come from ext-pcntl (or ext-posix), so each is
        // resolved through \defined() FIRST — reading an undefined
        // constant throws Error before `??` can fall back, which
        // would fatal exactly on the pcntl-less hosts this map must
        // degrade on. The literal numbers are the POSIX values used
        // as the transport-side fallback where the extension is absent.
        // Those numbers are the Linux and BSD values — `WINCH` is 28 on both
        // — which is exactly why each name is probed through \defined() first:
        // a platform that numbers a signal differently resolves to its own
        // constant, and a literal is reached only where no extension defines
        // the name at all (audit NIT 10).
        $map = [
            'INT'   => \defined('SIGINT') ? \SIGINT : 2,
            'TERM'  => \defined('SIGTERM') ? \SIGTERM : 15,
            'HUP'   => \defined('SIGHUP') ? \SIGHUP : 1,
            'QUIT'  => \defined('SIGQUIT') ? \SIGQUIT : 3,
            'KILL'  => \defined('SIGKILL') ? \SIGKILL : 9,
            'USR1'  => \defined('SIGUSR1') ? \SIGUSR1 : 10,
            'USR2'  => \defined('SIGUSR2') ? \SIGUSR2 : 12,
            'WINCH' => \defined('SIGWINCH') ? \SIGWINCH : 28,
        ];

        $sig = $map[$msg->signalName] ?? null;
        if ($sig !== null) {
            $this->spawner?->signalChild($sig);
        }
    }

    /**
     * Store a peer-supplied environment variable, within the budget.
     *
     * The accessor contract (audit 088, pinned beside the child-env veto
     * in DefaultChannelHandlerTest): entries are stored RAW here and
     * deny/allow filtering happens only in {@see buildEnv()} — but RAW
     * does not mean UNBOUNDED (audit 048): oversize names, oversize
     * values and a 65th distinct entry are dropped at the door, counted,
     * and the first drop says so once on the refusal stream.
     */
    public function handleEnv(EnvMsg $msg, Session $session): void
    {
        $overBudget = \strlen($msg->name) === 0
            || \strlen($msg->name) > self::ENV_MAX_NAME_BYTES
            || \strlen($msg->value) > self::ENV_MAX_VALUE_BYTES
            || (!\array_key_exists($msg->name, $this->envVars)
                && \count($this->envVars) >= self::ENV_MAX_ENTRIES);

        if ($overBudget) {
            $this->envDrops++;
            if (!$this->envNoticeSent && \is_resource($this->stderr)) {
                $this->envNoticeSent = true;
                \fwrite($this->stderr, Lang::t('channel.env_dropped') . "\n");
            }

            return;
        }

        $this->envVars[$msg->name] = $msg->value;
    }

    /**
     * Receive the RFC 4254 `break` signal — and do nothing with it.
     *
     * A break is a line-state event on the SSH channel (think a physical
     * terminal's BREAK key). Nothing reaches a PTY child through it: the byte
     * stream stays untouched, the child's terminal state stays as it was, and
     * the only faithful local action would be to invent an event the child
     * never asked for. Silence is the correct handler, so this method is empty
     * on purpose (audit NIT 13). It stays rather than disappearing because
     * {@see ChannelHandler} is the seam a wire decoder feeds, and a decoder
     * needs every message named.
     */
    public function handleBreak(BreakMsg $msg, Session $session): void
    {
    }

    public function cols(): int
    {
        return $this->cols;
    }

    public function rows(): int
    {
        return $this->rows;
    }

    public function ptyAllocated(): ?bool
    {
        return $this->ptyAllocated;
    }

    /**
     * @return array<string,string>
     */
    public function envVars(): array
    {
        return $this->envVars;
    }

    public function shellRequested(): bool
    {
        return $this->shellRequested;
    }

    public function execRequested(): bool
    {
        return $this->execRequested;
    }

    /**
     * @return list<string>
     */
    public function pendingCommand(): array
    {
        return $this->pendingCommand;
    }

    private function spawnShell(Session $session): void
    {
        if ($this->spawner === null) {
            return;
        }
        $env = $this->buildEnv($session);
        $fallbackCols = $this->cols > 0 ? $this->cols : 80;
        $fallbackRows = $this->rows > 0 ? $this->rows : 24;
        $effectiveCols = $session->cols > 0 ? $session->cols : $fallbackCols;
        $effectiveRows = $session->rows > 0 ? $session->rows : $fallbackRows;
        // Carry protocol metadata (sessionId/authMethod/versions) that
        // InProcessTransport::run attached — only geometry is re-derived
        // here, never the protocol fields.
        $effectiveSession = $session->withSize($effectiveCols, $effectiveRows);
        $this->spawner->runChild($effectiveSession, [$this->resolveShell(), '-l'], $env);
    }

    /**
     * Resolve the login shell to spawn.
     *
     * Order: explicit configured shell → the server-side process's
     * `SHELL` variable (under sshd ForceCommand that is the
     * authenticated user's login shell) → {@see FALLBACK_SHELL}.
     *
     * Client-supplied values never participate: `SHELL` sits in
     * {@see DANGEROUS_VARS} and is stripped from the child environment,
     * so a remote peer cannot pick the interpreter it gets exec'd into.
     */
    private function resolveShell(): string
    {
        $configured = $this->shell;
        if ($configured !== null && trim($configured) !== '') {
            return $configured;
        }

        $fromEnvironment = getenv('SHELL');
        if (is_string($fromEnvironment) && trim($fromEnvironment) !== '') {
            return $fromEnvironment;
        }

        return self::FALLBACK_SHELL;
    }

    /**
     * @param list<string> $cmd
     */
    private function spawnWithExec(Session $session, array $cmd): void
    {
        if ($this->spawner === null) {
            return;
        }
        $env = $this->buildEnv($session);
        $this->spawner->runChild($session, $cmd, $env);
    }

    /**
     * Build the environment for the child process.
     *
     * Always returns a floor of safe vars (TERM, USER, LANG, PATH, and
     * HOME when the session carries a user name — see the empty-user leg
     * below). Client-supplied env vars are merged only if their names
     * appear in the $acceptEnv allowlist, and never includes known-
     * dangerous vars (LD_PRELOAD, LD_LIBRARY_PATH, BASH_ENV, ENV, IFS,
     * PATH, SHELL) even if explicitly allowlisted.
     *
     * @return array<string,string>
     */
    private function buildEnv(Session $session): array
    {
        // Build the child's environment from a floor of our own naming.
        //
        // Two entries are honest exceptions to that sentence, and both are
        // deliberate (audit MINOR 14). `PATH` is taken from the daemon on
        // purpose: a child with no search path cannot find /bin/sh, so the
        // alternative to inheriting it is a hardcoded default that breaks
        // any deployment that installs elsewhere - the value is the
        // supervisor's, which under ForceCommand is root's environment, not
        // the peer's. `HOME` is DERIVED (`/home/<user>`), not looked up,
        // because reading the passwd entry here would mean a second source
        // of truth this class cannot validate; a deployment whose homes
        // differ passes `HOME` explicitly. Everything else the peer can
        // name arrives only through `$acceptEnv` ∩ this file's deny-list.
        $env = [
            'TERM'  => $session->term ?: 'xterm-256color',
            'USER'  => $session->user,
            'LANG'  => $session->lang ?: 'C.UTF-8',
            'PATH'  => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
        ];
        // HOME is DERIVED (`/home/<user>`), never looked up — and only
        // derived at all when a user name exists to hang it on. `/home/`
        // plus an empty name is the root-owned directory itself, and a
        // trustingly-writing child would land there; omitting the key
        // instead leaves the child in the platform's own "unset HOME"
        // posture, which every program already handles (audit 041).
        if ($session->user !== '') {
            $env['HOME'] = '/home/' . $session->user;
        }

        // Merge only allowlisted client vars; exclude dangerous ones unconditionally.
        foreach ($this->envVars as $name => $value) {
            if (\in_array($name, self::DANGEROUS_VARS, true)) {
                continue;
            }
            if (\in_array($name, $this->acceptEnv, true)) {
                $env[$name] = $value;
            }
        }

        return $env;
    }

    /**
     * Split a command string into argv tokens.
     *
     * Handles basic quoting (single and double) and backslash escapes.
     * Outside quotes, `\` consumes the next char literally so
     * `foo\ bar` → one token `foo bar`. Inside `"…"`, `\` escapes
     * only `$`, backtick, `"`, `\` (and a newline continues the line);
     * before anything else the backslash is literal, so Windows paths
     * like `"C:\path"` survive verbatim while `"a\"b"` recovers the
     * double quote the sending shell escaped (POSIX). Inside single
     * quotes a backslash is always literal. Empty quoted args (`cmd ''`)
     * are preserved as empty-string tokens.
     *
     * This is a pragmatic tokenizer, NOT full POSIX word-splitting —
     * no `$()`, backticks, variable expansion, or globbing (the
     * double-quote escapes above restore quoting bytes only; `$HOME`
     * stays literal text, never a lookup). Tokens go
     * to `runChild()` as argv with no shell, so the divergence is safe
     * but documented. It is also the decoder an embedder uses for the
     * `SSH_ORIGINAL_COMMAND` a ForceCommand child receives: that
     * variable carries the same string the exec channel would have
     * carried, so decode-and-gate once ({@see execAllowed()}) rather
     * than re-splitting ad hoc.
     *
     * @return list<string>
     */
    public static function decodeCommandString(string $command): array
    {
        $tokens = [];
        $current = '';
        $inSingle = false;
        $inDouble = false;
        $quoteOpened = false; // tracks whether current token had a quote open
        $i = 0;
        $len = \strlen($command);

        while ($i < $len) {
            $ch = $command[$i];

            if (!$inDouble && !$inSingle) {
                if ($ch === "'") {
                    $inSingle = true;
                    $quoteOpened = true;
                } elseif ($ch === '"') {
                    $inDouble = true;
                    $quoteOpened = true;
                } elseif ($ch === '\\') {
                    // Backslash escape outside quotes: consume next char literally.
                    $i++;
                    if ($i < $len) {
                        $current .= $command[$i];
                    }
                } elseif ($ch === ' ') {
                    if ($current !== '' || $quoteOpened) {
                        $tokens[] = $current;
                        $current = '';
                        $quoteOpened = false;
                    }
                } else {
                    $current .= $ch;
                }
            } elseif ($inSingle && $ch === "'") {
                // Inside single quotes, backslash is literal — no special handling.
                $inSingle = false;
            } elseif ($inDouble && $ch === '\\') {
                // POSIX double-quote rule: inside `"…"`, a backslash
                // escapes exactly `$`, backtick, `"` and `\`, and a
                // following newline is a line continuation; before any
                // other character the backslash is literal itself.
                $next = $i + 1 < $len ? $command[$i + 1] : null;
                if ($next === '"' || $next === '$' || $next === '`' || $next === '\\') {
                    $current .= $next;
                    $i++;
                } elseif ($next === "\n") {
                    $i++;
                } else {
                    // Literal backslash — including the trailing-`\`-at-
                    // end-of-string leg, which keeps its byte rather
                    // than swallowing it.
                    $current .= $ch;
                }
            } elseif ($inDouble && $ch === '"') {
                // Emit empty-string token for `''` or `""` before clearing.
                if ($quoteOpened && $current === '') {
                    $tokens[] = '';
                }
                $inDouble = false;
                $quoteOpened = false;
            } else {
                $current .= $ch;
            }
            $i++;
        }

        if ($current !== '' || $quoteOpened) {
            $tokens[] = $current;
        }

        return $tokens;
    }
}
