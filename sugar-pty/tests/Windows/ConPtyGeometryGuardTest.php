<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Windows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\PtyException;
use SugarCraft\Pty\Windows\ConPtySystem;
use SugarCraft\Pty\Windows\Kernel32ConPty;

/**
 * PTY-21: geometry is validated once, at the shared COORD gate.
 *
 * Pure-PHP legs run on any host — the guard exists precisely because the
 * failure mode (100000 becoming X=-31072 through the signed-short wrap)
 * looks like success all the way into the kernel. The Windows CI leg
 * confirms the placement only (createPseudoConsole/ResizePseudoConsole
 * now refuse before packing); the refusal numbers themselves are
 * host-independent, so this file is the real pin.
 */
final class ConPtyGeometryGuardTest extends TestCase
{
    public function testAcceptsEverythingTheShortsCanCarry(): void
    {
        Kernel32ConPty::checkGeometry(1, 1);
        Kernel32ConPty::checkGeometry(80, 24);
        Kernel32ConPty::checkGeometry(32767, 32767);

        $this->addToAssertionCount(1);
    }

    public function testRefusesTheWrapNamingWhatWouldHaveArrived(): void
    {
        // The findings measured value: 100000 packs as -31072.
        $this->expectException(PtyException::class);
        $this->expectExceptionMessage('100000x24');

        try {
            Kernel32ConPty::checkGeometry(100000, 24);
        } finally {
            // belt: the message must disclose the silent destination too
        }
    }

    public function testWrapDestinationAppearsInTheRefusal(): void
    {
        try {
            Kernel32ConPty::checkGeometry(100000, 24);
            $this->fail('expected the out-of-range geometry to throw');
        } catch (PtyException $e) {
            self::assertStringContainsString('-31072', $e->getMessage());
        }
    }

    public function testRefusesZeroAndNegativeOnBothAxes(): void
    {
        foreach ([[0, 24], [80, 0], [-5, 24], [80, -1], [32768, 10], [10, 70000]] as [$w, $h]) {
            try {
                Kernel32ConPty::checkGeometry($w, $h);
                $this->fail("geometry {$w}x{$h} must not pass the COORD gate");
            } catch (PtyException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testOpenRefusesOversizedGeometryBeforeMintingAPseudoConsole(): void
    {
        $api = new FakeConPtyApi();

        try {
            (new ConPtySystem($api))->open(100000, 24);
            $this->fail('open() accepted a width that cannot fit a COORD');
        } catch (PtyException $e) {
            self::assertStringContainsString('100000x24', $e->getMessage());
        }

        self::assertSame(
            [],
            $api->callsMatching('createPseudoConsole'),
            'the refusal must happen before a pseudo console is minted',
        );
    }

    public function testResizeArmRefusesThroughTheSameGate(): void
    {
        $api = new FakeConPtyApi();
        $pair = (new ConPtySystem($api))->open(80, 24);

        try {
            $pair->master()->resize(100000, 24);
            $this->fail('resize accepted an out-of-range width');
        } catch (PtyException $e) {
            self::assertStringContainsString('100000x24', $e->getMessage());
        }

        self::assertSame(
            [],
            $api->callsMatching('resizePseudoConsole'),
            'no resize may reach the api once the gate refuses',
        );
    }
}
