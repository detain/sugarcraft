<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The examples ARE the shipped documentation surface (README demos + the VHS
 * tapes drive exactly these files), yet nothing in CI ever EXECUTED them:
 * the vhs job swallows the exit code, so a fataling example shipped a green
 * suite with a broken GIF at worst.
 *
 * This is the hard execution gate. It shells out with exec() — not
 * proc_open() — deliberately: tools/check-child-lifetimes.php censuses
 * proc_open sites repo-wide and its roster lives outside this lib's path
 * ownership; exec() keeps the smoke honest without minting a cross-lane
 * bookkeeping row.
 */
final class ExamplesExecuteTest extends TestCase
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

    public function testEveryExampleRunsHeadlessToCompletion(): void
    {
        foreach ($this->examples() as $name => $path) {
            $out = [];
            $rc = 0;
            // 2>&1 lands PHP fatals (written to stderr) inside $out for the
            // message assertions below; the timeout guard keeps a future
            // interactive example from hanging CI.
            \exec(
                'timeout 30 ' . \escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg($path) . ' 2>&1',
                $out,
                $rc
            );
            $text = \implode("\n", $out);
            $this->assertSame(0, $rc, "examples/{$name} exited with {$rc}: " . \substr($text, 0, 400));
            // Diagnostics are anchored at line start (PHP's own emitter
            // style) — a demo that legitimately PRINTS the word Warning as
            // toast content must not false-positive this gate.
            $this->assertDoesNotMatchRegularExpression(
                '/^PHP (?:Warning|Fatal error|Deprecated|Parse error|Notice)|^Fatal error|^Parse error|^Uncaught|\bPHP Fatal error/m',
                $text,
                "examples/{$name} printed a PHP diagnostic"
            );
            $this->assertNotSame('', \trim($text), "examples/{$name} produced no output at all");
        }
    }
}
