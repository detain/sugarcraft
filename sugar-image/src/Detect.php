<?php

declare(strict_types=1);

namespace SugarCraft\Image;

use SugarCraft\ColorProfile\Probe\Capability as ProbeCapability;
use SugarCraft\ColorProfile\Probe\TerminalProbe;
use SugarCraft\Core\Util\TtyDetect;

/**
 * Terminal image-protocol capability detection.
 *
 * F-01: the env stage is a {@see TermDb} consult — a declarative port of
 * chafa's 1338-line built-in terminal table — and a table claim is only
 * ever UPGRADED by a live DA1 reply, never downgraded through it.
 *
 * Probes environment variables and sends DA1 queries to determine
 * which image-rendering protocols the current terminal supports.
 * Results are cached per-process.
 *
 * Precedence: Kitty → iTerm2 → Sixel → HalfBlock (always available).
 *
 * The query phase runs with the tty briefly in non-canonical, echo-off
 * mode (ISS-048) — in cooked mode the queries echo, the replies sit
 * behind Enter, and leftovers ride into the next reader.
 */
final class Detect
{
    private const DA1_QUERY    = "\x1b[c";           // DA1 "who are you?"
    private const XTWINOP_14  = "\x1b[14t";          // window pixel size
    private const XTWINOP_16  = "\x1b[16t";          // cell pixel size
    private const XTWINOP_18  = "\x1b[18t";          // terminal cell count
    private const TIMEOUT_MS  = 100;

    /**
     * F-04: the tmux format string carrying the OUTER terminal's negotiated
     * feature list, asked the way chafa asks it
     * (mirrors chafa `tools/chafa/chicle-options.c:1766-1796`
     * `query_tmux_client_sixels()` → `tmux display-message -p
     * '#{client_termfeatures}'`). Measured on tmux next-3.6 the answer is a
     * comma list ("bpaste,ccolour,clipboard,cstyle,focus,title"); other builds
     * of the 3.3+ feature list use ';'. {@see parseTermFeatures()} splits on
     * both, so neither spelling can hide the sixel token.
     */
    private const CLIENT_FEATURES_FORMAT = '#{client_termfeatures}';

    /** F-04: wall bound for one feature query — a socket round-trip, not a job. */
    private const TERMFEATURES_DEADLINE_MS = 100;

    /** F-04: hard cap on captured stdout before the child is killed (answers are short). */
    private const TERMFEATURES_MAX_BYTES = 65536;

    /** F-04: grace between SIGTERM and SIGKILL while escalating a runaway child. */
    private const TERMFEATURES_TERM_GRACE_SECONDS = 0.25;

    /**
     * Terminator for a DA1 reply: `` ESC [ ? <attrs> c ``.
     *
     * It deliberately requires the ``?`` flag byte, which no real reply
     * omits and the query itself (`` ESC [ c ``) never carries — echoed
     * query bytes therefore cannot terminate a read (ISS-048). Public so
     * tests pin its polarity.
     */
    public const DA1_REPLY_TERMINATOR = '/\e\[\?[\d;]*c/';

    /**
     * Terminator for an XTWINOPS 14/16/18 reply: every real answer carries
     * the mode id and at least one ``;`` parameter before the ``t``
     * (``ESC [ 6 ; h ; w t``), which the queries (``ESC [ 16t`` …) never
     * do — echo-safe by the same argument as the DA1 pattern.
     */
    public const XTWINOPS_REPLY_TERMINATOR = '/\e\[[468];[\d;]*t/';

    /**
     * F-19: plausibility ceilings for an XTWINOPS reply. Chafa's real
     * bounds (chafa-term.c:54-55) are per-TYPE: PIXEL_EXTENT_MAX (8192*3
     * = 24576) caps BOTH axes of a pixel reply (:476-477) and
     * CELL_EXTENT_PX_MAX 8192 caps BOTH axes of a cell reply (:498-499) —
     * the cure added after issue #62, when a hostile reply drove pixel
     * budgets into gigabyte territory. Detect ships one per-AXIS pair
     * instead: every 4/6/8;t reply must satisfy `0 < x < 8192` /
     * `0 < y < 24576` (exclusive). Disclosed divergences: a pixel reply
     * wider than 8192 is falsely rejected (fail-safe — the ladder falls
     * back to font-estimated sizes), and a cell reply claiming more than
     * 8192 rows is accepted where chafa would refuse (harmless — cell
     * counts are display-bound, not pixel-bound). Per-type parity is a
     * candidate alongside the S4.2 TermDb probe rework; the exclusive
     * lower bound (zero extents are non-answers) is chafa-faithful
     * either way.
     */
    private const MAX_REPLY_X = 8192;
    private const MAX_REPLY_Y = 24576;

    /** @var resource|null */
    private static $probeStdin = null;

    /**
     * F-05 opt-out: when false, {@see TmuxPassthrough::ensure()} refuses to
     * touch the tmux `allow-passthrough` option at all. Library politeness
     * for embedders that own their tmux configuration — chafa gates the
     * same way CLI-side before ever calling the applier
     * (chicle-options.c:2412-2418 "only if both detected and desired, and
     * a graphics protocol is desired").
     */
    private static bool $autoPassthrough = true;

    /**
     * F-04 test seam: forced tri-state for {@see probeTmuxTermFeatures()}.
     * null means "no override — ask (or fail-soft AUTO)"; when set, the
     * wrapped state is the whole answer and NO tmux process is spawned.
     * The array wrapper is what keeps "override with AUTO" (['state'=>null])
     * distinct from "no override" (null) — the same sentinel-pair idea the
     * lib's XSet law uses, one level up.
     *
     * @var array{state:bool|null}|null
     */
    private static ?array $tmuxFeaturesOverride = null;

    /**
     * F-07 test seam: forced terminfo answer for {@see probeTerminfoSixel()}.
     * null means "no override — run a live TerminalProbe". Unlike the F-04
     * feature list this stage has no AUTO polarity to preserve: a false
     * answer falls through to the DA1 arm (exactly the pre-F-07 world), so a
     * plain ?bool suffices where F-04 needed the sentinel wrapper.
     */
    private static ?bool $terminfoSixelOverride = null;

    /**
     * F-07 seam: the probe object the live arm consults; null constructs a
     * default TerminalProbe on each need (Detect::probe() caches
     * process-wide, so this runs at most once anyway). Tests inject a
     * subclass faking the protected runCommand/infocmpAvailable arms — the
     * override contract sugar-colorprofile documents for exactly that.
     */
    private static ?TerminalProbe $terminfoProbe = null;

    /** Raw bytes of the last DA1 reply, for debugging / testing. */
    private static ?string $lastDa1Reply = null;

    private static ?Capability $cached = null;

    /**
     * A-02 test seam: forced answer for {@see supportsRepeat()}.
     * null means "no override — consult the env truth table".
     */
    private static ?bool $repeatOverride = null;

    /**
     * Probe once and cache the result for the lifetime of this process.
     */
    public static function cached(): Capability
    {
        return self::$cached ??= self::probe();
    }

    /**
     * Probe the terminal: env vars first, then optional DA1 query.
     *
     * If env vars already give a definite protocol (Kitty / iTerm2),
     * DA1 is skipped.  It only fills gaps — primarily detecting sixel
     * on xterm-compatible terminals that have no identifying env var.
     */
    public static function probe(): Capability
    {
        // ISS-048: every path below writes query bytes and waits ≤100 ms for
        // a reply. In the terminal's default COOKED mode that exchange is
        // broken three ways: the query echoes to the screen, the reply only
        // becomes readable after Enter, and any bytes left unread when we
        // stop waiting ride into the next reader — for candy-code, out of
        // the launcher and into the user's bash. Put the tty in
        // non-canonical, echo-off mode for exactly the query phase, and
        // restore whatever settings it had even if a probe throws.
        $restore = self::enterQueryMode();
        try {
            return self::probeQueries();
        } finally {
            self::exitQueryMode($restore);
        }
    }

    private static function probeQueries(): Capability
    {
        $inTmux = getenv('TMUX') !== false;

        $cap = self::probeEnv($inTmux);

        // Env vars gave a definite answer — no DA1 needed.
        if ($cap->kitty || $cap->iterm2) {
            $result = $cap->withCellSize(self::probeFontSize());
            self::drainStdin(50, self::stdinFd());
            return $result;
        }

        // F-04: inside tmux the pane's DA1 reply answers FOR TMUX, not for
        // the outer terminal — chicle-options.c:1755-1764: "tmux claims sixel
        // support in its own DA1 response regardless, so probing the pane
        // can't tell us" (it includes the sixel bit to enable passthrough).
        // Ask the server what the attached client's terminal really features;
        // a definite answer outranks any pane-side claim, and AUTO (tmux
        // absent/old, no client, dead socket, over-budget reply) leaves the
        // pre-F-04 ladder exactly as it was — fail-soft, never a veto by
        // silence.
        $features = $inTmux ? self::probeTmuxTermFeatures() : null;

        if ($features === true) {
            // The outer terminal does sixel and a sixel-capable tmux (3.3+)
            // renders it in-pane; the DA1 round-trip could only agree — skip
            // it, like chicle short-circuits on its TRUE tristate.
            $result = Capability::sixel(self::probeFontSize(), $inTmux);
            self::drainStdin(50, self::stdinFd());
            return $result;
        }

        // Run DA1 sixel probing BEFORE font-size probing so that in tests
        // using a preloaded socket pair, DA1 reply bytes are not consumed
        // by probeFontSize() before probeDa1() gets to read them. A definite
        // feature-list NO (below) skips the round-trip outright: its answer
        // is already known and the query is the thing F-04 distrusts.
        if ($features !== false) {
            // F-07: consult the terminal database BEFORE the DA1 round-trip
            // (same ordering logic as chafa: a local fact costs nothing, a
            // live query costs a timeout). A sixl/Sixel/Ss1 capability in
            // the entry is a definite published fact about $TERM, so
            // TERM-only sixel terminals are recognised without waiting on
            // any reply at all. Only TRUE promotes; "no sixl" and "cannot
            // ask" both leave the DA1 arm exactly as F-04 left it. Under a
            // definite feature-list VETO the stage is skipped: terminfo
            // describes the emulator $TERM names, not tmux's display
            // policy — a veto must not be laundered back into a sixel
            // claim through the database.
            if (!$cap->sixel && self::probeTerminfoSixel()) {
                $result = Capability::sixel(self::probeFontSize(), $inTmux);
                self::drainStdin(50, self::stdinFd());
                return $result;
            }

            $sixelViaDa1 = self::probeDa1();
            if ($sixelViaDa1 === true) {
                $result = Capability::sixel(self::probeFontSize(), $inTmux);
                self::drainStdin(50, self::stdinFd());
                return $result;
            }
        }

        if ($features === false && $cap->sixel) {
            // Chicle turns the sixel pixel mode OFF on exactly this answer
            // (chicle-options.c:2180-2191). The same override demotes a
            // sixel claim the ENV ladder seeded (a leaked $XTERM_VERSION or
            // $TERM survives into some panes): inside a pane, graphics never
            // reach an outer terminal the server says cannot display them.
            $cap = Capability::unknown(null, $inTmux);
        }

        $result = $cap->withCellSize(self::probeFontSize());
        // Drain any remaining probe-response bytes so they don't appear
        // as stray output before the image render.
        self::drainStdin(50, self::stdinFd());
        return $result;
    }

    /**
     * Clear the cached capability and last DA1 reply (for testing).
     *
     * Deliberately does NOT clear the override seams ($repeatOverride,
     * $tmuxTermFeatures, $terminfoSixel) — tests that armed one must call
     * the matching {@see clearSupportsRepeatOverride()}-style clear
     * explicitly.
     */
    public static function reset(): void
    {
        self::$cached       = null;
        self::$lastDa1Reply = null;
    }

    /**
     * Return the raw bytes of the last DA1 reply, or null if no query
     * was made or it timed out.
     */
    public static function lastDa1Reply(): ?string
    {
        return self::$lastDa1Reply;
    }

    /**
     * Override the stdin source used during DA1 probing.
     * Use this in tests to inject canned responses.
     *
     * Pass null to restore the real stdin.
     *
     * @param resource|null $fd
     */
    public static function setProbeStdin($fd): void
    {
        self::$probeStdin = $fd;
    }

    /**
     * Opt the automatic tmux `allow-passthrough` transaction on or off
     * (F-05). Default on; {@see TmuxPassthrough::ensure()} consults this
     * on every call. Takes effect immediately for future probes — an
     * already-open transaction is NOT rolled back here; retire it via
     * {@see TmuxPassthrough::retire()}.
     */
    public static function setAutoPassthrough(bool $enabled): void
    {
        self::$autoPassthrough = $enabled;
    }

    /** Whether {@see setAutoPassthrough()} currently permits the F-05 transaction. */
    public static function autoPassthrough(): bool
    {
        return self::$autoPassthrough;
    }

    /**
     * A-02 capability gate: does the current terminal interpret
     * `ESC[count b` (REP — repeat the preceding graphic character)?
     *
     * chafa models this per-emulator in its term.c tables
     * (CHAFA_TERM_SEQ_REPEAT_CHAR set only for the emulators audited to
     * implement REP); a mosaic emitter must AND this fact with the
     * {@see \SugarCraft\CellBuffer\Diff\RepeatGate} byte arithmetic before
     * compressing a run.
     *
     * This is the env-only truth table that lives until the audited
     * per-terminal REP axis lands on the S4.2 TermDb table (F-01 shipped
     * that table pixel-only, deliberately) — the seam is exactly one
     * method body, mirroring how {@see probeTerminfoSixel()} names its own
     * swap point. Conservative rows (ECMA-48 REP is a core
     * sequence; only two shapes are refused here):
     *  - no $TERM / TERM=dumb  → false (no escape-sequence guarantees);
     *  - TERM=screen*          → false (GNU screen keeps its own screen
     *    buffer and is the one mainstream multiplexer not documented to
     *    honour client REP);
     *  - everything else       → true (xterm-class: xterm, rxvt, vte/foot,
     *    kitty, alacritty, wezterm, mlterm, tmux). Per-emulator vetoes
     *    beyond these rows are the TermDb table's REP-axis follow-up, not
     *    a guess the env ladder should make.
     *
     * TODO: supportsRepeat REP-axis chicle parity — follow-up E-ID mint at
     * W5 docs (never claim parity now).
     */
    public static function supportsRepeat(): bool
    {
        if (self::$repeatOverride !== null) {
            return self::$repeatOverride;
        }

        $term = (string) getenv('TERM');
        if ($term === '' || $term === 'dumb') {
            return false;
        }

        return !str_starts_with($term, 'screen');
    }

    /**
     * Force the A-02 REP answer for tests (null restores the env table).
     * Same shape as the other Detect tri-states: while overridden, the
     * table below is never consulted.
     */
    public static function setSupportsRepeatOverride(?bool $supported): void
    {
        self::$repeatOverride = $supported;
    }

    /** Remove the override; {@see supportsRepeat()} goes back to the env table. */
    public static function clearSupportsRepeatOverride(): void
    {
        self::$repeatOverride = null;
    }

    /**
     * Force the F-04 termfeatures answer for tests: true = outer terminal
     * has sixel, false = definite no (veto), null = AUTO (the fail-soft
     * verdict — while overridden, NO tmux process is ever spawned).
     */
    public static function setTmuxTermFeaturesOverride(?bool $state): void
    {
        self::$tmuxFeaturesOverride = ['state' => $state];
    }

    /** Remove the override; {@see probeTmuxTermFeatures()} goes back to asking tmux. */
    public static function clearTmuxTermFeaturesOverride(): void
    {
        self::$tmuxFeaturesOverride = null;
    }

    /**
     * F-07 test seam: pin the terminfo sixl answer without touching a real
     * terminal database. true = short-circuit the ladder to sixel; false =
     * "terminfo says nothing", fall through to the DA1 arm. While overridden
     * no infocmp process is ever spawned.
     */
    public static function setTerminfoSixelOverride(bool $present): void
    {
        self::$terminfoSixelOverride = $present;
    }

    /** Remove the override; {@see probeTerminfoSixel()} consults the live probe again. */
    public static function clearTerminfoSixelOverride(): void
    {
        self::$terminfoSixelOverride = null;
    }

    /**
     * F-07 seam: substitute the {@see TerminalProbe} the live arm runs
     * (null restores default construction). The capability table itself
     * stays in sugar-colorprofile — this is wiring, not a second lookup.
     */
    public static function setTerminfoProbe(?TerminalProbe $probe): void
    {
        self::$terminfoProbe = $probe;
    }

    /**
     * F-04: tri-state answer to "can the terminal OUTSIDE this tmux pane
     * display sixels?", mirroring {@see chafa}'s `ChicleTristate`
     * (chicle-options.c:1760-1796).
     *
     *  - true  — the feature list names sixel;
     *  - false — the feature list came back definite and sixel is NOT in it
     *            (this is the ONLY shape that vetoes; per chicle, "no answer"
     *            is not an answer);
     *  - null  — AUTO: outside tmux, tmux older than 3.3 (the format did not
     *            exist and the command errors), no attached client, dead
     *            socket, unparseable or empty reply, or the 100 ms budget
     *            expired. Callers must treat this as "no information".
     */
    private static function probeTmuxTermFeatures(): ?bool
    {
        if (self::$tmuxFeaturesOverride !== null) {
            return self::$tmuxFeaturesOverride['state'];
        }
        if (getenv('TMUX') === false) {
            return null; // the guard clause IS the zero-process cost outside tmux
        }

        $reply = self::runTmuxFeatureQuery();

        return $reply === null ? null : self::parseTermFeatures($reply);
    }

    /**
     * F-07: whether the terminfo entry for $TERM declares a Sixel graphics
     * capability, consulted through sugar-colorprofile's TerminalProbe
     * (its Phase 2, sugar-colorprofile/src/Probe/TerminalProbe.php:247-259
     * and the name-position matcher at :297-324). The SIXEL_TERMCAPS table
     * lives THERE on purpose; this call site owns only the wiring.
     *
     * Terminfo is a LOCAL database read (`infocmp -1 $TERM` parses termcap
     * files; no bytes touch the tty), which is why this answer can outrank
     * a missing DA1 round-trip but not a live one under tmux veto. The arm
     * is a SugarCraft extension: chicle reads NO terminfo at all (zero
     * tgetent/infocmp/setupterm call sites in chafa/) — its sixl answer is
     * the built-in env-keyed table (chafa-term-db.c:1142-1161) plus the
     * live DA1 (chafa-term.c:509-522).
     *
     * Returns false — never throws, never blocks on a terminal — for an
     * absent/dumb $TERM, an absent infocmp, or NO_COLOR/CLICOLOR=0
     * suppression (the probe's own ladder rules, which terminate its
     * capability phases). Only TRUE promotes here: a terminfo NO is never
     * a veto, it leaves the DA1 arm byte-unchanged — the database is an
     * accelerator, not an authority. F-01 note: the s2-5 ledger's swap seam
     * ("the whole stage the S4.2 TermDb rework replaces in one move") landed
     * as a COMPLEMENT, not a replacement — the declarative table
     * {@see TermDb} took over the env stage ahead of this arm, and the live
     * infocmp consult stays as the long tail for $TERM entries the table
     * does not name. Chicle has no terminfo half — its detection is the
     * term-db.c table plus the live DA1 only (PC-10); this arm is ours
     * alone, a strict superset beside the table, and collapsing it into
     * the table would be a behavior regression the DetectTest suite pins
     * forbid.
     */
    private static function probeTerminfoSixel(): bool
    {
        if (self::$terminfoSixelOverride !== null) {
            return self::$terminfoSixelOverride;
        }

        $probe = self::$terminfoProbe ?? new TerminalProbe();

        // interactive=false: this stage is the terminal DATABASE question,
        // never an escape-query round-trip — pinning it false also keeps a
        // production call from writing probe bytes to a tty the caller has
        // not entered query mode on.
        return $probe->runProbe([], false)->has(ProbeCapability::Sixel);
    }

    /**
     * Parse a `#{client_termfeatures}` reply into the F-04 tri-state.
     *
     * Separator alphabet: chicle splits the comma list on `, \t\r\n`; tmux
     * builds that are 'us;suspend;...' style carry ';'. Split on both plus
     * whitespace so no list spelling hides a token. Match is on WHOLE tokens
     * only — an unrelated feature name containing the letters sixel (or a
     * `desc=` string, which this command never emits but a future format
     * might) must not fake a claim the way the old DA1 substring match faked
     * one from tmux's own reply.
     *
     * tmux 3.4+ additionally spells refusals as `no-<feature>`; a `no-sixel`
     * token is the DEFINITE no even when a bare list also came through, so it
     * is checked per token, not after the loop.
     */
    private static function parseTermFeatures(string $output): ?bool
    {
        $tokens = preg_split('/[,;\s]+/', trim($output), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $sawFeature = false;
        foreach ($tokens as $token) {
            if (str_starts_with($token, 'no-')) {
                if (substr($token, 3) === 'sixel') {
                    return false; // explicit negative outranks everything else
                }
                continue;
            }
            if ($token === 'unknown') {
                // tmux's own "I never asked the outer terminal" marker —
                // features were reported, but not trustworthily. AUTO.
                return null;
            }
            if ($token === 'sixel') {
                return true;
            }
            $sawFeature = true;
        }

        // A list came back, sixel was simply not in it: chicle's exact
        // "features were reported, but sixel isn't among them" FALSE.
        return $sawFeature ? false : null;
    }

    /**
     * Run `tmux display-message -p '#{client_termfeatures}'` and return its
     * stdout, or null on ANY failure (missing binary, dead socket, non-zero
     * exit, over-budget reply) — every null path reads back as AUTO upstream.
     *
     * Child-lifetime discipline is the house shape copied from
     * {@see TmuxPassthrough::runTmux}: argv-array proc_open (no shell in
     * between), stdin and stderr both /dev/null (a reader-less stderr pipe
     * would SIGPIPE the child mid-command), a deadline-bounded read, and a
     * TERM→grace-poll→KILL escalation that ends in proc_close() on EVERY
     * path so no child or zombie outlives this call.
     *
     * The command carries NO -S socket argument on purpose: tmux client
     * commands take their server from $TMUX, which probeTmuxTermFeatures()
     * has just checked — chicle's model command, same reason.
     */
    private static function runTmuxFeatureQuery(): ?string
    {
        $descriptorSpec = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];
        $pipes = [];
        $proc = @proc_open(
            ['tmux', 'display-message', '-p', self::CLIENT_FEATURES_FORMAT],
            $descriptorSpec,
            $pipes
        );
        if (!is_resource($proc)) {
            return null;
        }

        $stdout    = '';
        $completed = false;
        $deadline  = Deadline::in(self::TERMFEATURES_DEADLINE_MS);
        while (true) {
            $remaining = $deadline->remainingMs();
            if ($remaining <= 0) {
                break;
            }
            $read  = [$pipes[1]];
            $write = $except = null;
            /** @var int|false */
            $ready = @stream_select($read, $write, $except, 0, $remaining * 1000);
            if ($ready === false) {
                break; // signal-interrupted select: the query is blown
            }
            if ($ready === 0) {
                continue; // slice spent: the deadline bounds the loop
            }
            $chunk = fread($pipes[1], 8192);
            if ($chunk === false) {
                $completed = true; // read error reads as end-of-stream; exit judges
                break;
            }
            $stdout .= $chunk;
            if (strlen($stdout) > self::TERMFEATURES_MAX_BYTES) {
                break; // an answer this long is not a feature list; kill and bail
            }
            if (feof($pipes[1])) {
                $completed = true; // stdout closed: the answer (or its absence) is complete
                break;
            }
        }
        fclose($pipes[1]);

        if (!$completed) {
            self::terminateBoundedChild($proc);
            proc_close($proc);
            return null;
        }

        $status = proc_get_status($proc);
        if ($status['running']) {
            // EOF arrived but the child lingers (issue #62 lesson generalized:
            // a still-running writer never yields a stable exit code).
            self::terminateBoundedChild($proc);
        }

        $exit = proc_close($proc);
        if ($exit !== 0) {
            return null; // old tmux rejects the unknown format with exit != 0 → AUTO
        }

        return $stdout;
    }

    /**
     * SIGTERM, poll through the grace window, SIGKILL, poll for the reap —
     * the integer-literal signals (15, 9) are the same no-ext-pcntl choice
     * {@see TmuxPassthrough::terminateBounded()} documents.
     *
     * @param resource $proc
     */
    private static function terminateBoundedChild($proc): void
    {
        if (!proc_get_status($proc)['running']) {
            return;
        }

        proc_terminate($proc, 15);
        if (self::awaitChildExit($proc, self::TERMFEATURES_TERM_GRACE_SECONDS)) {
            return;
        }

        proc_terminate($proc, 9);
        self::awaitChildExit($proc, self::TERMFEATURES_TERM_GRACE_SECONDS);
    }

    /**
     * Poll the child's status until it reports not-running or the budget
     * expires — one poll before the first sleep and one after the last, so
     * the return is measured liveness, not hope.
     *
     * @param resource $proc
     */
    private static function awaitChildExit($proc, float $budgetSeconds): bool
    {
        $end = hrtime(true) / 1_000_000_000 + $budgetSeconds;
        do {
            if (!proc_get_status($proc)['running']) {
                return true;
            }
            usleep(10_000);
        } while (hrtime(true) / 1_000_000_000 < $end);

        return !proc_get_status($proc)['running'];
    }

    /**
     * Send a DA1 query to the terminal and parse the reply.
     *
     * Writes `` \\x1b[c `` to stdout and reads the response from stdin
     * with a 100 ms timeout.  Returns null when stdin/out is not a TTY
     * or the reply times out.
     *
     * @return bool|null  true = sixel supported, false = not supported,
     *                    null = could not query (not a TTY or timeout)
     */
    private static function probeDa1(): ?bool
    {
        if (!self::isInteractiveTty()) {
            return null;
        }

        $fd = self::stdinFd();

        // Note: we intentionally skip a pre-read drain here.  In a real
        // interactive terminal any stale bytes before our query are
        // unlikely (we're the only thing writing), and even if some
        // arrive they can't be confused for a valid DA1 reply: the
        // reply shape requires the '?' flag byte the query never carries,
        // and the read only stops on it (ISS-048).  In tests, pre-loading
        // the reply avoids any drain-then-read race condition.

        // Write DA1 query to stdout (terminal receives it as input).
        $written = @fwrite(STDOUT, self::DA1_QUERY);
        if ($written === false || $written !== strlen(self::DA1_QUERY)) {
            return null;
        }
        fflush(STDOUT);

        // Read the reply from stdin (terminal echoes it back).
        $reply = self::readStdinTimed(self::TIMEOUT_MS, $fd, self::DA1_REPLY_TERMINATOR);
        self::$lastDa1Reply = $reply;

        if ($reply === '') {
            return null;
        }

        return self::parseDa1Reply($reply);
    }

    /**
     * Drain up to $limitMs milliseconds of already-available stdin data.
     * This suppresses spurious replies from earlier queries.
     *
     * Null means {@see stdinFd()} found no usable descriptor 0, and there is
     * then nothing to drain. This is the site the E318 fatal came out of:
     * {@see probe()} drains UNCONDITIONALLY on all three of its exits, with no
     * isInteractiveTty() ahead of it, so it is reached with a closed handle
     * where the two read paths are not.
     *
     * @param resource|null $fd
     */
    private static function drainStdin(int $limitMs, $fd): void
    {
        if (!\is_resource($fd)) {
            return;
        }

        $read    = [$fd];
        $deadline = Deadline::in($limitMs);
        while (true) {
            $remaining = $deadline->remaining();
            if ($remaining <= 0) {
                break;
            }
            $w = $e = null;
            /** @var int|false */
            $changed = @stream_select($read, $w, $e, 0, $remaining * 1000);
            if ($changed === false || $changed === 0) {
                break;
            }
            $chunk = @fread($read[0], 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
        }
    }

    /**
     * Read available bytes from stdin until $timeoutMs elapses or the
     * buffer matches $replyTerminator (a PCRE pattern naming the reply's
     * shape).  Returns '' on timeout.
     *
     * Null means {@see stdinFd()} found no usable descriptor 0. '' is already
     * this method's no-answer value and every caller handles it, so a dead
     * descriptor degrades exactly the way a terminal that did not reply does
     * (E318).
     *
     * @param resource|null $fd
     */
    private static function readStdinTimed(int $timeoutMs, $fd, string $replyTerminator): string
    {
        if (!\is_resource($fd)) {
            return '';
        }

        $buf      = '';
        $deadline = Deadline::in($timeoutMs);

        while (true) {
            $remaining = $deadline->remaining();
            if ($remaining <= 0) {
                break;
            }
            $r = [$fd];
            $w = $e = null;
            /** @var int|false */
            $changed = @stream_select($r, $w, $e, 0, $remaining * 1000);
            if ($changed === false || $changed === 0) {
                break; // timeout
            }
            $chunk = @fread($r[0], 8192);
            if ($chunk === false) {
                break;
            }
            $buf .= $chunk;

            // Break on the REPLY's shape (ISS-048). This used to scan for
            // the QUERY bytes "\x1b[c", which a reply never contains — the
            // read then always ran the full 100 ms budget.
            if (preg_match($replyTerminator, $buf) === 1) {
                break;
            }
        }

        return $buf;
    }

    /**
     * The stream this class probes descriptor 0 through, or null when there
     * is not a usable one.
     *
     * WHAT THIS DOC-BLOCK SAID: "Whether stdin and stdout are connected to an
     * interactive TTY." WHAT IS TRUE: that sentence belongs to
     * {@see isInteractiveTty()} directly below and was copied onto the wrong
     * method; this one resolves a STREAM. WHY THE CORRECTION EARNS ITS PLACE
     * rather than a silent rewrite: the wrong sentence is what made the missing
     * guard below read as already handled — a method documented as returning a
     * boolean predicate is not one a reader checks for a closed resource.
     *
     * NULL IS A REAL ANSWER, NOT A DEFENSIVE ONE (E318/E302). A closed
     * descriptor 0 is a legitimate state for a process to be in — a daemon, a
     * git hook, `candy-code 0<&-`, or a test harness that replaced its own fd 0
     * — and `defined('STDIN')` stays true after `fclose(STDIN)` while
     * `is_resource()` goes false. Handing that on unguarded is what this used
     * to do, and the failure is NOT the suppressed warning it looks like:
     * MEASURED, PHP 8.3.6, `@stream_select()` drops the closed handle out of
     * the read array and then throws `ValueError: No stream arrays were
     * passed`, which `@` cannot suppress because it is thrown rather than
     * raised. Driving `Detect::probe()` in a process that had done
     * `fclose(\STDIN)`: `ValueError: No stream arrays were passed` from
     * {@see drainStdin()}, every call, before this guard existed.
     *
     * The callers below therefore treat null as "no probe is possible" and
     * return their own no-answer value, which is the same thing they already
     * return on a timeout.
     *
     * THIS IS ONE MEMBER OF A FAMILY, AND THE FAMILY LIVES AT sugar-core.
     * sugar-core and sugar-image were both written when descriptor 0 was
     * always a live terminal; the through-line, the other members, and the
     * measurements for each of the three standard-library calls that THROW
     * rather than degrade on a closed handle are on
     * {@see \SugarCraft\Core\Util\TtyDetect}'s class doc-block. Read it
     * before adding a caller here.
     *
     * The one detail that belongs on this side of the package boundary:
     * {@see isInteractiveTty()} below passes this method's result straight
     * into `TtyDetect::isAtty()`, and that method's `@param` reads
     * `resource|null` for exactly this reason (E341). The null is a real
     * answer crossing a package boundary, not defensive padding — the kind
     * of contract no single package's suite is positioned to catch.
     *
     * @return resource|null
     */
    private static function stdinFd()
    {
        $fd = self::$probeStdin ?? STDIN;

        return \is_resource($fd) ? $fd : null;
    }

    private static function isInteractiveTty(): bool
    {
        // Test override: when setProbeStdin() injected a mock stdin, skip
        // the real TTY check (CI environments have no real TTY). The
        // is_resource() is not redundant with the null check: a caller that
        // injected a stream and then closed it leaves a non-null handle that
        // is no longer a stream, and answering "interactive" for it is what
        // carries a dead descriptor into readStdinTimed() (E318).
        if (self::$probeStdin !== null) {
            return \is_resource(self::$probeStdin);
        }

        // Explicitly disabled for testing daemon/CGI environments.
        if (isset($GLOBALS['__candy_non_interactive'])) {
            return !$GLOBALS['__candy_non_interactive'];
        }

        return (
            TtyDetect::isAtty(self::stdinFd())
            && TtyDetect::isAtty(STDOUT)
        );
    }

    /**
     * Switch the controlling tty to non-canonical, echo-off mode for the
     * query phase, returning the `stty -g` snapshot that undoes it — or
     * null when this process must not touch a terminal at all.
     *
     * WHY IT CAN BE NULL, in order (ISS-048):
     *  - a probe stdin was injected (tests, harnesses): the real tty is
     *    not the stream under test, and re-setting it would be a side
     *    effect on the developer's own terminal for an exchange that
     *    never happens;
     *  - not an interactive tty: nothing echoes, nothing canonicalises,
     *    so there is nothing to fix;
     *  - `stty -g` answered empty (no `stty` binary, exotic terminal):
     *    restoring would then be impossible, and a probe stuck in
     *    raw mode is worse than one that echoes.
     *
     * `shell_exec` inherits our fd 0, so stty operates on exactly the
     * descriptor the probes read from.
     */
    private static function enterQueryMode(): ?string
    {
        if (self::$probeStdin !== null || !self::isInteractiveTty()) {
            return null;
        }

        $saved = trim((string) shell_exec('stty -g 2>/dev/null'));
        if ($saved === '') {
            return null;
        }
        shell_exec('stty -icanon -echo min 1 time 0 2>/dev/null');

        return $saved;
    }

    /** Put back what {@see enterQueryMode()} snapshot; null means it never took anything off. */
    private static function exitQueryMode(?string $saved): void
    {
        if ($saved === null) {
            return;
        }
        shell_exec('stty ' . escapeshellarg($saved) . ' 2>/dev/null');
    }

    /**
     * Parse a raw DA1 reply string.
     *
     * The reply format is `` ESC [ <primary> ; <secondary> ; ... c ``.
     * Sixel graphics support is indicated by bit 2 (value 4) in the
     * secondary attributes field, e.g. `` \\x1b[?62;4;0c ``.
     *
     * @return bool  true if `` ;4; `` appears in the reply
     */
    private static function parseDa1Reply(string $reply): bool
    {
        // DA1 reply format: ESC [ <attrs> c
        // Sixel support indicated by bit 4 in the SECONDARY or TERTIARY
        // attribute field.  The '4' appears as one of the semicolon-
        // separated values.  We accept:
        //   ;4;    sixel as secondary attr (most common: ?62;4;0c)
        //   ;4c    sixel as terminal attr in tertiary position (?62;0;4c)
        //   ?4c    sixel-only reply (mlterm sends this)
        // We scan for either ";4" or "?4" to cover all known formats.
        return (str_contains($reply, ';4;') || str_contains($reply, ';4c')
            || str_contains($reply, '?4c'));
    }

    /**
     * Probe font / cell size via XTWINOPS queries.
     *
     * Probes in order: 16t (cell pixels), 14t (window px) + 18t
     * (cell count) as fallback.  Returns null when stdin/out is not
     * a TTY or all queries time out.
     */
    private static function probeFontSize(): ?CellSize
    {
        if (!self::isInteractiveTty()) {
            return null;
        }

        // Try 16t first — it gives cell pixels directly.
        $cellSize = self::probeXtwino(self::XTWINOP_16);
        if ($cellSize !== null) {
            return $cellSize;
        }

        // Fall back: 14t (window px) + 18t (cell count).
        $windowPx = self::probeXtwino(self::XTWINOP_14);
        $cellCount = self::probeXtwinoCellCount();
        if ($windowPx !== null && $cellCount !== null
            && $cellCount->cellWidth > 0 && $cellCount->cellHeight > 0
        ) {
            $cellW = (int) round($windowPx->cellWidth / $cellCount->cellWidth);
            $cellH = (int) round($windowPx->cellHeight / $cellCount->cellHeight);
            if ($cellW > 0 && $cellH > 0) {
                return new CellSize($cellW, $cellH);
            }
        }

        return null;
    }

    /**
     * Send a single XTWINOPS query and parse the numeric reply.
     *
     * Replies are `` ESC [ <val1> ; <val2> t `` for 16t, and
     * `` ESC [ <rows> ; <cols> t `` for 14t.
     *
     * @return CellSize|null  parsed cell size, or null on timeout/parse error
     */
    private static function probeXtwino(string $query): ?CellSize
    {
        // Write the query to stdout.
        $written = @fwrite(STDOUT, $query);
        if ($written === false || $written !== strlen($query)) {
            return null;
        }
        fflush(STDOUT);

        // Read the response with a tight timeout (terminal responds fast).
        $reply = self::readStdinTimed(self::TIMEOUT_MS, self::stdinFd(), self::XTWINOPS_REPLY_TERMINATOR);

        return self::parseXtwinoReply($reply, $query);
    }

    /**
     * Probe terminal cell count via XTWINOPS 18t.
     *
     * Returns a CellSize where cellWidth = columns, cellHeight = rows.
     */
    private static function probeXtwinoCellCount(): ?CellSize
    {
        $written = @fwrite(STDOUT, self::XTWINOP_18);
        if ($written === false || $written !== strlen(self::XTWINOP_18)) {
            return null;
        }
        fflush(STDOUT);

        $reply = self::readStdinTimed(self::TIMEOUT_MS, self::stdinFd(), self::XTWINOPS_REPLY_TERMINATOR);

        return self::parseXtwinoReply($reply, self::XTWINOP_18);
    }

    /**
     * Parse an XTWINOPS reply into a CellSize.
     *
     * - 16t: `` ESC [ 6 ; <cellHeight> ; <cellWidth> t ``
     * - 14t: `` ESC [ 4 ; <windowHeight> ; <windowWidth> t ``
     *
     * @return CellSize|null
     */
    private static function parseXtwinoReply(string $reply, string $query): ?CellSize
    {
        if (!str_contains($reply, "\x1b[")) {
            return null;
        }

        $inner = substr($reply, strpos($reply, "\x1b[") + 2);
        if (!str_ends_with($inner, 't')) {
            return null;
        }
        $inner = rtrim(substr($inner, 0, -1)); // strip trailing 't'
        $parts = array_map('intval', explode(';', $inner));

        // 16t: format is 6;<cellHeight>;<cellWidth>t
        // 14t: format is 4;<windowHeight>;<windowWidth>t
        if (count($parts) < 3) {
            return null;
        }
        [$id, $v1, $v2] = $parts;

        // F-19: a well-formed reply can still lie about its size. Chafa
        // learned this the hard way (issue #62: a hostile emulator/host can
        // answer 16t with absurd extents, and every pixel-budget arithmetic
        // downstream trusts the numbers). Reject out-of-range extents here —
        // the single parse chokepoint all three queries flow through — so a
        // fabricated reply is indistinguishable from "no reply" and the
        // caller falls back to font-estimated sizes. The bound also rejects
        // ZERO extents: a terminal that answers "0 rows" did not answer
        // (pre-F-19, CellSize(0, n) rode through into division arithmetic).
        if ($v1 < 1 || $v1 >= self::MAX_REPLY_Y || $v2 < 1 || $v2 >= self::MAX_REPLY_X) {
            return null;
        }

        if ($id === 6 && $query === self::XTWINOP_16) {
            return new CellSize($v2, $v1); // w, h
        }
        if ($id === 4 && $query === self::XTWINOP_14) {
            return new CellSize($v2, $v1); // w, h
        }
        if ($id === 8 && $query === self::XTWINOP_18) {
            return new CellSize($v2, $v1); // cols, rows
        }

        return null;
    }

    /**
     * F-01: the environment stage is now a terminal-database consult.
     *
     * Snapshots the env vars {@see TermDb} knows about, resolves the chained
     * {@see TermInfo}, and maps it to a Capability under SugarCraft's family
     * precedence (Kitty > iTerm2 > Sixel) — the same order the pre-F-01
     * hardcoded ladder checked its three `if` blocks in, so realistic env
     * shapes answer as the old ladder did. Three synthetic edges diverge
     * (measured, disclosed): an EMPTY-string KITTY_WINDOW_ID counted by the
     * old getenv()!==false test but refused by the table's set-AND-non-empty
     * ISSET; TERM_PROGRAM case handling — 'WezTerm' now also answers iterm2
     * via the ORIGTERM row; and the old /xterm-kitty/i matched ANYWHERE in
     * $TERM where the dot-head split does not (TERM=xterm-kitty-foo loses
     * kitty, keeps sixel). What changed is that terminals the
     * ladder never named (konsole ≥ 22.03.70, foot via its prefix rule, warp,
     * contour, yaft, rio, mintty-as-$TERM, the tmux ≥ 3.4 native-sixel row)
     * are now recognized, and terminals chicle proves color-only (alacritty,
     * st, linux, plain xterm/vte) can be *listed* as known-nothing instead of
     * silently falling through.
     */
    private static function probeEnv(bool $inTmux = false): Capability
    {
        $info = TermDb::matchChain(TermDb::snapshotEnv());

        // Family precedence: the old ladder's block order is now the table's
        // resolution order (wezterm/mintty/rio claim several protocols at
        // once; the first hit wins exactly as before — pinned by
        // testWezTermIsKittyOnlyNotIterm2 and testIterm2ViaTermProgramMintty).
        if ($info->supports(TermInfo::PROTOCOL_KITTY)) {
            return Capability::kitty(null, $inTmux);
        }
        if ($info->supports(TermInfo::PROTOCOL_ITERM2)) {
            return Capability::iterm2(null, $inTmux);
        }
        if ($info->supports(TermInfo::PROTOCOL_SIXEL)) {
            return Capability::sixel(null, $inTmux);
        }

        // Half-block: always available.
        return Capability::unknown(null, $inTmux);
    }
}
