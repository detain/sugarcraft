<?php

declare(strict_types=1);

namespace SugarCraft\McpClient\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The examples are shipped documentation: the README promises
 * "`php examples/echo.php` is a dependency-free trial", and nothing but this
 * gate makes that promise an enforced one. It shells out with exec() — not
 * proc_open() — deliberately: tools/check-child-lifetimes.php censuses
 * proc_open sites repo-wide and its roster lives outside this lib's path
 * ownership; exec() keeps the smoke honest without minting a cross-lane
 * bookkeeping row.
 */
final class ExamplesTest extends TestCase
{
    /** @return array<string,string> name => absolute path */
    private function examples(): array
    {
        $dir = \dirname(__DIR__) . '/examples';
        $found = [];
        foreach ((new \GlobIterator($dir . '/*.php')) ?: [] as $file) {
            $found[$file->getFilename()] = $file->getPathname();
        }
        $this->assertNotEmpty($found, 'examples/ must keep shipping at least one demo');

        return $found;
    }

    public function testEveryExampleIsSyntacticallyValid(): void
    {
        foreach ($this->examples() as $name => $path) {
            $out = [];
            $rc = 0;
            \exec(\escapeshellarg(\PHP_BINARY) . ' -l ' . \escapeshellarg($path) . ' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, "php -l failed for examples/{$name}: " . \implode("\n", $out));
        }
    }

    public function testEchoExampleCompletesItsRoundTripHeadless(): void
    {
        $out = [];
        $rc = 0;
        // 2>&1 lands PHP fatals (stderr) inside $out; the timeout guard stops
        // a future hanging example from hanging CI.
        \exec(
            'timeout 60 ' . \escapeshellarg(\PHP_BINARY) . ' '
            . \escapeshellarg(\dirname(__DIR__) . '/examples/echo.php') . ' 2>&1',
            $out,
            $rc
        );
        $text = \implode("\n", $out);
        $this->assertSame(0, $rc, "examples/echo.php exited with {$rc}: " . \substr($text, 0, 400));
        $this->assertStringNotContainsString('Fatal error', $text);
        // Every leg of the promise, in order: identity from the kept
        // initialize result, the advertised tool, the echoed reply, sentinel.
        $this->assertContains('server: example-echo 1.0.0', $out);
        $this->assertContains('tool: echo - Echoes the message argument back as text', $out);
        $this->assertContains('reply: echo: dependency-free round trip', $out);
        $this->assertContains('OK', $out);
    }
}
