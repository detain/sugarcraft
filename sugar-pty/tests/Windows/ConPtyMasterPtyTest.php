<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Windows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\PtyException;
use SugarCraft\Pty\Windows\ConPtyMasterPty;
use SugarCraft\Pty\Windows\ConPtySystem;

/**
 * The master adapter's byte contract: read() answers null on timeout and '' on
 * EOF exactly like PosixMasterPty (callers switch backends without switching
 * their pump logic), writes count, resize caches what the host cannot query,
 * and close() retires every handle the system minted — once.
 */
final class ConPtyMasterPtyTest extends TestCase
{
    /**
     * The output pipe's READ end is always the fifth handle issued by a fresh
     * double on a bare open() (read/write, read/write, console) — the number
     * the master must peek and read from.
     */
    private const OUTPUT_READ_HANDLE = 102;

    private FakeConPtyApi $api;

    private ConPtyMasterPty $master;

    protected function setUp(): void
    {
        $this->api = new FakeConPtyApi();
        $this->master = (new ConPtySystem($this->api))->open(80, 24)->master();
    }

    public function testReadHandsBackQueuedBytesAndAsksForTheRequestedLength(): void
    {
        $this->api->readQueue[] = 'hello conpty';

        self::assertSame('hello conpty', $this->master->read(8192, 1.0));
        self::assertSame(
            ['readFile(' . self::OUTPUT_READ_HANDLE . ',8192)'],
            $this->api->callsMatching('readFile('),
        );
    }

    public function testReadNeverOverdeliversBeyondItsLength(): void
    {
        $this->api->readQueue[] = 'abcdef';

        self::assertSame('abc', $this->master->read(3, 1.0));
    }

    public function testReadAnswersEmptyStringWhenThePeerIsGone(): void
    {
        // Broken pipe is EOF, not a timeout: the ''-vs-null polarity is the
        // contract every reader loop branches on, so a dead output end must
        // end the stream with '' even though a timeout shape was offered.
        $this->api->closeHandle(self::OUTPUT_READ_HANDLE);

        self::assertSame('', $this->master->read(1024, 0.05));
    }

    public function testReadAnswersNullWhenNothingArrivesBeforeTheDeadline(): void
    {
        $started = \microtime(true);

        self::assertNull($this->master->read(1024, 0.02));
        self::assertGreaterThanOrEqual(1, \count($this->api->callsMatching('peekNamedPipe')));
        // The ceiling is honoured on something like its own scale — a 20 ms
        // poll must not turn into a hang nor a hot spin into seconds.
        self::assertLessThan(1.0, \microtime(true) - $started);
    }

    public function testReadRejectsNonPositiveLengths(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('read length must be > 0; got 0');

        $this->master->read(0);
    }

    public function testReadRejectsNegativeTimeouts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('timeout must be >= 0');

        $this->master->read(1024, -0.5);
    }

    public function testWriteReturnsTheByteCountTheBindingAccepted(): void
    {
        self::assertSame(4, $this->master->write('abcd'));
        self::assertSame(
            ['writeFile(101,4 bytes)'],
            $this->api->callsMatching('writeFile('),
        );
    }

    public function testResizeRefreshesTheSizeCache(): void
    {
        $this->master->resize(120, 40);

        self::assertSame(['cols' => 120, 'rows' => 40, 'xpix' => 0, 'ypix' => 0], $this->master->size());
    }

    public function testSizeCarriesZeroPixelFieldsBecauseTheBindingHasNoQueryForThem(): void
    {
        // size() is honest about its own cache: no pixel geometry was ever
        // bound, so the pixel pair is structurally zero, never guessed.
        self::assertSame(['cols' => 80, 'rows' => 24, 'xpix' => 0, 'ypix' => 0], $this->master->size());
    }

    public function testStreamAndFdRefuseBecauseConPTYHasNeitherSurface(): void
    {
        // The Pump/ReactPump/MultiPump consequence the README now documents:
        // the contract's escape hatches are closed HERE, loudly.
        $this->expectException(PtyException::class);
        $this->expectExceptionMessageMatches('/no PHP stream/');

        $this->master->stream();
    }

    public function testFdRefuseBecauseWin32HandlesAreNotDescriptors(): void
    {
        $this->expectException(PtyException::class);
        $this->expectExceptionMessageMatches('/no POSIX file descriptor/');

        $this->master->fd();
    }

    public function testCloseRetiresTheConsoleAndAllFourPipeEndsExactlyOnce(): void
    {
        $this->master->close();

        self::assertTrue($this->api->everythingIsClosed());

        $transcriptDepth = \count($this->api->transcript);
        $this->master->close();
        self::assertCount($transcriptDepth, $this->api->transcript, 'second close() must be inert');
    }

    public function testIsClosedFlipsOnlyAfterClose(): void
    {
        self::assertFalse($this->master->isClosed());
        $this->master->close();
        self::assertTrue($this->master->isClosed());
    }

    public function testDataPlaneAfterCloseRefuses(): void
    {
        $this->master->close();

        foreach ([
            fn (): mixed => $this->master->read(10, 0.01),
            fn (): mixed => $this->master->write('x'),
            fn (): mixed => $this->master->resize(90, 30),
        ] as $index => $attempt) {
            try {
                $attempt();
                self::fail('operation ' . $index . ' must refuse a closed master');
            } catch (PtyException $e) {
                self::assertStringContainsString('closed', $e->getMessage());
            }
        }
    }
}
