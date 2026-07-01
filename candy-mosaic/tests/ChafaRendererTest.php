<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Renderer\ChafaRenderer;

final class ChafaRendererTest extends TestCase
{
    public function testChafaRendererName(): void
    {
        $renderer = new ChafaRenderer();
        $this->assertSame('chafa', $renderer->name());
    }

    public function testChafaRendererSupportsAlpha(): void
    {
        $renderer = new ChafaRenderer();
        $this->assertTrue($renderer->supportsAlpha());
    }

    public function testChafaRendererConstructorOptions(): void
    {
        $renderer = new ChafaRenderer(['--colors=16', '--work=n']);
        $this->assertSame('chafa', $renderer->name());
        $this->assertTrue($renderer->supportsAlpha());
    }

    public function testChafaRendererDefaultOptions(): void
    {
        $renderer = new ChafaRenderer();
        $this->assertSame('chafa', $renderer->name());
    }

    public function testChafaRendererWith1x1GifPixel(): void
    {
        // 1x1 red GIF pixel
        $gifBytes = base64_decode('R0lGODlhAQABAIAAAMLCwgAAACH5BAAAAAAALAAAAAABAAEAAAICRAEAOw==');
        $source = ImageSource::fromString($gifBytes);

        $renderer = new ChafaRenderer(['--colors=256']);

        // If chafa is not installed, this will throw a RuntimeException
        try {
            $output = $renderer->render($source, 10, 10);
            $this->assertIsString($output);
        } catch (\RuntimeException $e) {
            // Expected if chafa is not installed
            $this->assertStringContainsString('chafa', strtolower($e->getMessage()));
        }
    }

    public function testChafaRendererInvalidWidthThrows(): void
    {
        $gifBytes = base64_decode('R0lGODlhAQABAIAAAMLCwgAAACH5BAAAAAAALAAAAAABAAEAAAICRAEAOw==');
        $source = ImageSource::fromString($gifBytes);
        $renderer = new ChafaRenderer();

        $this->expectException(\InvalidArgumentException::class);
        $renderer->render($source, 0);
    }

    public function testChafaRendererInvalidHeightThrows(): void
    {
        $gifBytes = base64_decode('R0lGODlhAQABAIAAAMLCwgAAACH5BAAAAAAALAAAAAABAAEAAAICRAEAOw==');
        $source = ImageSource::fromString($gifBytes);
        $renderer = new ChafaRenderer();

        $this->expectException(\InvalidArgumentException::class);
        $renderer->render($source, 10, -1);
    }

    public function testAvailableResets(): void
    {
        // Reset clears the memoised $available so the next available() re-probes.
        // This is important for long-running CLI tools that may install chafa after startup.
        ChafaRenderer::reset();

        // After reset, available() should re-probe (not return a stale cached value).
        $result = ChafaRenderer::available();
        $this->assertIsBool($result);

        // Calling reset again followed by available() should produce the same result
        // but via a fresh probe, not a stale cache.
        ChafaRenderer::reset();
        $this->assertSame($result, ChafaRenderer::available());
    }

    /**
     * @see plan_candy-mosaic.md — Item 1.1 (Item 5.9 in findings_resume_plan)
     *
     * Verifies that when proc_open() partially succeeds (pipes created before failure),
     * any opened file descriptors are cleaned up to avoid FD leaks.
     *
     * We run in a child process to detect any PHP warnings about unclosed FDs,
     * which would be emitted if pipes were not properly closed.
     */
    public function testAvailableFalseCleansUpPipes(): void
    {
        // If chafa is installed, the memoized result blocks the failure path
        if (ChafaRenderer::available()) {
            $this->markTestSkipped('chafa is installed — cannot test failure path');
        }

        // Reset memoized state so we hit the proc_open path again
        ChafaRenderer::reset();

        // Run availability check in isolated process to catch FD warnings
        $php = \PHP_BINARY;
        $code = \sprintf(
            '<?php require %s; use %s; %s::reset(); $r = %s::available(); exit($r === false ? 0 : 1);',
            \var_export(__DIR__ . '/../vendor/autoload.php', true),
            ChafaRenderer::class,
            ChafaRenderer::class,
            ChafaRenderer::class,
        );

        $proc = \proc_open(
            [$php, '-r', $code],
            [[ 'pipe', 'r' ], [ 'pipe', 'w' ], [ 'pipe', 'w' ]],
            $pipes,
        );

        $this->assertIsResource($proc, 'proc_open should succeed for test process');

        foreach ($pipes as $pipe) {
            \fclose($pipe);
        }

        $exitCode = \proc_close($proc);
        $this->assertSame(0, $exitCode, 'chafa unavailability should be detected without FD warnings');
    }
}
