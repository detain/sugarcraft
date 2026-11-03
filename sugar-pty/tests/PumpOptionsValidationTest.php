<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\PumpOptions;

/**
 * The tuning knobs become select()/timer arguments the moment a pump
 * reads them — a zero or negative value there is a ValueError deep
 * inside a hot loop or (worse) an infinite select, far from the typo.
 * They are refused at the construction door instead.
 */
final class PumpOptionsValidationTest extends TestCase
{
    public function testZeroChunkBytesIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('chunkBytes must be >= 1; got 0');

        new PumpOptions(chunkBytes: 0);
    }

    public function testNegativeChunkBytesIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('chunkBytes must be >= 1; got -7');

        new PumpOptions(chunkBytes: -7);
    }

    public function testZeroSelectTimeoutUsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('selectTimeoutUs must be >= 1; got 0');

        new PumpOptions(selectTimeoutUs: 0);
    }

    public function testNegativeFlushDeadlineIsRefusedAndZeroIsTheAllowedEdge(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('flushDeadlineSec must be >= 0; got -0.1');

        new PumpOptions(flushDeadlineSec: -0.1);
    }

    public function testFlushDeadlineZeroMeansImmediateDrainBudgetAndIsAccepted(): void
    {
        self::assertSame(0.0, (new PumpOptions(flushDeadlineSec: 0.0))->flushDeadlineSec);
    }

    public function testNegativeStdinEofGraceIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('stdinEofGraceSec must be >= 0; got -1');

        new PumpOptions(stdinEofGraceSec: -1.0);
    }

    public function testStdinEofGraceZeroMeansNoGraceAndIsAccepted(): void
    {
        self::assertSame(0.0, (new PumpOptions(stdinEofGraceSec: 0.0))->stdinEofGraceSec);
    }

    public function testPumpDeadlineGuardKeepsItsHistoricMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('pumpDeadlineUs must be > 0 or null (unbounded); got 0');

        new PumpOptions(pumpDeadlineUs: 0);
    }

    public function testFluentSettersWalkTheSameDoorAsTheConstructor(): void
    {
        $options = new PumpOptions();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('chunkBytes must be >= 1; got 0');

        $options->withChunkBytes(0);
    }

    public function testSshDefaultIsTheIdentityPreset(): void
    {
        // M19 truth pin: sshDefault() is documented as an ALIAS for the
        // defaults, not a differentiated profile. This comparison fails
        // the day the two ever diverge — which is exactly when the prose
        // must be updated too.
        $preset = PumpOptions::sshDefault();
        $plain = new PumpOptions();

        self::assertSame($plain->chunkBytes, $preset->chunkBytes);
        self::assertSame($plain->selectTimeoutUs, $preset->selectTimeoutUs);
        self::assertSame($plain->flushDeadlineSec, $preset->flushDeadlineSec);
        self::assertSame($plain->stdinEofGraceSec, $preset->stdinEofGraceSec);
        self::assertSame($plain->veof, $preset->veof);
        self::assertNull($preset->keepalive);
        self::assertNull($preset->pumpDeadlineUs);
    }

    public function testFluentCarryPreservesUnrelatedFields(): void
    {
        // M22 refactor guard for the mutate() pattern: swapping one knob
        // must not silently drop the closures or the deadline.
        $keepalive = static function (): void {};
        $onIdle = static function (int $us): void {};
        $options = (new PumpOptions(pumpDeadlineUs: 5_000_000))
            ->withKeepalive($keepalive)
            ->withOnIdle($onIdle)
            ->withVEOF("\x03");

        self::assertSame($keepalive, $options->keepalive);
        self::assertSame($onIdle, $options->onIdle);
        self::assertSame("\x03", $options->veof);
        self::assertSame(5_000_000, $options->pumpDeadlineUs);
    }
}
