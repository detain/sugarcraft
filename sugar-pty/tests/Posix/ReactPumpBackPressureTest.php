<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\Posix\ReactPump;
use SugarCraft\Pty\PumpOptions;

/**
 * {@see ReactPump} holds stdin while the master write is short — the
 * input-side port of the law the sink side already obeys.
 *
 * Before this pin, onStdinReadable kept pulling stdin while a parked
 * remainder waited for the master to accept it, so every readable tick
 * appended a fresh chunk to pendingStdin: a fast producer against a slow
 * master grew the park without bound in RAM (PosixPump never does this —
 * it drops stdin from the select read set while draining). The pump now
 * pauses the stdin read watcher when writeToMaster parks and re-arms it
 * from onMasterWritable once the park drains.
 *
 * The pin drives a fake master that refuses two of every three writes
 * against a 20-chunk tmpfile producer and asserts both halves of the
 * contract: BOUNDED (the park never holds more than one chunk across the
 * whole drain) and COMPLETE (every byte, then VEOF, reaches the master in
 * order — pausing must not drop or reorder anything).
 */
final class ReactPumpBackPressureTest extends TestCase
{
    use \SugarCraft\Pty\Tests\Support\RequiresPtyCapability;

    private const CHUNK_BYTES = 256;
    private const CHUNKS = 20;

    public function testFastStdinAgainstSlowMasterKeepsTheParkBoundedAndDeliversEverything(): void
    {
        $loop = new StreamSelectLoop();
        [$masterEnd, $peerEnd] = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        \stream_set_blocking($masterEnd, false);
        $master = new SlowWriteMasterPty($masterEnd, acceptEvery: 3);

        // tmpfile(), not php://temp: stream_select() forces a stream cast to
        // an fd, and a memory-buffered php://temp LOSES its unread buffered
        // bytes during that conversion (the 'buffered data lost during
        // stream conversion' warnings this choice provably avoids).
        $stdin = \tmpfile();
        $payload = '';
        for ($i = 0; $i < self::CHUNKS; $i++) {
            // Distinguishable chunks so delivery order is provable.
            $chunk = \str_pad("c{$i}", self::CHUNK_BYTES, random_bytes(self::CHUNK_BYTES - 4));
            \fwrite($stdin, $chunk);
            $payload .= $chunk;
        }
        \rewind($stdin);

        $opts = (new PumpOptions())
            ->withChunkBytes(self::CHUNK_BYTES)
            ->withStdinEofGraceSec(0.2);

        $pump = new ReactPump($loop);
        $parkProbe = $this->parkLengthProbe($pump);

        $maxPark = 0;
        $samples = 0;
        $done = false;
        $completed = false;
        $watchdog = $loop->addTimer(10.0, function () use ($loop): void {
            $loop->stop();
        });
        $tick = $loop->addPeriodicTimer(0.0002, function () use (
            $pump,
            $loop,
            $master,
            $parkProbe,
            $payload,
            &$maxPark,
            &$samples,
            &$completed,
            $opts,
        ): void {
            $parked = $parkProbe();
            $samples++;
            $maxPark = \max($maxPark, $parked);
            if (\strlen($master->delivered) === \strlen($payload) + \strlen($opts->veof)) {
                $completed = true;
                $done = true;
                $pump->stop();
                $loop->stop();
            }
        });

        $pump->start($master, $stdin, null, null, $opts);

        $loop->run();

        $loop->cancelTimer($watchdog);
        $loop->cancelTimer($tick);

        // Drain whatever the peer end holds so the master end is not
        // blocked by our own unread socket; the delivered record is what
        // we assert against.
        \fclose($peerEnd);

        // Deterministic proof the pin actually exercised the park/drain
        // cycle: 21 payloads (20 chunks + VEOF) at 2 refusals each = 63
        // write attempts minimum.
        $this->assertGreaterThanOrEqual(60, $master->writeCalls, 'the slow-master cycle barely ran — the pin proved nothing');
        $this->assertTrue($completed, 'the pump never delivered all of stdin + VEOF within the watchdog');
        $this->assertLessThanOrEqual(
            self::CHUNK_BYTES,
            $maxPark,
            "pendingStdin grew past one chunk (max {$maxPark} B over {$samples} samples) — stdin kept "
            . 'being pulled while the master owed bytes: the unbounded-park regression.',
        );
        $expected = $payload . $opts->veof;
        $this->assertSame(
            \strlen($expected),
            \strlen($master->delivered),
            'delivered byte count differs — bytes were dropped or duplicated',
        );
        $this->assertSame($expected, \substr($master->delivered, 0, \strlen($expected)), 'delivery order broken');

        \fclose($masterEnd);
        \fclose($stdin);
    }

    /** Reflection seam: the byte length of the live park, sampled each tick. */
    private function parkLengthProbe(ReactPump $pump): callable
    {
        $prop = new \ReflectionProperty(ReactPump::class, 'pendingStdin');
        $prop->setAccessible(true);
        return static fn (): int => \strlen((string) $prop->getValue($pump));
    }
}

/**
 * MasterPty double whose write() would-blocks two calls out of every
 * $acceptEvery — exactly the short-write regime the park exists for.
 * read() is permanently would-block (null): the input side is the subject.
 */
final class SlowWriteMasterPty implements MasterPty
{
    /** @var int */
    public int $writeCalls = 0;

    /** @var string every byte the pump finally got through */
    public string $delivered = '';

    /** @param resource $stream */
    public function __construct(private $stream, private int $acceptEvery)
    {
    }

    public function read(int $len = 8192, ?float $timeout = null): ?string
    {
        return null;
    }

    public function write(string $bytes): int
    {
        $this->writeCalls++;
        if ($this->writeCalls % $this->acceptEvery !== 0) {
            return 0; // would-block
        }
        // A real would-block master unblocks on kernel scheduling, not on
        // the loop's next spin; 200 us per accept stretches the 21-payload
        // drain to ~4 ms so the 0.2 ms park sampler sees real history.
        \usleep(200);
        $this->delivered .= $bytes;
        return \strlen($bytes);
    }

    public function resize(int $cols, int $rows): void
    {
    }

    public function size(): array
    {
        return ['cols' => 80, 'rows' => 24, 'xpix' => 0, 'ypix' => 0];
    }

    public function stream(): mixed
    {
        return $this->stream;
    }
    /**
     * MasterPty contract member (PTY-13). Scripted fakes hold no real
     * blocking mode — nothing they return depends on it.
     */
    public function setBlocking(bool $blocking): void
    {
    }


    public function fd(): int
    {
        return -1;
    }

    public function close(): void
    {
    }

    public function isClosed(): bool
    {
        return false;
    }
}
