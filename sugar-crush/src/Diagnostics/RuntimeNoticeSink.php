<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Diagnostics;

use React\EventLoop\Loop;

/**
 * The MID-SESSION half of the transcript seam: a process-wide inbox any
 * subsystem can put a user-visible warning into once the alternate screen is
 * already up, drained by {@see \SugarCraft\Crush\Chat} on a subscription tick.
 *
 * WHY THIS EXISTS, AND WHAT IT IS NOT A DUPLICATE OF (E171). This application
 * already has a transcript seam:
 * {@see \SugarCraft\Crush\Cli\Bootstrap::warnPermissionConfigInTranscript()}
 * appends to a static list which
 * {@see \SugarCraft\Crush\Cli\Bootstrap::chat()} drains into
 * {@see \SugarCraft\Crush\Chat::withLaunchNotices()}. That drain happens ONCE,
 * at construction (and once more, as a delta, on the second-scan path in
 * `Bootstrap::app()`). It is a LAUNCH seam. Four rounds of audit prose called
 * it "the transcript seam" without that qualifier, and the qualifier is the
 * whole finding: a row recorded there after the drain goes into a static array
 * with no reader. Everything that warns DURING a turn — a tool-call parser
 * that had to refuse a call, a provider that degraded, an agent worker that
 * could not start — had nowhere to go but `error_log()`, i.e. fd 2, i.e. a
 * frame the renderer believes it owns.
 *
 * BOTH CHANNELS, NEVER ONE INSTEAD OF THE OTHER, and {@see warn()} is the
 * entry point that makes that the default rather than a thing each call site
 * has to remember. The `error_log()` copy stays because it is the COMPLETE
 * record: it is unclipped, it survives a sink that overflowed, it is what a
 * `-p` one-shot and a redirected log have, and it costs no model tokens. The
 * transcript copy is clipped and bounded because those rows are part of the
 * CONVERSATION — sent to the model on every subsequent turn — which is the
 * argument {@see \SugarCraft\Crush\Cli\Bootstrap::LAUNCH_NOTICE_LIMIT}
 * makes for the launch list.
 *
 * "BOUNDED" AND NOT "CAPPED", AND THE DIFFERENCE FROM THE LAUNCH LIST IS REAL.
 * WHAT THIS SAID: "clipped and capped … the same argument LAUNCH_NOTICE_LIMIT
 * makes". WHAT IS TRUE NOW, checked at source rather than inferred from the
 * constant's name: `LAUNCH_NOTICE_LIMIT` is a SESSION cap — the launch list
 * stops at 24 and synthesises one overflow row — whereas here the three bounds
 * are per-ROW ({@see MAX_CHARS}), per-BATCH ({@see NOTICE_LIMIT}, applied by
 * {@see drain()}) and, on the array backend only, per-QUEUE (`NOTICE_LIMIT`
 * again, applied by {@see record()}). On the TRANSPORT backend — the one an
 * interactive launch uses — `record()` returns before it ever reaches that
 * check, so the only limit on how many rows a session can accumulate is the
 * kernel send buffer (measured at 167 in the transport paragraph below) and
 * `drain()` hands the conversation up to `NOTICE_LIMIT` of them per tick. A
 * generation with N malformed invokes therefore puts N rows in the transcript,
 * each resent on every later turn. WHY THE COMPARISON STILL EARNS ITS PLACE:
 * the ARGUMENT is the same one — a transcript row is a recurring token cost,
 * not a line on a terminal — and it is why `MAX_CHARS` and the per-batch bound
 * exist at all. It is the SCOPE of the two caps that differs — and the scope
 * question E199 left open is now DECIDED: per TURN, not per session (round 69;
 * a session cap would let a long session stop surfacing anything, and "never"
 * was the status quo the entry was filed against). {@see beginTurn()} opens a
 * budget of {@see TURN_NOTICE_LIMIT} transcript rows; {@see drain()} enforces
 * it at the PARENT's read side only — children keep writing, because a child
 * cannot see a turn boundary — truncates the batch, appends
 * {@see OVERFLOW_FORMAT} ONCE, and discards everything else until the inbox
 * reads empty, so a saturated turn cannot leave {@see hasPending()} true and
 * keep Chat's tick repainting forever. The accounting is OPT-IN until the
 * drain-owner's call site lands: an unarmed drain behaves exactly as it
 * always did, which is the honest state of a process nobody has told where a
 * turn begins. Unlike the launch list, this inbox has no point at which it is
 * known to be complete — per-turn is the closest bound a live engine loop can
 * actually re-open.
 *
 * WHY IT IS STATIC, WHICH IS NOT LAZINESS. Two of the five emitter classes
 * E171 names are `final readonly`
 * ({@see \SugarCraft\Crush\Providers\ToolCallParser\DsmlToolCallParser},
 * {@see \SugarCraft\Crush\Providers\ToolCallParser\MinimaxXmlFallbackToolCallParser}),
 * so there is no per-instance accumulator to write into and no wither that
 * could hand one back. They are also constructed by
 * {@see \SugarCraft\Crush\Providers\ProviderFactory} several layers below
 * anything holding a `Chat`. A process-wide sink is what those two constraints
 * leave.
 *
 * THE FORK IS THE PART THAT MAKES THIS NON-TRIVIAL, AND IT IS MEASURED, NOT
 * ASSUMED. On the interactive path a turn does not run in this process:
 * {@see \SugarCraft\Crush\Backend\EngineBackend::completeAsync()} calls
 * `pcntl_fork()` and runs the whole engine loop — provider, tool-call parser,
 * tool dispatch — inside the child, whose only channel back is a one-way frame
 * socket. A plain static array would therefore accumulate rows in a process
 * that is about to `exit()`, and the parent would poll an empty queue forever:
 * a sink nothing drains, which is E171's own defect reproduced one level down.
 *
 * So the sink has two backends and picks between them by whether
 * {@see arm()} could create a TRANSPORT before the fork:
 *
 *  - TRANSPORT — an `AF_UNIX`/`SOCK_DGRAM` `stream_socket_pair()` created
 *    BEFORE any fork, so every child inherits the write end as an open fd and
 *    a `record()` in the child lands in the parent's read end. This is what
 *    every real interactive launch gets.
 *  - NO TRANSPORT — an in-process `list<string>`. Reached when
 *    `stream_socket_pair()` refuses (an fd-exhausted or `AF_UNIX`-less host)
 *    and, deliberately, when a caller asks for it with `arm(false)`: an
 *    embedder that drives {@see \SugarCraft\Crush\Chat} in one process has no
 *    fork to cross and no reason to hold two fds open for the session.
 *    KEPT RATHER THAN COLLAPSED INTO THE TRANSPORT (rule 6): it is the only
 *    backend on a host where the pair cannot be made, and
 *    {@see \SugarCraft\Crush\Tests\Diagnostics\RuntimeNoticeSinkTest} drives
 *    it directly rather than leaving it dormant and unexercised.
 *
 * IT MUST BE ARMED, AND AN UNARMED {@see record()} DROPS — WHICH IS THE WHOLE
 * FINDING APPLIED TO THIS CLASS ITSELF. A sink is only a seam if something
 * drains it, and the only thing that does is {@see \SugarCraft\Crush\Chat}'s
 * subscription tick. Processes that never build one are not exotic: the `-p`
 * one-shot does not — {@see \SugarCraft\Crush\Cli\NonInteractive} builds a
 * backend and calls `complete()` without ever reaching
 * {@see \SugarCraft\Crush\Cli\Bootstrap::chat()} — and neither do the
 * `sugarcrush mcp …` subcommands. Queueing there would accumulate rows in a
 * process about to exit, i.e. E171's own defect reproduced one level down, and
 * the `error_log()` copy is what those runs actually have.
 *
 * THAT IS MEASURED AND NOT AN ARGUMENT FROM TASTE. With `record()` ungated and
 * the two tool-call parsers routed here,
 * `vendor/bin/phpunit --filter '(BootstrapTest|DsmlToolCallParserTest|`
 * `MinimaxXmlFallbackToolCallParserTest|StatusLineSegmentTest|ChatTest|`
 * `AppModelTest)'` on PHP 8.3.6 went `Tests: 381, Failures: 2` — both in
 * `tests/Renderer/StatusLineSegmentTest`, a file that has never heard of this
 * class, because a parser test twenty classes earlier had left a row in a
 * process-wide static and `Chat::subscriptions()` correctly reported that
 * something was pending. A process-wide inbox with no reader is a bug whether
 * the process is a test runner or a `-p` run.
 *
 * WHY DATAGRAM AND NOT STREAM. A `SOCK_STREAM` pair would need a length prefix
 * and would interleave partial writes from concurrent children; `SOCK_DGRAM`
 * makes each write one indivisible message, which is exactly the framing this
 * needs and none of the code. MEASURED on PHP 8.3.6 / Linux 6.8 (this box has
 * only 8.3; CI also runs 8.4 and this is not asserted there): a pair created
 * before three `pcntl_fork()`s, four 212-byte writes per child, drains in the
 * parent as fourteen whole datagrams in write order with the parent's own
 * pre-fork and post-fork writes among them, read non-blocking via
 * `stream_socket_recvfrom()` until it returns `''`.
 *
 * TWO MEASURED FAILURE MODES, BOTH DELIBERATELY SILENT HERE BECAUSE THE
 * `error_log()` COPY ALREADY HAS THE TEXT:
 *
 *  1. The kernel send buffer fills when nobody drains. Same box: 167 datagrams
 *     of 500 bytes were accepted and the 168th `fwrite()` returned 0 with NO
 *     diagnostic and no block. That is the cap on this backend, and it is the
 *     kernel's rather than {@see NOTICE_LIMIT}'s.
 *  2. Writing after the read end is closed raises a PHP diagnostic —
 *     `fwrite(): Send of N bytes failed with errno=111 Connection refused`,
 *     measured on the same box. An unsuppressed diagnostic goes to fd 2, which
 *     is the precise corruption this class exists to stop, so the write is
 *     `@fwrite()` and its return value is the only signal read. That `@` is
 *     load-bearing; do not remove it as noise.
 */
final class RuntimeNoticeSink
{
    /**
     * The most characters one notice may contribute to the transcript.
     *
     * The same number and the same reasoning as
     * {@see \SugarCraft\Crush\Cli\Bootstrap::LAUNCH_NOTICE_MAX_CHARS}: these
     * messages interpolate values nothing bounds (a tool name and a parameter
     * name straight out of a model's generation, a `json_last_error_msg()`), so
     * a hostile or merely broken generation must not be able to spend the
     * session's context on one row. It is DELIBERATELY generous rather than
     * terse — the longest message routed here today is
     * `DsmlToolCallParser::parseDsml()`'s no-positioned-envelope diagnostic,
     * and it IS clipped, which is correct: its actionable half is its first
     * sentence and the stderr copy carries the rest.
     *
     * THAT LENGTH IS DERIVED, NOT WRITTEN DOWN HERE, and the previous revision
     * of this paragraph is why. WHAT IT SAID: "at 452 characters (MEASURED)".
     * WHAT IS TRUE NOW, re-measured on PHP 8.3.6 by running the parser and
     * counting what it wrote: 488. Nobody had re-run it, and a figure carried
     * in prose beside a message that anyone may reword is a figure that goes
     * stale silently. WHY THE SENTENCE STILL EARNS ITS PLACE: the ARGUMENT — a
     * real routed message exceeds this budget on purpose, and the clip is not a
     * theoretical branch — is the whole justification for the number below.
     * {@see \SugarCraft\Crush\Tests\Diagnostics\RuntimeNoticeSinkTest::testTheLongestNoticeThisParserActuallyEmitsIsMeasuredByEmittingIt()}
     * asserts both halves against the live parser, so the claim survives a
     * rewording and the digits do not have to.
     *
     * Also keeps a notice inside one datagram with margin to spare — the
     * margin is pinned live, not restated here; see
     * {@see \SugarCraft\Crush\Tests\Config\DocFigureProseDriftTest::testNoticeWorstCaseFitsOneDatagramWithPinnedMargin()}
     * and this class's doc-block on why the transport is `SOCK_DGRAM`.
     */
    public const MAX_CHARS = 400;

    /**
     * Appended to a clipped notice, and counted against {@see MAX_CHARS} so the
     * row never exceeds it.
     *
     * Says where the rest is, because a row that is silently short is worse
     * than a long one — the reader cannot tell a clipped diagnostic from a
     * complete one.
     */
    public const CLIP_SUFFIX = '… (clipped; full text on stderr)';

    /**
     * The most notices the IN-PROCESS backend will hold between drains.
     *
     * A CAP ON THE BACKEND NOTHING ELSE BOUNDED — until E199. The datagram
     * backend is capped by the kernel's send buffer (measured in this class's
     * doc-block); the array backend has no such ceiling, and the case that
     * needs one is real — a `-p` one-shot, or any embedder that never polls,
     * running a model that emits a malformed tool call on every step. Twenty
     * is above every plausible honest burst (the parsers emit at most one
     * notice per parameter of one call) and far below a number that would
     * matter. WHAT SINCE E199 BOUNDS BOTH BACKENDS ACROSS BATCHES is
     * {@see TURN_NOTICE_LIMIT}, enforced at the parent's {@see drain()}, so
     * this constant is now the per-BATCH bound it always was plus a
     * per-QUEUE bound on the array backend, and no longer the whole story on
     * either.
     */
    public const NOTICE_LIMIT = 20;

    /**
     * The most notice rows ONE TURN may add to the transcript (E199, decided
     * per-turn in round 69 — the session-scoped cap E199 asked for was judged
     * wrong for a long-lived TUI, which would end up surfacing nothing).
     *
     * ENFORCED AT THE DRAIN, NOT THE RECORD. The rows cross a fork on the
     * interactive path, so a parent-side queue count would miss every child's
     * contribution; the drain is the one place the whole batch is visible in
     * one process. Children keep writing (their text is in `error_log()`
     * whatever this cap does); a drained-past-budget batch is truncated, gets
     * one {@see OVERFLOW_FORMAT} row for the turn, and the rest of the inbox
     * is DISCARDED — not deferred, because a transport that stays readable
     * keeps Chat's notice subscription firing on an empty payload.
     *
     * THE NUMBER is the same budget the per-batch cap already argues is
     * "above every plausible honest burst", now spent across a whole turn:
     * twenty rows of up to {@see MAX_CHARS} each, every one of them resent to
     * the model on every later turn, is already a wall no healthy generation
     * fills — and the row that truncates is the signal, not a loss: the
     * complete text is on stderr by construction.
     *
     * ACTIVE ONLY once a drain owner calls {@see beginTurn()}; until then
     * {@see drain()} bounds per batch exactly as it did before E199.
     */
    public const TURN_NOTICE_LIMIT = 20;

    /**
     * How the "and N more" tail row is spelled. `%d` the dropped count, `%s`
     * the plural.
     *
     * Synthesised at {@see drain()} rather than stored, for the reason
     * {@see \SugarCraft\Crush\Cli\Bootstrap::launchNotices()} gives: a marker
     * occupying a slot in the list would make the cap dishonest and a second
     * overflow would have to rewrite it.
     *
     * TWO OVERFLOWS SPELL THEIR TAIL WITH THIS ONE FORMAT (E199): the
     * in-process backend's per-batch refusals above {@see NOTICE_LIMIT}, and
     * the turn truncation at {@see TURN_NOTICE_LIMIT}. "this session" stays true
     * for the second — the dropped rows are gone from the session's surface,
     * not parked for a later one — and a format constant each lane had to
     * invent would put two spellings of the same sentence in the transcript.
     */
    public const OVERFLOW_FORMAT = '… and %d more runtime notice%s this session; see stderr for the full text.';

    /**
     * Read size for one datagram.
     *
     * A datagram longer than this would be TRUNCATED rather than queued, so it
     * is deliberately above {@see MAX_CHARS}' worst case with margin to spare.
     * No fixed multiplier is written here on purpose: the margin is two
     * constants deep (worst-case UTF-8 width plus the overflow suffix) and it
     * is recomputed from the live constants on every test run by
     * {@see \SugarCraft\Crush\Tests\Config\DocFigureProseDriftTest::testNoticeWorstCaseFitsOneDatagramWithPinnedMargin()}.
     * The sentence this replaces claimed a ten-times-plus margin
     * over the very worst case its own parentheses spelled (under 1,700
     * bytes against 8,192 — under five times), which is E633's exact shape
     * and was caught by mutating the sentence, not the arithmetic.
     */
    private const DATAGRAM_BYTES = 8192;

    /**
     * Whether {@see arm()} has opened the inbox in this process.
     *
     * FALSE IS THE DEFAULT AND IT MEANS "DROP", not "queue for later". See this
     * class's doc-block: a notice recorded in a process that will never build a
     * {@see \SugarCraft\Crush\Chat} has no reader, and the `error_log()` half
     * of {@see warn()} is what that run gets.
     */
    private static bool $armed = false;

    /** @var list<string> The in-process backend. See this class's doc-block. */
    private static array $queue = [];

    /** Notices the in-process backend refused because {@see NOTICE_LIMIT} was reached. */
    private static int $dropped = 0;

    /**
     * Whether the per-turn budget (E199) is armed — true from the first
     * {@see beginTurn()} until {@see reset()}.
     *
     * OPT-IN, AND THAT IS THE POINT: until a drain owner says where its turns
     * begin, {@see drain()} must not start swallowing rows from callers whose
     * contract predates the cap.
     */
    private static bool $turnAccounting = false;

    /** Notice rows surfaced to the transcript since {@see beginTurn()}, overflow row excluded. */
    private static int $turnSurfaced = 0;

    /** Whether this turn has already had its single {@see OVERFLOW_FORMAT} row. */
    private static bool $turnOverflowAnnounced = false;

    /** @var resource|null The read end of the transport, owned by this process. */
    private static $transportRead = null;

    /** @var resource|null The write end, inherited by every child forked after {@see arm()}. */
    private static $transportWrite = null;

    /**
     * Removes the readable-watcher {@see notifyOnceWhenPending()} installed, or
     * null when none is installed.
     *
     * A CLOSURE AND NOT A BOOL, so the fd and the loop it was registered on
     * travel with the canceller. {@see reset()} must be able to take the
     * watcher off the loop BEFORE it closes the stream, and a `removeReadStream`
     * against `self::$transportRead` read at cancel time would be reaching for a
     * property the caller may already have nulled.
     */
    private static ?\Closure $pendingWatcher = null;

    /**
     * Report a warning on BOTH channels: `error_log()` for the complete record,
     * and the transcript inbox for the surface an interactive user actually
     * has.
     *
     * THE ONE ENTRY POINT CALL SITES SHOULD USE. {@see record()} exists for the
     * launch-time callers that have already written their own stderr line
     * through a different envelope, and for tests; a subsystem that just wants
     * to be heard wants this.
     *
     * ORDER IS DELIBERATE: stderr first. The `error_log()` copy is the one that
     * must survive, and a sink whose transport has gone away (see the
     * `errno=111` case in this class's doc-block) must not be able to take the
     * forensic record down with it.
     *
     * WHAT IS PINNED AND WHAT IS NOT, measured rather than assumed, because a
     * round-47 review read this paragraph as claiming a property nothing held
     * and only half of that was right. MEASURED by mutation, PHP 8.3.6:
     *   - Making the stderr copy CONDITIONAL on `record()` succeeding —
     *     `if (self::record($m)) { error_log($m); }` — is KILLED, three
     *     failures. So the CONSEQUENCE of the ordering, the one this paragraph
     *     is actually about, is held: the forensic copy is unconditional and
     *     survives an unarmed, full or torn-down sink.
     *   - Swapping the two statements outright SURVIVES. So the literal
     *     ORDER is not pinned, and deliberately is not: the only way the swap
     *     could matter is `record()` throwing before `error_log()` ran, and
     *     `record()` has no throwing path — every failure mode it has returns
     *     `false`. A test for it would need a seam invented purely to be
     *     broken, which is a worse guard than none.
     * WHY THE SENTENCE STILL EARNS ITS PLACE: it is the reason the order is
     * not to be "tidied" if `record()` ever DOES grow a throwing path — at
     * which point the swap becomes observable and gets a test.
     */
    public static function warn(string $message): void
    {
        error_log($message);
        self::record($message);
    }

    /**
     * Put a notice in the inbox WITHOUT touching stderr.
     *
     * @return bool Whether the notice was accepted. False means it was dropped
     *              — the in-process backend was full, or the transport refused
     *              the write. Callers are not expected to act on it; it is
     *              returned so a test can distinguish "accepted" from "silently
     *              lost", which is the distinction this whole class is about.
     */
    public static function record(string $message): bool
    {
        if (!self::$armed) {
            // NOT AN OPTIMISATION AND NOT A GUARD AGAINST MISUSE. Nothing in
            // this process will ever drain the inbox, so a row put in it is a
            // row lost with more steps — see this class's doc-block, which
            // measures what happens when this returns true anyway.
            return false;
        }

        $notice = self::clip(trim($message));

        if ($notice === '') {
            return false;
        }

        if (self::$transportWrite !== null) {
            // `@` is load-bearing, not noise — see this class's doc-block on
            // the measured `errno=111` diagnostic. A datagram is all-or-nothing,
            // so a short write is impossible and there is no resume loop.
            $written = @fwrite(self::$transportWrite, $notice);

            return $written !== false && $written > 0;
        }

        if (count(self::$queue) >= self::NOTICE_LIMIT) {
            self::$dropped++;

            return false;
        }

        self::$queue[] = $notice;

        return true;
    }

    /**
     * Whether a poll would find anything, WITHOUT consuming it.
     *
     * Exists so {@see \SugarCraft\Crush\Chat::subscriptions()} can decide
     * whether to declare its tick at all. A subscription declared
     * unconditionally would keep a timer waking the event loop and repainting
     * forever on every launch — the objection that method's doc-block already
     * raises against its other two ticks — and this is what makes the third one
     * conditional on the same terms.
     *
     * On the transport backend this is one `stream_select()` with a zero
     * timeout, which is a syscall and no allocation. It is called once per
     * `Program` reconcile, i.e. once per `Msg`, not on a timer.
     */
    public static function hasPending(): bool
    {
        if (self::$queue !== [] || self::$dropped > 0) {
            return true;
        }

        if (self::$transportRead === null) {
            return false;
        }

        $read = [self::$transportRead];
        $write = null;
        $except = null;

        return @stream_select($read, $write, $except, 0, 0) > 0;
    }

    /**
     * Take everything pending and clear it.
     *
     * DE-DUPLICATED WITHIN THE BATCH, AND ONLY WITHIN IT. A parser walking a
     * malformed invoke can emit the identical "duplicate parameter" line once
     * per repeated parameter, and thirty identical transcript rows is a wall a
     * user scrolls past rather than a warning. Across batches nothing is
     * de-duplicated, on purpose: the same warning on turn 1 and on turn 50 is
     * two events, and collapsing them would tell the user the second turn was
     * clean.
     *
     * @return list<string> in the order recorded, first occurrence kept
     */
    public static function drain(): array
    {
        if (self::$turnOverflowAnnounced) {
            // E199: this turn already got its overflow row. Everything after
            // it is DISCARDED at the read, in this call — not deferred —
            // because a transport left readable keeps hasPending() true and
            // Chat's notice subscription firing on rows that will never
            // surface. The turn's complete record is on stderr by
            // construction; the budget protects the transcript, not the log.
            self::discardPendingNotices();

            return [];
        }

        $notices = self::$queue;
        self::$queue = [];
        $dropped = self::$dropped;
        self::$dropped = 0;

        if (self::$transportRead !== null) {
            // Bounded by NOTICE_LIMIT per drain rather than "until empty": a
            // child in a loop could otherwise hand one update() an unbounded
            // batch, and the tick that follows will pick the rest up. The
            // socket keeps them in the meantime — it is the queue.
            // (The one exception is E199's truncation path below, where the
            // rest is deliberately NOT picked up.)
            for ($i = 0; $i < self::NOTICE_LIMIT; $i++) {
                $datagram = @stream_socket_recvfrom(self::$transportRead, self::DATAGRAM_BYTES);
                if ($datagram === false || $datagram === '') {
                    break;
                }
                $notices[] = $datagram;
            }
        }

        $unique = [];
        foreach ($notices as $notice) {
            if (!in_array($notice, $unique, true)) {
                $unique[] = $notice;
            }
        }

        if (self::$turnAccounting) {
            $remaining = self::TURN_NOTICE_LIMIT - self::$turnSurfaced;

            if (count($unique) > $remaining) {
                $excess = count($unique) - max(0, $remaining);
                $unique = $remaining > 0 ? array_slice($unique, 0, $remaining) : [];
                self::$turnSurfaced += count($unique);
                self::$turnOverflowAnnounced = true;

                // ONE row for everything the turn will not surface: this
                // batch's unique excess, the transport's unread remainder,
                // and any array-backend refusals taken with it. Within-batch
                // duplicates are not counted — they were never rows, which is
                // what the de-duplication paragraph above has always meant.
                // Not a second row per later drain — the budget is announced,
                // then it holds.
                $discarded = $excess + self::discardPendingNotices() + $dropped;
                $unique[] = sprintf(
                    self::OVERFLOW_FORMAT,
                    $discarded,
                    $discarded === 1 ? '' : 's',
                );

                return $unique;
            }

            self::$turnSurfaced += count($unique);
        }

        if ($dropped > 0) {
            $unique[] = sprintf(self::OVERFLOW_FORMAT, $dropped, $dropped === 1 ? '' : 's');
        }

        return $unique;
    }

    /**
     * Open a per-turn notice budget of {@see TURN_NOTICE_LIMIT} transcript
     * rows (E199).
     *
     * THE DRAIN OWNER CALLS IT, NOT THE EMITTERS. Children write without
     * knowing where a turn begins; the cap is therefore counted at the one
     * parent-side point every row passes — {@see drain()} — and only from a
     * call site that owns the turn boundary. Until such a caller exists the
     * cap is armed by nothing, which is what keeps an unbudgeted host
     * behaving exactly as it did before E199 (see {@see $turnAccounting}).
     * {@see reset()} takes the arming back off.
     *
     * IDEMPOTENT WITHIN A CALL, and re-calling it per turn is the intended
     * rhythm: each call re-opens the budget and re-allows the single overflow
     * row.
     */
    public static function beginTurn(): void
    {
        self::$turnAccounting = true;
        self::$turnSurfaced = 0;
        self::$turnOverflowAnnounced = false;
    }

    /**
     * Take everything still waiting and throw it away; return how many rows
     * went (E199).
     *
     * Reads the transport DRY, not just up to a bound: this runs from the
     * saturation paths, where the point is precisely that `hasPending()` must
     * go false afterwards or Chat repaints on a payload nobody will show.
     */
    private static function discardPendingNotices(): int
    {
        $discarded = count(self::$queue) + self::$dropped;
        self::$queue = [];
        self::$dropped = 0;

        if (self::$transportRead !== null) {
            while (true) {
                $datagram = @stream_socket_recvfrom(self::$transportRead, self::DATAGRAM_BYTES);
                if ($datagram === false || $datagram === '') {
                    break;
                }
                $discarded++;
            }
        }

        return $discarded;
    }

    /**
     * Create the cross-fork transport, so notices raised inside
     * {@see \SugarCraft\Crush\Backend\EngineBackend::completeAsync()}'s child
     * reach the parent's transcript instead of dying with it.
     *
     * MUST BE CALLED BEFORE THE FIRST FORK, which is why the call site is
     * {@see \SugarCraft\Crush\Cli\Bootstrap::chat()} — a turn cannot start
     * before the `Chat` that runs it exists. Nothing enforces the ordering at
     * runtime because nothing can: a child that inherited no fd is
     * indistinguishable from one whose parent never installed a transport, and
     * both degrade to "the notice is on stderr only", which is where every one
     * of them was before this class.
     *
     * IDEMPOTENT. A second `chat()` in one process (the second-scan path, a
     * test) keeps the first transport rather than orphaning a pair of fds.
     *
     * ARMING AND CREATING THE TRANSPORT ARE ONE CALL ON PURPOSE. Two entry
     * points would let a caller open the inbox without a way across the fork,
     * or a transport with the inbox still dropping — two states with no
     * meaning, both silent.
     *
     * @param bool $crossFork Whether to create the cross-fork transport. False
     *                        selects the in-process backend deliberately, for
     *                        an embedder that drives a `Chat` in one process
     *                        and has no fork for a notice to cross.
     *
     * @return bool whether the transport exists. False means the sink is armed
     *              on the in-process backend — because the caller asked for it,
     *              or because the pair could not be created — and a notice
     *              raised inside a forked child will then reach stderr only,
     *              which is where every one of them was before this class
     */
    public static function arm(bool $crossFork = true): bool
    {
        if (self::$armed) {
            return self::$transportWrite !== null;
        }

        self::$armed = true;

        if (!$crossFork) {
            return false;
        }

        $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_DGRAM, 0);

        if ($pair === false) {
            return false;
        }

        [self::$transportRead, self::$transportWrite] = $pair;
        stream_set_blocking(self::$transportRead, false);
        // Non-blocking on the WRITE end is the half that matters: a child that
        // blocked here would stall the turn behind a diagnostic nobody is
        // reading. Overflow degrades to a dropped datagram — measured at 167
        // in this class's doc-block — and the stderr copy still has the text.
        stream_set_blocking(self::$transportWrite, false);

        return true;
    }

    /** Whether {@see arm()} has run in this process, on either backend. */
    public static function isArmed(): bool
    {
        return self::$armed;
    }

    /** Whether {@see arm()} got the cross-fork transport rather than the array. */
    public static function hasTransport(): bool
    {
        return self::$transportWrite !== null;
    }

    /**
     * Call `$notify` once, on the event loop, the next time a notice arrives
     * on the cross-fork transport (E193).
     *
     * WHY THIS EXISTS AT ALL, AND WHY {@see hasPending()} IS NOT ENOUGH.
     * {@see \SugarCraft\Crush\Chat::subscriptions()} declares its poll on
     * `$inFlight || hasPending()`, and `hasPending()` is only ever consulted
     * when `\SugarCraft\Core\Program` reconciles — which it does after
     * `init()` and after every dispatched `Msg`, and at no other time
     * (`Program::reconcileWantedSubscriptions()` has exactly those two
     * callers). So a notice that becomes pending while the UI is IDLE — no
     * turn in flight, nothing else armed — arms nothing, and goes on arming
     * nothing until some unrelated `Msg` happens to arrive. In practice that
     * is the next key the user presses.
     *
     * MEASURED, PHP 8.3.6 / `StreamSelectLoop`, on a real `Program` running a
     * real `Chat`: a child forked before `run()` recorded one notice 0.3s in;
     * after 2.0s of loop time the transcript had ZERO `Role::System` rows and
     * `hasPending()` was still true. The same probe with `inFlight: true`
     * delivered the row, and with the notice recorded before `run()` delivered
     * both — so the harness was not blind, the idle case simply never wakes.
     * {@see \SugarCraft\Crush\Tests\Diagnostics\RuntimeNoticeSinkDeliveryTest}
     * carries all three as tests.
     *
     * WHY A READ WATCHER AND NOT AN UNCONDITIONAL TICK. That is the fix
     * `Chat::subscriptions()`' doc-block rules out three separate times, in
     * the same words each time: a timer that wakes the loop and repaints
     * forever on the overwhelmingly common launch where nothing ever warns.
     * The objection is about the repaint, and it is correct —
     * `Program::dispatch()` marks the frame dirty for every `Msg`, so an
     * unconditional pump tick is an unconditional repaint at its interval. A
     * readable-watcher costs one fd in the loop's select set and produces a
     * `Msg` only when a datagram actually lands, which is strictly better than
     * the tick on both axes: no idle wake-ups at all, and no poll latency when
     * one does arrive.
     *
     * ONE-SHOT, AND IT CANCELS ITSELF BEFORE IT NOTIFIES. The caller re-arms
     * from its own `update()`, which is what keeps the arming decision in the
     * model rather than in this class — a `Chat` that stopped being the
     * process's drain owner must be able to stop listening, and a second
     * `notifyOnceWhenPending()` replaces the first rather than stacking a
     * second watcher on one fd.
     *
     * NO SPIN, and the reason is a property of the transport rather than a
     * guard. `stream_select()` reports the read end readable only when at
     * least one whole datagram is queued ({@see arm()} makes a `SOCK_DGRAM`
     * pair), `record()` refuses an empty message before it ever writes, and
     * this process holds its own copy of the write end open for the life of
     * the sink — so a readable fd always yields something for {@see drain()}
     * to take. The one shape that WOULD spin is a readable fd whose peer has
     * hung up, and the only code that closes the write end is {@see reset()},
     * which cancels this watcher first.
     *
     * @param \Closure(): void $notify run on the loop when a datagram lands
     *
     * @return bool whether a watcher was installed. False means there is no
     *              cross-fork transport — the in-process backend can only be
     *              written by this process, synchronously, and every such write
     *              is already followed by a reconcile
     */
    public static function notifyOnceWhenPending(\Closure $notify): bool
    {
        if (self::$transportRead === null) {
            return false;
        }

        self::cancelPendingNotification();

        $loop = Loop::get();
        $stream = self::$transportRead;

        $loop->addReadStream($stream, static function () use ($notify): void {
            self::cancelPendingNotification();
            $notify();
        });

        self::$pendingWatcher = static function () use ($loop, $stream): void {
            $loop->removeReadStream($stream);
        };

        return true;
    }

    /**
     * Take any {@see notifyOnceWhenPending()} watcher back off the loop.
     *
     * IDEMPOTENT, and it nulls the property BEFORE running the canceller: the
     * watcher's own callback calls this, so a canceller that re-entered would
     * otherwise see itself still installed.
     */
    public static function cancelPendingNotification(): void
    {
        $canceller = self::$pendingWatcher;
        self::$pendingWatcher = null;

        if ($canceller !== null) {
            $canceller();
        }
    }

    /** Whether a {@see notifyOnceWhenPending()} watcher is installed. */
    public static function isNotificationArmed(): bool
    {
        return self::$pendingWatcher !== null;
    }

    /**
     * Drop everything, including the transport.
     *
     * DISARMS TOO, so a reset sink is a dropping sink until something arms it
     * again. That is the same statement {@see $armed} makes and not a second
     * policy: a process that has torn the inbox down has no reader either.
     *
     * TWO CALLERS, AND THE CLAIM THAT THERE IS A THIRD WAS WRONG. This
     * paragraph said "for tests, and for
     * `\SugarCraft\Crush\Cli\Bootstrap::resetForTests()`". WHAT IS TRUE NOW,
     * checked rather than assumed: `Bootstrap` has no `resetForTests()` and
     * never had one — `grep -rn resetForTests src/` finds only that sentence.
     * WHY THE PARAGRAPH STILL EARNS ITS PLACE: the reason it gave is the real
     * one. A static that leaks between test cases makes one test's warning
     * another test's assertion (MEASURED — see this class's doc-block, which
     * names the two `StatusLineSegmentTest` cases that fell to exactly that),
     * and a socket pair that leaks per case exhausts the fd table of a
     * 9000-test run. The live callers are this suite's `setUp`/`tearDown` and
     * {@see \SugarCraft\Crush\Cli\Bootstrap::chat()}, which resets before it
     * arms so a second `chat()` in one process starts from an empty inbox.
     */
    public static function reset(): void
    {
        // BEFORE THE fclose() BELOW, not after. A watcher left on the loop over
        // a closed stream is a resource `stream_select()` will be handed on
        // every iteration for the rest of the process — and in a 9000-test run
        // that is every later test's loop, not just this one's.
        self::cancelPendingNotification();

        self::$armed = false;
        self::$queue = [];
        self::$dropped = 0;

        // The per-turn budget (E199) is torn down with the inbox: a fresh
        // arm starts as unbudgeted as a process that never heard of the cap,
        // and the drain owner re-arms it with its next beginTurn().
        self::$turnAccounting = false;
        self::$turnSurfaced = 0;
        self::$turnOverflowAnnounced = false;

        foreach ([self::$transportRead, self::$transportWrite] as $handle) {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        self::$transportRead = null;
        self::$transportWrite = null;
    }

    /**
     * Clip to {@see MAX_CHARS}, counting the suffix against the budget.
     *
     * `mb_*` and not `substr()`, for the reason
     * {@see \SugarCraft\Crush\Cli\Bootstrap::warnPermissionConfigInTranscript()}
     * gives: these messages interpolate tool and parameter names straight out
     * of a model's generation, and a cut mid-codepoint hands the transcript a
     * row that is not valid UTF-8 — which `json_encode()`, and therefore the
     * session store and the `-p` document, refuses outright rather than
     * degrading.
     */
    private static function clip(string $message): string
    {
        if (mb_strlen($message, 'UTF-8') <= self::MAX_CHARS) {
            return $message;
        }

        return mb_substr(
            $message,
            0,
            self::MAX_CHARS - mb_strlen(self::CLIP_SUFFIX, 'UTF-8'),
            'UTF-8',
        ) . self::CLIP_SUFFIX;
    }
}
