<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\Input\PtyInputDecoder;

/**
 * m2 (round lib-fixes-1103): readEvents() answers [] for both idle and
 * EOF; the EOF verdict must be observable, and the chunk door honest.
 */
final class PtyInputDecoderEofTest extends TestCase
{
    /** @param list<string|null> $script */
    private function master(array $script): MasterPty
    {
        return new class ($script) implements MasterPty {
            /** @param list<string|null> $script */
            public function __construct(private array $script) {}

            public function read(int $len = 8192, ?float $timeout = 0.05): ?string
            {
                if ($this->script === []) {
                    return null;
                }
                return \array_shift($this->script);
            }

            public function write(string $bytes): int { return \strlen($bytes); }
            public function resize(int $cols, int $rows): void {}
            public function size(): array { return ['rows' => 24, 'cols' => 80, 'xpix' => 0, 'ypix' => 0]; }
            public function stream(): mixed { return null; }
            public function close(): void {}
            public function isClosed(): bool { return false; }
            public function fd(): int { return 3; }
        };
    }

    public function testIdleNeverLatchesEof(): void
    {
        $decoder = new PtyInputDecoder($this->master([null, null]));
        $this->assertSame([], $decoder->readEvents(0.0));
        $this->assertSame([], $decoder->readEvents(0.0));
        $this->assertFalse($decoder->isEof());
    }

    public function testConfirmedEofLatches(): void
    {
        $decoder = new PtyInputDecoder($this->master(['', null]));
        $this->assertSame([], $decoder->readEvents(0.0));
        $this->assertTrue($decoder->isEof());
        // A later idle read must not unlatch it.
        $decoder->readEvents(0.0);
        $this->assertTrue($decoder->isEof());
    }

    public function testDataBeforeEofStillDecodes(): void
    {
        $decoder = new PtyInputDecoder($this->master(["\x1b[A", '']));
        $events = $decoder->readEvents(0.0);
        $this->assertNotSame([], $events, 'byte payload must reach the decoder');
        $this->assertFalse($decoder->isEof());
        $decoder->readEvents(0.0);
        $this->assertTrue($decoder->isEof());
    }

    public function testResetClearsTheLatch(): void
    {
        $decoder = new PtyInputDecoder($this->master(['']));
        $decoder->readEvents(0.0);
        $this->assertTrue($decoder->isEof());
        $decoder->reset();
        $this->assertFalse($decoder->isEof());
    }

    public function testConstructorRefusesNonPositiveChunk(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('readChunk must be >= 1, got 0');
        new PtyInputDecoder($this->master([]), readChunk: 0);
    }
}
