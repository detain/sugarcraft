<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Testing\Snapshot\GoldenFile;
use SugarCraft\Testing\TestResult;

/**
 * Copy-paste contract: the fenced PHP under README ## Quickstart and
 * ### GoldenFile must EXECUTE as written — the review round found the
 * quickstart referencing a never-defined $program and echoing a model
 * object, which is fatal for the newcomer who pastes it in good faith.
 *
 * The GoldenFile fence anchors at `__DIR__` exactly as a reader's test
 * file would; here that token is substituted with a scratch directory so
 * the proof does not write into the repo tree. Semantics are unchanged —
 * only the base path moves.
 */
final class ReadmeSnippetsTest extends TestCase
{
    private string $scratch;

    protected function setUp(): void
    {
        $this->scratch = \sys_get_temp_dir() . '/sugar-testing-readme-' . \bin2hex(\random_bytes(4));
        \mkdir($this->scratch, 0700, true);

        // A stale golden from a previous run would make the guarded seed
        // in the quickstart skip its creation leg; start clean.
        @\unlink(\sys_get_temp_dir() . '/sugar-testing-quickstart.golden');
    }

    protected function tearDown(): void
    {
        @\unlink(\sys_get_temp_dir() . '/sugar-testing-quickstart.golden');
        $this->removeTree($this->scratch);
    }

    public function testTheQuickstartBlockCarriesAnExecutableFence(): void
    {
        $fences = self::phpFencesBetween('## Quickstart', '## API');
        self::assertGreaterThan(0, \count($fences), 'README lost its Quickstart php fence');

        foreach ($fences as $php) {
            eval($php);
        }

        // eval() shares this method's scope, so the fence's variables are
        // visible here — pin that the snippet actually computed the demo.
        self::assertInstanceOf(TestResult::class, $result ?? null, 'quickstart fence must yield $result');
        self::assertSame('count: 1', $result->view);
        self::assertSame($result->view, GoldenFile::load(\sys_get_temp_dir() . '/sugar-testing-quickstart.golden'));
    }

    public function testTheGoldenFileBlockExecutesAgainstAScratchBase(): void
    {
        $fences = self::phpFencesBetween('### GoldenFile', '### Graphics');
        self::assertGreaterThan(0, \count($fences), 'README lost its GoldenFile php fence');

        foreach ($fences as $php) {
            // Substitute only the __DIR__ token; everything else runs verbatim.
            eval(\str_replace('__DIR__', \var_export($this->scratch, true), $php));
        }

        self::assertFileExists($this->scratch . '/fixtures/view.golden');
        self::assertSame('frame', GoldenFile::load($this->scratch . '/fixtures/view.golden'));
    }

    public function testTheSimulatorSectionStillDocumentsTheRealSimulator(): void
    {
        // Cheap tripwire: the rename that introduced TestResult->frames left
        // a window where the README promised methods the class does not
        // have. Every ->member named in the ProgramSimulator/API fences must
        // exist on TestResult (properties) — the fence bodies themselves
        // cover the statics by executing above.
        $text = (string) file_get_contents(\dirname(__DIR__) . '/README.md');
        preg_match_all('/\$result->(\w+)/', $text, $matches);

        $known = get_object_vars(new TestResult(
            new \stdClass(),
            '',
            [],
            '',
        ));
        $known += ['frames' => [], 'droppedAfterStop' => 0];

        foreach (array_unique($matches[1]) as $member) {
            self::assertArrayHasKey($member, $known, "README names \$result->{$member} which TestResult does not expose");
        }
    }

    /**
     * @return list<string> every ```php fence body between the two headings
     */
    private static function phpFencesBetween(string $startHeading, string $endHeading): array
    {
        $text = (string) file_get_contents(\dirname(__DIR__) . '/README.md');

        $start = strpos($text, "\n" . $startHeading . "\n");
        self::assertIsInt($start, "README lost its '{$startHeading}' heading");
        $end = strpos($text, "\n" . $endHeading, $start);
        self::assertIsInt($end, "README lost its '{$endHeading}' heading");

        $segment = substr($text, $start, $end - $start);
        preg_match_all('/^```php\n(.*?)\n```$/ms', $segment, $matches);

        return $matches[1];
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeTree($path);
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
