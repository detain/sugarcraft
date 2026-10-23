<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Encode;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Encode\TapeToGif;

/**
 * F2 (docs-fix-1023, ext-gd MINOR) — a gd-less install must not discover
 * the missing extension as a mid-render "undefined function imagecreate()"
 * fatal. TapeToGif::assertBackendAvailable() is the fail-loud door at the
 * backend-resolution choke; the callable seam lets the suite simulate a
 * backend-less install without uninstalling extensions.
 */
final class TapeToGifBackendGuardTest extends TestCase
{
    public function testGdBackendRefusesWhenGdIsMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('gd');

        TapeToGif::assertBackendAvailable('gd', static fn (string $ext): bool => false);
    }

    public function testImagickBackendRefusesWhenImagickIsMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('imagick');

        TapeToGif::assertBackendAvailable('imagick', static fn (string $ext): bool => $ext === 'gd');
    }

    public function testRefusalNamesTheRemedyAndTheAlternative(): void
    {
        try {
            TapeToGif::assertBackendAvailable('gd', static fn (string $ext): bool => false);
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('gd PHP extension', $e->getMessage());
            $this->assertStringContainsString('--backend=imagick', $e->getMessage());
        }
    }

    public function testAvailableBackendPassesThrough(): void
    {
        TapeToGif::assertBackendAvailable('gd', static fn (string $ext): bool => $ext === 'gd');
        TapeToGif::assertBackendAvailable('imagick', static fn (string $ext): bool => $ext === 'imagick');
        $this->addToAssertionCount(2);
    }

    public function testUnknownBackendIsProbedAsTheGdLegItWillTake(): void
    {
        // create() routes anything not 'imagick' to GdRasterizer, so the
        // guard must refuse an unknown backend on the gd extension — the
        // same door the match() arm walks through.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('gd');

        TapeToGif::assertBackendAvailable('sixel', static fn (string $ext): bool => false);
    }

    /**
     * Structural pin: create() actually consults the guard, so the door
     * cannot be silently bypassed by a future refactor of the factory.
     */
    public function testCreateConsultsTheBackendGuard(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Encode/TapeToGif.php');
        $start = strpos($source, 'public static function create(');
        $this->assertIsInt($start);
        $body = substr($source, $start, (int) strpos(substr($source, $start), 'return new self('));

        $this->assertStringContainsString('assertBackendAvailable($backend)', $body);
    }

    public function testRealEnvironmentSatisfiesTheDefaultBackend(): void
    {
        // No seam: the default 'gd' backend must pass the guard with the
        // real extension table, or every render example on this box is
        // already dead.
        TapeToGif::assertBackendAvailable('gd');
        $this->addToAssertionCount(1);
    }
}
