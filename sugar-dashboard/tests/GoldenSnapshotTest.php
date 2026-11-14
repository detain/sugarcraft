<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests;

use PHPUnit\Framework\Error\Warning;
use PHPUnit\Framework\TestCase;

/**
 * Golden snapshot tests for sugar-dashboard examples.
 *
 * Compares the current output of each example at 80x24 and 120x40
 * dimensions against the committed golden files under tests/golden/.
 *
 * Run with `--filter Golden --regenerate` to regenerate goldens
 * (also run: php tools/generate-goldens.php separately).
 *
 * @see sugar-dashboard/tools/generate-goldens.php
 */
final class GoldenSnapshotTest extends TestCase
{
    // Fixtures live at sugar-dashboard/tests/golden/ (written by tools/generate-goldens.php);
    // examples live at sugar-dashboard/examples/. Both are adjacent/one level up from tests/.
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
        'calendar'              => 'non-deterministic: Calendar::now() highlights today (date("j")); drifts daily',
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
     *    resolves to /tmp, not the sugar-dashboard root)
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
        // When the temp file runs from /tmp, __DIR__ is /tmp, not the sugar-dashboard root.
        // Replace with the absolute path to the sugar-dashboard vendor autoloader.
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
     * The single subprocess core every example leg shares (finding #116).
     *
     * Honest capture: stdout and stderr are collected on SEPARATE pipes, and
     * the process exit status comes from proc_close()'s return — never
     * discarded. The old smoke leg threw stderr away and ignored the exit
     * code, so an example that died with rc=255 still read as green; that
     * rc=0-blindness is exactly the 001/010/115 hide-class this row retires.
     *
     * @return array{out: string, err: string, rc: int}
     */
    private static function executePhp(string $phpFile, ?array $env = null): array
    {
        $spec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open([PHP_BINARY, $phpFile], $spec, $pipes, null, $env ?? array_merge(getenv(), ['COLUMNS' => '80', 'LINES' => '24']));
        if ($proc === false) {
            return ['out' => '', 'err' => 'proc_open failed', 'rc' => -1];
        }

        // Closing stdin gives every event-loop example an EOF and lets it exit;
        // disk-probed at this writing: all 152 pipe-executable examples
        // terminate rc 0 under this contract (run-all/generate-all are meta,
        // dashboard-live is TTY-guarded and rides the PtyProbe leg instead).
        fclose($pipes[0]);

        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $rc = proc_close($proc);

        return ['out' => $out, 'err' => $err, 'rc' => $rc];
    }

    /**
     * Run an example file at the given dimensions and return its captured bytes.
     *
     * @return array{merged: string, err: string, rc: int} merged is stdout+err
     *                                                          in legacy order
     */
    private static function runExample(string $slug, int $width, int $height): array
    {
        $file = self::EXAMPLES_DIR . "/{$slug}.php";

        if (!file_exists($file)) {
            return ['merged' => '', 'err' => '', 'rc' => 0];
        }

        $content = file_get_contents($file);
        $patchedContent = self::patchExample($content, $width, $height);

        // Write patched content to temp file and run it. The pid suffix keeps
        // concurrent runs (finding sugar-dashboard-154) from clobbering each
        // other's temp fixture; the compare normalizer below tolerates it.
        $tmpFile = sys_get_temp_dir() . '/golden_test_' . $slug . '_' . $width . 'x' . $height . '_' . getmypid() . '.php';

        try {
            file_put_contents($tmpFile, $patchedContent);

            // Merge with current environment to preserve PATH, etc.
            $fullEnv = array_merge(getenv(), [
                'COLUMNS' => (string) $width,
                'LINES'   => (string) $height,
            ]);

            $result = self::executePhp($tmpFile, $fullEnv);

            return ['merged' => $result['out'] . $result['err'], 'err' => $result['err'], 'rc' => $result['rc']];
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

        // Audit finding #7: a missing golden is a FAILURE, not a skip — an
        // example added without regenerating goldens must go red instead of
        // silently passing as "skipped". Every shipped pair has a golden.
        if (!file_exists($goldenPath)) {
            $this->fail(
                "Missing golden file: {$dimLabel}/{$slug}.golden — "
                . "regenerate with: php tools/generate-goldens.php"
            );
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

        // Run the example at target dimensions. A non-zero exit is a hard red
        // before any byte compare: the merged golden bytes could still match a
        // historical clean run's prefix (finding #116 rc=0-blindness).
        $run = self::runExample($slug, $width, $height);
        $this->assertSame(
            0,
            $run['rc'],
            "Example {$slug} at {$dimLabel} exited {$run['rc']}; stderr: {$run['err']}"
        );

        $currentOutput = rtrim($run['merged'], "\n");

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
            '~(?:/tmp|[A-Za-z]:[^\s\'"]*?)[/\\\\]golden_(?:test_)?[a-zA-Z0-9_]+\.php~',
            '/tmp/golden.example.php',
            $goldenOutput
        );
        $normalizedCurrent = preg_replace(
            '~(?:/tmp|[A-Za-z]:[^\s\'"]*?)[/\\\\]golden_(?:test_)?[a-zA-Z0-9_]+\.php~',
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
     * Orphan guard (finding sugar-dashboard-057): the golden inventory on
     * disk must match the harness inventory EXACTLY, both directions.
     * A golden no example leg writes-or-reads is fake coverage that rots —
     * the 14 pruned dashboard-* pairs were unreachable both ways (skipped
     * here, refused by tools/generate-goldens.php), and a missing pair must
     * fail loudly here rather than as an unrelated provider error.
     */
    public function testGoldenInventoryMatchesHarness(): void
    {
        $expected = [];
        foreach (self::provideExamples() as [$slug, $dimLabel]) {
            $expected["{$dimLabel}/{$slug}.golden"] = true;
        }

        $orphans = [];
        foreach (array_keys(self::DIMENSIONS) as $dimLabel) {
            $found = glob(self::GOLDEN_ROOT . "/{$dimLabel}/*.golden") ?: [];
            foreach ($found as $path) {
                $rel = "{$dimLabel}/" . basename($path);
                if (!isset($expected[$rel])) {
                    $orphans[] = $rel;
                }
            }
        }
        $this->assertSame(
            [],
            $orphans,
            'Golden files unreachable by the harness (un-skip the example or delete the file): ' . implode(', ', $orphans)
        );

        $missing = [];
        foreach (array_keys($expected) as $rel) {
            if (!file_exists(self::GOLDEN_ROOT . '/' . $rel)) {
                $missing[] = $rel;
            }
        }
        $this->assertSame(
            [],
            $missing,
            'Harness inventory has no golden on disk — run: php tools/generate-goldens.php. Missing: ' . implode(', ', $missing)
        );
    }

    /// ------------------------------------------------------------
    // Honest smoke sweep (finding #116)
    /// ------------------------------------------------------------

    /** @var list<string> meta runners and the TTY-guarded flagship, each guarded elsewhere or unrunnable by pipe */
    private const SMOKE_EXCLUDED = ['run-all', 'generate-all', 'dashboard-live'];

    /**
     * Every shipped example, once, minus the meta runners and the TTY-guarded
     * flagship (examples/dashboard-live.php prints its isatty refusal on a
     * pipe by design — its real guard is the PtyProbe leg in
     * Tests\Examples\DashboardLivePtySmokeTest).
     *
     * @return iterable<string, array{0: string}>
     */
    public static function provideSmokeExamples(): iterable
    {
        foreach (glob(self::EXAMPLES_DIR . '/*.php') ?: [] as $file) {
            $slug = basename($file, '.php');
            if (in_array($slug, self::SMOKE_EXCLUDED, true)) {
                continue;
            }
            yield $slug => [$slug];
        }
    }

    /**
     * Non-empty stdout, empty stderr, exit zero — per example, on a pipe.
     *
     * @dataProvider provideSmokeExamples
     *
     * The retired leg this replaces ran five hard-coded slugs, discarded
     * proc_close's exit code, threw stderr down a closed pipe, and asserted
     * only that stream_get_contents returned a string — vacuously true even
     * for a fataling example. The class of failure findings 001/010/115 all
     * hid behind: a shared data provider now walks EVERY shipped example.
     * (The golden legs already double-pin the byte-frozen set, now with an
     * honest rc assert; this sweep additionally reaches the non-deterministic
     * and interactive demos the golden set skips, and its stderr-empty arm
     * catches fatals the merged golden bytes smear.)
     */
    public function testEveryExampleProducesHonestOutput(string $slug): void
    {
        $run = self::executePhp(self::EXAMPLES_DIR . "/{$slug}.php");

        $this->assertSame(0, $run['rc'], "example/{$slug}.php exited {$run['rc']}; stderr: {$run['err']}");
        $this->assertSame('', $run['err'], "example/{$slug}.php wrote to stderr: " . substr($run['err'], 0, 400));
        // Liveness bar, not content bar: examples/spacer.php renders pure
        // whitespace BY DESIGN (914 blank bytes), so the honest assertion is
        // 'produced bytes', not 'produced ink' — content is the golden legs' job.
        $this->assertNotSame('', $run['out'], "example/{$slug}.php produced empty stdout");
    }
}
