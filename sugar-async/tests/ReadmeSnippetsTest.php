<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Standing guard for the documentation surface: every ```php fence in README.md
 * must lint and execute in a child process. This is the permanent recurrence
 * guard for the class of defect that shipped a parse-error line in a fence
 * (`fn() => echo`) once before — docs that are pinned to no code rot silently,
 * docs that RUN cannot.
 */
final class ReadmeSnippetsTest extends TestCase
{
    private const MIN_FENCES = 7;

    public function testTheReadmeCarriesTheExpectedFenceFamily(): void
    {
        self::assertFileExists(self::readmePath());
        self::assertGreaterThanOrEqual(
            self::MIN_FENCES,
            count(self::fences()),
            'README fences are the executable documentation surface',
        );
    }

    /**
     * Every fence is wrapped with an `<?php` header and the library autoloader,
     * lint-checked, then executed with a wall-clock guard: a snippet that needs
     * a running loop must drive (and stop) its own, so a hang is a fence bug.
     */
    public function testEveryFenceLintsAndRuns(): void
    {
        $dir = sys_get_temp_dir() . '/sugar-async-readme-' . getmypid();
        if (!is_dir($dir)) {
            self::assertTrue(mkdir($dir, 0700, true));
        }

        try {
            foreach (self::fences() as $index => $fence) {
                $file = $dir . '/fence' . $index . '.php';
                $source = "<?php\n\ndeclare(strict_types=1);\n\nrequire "
                    . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ";\n\n"
                    . $fence . "\n";
                self::assertNotFalse(file_put_contents($file, $source));

                exec('php -l ' . escapeshellarg($file) . ' 2>&1', $lintOut, $lintCode);
                self::assertSame(0, $lintCode, "fence $index must lint: " . implode(' ', $lintOut));
                $lintOut = [];

                exec('timeout -s KILL 10 php ' . escapeshellarg($file) . ' 2>&1', $runOut, $runCode);
                self::assertSame(
                    0,
                    $runCode,
                    "fence $index must run cleanly (code $runCode): " . implode("\n", $runOut),
                );
            }
        } finally {
            foreach (glob($dir . '/fence*.php') ?: [] as $left) {
                unlink($left);
            }
            @rmdir($dir);
        }
    }

    /** @return list<string> */
    private static function fences(): array
    {
        $contents = (string) file_get_contents(self::readmePath());
        preg_match_all('/```php\n(.*?)```/s', $contents, $matches);
        /** @var list<string> $fences */
        $fences = $matches[1];
        return $fences;
    }

    private static function readmePath(): string
    {
        return dirname(__DIR__) . '/README.md';
    }
}
