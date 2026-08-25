<?php

declare(strict_types=1);

/**
 * The only automated guard `tools/` has, and until round 57 nothing ran it.
 *
 * WHAT THIS DOC-BLOCK USED TO SAY, and it was wrong in a way that mattered
 * (rule 7): "Runs as a standalone script: php tools/tests/CheckPathReposTest.php
 * — bootstraps from monorepo root vendor/autoload.php". WHAT IS TRUE NOW, and
 * was true then: running a PHPUnit `TestCase` file with the PHP binary defines
 * a class and returns. MEASURED on PHP 8.3.6:
 * `php tools/tests/CheckPathReposTest.php` prints NOTHING and exits 0. The
 * documented way to run this file was a silent green, which is the same shape
 * as the defects the suite it guards exists to catch. And the root `vendor/`
 * carries no PHPUnit, so the second half was wrong too. WHY THE SENTENCE IS
 * REWRITTEN RATHER THAN DELETED: someone believed it, and the next reader will
 * reach for the same command.
 *
 * HOW TO RUN IT, both of which are exercised:
 *
 *     candy-core/vendor/bin/phpunit --no-configuration \
 *         --bootstrap candy-core/vendor/autoload.php tools/tests/
 *     phpunit --no-configuration tools/tests/     # a phpunit PHAR, as CI uses
 *
 * IT WAS DORMANT FOR SIX MONTHS AND IT PASSES. No `phpunit.xml` in the tree
 * references `tools/tests/`, no CI job ran it, and nothing autoloads
 * `SugarCraft\Tools\Tests`. `.github/workflows/ci.yml`'s `path-repo-check`
 * job runs it now — the same job that gates on the script under test, which is
 * where a guard for that script belongs.
 *
 * WHAT IT COVERS. `--fix`, `--help`, the report-only default and
 * `--no-lib-path-repos` were here already. `--unused` — the pass that is a HARD
 * CI GATE on candidate-grade output, and the one E487 is about — had no test at
 * all, in either polarity: not that it finds a dead require, and not that the
 * `deferred-wiring` release valve suppresses one. Both are here now, with the
 * IDLE_DEFERRAL arm that stops the valve becoming an exemption.
 */

namespace SugarCraft\Tools\Tests;

// Standalone script: the script under test (check-path-repos.php) is invoked as
// a subprocess, so no SugarCraft lib classes are needed — only PHPUnit itself.
//
// The root vendor/ carries no phpunit (root require-dev is phpstan only), so
// borrow the first per-lib vendor that has one, then fall back to a global
// composer install. The previous single hardcoded ~/.composer path made this
// file unrunnable on any machine but its author's — it died in require_once
// before PHPUnit could report anything.
$autoloadCandidates = [];
foreach (\glob(\dirname(__DIR__, 2) . '/*/vendor/autoload.php') ?: [] as $candidate) {
    if (\is_file(\dirname($candidate) . '/bin/phpunit')) {
        $autoloadCandidates[] = $candidate;
    }
}
$autoloadCandidates[] = ($_SERVER['HOME'] ?? '') . '/.composer/vendor/autoload.php';
$autoloadCandidates[] = ($_SERVER['HOME'] ?? '') . '/.config/composer/vendor/autoload.php';

// AND NOT AT ALL WHEN PHPUNIT IS ALREADY HERE. A phpunit PHAR bundles its own
// classes, so the hunt below finds nothing it needs and the `exit(2)` fired on
// a runner that was perfectly capable of running this file — which is how a
// guard ends up unrunnable in the one environment you want it in.
$loaded = \class_exists(\PHPUnit\Framework\TestCase::class, false);
foreach ($loaded ? [] : $autoloadCandidates as $candidate) {
    if (\is_file($candidate)) {
        require_once $candidate;
        $loaded = true;
        break;
    }
}
if (!$loaded) {
    \fwrite(\STDERR, "CheckPathReposTest: no autoloader with PHPUnit found. Run `composer install` in any lib.\n");
    exit(2);
}

use PHPUnit\Framework\TestCase;

final class CheckPathReposTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = \sys_get_temp_dir() . '/check_path_repos_test_' . \uniqid('', true);
        \mkdir($this->tmpDir . '/lib-b', 0777, true);
        \mkdir($this->tmpDir . '/lib-a', 0777, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp directory.
        if ($this->tmpDir !== '' && \is_dir($this->tmpDir)) {
            $this->removeDir($this->tmpDir);
        }
    }

    private function removeDir(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                \rmdir($item->getPathname());
            } else {
                \unlink($item->getPathname());
            }
        }
        \rmdir($dir);
    }

    private function writeComposerJson(string $dir, array $data): void
    {
        $json = \json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        \file_put_contents($dir . '/composer.json', $json . "\n");
    }

    /**
     * `sugarcraft/lib-a` to the directory name `lib-a`.
     *
     * IT WAS DEAD AND IT WAS BROKEN, and the second fact is what the first one
     * hid. It read `preg_replace('#^sugarcraft/##', …)`: `#` opens the pattern
     * and `#` closes it, so the trailing `#` is a MODIFIER, and there is no
     * such modifier. MEASURED on PHP 8.3.6 it returns `null` with
     * `preg_last_error_msg()` of "Internal error" — every call would have
     * produced an empty slug. Nothing called it, in a file nothing ran, so
     * nothing said so for six months.
     *
     * Wired rather than deleted: the fixtures below name their packages and
     * their directories separately, and this is the mapping between them.
     * {@see testTheSlugMappingIsTheOneTheFixturesRelyOn()} pins it, so a
     * silent `null` cannot come back.
     */
    private function makeSlug(string $name): string
    {
        return (string) \preg_replace('#^sugarcraft/#', '', $name);
    }

    /**
     * The slug mapping answers what the fixture layout assumes.
     *
     * BOTH POLARITIES: a prefixed name loses the prefix, an unprefixed one is
     * unchanged, and — the half that would have caught the original — the
     * result is never empty for a non-empty input.
     */
    public function testTheSlugMappingIsTheOneTheFixturesRelyOn(): void
    {
        $this->assertSame('lib-a', $this->makeSlug('sugarcraft/lib-a'));
        $this->assertSame('lib-a', $this->makeSlug('lib-a'));
        $this->assertSame('other/lib-a', $this->makeSlug('other/lib-a'));
        $this->assertNotSame('', $this->makeSlug('sugarcraft/lib-b'));
    }

    private function runScript(string $fixtureRoot, array $args = []): array
    {
        // Use SUGARCRAFT_CHECK_PATH_REPOS_ROOT env var to point the script
        // at the fixture directory instead of the real monorepo.
        $env = 'SUGARCRAFT_CHECK_PATH_REPOS_ROOT=' . \escapeshellarg($fixtureRoot);
        $script = __DIR__ . '/../check-path-repos.php';
        $cmd = \escapeshellcmd(PHP_BINARY) . ' ' . \escapeshellarg($script);
        foreach ($args as $arg) {
            $cmd .= ' ' . \escapeshellarg($arg);
        }
        $fullCmd = $env . ' ' . $cmd;
        $output = [];
        $exitCode = 0;
        \exec($fullCmd . ' 2>&1', $output, $exitCode);
        return ['exit' => $exitCode, 'output' => \implode("\n", $output)];
    }

    public function testFixInsertsMissingPathRepoEntry(): void
    {
        // Set up lib-b (the dependency) — must be a sugarcraft/ prefix so
        // the script's sugarcraft/ filter catches it.
        $this->writeComposerJson($this->tmpDir . '/lib-b', [
            'name' => 'sugarcraft/lib-b',
            'type' => 'library',
            'require' => ['php' => '^8.3'],
            'repositories' => [],
            'autoload' => ['psr-4' => ['SugarCraft\LibB\\' => 'src/']],
            'minimum-stability' => 'dev',
        ]);

        // Set up lib-a (the consumer) — requires sugarcraft/lib-b@dev but has NO
        // path-repo entry.
        $this->writeComposerJson($this->tmpDir . '/lib-a', [
            'name' => 'sugarcraft/lib-a',
            'type' => 'library',
            'require' => [
                'php' => '^8.3',
                'sugarcraft/lib-b' => '@dev',
            ],
            'repositories' => [],  // deliberately missing path-repo for lib-b.
            'autoload' => ['psr-4' => ['SugarCraft\LibA\\' => 'src/']],
            'minimum-stability' => 'dev',
        ]);

        // Run script WITHOUT --fix — should detect issue and exit non-zero.
        // We redirect to a temporary monorepo root (our temp dir) so the script
        // scans only our fixture libs.
        $resultBefore = $this->runScript($this->tmpDir);
        $this->assertNotEquals(0, $resultBefore['exit'], 'Script should exit non-zero when path-repo is missing');
        $this->assertStringContainsString('lib-a', $resultBefore['output']);
        // Matches the wording the script actually emits. It said "no path-repo
        // entry" when this assertion was written; the drift went unnoticed
        // because the file died in require_once on every machine but its
        // author's, so nothing ever executed it.
        $this->assertStringContainsString('missing path-repo for lib-b', $resultBefore['output']);

        // Apply --fix.
        $resultFix = $this->runScript($this->tmpDir, ['--fix']);
        $this->assertEquals(0, $resultFix['exit'], 'Script with --fix should exit 0 when all issues are fixable');

        // Verify the lib-a composer.json was updated.
        $libAComposer = \json_decode(\file_get_contents($this->tmpDir . '/lib-a/composer.json'), true);
        $this->assertIsArray($libAComposer);

        $repos = $libAComposer['repositories'] ?? [];
        $this->assertNotEmpty($repos, 'repositories should not be empty after --fix');

        // Find the path-repo entry for lib-b.
        $found = false;
        foreach ($repos as $repo) {
            if (($repo['type'] ?? '') === 'path'
                && ($repo['url'] ?? '') === '../lib-b'
                && ($repo['options']['symlink'] ?? false) === true
            ) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'lib-a composer.json should contain path-repo entry for ../lib-b');

        // After fix, running without args should be clean.
        $resultAfter = $this->runScript($this->tmpDir);
        $this->assertEquals(0, $resultAfter['exit'], 'Script should exit 0 after fix on clean closure');
        $this->assertStringContainsString('closure clean', $resultAfter['output']);
    }

    public function testHelpFlagExitsZeroAndPrintsUsage(): void
    {
        // Running from the monorepo root (not a fixture dir) for the help test.
        $result = $this->runScript(\dirname(__DIR__, 2), ['--help']);
        $this->assertEquals(0, $result['exit']);
        $this->assertStringContainsString('Usage:', $result['output']);
        $this->assertStringContainsString('--fix', $result['output']);
    }

    /**
     * The `--help` block naming what CI runs IS what CI runs.
     *
     * WHY THIS EXISTS. Round 57 added a "WHAT CI RUNS, IN ORDER" block to
     * `--help` in the same commit whose whole subject was a guard that nothing
     * ran, and put no guard on the block. It drifts the moment a step is added
     * to the job — and it already had drifted, silently: `--colors=never` was
     * in the workflow and absent from the help. A paragraph of prose about CI
     * that nothing checks is worth less than no paragraph, because a reader
     * runs it and believes they have reproduced the job.
     *
     * BOTH DIRECTIONS, AS A SET. A step added to the workflow and not to the
     * help reds; a command left in the help after its step is deleted reds too.
     * Comparing sets rather than asserting containment is what makes the second
     * half work.
     */
    public function testTheHelpTextNamesExactlyWhatTheWorkflowRuns(): void
    {
        $root = \dirname(__DIR__, 2);
        $workflow = \file_get_contents($root . '/.github/workflows/ci.yml');
        $this->assertIsString($workflow, 'ci.yml is unreadable, so this comparison speaks for nothing');

        $fromWorkflow = $this->pathRepoCheckCommands($workflow);
        $fromHelp = $this->helpTextCiCommands($this->runScript($root, ['--help'])['output']);

        // KNOWN-POSITIVE FIRST (rule 15). Both sides are parsed out of text,
        // and two empty lists compare equal — which is exactly what a parser
        // that has stopped matching produces on both sides at once.
        $this->assertNotSame(
            [],
            $fromWorkflow,
            'the path-repo-check job parsed to no commands at all, so the comparison below is '
            . 'two empty lists agreeing with each other. The job block or the run: shape has '
            . 'changed and this parser has to be taught it',
        );
        $this->assertNotSame(
            [],
            $fromHelp,
            'the --help CI block parsed to no commands at all; same problem, other side',
        );

        $this->assertSame(
            $fromWorkflow,
            $fromHelp,
            "the --help text's \"WHAT CI RUNS\" block and the path-repo-check job in "
            . '.github/workflows/ci.yml have diverged. A contributor reads that block to '
            . 'reproduce the job before pushing; if it is missing a step they are failed by a '
            . 'check they had no way to run. Update the block in tools/check-path-repos.php, '
            . 'not this assertion',
        );
    }

    /**
     * Every shell command the `path-repo-check` job runs, normalised.
     *
     * @return list<string> sorted
     */
    private function pathRepoCheckCommands(string $workflow): array
    {
        $lines = \explode("\n", $workflow);
        $commands = [];
        $inJob = false;
        $blockIndent = null;

        foreach ($lines as $line) {
            if (\preg_match('/^  ([A-Za-z0-9_-]+):\s*$/', $line, $m) === 1) {
                $inJob = $m[1] === 'path-repo-check';
                $blockIndent = null;

                continue;
            }
            if (!$inJob) {
                continue;
            }
            if ($blockIndent !== null) {
                // Inside a `run: |` block scalar: it ends at the first line
                // that is non-empty and not more-indented than the opener.
                $indent = \strlen($line) - \strlen(\ltrim($line));
                if (\trim($line) !== '' && $indent <= $blockIndent) {
                    $blockIndent = null;
                } elseif (\trim($line) !== '') {
                    $commands[] = $this->normaliseCommand($line);

                    continue;
                } else {
                    continue;
                }
            }
            if (\preg_match('/^(\s*)- run:\s*\|\s*$/', $line, $m) === 1) {
                $blockIndent = \strlen($m[1]);

                continue;
            }
            if (\preg_match('/^(\s*)run:\s*\|\s*$/', $line, $m) === 1) {
                $blockIndent = \strlen($m[1]);

                continue;
            }
            if (\preg_match('/^\s*-?\s*run:\s*(\S.*)$/', $line, $m) === 1) {
                $commands[] = $this->normaliseCommand($m[1]);
            }
        }

        return $this->tidyCommands($commands);
    }

    /**
     * The commands the `--help` text's CI block names, normalised the same way.
     *
     * @return list<string> sorted
     */
    private function helpTextCiCommands(string $help): array
    {
        $marker = \strpos($help, 'WHAT CI RUNS, IN ORDER');
        if ($marker === false) {
            return [];
        }
        $rest = \substr($help, $marker);

        // The block is the run of four-space-indented lines. Continuations end
        // in a backslash and are joined before anything is split.
        if (\preg_match('/\n((?:    \S[^\n]*\n|      \S[^\n]*\n)+)/', $rest, $m) !== 1) {
            return [];
        }
        $block = \str_replace(["\\\n", "\n      "], [' ', ' '], $m[1]);

        $commands = [];
        foreach (\explode("\n", $block) as $line) {
            foreach (\preg_split('/&&|;/', $line) ?: [] as $piece) {
                $commands[] = $this->normaliseCommand($piece);
            }
        }

        return $this->tidyCommands($commands);
    }

    /** Trailing comment stripped, whitespace collapsed. */
    private function normaliseCommand(string $command): string
    {
        $command = (string) \preg_replace('/#.*$/', '', $command);

        return \trim((string) \preg_replace('/\s+/', ' ', $command));
    }

    /**
     * @param list<string> $commands
     *
     * @return list<string> sorted, unique, no empties
     */
    private function tidyCommands(array $commands): array
    {
        $commands = \array_values(\array_unique(\array_filter(
            $commands,
            static fn (string $c): bool => $c !== '',
        )));
        \sort($commands);

        return $commands;
    }

    public function testIdempotentWithoutFixFlag(): void
    {
        // Set up lib-b (sugarcraft prefix so the script cares about it).
        $this->writeComposerJson($this->tmpDir . '/lib-b', [
            'name' => 'sugarcraft/lib-b',
            'type' => 'library',
            'require' => ['php' => '^8.3'],
            'repositories' => [],
            'autoload' => ['psr-4' => ['SugarCraft\LibB\\' => 'src/']],
            'minimum-stability' => 'dev',
        ]);

        // Set up lib-a with missing path-repo.
        $this->writeComposerJson($this->tmpDir . '/lib-a', [
            'name' => 'sugarcraft/lib-a',
            'type' => 'library',
            'require' => [
                'php' => '^8.3',
                'sugarcraft/lib-b' => '@dev',
            ],
            'repositories' => [],
            'autoload' => ['psr-4' => ['SugarCraft\LibA\\' => 'src/']],
            'minimum-stability' => 'dev',
        ]);

        // Record the original content.
        $originalContent = \file_get_contents($this->tmpDir . '/lib-a/composer.json');

        // Run WITHOUT --fix twice — content must be unchanged after each run.
        $result1 = $this->runScript($this->tmpDir);
        $this->assertNotEquals(0, $result1['exit']);
        $this->assertSame($originalContent, \file_get_contents($this->tmpDir . '/lib-a/composer.json'));

        $result2 = $this->runScript($this->tmpDir);
        $this->assertNotEquals(0, $result2['exit']);
        $this->assertSame($originalContent, \file_get_contents($this->tmpDir . '/lib-a/composer.json'));
    }

    /**
     * A fixture monorepo of two libs, with `lib-a` requiring `lib-b`.
     *
     * `$aSource` is what `lib-a/src/A.php` contains, which is the ONLY thing
     * `--unused` looks at to decide whether the require is live: it resolves
     * `lib-b`'s PSR-4 prefix from `lib-b`'s own manifest and greps `lib-a/src`
     * for it. `$extra` carries the deferred-wiring valve when a case needs one.
     *
     * @param array<string, mixed> $extra
     */
    private function twoLibFixture(string $aSource, array $extra = []): string
    {
        $root = $this->tmpDir;
        $a = $root . '/' . $this->makeSlug('sugarcraft/lib-a');
        $b = $root . '/' . $this->makeSlug('sugarcraft/lib-b');

        $this->writeComposerJson($b, [
            'name' => 'sugarcraft/lib-b',
            'autoload' => ['psr-4' => ['SugarCraft\\LibB\\' => 'src/']],
        ]);
        $manifest = [
            'name' => 'sugarcraft/lib-a',
            'require' => ['sugarcraft/lib-b' => '@dev'],
            'repositories' => [[
                'type' => 'path',
                'url' => '../lib-b',
                'options' => ['symlink' => true],
            ]],
            'autoload' => ['psr-4' => ['SugarCraft\\LibA\\' => 'src/']],
        ];
        if ($extra !== []) {
            $manifest['extra'] = $extra;
        }
        $this->writeComposerJson($a, $manifest);

        \mkdir($a . '/src', 0777, true);
        \mkdir($b . '/src', 0777, true);
        \file_put_contents($a . '/src/A.php', $aSource);
        \file_put_contents($b . '/src/B.php', "<?php\nnamespace SugarCraft\\LibB;\nclass B {}\n");

        return $root;
    }

    /** `lib-a/src` that never mentions `lib-b`. */
    private const A_IGNORES_B = "<?php\nnamespace SugarCraft\\LibA;\nclass A {}\n";

    /** `lib-a/src` that uses `lib-b`, so the require is live. */
    private const A_USES_B = "<?php\nnamespace SugarCraft\\LibA;\nuse SugarCraft\\LibB\\B;\nclass A { public function f(): B { return new B(); } }\n";

    /**
     * `--unused` finds a require nothing in `src/` reaches.
     *
     * THE POSITIVE HALF OF A GATE THAT HAD NEITHER. This pass runs in CI with
     * no `continue-on-error`, so it fails a push, and nothing anywhere asserted
     * that it can still see a dead require at all. A `--unused` that had
     * stopped matching would have reported the same clean tree it reports
     * today, from every branch, indefinitely.
     */
    public function testUnusedFindsARequireThatSrcNeverReaches(): void
    {
        $result = $this->runScript($this->twoLibFixture(self::A_IGNORES_B), ['--unused']);

        $this->assertSame(1, $result['exit'], "--unused must exit 1 on a finding:\n" . $result['output']);
        $this->assertStringContainsString('PRUNE_REQUIRE_AND_REPO', $result['output']);
        $this->assertStringContainsString('sugarcraft/lib-b', $result['output']);
        $this->assertStringContainsString('tests_uses: no', $result['output']);
    }

    /**
     * And it stays quiet when the require is live.
     *
     * THE NEGATIVE HALF, which is not decoration: a pass that flagged every
     * require would satisfy the assertion above just as well, and would gate
     * CI on every dependency in the monorepo.
     */
    public function testUnusedIsQuietWhenSrcActuallyUsesTheDep(): void
    {
        $result = $this->runScript($this->twoLibFixture(self::A_USES_B), ['--unused']);

        $this->assertSame(0, $result['exit'], "--unused must exit 0 on a live require:\n" . $result['output']);
        $this->assertStringContainsString('no dead path-repo deps found', $result['output']);
        $this->assertStringNotContainsString('PRUNE_REQUIRE', $result['output']);
    }

    /**
     * The `deferred-wiring` valve suppresses a real finding, and says so.
     *
     * This is the release valve that makes gating on candidate-grade output
     * survivable: a candidate confirmed by hand in the KEEP direction is
     * recorded in the consuming manifest and stops counting. The fixture is the
     * SAME tree as the positive case above, plus the row — so the assertion is
     * about the row and not about the fixture.
     */
    public function testADeferredWiringRowSuppressesTheFindingItRecords(): void
    {
        $root = $this->twoLibFixture(self::A_IGNORES_B, [
            'sugarcraft' => ['deferred-wiring' => [
                'sugarcraft/lib-b' => 'kept for a named, still-open item',
            ]],
        ]);

        $result = $this->runScript($root, ['--unused']);

        $this->assertSame(0, $result['exit'], "a recorded deferral must not gate CI:\n" . $result['output']);
        $this->assertStringContainsString('DEFERRED_WIRING', $result['output']);
        $this->assertStringContainsString('kept for a named, still-open item', $result['output']);
        $this->assertStringContainsString('no dead path-repo deps found', $result['output']);
    }

    /**
     * A row that suppresses nothing is itself a finding.
     *
     * WITHOUT THIS ARM THE VALVE IS AN EXEMPTION. The commonest way a row goes
     * idle is the happy one — the wiring landed, `src/` reaches the dep, and
     * the row is now a licence sitting in a manifest with nobody watching it.
     * The fixture is the LIVE tree plus a stale row, and the report must name
     * the ROW rather than advise pruning a require the lib is using.
     */
    public function testARowThatSuppressesNothingIsReportedRatherThanHonoured(): void
    {
        $root = $this->twoLibFixture(self::A_USES_B, [
            'sugarcraft' => ['deferred-wiring' => [
                'sugarcraft/lib-b' => 'stale: the wiring landed rounds ago',
            ]],
        ]);

        $result = $this->runScript($root, ['--unused']);

        $this->assertSame(1, $result['exit'], "an idle deferral must gate:\n" . $result['output']);
        $this->assertStringContainsString('IDLE_DEFERRAL', $result['output']);
        $this->assertStringContainsString('delete the row', $result['output']);
    }

    /**
     * --no-lib-path-repos is the check that guards the COMMITTED tree, so it has
     * to fail on the state --fix produces. Driving both directions in one test
     * pins them as inverses rather than as two independent assertions: --fix
     * writes the entry, --no-lib-path-repos must then refuse it.
     */
    public function testNoLibPathReposIsTheInverseOfFix(): void
    {
        $this->writeComposerJson($this->tmpDir . '/lib-b', [
            'name' => 'sugarcraft/lib-b',
            'type' => 'library',
            'require' => ['php' => '^8.3'],
            'autoload' => ['psr-4' => ['SugarCraft\LibB\\' => 'src/']],
            'minimum-stability' => 'dev',
        ]);
        $this->writeComposerJson($this->tmpDir . '/lib-a', [
            'name' => 'sugarcraft/lib-a',
            'type' => 'library',
            'require' => [
                'php' => '^8.3',
                'sugarcraft/lib-b' => '@dev',
            ],
            'autoload' => ['psr-4' => ['SugarCraft\LibA\\' => 'src/']],
            'minimum-stability' => 'dev',
        ]);

        // Stripped tree: this is what gets committed, so the guard must pass.
        $clean = $this->runScript($this->tmpDir, ['--no-lib-path-repos']);
        $this->assertSame(0, $clean['exit'], '--no-lib-path-repos should pass on a stripped tree');
        $this->assertStringContainsString('no sibling path-repos', $clean['output']);

        // Inject exactly what CI injects, then the same guard must refuse it.
        $injected = $this->runScript($this->tmpDir, ['--fix', '--strict-closure']);
        $this->assertSame(0, $injected['exit'], '--fix should succeed');

        $refused = $this->runScript($this->tmpDir, ['--no-lib-path-repos']);
        $this->assertNotEquals(0, $refused['exit'], '--no-lib-path-repos must refuse an injected tree');
        $this->assertStringContainsString('lib-a', $refused['output']);
        $this->assertStringContainsString('lib-b', $refused['output']);

        // Read-only: the refusal must not have edited anything back out.
        $stillInjected = \json_decode(
            (string) \file_get_contents($this->tmpDir . '/lib-a/composer.json'),
            true,
        );
        $this->assertNotEmpty($stillInjected['repositories'] ?? [], '--no-lib-path-repos must not mutate manifests');
    }
}
