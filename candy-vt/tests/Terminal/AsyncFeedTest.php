<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Terminal;

use LogicException;
use PHPUnit\Framework\TestCase;
use React\Stream\ThroughStream;
use RuntimeException;
use SugarCraft\Async\CancellationSource;
use SugarCraft\Async\OperationCancelledException;
use SugarCraft\Vt\Terminal\Terminal;
use Throwable;

/**
 * Async input surface: `feedAsync()` resolves with the reply-channel bytes
 * and `feedStream()` pumps a ReactPHP ReadableStreamInterface through the
 * parser incrementally.
 *
 * The reply semantics under test are the ones `feed(string, $respond)` and
 * `replies()` already pin (QueryReplyTest): delivery in request order, the
 * whole queue drained exactly once. These tests add only the promise shape.
 * Everything is driven through ThroughStream, whose `write`/`end`/`close`
 * emit synchronously — no event loop run, no timers, no wall-clock sleeps,
 * so each assertion holds the moment the call returns.
 *
 * Mirrors charmbracelet/x/vt `Emulator.Read()` io.Pipe semantics (x/vt
 * emulator.go L265-281) in promise form; findings/candy-vt.md #29.
 */
final class AsyncFeedTest extends TestCase
{
    private const DA1 = "\x1b[?62;1;6;22c";

    // ─── feedAsync ─────────────────────────────────────────────────────────

    public function testFeedAsyncResolvesWithReplyBytesInRequestOrder(): void
    {
        $t = Terminal::new(10, 3);

        $resolved = null;
        $t->feedAsync("\x1b[c\x1b[>c")->then(
            static function (string $bytes) use (&$resolved): void {
                $resolved = $bytes;
            },
        );

        $this->assertSame(self::DA1 . "\x1b[>1;10;0c", $resolved, 'both answers, request order');
        $this->assertSame([], $t->replies(), 'the promise delivery IS the drain');
    }

    public function testFeedAsyncResolvesEmptyStringWhenNothingAnswers(): void
    {
        $t = Terminal::new(10, 3);

        $resolved = null;
        $t->feedAsync('abc')->then(static function (string $bytes) use (&$resolved): void {
            $resolved = $bytes;
        });

        $this->assertSame('', $resolved);
        $this->assertSame('a', $t->screen()->cell(0, 0)->grapheme, 'bytes still reached the grid');
    }

    public function testFeedAsyncAlsoDrainsRepliesQueuedByEarlierSyncFeeds(): void
    {
        // The respond-less `feed()` contract leaves answers queued; the next
        // async feed must hand over the FULL queue (same rule as
        // `feed($bytes, $respond)`), never just its own slice.
        $t = Terminal::new(10, 3);
        $t->feed("\x1b[c");

        $resolved = null;
        $t->feedAsync('x')->then(static function (string $bytes) use (&$resolved): void {
            $resolved = $bytes;
        });

        $this->assertSame(self::DA1, $resolved);
        $this->assertSame([], $t->replies());
    }

    public function testFeedAsyncReturnsAPromiseEvenForEmptyInput(): void
    {
        $t = Terminal::new(10, 3);

        $promise = $t->feedAsync('');

        $resolved = 'unset';
        $promise->then(static function (string $bytes) use (&$resolved): void {
            $resolved = $bytes;
        });
        $this->assertSame('', $resolved);
    }

    // ─── feedStream ────────────────────────────────────────────────────────

    public function testFeedStreamParsesBytesAsTheyArrive(): void
    {
        $t = Terminal::new(10, 3);
        $stream = new ThroughStream();
        /** @var list<string> $seen */
        $seen = [];
        $promise = $t->feedStream($stream, static function (string $reply) use (&$seen): void {
            $seen[] = $reply;
        });

        $stream->write('hi');
        $this->assertSame('h', $t->screen()->cell(0, 0)->grapheme, 'fed on the data event, not on end');
        $this->assertCount(0, $seen);

        $stream->end("\x1b[c");
        $this->assertSame([self::DA1], $seen);

        $resolved = 'unset';
        $promise->then(static function (string $bytes) use (&$resolved): void {
            $resolved = $bytes;
        });
        $this->assertSame('', $resolved, 'respond-carried replies leave the promise empty');
    }

    public function testFeedStreamKeepsParserStateAcrossChunkBoundaries(): void
    {
        // An escape sequence split over two data events must parse as one —
        // the pump feeds incrementally, it does not concatenate then replay.
        $t = Terminal::new(10, 3);
        $stream = new ThroughStream();
        $promise = $t->feedStream($stream);

        $stream->write("\x1b[");
        $stream->write("c");
        $stream->end();

        $resolved = null;
        $promise->then(static function (string $bytes) use (&$resolved): void {
            $resolved = $bytes;
        });
        $this->assertSame(self::DA1, $resolved);
    }

    public function testFeedStreamWithoutRespondResolvesWithTheWholeTranscript(): void
    {
        $t = Terminal::new(10, 3);
        $stream = new ThroughStream();
        $promise = $t->feedStream($stream);

        $stream->write("\x1b[c");
        $stream->write("\x1b[>c");
        $stream->end();

        $resolved = null;
        $promise->then(static function (string $bytes) use (&$resolved): void {
            $resolved = $bytes;
        });
        $this->assertSame(self::DA1 . "\x1b[>1;10;0c", $resolved);
        $this->assertSame([], $t->replies(), 'end resolved the queue — nothing left for replies()');
    }

    public function testFeedStreamFlushesUnterminatedStringSequenceOnEnd(): void
    {
        // An OSC without its terminator only dispatches via the parser flush;
        // the pump must flush at end-of-stream so a session that dies mid-
        // sequence still lands its committed state.
        $t = Terminal::new(10, 3);
        $stream = new ThroughStream();
        $promise = $t->feedStream($stream);

        $stream->write("\x1b]0;AsyncT");
        $this->assertNull($t->windowTitle(), 'still in-flight until the stream ends');
        $stream->end();

        $this->assertSame('AsyncT', $t->windowTitle());
        $resolved = 'unset';
        $promise->then(static function (string $bytes) use (&$resolved): void {
            $resolved = $bytes;
        });
        $this->assertSame('', $resolved);
    }

    public function testFeedStreamRejectsWithTheStreamError(): void
    {
        $t = Terminal::new(10, 3);
        $stream = new ThroughStream();
        $promise = $t->feedStream($stream);

        $boom = new RuntimeException('pty exploded');
        $stream->emit('error', [$boom]);

        $reason = null;
        $promise->then(null, static function (Throwable $e) use (&$reason): void {
            $reason = $e;
        });
        $this->assertSame($boom, $reason);

        // React closes the stream after surfacing an error; the close that
        // follows must not attempt a second settlement (it would be ignored
        // anyway — the guard keeps listener teardown single-shot).
        $stream->close();
        $this->assertSame($boom, $reason);
    }

    public function testFeedStreamRejectsWhenAttachedToAnAlreadyClosedStream(): void
    {
        $t = Terminal::new(10, 3);
        $stream = new ThroughStream();
        $stream->close();

        $reason = null;
        $t->feedStream($stream)->then(null, static function (Throwable $e) use (&$reason): void {
            $reason = $e;
        });

        $this->assertInstanceOf(RuntimeException::class, $reason);
    }

    public function testFeedStreamRejectsOnCloseWithoutEndAsTruncatedInput(): void
    {
        // A transport that drops the connection mid-session must say so —
        // resolving quietly here would present half-read input as complete.
        $t = Terminal::new(10, 3);
        $stream = new ThroughStream();
        $promise = $t->feedStream($stream);

        $stream->write('abc');
        $stream->close();

        $reason = null;
        $promise->then(null, static function (Throwable $e) use (&$reason): void {
            $reason = $e;
        });
        $this->assertInstanceOf(RuntimeException::class, $reason);
        $this->assertStringContainsString('before signalling end-of-stream', $reason->getMessage());
    }

    public function testSettlementAfterEndIsSingleAndFinal(): void
    {
        // React's stream contract closes after ending; both events land on
        // our handlers and exactly one may settle the deferred.
        $t = Terminal::new(10, 3);
        $stream = new ThroughStream();
        $promise = $t->feedStream($stream);

        $settles = 0;
        $promise->then(
            static function () use (&$settles): void {
                $settles++;
            },
            static function () use (&$settles): void {
                $settles++;
            },
        );

        $stream->end();
        $stream->close();

        $this->assertSame(1, $settles);
    }

    public function testFeedStreamDetachesItsListenersOnEnd(): void
    {
        $t = Terminal::new(10, 3);
        $stream = new ThroughStream();
        $promise = $t->feedStream($stream);
        $this->assertSame(1, count($stream->listeners("data")));

        $stream->end();
        $this->assertSame(0, count($stream->listeners("data")), 'settled pumps must not linger on the stream');
        $stream->close();

        $resolved = null;
        $promise->then(static function (string $bytes) use (&$resolved): void {
            $resolved = $bytes;
        });
        $this->assertSame('', $resolved);
    }

    // ─── caller failures (round-1 review M1) ───────────────────────────────

    public function testThrowingRespondOnDataRejectsDetachesAndRethrows(): void
    {
        $t = Terminal::new(10, 3);
        $stream = new ThroughStream();
        $boom = new LogicException('respond exploded');
        $promise = $t->feedStream($stream, static function (string $reply) use ($boom): void {
            throw $boom;
        });

        $reason = null;
        $promise->then(null, static function (Throwable $e) use (&$reason): void {
            $reason = $e;
        });

        try {
            $stream->write("\x1b[c"); // DA1 answers → $respond runs on the data route
            $this->fail('a throwing respond must still bubble to the emitter on the data route');
        } catch (LogicException $e) {
            $this->assertSame($boom, $e);
        }

        $this->assertSame($boom, $reason, 'the pump must reject with the callback error, not hang unsettled');
        $this->assertSame(0, count($stream->listeners('data')));
        $this->assertSame(0, count($stream->listeners('end')));
        $this->assertSame([], $t->replies(), 'clear-before-delivery must not leave the answer queued for duplicate delivery');

        $settles = 0;
        $promise->then(
            static function () use (&$settles): void {
                $settles++;
            },
            static function () use (&$settles): void {
                $settles++;
            },
        );
        $stream->end();
        $stream->close();
        $this->assertSame(1, $settles, 'the rejection was the single settlement; later events cannot re-settle');
    }

    public function testThrowingRespondOnDataDropsUndeliveredReplies(): void
    {
        // Documented data-route failure semantics: the queue is spliced
        // before delivery, so when the callback dies on reply #1 the #2
        // that was already queued with it is dropped — no resurrection of
        // answers on a pump that just rejected (round-2 review m2).
        $t = Terminal::new(10, 3);
        $stream = new ThroughStream();
        $seen = [];
        $promise = $t->feedStream($stream, static function (string $reply) use (&$seen): void {
            $seen[] = $reply;

            throw new LogicException('dead on the first answer');
        });

        $reason = null;
        $promise->then(null, static function (Throwable $e) use (&$reason): void {
            $reason = $e;
        });

        try {
            $stream->write("\x1b[c\x1b[>c"); // DA1 + DA2 queue in one chunk
            $this->fail('the respond error must bubble to the emitter');
        } catch (LogicException) {
            // expected
        }

        $this->assertCount(1, $seen, 'the first answer was delivered before the throw');
        $this->assertInstanceOf(LogicException::class, $reason);
        $this->assertSame([], $t->replies(), 'the undelivered sibling is dropped with the dead pump');

        // And it stays dropped: a later chunk cannot resurrect it either.
        $stream->write("\x1b[c");
        $this->assertSame([self::DA1], $seen);
        $this->assertSame([], $t->replies());
    }

    public function testThrowingRespondOnEndRejectsWithoutHangingThePromise(): void
    {
        $t = Terminal::new(10, 3);
        $stream = new ThroughStream();
        // Queue the reply over the sync route so the `end` drain is the first
        // $respond call — no write() happens, so `data` never delivers it.
        $t->feed("\x1b[c");

        $boom = new LogicException('respond exploded at end');
        $promise = $t->feedStream($stream, static function (string $tail) use ($boom): void {
            throw $boom;
        });

        $reason = null;
        $promise->then(null, static function (Throwable $e) use (&$reason): void {
            $reason = $e;
        });

        $stream->end();

        $this->assertSame($boom, $reason);
        $this->assertSame(0, count($stream->listeners('data')), 'a dead pump must not linger on the stream');
        $this->assertSame(0, count($stream->listeners('end')));
        $this->assertSame(0, count($stream->listeners('close')));
        $this->assertSame([], $t->replies());
    }

    // ─── interop with the sync surface ─────────────────────────────────────

    public function testSyncRespondChannelIsUnchangedAlongsideAsyncApi(): void
    {
        // The query→reply channel (#1417) keeps its exact contract now that
        // async siblings exist: per-reply callback delivery, queue cleared.
        $t = Terminal::new(10, 3);
        $seen = [];
        $t->feed("\x1b[c", static function (string $reply) use (&$seen): void {
            $seen[] = $reply;
        });

        $this->assertSame([self::DA1], $seen);
        $this->assertSame([], $t->replies());

        // And a later async feed on the same instance sees a clean queue.
        $resolved = 'unset';
        $t->feedAsync('z')->then(static function (string $bytes) use (&$resolved): void {
            $resolved = $bytes;
        });
        $this->assertSame('', $resolved);
    }

    // ─── cancellation handle (wave-7 A1, closes n3) ──────────────────────────
    //
    // Every case here is driven through ThroughStream, whose write/end/close
    // emit synchronously — no event loop run, no timers, so nothing is ever
    // armed on the shared Loop::get(). That is why candy-vt needs no
    // tests/bootstrap.php / LoopPin for this surface: there is no wall-clock
    // bound to keep stable. The "no residue" assertion is on the STREAM
    // (zero listeners after settle), which is exactly what a detached pump owes.

    public function testCancelMidStreamRejectsDetachesAndBlocksReentry(): void
    {
        $t = Terminal::new(10, 3);
        $stream = new ThroughStream();
        $source = CancellationSource::new();
        $promise = $t->feedStream($stream, null, $source->token());

        $stream->write('ab');
        $this->assertSame('a', $t->screen()->cell(0, 0)->grapheme, 'bytes before the cancel landed');
        $this->assertSame('b', $t->screen()->cell(0, 1)->grapheme, 'both pre-cancel bytes landed');

        $reason = null;
        /** @var list<string> $settleLog every promise settlement, in order */
        $settleLog = [];
        $promise->then(
            static function () use (&$settleLog): void {
                $settleLog[] = 'resolve';
            },
            static function (Throwable $e) use (&$reason, &$settleLog): void {
                $reason = $e;
                $settleLog[] = 'reject';
            },
        );

        $source->cancel();

        // Supertype first: assert the BC (catchable as a plain RuntimeException)
        // before the subtype assertion narrows $reason, so the check stays honest
        // rather than an always-true tautology.
        $this->assertInstanceOf(RuntimeException::class, $reason, 'cancel rejects as a RuntimeException (BC)');
        $this->assertInstanceOf(OperationCancelledException::class, $reason, '...and specifically the candy-async cancel signal');
        $this->assertSame(['reject'], $settleLog, 'the cancel was the single settlement');
        $this->assertSame([], $stream->listeners('data'), 'listeners detached — pump left no residue on the stream');
        $this->assertSame([], $stream->listeners('end'));
        $this->assertSame([], $stream->listeners('error'));
        $this->assertSame([], $stream->listeners('close'));

        // Post-cancel writes must NOT re-enter the parser: neither the grid nor
        // the reply queue moves, and no fresh answer is resurrected.
        $stream->write('cdef');
        $this->assertSame('a', $t->screen()->cell(0, 0)->grapheme);
        $this->assertSame('b', $t->screen()->cell(0, 1)->grapheme, 'the pre-cancel write is intact');
        $this->assertSame(' ', $t->screen()->cell(0, 2)->grapheme, 'post-cancel writes never re-enter the detached parser');
        $this->assertSame([], $t->replies(), 'a detached pump does not answer queries');
        $this->assertCount(1, $settleLog, 'the write after cancel cannot re-settle');
    }

    public function testAlreadyCancelledTokenRejectsBeforeAnyData(): void
    {
        $t = Terminal::new(10, 3);
        $stream = new ThroughStream();
        $source = CancellationSource::new();
        $source->cancel(); // cancelled before attach → onCancel fires synchronously

        $reason = null;
        $t->feedStream($stream, null, $source->token())
            ->then(null, static function (Throwable $e) use (&$reason): void {
                $reason = $e;
            });

        $this->assertInstanceOf(OperationCancelledException::class, $reason, 'a pre-cancelled token rejects on return');
        $this->assertSame([], $stream->listeners('data'), 'the synchronously-fired cancel still detached the just-attached listeners');
        $this->assertSame([], $t->replies());

        // The stream is still open and writable — feeding it changes nothing.
        $stream->write('hi');
        $this->assertSame(' ', $t->screen()->cell(0, 0)->grapheme, 'nothing reached the grid');
    }

    public function testCancelIsIdempotent(): void
    {
        $t = Terminal::new(10, 3);
        $stream = new ThroughStream();
        $source = CancellationSource::new();
        $promise = $t->feedStream($stream, null, $source->token());

        $settles = 0;
        $promise->then(null, static function () use (&$settles): void {
            $settles++;
        });

        $source->cancel();
        $source->cancel();
        $source->cancel();

        $this->assertSame(1, $settles, 'N cancels settle the pump exactly once');
        $this->assertSame([], $stream->listeners('data'));
    }

    public function testCancelWinsRaceAgainstNaturalEnd(): void
    {
        $t = Terminal::new(10, 3);
        $stream = new ThroughStream();
        $source = CancellationSource::new();
        $promise = $t->feedStream($stream, null, $source->token());

        $settles = 0;
        $reason = 'unset';
        $value = 'unset';
        $promise->then(
            static function (string $bytes) use (&$settles, &$value): void {
                $settles++;
                $value = $bytes;
            },
            static function (Throwable $e) use (&$settles, &$reason): void {
                $settles++;
                $reason = $e;
            },
        );

        $source->cancel();
        $stream->end();
        $stream->close();

        $this->assertSame(1, $settles, 'settle-once latch: cancel then end is a single settlement');
        $this->assertInstanceOf(OperationCancelledException::class, $reason, 'the first event (cancel) owns the outcome');
        $this->assertSame('unset', $value, 'the later end never resolves the already-cancelled pump');
    }

    public function testCancelAfterNaturalEndIsANoOp(): void
    {
        $t = Terminal::new(10, 3);
        $stream = new ThroughStream();
        $source = CancellationSource::new();
        $promise = $t->feedStream($stream, null, $source->token());

        $settles = 0;
        $resolved = 'unset';
        $rejected = false;
        $promise->then(
            static function (string $bytes) use (&$settles, &$resolved): void {
                $settles++;
                $resolved = $bytes;
            },
            static function () use (&$settles, &$rejected): void {
                $settles++;
                $rejected = true;
            },
        );

        $stream->end(); // natural settle wins first
        $source->cancel(); // arrives after settle — must be inert

        $this->assertSame(1, $settles, 'the natural end was the only settlement');
        $this->assertFalse($rejected, 'a late cancel cannot flip a resolved pump to rejected');
        $this->assertSame('', $resolved);
    }

    public function testNullTokenKeepsThePlainFeedStreamContractByteIdentical(): void
    {
        // Explicitly passing null is the same pump as the two-arg form — the
        // whole existing end/error/close settlement behaviour runs down this branch unchanged.
        $t = Terminal::new(10, 3);
        $stream = new ThroughStream();
        $promise = $t->feedStream($stream, null, null);

        $stream->end();

        $resolved = 'unset';
        $promise->then(static function (string $bytes) use (&$resolved): void {
            $resolved = $bytes;
        });
        $this->assertSame('', $resolved);
        $this->assertSame([], $stream->listeners('data'), 'no cancel closure was ever registered on a null token');
    }
}
