<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\PumpOptions;

final class PumpOptionsTest extends TestCase
{
    public function testDefaultValuesMatchConstants(): void
    {
        $opts = new PumpOptions();

        $this->assertSame(PumpOptions::DEFAULT_CHUNK_BYTES, $opts->chunkBytes);
        $this->assertSame(PumpOptions::DEFAULT_SELECT_TIMEOUT_US, $opts->selectTimeoutUs);
        $this->assertSame(PumpOptions::DEFAULT_FLUSH_DEADLINE_SEC, $opts->flushDeadlineSec);
        $this->assertSame(PumpOptions::DEFAULT_STDIN_EOF_GRACE_SEC, $opts->stdinEofGraceSec);
        $this->assertSame(PumpOptions::DEFAULT_VEOF, $opts->veof);
        $this->assertNull($opts->keepalive);
        $this->assertNull($opts->onIdle);
        $this->assertNull($opts->onSigwinch);
        $this->assertNull($opts->onChildExit);
    }

    public function testWithChunkBytesReturnsNewInstanceOriginalUnchanged(): void
    {
        $original = new PumpOptions();
        $modified = $original->withChunkBytes(8192);

        $this->assertNotSame($original, $modified);
        $this->assertSame(4096, $original->chunkBytes);
        $this->assertSame(8192, $modified->chunkBytes);
        $this->assertSame($original->selectTimeoutUs, $modified->selectTimeoutUs);
        $this->assertSame($original->flushDeadlineSec, $modified->flushDeadlineSec);
    }

    public function testWithKeepaliveAcceptsCallable(): void
    {
        $cb = fn () => null;
        $opts = (new PumpOptions())->withKeepalive($cb);

        $this->assertNotNull($opts->keepalive);
        $this->assertSame($cb, $opts->keepalive);
    }

    public function testWithOnSigwinchAcceptsCallable(): void
    {
        $cb = fn (int $cols, int $rows) => null;
        $opts = (new PumpOptions())->withOnSigwinch($cb);

        $this->assertNotNull($opts->onSigwinch);
        $this->assertSame($cb, $opts->onSigwinch);
    }

    public function testWithOnIdleAcceptsCallable(): void
    {
        $cb = fn () => null;
        $opts = (new PumpOptions())->withOnIdle($cb);

        $this->assertNotNull($opts->onIdle);
        $this->assertSame($cb, $opts->onIdle);
    }

    public function testWithOnChildExitSetsNullExplicitly(): void
    {
        $opts = (new PumpOptions())->withOnChildExit(fn (int $exitCode) => null);
        $this->assertNotNull($opts->onChildExit);

        $optsNull = $opts->withOnChildExit(null);
        $this->assertNull($optsNull->onChildExit);
    }

    public function testAllEightWithMethodsWorkAndReturnCorrectTypes(): void
    {
        $opts = new PumpOptions();

        $byInt = $opts
            ->withChunkBytes(8192)
            ->withSelectTimeoutUs(100000);

        $this->assertSame(8192, $byInt->chunkBytes);
        $this->assertSame(100000, $byInt->selectTimeoutUs);
        $this->assertInstanceOf(PumpOptions::class, $byInt);

        $byFloat = $opts
            ->withFlushDeadlineSec(1.0)
            ->withStdinEofGraceSec(0.5);

        $this->assertSame(1.0, $byFloat->flushDeadlineSec);
        $this->assertSame(0.5, $byFloat->stdinEofGraceSec);
        $this->assertInstanceOf(PumpOptions::class, $byFloat);

        $byString = $opts->withVEOF("\x03");
        $this->assertSame("\x03", $byString->veof);
        $this->assertInstanceOf(PumpOptions::class, $byString);

        $keepaliveCb = fn () => null;
        $sigwinchCb = fn (int $c, int $r) => null;
        $childExitCb = fn (int $ec) => null;

        $byCallable = $opts
            ->withKeepalive($keepaliveCb)
            ->withOnSigwinch($sigwinchCb)
            ->withOnChildExit($childExitCb);

        $this->assertSame($keepaliveCb, $byCallable->keepalive);
        $this->assertSame($sigwinchCb, $byCallable->onSigwinch);
        $this->assertSame($childExitCb, $byCallable->onChildExit);
        $this->assertInstanceOf(PumpOptions::class, $byCallable);
    }

    public function testWithOnSigwinchAcceptsNull(): void
    {
        $opts = (new PumpOptions())->withOnSigwinch(null);
        $this->assertNull($opts->onSigwinch);
    }

    public function testWithOnChildExitAcceptsCallable(): void
    {
        $cb = fn (int $exitCode) => 'handled';
        $opts = (new PumpOptions())->withOnChildExit($cb);

        $this->assertSame($cb, $opts->onChildExit);
    }

    public function testImmutabilityAllPropertiesPreservedAcrossWithCalls(): void
    {
        $keepaliveCb = fn () => null;
        $idleCb = fn () => null;
        $sigwinchCb = fn (int $c, int $r) => null;
        $childExitCb = fn (int $ec) => null;

        $original = new PumpOptions(
            chunkBytes: 4096,
            selectTimeoutUs: 50000,
            flushDeadlineSec: 0.5,
            stdinEofGraceSec: 0.3,
            veof: "\x04",
            keepalive: $keepaliveCb,
            onIdle: $idleCb,
            onSigwinch: $sigwinchCb,
            onChildExit: $childExitCb,
        );

        $modified = $original
            ->withChunkBytes(8192)
            ->withSelectTimeoutUs(100000)
            ->withFlushDeadlineSec(1.0)
            ->withStdinEofGraceSec(0.6)
            ->withVEOF("\x03")
            ->withKeepalive(null)
            ->withOnIdle(null)
            ->withOnSigwinch(null)
            ->withOnChildExit(null);

        $this->assertSame(4096, $original->chunkBytes);
        $this->assertSame(50000, $original->selectTimeoutUs);
        $this->assertSame(0.5, $original->flushDeadlineSec);
        $this->assertSame(0.3, $original->stdinEofGraceSec);
        $this->assertSame("\x04", $original->veof);
        $this->assertSame($keepaliveCb, $original->keepalive);
        $this->assertSame($idleCb, $original->onIdle);
        $this->assertSame($sigwinchCb, $original->onSigwinch);
        $this->assertSame($childExitCb, $original->onChildExit);

        $this->assertSame(8192, $modified->chunkBytes);
        $this->assertSame(100000, $modified->selectTimeoutUs);
        $this->assertSame(1.0, $modified->flushDeadlineSec);
        $this->assertSame(0.6, $modified->stdinEofGraceSec);
        $this->assertSame("\x03", $modified->veof);
        $this->assertNull($modified->keepalive);
        $this->assertNull($modified->onIdle);
        $this->assertNull($modified->onSigwinch);
        $this->assertNull($modified->onChildExit);
    }
}
