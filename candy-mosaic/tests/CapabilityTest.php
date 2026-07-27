<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\Capability;
use SugarCraft\Mosaic\CellSize;

use ReflectionClass;

final class CapabilityTest extends TestCase
{
    /**
     * Helper: instantiate Capability bypassing the private constructor.
     * Used only for testing edge-case flag combinations that no factory produces.
     *
     * @param array{sixel: bool, kitty: bool, iterm2: bool, halfblock: bool, chafa: bool, cellSize: ?CellSize, inTmux: bool} $flags
     */
    private function createCapability(array $flags): Capability
    {
        $rc = new ReflectionClass(Capability::class);
        $ctor = $rc->getConstructor();
        $ctor->setAccessible(true);
        $cap = $rc->newInstanceWithoutConstructor();
        $ctor->invoke($cap, ...array_values($flags));

        return $cap;
    }

    public function testUniversalHasAllProtocolsEnabled(): void
    {
        $cap = Capability::universal();

        $this->assertTrue($cap->sixel);
        $this->assertTrue($cap->kitty);
        $this->assertTrue($cap->iterm2);
        $this->assertTrue($cap->halfblock);
        $this->assertTrue($cap->chafa);
        $this->assertFalse($cap->inTmux);
        $this->assertNull($cap->cellSize);
    }

    public function testUniversalWithCellSize(): void
    {
        $cs = new CellSize(8, 20);
        $cap = Capability::universal($cs);

        $this->assertSame($cs, $cap->cellSize);
    }

    public function testUniversalInTmux(): void
    {
        $cap = Capability::universal(null, true);

        $this->assertTrue($cap->inTmux);
    }

    public function testKittyHasOnlyKittyAndHalfblock(): void
    {
        $cap = Capability::kitty();

        $this->assertFalse($cap->sixel);
        $this->assertTrue($cap->kitty);
        $this->assertFalse($cap->iterm2);
        $this->assertTrue($cap->halfblock);
        $this->assertFalse($cap->chafa);
    }

    public function testIterm2HasOnlyIterm2AndHalfblock(): void
    {
        $cap = Capability::iterm2();

        $this->assertFalse($cap->sixel);
        $this->assertFalse($cap->kitty);
        $this->assertTrue($cap->iterm2);
        $this->assertTrue($cap->halfblock);
        $this->assertFalse($cap->chafa);
    }

    public function testSixelHasOnlySixelAndHalfblock(): void
    {
        $cap = Capability::sixel();

        $this->assertTrue($cap->sixel);
        $this->assertFalse($cap->kitty);
        $this->assertFalse($cap->iterm2);
        $this->assertTrue($cap->halfblock);
        $this->assertFalse($cap->chafa);
    }

    public function testUnknownHasOnlyHalfblock(): void
    {
        $cap = Capability::unknown();

        $this->assertFalse($cap->sixel);
        $this->assertFalse($cap->kitty);
        $this->assertFalse($cap->iterm2);
        $this->assertTrue($cap->halfblock);
        $this->assertFalse($cap->chafa);
    }

    public function testWithCellSizeReturnsNewInstance(): void
    {
        $original = Capability::unknown();
        $cs = new CellSize(10, 24);
        $withSize = $original->withCellSize($cs);

        $this->assertNotSame($original, $withSize);
        $this->assertNull($original->cellSize);
        $this->assertSame($cs, $withSize->cellSize);
        // Other flags unchanged
        $this->assertFalse($withSize->sixel);
        $this->assertTrue($withSize->halfblock);
    }

    public function testWithCellSizeCanClearCellSize(): void
    {
        $cap = Capability::universal(new CellSize(8, 16));
        $cleared = $cap->withCellSize(null);

        $this->assertNull($cleared->cellSize);
        $this->assertTrue($cap->cellSize instanceof CellSize);
    }

    public function testDetectSummaryReturnsKitty(): void
    {
        $cap = Capability::kitty();
        $this->assertSame('Kitty', $cap->detectSummary());
    }

    public function testDetectSummaryReturnsIterm2(): void
    {
        $cap = Capability::iterm2();
        $this->assertSame('iTerm2', $cap->detectSummary());
    }

    public function testDetectSummaryReturnsSixel(): void
    {
        $cap = Capability::sixel();
        $this->assertSame('Sixel', $cap->detectSummary());
    }

    public function testDetectSummaryReturnsChafaWhenKittyAndSixelAndIterm2AreFalse(): void
    {
        // Only chafa is true (edge case — no factory produces this exact combo)
        $cap = $this->createCapability([
            'sixel' => false,
            'kitty' => false,
            'iterm2' => false,
            'halfblock' => false,
            'chafa' => true,
            'cellSize' => null,
            'inTmux' => false,
        ]);
        $this->assertSame('Chafa', $cap->detectSummary());
    }

    public function testDetectSummaryReturnsHalfBlockWhenOnlyHalfblockIsTrue(): void
    {
        $cap = Capability::unknown();
        $this->assertSame('HalfBlock', $cap->detectSummary());
    }

    public function testDetectSummaryReturnsUnknownWhenNoProtocolsAreTrue(): void
    {
        // Build an instance with everything false (edge case — no factory produces this)
        $cap = $this->createCapability([
            'sixel' => false,
            'kitty' => false,
            'iterm2' => false,
            'halfblock' => false,
            'chafa' => false,
            'cellSize' => null,
            'inTmux' => false,
        ]);
        $this->assertSame('Unknown', $cap->detectSummary());
    }

    public function testImmutabilityOfWithCellSize(): void
    {
        $cap = Capability::kitty(new CellSize(8, 16));
        $modified = $cap->withCellSize(new CellSize(10, 20));

        // Original unchanged
        $this->assertSame(8, $cap->cellSize->cellWidth);
        $this->assertSame(16, $cap->cellSize->cellHeight);
        // New instance has new size
        $this->assertSame(10, $modified->cellSize->cellWidth);
        $this->assertSame(20, $modified->cellSize->cellHeight);
    }
}
