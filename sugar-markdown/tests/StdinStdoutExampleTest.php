<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Markdown\Renderer;
use SugarCraft\Markdown\Theme;

/**
 * Pins examples/stdin-stdout.php's theme resolution: the catalog slugs that
 * Theme::byName() does not know (solarized, monokai, github) must reach the
 * renderer, and an unknown name must warn on stderr rather than fall back
 * silently.
 */
final class StdinStdoutExampleTest extends TestCase
{
    private const MARKDOWN = "# Title\n\nSome **bold** text.\n";

    public function testCatalogOnlySlugIsHonouredNotSilentlyReplacedByAnsi(): void
    {
        [$status, $stdout, $stderr] = $this->runExample('--theme=solarized');

        $this->assertSame(0, $status, $stderr);
        $this->assertSame('', $stderr);
        $this->assertSame($this->renderSolarized(), $stdout);
        $this->assertNotSame($this->render(Theme::ansi()), $stdout);
    }

    public function testLooseSpellingOfACatalogSlugResolves(): void
    {
        [$status, $stdout, $stderr] = $this->runExample('--theme=Solarized');

        $this->assertSame(0, $status, $stderr);
        $this->assertSame($this->renderSolarized(), $stdout);
    }

    public function testUnknownThemeWarnsAndFallsBackToAnsi(): void
    {
        [$status, $stdout, $stderr] = $this->runExample('--theme=nosuchtheme');

        $this->assertSame(0, $status, $stderr);
        $this->assertSame("unknown theme 'nosuchtheme'; using ansi\n", $stderr);
        $this->assertSame($this->render(Theme::ansi()), $stdout);
    }

    /**
     * Expected output from a theme object of its own. Rendering the shared
     * catalog instance would memoise SGR on it and skew ThemeCatalogTest's
     * comparison of the catalog against the files.
     */
    private function renderSolarized(): string
    {
        return $this->render(Theme::fromFile(dirname(__DIR__) . '/themes/solarized.json'));
    }

    private function render(Theme $theme): string
    {
        return (new Renderer($theme))->render(self::MARKDOWN) . "\n";
    }

    /** @return array{int, string, string} exit status, stdout, stderr */
    private function runExample(string $themeArg): array
    {
        $script = dirname(__DIR__) . '/examples/stdin-stdout.php';
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open([PHP_BINARY, $script, $themeArg], $spec, $pipes);
        $this->assertIsResource($process, 'could not start the example');

        fwrite($pipes[0], self::MARKDOWN);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
