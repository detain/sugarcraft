<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Tape;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Tape\Compiler;

/**
 * The `Output <path>` directive must be honored (its path exposed via
 * Compiler::outputPath()) and confined to the tape's own directory so an
 * untrusted tape cannot redirect the render to an arbitrary file.
 */
final class CompilerOutputTest extends TestCase
{
    private string $dir;
    private string $realDir;
    private string $tapePath;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/candy-vcr-out-' . bin2hex(random_bytes(4));
        if (!mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            self::fail("Failed to create temp dir: {$this->dir}");
        }
        $real = realpath($this->dir);
        self::assertNotFalse($real);
        $this->realDir = $real;
        $this->tapePath = $this->dir . '/demo.tape';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testOutputDirectiveIsHonoredAndResolvedUnderTapeDir(): void
    {
        $result = Compiler::parseSource('Output custom.gif');
        $compiler = new Compiler();
        $compiler->compile($result['ast'], $this->tapePath);

        // Pre-fix the directive was discarded and outputPath() returned null.
        $this->assertSame($this->realDir . '/custom.gif', $compiler->outputPath());
    }

    public function testNoOutputDirectiveLeavesOutputPathNull(): void
    {
        $result = Compiler::parseSource("Type \"hi\"\nEnter");
        $compiler = new Compiler();
        $compiler->compile($result['ast'], $this->tapePath);

        $this->assertNull($compiler->outputPath());
    }

    public function testOutputDirectiveEmitsNoEvents(): void
    {
        $result = Compiler::parseSource('Output custom.gif');
        $compiler = new Compiler();
        $cassette = $compiler->compile($result['ast'], $this->tapePath);

        $this->assertSame(0, $cassette->eventCount());
    }

    public function testRelativeTraversalOutputIsRejected(): void
    {
        $result = Compiler::parseSource('Output ../evil.gif');
        $compiler = new Compiler();
        $compiler->compile($result['ast'], $this->tapePath);

        $this->assertNull($compiler->outputPath(), 'traversal path must not be honored');
    }

    public function testAbsoluteEscapingOutputIsRejected(): void
    {
        $outside = sys_get_temp_dir() . '/candy-vcr-outside-' . bin2hex(random_bytes(4)) . '.gif';
        $result = Compiler::parseSource('Output ' . $outside);
        $compiler = new Compiler();
        $compiler->compile($result['ast'], $this->tapePath);

        $this->assertNull($compiler->outputPath(), 'absolute path escaping the tape dir must not be honored');
    }

    public function testBackslashOutputIsRejected(): void
    {
        $result = Compiler::parseSource('Output ..\\evil.gif');
        $compiler = new Compiler();
        $compiler->compile($result['ast'], $this->tapePath);

        $this->assertNull($compiler->outputPath());
    }

    public function testAbsoluteOutputInsideTapeDirIsHonored(): void
    {
        $inside = $this->realDir . '/inside.gif';
        $result = Compiler::parseSource('Output ' . $inside);
        $compiler = new Compiler();
        $compiler->compile($result['ast'], $this->tapePath);

        $this->assertSame($inside, $compiler->outputPath());
    }

    public function testCompileResetsPreviousOutputPath(): void
    {
        $compiler = new Compiler();

        $withOutput = Compiler::parseSource('Output custom.gif');
        $compiler->compile($withOutput['ast'], $this->tapePath);
        $this->assertSame($this->realDir . '/custom.gif', $compiler->outputPath());

        $withoutOutput = Compiler::parseSource('Type "hi"');
        $compiler->compile($withoutOutput['ast'], $this->tapePath);
        $this->assertNull($compiler->outputPath(), 'outputPath must reset between compiles');
    }

    public function testOutputThroughPreplantedSymlinkIsRejected(): void
    {
        // Pre-plant a symlink inside the tape dir whose parent (the tape dir)
        // resolves under base but whose target escapes it. The parent-realpath
        // guard passes; only the symlink-target guard can catch this.
        $outside = sys_get_temp_dir() . '/candy-vcr-symlink-target-' . bin2hex(random_bytes(4)) . '.gif';
        $link = $this->dir . '/out.gif';
        if (!@symlink($outside, $link)) {
            self::markTestSkipped('symlink() not available on this platform');
        }

        $result = Compiler::parseSource('Output out.gif');
        $compiler = new Compiler();
        $compiler->compile($result['ast'], $this->tapePath);

        $this->assertNull(
            $compiler->outputPath(),
            'an Output target that is a pre-planted symlink must be rejected',
        );
    }

    public function testAbsoluteOutputThroughPreplantedSymlinkIsRejected(): void
    {
        $outside = sys_get_temp_dir() . '/candy-vcr-symlink-target-' . bin2hex(random_bytes(4)) . '.gif';
        $link = $this->realDir . '/abs-out.gif';
        if (!@symlink($outside, $link)) {
            self::markTestSkipped('symlink() not available on this platform');
        }

        $result = Compiler::parseSource('Output ' . $link);
        $compiler = new Compiler();
        $compiler->compile($result['ast'], $this->tapePath);

        $this->assertNull(
            $compiler->outputPath(),
            'an absolute Output target that is a pre-planted symlink must be rejected',
        );
    }

    public function testNulByteOutputIsCleanlyRejected(): void
    {
        $result = Compiler::parseSource("Output foo\0bar.gif");
        $compiler = new Compiler();

        // Must reject up front (null), not defer a ValueError to write time.
        $compiler->compile($result['ast'], $this->tapePath);

        $this->assertNull($compiler->outputPath(), 'a NUL-byte path must be rejected cleanly');
    }
}
