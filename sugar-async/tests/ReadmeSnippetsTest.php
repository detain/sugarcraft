<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Async\Tests\Support\BoundedRun;

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

                $lint = BoundedRun::run([PHP_BINARY, '-l', $file], 10.0);
                self::assertSame(0, $lint['code'], "fence $index must lint: " . $lint['output']);

                $run = BoundedRun::run([PHP_BINARY, $file], 10.0);
                self::assertFalse(
                    $run['killed'],
                    "fence $index must finish inside the bound — a hang is a fence bug",
                );
                self::assertSame(
                    0,
                    $run['code'],
                    "fence $index must run cleanly (code {$run['code']}): " . $run['output'],
                );
            }
        } finally {
            foreach (glob($dir . '/fence*.php') ?: [] as $left) {
                unlink($left);
            }
            @rmdir($dir);
        }
    }

    /**
     * The cancellation fence must demonstrate the cancel arm. With a genuinely
     * pending inner promise (Deferred) the guard is unsettled when cancel()
     * fires, so the reject handler prints "cancelled cleanly" — never
     * "resolved:". An already-resolved inner promise degrades the example to
     * the happy path silently (it still exits 0), which is how this fence
     * shipped broken; this pin makes that shape red.
     */
    public function testTheCancellationFenceTakesTheCancelArm(): void
    {
        $needle = 'cancelled cleanly';
        $candidates = array_values(array_filter(
            self::fences(),
            static fn (string $fence): bool => str_contains($fence, $needle),
        ));
        self::assertCount(1, $candidates, 'exactly one fence demonstrates the cancel arm');

        $dir = sys_get_temp_dir() . '/sugar-async-readme-' . getmypid();
        if (!is_dir($dir)) {
            self::assertTrue(mkdir($dir, 0700, true));
        }

        $file = $dir . '/cancellation-arm.php';
        try {
            $source = "<?php\n\ndeclare(strict_types=1);\n\nrequire "
                . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ";\n\n"
                . $candidates[0] . "\n";
            self::assertNotFalse(file_put_contents($file, $source));

            $run = BoundedRun::run([PHP_BINARY, $file], 10.0);
            self::assertFalse($run['killed'], 'cancellation fence must finish inside the bound');
            self::assertSame(0, $run['code'], 'cancellation fence must run cleanly (code ' . $run['code'] . ')');
            $stdout = $run['output'];
            self::assertStringContainsString(
                'cancelled cleanly',
                $stdout,
                'the fence must take the cancel arm, but printed: ' . $stdout,
            );
            self::assertStringNotContainsString(
                'resolved:',
                $stdout,
                'the guarded promise must not adopt a value before cancellation',
            );
        } finally {
            if (is_file($file)) {
                unlink($file);
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
