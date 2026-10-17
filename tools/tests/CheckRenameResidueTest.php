<?php

declare(strict_types=1);

namespace SugarCraft\Tools\Tests;

require_once __DIR__ . '/_bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * Fixture tests for tools/check-rename-residue.php (plan_rename.md Step 0.4).
 *
 * Each test builds a throwaway git repo plus a throwaway renames map, runs
 * the checker as a subprocess against them (SUGARCRAFT_RENAME_ROOT pins the
 * root, exactly like tools/rename-lib.php's fixture suite), and pins stdout
 * byte-for-byte where the finding set is fully determined. The discriminating
 * pair the plan demands — clean fixture exits 0, one leftover exits 1 naming
 * the line, an `@rename-keep` line is ignored — lives in the first three
 * tests; the rest pin the rule vocabulary, the status semantics, and the
 * C.3 exclusion reuse.
 *
 * Fixture vocabulary is candy-foo/sugar-bar (renamed entry) and
 * candy-quiz/sugar-quoz (pending entry); nothing here may resemble any real
 * slug pair, and the checker excludes its own tool and test files anyway.
 */
final class CheckRenameResidueTest extends TestCase
{
    use TmpTree;

    private const TOOL = __DIR__ . '/../check-rename-residue.php';

    /** Renamed entry: every rule has a token to hunt. */
    private const ENTRY_FOO = [
        'batch' => 'T',
        'kind' => 'rename',
        'old' => 'candy-foo',
        'new' => 'sugar-bar',
        'ns_old' => 'Foo',
        'ns_new' => 'Bar',
        'type' => 'lib',
        'bins' => ['bin/foozip' => 'bin/sugar-bar'],
        'extra_literals' => ['foo-legacy' => 'sugar-bar'],
        'status' => 'renamed',
    ];

    /** Pending entry: legitimately still carries old vocabulary. */
    private const ENTRY_QUIZ = [
        'batch' => 'T',
        'kind' => 'rename',
        'old' => 'candy-quiz',
        'new' => 'sugar-quoz',
        'ns_old' => 'Quiz',
        'ns_new' => 'Quoz',
        'type' => 'lib',
        'bins' => [],
        'status' => 'pending',
    ];

    /** @var list<string> every temp dir made, all removed in tearDown */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            $this->removeDir($dir);
        }
        $this->dirs = [];
    }

    public function testHelpPrintsUsageAndExitsZero(): void
    {
        $result = $this->runTool($this->makeRepo([]), ['--help']);

        $this->assertSame(0, $result['rc']);
        $this->assertStringContainsString('Usage: php tools/check-rename-residue.php', $result['out']);
    }

    public function testCleanTreeExitsZeroAndReportsNothing(): void
    {
        // New vocabulary plus the safe lookaround shapes each rule must NOT
        // match: candy-foobar (R3 suffix), CandyFooz (R2 suffix),
        // SugarCraft\FooBar (R1 suffix), $candyfooDir (R4 lookbehind).
        $result = $this->runTool($this->makeRepo([
            'README.md' => "sugar-bar is the library now\n"
                . "use SugarCraft\\Bar\\Thing;\n"
                . "final class SugarBar {}\n"
                . "export SUGAR_BAR_TOKEN=1\n"
                . "run `sugar-bar` via bin/sugar-bar\n",
            'notes.md' => "candy-foobar keeps shipping separately\n"
                . "class CandyFooz is unrelated\n"
                . "use SugarCraft\\FooBar\\Legacy;\n"
                . 'call $candyfooDir method' . "\n",
        ]), ['--old=candy-foo']);

        $this->assertSame(0, $result['rc'], $result['out']);
        $this->assertSame("check-rename-residue: 1 entries checked, 0 findings\n", $result['out']);
    }

    public function testOneLeftoverExitsOneAndNamesTheLine(): void
    {
        $result = $this->runTool($this->makeRepo([
            'README.md' => "sugar-bar is the library now\n",
            'CHANGELOG.md' => "line one is clean\nrequires sugarcraft/candy-foo instead\n",
        ]), ['--old=candy-foo']);

        $this->assertSame(1, $result['rc']);
        $this->assertSame(
            "CHANGELOG.md:2: [R3] candy-foo\n"
            . "check-rename-residue: 1 entries checked, 1 findings\n",
            $result['out'],
        );
    }

    public function testRenameKeepLineIsIgnored(): void
    {
        $result = $this->runTool($this->makeRepo([
            'CHANGELOG.md' => "requires sugarcraft/candy-foo instead <!-- @rename-keep -->\n",
        ]), ['--old=candy-foo']);

        $this->assertSame(0, $result['rc'], $result['out']);
    }

    public function testBrandProtectExemptsSiblingBrandAndEnvFromResidue(): void
    {
        $files = [
            'keep.md' => "CandyAnsiInspect ships as candy-ansi-inspect with CANDY_ANSI_INSPECT_BIN\n",
            'flag.md' => "brand CandyAnsi is stale\n",
        ];
        $entry = ['batch' => 'T', 'kind' => 'rename', 'old' => 'candy-ansi', 'new' => 'sugar-ansi-parser',
            'ns_old' => 'Ansi', 'ns_new' => 'AnsiParser', 'type' => 'lib', 'bins' => [],
            'protect' => ['candy-ansi-inspect'], 'status' => 'renamed'];
        $brand = $entry;
        $brand['brand_protect'] = true;

        $with = $this->runTool($this->makeRepo($files), ['--old=candy-ansi'], $this->makeMap([$brand]));
        $this->assertSame(1, $with['rc']);
        $this->assertSame(
            "flag.md:1: [R2] CandyAnsi\n"
            . "check-rename-residue: 1 entries checked, 1 findings\n",
            $with['out'],
            'brand_protect must keep the sibling brand/env out of the findings',
        );

        $without = $this->runTool($this->makeRepo($files), ['--old=candy-ansi'], $this->makeMap([$entry]));
        $this->assertSame(
            "flag.md:1: [R2] CandyAnsi\n"
            . "keep.md:1: [R2] CandyAnsi\n"
            . "keep.md:1: [R5] CANDY_ANSI_\n"
            . "check-rename-residue: 1 entries checked, 3 findings\n",
            $without['out'],
            'the default must keep folding uppercase continuations (batch F law)',
        );
    }

    public function testEveryRuleShapeIsCaughtWithItsTagAndLine(): void
    {
        $result = $this->runTool($this->makeRepo([
            'residue.md' => "use SugarCraft\\Foo\\Base;\n"
                . "final class CandyFoo {}\n"
                . "pkg sugarcraft/candy-foo\n"
                . "alias candyfoo=run\n"
                . "export CANDY_FOO_TOKEN=1 CANDYFOO_TOKEN=2\n"
                . "the foo-legacy alias\n"
                . "run `foozip` daily\n",
            'docs/cli.md' => "call\n\$ foozip --help\nnow\n",
        ]), ['--old=candy-foo']);

        $this->assertSame(1, $result['rc']);
        // Deduplicated, ordered per (path, line, R1..R7,BIN); the two R5
        // prefixes on one line are two findings, in discovery order.
        $this->assertSame(
            "docs/cli.md:2: [BIN] \$ foozip\n"
            . "residue.md:1: [R1] SugarCraft\\Foo\n"
            . "residue.md:2: [R2] CandyFoo\n"
            . "residue.md:3: [R3] candy-foo\n"
            . "residue.md:4: [R4] candyfoo\n"
            . "residue.md:5: [R5] CANDY_FOO_\n"
            . "residue.md:5: [R5] CANDYFOO_\n"
            . "residue.md:6: [R7] foo-legacy\n"
            . "residue.md:7: [BIN] `foozip\n"
            . "check-rename-residue: 1 entries checked, 9 findings\n",
            $result['out'],
        );
    }

    public function testBinOnlyResidueSurvivesTheMightMatchPrefilter(): void
    {
        // RenameLib::mightMatch has no needle for the bare bin name "foozip"
        // and this file carries no other old token (lowercase f-o-o never
        // equals the "Foo" namespace needle) — the checker's own bin-bare
        // prefilter extension is what keeps this file on the scan list.
        $result = $this->runTool($this->makeRepo([
            'docs/cli.md' => "quick start:\n\$ foozip go\n",
        ]), ['--old=candy-foo']);

        $this->assertSame(1, $result['rc'], $result['out']);
        $this->assertStringContainsString("docs/cli.md:2: [BIN] \$ foozip\n", $result['out']);
    }

    public function testAllDoneModeSkipsPendingEntries(): void
    {
        $root = $this->makeRepo([
            'notes.md' => "candy-quiz still lives here while pending\n",
        ]);

        foreach ([[], ['--all-done']] as $args) {
            $result = $this->runTool($root, $args);
            $this->assertSame(0, $result['rc'], $result['out']);
            $this->assertSame("check-rename-residue: 1 entries checked, 0 findings\n", $result['out']);
        }
    }

    public function testOldFlagScansPendingEntryAnyway(): void
    {
        $result = $this->runTool($this->makeRepo([
            'notes.md' => "candy-quiz still lives here while pending\n",
        ]), ['--old=candy-quiz']);

        $this->assertSame(1, $result['rc']);
        $this->assertSame(
            "notes.md:1: [R3] candy-quiz\n"
            . "check-rename-residue: 1 entries checked, 1 findings\n",
            $result['out'],
        );
    }

    public function testFullyPendingMapChecksNothingAndExitsZero(): void
    {
        $pending = self::ENTRY_FOO;
        $pending['status'] = 'pending';
        $map = $this->makeMap([$pending, self::ENTRY_QUIZ]);

        // The current real-repo shape: zero executed renames means the
        // checker walks nothing, even though the tree is full of olds.
        $result = $this->runTool($this->makeRepo([
            'notes.md' => "sugarcraft/candy-foo and candy-quiz\n",
        ]), [], $map);

        $this->assertSame(0, $result['rc'], $result['out']);
        $this->assertSame("check-rename-residue: 0 entries checked, 0 findings\n", $result['out']);
    }

    public function testExcludedPathsWithResidueAreNotScanned(): void
    {
        // C.3: the plan file, docs/old/, any vendor/ segment, and binary
        // extensions are out of the scanned file set by construction.
        $result = $this->runTool($this->makeRepo([
            'plan_rename.md' => "sugarcraft/candy-foo\n",
            'docs/old/rant.md' => "sugarcraft/candy-foo\n",
            'pkg/vendor/v.md' => "sugarcraft/candy-foo\n",
            'img/logo.png' => "sugarcraft/candy-foo\n",
        ]), ['--old=candy-foo']);

        $this->assertSame(0, $result['rc'], $result['out']);
    }

    public function testUnknownSlugIsARefusalNotACleanRun(): void
    {
        $result = $this->runTool($this->makeRepo(['notes.md' => "clean\n"]), ['--old=candy-nope']);

        $this->assertSame(2, $result['rc']);
        $this->assertStringContainsString('no map entry with old="candy-nope"', $result['out']);
    }

    public function testMissingMapFileIsARefusal(): void
    {
        $result = $this->runTool($this->makeRepo(['notes.md' => "clean\n"]), ['--map=/nonexistent/renames.json']);

        $this->assertSame(2, $result['rc']);
        $this->assertStringContainsString('check-rename-residue:', $result['out']);
    }

    public function testUnknownArgumentIsARefusal(): void
    {
        $result = $this->runTool($this->makeRepo(['notes.md' => "clean\n"]), ['--nope']);

        $this->assertSame(2, $result['rc']);
        $this->assertStringContainsString('unrecognised argument: --nope', $result['out']);
    }

    /** @param array<string, string> $files @return array{rc:int,out:string} */
    private function runTool(string $root, array $args, ?string $map = null): array
    {
        $flags = implode(' ', array_map('escapeshellarg', $args));
        // An args-provided --map (the refusal test) must not be shadowed by
        // the fixture map, so the default is only appended when none was given.
        if (!array_filter($args, static fn(string $a): bool => str_starts_with($a, '--map='))) {
            $flags .= ' --map=' . escapeshellarg($map ?? $this->makeMap([self::ENTRY_FOO, self::ENTRY_QUIZ]));
        }

        return $this->runShell($root, 'php ' . escapeshellarg(self::TOOL) . ' ' . $flags . ' 2>&1');
    }

    /** @param list<array<string, mixed>> $entries */
    private function makeMap(array $entries): string
    {
        $dir = $this->makeTmpTree('residue-map-');
        $this->dirs[] = $dir;
        $path = "$dir/map.json";
        file_put_contents($path, json_encode(['entries' => $entries], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    /** @param array<string, string> $files */
    private function makeRepo(array $files): string
    {
        $root = $this->makeTmpTree('residue-repo-');
        $this->dirs[] = $root;
        foreach ($files as $rel => $contents) {
            $this->writeFile($root, $rel, $contents);
        }
        $this->runShell($root, 'git init -q && git add -A'
            . ' && git -c user.name=fixture -c user.email=[EMAIL] commit -qm base');

        return $root;
    }

    private function writeFile(string $root, string $rel, string $contents): void
    {
        $path = "$root/$rel";
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }
        file_put_contents($path, $contents);
    }

    /** @return array{rc:int,out:string} */
    private function runShell(string $root, string $cmd): array
    {
        $env = [
            'PATH' => (string) getenv('PATH') . ':/usr/local/bin:/usr/bin:/bin',
            'HOME' => $root,
            'LANG' => 'C',
            'SUGARCRAFT_RENAME_ROOT' => $root,
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_CONFIG_SYSTEM' => '/dev/null',
        ];
        $pipes = [];
        $process = proc_open(['/bin/sh', '-c', $cmd], [1 => ['pipe', 'w']], $pipes, $root, $env);
        $this->assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return ['rc' => proc_close($process), 'out' => $out];
    }
}
