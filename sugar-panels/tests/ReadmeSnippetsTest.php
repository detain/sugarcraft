<?php

declare(strict_types=1);

namespace SugarCraft\Panels\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Documentation-execution guard (house genre, sugar-spring shape).
 *
 * Every fenced php block in README.md and every examples/*.php file is run
 * as a standalone script: extracted snippet bodies get a real autoload
 * preamble, examples run in place. A doc block that fatals, warns, or emits
 * anything on stderr is a broken promise to the reader — phantom APIs
 * (a Border::sharp() that never existed) and discard-the-chain snippets
 * (immutable with*() results thrown away) both die here, not in the wild.
 */
final class ReadmeSnippetsTest extends TestCase
{
    private const AUTOLOAD = __DIR__ . '/../vendor/autoload.php';

    /** Wall-clock leash per script (max_execution_time inside php, not a kill). */
    private const RUN_TIMEOUT_SECONDS = 15;

    private string $scratchDir = '';

    protected function setUp(): void
    {
        $this->scratchDir = \sys_get_temp_dir() . '/panels-readme-'
            . \getmypid() . '-' . \spl_object_id($this);
        \mkdir($this->scratchDir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) \glob($this->scratchDir . '/*.php') as $file) {
            \unlink($file);
        }
        if (\is_dir($this->scratchDir)) {
            \rmdir($this->scratchDir);
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function scriptProvider(): array
    {
        $cases = [];

        $readme = \file_get_contents(\dirname(__DIR__) . '/README.md');
        \assert($readme !== false);

        // Walk fenced ```php blocks with offsets so each case is named by its
        // block number — the reader-localised failure label is the point.
        $count = \preg_match_all('/^```php[ \t]*\n(.*?)\n```/ms', $readme, $matches, PREG_OFFSET_CAPTURE);
        if ($count === false || $count < 3) {
            throw new \RuntimeException(
                'README.md must carry at least 3 executable php fences (found '
                . ($count === false ? 0 : $count) . '); a silently shrinking doc set is a regression.'
            );
        }
        $block = 0;
        foreach ($matches[1] as $captured) {
            $block++;
            $cases['README block ' . $block] = ['snippet', (string) $captured[0]];
        }

        $examplesDir = \dirname(__DIR__) . '/examples';
        foreach ((array) \scandir($examplesDir) as $entry) {
            if (\is_string($entry) && \str_ends_with($entry, '.php')) {
                $cases['example ' . $entry] = ['example', $examplesDir . '/' . $entry];
            }
        }

        return $cases;
    }

    #[DataProvider('scriptProvider')]
    public function testEveryDocumentedSnippetCompiles(string $kind, string $body): void
    {
        $file = $this->materialise($kind, $body);
        [$status] = $this->runPhp(['-l', $file]);
        $this->assertSame(0, $status, $kind . ' does not lint: ' . $body);
    }

    #[DataProvider('scriptProvider')]
    public function testEveryDocumentedSnippetRunsToCompletion(string $kind, string $body): void
    {
        $file = $this->materialise($kind, $body);
        [$status, $stdout, $stderr] = $this->runPhp([
            '-d', 'max_execution_time=' . self::RUN_TIMEOUT_SECONDS,
            '-d', 'display_errors=stderr',
            '-d', 'error_reporting=E_ALL',
            $file,
        ]);
        $this->assertSame(0, $status, $kind . ' exited ' . $status . ': ' . $stderr);
        $this->assertSame('', \trim($stderr), $kind . ' wrote to stderr: ' . $stderr);
        \assert($stdout !== ''); // a documented render must print something
    }

    /**
     * Snippets run as extracted; examples run in place (their __DIR__-relative
     * autoload stays intact). Both get the same absolute-path materialisation
     * so the scratch copy never shadows a real file.
     */
    private function materialise(string $kind, string $body): string
    {
        if ($kind === 'example') {
            \assert(\is_file($body));

            return $body;
        }

        $file = $this->scratchDir . '/block-' . \substr(\md5($body), 0, 12) . '.php';
        \file_put_contents($file, "<?php\n\ndeclare(strict_types=1);\n\nrequire "
            . \var_export(\realpath(self::AUTOLOAD), true) . ";\n\n" . $body . "\n");

        return $file;
    }

    /**
     * @param list<string> $args
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function runPhp(array $args): array
    {
        $stderrFile = $this->scratchDir . '/stderr-' . \spl_object_id((object) $args);
        $command = \escapeshellarg(PHP_BINARY);
        foreach ($args as $arg) {
            $command .= ' ' . \escapeshellarg($arg);
        }
        $command .= ' 2> ' . \escapeshellarg($stderrFile);

        $status = 0;
        $stdout = \exec($command, $lines, $status);
        \assert($stdout !== false);
        $stderr = (string) \file_get_contents($stderrFile);
        \unlink($stderrFile);

        return [$status, $stdout . "\n" . \implode("\n", $lines), $stderr];
    }
}
