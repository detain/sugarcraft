<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins that every ```php fence in README.md is at least syntactically real.
 *
 * Deviation from the canonical sugar-log/sugar-panels ReadmeSnippetsTest
 * shape (which RUNS its snippets): sugar-pty's fences reference variables
 * introduced by EARLIER fences and open real PTYs / signal handlers, so a
 * full execution harness would re-implement the examples. Execution of the
 * runnable end-to-end demos is pinned by {@see ExamplesExecTest} against
 * examples/ instead; here every fence must PARSE (php -l) so a snippet can
 * never rot into the paste-fatal shapes the 2026-11 review caught.
 *
 * Also carries the resize-recipe guard: the host-terminal size lookup must
 * be taught as an INSTANCE call, never as a static call to the non-static
 * size() method (the review's sole CRITICAL).
 */
final class ReadmeSnippetsTest extends TestCase
{
    private string $scratchDir;

    protected function setUp(): void
    {
        $this->scratchDir = sys_get_temp_dir() . '/sugar-pty-readme-' . getmypid() . '-' . spl_object_id($this);
        if (!is_dir($this->scratchDir)) {
            mkdir($this->scratchDir, 0700);
        }
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->scratchDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $file = $this->scratchDir . '/' . $entry;
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->scratchDir)) {
            rmdir($this->scratchDir);
        }
    }

    /** @return array<string,array{0:string}> */
    public static function readmeFences(): array
    {
        $readme = self::readme();
        $count = preg_match_all('/```php\r?\n(.*?)\r?\n```/s', $readme, $matches);
        self::assertIsInt($count);
        self::assertGreaterThan(0, $count, 'README.md lost every php fence — the guard would go silently vacuous.');

        $fences = [];
        foreach ($matches[1] as $index => $body) {
            $fences['fence ' . ($index + 1)] = [$body];
        }

        return $fences;
    }

    #[DataProvider('readmeFences')]
    public function testEveryReadmeFenceParses(string $body): void
    {
        // Fences that already open a php tag must not get a second one.
        $source = str_contains($body, '<?php')
            ? $body
            : "<?php\n\ndeclare(strict_types=1);\n\n" . $body;

        $path = $this->scratchDir . '/fence.php';
        file_put_contents($path, $source);

        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $status);
        self::assertSame(0, $status, "README fence does not parse:\n" . implode("\n", $output) . "\n--- fence ---\n" . $source);
    }

    public function testTheResizeRecipeIsTaughtAsAnInstanceCall(): void
    {
        $readme = self::readme();
        self::assertGreaterThanOrEqual(
            2,
            substr_count($readme, 'new Tty('),
            'The resize recipe must instantiate the host terminal (the size() method is not static).'
        );
    }

    public function testNoFenceTeachesTheStaticSizeSpelling(): void
    {
        // Assembled by concatenation so this file never carries the forbidden
        // spelling itself.
        $forbidden = 'Tty' . chr(58) . chr(58) . 'size(';
        self::assertSame(
            0,
            substr_count(self::readme(), $forbidden),
            'README teaches the size() lookup as a static Tty call; it is an instance method — paste-fatal.'
        );
    }

    private static function readme(): string
    {
        $path = dirname(__DIR__) . '/README.md';
        $readme = file_get_contents($path);
        self::assertIsString($readme, 'README.md must be readable from the lib root: ' . $path);

        return $readme;
    }
}
