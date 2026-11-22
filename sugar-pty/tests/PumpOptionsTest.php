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
        $this->assertNull($opts->onIdle);
        $this->assertNull($opts->onResize);
        $this->assertNull($opts->onChildExit);
    }

    public function testSshDefaultReturnsExpectedDtoShape(): void
    {
        $opts = PumpOptions::sshDefault();

        $this->assertInstanceOf(PumpOptions::class, $opts);
        $this->assertSame(PumpOptions::DEFAULT_CHUNK_BYTES, $opts->chunkBytes);
        $this->assertSame(PumpOptions::DEFAULT_SELECT_TIMEOUT_US, $opts->selectTimeoutUs);
        $this->assertSame(PumpOptions::DEFAULT_FLUSH_DEADLINE_SEC, $opts->flushDeadlineSec);
        $this->assertSame(PumpOptions::DEFAULT_STDIN_EOF_GRACE_SEC, $opts->stdinEofGraceSec);
        $this->assertSame(PumpOptions::DEFAULT_VEOF, $opts->veof);
        $this->assertNull($opts->onIdle);
        $this->assertNull($opts->onResize);
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

    public function testWithOnResizeAcceptsCallable(): void
    {
        $cb = fn (int $cols, int $rows) => null;
        $opts = (new PumpOptions())->withOnResize($cb);

        $this->assertNotNull($opts->onResize);
        $this->assertSame($cb, $opts->onResize);
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

        $idleCb = fn () => null;
        $sigwinchCb = fn (int $c, int $r) => null;
        $childExitCb = fn (int $ec) => null;

        $byCallable = $opts
            ->withOnIdle($idleCb)
            ->withOnResize($sigwinchCb)
            ->withOnChildExit($childExitCb);

        $this->assertSame($idleCb, $byCallable->onIdle);
        $this->assertSame($sigwinchCb, $byCallable->onResize);
        $this->assertSame($childExitCb, $byCallable->onChildExit);
        $this->assertInstanceOf(PumpOptions::class, $byCallable);
    }

    public function testWithOnResizeAcceptsNull(): void
    {
        $opts = (new PumpOptions())->withOnResize(null);
        $this->assertNull($opts->onResize);
    }

    public function testWithOnChildExitAcceptsCallable(): void
    {
        $cb = fn (int $exitCode) => 'handled';
        $opts = (new PumpOptions())->withOnChildExit($cb);

        $this->assertSame($cb, $opts->onChildExit);
    }

    public function testImmutabilityAllPropertiesPreservedAcrossWithCalls(): void
    {
        $idleCb = fn () => null;
        $sigwinchCb = fn (int $c, int $r) => null;
        $childExitCb = fn (int $ec) => null;

        $original = new PumpOptions(
            chunkBytes: 4096,
            selectTimeoutUs: 50000,
            flushDeadlineSec: 0.5,
            stdinEofGraceSec: 0.3,
            veof: "\x04",
            onIdle: $idleCb,
            onResize: $sigwinchCb,
            onChildExit: $childExitCb,
        );

        $modified = $original
            ->withChunkBytes(8192)
            ->withSelectTimeoutUs(100000)
            ->withFlushDeadlineSec(1.0)
            ->withStdinEofGraceSec(0.6)
            ->withVEOF("\x03")
            ->withOnIdle(null)
            ->withOnResize(null)
            ->withOnChildExit(null);

        $this->assertSame(4096, $original->chunkBytes);
        $this->assertSame(50000, $original->selectTimeoutUs);
        $this->assertSame(0.5, $original->flushDeadlineSec);
        $this->assertSame(0.3, $original->stdinEofGraceSec);
        $this->assertSame("\x04", $original->veof);
        $this->assertSame($idleCb, $original->onIdle);
        $this->assertSame($sigwinchCb, $original->onResize);
        $this->assertSame($childExitCb, $original->onChildExit);

        $this->assertSame(8192, $modified->chunkBytes);
        $this->assertSame(100000, $modified->selectTimeoutUs);
        $this->assertSame(1.0, $modified->flushDeadlineSec);
        $this->assertSame(0.6, $modified->stdinEofGraceSec);
        $this->assertSame("\x03", $modified->veof);
        $this->assertNull($modified->onIdle);
        $this->assertNull($modified->onResize);
        $this->assertNull($modified->onChildExit);
    }

    // ------------------------------------------------------------------
    // E717 — pumpDeadlineUs: the opt-in caller-bound on the options DTO.
    // ------------------------------------------------------------------

    public function testPumpDeadlineUsDefaultsToNullUnbounded(): void
    {
        // The contract: NO default deadline. A fresh options object (and
        // the ssh preset) must leave the pump unbounded exactly as before
        // E717 — a default of anything here would silently kill healthy
        // interactive sessions.
        $this->assertNull((new PumpOptions())->pumpDeadlineUs);
        $this->assertNull(PumpOptions::sshDefault()->pumpDeadlineUs);
    }

    public function testWithPumpDeadlineUsSetsCarriesAndDetaches(): void
    {
        $original = new PumpOptions();
        $bounded = $original->withPumpDeadlineUs(250_000);

        $this->assertNotSame($original, $bounded);
        $this->assertNull($original->pumpDeadlineUs, 'with* must not mutate');
        $this->assertSame(250_000, $bounded->pumpDeadlineUs);
        $this->assertNull($bounded->withPumpDeadlineUs(null)->pumpDeadlineUs);
    }

    public function testPumpDeadlineUsSurvivesEveryOtherSetter(): void
    {
        // Propagation census: every with*() rebuilds via `new self(...)`,
        // so any setter that forgets to carry pumpDeadlineUs silently
        // un-bounds a caller's pump. One chain through ALL of them,
        // asserting the value at each hop.
        $opts = (new PumpOptions())->withPumpDeadlineUs(123_456);

        $hops = [
            'chunkBytes'      => $opts->withChunkBytes(8192),
            'selectTimeoutUs' => $opts->withSelectTimeoutUs(10_000),
            'flushDeadline'   => $opts->withFlushDeadlineSec(0.25),
            'stdinEofGrace'   => $opts->withStdinEofGraceSec(0.1),
            'veof'            => $opts->withVEOF("\x03"),
            'onIdle'          => $opts->withOnIdle(static fn () => null),
            'onResize'      => $opts->withOnResize(static fn (int $c, int $r) => null),
            'onChildExit'     => $opts->withOnChildExit(static fn (int $e) => null),
            'recorder'        => $opts->withRecorder(null),
        ];

        foreach ($hops as $name => $hopped) {
            $this->assertSame(
                123_456,
                $hopped->pumpDeadlineUs,
                "with{$name}() dropped pumpDeadlineUs — the bound would silently vanish",
            );
        }
    }

    public function testPumpDeadlineUsRejectsNonPositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PumpOptions(pumpDeadlineUs: 0);
    }

    public function testWithPumpDeadlineUsRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PumpOptions())->withPumpDeadlineUs(-1);
    }

    // ─── P1-8e: the deprecated keepalive and onSigwinch spellings are gone ───

    public function testTheDeprecatedKeepaliveAndOnSigwinchSpellingsAreRemoved(): void
    {
        // onIdle is the one idle hook and onResize the one resize hook; the
        // removed aliases must not come back as a second door to either.
        $this->assertFalse(\method_exists(PumpOptions::class, 'withKeepalive'));
        $this->assertFalse(\method_exists(PumpOptions::class, 'withOnSigwinch'));
        $this->assertFalse(\property_exists(PumpOptions::class, 'keepalive'));

        $params = \array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(PumpOptions::class, '__construct'))->getParameters(),
        );
        $this->assertNotContains('keepalive', $params);
        $this->assertNotContains('onSigwinch', $params);
    }

    public function testAPositionalCallPastVeofStillBindsEachHookToItsOwnSlot(): void
    {
        // The removed keepalive slot is reserved, so a positional caller
        // written against the old signature is not shifted one hook left.
        $onIdle = static fn (): null => null;
        $onResize = static fn (int $c, int $r): null => null;
        $onChildExit = static fn (int $code): null => null;

        $opts = new PumpOptions(4096, 50000, 0.5, 0.3, "\x04", null, $onIdle, $onResize, $onChildExit, null, 1000);

        $this->assertSame($onIdle, $opts->onIdle);
        $this->assertSame($onResize, $opts->onResize);
        $this->assertSame($onChildExit, $opts->onChildExit);
        $this->assertNull($opts->recorder);
        $this->assertSame(1000, $opts->pumpDeadlineUs);
        $this->assertSame($onIdle, $opts->withChunkBytes(8)->onIdle);
    }

    public function testAClosureInTheRemovedKeepaliveSlotFailsLoudly(): void
    {
        $this->expectException(\TypeError::class);
        new PumpOptions(4096, 50000, 0.5, 0.3, "\x04", static fn (): null => null);
    }
}
