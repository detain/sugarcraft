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
 * makes for the launch list. In the TUI that record lands in
 * {@see TuiErrorLog}'s private file rather than on the tty; the one case
 * where {@see warn()} writes only the transcript copy is spelled out there.
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
     * keep Chat's tick repainting forever. The accounting remains OPT-IN —
     * armed by {@see beginTurn()} and nothing else — and since round 70 the
     * call site exists: {@see \SugarCraft\Crush\Chat::scheduleBackendCompletion()}
     * opens a budget on every dispatch, gated on that Chat being the appointed
     * drain owner. An unarmed drain still behaves exactly as it always did,
     * which is the honest state of every process nobody has told where a turn
     * begins: the `-p` one-shot, the `sugarcrush mcp …` subcommands, and every
     * embedder that never appoints an owner. Unlike the launch list, this inbox
     * has no point at which it is known to be complete — per-turn is the
     * closest bound a live engine loop can actually re-open.
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
 * STATIC SURFACE, PER-SESSION STATE (O-2a, Appendix O §4.2). Those two
 * constraints fix where the EMITTERS write — a static entry point — and say
 * nothing about where the rows are KEPT, and a process-global queue is the one
 * thing a host running two sessions cannot have: concurrent turns would write
 * into one inbox and whichever session drained first would show the other's
 * warnings. So the inbox is now an instance, {@see NoticeSink}, and this class
 * is a facade over the one it calls {@see current()}: the process's own sink
 * ({@see process()}) unless a host routed elsewhere with {@see routeTo()} or
 * {@see using()}. A turn child pins the sink that was current when it was
 * forked ({@see enterForkedChild()}), so a parser deep inside that turn writes
 * to the session that started it with no change at any call site. A TUI never
 * routes, so for `bin/sugarcrush` every sentence below about "the sink" is
 * about the process sink, exactly as before.
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
     * sentence and the `error_log()` copy carries the rest.
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
     * row never exceeds it. `%s` is {@see fullTextPhrase()}.
     *
     * Says where the rest is, because a row that is silently short is worse
     * than a long one — the reader cannot tell a clipped diagnostic from a
     * complete one.
     *
     * WHERE, NOT "STDERR" (audit C4). WHAT THIS SAID: a fixed
     * `… (clipped; full text on stderr)`. That was written before
     * {@see TuiErrorLog}: in the TUI — the only process with a transcript to
     * clip into — the full text goes to `~/.sugar-crush/logs/sugarcrush.log`
     * (or the R16 fallback), and stderr has nothing. The destination is read
     * from the live `error_log` ini at clip time, which is right in the forked
     * turn child too: it inherits the ini and the transport.
     */
    public const CLIP_SUFFIX_FORMAT = '… (clipped; %s)';

    /**
     * {@see fullTextPhrase()}'s spelling for a destination that can be read
     * back, `%s` from {@see TuiErrorLog::describeDestination()}.
     */
    public const FULL_TEXT_AT_FORMAT = 'full text %s %s';

    /**
     * {@see fullTextPhrase()}'s spelling when the complete text went nowhere
     * a reader can open: the R16 null-device fallback, or {@see warn()}'s
     * C2a skip.
     */
    public const FULL_TEXT_NOT_KEPT = 'full text not kept';

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
     * complete text is in the `error_log()` record by construction (the TUI's
     * log file, stderr elsewhere — the row says which, audit C4).
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
     * the turn truncation at {@see TURN_NOTICE_LIMIT}. "this session" in the
     * spelled sentence describes the DROP, not the budget's scope: the budget
     * is per turn (round 69's decision, wired in round 70), while the rows a
     * truncation discards are gone from this session's transcript for good —
     * the next {@see beginTurn()} re-opens a budget, not those rows. And a
     * format constant each lane had to invent would put two spellings of the
     * same sentence in the transcript.
     *
     * The third `%s` is {@see fullTextPhrase()} — the audit C4 fix that
     * {@see CLIP_SUFFIX_FORMAT} explains; build the row with
     * {@see overflowNotice()}.
     */
    public const OVERFLOW_FORMAT = '… and %d more runtime notice%s this session; %s.';

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
    public const DATAGRAM_BYTES = 8192;

    /**
     * The process's own sink — the one a TUI launch arms and every emitter
     * reaches when nothing more specific is current. Created on first use and
     * never replaced: {@see reset()} empties it in place, so a
     * {@see \SugarCraft\Crush\Host\WorkspaceContext} that captured it keeps
     * pointing at the live inbox across a second `Bootstrap::chat()`.
     *
     * UNARMED UNTIL SOMETHING ARMS IT, AND UNARMED MEANS "DROP", not "queue for
     * later" — see this class's doc-block: a notice recorded in a process that
     * will never build a {@see \SugarCraft\Crush\Chat} has no reader, and the
     * `error_log()` half of {@see warn()} is what that run gets.
     */
    private static ?NoticeSink $process = null;

    /**
     * The sink this process routes to instead of {@see $process}, or null for
     * the process sink (O-2a). Set by {@see routeTo()} / {@see using()} in a host
     * that runs several sessions, and pinned in a forked turn child by
     * {@see enterForkedChild()}.
     */
    private static ?NoticeSink $current = null;

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
     *
     * ONE CASE NOW SKIPS THE COPY (audit C2a). WHAT THIS USED TO SAY: the
     * `error_log()` copy is unconditional. WHAT IS TRUE NOW: it is skipped
     * exactly when the sink is armed WITH the cross-fork transport — which
     * only an interactive launch arms — AND `error_log` still resolves to
     * stderr ({@see TuiErrorLog::destinationIsStderr()}). In that process fd 2
     * is the tty the renderer owns, so the "forensic" copy was a raw line
     * painted over the frame, not a record anyone could keep. The TUI normally
     * never reaches this branch: `bin/sugarcrush` installs
     * {@see TuiErrorLog} before `Program::run()`, the ini then names a file,
     * and the complete, unclipped record goes there — including from the
     * forked turn child, which inherits the ini. Since audit R16 that
     * redirect cannot leave the ini on stderr either — no owned home, an
     * unwritable log directory, falls through to a private temp-dir file and
     * finally the null device — so in `bin/sugarcrush` this branch is now
     * unreachable; it stays as the guard for an embedder that arms the
     * transport without installing the redirect. Its cost is stated rather
     * than hidden: there, a clipped row's tail and a dropped datagram's text
     * are lost instead of smeared across the screen, and the rows say "full
     * text not kept" rather than send the reader to stderr
     * ({@see fullTextPhrase()}). The unarmed sink (`-p`, the
     * subcommands), the in-process backend and every non-stderr destination
     * keep the copy, so the unarmed, full and torn-down cases the mutation
     * result above pins are unchanged; both sides of the new rule are pinned
     * on a real fd 2 by
     * {@see \SugarCraft\Crush\Tests\Diagnostics\RuntimeNoticeSinkStderrTest}.
     */
    public static function warn(string $message): void
    {
        $sink = self::current();
        if (!($sink->hasTransport() && TuiErrorLog::destinationIsStderr(ini_get('error_log')))) {
            error_log($message);
        }
        $sink->record($message);
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
        // An unarmed sink drops — NOT AN OPTIMISATION AND NOT A GUARD AGAINST
        // MISUSE: nothing in this process will ever drain it, so a row put in
        // it is a row lost with more steps. See this class's doc-block, which
        // measures what happens when this returns true anyway.
        return self::current()->record($message);
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
        return self::current()->hasPending();
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
        return self::current()->drain();
    }

    /**
     * Open a per-turn notice budget of {@see TURN_NOTICE_LIMIT} transcript
     * rows (E199).
     *
     * THE DRAIN OWNER CALLS IT, NOT THE EMITTERS. Children write without
     * knowing where a turn begins; the cap is therefore counted at the one
     * parent-side point every row passes — {@see drain()} — and only from a
     * call site that owns the turn boundary. Since round 70 that call site
     * exists: {@see \SugarCraft\Crush\Chat::scheduleBackendCompletion()},
     * gated on the Chat's appointment flag, so a host with no appointed owner
     * keeps the pre-E199 per-batch shape (the budget is opt-in per sink).
     * {@see reset()} takes the arming back off.
     *
     * IDEMPOTENT WITHIN A CALL, and re-calling it per turn is the intended
     * rhythm: each call re-opens the budget and re-allows the single overflow
     * row.
     */
    public static function beginTurn(): void
    {
        self::current()->beginTurn();
    }

    /**
     * Create the cross-fork transport, so notices raised inside
     * {@see \SugarCraft\Crush\Backend\EngineBackend::completeAsync()}'s child
     * reach the parent's transcript instead of dying with it.
     *
     * MUST BE CALLED BEFORE THE FIRST FORK, which is why the call site is
     * {@see \SugarCraft\Crush\Cli\Bootstrap::workspace()}, the non-UI half of
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
        return self::current()->arm($crossFork);
    }

    /** Whether {@see arm()} has run in this process, on either backend. */
    public static function isArmed(): bool
    {
        return self::current()->isArmed();
    }

    /** Whether {@see arm()} got the cross-fork transport rather than the array. */
    public static function hasTransport(): bool
    {
        return self::current()->hasTransport();
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
        return self::current()->notifyOnceWhenPending($notify);
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
        self::current()->cancelPendingNotification();
    }

    /** Whether a {@see notifyOnceWhenPending()} watcher is installed. */
    public static function isNotificationArmed(): bool
    {
        return self::current()->isNotificationArmed();
    }

    /**
     * Drop everything — queue, transport, and the E199 turn budget — so a
     * fresh arm starts as unbudgeted as a process that never heard of the cap.
     *
     * DISARMS TOO, so a reset sink is a dropping sink until something arms it
     * again. That is the same statement an unarmed {@see NoticeSink} makes and
     * not a second policy: a process that has torn the inbox down has no reader either.
     *
     * THE CALLERS — AND THE PHANTOM THIRD THAT USED TO BE CLAIMED. This
     * paragraph said "for tests, and for
     * `\SugarCraft\Crush\Cli\Bootstrap::resetForTests()`". WHAT IS TRUE NOW,
     * checked rather than assumed: `Bootstrap` has no `resetForTests()` and
     * never had one — `grep -rn resetForTests src/` finds only that sentence.
     * WHY THE PARAGRAPH STILL EARNS ITS PLACE: the reason it gave is the real
     * one. A static that leaks between test cases makes one test's warning
     * another test's assertion (MEASURED — see this class's doc-block, which
     * names the two `StatusLineSegmentTest` cases that fell to exactly that),
     * and a socket pair that leaks per case exhausts the fd table of a
     * 9000-test run. The live callers since E194 are
     * {@see \SugarCraft\Crush\Tests\Support\RuntimeNoticeSinkResetExtension},
     * registered from `phpunit.xml`, which resets before EVERY test rather
     * than trusting each suite's good behaviour — the explicit resets in the
     * sink's own `setUp`/`tearDown`, which the extension makes redundant but
     * which the files keep as a per-class statement of intent — and
     * {@see \SugarCraft\Crush\Cli\Bootstrap::workspace()} (the non-UI half of
     * `Bootstrap::chat()`), the only caller in `src/`, which resets before it
     * arms so a second `chat()` in one process starts from an empty inbox.
     *
     * WHAT IT RESETS SINCE O-2a: the PROCESS sink, in place, plus the route —
     * a sink a host made and routed to with {@see routeTo()} is that host's to
     * reset, and this only stops routing to it.
     */
    public static function reset(): void
    {
        // The process sink is emptied IN PLACE rather than replaced, so a
        // WorkspaceContext that captured it still points at the live inbox.
        // A routed-to sink belongs to whoever made it (they reset it); this
        // only drops the route, so the next arm opens the process sink.
        self::$current = null;
        self::process()->reset();
    }

    /**
     * The process's own sink: the one a TUI launch arms, and the one every
     * emitter reaches when no host has routed elsewhere.
     */
    public static function process(): NoticeSink
    {
        return self::$process ??= NoticeSink::new();
    }

    /**
     * The sink this process's emitters write to and its drain owner reads:
     * whatever {@see routeTo()} routed to, else {@see process()}.
     */
    public static function current(): NoticeSink
    {
        return self::$current ?? self::process();
    }

    /**
     * Route this process's notices to `$sink`; null routes back to
     * {@see process()}.
     *
     * For a host that owns several sessions' sinks (O-2a). Prefer
     * {@see using()}, which cannot forget to route back.
     */
    public static function routeTo(?NoticeSink $sink): void
    {
        self::$current = $sink;
    }

    /**
     * Run `$body` with `$sink` current, then restore whatever was current.
     *
     * THE WAY A HOST STARTS A TURN FOR ONE OF SEVERAL SESSIONS:
     * {@see \SugarCraft\Crush\Backend\EngineBackend::completeAsync()} forks
     * synchronously inside the call, so a turn begun inside `$body` belongs to
     * `$sink` for its whole life even though the promise settles long after
     * this returns — the child pinned it ({@see enterForkedChild()}).
     *
     * @template T
     *
     * @param \Closure(): T $body
     *
     * @return T
     */
    public static function using(NoticeSink $sink, \Closure $body): mixed
    {
        $previous = self::$current;
        self::$current = $sink;

        try {
            return $body();
        } finally {
            self::$current = $previous;
        }
    }

    /**
     * The forked-turn-child half of the per-session seam: make `$sink` this
     * process's sink for good, and make the process a WRITER ONLY.
     *
     * Called first thing in
     * {@see \SugarCraft\Crush\Backend\EngineBackend::completeAsync()}'s child
     * branch with the sink that was current in the parent when the turn was
     * started. Pinning it is what keeps the turn's notices on the session that
     * started it even if anything in the child consults the route again. And
     * every read watcher the child inherited is forgotten WITHOUT running its
     * canceller: the watcher belongs to the parent's loop, the child holds only
     * a copy of that loop, and a child that ever serviced it would hand the
     * parent's Chat a wake-up in the wrong process — which, with the drain
     * that follows, is the parent's inbox read dry by a child. {@see NoticeSink}
     * refuses that read by pid as well; this drops the trigger.
     */
    public static function enterForkedChild(NoticeSink $sink): void
    {
        self::process()->forgetInheritedWatcher();
        self::$current?->forgetInheritedWatcher();
        $sink->forgetInheritedWatcher();
        self::$current = $sink;
    }

    /**
     * The suffix a clipped row ends with, naming where its full text is now.
     *
     * Bounded: {@see TuiErrorLog::describeDestination()} caps the destination
     * at {@see TuiErrorLog::MAX_DESCRIBED_BYTES}, so the suffix can never eat
     * {@see MAX_CHARS}' budget whole.
     */
    public static function clipSuffix(): string
    {
        return sprintf(self::CLIP_SUFFIX_FORMAT, self::fullTextPhrase());
    }

    /** The "and N more" tail row, spelled with {@see OVERFLOW_FORMAT}. */
    public static function overflowNotice(int $dropped): string
    {
        return sprintf(self::OVERFLOW_FORMAT, $dropped, $dropped === 1 ? '' : 's', self::fullTextPhrase());
    }

    /**
     * Where this process's complete record of a notice is — `full text on
     * stderr`, `full text in ~/.sugar-crush/logs/sugarcrush.log` — or
     * {@see FULL_TEXT_NOT_KEPT} (audit C4).
     *
     * Two cases keep nothing a reader can open, and both say so rather than
     * point at a place that is empty: {@see warn()}'s C2a skip (transport
     * armed and `error_log` still on stderr — no copy is written at all), and
     * an `error_log` on the null device, which is {@see TuiErrorLog}'s R16
     * last resort.
     *
     * @param NoticeSink|null $sink whose transport decides the C2a case — the
     *                              sink clipping the row; null asks about
     *                              {@see current()}, which is the one
     *                              {@see warn()} consults
     */
    public static function fullTextPhrase(?NoticeSink $sink = null): string
    {
        $ini = ini_get('error_log');
        if (($sink ?? self::current())->hasTransport() && TuiErrorLog::destinationIsStderr($ini)) {
            return self::FULL_TEXT_NOT_KEPT;
        }

        $where = TuiErrorLog::describeDestination($ini);
        if ($where === null) {
            return self::FULL_TEXT_NOT_KEPT;
        }

        return sprintf(self::FULL_TEXT_AT_FORMAT, $where === 'stderr' ? 'on' : 'in', $where);
    }
}
