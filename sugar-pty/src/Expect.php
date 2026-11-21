<?php

declare(strict_types=1);

namespace SugarCraft\Pty;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Core\Concerns\Mutable;
use SugarCraft\Core\Recorder;
use SugarCraft\Pty\Contract\Child;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\Exception\ExpectEofException;
use SugarCraft\Pty\Exception\ExpectTimeoutException;
use SugarCraft\Pty\Posix\PosixMasterPty;

/**
 * Fluent expect-style driver over a {@see MasterPty}. Models Python
 * `pexpect` so callers can script terminal dialogs:
 *
 * ```php
 * $state = Expect::on($master)
 *     ->expect('login: ')
 *     ->sendLine('alice')
 *     ->expect('password: ')
 *     ->sendLine('secret')
 *     ->expect('# ');
 *
 * echo $state->before;     // text between the previous match and the prompt
 * echo $state->lastMatch;  // '# '
 * ```
 *
 * Immutable per the SugarCraft model pattern — every `send*` / `expect*`
 * returns a NEW instance. `send*` performs the master write as a side
 * effect but carries no buffered state forward; `expect*` reads bytes
 * until the needle (or pattern) matches and slices the buffer so the
 * next call resumes after the match.
 */
final class Expect
{
    use Mutable;

    /** Default per-expect timeout in seconds. Matches Python pexpect's. */
    public const DEFAULT_TIMEOUT_SEC = 5.0;

    /** Per-`fread` chunk size when filling the buffer. */
    public const DEFAULT_READ_CHUNK = 4096;

    /**
     * Default tail bound on the read buffer (PTY-31): 1 MiB, so a
     * chatty child cannot grow it without limit. The finding merge
     * picked this over ASYNC-004's 64 KiB because real build-log
     * tails run past 64 KiB. `maxBuffer: null` is the explicit
     * opt-out for full capture.
     */
    public const DEFAULT_MAX_BUFFER = 1048576;

    /**
     * How long {@see send()} keeps retrying a non-blocking master that
     * accepts nothing before it gives up, in seconds. It is a NO-PROGRESS
     * window: every accepted byte restarts it, so a long send into a child
     * that is reading slowly never trips it -- only a master that stays
     * full for this long does.
     */
    public const SEND_STALL_BUDGET_SEC = 0.1;

    /**
     * @param string      $buffer    Unconsumed bytes read past the last match.
     * @param string|null $lastMatch The most recent successful needle or matched
     *                               substring (when `expectPattern` matched).
     *                               Null on a fresh {@see on()} instance.
     * @param string|null $before    Bytes that appeared BEFORE the last match —
     *                               equivalent to pexpect's `child.before`.
     *                               Null on a fresh instance.
     * @param Recorder|null $recorder Optional recording sink for dialog
     *                               scripting.  When set, {@see send()} calls
     *                               {@see Recorder::recordInputBytes()} and
     *                               each successful match calls {@see Recorder::recordOutput()}.
     * @param int|null      $maxBuffer   Cap on buffer size in bytes; defaults to
     *                                   {@see self::DEFAULT_MAX_BUFFER} (1 MiB).
     *                                   null = explicit opt-OUT: unbounded capture.
     *                                   When set, the buffer is trimmed to this size after
     *                                   each read to prevent unbounded growth. `expectAny`
     *                                   trims only after a pass has searched the fresh
     *                                   chunk, so its buffer (and the one a match carries
     *                                   forward) can exceed the cap by up to one read chunk.
     * @param int|null       $searchWindow Number of bytes at the buffer tail to search;
     *                                   null = search entire buffer (original behaviour).
     *                                   Reduces O(n²) re-scan cost for long sessions.
     * @param LoopInterface|null $loop  The event loop {@see expectAsync()} reads on;
     *                                   null leaves only the blocking matchers usable.
     */
    public function __construct(
        public readonly MasterPty $master,
        public readonly string $buffer = '',
        public readonly ?string $lastMatch = null,
        public readonly ?string $before = null,
        public readonly int $readChunk = self::DEFAULT_READ_CHUNK,
        public readonly ?Recorder $recorder = null,
        public readonly ?int $maxBuffer = self::DEFAULT_MAX_BUFFER,
        public readonly ?int $searchWindow = null,
        public readonly ?LoopInterface $loop = null,
    ) {
        if ($readChunk <= 0) {
            throw new \InvalidArgumentException("readChunk must be > 0, got {$readChunk}");
        }
        if ($maxBuffer !== null && $maxBuffer <= 0) {
            throw new \InvalidArgumentException("maxBuffer must be > 0 or null, got {$maxBuffer}");
        }
        if ($searchWindow !== null && $searchWindow <= 0) {
            throw new \InvalidArgumentException("searchWindow must be > 0 or null, got {$searchWindow}");
        }
    }

    /**
     * Open a fresh expect session on the given master. Equivalent to
     * pexpect's `pexpect.spawn(...)` after the child has been forked —
     * this lib expects the caller to handle the spawn separately so
     * the master can be reused across multiple Expect chains.
     *
     * Pass `$loop` to enable {@see expectAsync()}, which reads on that loop
     * instead of blocking; the blocking matchers ignore it.
     */
    public static function on(
        MasterPty $master,
        int $readChunk = self::DEFAULT_READ_CHUNK,
        ?Recorder $recorder = null,
        ?LoopInterface $loop = null,
    ): self {
        if ($readChunk <= 0) {
            throw new \InvalidArgumentException("readChunk must be > 0, got {$readChunk}");
        }
        return new self($master, readChunk: $readChunk, recorder: $recorder, loop: $loop);
    }

    /**
     * Write raw bytes to the master without any newline conversion.
     * Returns a new Expect carrying the same buffered state.
     *
     * Short and would-block writes are retried until every byte is
     * accepted. A master that accepts nothing for
     * {@see SEND_STALL_BUDGET_SEC} -- a child that has stopped reading its
     * input -- fails the send; ordinary back-pressure from a child that is
     * still draining its input queue does not, because each accepted byte
     * restarts that window. (It used to give up after a single 1 ms retry,
     * so a long scripted send into a busy child threw spuriously.)
     *
     * @throws \RuntimeException when the master stays full for the whole
     *         stall budget
     */
    public function send(string $bytes): self
    {
        $written = 0;
        $total = \strlen($bytes);
        $stallDeadline = \microtime(true) + self::SEND_STALL_BUDGET_SEC;
        // The stall guarantee only bites if the loop runs while the write
        // is stuck. A bare write() against a BLOCKING master with a full
        // queue never returns — it sleeps inside the kernel — so the
        // deadline below would be checked too late or never. Arm a real
        // write-readiness select over the remaining budget for the
        // masters we own; foreign implementations (test fixtures, future
        // backends without stream()) keep the write-and-backoff loop.
        $writeStream = $this->master instanceof PosixMasterPty
            ? $this->master->stream()
            : null;
        // A blocking stream cannot be interrupted from userspace at all:
        // fwrite(2) on a full queue sleeps inside the kernel until room
        // appears, no matter how carefully we gate the call. Borrow the
        // pump pattern — non-blocking for the duration of the send, the
        // caller's mode restored in finally — so the readiness select
        // below actually owns the wait.
        $restoreBlocking = false;
        if ($writeStream !== null && (\stream_get_meta_data($writeStream)['blocked'] ?? true)) {
            @\stream_set_blocking($writeStream, false);
            $restoreBlocking = true;
        }
        try {
            while ($written < $total) {
                if ($writeStream !== null) {
                    $budget = $stallDeadline - \microtime(true);
                    if ($budget <= 0) {
                        throw $this->stalled($written, $total);
                    }
                    $read = [];
                    $write = [$writeStream];
                    $except = null;
                    $ready = PosixMasterPty::retryOnEintr(
                        $read,
                        $write,
                        $except,
                        (int) $budget,
                        (int) \round(($budget - (int) $budget) * 1_000_000),
                    );
                    if ($ready === false || $ready === 0) {
                        continue;
                    }
                }
                $n = $this->master->write(\substr($bytes, $written));
                if ($n > 0) {
                    $written += $n;
                    $stallDeadline = \microtime(true) + self::SEND_STALL_BUDGET_SEC;
                    continue;
                }
                if (\microtime(true) >= $stallDeadline) {
                    throw $this->stalled($written, $total);
                }
                // Would-block on a non-blocking master: back off briefly.
                \usleep(1000);
            }
        } finally {
            // The single restore point for every path (PTY-32): the
            // old in-try duplicate was dead — this finally covers the
            // success leg too.
            if ($restoreBlocking) {
                \stream_set_blocking($writeStream, true);
            }
        }
        $this->recorder?->recordInputBytes($bytes);
        return $this->mutate([]);
    }

    /**
     * The send-stall failure, shared by the pre-write select arm and the
     * post-write fallback check so both spell the diagnosis identically.
     */
    private function stalled(int $written, int $total): \RuntimeException
    {
        return new \RuntimeException(Lang::t('expect.stalled', [
            'budget_ms' => (int) (self::SEND_STALL_BUDGET_SEC * 1000),
            'written' => $written,
            'total' => $total,
        ]));
    }

    /**
     * Convenience wrapper: append `$eol` to `$line` before sending.
     * Mirrors pexpect's `sendline()` — default eol is `\n`, override
     * for telnet-style `\r\n` or pure-CR terminals.
     */
    public function sendLine(string $line, string $eol = "\n"): self
    {
        return $this->send($line . $eol);
    }

    /**
     * Attach (or detach) a {@see Recorder} to tee sent bytes and received
     * output into a cassette.  Pass null to detach.
     *
     * Returns a new Expect — immutable + fluent per the model pattern.
     *
     * @see \SugarCraft\Core\Recorder
     */
    public function withRecorder(?Recorder $recorder): self
    {
        return $this->mutate(['recorder' => $recorder]);
    }

    /**
     * Return a new Expect with a buffer size cap applied.
     *
     * Fresh instances arrive capped at 1 MiB (DEFAULT_MAX_BUFFER); this
     * sets a different bound. When set, the buffer is trimmed to at most
     * `$maxBuffer` bytes after each read, keeping enough trailing bytes
     * to still match the longest needle. Null removes the cap — HAZARD:
     * the buffer then grows without bound for the life of the session,
     * which is only safe for short scripted conversations you intend to
     * finish. Zero and negative caps are refused at construction.
     */
    public function withMaxBuffer(?int $maxBuffer): self
    {
        return $this->mutate(['maxBuffer' => $maxBuffer]);
    }

    /**
     * Return a new Expect with a search window applied.
     *
     * When set, `expectAny`/`expectPattern` only scan the last
     * `$searchWindow` bytes of the buffer for a match (pexpect's
     * `searchwindowsize`). This bounds the O(n) re-scan cost for
     * long-running sessions. Null means search the full buffer
     * (original behaviour). Zero and negative windows are refused at
     * construction.
     *
     * Straddle rule: with a window set, a match — literal needle or
     * regex — whose FIRST byte lands before the tail window's start is
     * never found: `expectAny` bounds the strpos START, and
     * `expectPattern` only feeds the window slice to the engine, so the
     * straddling head bytes are not even visible. Only occurrences
     * beginning inside the last `$searchWindow` bytes match. What the
     * pattern leg adds is an offset REMAP (slice offset → full-buffer
     * offset) so `before`/`buffer` split at the true position. Size the
     * window at or above your longest needle plus expected noise, or
     * keep it null.
     */
    public function withSearchWindow(?int $searchWindow): self
    {
        return $this->mutate(['searchWindow' => $searchWindow]);
    }

    /**
     * Block until `$needle` appears in the master's output, or the
     * timeout elapses. Returns a new Expect whose `$lastMatch` is the
     * needle and whose `$before` is everything that appeared between
     * the previous match and this one.
     *
     * @throws ExpectTimeoutException when no match arrives in time.
     * @throws ExpectEofException     when the master EOFs before a match.
     */
    public function expect(string $needle, float $timeoutSec = self::DEFAULT_TIMEOUT_SEC): self
    {
        if ($needle === '') {
            throw new \InvalidArgumentException('Expect::expect needle must be non-empty');
        }
        return $this->expectAny([$needle], $timeoutSec);
    }

    /**
     * Like {@see expect()} but races several needles in parallel.
     * Returns the new Expect for whichever needle matched first
     * (earliest byte offset in the buffer); ties broken by first in
     * the array.
     *
     * @param list<string> $needles
     * @throws ExpectTimeoutException when no needle matches in time.
     * @throws ExpectEofException     when the master EOFs before a match.
     */
    public function expectAny(array $needles, float $timeoutSec = self::DEFAULT_TIMEOUT_SEC): self
    {
        self::assertTimeoutIsNonNegative($timeoutSec);
        $needles = $this->validatedNeedles($needles, 'expectAny');
        $maxNeedleLen = self::longestNeedle($needles);

        $deadline = \microtime(true) + $timeoutSec;
        $buffer = $this->buffer;

        // Resume cursor per needle (rank 79): see scanNeedles().
        $resumeAt = \array_fill(0, \count($needles), 0);

        while (true) {
            $hit = $this->scanNeedles($buffer, $needles, $resumeAt);
            if ($hit !== null) {
                [$bestPos, $bestNeedle] = $hit;
                $before = \substr($buffer, 0, $bestPos);
                $remaining = \substr($buffer, $bestPos + \strlen($bestNeedle));
                return $this->mutate([
                    'buffer' => $remaining,
                    'lastMatch' => $bestNeedle,
                    'before' => $before,
                ]);
            }

            $buffer = $this->trimAfterPass($buffer, $maxNeedleLen, $resumeAt);

            $remaining = $deadline - \microtime(true);
            if ($remaining <= 0) {
                throw ExpectTimeoutException::forNeedles($needles, $timeoutSec, $buffer);
            }

            $chunk = $this->master->read(
                $this->readChunk,
                \min($remaining, 0.1),
            );
            if ($chunk === null) {
                continue;
            }
            if ($chunk === '') {
                throw ExpectEofException::forNeedles($needles, $buffer);
            }
            $this->recorder?->recordOutput($chunk);
            $buffer .= $chunk;
        }
    }

    /**
     * The needle list validated the way every literal matcher needs it:
     * non-empty, every entry a non-empty string, and no needle longer than
     * the buffer cap. Returns the needles reindexed from 0.
     *
     * @param array<mixed> $needles
     * @return list<string>
     */
    private function validatedNeedles(array $needles, string $method): array
    {
        if ($needles === []) {
            throw new \InvalidArgumentException("Expect::{$method} requires at least one needle");
        }
        foreach ($needles as $i => $needle) {
            if (!\is_string($needle) || $needle === '') {
                throw new \InvalidArgumentException("Expect::{$method} needle at index {$i} must be a non-empty string");
            }
        }
        /** @var list<string> $needles */
        $needles = \array_values($needles);
        $maxNeedleLen = self::longestNeedle($needles);

        // Fail loud: a literal needle longer than the buffer cap could
        // never survive the per-read trim, so the match is impossible by
        // construction. Reject the configuration instead of spinning
        // until timeout with a silently amnesic buffer.
        if ($this->maxBuffer !== null && $this->maxBuffer < $maxNeedleLen) {
            throw new \InvalidArgumentException(
                "Expect::{$method} maxBuffer ({$this->maxBuffer}) is smaller than the longest needle ({$maxNeedleLen}); the needle could never match",
            );
        }

        return $needles;
    }

    /** @param list<string> $needles */
    private static function longestNeedle(array $needles): int
    {
        $max = 0;
        foreach ($needles as $needle) {
            $max = \max($max, \strlen($needle));
        }

        return $max;
    }

    /**
     * One search pass over `$buffer`: the earliest needle occurrence, ties
     * broken by needle order, as [offset, needle], or null.
     *
     * `$resumeAt` holds a cursor per needle (rank 79): the first buffer offset
     * at which that needle has not been tried yet. A failed pass proves no
     * occurrence STARTS before `strlen - strlen(needle) + 1`, so the next
     * pass starts there instead of rescanning from byte 0. A needle can
     * still complete across the read boundary: its start sits inside the
     * last strlen(needle) - 1 bytes, which the cursor never passes.
     *
     * @param list<string>   $needles
     * @param array<int,int> $resumeAt updated in place for every needle that missed
     * @return array{0: int, 1: string}|null
     */
    private function scanNeedles(string $buffer, array $needles, array &$resumeAt): ?array
    {
        $bestPos = null;
        $bestNeedle = null;

        // searchWindow: only look at the last N bytes to bound O(n) re-scan cost.
        $searchStart = $this->searchWindow !== null
            ? \max(0, \strlen($buffer) - $this->searchWindow)
            : 0;

        foreach ($needles as $i => $needle) {
            $pos = \strpos($buffer, $needle, \max($searchStart, $resumeAt[$i]));
            if ($pos === false) {
                $resumeAt[$i] = \max(0, \strlen($buffer) - \strlen($needle) + 1);
                continue;
            }
            if ($bestPos === null || $pos < $bestPos) {
                $bestPos = $pos;
                $bestNeedle = $needle;
            }
        }

        return $bestNeedle === null ? null : [(int) $bestPos, $bestNeedle];
    }

    /**
     * Enforce maxBuffer only AFTER a pass has searched every byte: trimming a
     * fresh chunk before the search would cut away a match lying in, or
     * spanning, the bytes the cap gives up. The trim keeps at least the
     * longest needle, and each cursor already sits within that tail, so
     * shifting the cursors by the cut never skips an untried offset. The
     * buffer therefore peaks at maxBuffer plus one read chunk while a pass
     * runs.
     *
     * @param array<int,int> $resumeAt shifted in place by the cut
     */
    private function trimAfterPass(string $buffer, int $maxNeedleLen, array &$resumeAt): string
    {
        $untrimmed = \strlen($buffer);
        $buffer = $this->trimBuffer($buffer, $maxNeedleLen);
        $cut = $untrimmed - \strlen($buffer);
        if ($cut > 0) {
            foreach ($resumeAt as $i => $offset) {
                $resumeAt[$i] = \max(0, $offset - $cut);
            }
        }

        return $buffer;
    }

    /**
     * One PCRE pass over `$buffer` (or its searchWindow tail) as
     * [full-buffer offset, matched text], or null on no match.
     *
     * @return array{0: int, 1: string}|null
     * @throws \InvalidArgumentException on an invalid regex
     */
    private function matchPattern(string $regex, string $buffer, string $method): ?array
    {
        $matches = [];

        // searchWindow: only feed the last N bytes to preg_match to bound
        // the cost of anchored regexes that scan the full buffer.
        $windowed = $this->searchWindow !== null && \strlen($buffer) > $this->searchWindow;
        $searchBuffer = $windowed ? \substr($buffer, -$this->searchWindow) : $buffer;

        // Suppress warning for invalid regex — we handle the false case below.
        $rc = @\preg_match($regex, $searchBuffer, $matches, PREG_OFFSET_CAPTURE);
        if ($rc === false) {
            $error = \preg_last_error();
            $msg = \preg_last_error_msg() ?: "preg_match failed (error code {$error})";
            throw new \InvalidArgumentException(
                "Expect::{$method}: invalid regex '{$regex}': {$msg}",
            );
        }
        if ($rc !== 1) {
            return null;
        }

        /** @var array{0: array{0: string, 1: int}} $matches */
        [$matchText, $offset] = $matches[0];
        // Adjust offset to account for searchWindow slicing.
        $absoluteOffset = $windowed ? \strlen($buffer) - (int) $this->searchWindow + $offset : $offset;

        return [$absoluteOffset, $matchText];
    }

    /**
     * Like {@see expect()} but the needle is a PCRE pattern. Returns
     * a new Expect whose `$lastMatch` is the actual matched substring
     * (not the regex source).
     *
     * @throws ExpectTimeoutException when no match arrives in time.
     * @throws ExpectEofException     when the master EOFs before a match.
     */
    public function expectPattern(string $regex, float $timeoutSec = self::DEFAULT_TIMEOUT_SEC): self
    {
        self::assertTimeoutIsNonNegative($timeoutSec);
        if ($regex === '') {
            throw new \InvalidArgumentException('Expect::expectPattern regex must be non-empty');
        }

        $deadline = \microtime(true) + $timeoutSec;
        $buffer = $this->buffer;

        while (true) {
            $hit = $this->matchPattern($regex, $buffer, 'expectPattern');
            if ($hit !== null) {
                [$absoluteOffset, $matchText] = $hit;
                $before = \substr($buffer, 0, $absoluteOffset);
                $remaining = \substr($buffer, $absoluteOffset + \strlen($matchText));
                return $this->mutate([
                    'buffer' => $remaining,
                    'lastMatch' => $matchText,
                    'before' => $before,
                ]);
            }

            $remaining = $deadline - \microtime(true);
            if ($remaining <= 0) {
                throw ExpectTimeoutException::forPattern($regex, $timeoutSec, $buffer);
            }

            $chunk = $this->master->read(
                $this->readChunk,
                \min($remaining, 0.1),
            );
            if ($chunk === null) {
                continue;
            }
            if ($chunk === '') {
                throw ExpectEofException::forPattern($regex, $buffer);
            }
            $this->recorder?->recordOutput($chunk);
            $buffer .= $chunk;

            // Enforce maxBuffer: trim from front, keeping enough trailing bytes
            // for any plausible match (use a safe minimum of 1 KiB).
            $buffer = $this->trimBuffer($buffer, 1024);
        }
    }

    /**
     * The non-blocking form of {@see expectAny()} and {@see expectPattern()}:
     * it reads on the Expect's event loop and returns a promise instead of
     * blocking until a match.
     *
     * `$needle` is a literal needle, a list of literal needles raced like
     * {@see expectAny()} (earliest offset wins, ties by list order), or, with
     * `$isPattern: true`, one PCRE pattern. The buffer an earlier `expect*`
     * call carried forward is searched first, and literal needles resume
     * their search window between reads exactly as the blocking form does.
     *
     * The promise resolves with the matched text: the needle, or the
     * substring the pattern matched. It rejects with
     * {@see ExpectTimeoutException} once `$timeoutSec` passes with no match,
     * and with {@see ExpectEofException} when the master reaches EOF, or when
     * `$child` exits and a short drain of what it wrote last (0.1 s) brings no
     * match. Cancelling the promise detaches everything it armed and rejects
     * it with a \RuntimeException. A read error rejects it with that error.
     *
     * Nothing polls: bytes arrive through `$loop->addReadStream()` on the
     * master's stream, the timeout and the post-exit drain are one-shot
     * timers, and the exit is reported by the child's `onExit()` (SIGCHLD,
     * with that method's own fallback where SIGCHLD cannot be delivered). The
     * stream is switched to non-blocking while the promise is pending and
     * put back when it settles. One pending expectAsync() per master: a
     * second read listener on the same stream would not be armed.
     *
     * This instance is immutable and the promise yields text only, so the
     * bytes read past the match are not carried into a successor; a session
     * that needs them stays on the blocking matchers.
     *
     * @param string|list<string> $needle    literal needle(s), or one PCRE pattern
     * @param float               $timeoutSec seconds from this call
     * @param bool                $isPattern  treat `$needle` as one PCRE pattern
     * @param Child|null          $child      whose exit without a match rejects;
     *                                        it must offer `onExit()`
     * @return PromiseInterface<string>
     * @throws \InvalidArgumentException synchronously when the Expect has no loop,
     *         or on an invalid needle, pattern, timeout or child
     * @throws \RuntimeException synchronously when the master has no stream
     *         (a ConPTY master on Windows)
     */
    public function expectAsync(
        string|array $needle,
        float $timeoutSec = self::DEFAULT_TIMEOUT_SEC,
        bool $isPattern = false,
        ?Child $child = null,
    ): PromiseInterface {
        $loop = $this->loop;
        if ($loop === null) {
            throw new \InvalidArgumentException(
                'Expect::expectAsync needs an event loop: build the Expect with Expect::on($master, loop: $loop)',
            );
        }
        self::assertTimeoutIsNonNegative($timeoutSec);
        if ($isPattern) {
            if (!\is_string($needle) || $needle === '') {
                throw new \InvalidArgumentException('Expect::expectAsync pattern must be one non-empty string');
            }
            // Validates the regex now, so a bad one throws here and not on the loop.
            $this->matchPattern($needle, '', 'expectAsync');
            $needles = [$needle];
        } else {
            $needles = $this->validatedNeedles(\is_string($needle) ? [$needle] : $needle, 'expectAsync');
        }
        if ($child !== null && !\method_exists($child, 'onExit')) {
            throw new \InvalidArgumentException(
                'Expect::expectAsync child ' . $child::class . ' has no onExit(), and its exit cannot be awaited without polling',
            );
        }

        // ConPtyMasterPty::stream() throws a PtyException (a \RuntimeException):
        // there is no stream to watch, and a periodic read is not an option.
        $stream = $this->master->stream();
        if (!\is_resource($stream)) {
            throw new \RuntimeException('Expect::expectAsync: the master has no stream to read on the loop');
        }

        $buffer = $this->buffer;
        $resumeAt = \array_fill(0, \count($needles), 0);
        $maxNeedleLen = self::longestNeedle($needles);

        // One pass over the buffer: the matched text, or null after the trim
        // the blocking form applies between reads.
        $search = function () use (&$buffer, &$resumeAt, $needles, $isPattern, $maxNeedleLen): ?string {
            if ($isPattern) {
                $hit = $this->matchPattern($needles[0], $buffer, 'expectAsync');
                if ($hit === null) {
                    $buffer = $this->trimBuffer($buffer, 1024);

                    return null;
                }

                return $hit[1];
            }
            $hit = $this->scanNeedles($buffer, $needles, $resumeAt);
            if ($hit === null) {
                $buffer = $this->trimAfterPass($buffer, $maxNeedleLen, $resumeAt);

                return null;
            }

            return $hit[1];
        };

        $first = $search();
        if ($first !== null) {
            return \React\Promise\resolve($first);
        }

        $restoreBlocking = (bool) (\stream_get_meta_data($stream)['blocked'] ?? true);
        if ($restoreBlocking) {
            @\stream_set_blocking($stream, false);
        }

        $settled = false;
        /** @var TimerInterface|null $timeoutTimer */
        $timeoutTimer = null;
        /** @var TimerInterface|null $graceTimer */
        $graceTimer = null;
        /** @var \Closure|null $detachExit */
        $detachExit = null;

        // Removes everything this call armed. True only for the first caller,
        // so a promise settles once whichever event gets there first.
        $disarm = static function () use (&$settled, &$timeoutTimer, &$graceTimer, &$detachExit, $loop, $stream, $restoreBlocking): bool {
            if ($settled) {
                return false;
            }
            $settled = true;
            $loop->removeReadStream($stream);
            if ($timeoutTimer !== null) {
                $loop->cancelTimer($timeoutTimer);
                $timeoutTimer = null;
            }
            if ($graceTimer !== null) {
                $loop->cancelTimer($graceTimer);
                $graceTimer = null;
            }
            if ($detachExit !== null) {
                $detach = $detachExit;
                $detachExit = null;
                $detach();
            }
            if ($restoreBlocking && \is_resource($stream)) {
                @\stream_set_blocking($stream, true);
            }

            return true;
        };

        $deferred = new Deferred(static function (callable $resolve, callable $reject) use ($disarm): void {
            if ($disarm()) {
                $reject(new \RuntimeException('Expect::expectAsync cancelled before a match'));
            }
        });
        $fail = static function (\Throwable $error) use ($disarm, $deferred): void {
            if ($disarm()) {
                $deferred->reject($error);
            }
        };
        $eof = static function () use (&$buffer, $needles, $isPattern, $fail): void {
            $fail($isPattern
                ? ExpectEofException::forPattern($needles[0], $buffer)
                : ExpectEofException::forNeedles($needles, $buffer));
        };

        // Reads until the master answers nothing: a loop that polls the fd
        // (ext-uv, ext-ev) never reports bytes PHP's stream layer already
        // pulled into its own buffer, so one readiness event must drain them.
        // A pass stops at DRAIN_MAX_CHUNKS so a child still writing cannot pin
        // the loop, and a capped pass continues on the next tick. True once
        // the promise has settled.
        $pull = function () use (&$pull, &$buffer, &$settled, $loop, $search, $disarm, $deferred, $eof, $fail): bool {
            try {
                for ($i = 0; $i < self::DRAIN_MAX_CHUNKS && !$settled; $i++) {
                    $chunk = $this->master->read($this->readChunk, null);
                    if ($chunk === null) {
                        return $settled;
                    }
                    if ($chunk === '') {
                        $eof();

                        return true;
                    }
                    $this->recorder?->recordOutput($chunk);
                    $buffer .= $chunk;
                    $match = $search();
                    if ($match !== null && $disarm()) {
                        $deferred->resolve($match);
                    }
                }
            } catch (\Throwable $error) {
                $fail($error);
            }
            if (!$settled) {
                $loop->futureTick(static function () use (&$pull, &$settled): void {
                    if (!$settled) {
                        $pull();
                    }
                });
            }

            return $settled;
        };

        $loop->addReadStream($stream, static function () use ($pull): void {
            $pull();
        });

        $timeoutTimer = $loop->addTimer($timeoutSec, static function () use (&$timeoutTimer, &$buffer, $needles, $isPattern, $timeoutSec, $fail): void {
            $timeoutTimer = null;
            $fail($isPattern
                ? ExpectTimeoutException::forPattern($needles[0], $timeoutSec, $buffer)
                : ExpectTimeoutException::forNeedles($needles, $timeoutSec, $buffer));
        });

        if ($child !== null) {
            // The last frame can land a hair after the exit is seen, so the
            // stream stays watched for EXIT_DRAIN_SECONDS before the verdict.
            $detachExit = $child->onExit($loop, static function (int $code) use (&$detachExit, &$graceTimer, &$settled, &$buffer, $loop, $pull, $needles, $isPattern, $fail): void {
                $detachExit = null;
                if ($settled || $pull()) {
                    return;
                }
                $graceTimer = $loop->addTimer(self::EXIT_DRAIN_SECONDS, static function () use (&$graceTimer, &$buffer, $code, $pull, $needles, $isPattern, $fail): void {
                    $graceTimer = null;
                    if ($pull()) {
                        return;
                    }
                    $fail(ExpectEofException::forChildExit($needles, $code, $buffer, $isPattern));
                });
            });
        }

        return $deferred->promise();
    }

    /**
     * Wait for the master to signal EOF on the PTY. EOF is SUCCESS here:
     * the returned Expect carries the pre-EOF output in `$before` and a
     * null `$lastMatch`.
     *
     * The antecedent is Netflix's go-expect, like the rest of this
     * class — there is no charmbracelet expect port to mirror.
     *
     * @throws ExpectTimeoutException when the master never EOFs within
     *         `$timeoutSec`. This method never throws ExpectEofException.
     */
    public function expectEof(float $timeoutSec = self::DEFAULT_TIMEOUT_SEC): self
    {
        self::assertTimeoutIsNonNegative($timeoutSec);
        $deadline = \microtime(true) + $timeoutSec;
        $buffer = $this->buffer;

        while (true) {
            $remaining = $deadline - \microtime(true);
            if ($remaining <= 0) {
                throw ExpectTimeoutException::forEof($timeoutSec, $buffer);
            }

            $chunk = $this->master->read(
                $this->readChunk,
                \min($remaining, 0.1),
            );
            if ($chunk === null) {
                continue;
            }
            if ($chunk === '') {
                // Genuine EOF — return with empty buffer, lastMatch = null.
                return $this->mutate([
                    'buffer' => '',
                    'lastMatch' => null,
                    'before' => $buffer,
                ]);
            }
            $this->recorder?->recordOutput($chunk);
            $buffer .= $chunk;

            // Enforce maxBuffer while waiting for EOF.
            // Pass 0 for needleLen since EOF doesn't need to match anything.
            $buffer = $this->trimBuffer($buffer, 0);
        }
    }

    /** Bytes requested per read inside a window pass. */
    private const WINDOW_READ_BYTES = 8192;

    /**
     * The per-pass read tick. Short enough that a match lands within
     * milliseconds of the paint, long enough that an idle window costs
     * twenty wakeups per second and not one busier.
     */
    private const WINDOW_POLL_SECONDS = 0.05;

    /** Pause between exit checks once the master is at EOF, so the wait does not spin. */
    private const EOF_EXIT_POLL_SECONDS = 0.01;

    /** A post-exit drain stops after this many consecutive empty reads. */
    private const DRAIN_QUIET_READS = 2;

    /** Hard cap on chunks one post-exit drain may pull, so a still-writing child cannot extend it. */
    private const DRAIN_MAX_CHUNKS = 32;

    private const DRAIN_READ_TIMEOUT_SECONDS = 0.05;

    /**
     * Floor on the post-exit drain's own end, measured from the moment the exit is
     * seen. A child that paints its last frame and exits as the wall deadline passes
     * still has that frame read and matched; the drain overshoots the deadline by at
     * most this much, plus one read's scheduling slack.
     */
    private const EXIT_DRAIN_SECONDS = 0.1;

    /**
     * One bounded read window that never throws on a timeout, an EOF or a child
     * exit: the outcome is the return value, so a scripted session keeps one
     * result row per planned step whatever the child does.
     *
     * The window ends at the first of: the regex matching the window's retained
     * output, the child exiting, the window length elapsing, or the wall deadline
     * passing. Master EOF stops the reads but not the wait: a child that closes its
     * tty and exits a moment later is still reported as exited. With no child to wait
     * for, EOF ends the window. Each read is clamped to the time left in the window, so
     * the deadline binds even against a silent master. The one exception is the short
     * drain after a child exit: it runs to the later of the deadline and 0.1 s past the
     * exit, so a last frame painted as the deadline passes is still read and matched.
     * A window the deadline closes on a live child waits up to 0.1 s more for an exit
     * and, if one comes, runs that same drain.
     * A buffer left by an earlier
     * `expect*` call is the window's first input: those bytes were read past that call's
     * match, so the window reports them and does not read them from the master again.
     * The window consumes that buffer. The returned {@see ExpectWindow::$next} carries the
     * same master and state with the buffer cleared, and the following window must run on
     * it. This instance is readonly and still holds the buffer, so a second window run on
     * it reports those bytes again.
     * An invalid regex throws `\InvalidArgumentException`, as `expectPattern` does.
     *
     * The byte budget is session-wide. Pass the running `$retained` and `$dropped`
     * totals in and thread the returned ones into the next window. The transcript
     * is a byte prefix of the child's stream: bytes are kept as the child wrote them,
     * up to the cap, with no validation. Bytes past the cap are counted as dropped,
     * never silently lost. A codepoint that fits whole within the cap is kept whole.
     * A codepoint torn at the cap edge is cut back to its first byte, so the transcript
     * stays a prefix, and after that cut every later byte is refused, in this window
     * and in every later window that is handed the threaded totals. The transcript is
     * not promised to be valid UTF-8: a sequence the child itself tore, or one torn by
     * a read boundary that falls exactly on the cap, is retained as written.
     *
     * @param float         $windowSeconds Window length from now, in seconds.
     * @param float         $deadline      Absolute wall deadline, as microtime(true).
     * @param int           $maxBytes      Session-wide cap on retained bytes.
     * @param int           $retained      Session total retained before this window.
     * @param int           $dropped       Session total dropped before this window.
     * @param string|null   $regex         PCRE matched against this window's retained
     *                                     output; null collects without matching.
     * @param Child|null    $child         Child whose exit ends the window early.
     * @param (\Closure(): void)|null     $heartbeat Fired once per read pass.
     * @param (\Closure(string): void)|null $onChunk   Raw bytes per read, before retention.
     */
    public function collectWindow(
        float $windowSeconds,
        float $deadline,
        int $maxBytes,
        int $retained = 0,
        int $dropped = 0,
        ?string $regex = null,
        ?Child $child = null,
        ?\Closure $heartbeat = null,
        ?\Closure $onChunk = null,
    ): ExpectWindow {
        // Validate once: an invalid pattern would otherwise read as "never matched".
        if ($regex !== null && @\preg_match($regex, '') === false) {
            throw new \InvalidArgumentException("Expect::collectWindow: invalid regex '{$regex}'");
        }

        $output = '';
        $matched = $regex === null ? null : false;
        $childExited = false;
        $eof = false;
        $windowEnd = \min(\microtime(true) + $windowSeconds, $deadline);

        // Already recorded when the earlier read pulled them, so they are not recorded again.
        $pending = $this->buffer;
        if ($pending !== '') {
            if ($onChunk !== null) {
                $onChunk($pending);
            }
            [$output, $retained, $dropped] = self::retainCapped($output, $pending, $maxBytes, $retained, $dropped);
            if ($regex !== null && \preg_match($regex, $output) === 1) {
                $matched = true;
            }
        }

        while ($matched !== true) {
            $now = \microtime(true);
            if ($now >= $windowEnd) {
                break;
            }

            if ($heartbeat !== null) {
                $heartbeat();
            }

            $tick = \min(self::WINDOW_POLL_SECONDS, $windowEnd - $now);
            $chunk = $this->master->read(self::WINDOW_READ_BYTES, $tick);
            if ($chunk === '') {
                // Master EOF: no byte can follow, so stop reading. `eof` tells it from a
                // quiet window. A child that closed its tty may still exit a moment later,
                // so the wait for that exit continues below.
                $eof = true;
            }
            if ($chunk !== null && $chunk !== '') {
                $this->recorder?->recordOutput($chunk);
                if ($onChunk !== null) {
                    // Fed before retention so a live tail is independent of the
                    // transcript's byte budget.
                    $onChunk($chunk);
                }
                [$output, $retained, $dropped] = self::retainCapped($output, $chunk, $maxBytes, $retained, $dropped);
                if ($regex !== null && \preg_match($regex, $output) === 1) {
                    $matched = true;
                    break;
                }
            }

            if ($child?->exited() === true) {
                $childExited = true;
                [$output, $retained, $dropped, $matched] = $this->exitDrain($deadline, $maxBytes, $output, $retained, $dropped, $regex, $matched, $onChunk);

                break;
            }

            if ($eof) {
                if ($child === null) {
                    break;
                }
                // Reads return at once after EOF, so pause before re-checking the
                // child rather than spinning until the window closes.
                \usleep((int) (self::EOF_EXIT_POLL_SECONDS * 1_000_000));
            }
        }

        // The wall deadline can close the window between a last frame and the exit that
        // follows it. A child that exits within the exit-drain floor past the deadline
        // still has that frame read and matched, as an exit seen inside the window does.
        // A child still running then leaves its bytes on the master for the caller.
        if ($matched !== true && !$childExited && $child !== null && \microtime(true) >= $deadline) {
            $graceEnd = \microtime(true) + self::EXIT_DRAIN_SECONDS;
            while (!$child->exited() && \microtime(true) < $graceEnd) {
                \usleep((int) (self::EOF_EXIT_POLL_SECONDS * 1_000_000));
            }
            if ($child->exited()) {
                $childExited = true;
                [$output, $retained, $dropped, $matched] = $this->exitDrain($deadline, $maxBytes, $output, $retained, $dropped, $regex, $matched, $onChunk);
            }
        }

        // A deadline already past on entry ends the main loop with no read and still counts
        // as a wall hit: the deadline is what ended the window, whether or not a read happened.
        // The one exception is the exit grace above: a live child that exits inside it is
        // drained, so its final frame can match and the window is not a wall hit.
        // EOF does not suppress it: with a live child the window keeps waiting past EOF,
        // so a deadline that passes there is a wall hit too.
        $wallHit = $matched !== true && !$childExited && \microtime(true) >= $deadline;

        // The buffer is consumed by this window, so the successor starts with none.
        $next = $pending === '' ? $this : $this->mutate(['buffer' => '']);

        return new ExpectWindow($output, $matched, $childExited, $wallHit, $retained, $dropped, $eof, $next);
    }

    /**
     * The bounded drain after a child exit: the final repaint can land a hair after
     * the exit is observed, so it is read, recorded, retained and matched here.
     * Returns [output, retained, dropped, matched].
     *
     * @param (\Closure(string): void)|null $onChunk
     * @return array{0: string, 1: int, 2: int, 3: ?bool}
     */
    private function exitDrain(
        float $deadline,
        int $maxBytes,
        string $output,
        int $retained,
        int $dropped,
        ?string $regex,
        ?bool $matched,
        ?\Closure $onChunk,
    ): array {
        $finalWindow = $this->drainMaster(\max($deadline, \microtime(true) + self::EXIT_DRAIN_SECONDS));
        if ($finalWindow !== '') {
            $this->recorder?->recordOutput($finalWindow);
            if ($onChunk !== null) {
                $onChunk($finalWindow);
            }
        }
        [$output, $retained, $dropped] = self::retainCapped($output, $finalWindow, $maxBytes, $retained, $dropped);
        if ($regex !== null && $matched === false && \preg_match($regex, $output) === 1) {
            $matched = true;
        }

        return [$output, $retained, $dropped, $matched];
    }

    /**
     * Read the master until it answers nothing twice in a row, chunk-capped and
     * clamped to `$drainEnd`. The caller passes the later of the wall deadline and
     * a short floor from the exit, so a final frame written as the deadline passes
     * is still read, while a late exit overshoots the deadline by a bounded amount.
     */
    private function drainMaster(float $drainEnd): string
    {
        $seen = '';
        $quiet = 0;
        $budget = self::DRAIN_MAX_CHUNKS;

        while ($quiet < self::DRAIN_QUIET_READS && $budget-- > 0) {
            $left = $drainEnd - \microtime(true);
            if ($left <= 0.0) {
                break;
            }

            $chunk = $this->master->read(self::WINDOW_READ_BYTES, \min(self::DRAIN_READ_TIMEOUT_SECONDS, $left));
            if ($chunk === null || $chunk === '') {
                ++$quiet;

                continue;
            }

            $quiet = 0;
            $seen .= $chunk;
        }

        return $seen;
    }

    /**
     * Append a chunk under a cap shared across the whole session: `$retained` is
     * the running total every window draws from, so step count cannot multiply
     * the budget. A non-zero `$dropped` means the transcript already ended, so the
     * whole chunk is refused. Returns [buffer, retained, dropped].
     *
     * @return array{0: string, 1: int, 2: int}
     */
    private static function retainCapped(string $buffer, string $chunk, int $cap, int $retained, int $dropped): array
    {
        if ($chunk === '') {
            return [$buffer, $retained, $dropped];
        }

        // Only the cap refuses bytes: a continuation run at the head of a chunk is
        // retained like any other byte, so `dropped` means exactly the bytes the cap refused.
        // Once the cap has refused a byte the transcript has ended. Room left over by a
        // back-off cut is never spent, because a byte kept after that gap would not follow
        // its predecessor in the stream. Keying on the threaded `dropped` total carries the
        // refusal into every later window of the session as well.
        $room = $dropped > 0 ? 0 : $cap - $retained;
        if ($room <= 0) {
            return [$buffer, $retained, $dropped + \strlen($chunk)];
        }

        if (\strlen($chunk) <= $room) {
            $buffer .= $chunk;
            $retained += \strlen($chunk);
        } else {
            // The cap is a byte budget, so a raw substr at $room can tear a multi-byte
            // sequence at the cap edge. Back the cut off to the start of that sequence;
            // the bytes given up are counted as dropped, which refuses every later byte.
            $cut = self::utf8SafeByteCut($chunk, $room);
            $buffer .= \substr($chunk, 0, $cut);
            $retained += $cut;
            $dropped += \strlen($chunk) - $cut;
        }

        return [$buffer, $retained, $dropped];
    }

    /**
     * Cut length at or below $room bytes that keeps the retained part a byte
     * prefix of the chunk without tearing a codepoint at the cap edge. Only the
     * tail is inspected: scan back up to three bytes for a lead byte, and if its
     * declared length runs past the cut, back the cut off to just before it. A
     * codepoint that ends exactly at the cut is kept whole. Nothing else is
     * checked, so the prefix is not validated as UTF-8. The caller counts the
     * backed-off bytes as dropped, and from then on every later byte is refused.
     */
    private static function utf8SafeByteCut(string $chunk, int $room): int
    {
        $cut = \min($room, \strlen($chunk));

        for ($back = 1; $back <= 3 && $back <= $cut; $back++) {
            $b = \ord($chunk[$cut - $back]);
            if (($b & 0xc0) === 0x80) {
                continue; // continuation byte: keep looking for its lead
            }

            $need = match (true) {
                ($b & 0xe0) === 0xc0 => 2,
                ($b & 0xf0) === 0xe0 => 3,
                ($b & 0xf8) === 0xf0 => 4,
                default              => 1, // ASCII: the run is complete
            };
            if ($need > $back) {
                $cut -= $back; // a lead whose sequence is not yet complete
            }

            break;
        }

        return \max(0, $cut);
    }

    /**
     * A negative budget cannot be raced: it would mint timeout messages
     * like 'timed out after -5.000s' while silently disabling all waits.
     */
    private static function assertTimeoutIsNonNegative(float $timeoutSec): void
    {
        if ($timeoutSec < 0) {
            throw new \InvalidArgumentException(
                'timeoutSec must be >= 0; got ' . \var_export($timeoutSec, true)
            );
        }
    }

    /**
     * Trim the buffer to at most `$this->maxBuffer` bytes, keeping
     * enough trailing bytes to match a needle of `$needleLen`.
     *
     * When `$needleLen` is 0 (e.g. waiting for EOF), only the max
     * buffer cap is applied.
     *
     * Retention is floored at `$needleLen`: the old arithmetic
     * `max(0, maxBuffer - needleLen)` collapsed to `keep = 0` whenever
     * `maxBuffer <= needleLen`, and `substr($buffer, -0)` returns the
     * WHOLE buffer — silently defeating the cap and letting the buffer
     * grow unbounded while the needle could never match. `expectAny`
     * rejects that configuration at entry (fail loud); the floor here
     * is defense-in-depth for `expectPattern`'s fixed 1 KiB heuristic,
     * which may softly exceed a tighter `maxBuffer` (bounded by the
     * heuristic) rather than run away.
     */
    private function trimBuffer(string $buffer, int $needleLen): string
    {
        if ($this->maxBuffer === null || \strlen($buffer) <= $this->maxBuffer) {
            return $buffer;
        }
        $keep = \max($needleLen, $this->maxBuffer - $needleLen);
        if ($keep >= \strlen($buffer)) {
            return $buffer;
        }
        return \substr($buffer, -$keep);
    }
}
