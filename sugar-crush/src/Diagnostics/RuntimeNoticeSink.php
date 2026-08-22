<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Diagnostics;

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
 * transcript copy is clipped and capped because those rows are part of the
 * CONVERSATION — sent to the model on every subsequent turn — which is the
 * same argument
 * {@see \SugarCraft\Crush\Cli\Bootstrap::LAUNCH_NOTICE_LIMIT} makes for the
 * launch list.
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
     * Also keeps a notice comfortably inside one datagram — see this class's
     * doc-block on why the transport is `SOCK_DGRAM`.
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
     * A CAP ON THE BACKEND NOTHING ELSE BOUNDS. The datagram backend is capped
     * by the kernel's send buffer (measured in this class's doc-block); the
     * array backend has no such ceiling, and the case that needs one is real —
     * a `-p` one-shot, or any embedder that never polls, running a model that
     * emits a malformed tool call on every step. Twenty is above every
     * plausible honest burst (the parsers emit at most one notice per
     * parameter of one call) and far below a number that would matter.
     */
    public const NOTICE_LIMIT = 20;

    /**
     * How the "and N more" tail row is spelled. `%d` the dropped count, `%s`
     * the plural.
     *
     * Synthesised at {@see drain()} rather than stored, for the reason
     * {@see \SugarCraft\Crush\Cli\Bootstrap::launchNotices()} gives: a marker
     * occupying a slot in the list would make the cap dishonest and a second
     * overflow would have to rewrite it.
     */
    public const OVERFLOW_FORMAT = '… and %d more runtime notice%s this session; see stderr for the full text.';

    /**
     * Read size for one datagram.
     *
     * A datagram longer than this would be TRUNCATED rather than queued, so it
     * is deliberately an order of magnitude above {@see MAX_CHARS}' worst case
     * (400 characters of 4-byte UTF-8 plus the suffix is under 1700 bytes).
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

    /** @var resource|null The read end of the transport, owned by this process. */
    private static $transportRead = null;

    /** @var resource|null The write end, inherited by every child forked after {@see arm()}. */
    private static $transportWrite = null;

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
        $notices = self::$queue;
        self::$queue = [];
        $dropped = self::$dropped;
        self::$dropped = 0;

        if (self::$transportRead !== null) {
            // Bounded by NOTICE_LIMIT per drain rather than "until empty": a
            // child in a loop could otherwise hand one update() an unbounded
            // batch, and the tick that follows will pick the rest up. The
            // socket keeps them in the meantime — it is the queue.
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

        if ($dropped > 0) {
            $unique[] = sprintf(self::OVERFLOW_FORMAT, $dropped, $dropped === 1 ? '' : 's');
        }

        return $unique;
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
        self::$armed = false;
        self::$queue = [];
        self::$dropped = 0;

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
