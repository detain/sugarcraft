<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Expect;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\Expect;

/**
 * Construction-door pins: the numeric knobs that Expect's scanning and
 * trimming math assume are positive are refused at the constructor now,
 * so a hand-built `new Expect(...)` cannot smuggle in the corruption
 * shapes (negative searchWindow tail-truncating the scan buffer, zero
 * maxBuffer collapsing the trim floor) that used to fail silently or at
 * a distance from the mistake.
 */
final class ExpectValidationTest extends TestCase
{
    /**
     * Minimal fake used only on the legacy (non-select) send path and the
     * reading loops. Deliberately unlike ExpectTest's fixture: this one
     * exposes a scripted tail of reads after an initial priming chunk.
     */
    private static function stub(string $prime = '', array $tail = []): MasterPty
    {
        return new class($prime, $tail) implements MasterPty
        {
            public int $writtenBytes = 0;

            public function __construct(private string $primed, private array $tail) {}

            public function read(int $len = 8192, ?float $timeout = 0.05): ?string
            {
                if ($this->primed !== '') {
                    $out = $this->primed;
                    $this->primed = '';

                    return $out;
                }
                if ($this->tail !== []) {
                    return (string) \array_shift($this->tail);
                }
                if ($timeout !== null) {
                    \usleep((int) ($timeout * 1_000_000));
                }

                return null;
            }

            public function write(string $bytes): int
            {
                $this->writtenBytes += \strlen($bytes);

                return \strlen($bytes);
            }

            public function resize(int $cols, int $rows): void {}

            public function size(): array
            {
                return ['rows' => 24, 'cols' => 80, 'xpix' => 0, 'ypix' => 0];
            }

            public function stream(): mixed
            {
                throw new \LogicException('This stub carries no real stream');
            }

            public function close(): void {}

            public function isClosed(): bool
            {
                return false;
            }

            public function fd(): int
            {
                return -1;
            }
        };
    }

    public function testDirectConstructionStillRefusesZeroReadChunk(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('readChunk must be > 0, got 0');

        new Expect(self::stub(), readChunk: 0);
    }

    public function testNegativeMaxBufferIsRefusedAtTheDoor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxBuffer must be > 0 or null, got -4');

        new Expect(self::stub(), maxBuffer: -4);
    }

    public function testNegativeSearchWindowIsRefusedAtTheDoor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('searchWindow must be > 0 or null, got -5');

        new Expect(self::stub(), searchWindow: -5);
    }

    public function testNegativeTimeoutIsRefusedOnEveryRacingEntry(): void
    {
        // A negative budget would otherwise mint "timed out after -5.000s"
        // messages and skip every wait without ever observing the stream.
        foreach ([
            'expect' => static fn (Expect $e): Expect => $e->expect('x', -5.0),
            'expectAny' => static fn (Expect $e): Expect => $e->expectAny(['x'], -5.0),
            'expectPattern' => static fn (Expect $e): Expect => $e->expectPattern('/x/', -5.0),
            'expectEof' => static fn (Expect $e): Expect => $e->expectEof(-5.0),
        ] as $name => $call) {
            $e = new Expect(self::stub());
            try {
                $call($e);
                self::fail("{$name} accepted a negative timeoutSec");
            } catch (\InvalidArgumentException $reason) {
                self::assertSame('timeoutSec must be >= 0; got -5.0', $reason->getMessage());
            }
        }
    }

    public function testZeroTimeoutMatchesOnlyAlreadyBufferedBytes(): void
    {
        // >= 0, not > 0: a zero budget keeps its door open and means
        // "scan the buffer, never wait". The loop scans buffered bytes
        // BEFORE the deadline check, so a primed buffer matches instantly;
        // bytes still sitting behind the master are not chased.
        $warm = new Expect(self::stub(prime: 'IGNORED'), buffer: 'prompt> ');
        $got = $warm->expectAny(['prompt> '], 0.0);

        self::assertSame('prompt> ', $got->lastMatch);

        $cold = new Expect(self::stub(prime: 'prompt> '));
        try {
            $cold->expectAny(['prompt> '], 0.0);
            self::fail('zero budget must not read the master before its verdict');
        } catch (\SugarCraft\Pty\Exception\ExpectTimeoutException $reason) {
            self::assertStringContainsString('timed out after 0.000s', $reason->getMessage());
        }
    }

    public function testFluentSettersRouteThroughTheSameDoor(): void
    {
        $expect = Expect::on(self::stub());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('searchWindow must be > 0 or null, got -1');

        $expect->withSearchWindow(-1);
    }

    public function testExpectEofTreatsEofAsSuccessAndNeverRaisesTheEofSignal(): void
    {
        // The docblock used to promise an ExpectEofException throw here;
        // the body has always treated EOF as the SUCCESS return. This pin
        // locks the honest shape: pre-EOF bytes land in `before`,
        // `lastMatch` is null, no exception class is involved.
        $expect = Expect::on(self::stub(prime: 'farewell', tail: ['']));

        $done = $expect->expectEof(2.0);

        self::assertSame('farewell', $done->before);
        self::assertNull($done->lastMatch);
        self::assertSame('', $done->buffer);
    }

    public function testMutatePreservesEveryUntouchedField(): void
    {
        // M22 refactor guard: the fluent family now rebuilds via
        // get_object_vars merging. If any property name ever drifts from
        // its constructor parameter, this round trip loses state or the
        // named argument fatals.
        $stub = self::stub();
        $base = new Expect($stub, buffer: 'carry-me', readChunk: 64, maxBuffer: 512, searchWindow: 32);
        $expect = $base->withMaxBuffer(1024);

        self::assertSame('carry-me', $expect->buffer);
        self::assertSame(64, $expect->readChunk);
        self::assertSame(1024, $expect->maxBuffer);
        self::assertSame(32, $expect->searchWindow);
        self::assertNull($expect->lastMatch);
        // The original is untouched (immutability).
        self::assertSame(512, $base->maxBuffer);
        self::assertSame(64, $base->readChunk);
    }

    public function testSendOnAStreamlessFakeStillWalksTheLegacyLoop(): void
    {
        // The M8 select arm is gated on PosixMasterPty; fixtures that
        // merely satisfy the contract keep the historical write-and-
        // backoff path so fakes without streams cannot regress.
        $stub = self::stub();
        $expect = Expect::on($stub);

        $after = $expect->send("abc\n");

        self::assertSame(4, $stub->writtenBytes);
        self::assertSame('', $after->buffer);
    }
}
