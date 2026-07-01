<?php

declare(strict_types=1);

namespace SugarCraft\Pty;

use SugarCraft\Core\Recorder;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\Exception\ExpectEofException;
use SugarCraft\Pty\Exception\ExpectTimeoutException;

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
    /** Default per-expect timeout in seconds. Matches Python pexpect's. */
    public const DEFAULT_TIMEOUT_SEC = 5.0;

    /** Per-`fread` chunk size when filling the buffer. */
    public const DEFAULT_READ_CHUNK = 4096;

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
     * @param int|null      $maxBuffer   Cap on buffer size in bytes; null = unbounded.
     *                                   When set, the buffer is trimmed to this size after
     *                                   each read to prevent unbounded growth.
     * @param int|null       $searchWindow Number of bytes at the buffer tail to search;
     *                                   null = search entire buffer (original behaviour).
     *                                   Reduces O(n²) re-scan cost for long sessions.
     */
    public function __construct(
        public readonly MasterPty $master,
        public readonly string $buffer = '',
        public readonly ?string $lastMatch = null,
        public readonly ?string $before = null,
        public readonly int $readChunk = self::DEFAULT_READ_CHUNK,
        public readonly ?Recorder $recorder = null,
        public readonly ?int $maxBuffer = null,
        public readonly ?int $searchWindow = null,
    ) {}

    /**
     * Open a fresh expect session on the given master. Equivalent to
     * pexpect's `pexpect.spawn(...)` after the child has been forked —
     * this lib expects the caller to handle the spawn separately so
     * the master can be reused across multiple Expect chains.
     */
    public static function on(MasterPty $master, int $readChunk = self::DEFAULT_READ_CHUNK, ?Recorder $recorder = null): self
    {
        if ($readChunk <= 0) {
            throw new \InvalidArgumentException("readChunk must be > 0, got {$readChunk}");
        }
        return new self($master, readChunk: $readChunk, recorder: $recorder);
    }

    /**
     * Write raw bytes to the master without any newline conversion.
     * Returns a new Expect carrying the same buffered state.
     */
    public function send(string $bytes): self
    {
        // Flush loop: handle short writes by looping until all bytes are accepted.
        $written = 0;
        while ($written < \strlen($bytes)) {
            $n = $this->master->write(\substr($bytes, $written));
            if ($n <= 0) {
                // Would-block on non-blocking master — brief retry.
                \usleep(1000);
                $n = $this->master->write(\substr($bytes, $written));
                if ($n <= 0) {
                    throw new \RuntimeException('Failed to write to master PTY');
                }
            }
            $written += $n;
        }
        $this->recorder?->recordInputBytes($bytes);
        return new self(
            master: $this->master,
            buffer: $this->buffer,
            lastMatch: $this->lastMatch,
            before: $this->before,
            readChunk: $this->readChunk,
            recorder: $this->recorder,
            maxBuffer: $this->maxBuffer,
            searchWindow: $this->searchWindow,
        );
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
        return new self(
            master: $this->master,
            buffer: $this->buffer,
            lastMatch: $this->lastMatch,
            before: $this->before,
            readChunk: $this->readChunk,
            recorder: $recorder,
            maxBuffer: $this->maxBuffer,
            searchWindow: $this->searchWindow,
        );
    }

    /**
     * Return a new Expect with a buffer size cap applied.
     *
     * When set, the buffer is trimmed to at most `$maxBuffer` bytes after
     * each read, keeping enough trailing bytes to still match the longest
     * needle. Null unsets the cap (unbounded growth, original behaviour).
     */
    public function withMaxBuffer(?int $maxBuffer): self
    {
        return new self(
            master: $this->master,
            buffer: $this->buffer,
            lastMatch: $this->lastMatch,
            before: $this->before,
            readChunk: $this->readChunk,
            recorder: $this->recorder,
            maxBuffer: $maxBuffer,
            searchWindow: $this->searchWindow,
        );
    }

    /**
     * Return a new Expect with a search window applied.
     *
     * When set, `expectAny`/`expectPattern` only scan the last
     * `$searchWindow` bytes of the buffer for a match (pexpect's
     * `searchwindowsize`). This bounds the O(n) re-scan cost for
     * long-running sessions. Null means search the full buffer
     * (original behaviour).
     */
    public function withSearchWindow(?int $searchWindow): self
    {
        return new self(
            master: $this->master,
            buffer: $this->buffer,
            lastMatch: $this->lastMatch,
            before: $this->before,
            readChunk: $this->readChunk,
            recorder: $this->recorder,
            maxBuffer: $this->maxBuffer,
            searchWindow: $searchWindow,
        );
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
        if ($needles === []) {
            throw new \InvalidArgumentException('Expect::expectAny requires at least one needle');
        }
        foreach ($needles as $i => $needle) {
            if (!\is_string($needle) || $needle === '') {
                throw new \InvalidArgumentException("Expect::expectAny needle at index {$i} must be a non-empty string");
            }
        }

        $deadline = \microtime(true) + $timeoutSec;
        $buffer = $this->buffer;
        $maxNeedleLen = 0;
        foreach ($needles as $needle) {
            $maxNeedleLen = \max($maxNeedleLen, \strlen($needle));
        }

        while (true) {
            $bestPos = null;
            $bestNeedle = null;

            // searchWindow: only look at the last N bytes to bound O(n) re-scan cost.
            $searchStart = $this->searchWindow !== null
                ? \max(0, \strlen($buffer) - $this->searchWindow)
                : 0;

            foreach ($needles as $needle) {
                $pos = \strpos($buffer, $needle, $searchStart);
                if ($pos === false) {
                    continue;
                }
                if ($bestPos === null || $pos < $bestPos) {
                    $bestPos = $pos;
                    $bestNeedle = $needle;
                }
            }
            if ($bestNeedle !== null) {
                /** @var int $bestPos — guarded above with $bestNeedle */
                $before = \substr($buffer, 0, $bestPos);
                $remaining = \substr($buffer, $bestPos + \strlen($bestNeedle));
                return new self(
                    master: $this->master,
                    buffer: $remaining,
                    lastMatch: $bestNeedle,
                    before: $before,
                    readChunk: $this->readChunk,
                    recorder: $this->recorder,
                    maxBuffer: $this->maxBuffer,
                    searchWindow: $this->searchWindow,
                );
            }

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

            // Enforce maxBuffer: trim from front, keeping enough trailing bytes
            // to still match the longest needle.
            if ($this->maxBuffer !== null && \strlen($buffer) > $this->maxBuffer) {
                $keep = \max(0, $this->maxBuffer - $maxNeedleLen);
                if ($keep < \strlen($buffer)) {
                    $buffer = \substr($buffer, -$keep);
                }
            }
        }
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
        if ($regex === '') {
            throw new \InvalidArgumentException('Expect::expectPattern regex must be non-empty');
        }

        $deadline = \microtime(true) + $timeoutSec;
        $buffer = $this->buffer;

        while (true) {
            $matches = [];

            // searchWindow: only feed the last N bytes to preg_match to bound
            // the cost of anchored regexes that scan the full buffer.
            $searchBuffer = $this->searchWindow !== null && \strlen($buffer) > $this->searchWindow
                ? \substr($buffer, -$this->searchWindow)
                : $buffer;

            $rc = @\preg_match($regex, $searchBuffer, $matches, PREG_OFFSET_CAPTURE);
            if ($rc === false) {
                throw new \InvalidArgumentException(
                    "Expect::expectPattern: invalid regex '{$regex}'",
                );
            }
            if ($rc === 1) {
                /** @var array{0: array{0: string, 1: int}} $matches */
                [$matchText, $offset] = $matches[0];
                // Adjust offset to account for searchWindow slicing.
                $absoluteOffset = $this->searchWindow !== null && \strlen($buffer) > $this->searchWindow
                    ? \strlen($buffer) - $this->searchWindow + $offset
                    : $offset;
                $before = \substr($buffer, 0, $absoluteOffset);
                $remaining = \substr($buffer, $absoluteOffset + \strlen($matchText));
                return new self(
                    master: $this->master,
                    buffer: $remaining,
                    lastMatch: $matchText,
                    before: $before,
                    readChunk: $this->readChunk,
                    recorder: $this->recorder,
                    maxBuffer: $this->maxBuffer,
                    searchWindow: $this->searchWindow,
                );
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
            if ($this->maxBuffer !== null && \strlen($buffer) > $this->maxBuffer) {
                $keep = \max(0, $this->maxBuffer - 1024);
                if ($keep < \strlen($buffer)) {
                    $buffer = \substr($buffer, -$keep);
                }
            }
        }
    }

    /**
     * Wait for the master to signal EOF on the PTY.
     *
     * Mirrors charmbracelet/mbubbletea/expect.Exp.
     *
     * @throws ExpectEofException when the master already reached EOF.
     */
    public function expectEof(float $timeoutSec = self::DEFAULT_TIMEOUT_SEC): self
    {
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
                return new self(
                    master: $this->master,
                    buffer: '',
                    lastMatch: null,
                    before: $buffer,
                    readChunk: $this->readChunk,
                    recorder: $this->recorder,
                    maxBuffer: $this->maxBuffer,
                    searchWindow: $this->searchWindow,
                );
            }
            $this->recorder?->recordOutput($chunk);
            $buffer .= $chunk;

            // Enforce maxBuffer while waiting for EOF.
            if ($this->maxBuffer !== null && \strlen($buffer) > $this->maxBuffer) {
                $buffer = \substr($buffer, -$this->maxBuffer);
            }
        }
    }
}
