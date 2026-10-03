<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Support\ForkedChild;
use SugarCraft\Crush\Support\SubAgentActivityRelay;

/**
 * {@see SubAgentActivityRelay}'s wire, driven in one process: the writer and
 * reader ends are the same socket pair either side of a fork, so the framing,
 * the partial-frame buffer and the fail-quiet arms are all observable here.
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
            $emit = $relay->childEmitter();
            $emit(new SubAgentActivity(SubAgentActivity::OP_STARTED, 'id-1', 'reviewer', 'look over candy-core', 1, ''));
            $emit(new SubAgentActivity(SubAgentActivity::OP_PROGRESS, 'id-1', 'reviewer', '', 2, "-> Read\nthinking: …"));
            $emit(new SubAgentActivity(SubAgentActivity::OP_FINISHED, 'id-1', 'reviewer', '', 3, 'report'));
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

    public function testAPartialFrameWaitsForTheRestOfItsBytes(): void
    {
        [$reader, $writer] = self::rawPair();
        $relay = self::wrap($reader, $writer);

        $body = serialize(['op' => 'started', 'id' => 'id-2', 'name' => 'coder', 'task' => 't', 'seq' => 1, 'tail' => '']);
        $frame = pack('N', strlen($body)) . $body;
        fwrite($writer, substr($frame, 0, 7));

        $this->assertSame([], $relay->drain(), 'half a frame is not a beat');

        fwrite($writer, substr($frame, 7));
        $beats = $relay->drain();

        $this->assertCount(1, $beats);
        $this->assertSame('id-2', $beats[0]->id);

        $relay->close();
    }

    public function testACorruptFrameKeepsTheWholeBeatsBeforeItAndDropsTheRest(): void
    {
        [$reader, $writer] = self::rawPair();
        $relay = self::wrap($reader, $writer);

        $good = serialize(['op' => 'progress', 'id' => 'id-3', 'name' => 'coder', 'task' => '', 'seq' => 2, 'tail' => 'x']);
        $bad = serialize(['op' => 'exploded', 'id' => 'id-3']);
        $later = $good;
        fwrite($writer, pack('N', strlen($good)) . $good . pack('N', strlen($bad)) . $bad . pack('N', strlen($later)) . $later);

        $beats = $relay->drain();

        $this->assertCount(1, $beats, 'only the frame before the corruption survives');
        $this->assertSame('x', $beats[0]->tail);

        fwrite($writer, pack('N', strlen($good)) . $good);
        $this->assertSame([], $relay->drain(), 'a broken stream stays broken: nothing after it can be trusted');

        $relay->close();
    }

    public function testAWriteAfterTheReaderIsGoneIsDroppedQuietly(): void
    {
        $relay = self::relay();
        $emit = $relay->childEmitter();
        $relay->close();

        $emit(new SubAgentActivity(SubAgentActivity::OP_STARTED, 'id-4', 'coder', 't', 1, ''));

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
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
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
