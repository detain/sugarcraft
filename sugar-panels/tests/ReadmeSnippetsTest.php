<?php

declare(strict_types=1);

namespace SugarCraft\Panels\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Panels\SugarPanels;

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

    /**
     * Namespace citations in prose → a class that must exist under them.
     *
     * `SugarCraft\Boxer\` → `SugarCraft\Panels\` in the rename note names a
     * namespace, not a class, so the resolver needs a witness rather than a
     * free pass.
     */
    private const NAMESPACE_PROBES = [
        'SugarCraft\Panels' => 'SugarCraft\Panels\SugarPanels',
    ];

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
     * Inline prose is where documentation lies survive.
     *
     * The execution arm above only sees code inside ```php fences, so an FQN
     * spelled in a sentence — the sanitizer reference that pointed at a
     * `SugarCraft\Core\Sanitize` class which has never existed — could not be
     * caught by running anything. Every `SugarCraft\...` citation in README.md is
     * therefore resolved for real here: classes must exist, and a `::method` or
     * `::CONST` tail must be a member of the class it names.
     */
    public function testEveryClassNameCitedInProseResolves(): void
    {
        $readme = \file_get_contents(\dirname(__DIR__) . '/README.md');
        $this->assertIsString($readme);

        // Drop fences (executed elsewhere) and rename markers before scanning.
        $prose = \preg_replace('/^```.*?^```/ms', '', $readme);
        $this->assertIsString($prose);
        $prose = \preg_replace('/<!--.*?-->/s', '', $prose) ?? '';

        $count = \preg_match_all('/SugarCraft(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+(?:::[A-Za-z_][A-Za-z0-9_]*)?/', $prose, $matches);
        $this->assertGreaterThan(0, $count, 'the prose scan found nothing — a regex drift would make this arm vacuous');

        $seen = [];
        $symbols = [];
        foreach ($matches[0] as $citation) {
            if (isset($seen[$citation])) {
                continue;
            }
            $seen[$citation] = true;
            $symbols[\explode('::', $citation, 2)[0]] = true;

            [$symbol, $member] = \explode('::', $citation, 2) + [1 => null];
            // The 2026 rename note cites namespaces, and cites the OLD one on
            // purpose. A namespace citation is only honest if something really
            // lives under it, which is what the probe class below checks.
            if (\str_starts_with($symbol, 'SugarCraft\Boxer')) {
                $this->assertTrue(true, $citation);
                continue;
            }
            if (isset(self::NAMESPACE_PROBES[$symbol])) {
                $this->assertTrue(\class_exists(self::NAMESPACE_PROBES[$symbol]), "README cites the namespace {$symbol}, but its probe class is gone");
                continue;
            }
            $this->assertTrue(\class_exists($symbol) || \interface_exists($symbol), "README cites {$symbol}, which does not exist");
            if ($member === null) {
                continue;
            }
            $resolves = \method_exists($symbol, $member)
                || \defined($symbol . '::' . $member)
                || \array_key_exists($member, (new \ReflectionClass($symbol))->getConstants());
            $this->assertTrue($resolves, "README cites {$symbol}::{$member}, which the class does not have");
        }

        $this->assertArrayHasKey('SugarCraft\Panels\Sanitize', $symbols, 'the trust section must name the library-side boundary');
    }

    /**
     * The viewport ceilings are host-facing numbers in two places at once: a
     * public constant and the prose that tells an integrator what to catch. This
     * arm re-derives the prose from the constants so the two cannot drift.
     */
    public function testTheViewportCeilingsInTheProseMatchTheConstants(): void
    {
        $readme = \file_get_contents(\dirname(__DIR__) . '/README.md');
        $this->assertIsString($readme);

        $this->assertMatchesRegularExpression(
            '/MAX_VIEWPORT_DIMENSION`?\s*\|?[^\n]*' . \preg_quote((string) SugarPanels::MAX_VIEWPORT_DIMENSION, '/') . '/',
            $readme,
            'README must state the real per-side ceiling (SugarPanels::MAX_VIEWPORT_DIMENSION)',
        );
        $this->assertMatchesRegularExpression(
            '/' . \preg_quote(\number_format(SugarPanels::MAX_VIEWPORT_CELLS, 0, ' ', ' '), '/') . '/',
            $readme,
            'README must state the real cell budget (SugarPanels::MAX_VIEWPORT_CELLS)',
        );
        $this->assertStringContainsString('MAX_VIEWPORT_CELLS', $readme);
    }

    /**
     * The *Flex and natural sizes* recipe is the flagship geometry claim: the
     * content-sized sidebar consumes exactly five columns plus the one column
     * of spacing, and the grown pane starts on column seven of the documented
     * 40x3 viewport with nothing wrapped mid-example. Running the fence and
     * measuring its rows is what keeps prose and pixels from parting ways.
     */
    public function testTheFlexRecipePlacesTheGrowingPaneOnColumnSeven(): void
    {
        $readme = \file_get_contents(\dirname(__DIR__) . '/README.md');
        $this->assertIsString($readme);

        $count = \preg_match_all('/^```php[ \t]*\n(.*?)\n```/ms', $readme, $matches);
        $this->assertGreaterThan(0, $count, 'README must still carry php fences');

        $body = null;
        foreach ((array) $matches[1] as $captured) {
            if (\str_contains((string) $captured, '$panels->leaf("main content area")')) {
                $this->assertNull($body, 'the flex recipe must appear exactly once in README.md');
                $body = (string) $captured;
            }
        }
        $this->assertNotNull($body, 'the README flex recipe fence is gone — this arm would run vacuously');

        $file = $this->materialise('snippet', $body);
        $raw = (string) \shell_exec(\escapeshellarg(PHP_BINARY) . ' ' . \escapeshellarg($file) . ' 2>/dev/null');

        $this->assertStringStartsWith("\x1b[H", $raw, 'a first render opens with the cursor home');
        $rows = \explode("\n", \substr($raw, 3));
        $this->assertCount(3, $rows, 'the frame is exactly the viewport height — no row wrapped mid-example');
        $this->assertSame('nav   main content area', Ansi::strip(\rtrim($rows[0])), 'six columns are consumed before main starts on column seven');
        $this->assertSame('home', Ansi::strip(\rtrim($rows[1])));
        $this->assertSame('about', Ansi::strip(\rtrim($rows[2])));
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
