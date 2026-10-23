<?php

declare(strict_types=1);

namespace SugarCraft\Overlay\Tests;

use PHPUnit\Framework\TestCase;

/**
 * README snippets and shipped examples must COMPILE and RUN.
 *
 * Minted by docs-fix-1023 (M1): the old README taught
 * `Border::new()->style(Border::STYLE_ROUND)` — a method and a constant
 * sugar-style never had — and every fenced block was decorative prose
 * nobody executed. Same genre as sugar-spring's ReadmeSnippetsTest:
 * extract every ```php fence from README.md (each block is self-contained
 * by house rule) plus every examples/*.php, lint each with `php -l`, then
 * execute it with display_errors on stderr and require exit 0 with a
 * clean stderr.
 */
final class ReadmeSnippetsTest extends TestCase
{
    private const AUTOLOAD = __DIR__ . '/../vendor/autoload.php';
    private const RUN_TIMEOUT_SECONDS = 15;

    private string $scratchDir;

    protected function setUp(): void
    {
        $this->scratchDir = sys_get_temp_dir() . '/overlay-readme-' . getmypid() . '-' . spl_object_id($this);
        if (!is_dir($this->scratchDir)) {
            $this->assertTrue(mkdir($this->scratchDir, 0700, true), 'scratch dir for README snippets');
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->scratchDir . '/' . chr(42)) ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->scratchDir);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: ?string}> label => [source, workdir, onDiskPath|null]
     */
    public static function scriptProvider(): iterable
    {
        $readme = (string) file_get_contents(__DIR__ . '/../README.md');
        $blocks = [];
        preg_match_all('/^```php[ \t]*\n(.*?)\n```/ms', $readme, $blocks, PREG_OFFSET_CAPTURE);

        // Floor guard: the extractor breaking silently would turn this test
        // into a vacuous pass over zero snippets. The README documents at
        // least ten runnable blocks.
        self::assertGreaterThanOrEqual(10, count($blocks[1]), 'README.md lost fenced php blocks (or the extractor broke)');

        foreach ($blocks[1] as $index => $block) {
            yield 'README block ' . ($index + 1) => [$block[0], dirname(__DIR__), null];
        }

        foreach (scandir(__DIR__ . '/../examples') ?: [] as $file) {
            if (str_ends_with($file, '.php')) {
                yield 'example ' . $file => [
                    (string) file_get_contents(__DIR__ . '/../examples/' . $file),
                    dirname(__DIR__) . '/examples',
                    realpath(__DIR__ . '/../examples/' . $file),
                ];
            }
        }
    }

    /**
     * @dataProvider scriptProvider
     */
    public function testEveryDocumentedSnippetCompiles(string $source, string $workdir, ?string $onDiskPath): void
    {
        $file = $this->materialise($source, $onDiskPath);
        [$status, $stderr] = $this->runPhp(['-l', $file], $workdir);

        $this->assertSame(0, $status, 'php -l failed: ' . $stderr);
    }

    /**
     * @dataProvider scriptProvider
     */
    public function testEveryDocumentedSnippetRunsToCompletion(string $source, string $workdir, ?string $onDiskPath): void
    {
        $file = $this->materialise($source, $onDiskPath);
        [$status, $stderr] = $this->runPhp([
            '-d', 'max_execution_time=' . self::RUN_TIMEOUT_SECONDS,
            '-d', 'display_errors=stderr',
            '-d', 'error_reporting=E_ALL',
            $file,
        ], $workdir);

        $this->assertSame(0, $status, 'snippet exited non-zero; stderr: ' . $stderr);
        $this->assertSame('', trim($stderr), 'snippet printed warnings/errors to stderr');
    }

    private function materialise(string $source, ?string $onDiskPath): string
    {
        // Real examples run in place — their `require __DIR__/../vendor`
        // only resolves from examples/. README bodies get the autoload
        // header the fences deliberately omit.
        if ($onDiskPath !== null) {
            return $onDiskPath;
        }

        $file = $this->scratchDir . '/snippet-' . substr(md5($source), 0, 12) . '.php';
        // The fence carries its own `<?php` for readers; the script gets the
        // header instead, so drop the tag.
        $body = preg_replace('/^\s*<\?php\s*/', '', $source, 1);
        file_put_contents($file, "<?php\ndeclare(strict_types=1);\nrequire "
            . var_export(realpath(self::AUTOLOAD), true) . ";\n" . $body);

        return $file;
    }

    /**
     * @param list<string> $args
     * @return array{0: int, 1: string}
     */
    private function runPhp(array $args, ?string $workdir = null): array
    {
        $errFile = $this->scratchDir . '/stderr-' . bin2hex(random_bytes(4));
        $command = escapeshellarg(PHP_BINARY) . ' ' . implode(' ', array_map('escapeshellarg', $args))
            . ' 2>' . escapeshellarg($errFile);
        $previous = null;
        if ($workdir !== null) {
            $previous = getcwd();
            chdir($workdir);
        }

        try {
            exec($command, $output, $status);
        } finally {
            if ($previous !== null) {
                chdir($previous);
            }
        }

        $stderr = file_exists($errFile) ? (string) file_get_contents($errFile) : '';
        if (file_exists($errFile)) {
            unlink($errFile);
        }

        return [$status, $stderr];
    }
}
