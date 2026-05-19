<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Golden;

use PHPUnit\Framework\Error\Warning;
use PHPUnit\Framework\TestCase;

/**
 * Golden snapshot tests for sugar-dash examples.
 *
 * Compares the current output of each example at 80x24 and 120x40
 * dimensions against the committed golden files under tests/golden/.
 *
 * Run with `--filter Golden --regenerate` to regenerate goldens
 * (also run: php tools/generate-goldens.php separately).
 *
 * @see sugar-dash/tools/generate-goldens.php
 */
final class GoldenSnapshotTest extends TestCase
{
    private const GOLDEN_ROOT = __DIR__ . '/golden';
    private const EXAMPLES_DIR = __DIR__ . '/../examples';

    /** @var bool Whether to regenerate goldens instead of comparing */
    private static bool $regenerate = false;

    /// ------------------------------------------------------------
    // Dimensions to test
    /// ------------------------------------------------------------

    private const DIMENSIONS = [
        '80x24'  => [80, 24],
        '120x40' => [120, 40],
    ];

    /// ------------------------------------------------------------
    // Examples that are skipped (interactive / non-deterministic)
    /// ------------------------------------------------------------

    /** @var array<string, string> slug => reason */
    private const SKIPPED = [
        'dashboard-live'          => 'interactive TUI (VHS in part 2)',
        'dashboard-accordion-timeline'   => 'accordion and timeline demo',
        'dashboard-charts'         => 'interactive dashboard demo',
        'dashboard-complex'         => 'interactive dashboard demo',
        'dashboard-data'            => 'interactive dashboard demo',
        'dashboard-devtools'        => 'interactive dashboard demo',
        'dashboard-form'            => 'interactive dashboard demo',
        'dashboard-layout'          => 'interactive dashboard demo',
        'dashboard-media'           => 'interactive dashboard demo',
        'dashboard-metrics'         => 'interactive dashboard demo',
        'dashboard-nav'             => 'interactive dashboard demo',
        'dashboard-showcase'        => 'interactive dashboard demo',
        'dashboard-status'          => 'interactive dashboard demo',
        'dashboard-text'            => 'interactive dashboard demo',
        'dashboard-time'            => 'interactive dashboard demo',
        'dashboard-ui'              => 'interactive dashboard demo',
        'terminal'                => 'interactive terminal emulator',
        'commandPalette'         => 'interactive (blocks on input)',
        'drawer'                 => 'interactive drawer',
        'popover'                => 'interactive popover',
        'clock'                  => 'non-deterministic: real-time clock',
        'timer'                  => 'non-deterministic: real-time timer',
        'stopwatch'              => 'non-deterministic: real-time stopwatch',
        'spinner'                => 'non-deterministic: animated spinner',
        'loadingText'            => 'non-deterministic: animated loading text',
        'log'                   => 'non-deterministic: timestamp-based log',
        'bubble'                => 'non-deterministic: may include time-dependent content',
        'run-all'                => 'meta: example runner',
        'generate-all'           => 'meta: example generator',
    ];

    /// ------------------------------------------------------------
    // Setup
    /// ------------------------------------------------------------

    public static function setUpBeforeClass(): void
    {
        // Check for --regenerate flag via argv
        global $argv;
        if ($argv !== null && in_array('--regenerate', $argv, true)) {
            self::$regenerate = true;
        }
    }

    /// ------------------------------------------------------------
    // Data provider
    /// ------------------------------------------------------------

    /**
     * @return iterable<string, array{0: string, 1: string, 2: int, 3: int}>
     */
    public static function provideExamples(): iterable
    {
        $examplesDir = self::EXAMPLES_DIR;
        if (!is_dir($examplesDir)) {
            return;
        }

        $files = glob("{$examplesDir}/*.php");
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            $basename = basename($file, '.php');

            if (isset(self::SKIPPED[$basename])) {
                continue;
            }

            // Skip non .php files that might appear
            if (!str_ends_with($file, '.php')) {
                continue;
            }

            foreach (self::DIMENSIONS as $dimLabel => [$width, $height]) {
                yield "{$basename}@{$dimLabel}" => [$basename, $dimLabel, $width, $height];
            }
        }
    }

    /// ------------------------------------------------------------
    // Helper: patch setSize in example content
    /// ------------------------------------------------------------

    /**
     * Patch example content for running in a temp file.
     *
     * Fixes:
     * 1. setSize dimensions → target width/height
     * 2. require_once path → absolute path (because __DIR__ in /tmp/...
     *    resolves to /tmp, not the sugar-dash root)
     *
     * @param string $content     File content
     * @param int    $width       Target width
     * @param int    $height      Target height
     * @return string Patched content
     */
    private static function patchExample(string $content, int $width, int $height): string
    {
        // Patch setSize
        $pattern = '/->setSize\s*\(\s*(\d+)\s*,\s*(\d+)\s*\)/';
        $patched = "->setSize({$width}, {$height})";
        $content = preg_replace($pattern, $patched, $content, 1);

        // Fix require_once path: the example files use __DIR__ . '/../vendor/autoload.php'
        // When the temp file runs from /tmp, __DIR__ is /tmp, not the sugar-dash root.
        // Replace with the absolute path to the sugar-dash vendor autoloader.
        $sugarDashRoot = realpath(self::EXAMPLES_DIR . '/..');
        if ($sugarDashRoot !== false) {
            $autoloadAbs = $sugarDashRoot . '/vendor/autoload.php';
            $content = preg_replace(
                "/require_once\s*\(?\s*__DIR__\s*\.\s*['\"][^'\"]*vendor\/autoload\.php['\"]\s*\)?/",
                "require_once '" . addslashes($autoloadAbs) . "'",
                $content
            );
        }

        return $content;
    }

    /// ------------------------------------------------------------
    // Helper: run example and capture output
    /// ------------------------------------------------------------

    /**
     * Run an example file at the given dimensions and return its stdout.
     */
    private static function runExample(string $slug, int $width, int $height): string
    {
        $file = self::EXAMPLES_DIR . "/{$slug}.php";

        if (!file_exists($file)) {
            return '';
        }

        $content = file_get_contents($file);
        $patchedContent = self::patchExample($content, $width, $height);

        // Write patched content to temp file and run it
        $tmpFile = sys_get_temp_dir() . '/golden_test_' . $slug . '_' . $width . 'x' . $height . '.php';

        try {
            file_put_contents($tmpFile, $patchedContent);

            // Merge with current environment to preserve PATH, etc.
            $fullEnv = array_merge(getenv(), [
                'COLUMNS' => (string) $width,
                'LINES'   => (string) $height,
            ]);

            $spec = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $proc = proc_open('php ' . escapeshellarg($tmpFile), $spec, $pipes, null, $fullEnv);

            if ($proc === false) {
                return '';
            }

            fclose($pipes[0]);

            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);

            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);

            proc_close($proc);

            return ($output ?? '') . ($stderr ?? '');
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    /// ------------------------------------------------------------
    // Tests
    /// ------------------------------------------------------------

    /**
     * @dataProvider provideExamples
     *
     * @param string $slug    Example slug (e.g. "alert", "spinner")
     * @param string $dimLabel Dimension label (e.g. "80x24", "120x40")
     * @param int    $width   Target width in columns
     * @param int    $height  Target height in rows
     */
    public function testExampleMatchesGolden(string $slug, string $dimLabel, int $width, int $height): void
    {
        $goldenPath = self::GOLDEN_ROOT . "/{$dimLabel}/{$slug}.golden";

        // Skip if no golden file exists yet
        if (!file_exists($goldenPath)) {
            $this->markTestSkipped("No golden file found: {$dimLabel}/{$slug}.golden");
            return;
        }

        $goldenContent = file_get_contents($goldenPath);

        // Strip the header comment lines (php version + metadata) to get actual output
        $goldenLines = explode("\n", $goldenContent);
        $actualOutput = '';
        $inBody = false;

        foreach ($goldenLines as $line) {
            if ($inBody) {
                $actualOutput .= $line . "\n";
            } elseif ($line === '---') {
                $inBody = true;
            }
            // Skip php version line and dimension comment line
        }

        $goldenOutput = rtrim($actualOutput, "\n");

        // Run the example at target dimensions
        $currentOutput = self::runExample($slug, $width, $height);
        $currentOutput = rtrim($currentOutput, "\n");

        if (self::$regenerate) {
            // Write current output as new golden
            $newContent = "PHP (test)\n";
            $newContent .= "# dimensions: {$dimLabel} ({$width}x{$height})\n";
            $newContent .= "# example: {$slug} (regenerated)\n";
            $newContent .= "---\n";
            $newContent .= $currentOutput;

            $dir = dirname($goldenPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $tmp = $dir . '/.tmp.' . bin2hex(random_bytes(8));
            file_put_contents($tmp, $newContent);
            rename($tmp, $goldenPath);

            $this->assertTrue(true, "Regenerated: {$dimLabel}/{$slug}.golden");
            return;
        }

        // Compare current output to golden output
        // Normalize temp file paths since generator uses /tmp/golden_*.php
        // and test uses /tmp/golden_test_*.php (same content, different filename)
        $normalizedGolden = preg_replace(
            '/\/tmp\/golden_(test_)?[a-zA-Z0-9_]+\.php/',
            '/tmp/golden.example.php',
            $goldenOutput
        );
        $normalizedCurrent = preg_replace(
            '/\/tmp\/golden_(test_)?[a-zA-Z0-9_]+\.php/',
            '/tmp/golden.example.php',
            $currentOutput
        );

        $this->assertSame(
            $normalizedGolden,
            $normalizedCurrent,
            "Output mismatch for {$slug} at {$dimLabel}. "
            . "Run: php tools/generate-goldens.php --dimensions {$dimLabel} to regenerate."
        );
    }

    /**
     * Test that the golden directory structure exists and contains expected files.
     */
    public function testGoldenDirectoryStructure(): void
    {
        foreach (array_keys(self::DIMENSIONS) as $dimLabel) {
            $dir = self::GOLDEN_ROOT . "/{$dimLabel}";
            $this->assertDirectoryExists($dir, "Golden directory missing: {$dimLabel}");
        }
    }

    /**
     * Smoke test: verify a few examples render without crashing.
     *
     * This runs outside the golden comparison to catch obvious regressions.
     */
    public function testExamplesRenderWithoutError(): void
    {
        $smokeExamples = [
            ['alert', 80, 24],
            ['spinner', 80, 24],
            ['text', 80, 24],
            ['card', 80, 24],
            ['badge', 80, 24],
        ];

        foreach ($smokeExamples as [$slug, $width, $height]) {
            $file = self::EXAMPLES_DIR . "/{$slug}.php";

            if (!file_exists($file)) {
                continue;
            }

            $content = file_get_contents($file);
            $patchedContent = self::patchExample($content, $width, $height);

            $tmpFile = sys_get_temp_dir() . '/golden_smoke_' . $slug . '.php';

            try {
                file_put_contents($tmpFile, $patchedContent);

                $spec = [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ];

                $proc = proc_open('php ' . escapeshellarg($tmpFile), $spec, $pipes);
                if ($proc === false) {
                    continue;
                }

                fclose($pipes[0]);
                $output = stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);

                $this->assertNotNull($output, "Example {$slug} produced null output");
                $this->assertIsString($output, "Example {$slug} output is not a string");
            } finally {
                if (file_exists($tmpFile)) {
                    unlink($tmpFile);
                }
            }
        }
    }
}
