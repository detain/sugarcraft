<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Renderer\ChafaBinary;
use SugarCraft\Image\Renderer\ChafaRenderer;

/**
 * Finding 112's structural cure: every chafa sink is created inside a
 * lib-owned 0700 scratch directory that sweeps its own strays at first
 * use — the DiskCache mc-* idiom ported to the renderer. Sinks that
 * survive a kill -9 in bare /tmp were uncountable and unsweepable;
 * inside the owned directory they are reaped by the next process that
 * touches chafa.
 *
 * Hermetic law: the owned directory is REMOVED in setUp/tearDown, so
 * every pin sees a fresh creation (and the creation-time sweep it
 * proves). A pre-existing directory this uid does not own would refuse
 * the whole premise — the arm skips there rather than lying.
 */
final class ChafaRendererScratchDirTest extends TestCase
{
    private const GIF_B64 = 'R0lGODlhAQABAIAAAMLCwgAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==';

    private ?string $dir = null;

    private ?string $overrideBackup = null;

    private ?string $stubDir = null;

    /** The owned-scratch path this suite manipulates (system temp root + name). */
    private function ownedDir(): string
    {
        return $this->dir ??= sys_get_temp_dir() . '/sugar-image-chafa';
    }

    protected function setUp(): void
    {
        $armed = getenv(ChafaBinary::ENV_OVERRIDE);
        $this->overrideBackup = $armed === false ? null : $armed;

        $this->removeOwnedDir();
        ChafaRenderer::reset();
    }

    protected function tearDown(): void
    {
        ChafaRenderer::resetScratch();
        ChafaRenderer::reset();

        if ($this->dir !== null) {
            if (is_dir($this->dir) && @fileowner($this->dir) === (function_exists('posix_getuid') ? posix_getuid() : 0)) {
                foreach (@glob($this->dir . '/*') ?: [] as $stray) {
                    @unlink($stray);
                }
                @rmdir($this->dir);
            }
        }
        if ($this->stubDir !== null) {
            @unlink($this->stubDir . '/chafa');
            @rmdir($this->stubDir);
        }
        if ($this->overrideBackup !== null) {
            putenv(ChafaBinary::ENV_OVERRIDE . '=' . $this->overrideBackup);
        } else {
            putenv(ChafaBinary::ENV_OVERRIDE);
        }
    }

    private function removeOwnedDir(): void
    {
        $dir = $this->ownedDir();
        if (!is_dir($dir)) {
            return;
        }
        if (function_exists('posix_getuid') && @fileowner($dir) !== posix_getuid()) {
            $this->markTestSkipped('a foreign process owns the scratch dir; the owned-dir premise is unavailable');
        }
        foreach (@glob($dir . '/*') ?: [] as $stray) {
            @unlink($stray);
        }
        @rmdir($dir);
    }

    private function sinkDir(): string
    {
        $method = new ReflectionMethod(ChafaRenderer::class, 'sinkDir');
        $method->setAccessible(true);

        return $method->invoke(null);
    }

    public function testTheFirstSinkRequestCreatesAnOwnedZeroSevenZeroZeroDirectory(): void
    {
        $sink = $this->sinkDir();

        $this->assertSame($this->ownedDir(), $sink, 'the owned directory, not bare /tmp');
        $this->assertDirectoryExists($sink);
        $this->assertSame('0700', substr(sprintf('%o', (int) fileperms($sink)), -4));
    }

    public function testStaleStraysAreSweptAtFirstUseAndFreshOnesSurvive(): void
    {
        $dir = $this->sinkDir(); // creates + sweeps an empty dir

        $staleFrame = $dir . '/chafaSTALE1a2b';
        $staleErr   = $dir . '/schafaerrSTALE3c4d';
        $freshFrame = $dir . '/chafaFRESH5e6f';
        foreach ([$staleFrame, $staleErr, $freshFrame] as $stray) {
            file_put_contents($stray, 'x');
        }
        $old = time() - 3600;
        touch($staleFrame, $old);
        touch($staleErr, $old);

        ChafaRenderer::reset(); // fresh process shape: the memo is empty, creation-time sweep runs again

        $again = $this->sinkDir();
        $this->assertSame($dir, $again);
        $this->assertFileDoesNotExist($staleFrame, 'a dead process chafa scratch must not outlive its grace');
        $this->assertFileDoesNotExist($staleErr, 'the same for abandoned stderr sinks');
        $this->assertFileExists($freshFrame, 'within grace a file may belong to a LIVE process — leave it');

        @unlink($freshFrame);
    }

    /**
     * scratchFile's self-heal is what makes the sweep safe against a
     * long-running process whose reused scratch outlived the grace:
     * a vanished path is re-created, not trusted.
     */
    public function testTheSweepIsSafeUnderneathALiveReusedScratch(): void
    {
        $this->plantExitThreeStub();
        ChafaRenderer::reset();

        $source = ImageSource::fromString((string) base64_decode(self::GIF_B64));
        $renderer = new ChafaRenderer();
        try {
            $renderer->render($source, 8, 8);
        } catch (\RuntimeException) {
            // the stub refuses at exit 3; the scratch write happened first
        }

        $scratch = ChafaRenderer::currentScratchPath();
        $this->assertNotNull($scratch);
        $this->assertStringStartsWith($this->ownedDir() . '/', $scratch, 'the reused scratch lives inside the owned dir');
        $this->assertFileExists($scratch);

        // Simulate another process's creation-time sweep stealing it
        // mid-session: the next scratchFile call must re-create, never
        // hand the child a path that no longer exists.
        @unlink($scratch);

        $scratchFile = new ReflectionMethod(ChafaRenderer::class, 'scratchFile');
        $scratchFile->setAccessible(true);
        $path = (string) $scratchFile->invoke(null, (string) base64_decode(self::GIF_B64));
        $this->assertFileExists($path, 'a scratch that vanished is re-created, never reported as written');
        $this->assertStringStartsWith($this->ownedDir() . '/', $path);
    }

    public function testALoosenedOrForeignDirectoryIsRefusedForBareSystemTemp(): void
    {
        if (!function_exists('posix_getuid') || posix_getuid() === 0) {
            $this->markTestSkipped('the ownership gate is meaningless running as root');
        }

        $sink = $this->sinkDir();
        $this->assertSame($this->ownedDir(), $sink);

        chmod($sink, 0777); // someone loosened it — a shared scratch is exactly the planted-dir door

        ChafaRenderer::reset();
        $fallback = $this->sinkDir();

        $this->assertSame(sys_get_temp_dir(), $fallback, 'a stranger-mode directory must NOT be trusted — old behaviour, no silent adoption');

        // The refusal must be sticky-correct after the dir is fixed too.
        ChafaRenderer::reset();
        chmod($this->ownedDir(), 0700);
        $this->assertSame($this->ownedDir(), $this->sinkDir());
    }

    public function testTheProbeBannerSinkLivesInTheOwnedDirectory(): void
    {
        // A successful probe answers --version, writes the banner sink,
        // unlinks it after the settle; the dir must still be the owned one.
        $this->plantBannerStub('chafa 7.7.7');

        $this->assertTrue(ChafaRenderer::available());

        $dirProp = new ReflectionProperty(ChafaRenderer::class, 'sinkDir');
        $dirProp->setAccessible(true);
        $this->assertSame($this->ownedDir(), $dirProp->getValue());
        $this->assertSame(
            [],
            @glob($this->ownedDir() . '/schafaout*') ?: [],
            'the banner sink is unlinked on both doors — no stray is created by a healthy probe',
        );
    }

    private function plantExitThreeStub(): void
    {
        $this->plantBannerStub(null);
    }

    private function plantBannerStub(?string $banner): void
    {
        $this->stubDir = sys_get_temp_dir() . '/s6-stub-' . getmypid() . '-' . uniqid();
        mkdir($this->stubDir, 0700, true);
        $stub = $this->stubDir . '/chafa';
        $body = $banner === null
            ? "#!/bin/sh\nexit 3\n"
            : "#!/bin/sh\nif [ \"\$1\" = \"--version\" ]; then printf '%s\\n' '" . $banner . "'; exit 0; fi\nexit 3\n";
        file_put_contents($stub, $body);
        chmod($stub, 0700);
        putenv(ChafaBinary::ENV_OVERRIDE . '=' . $stub);
    }
}
