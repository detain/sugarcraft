<?php

declare(strict_types=1);

namespace SugarCraft\Log\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Documentation is executable. Every fenced php block in README.md must
 * lint AND run to a clean exit (status 0, nothing on stderr) against the
 * library's own autoloader, and every examples/*.php must behave exactly
 * as its header prose claims.
 *
 * This is the fleet's ReadmeSnippetsTest genre (sugar-style canonical):
 * docs that cannot run are docs that lie — the 2026-11 review found
 * several README snippets here that were already false to the API, and
 * this guard makes any future drift fail the suite instead of a reader.
 */
final class ReadmeSnippetsTest extends TestCase
{
    private const AUTOLOAD = __DIR__ . '/../vendor/autoload.php';

    private string $scratchDir;

    protected function setUp(): void
    {
        $this->scratchDir = \sys_get_temp_dir() . '/sugar-log-readme-' . \getmypid() . '-' . \spl_object_id($this);
        \mkdir($this->scratchDir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (\scandir($this->scratchDir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @\unlink($this->scratchDir . '/' . $entry);
            }
        }
        @\rmdir($this->scratchDir);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function runnableSnippetProvider(): array
    {
        $cases = [];
        foreach (self::readmePhpBlocks() as $i => $body) {
            $cases['README block #' . ($i + 1)] = ['README.md', $body];
        }
        foreach (self::runnableExamples() as $file) {
            $cases['example ' . $file] = ['example ' . $file, (string) \file_get_contents(__DIR__ . '/../examples/' . $file)];
        }
        return $cases;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function lintOnlySnippetProvider(): array
    {
        $cases = self::runnableSnippetProvider();
        // examples/panic-handler.php deliberately dies — the shutdown
        // handler renders a panic report and the process exits 255. It is
        // linted here and behavior-pinned by its own test below.
        unset($cases['example panic-handler.php']);
        $cases['example panic-handler.php (lint)'] = ['example panic-handler.php (lint)', (string) \file_get_contents(__DIR__ . '/../examples/panic-handler.php')];
        return $cases;
    }

    /**
     * @return list<string>
     */
    public static function runnableExamples(): array
    {
        return ['demo.php', 'panic-restore.php'];
    }

    /**
     * @return list<string>
     */
    public static function allExamples(): array
    {
        return ['demo.php', 'panic-handler.php', 'panic-restore.php'];
    }

    /**
     * @dataProvider lintOnlySnippetProvider
     */
    public function testEveryDocumentedSnippetCompiles(string $name, string $body): void
    {
        $file = $this->materialise($name, $body);
        [$status, $stderr] = $this->runPhp(['-l', $file]);
        $this->assertSame(0, $status, "php -l failed for {$name}:\n" . $stderr);
    }

    /** [XPL-01] Windows has no /dev/null; its null device is NUL. */
    private const NULL_DEVICE = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';

    /**
     * @dataProvider runnableSnippetProvider
     */
    public function testEveryDocumentedSnippetRunsToCompletion(string $name, string $body): void
    {
        $file = $this->materialise($name, $body);
        [$status, $stderr, $stdout] = $this->runPhp([
            '-d', 'display_errors=stderr',
            '-d', 'error_reporting=E_ALL',
            '-d', 'max_execution_time=15',
            $file,
        ]);
        $this->assertSame(0, $status, "running {$name} exited {$status}:\n" . $stderr);
        $this->assertSame('', $stderr, "running {$name} wrote to stderr:\n" . $stderr);

        if ($name === 'example demo.php') {
            // [XPL-01 floor] The VHS GIF records this exact surface; if the
            // demo ever runs green while printing nothing useful, the pinned
            // level labels catch the silent rot. (The finding's old '[svc]'
            // literal predates the current cookie-prefix demo — pinned to
            // live bytes instead.)
            $this->assertStringContainsString('🍪', $stdout, 'demo prefix surface missing');
            $this->assertStringContainsString('INF', $stdout);
            $this->assertStringContainsString('WRN', $stdout);
            $this->assertStringContainsString('ERR', $stdout);
            $this->assertStringContainsString('Starting the oven', $stdout);
        }
    }

    /**
     * The panic demo's contract, pinned instead of frozen into a comment
     * block that rots (N9): it installs the handler, hits E_USER_ERROR,
     * the shutdown handler renders the banner on STDERR, the process dies
     * 255, and the post-trigger line is never reached.
     */
    public function testPanicHandlerExampleDiesRenderingTheReport(): void
    {
        [$status, $stderr] = $this->runPhp([
            __DIR__ . '/../examples/panic-handler.php',
        ], stdin: self::NULL_DEVICE);

        $this->assertSame(255, $status, 'the fatal-error demo must exit through the shutdown handler');
        $this->assertStringContainsString('PANIC', $stderr, 'panic banner missing from the report');
        $this->assertStringContainsString('report rendered by sugar-log', $stderr);
    }

    public function testTheReadmeActuallyCarriesSnippets(): void
    {
        // A broken extractor would silently green the run pin — floor it.
        $blocks = self::readmePhpBlocks();
        $this->assertGreaterThanOrEqual(12, \count($blocks), 'README php snippet extraction found too few blocks — did the fence shape change?');
    }

    public function testNullDeviceRedirectConstantRecomputesPerPlatform(): void
    {
        // [XPL-01] Shape pin: the single const must equal the platform's real
        // null device — the Windows arm of the fix, provable by construction
        // on any host.
        $value = (new \ReflectionClass(self::class))->getReflectionConstant('NULL_DEVICE')->getValue();
        $this->assertSame(\DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null', $value);
    }

    /**
     * @return list<string>
     */
    private static function readmePhpBlocks(): array
    {
        $readme = (string) \file_get_contents(__DIR__ . '/../README.md');
        \preg_match_all('/^```php[ \t]*\n(.*?)\n```/ms', $readme, $matches);
        return $matches[1];
    }

    private function materialise(string $name, string $body): string
    {
        if (\str_starts_with($name, 'example ')) {
            $file = \substr($name, \strlen('example '));
            $file = \str_replace(' (lint)', '', $file);
            return __DIR__ . '/../examples/' . $file;
        }

        $path = $this->scratchDir . '/' . \md5($name) . '.php';
        \file_put_contents($path, "<?php\ndeclare(strict_types=1);\nrequire '" . self::AUTOLOAD . "';\n" . $body . "\n");
        return $path;
    }

    /**
     * @param list<string> $argv
     *
     * @return array{0: int, 1: string, 2: string} [exit status, captured stderr, captured stdout]
     */
    private function runPhp(array $argv, string $stdin = ''): array
    {
        $errorFile = $this->scratchDir . '/err-' . \md5(\implode(' ', $argv));
        $command = \escapeshellarg(PHP_BINARY);
        foreach ($argv as $arg) {
            $command .= ' ' . \escapeshellarg($arg);
        }
        // [XPL-01] One NULL_DEVICE const serves both redirect shapes.
        $command .= ' <' . \escapeshellarg($stdin === '' ? self::NULL_DEVICE : $stdin) . ' 2>' . \escapeshellarg($errorFile);

        \exec($command, $lines, $status);

        return [$status, (string) \file_get_contents($errorFile), \implode("\n", $lines ?? [])];
    }
}
