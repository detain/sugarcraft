<?php

declare(strict_types=1);

namespace SugarCraft\Tools\Tests;

require_once __DIR__ . '/_bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * The guard for `scripts/parallel-tests-make-shards.php`, the operator tool
 * that turns a PHPUnit JUnit XML into the durations table + LPT shard lists
 * `parallel-tests.sh` consumes. Before this round its measured-hazard doctrine
 * — class-first PSR-4 mapping (a trait-inherited test method carries a
 * `file=` pointing at the TRAIT file, which phpunit refuses to execute), the
 * RT-16 fail-closed refusals, and the byte-deterministic manifests the resumed
 * CI runs depend on — surfaced only as a phpunit rc at the next regeneration.
 *
 * RUNS HAPPEN IN A THROWAWAY OUTDIR. Every fixture XML names classes under
 * candy-code's REAL autoload prefixes (`SugarCraft\Code\Tests\...`) because
 * the mapper is deliberately optimistic — `classToFile()` guesses the path
 * from the prefix map and never checks existence — so the fixture proves the
 * CURRENT contract (guess wins, `file=` is the fallback below it) rather than
 * a hypothetical validated one. The `candy-code` lib argument resolves
 * against the repo's committed composer.json on READ only; nothing here writes
 * to the trio, to candy-code, or to scripts/.
 *
 * THE DOCTRINE PIN IS THE POINT OF THE MIXER ROW: a testcase whose class
 * matches a PSR-4 prefix and whose `file=` points at a trait maps to the
 * CLASS-derived path even though that path may not exist, and even though the
 * trait path does. If somebody "fixes" the mapper to validate existence or to
 * prefer `file=`, the bytes below move and this test goes red BY NAME — which
 * is the deliberate moment to decide, in review, whether trait files should
 * be executed as suites. Silence is the failure mode this guards.
 *
 * @internal
 */
final class MakeShardsTest extends TestCase
{
    /** @var list<string> */
    private array $temps = [];

    protected function tearDown(): void
    {
        foreach ($this->temps as $dir) {
            $walk = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($walk as $entry) {
                $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }
            @rmdir($dir);
        }
        $this->temps = [];
    }

    public function testTheXmlPathGroupsClassFirstIntoDeterministicManifests(): void
    {
        $root = \dirname(__DIR__, 2);
        $out = $this->tempDir();
        $xml = $this->tempDir() . '/suite.xml';
        \file_put_contents($xml, $this->fixtureXml($root));

        $run = $this->drive([$xml, '2', $out]);
        $this->assertSame(0, $run['exit'], 'stderr: ' . $run['stderr']);

        // The aggregation table: descending duration, full precision, the
        // exact sprintf layout parallel-tests.sh's --durations reader parses.
        $this->assertSame(
            "candy-code/tests/AlphaTest.php\t3.000\t2\n"
            . "candy-code/tests/Sub/BetaTest.php\t2.000\t1\n"
            . "candy-code/src/Engine.php\t1.000\t1\n"
            . "candy-code/tests/Concerns/Mixer.php\t0.500\t1\n"
            . "scripts/parallel-tests.sh\t0.250\t1\n",
            (string) \file_get_contents($out . '/durations.tsv'),
            'the per-file durations bytes moved — class-first grouping or the sort rule '
            . 'changed; the Mixer row is the trait-bleed doctrine pin and scripts/parallel-tests.sh '
            . 'is the file= fallback pin',
        );

        // LPT with the tie-break the header promises (lowest index first, so a
        // resumed run rebuilds the same plan): 0.5 lands on the 3.0/3.0 tie in
        // bucket 0, 0.25 on the now-lighter bucket 1.
        $this->assertSame(
            "candy-code/tests/AlphaTest.php\ncandy-code/tests/Concerns/Mixer.php\n",
            (string) \file_get_contents($out . '/shard-0.list'),
        );
        $this->assertSame(
            "candy-code/tests/Sub/BetaTest.php\ncandy-code/src/Engine.php\nscripts/parallel-tests.sh\n",
            (string) \file_get_contents($out . '/shard-1.list'),
        );
        $this->assertSame(
            "0\t3.500\t2\n1\t3.250\t3\n",
            (string) \file_get_contents($out . '/plan-k2.tsv'),
        );

        // Determinism is the tool's own header claim ("Same input => byte-
        // identical manifests, so a resumed run rebuilds the same plan").
        $out2 = $this->tempDir();
        $this->drive([$xml, '2', $out2]);
        foreach (['durations.tsv', 'shard-0.list', 'shard-1.list', 'plan-k2.tsv'] as $artifact) {
            $this->assertSame(
                (string) \file_get_contents($out . '/' . $artifact),
                (string) \file_get_contents($out2 . '/' . $artifact),
                "$artifact is not byte-stable across two runs of the same input",
            );
        }
    }

    public function testAAlreadyAggregatedTsvPlansWithoutReMeasuring(): void
    {
        $out = $this->tempDir();
        $tsv = $this->tempDir() . '/durations.tsv';
        \file_put_contents($tsv, "a/T.php\t2\t3\nb/T.php\t1\t2\n");

        $run = $this->drive([$tsv, '2', $out]);
        $this->assertSame(0, $run['exit'], 'stderr: ' . $run['stderr']);
        $this->assertFileDoesNotExist(
            $out . '/durations.tsv',
            'a TSV input re-wrote durations — the second output (durations.tsv) is an XML-only '
            . 'artifact per the tool header; the plan inputs must stay one-way',
        );
        $this->assertSame("a/T.php\n", (string) \file_get_contents($out . '/shard-0.list'));
        $this->assertSame("b/T.php\n", (string) \file_get_contents($out . '/shard-1.list'));
    }

    public function testAnUnmappableClassIsRefusedByNameAtExitThree(): void
    {
        $xml = $this->tempDir() . '/suite.xml';
        \file_put_contents($xml, <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <testsuites>
              <testsuite name="x" tests="1" assertions="1" errors="0" failures="0" skipped="0" time="1.0">
                <testcase class="BareTraitUser" name="testIt" time="1.0"/>
              </testsuite>
            </testsuites>
            XML);
        $run = $this->drive([$xml, '1', $this->tempDir()]);
        $this->assertSame(3, $run['exit'], 'stderr: ' . $run['stderr']);
        $this->assertStringContainsString('unmappable testcase class: BareTraitUser', $run['stderr']);
    }

    public function testAnEmptyOrUnparseableJunitIsRefusedAtExitTwo(): void
    {
        $empty = $this->tempDir() . '/empty.xml';
        \file_put_contents($empty, <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <testsuites>
              <testsuite name="x" tests="0" assertions="0" errors="0" failures="0" skipped="0" time="0.0"/>
            </testsuites>
            XML);
        $run = $this->drive([$empty, '1', $this->tempDir()]);
        $this->assertSame(2, $run['exit'], 'stderr: ' . $run['stderr']);
        $this->assertStringContainsString('zero testcases', $run['stderr']);

        $trunc = $this->tempDir() . '/trunc.xml';
        \file_put_contents($trunc, '<?xml version="1.0"?><testsuites><testsuite name="x" test');
        $run = $this->drive([$trunc, '1', $this->tempDir()]);
        $this->assertSame(2, $run['exit'], 'stderr: ' . $run['stderr']);
        $this->assertStringContainsString('unparseable junit', $run['stderr']);
    }

    public function testTheUsageGuardsExitOne(): void
    {
        $missing = \sys_get_temp_dir() . '/make-shards-none-' . \bin2hex(\random_bytes(6)) . '.xml';
        foreach ([
            'K below one' => [$missing, '0', $this->tempDir()],
            'a non-slug lib' => [$missing, '1', $this->tempDir(), '../etc'],
            'a lib with no manifest' => [$missing, '1', $this->tempDir(), 'sugar-nope'],
        ] as $label => $args) {
            $run = $this->drive($args);
            $this->assertSame(1, $run['exit'], "$label stderr: " . $run['stderr']);
        }
    }

    // -----------------------------------------------------------------
    // Instruments
    // -----------------------------------------------------------------

    private function fixtureXml(string $repoRoot): string
    {
        $traitFile = $repoRoot . '/candy-code/tests/Support/RefusesAnUnreadableSourceTrait.php';
        $scriptFile = $repoRoot . '/scripts/parallel-tests.sh';

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <testsuites>
              <testsuite name="candy-code" tests="6" assertions="6" errors="0" failures="0" skipped="0" time="6.75">
                <testcase class="SugarCraft\Code\Tests\AlphaTest" name="testOne" time="2.0"/>
                <testcase class="SugarCraft\Code\Tests\AlphaTest" name="testTwo" time="1.0"/>
                <testcase class="SugarCraft\Code\Tests\Sub\BetaTest" name="testOne" time="2.0"/>
                <testcase class="SugarCraft\Code\Engine" name="testOne" time="1.0"/>
                <testcase class="SugarCraft\Code\Tests\Concerns\Mixer" name="testBled" time="0.5" file="{$traitFile}"/>
                <testcase class="Legacy\NotMapped" name="testOld" time="0.25" file="{$scriptFile}"/>
              </testsuite>
            </testsuites>
            XML;
    }

    /**
     * @param list<string> $args
     *
     * @return array{exit: int, stderr: string}
     */
    private function drive(array $args): array
    {
        $cmd = \escapeshellarg(\PHP_BINARY)
            . ' ' . \escapeshellarg(\dirname(__DIR__, 2) . '/scripts/parallel-tests-make-shards.php')
            . ' ' . \implode(' ', \array_map('escapeshellarg', $args)) . ' 2>&1';
        $lines = [];
        $exitCode = 0;
        \exec($cmd, $lines, $exitCode);

        return ['exit' => $exitCode, 'stderr' => \implode("\n", $lines)];
    }

    private function tempDir(): string
    {
        $dir = \sys_get_temp_dir() . '/make-shards-' . \bin2hex(\random_bytes(6));
        \mkdir($dir, 0777, true);
        $this->temps[] = $dir;

        return $dir;
    }
}
