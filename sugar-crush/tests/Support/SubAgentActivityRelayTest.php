<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Support\ForkedChild;
use SugarCraft\Crush\Support\SubAgentActivityRelay;
use SugarCraft\Crush\Tools\DatagramActivitySink;

/**
 * {@see SubAgentActivityRelay}'s wire, driven in one process: the writer and
 * reader ends are the same datagram pair either side of a fork, so the
 * one-beat-per-datagram framing, the open-run bookkeeping and the fail-quiet
 * arms are all observable here.
 */
final class SubAgentActivityRelayTest extends TestCase
{
    use ReapsForkedChildrenTrait;

    protected function tearDown(): void
    {
        $this->reapTrackedForkedChildren();

        parent::tearDown();
    }

    public function testBeatsRoundTripWholeAndInOrderAcrossAFork(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('The relay crosses a fork, which requires ext-pcntl.');
        }

        $relay = self::relay();
        $pid = $this->forkTracked();
        if ($pid === -1) {
            $relay->close();
            $this->markTestSkipped('fork(2) failed.');
        }
        if ($pid === 0) {
            $sink = $relay->childSink();
            $sink->emit(new SubAgentActivity(SubAgentActivity::OP_STARTED, 'id-1', 'reviewer', 'look over candy-core', 1, ''));
            $sink->emit(new SubAgentActivity(SubAgentActivity::OP_PROGRESS, 'id-1', 'reviewer', '', 2, "-> Read\nthinking: …"));
            $sink->emit(new SubAgentActivity(SubAgentActivity::OP_FINISHED, 'id-1', 'reviewer', '', 3, 'report'));
            ForkedChild::exitNow(0);
        }

        $relay->becomeReader();
        $status = 0;
        pcntl_waitpid($pid, $status);
        $beats = $relay->drain();

        $this->assertSame(
            [SubAgentActivity::OP_STARTED, SubAgentActivity::OP_PROGRESS, SubAgentActivity::OP_FINISHED],
            array_map(static fn (SubAgentActivity $b): string => $b->op, $beats),
        );
        $this->assertSame('look over candy-core', $beats[0]->task);
        $this->assertSame("-> Read\nthinking: …", $beats[1]->tail);
        $this->assertSame([1, 2, 3], array_map(static fn (SubAgentActivity $b): int => $b->seq, $beats));
        $this->assertSame([], $relay->drain(), 'a drained relay has nothing left to hand out');

        $relay->close();
    }

    public function testAnEmptyRelayDrainsToNothingWithoutBlocking(): void
    {
        [$reader, $writer] = self::rawPair();
        $relay = self::wrap($reader, $writer);

        $started = microtime(true);
        $this->assertSame([], $relay->drain());
        $this->assertLessThan(1.0, microtime(true) - $started);

        $relay->close();
    }

    public function testACorruptDatagramCostsOnlyItselfNotTheBeatsAfterIt(): void
    {
        [$reader, $writer] = self::rawPair();
        $relay = self::wrap($reader, $writer);

        $good = serialize(['op' => 'progress', 'id' => 'id-3', 'name' => 'coder', 'task' => '', 'seq' => 2, 'tail' => 'x']);
        $bad = serialize(['op' => 'exploded', 'id' => 'id-3']);
        $later = serialize(['op' => 'progress', 'id' => 'id-3', 'name' => 'coder', 'task' => '', 'seq' => 3, 'tail' => 'y']);
        stream_socket_sendto($writer, $good);
        stream_socket_sendto($writer, $bad);
        stream_socket_sendto($writer, 'not serialized at all');
        stream_socket_sendto($writer, $later);

        $beats = $relay->drain();

        $this->assertSame(['x', 'y'], array_map(static fn (SubAgentActivity $b): string => $b->tail, $beats), 'a datagram is whole or skipped; the stream never breaks');

        $relay->close();
    }

    public function testABeatOverTheDatagramCeilingIsDroppedAtTheWriterAndLaterBeatsStillArrive(): void
    {
        [$reader, $writer] = self::rawPair();
        $reading = self::wrap($reader, $writer);
        $sink = DatagramActivitySink::over($writer);

        $sink->emit(new SubAgentActivity(SubAgentActivity::OP_PROGRESS, 'id-5', 'coder', '', 1, str_repeat('z', DatagramActivitySink::MAX_DATAGRAM_BYTES)));
        $sink->emit(new SubAgentActivity(SubAgentActivity::OP_PROGRESS, 'id-5', 'coder', '', 2, 'small'));

        $beats = $reading->drain();

        $this->assertCount(1, $beats);
        $this->assertSame('small', $beats[0]->tail);

        $reading->close();
    }

    public function testUnfinishedListsEveryRunSeenStartingButNeverFinishing(): void
    {
        [$reader, $writer] = self::rawPair();
        $relay = self::wrap($reader, $writer);
        $sink = DatagramActivitySink::over($writer);

        $sink->emit(new SubAgentActivity(SubAgentActivity::OP_STARTED, 'done', 'coder', 't', 1, ''));
        $sink->emit(new SubAgentActivity(SubAgentActivity::OP_STARTED, 'died', 'reviewer', 't', 1, ''));
        $sink->emit(new SubAgentActivity(SubAgentActivity::OP_PROGRESS, 'died', 'reviewer', '', 2, '-> Read', 120));
        $sink->emit(new SubAgentActivity(SubAgentActivity::OP_FINISHED, 'done', 'coder', '', 2, 'report'));
        $relay->drain();

        $open = $relay->unfinished();

        $this->assertCount(1, $open);
        $this->assertSame('died', $open[0]->id);
        $this->assertSame(2, $open[0]->seq, 'the LATEST beat is kept, so a synthesised close continues its seq');
        $this->assertSame(120, $open[0]->tokensUsed);

        $relay->close();
    }

    public function testAWriteAfterTheReaderIsGoneIsDroppedQuietly(): void
    {
        $relay = self::relay();
        $sink = $relay->childSink();
        $relay->close();

        $sink->emit(new SubAgentActivity(SubAgentActivity::OP_STARTED, 'id-4', 'coder', 't', 1, ''));

        $this->assertSame([], $relay->drain(), 'a closed relay never throws on the observation channel');
    }

    private static function relay(): SubAgentActivityRelay
    {
        $relay = SubAgentActivityRelay::open();
        if ($relay === null) {
            self::markTestSkipped('stream_socket_pair() is unavailable here.');
        }

        return $relay;
    }

    /**
     * @return array{0: resource, 1: resource}
     */
    private static function rawPair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_DGRAM, 0);
        if ($pair === false) {
            self::markTestSkipped('stream_socket_pair() is unavailable here.');
        }

        return $pair;
    }

    /**
     * A reader-side relay over a pair whose write end the test keeps, so it
     * can put arbitrary bytes on the wire.
     *
     * @param resource $reader
     * @param resource $writer
     */
    private static function wrap($reader, $writer): SubAgentActivityRelay
    {
        $relay = (new \ReflectionClass(SubAgentActivityRelay::class))->newInstanceWithoutConstructor();
        $set = \Closure::bind(static function (SubAgentActivityRelay $relay, $reader): void {
            $relay->reader = $reader;
            $relay->writer = null;
        }, null, SubAgentActivityRelay::class);
        $set($relay, $reader);
        $relay->becomeReader();

        return $relay;
    }
}
