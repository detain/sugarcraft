<?php

declare(strict_types=1);

namespace SugarCraft\Tools\Tests;

require_once __DIR__ . '/_bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * Behaviour pins for tools/rename-lib.php (rename plan Step 0.3).
 *
 * WHY THIS IS SHAPED LIKE THIS: the renamer is a destructive, git-aware tool,
 * so every test builds a THROWAWAY mini-repo under the system temp dir (a real
 * `git init` with a committed base, because the tool demands `git ls-files`
 * and a clean `git status --porcelain`), points the tool at it through the
 * documented SUGARCRAFT_RENAME_ROOT hook, and runs the tool as a subprocess —
 * never in-process, so a runaway exit() or a stale static can't poison the
 * suite. The map is the REAL tools/renames.json: these pins double as a guard
 * that the shipped entries (candy-shine, sugar-crush, sugar-boxer) keep the
 * derived-name algebra the plan's Part B.4 promises.
 *
 * The ten cases from Step 0.3's test list map onto the ten test* methods below
 * (R1, R3 boundaries, PROTECT, R2, R4, R5, @rename-keep, path moves with
 * binary/ansi byte preservation, C.3 exclusions, dry-run inertness); two extra
 * methods pin the idempotent re-run and the preflight refusals, and one pins
 * the --verbose matched-line dump.
 */
final class RenameLibTest extends TestCase
{
    use TmpTree;

    /** @var list<string> every temp dir made, all removed in tearDown */
    private array $dirs = [];

    private string $tool;

    protected function setUp(): void
    {
        $this->tool = dirname(__DIR__) . '/rename-lib.php';
        $this->assertFileExists($this->tool);
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            $this->removeDir($dir);
        }
        $this->dirs = [];
    }

    // ------------------------------------------------------------------
    // Case 1 — R1 namespace rewrite (single + JSON double form, ShineX safe)
    // and the path-gated R6 Lang domain flip.
    // ------------------------------------------------------------------

    public function testR1RewritesNamespacesButNotLongerOnes(): void
    {
        $root = $this->shineRepo();
        $run = $this->runTool($root, 'candy-shine');
        $this->assertSame(0, $run['rc'], $run['out']);

        $src = $this->read($root, 'sugar-markdown/src/Shine.php');
        $this->assertStringContainsString('namespace SugarCraft\Markdown;', $src);
        $this->assertStringContainsString('SugarCraft\Markdown\Foo', $src);
        $this->assertStringContainsString('SugarCraft\ShineX', $src, 'R1 lookahead must not eat a longer segment');
        $this->assertStringNotContainsString('SugarCraft\Shine\\', $src);

        // composer.json carries the double-backslash JSON form.
        $composer = $this->read($root, 'sugar-markdown/composer.json');
        $this->assertStringContainsString('SugarCraft\\\\Markdown\\\\', $composer);
        $this->assertStringContainsString('sugarcraft/sugar-markdown', $composer);

        // R6 is path-gated and must fire even though 'shine' alone matches no needle.
        $lang = $this->read($root, 'sugar-markdown/src/Lang.php');
        $this->assertStringContainsString("NAMESPACE = 'markdown'", $lang);
    }

    // ------------------------------------------------------------------
    // Case 2 — R3 slug boundaries.
    // ------------------------------------------------------------------

    public function testR3MatchesSlugAtBoundariesOnly(): void
    {
        $root = $this->shineRepo();
        $run = $this->runTool($root, 'candy-shine');
        $this->assertSame(0, $run['rc'], $run['out']);

        $codecov = $this->read($root, 'codecov.yml');
        $this->assertStringContainsString('sugarcraft/sugar-markdown', $codecov);
        $this->assertStringContainsString('render-sugar-markdown', $codecov);
        $this->assertStringContainsString('.sugar-markdown/', $codecov);
        $this->assertStringContainsString('candy-shiner', $codecov, 'R3 must not match a slug with letter continuation');
        $this->assertStringContainsString('xcandy-shine', $codecov, 'R3 lookbehind must not match a glued prefix');
        // vendor prefix itself is sacred (C.4)
        $this->assertStringContainsString('sugarcraft/', $codecov);
    }

    // ------------------------------------------------------------------
    // Case 3 — PROTECT: prefixed sibling slugs survive the parent rename.
    // ------------------------------------------------------------------

    public function testProtectKeepsPrefixedSiblingSlug(): void
    {
        $root = $this->crushRepo();
        $run = $this->runTool($root, 'sugar-crush');
        $this->assertSame(0, $run['rc'], $run['out']);

        $readme = $this->read($root, 'candy-code/README.md');
        $this->assertStringContainsString('sugar-crush-web', $readme, 'explicit protect must block R3');
        $this->assertStringContainsString('paths candy-code/ and candy-code. here', $readme);

        // Root composer require + path repository follow R3 too.
        $rootComposer = $this->read($root, 'composer.json');
        $this->assertStringContainsString('sugarcraft/candy-code', $rootComposer);
        $this->assertStringContainsString('"url":"candy-code"', $rootComposer);
        $this->assertStringNotContainsString('sugar-crush', $rootComposer);

        // R6 domain for this entry is the ns tail, not the old slug word.
        $this->assertStringContainsString("NAMESPACE = 'code'", $this->read($root, 'candy-code/src/Lang.php'));
    }

    // ------------------------------------------------------------------
    // Case 4 — R2 brand (PascalCase) rewrite, uppercase-fold yes, lowercase no.
    // ------------------------------------------------------------------

    public function testR2RewritesPascalBrands(): void
    {
        $crush = $this->crushRepo();
        $run = $this->runTool($crush, 'sugar-crush');
        $this->assertSame(0, $run['rc'], $run['out']);
        $readme = $this->read($crush, 'candy-code/README.md');
        $this->assertStringContainsString('CandyCodeWeb', $readme);
        $this->assertStringContainsString('SugarCrushx', $readme, 'lowercase continuation must block R2');

        $boxer = $this->boxerRepo();
        $run = $this->runTool($boxer, 'sugar-boxer');
        $this->assertSame(0, $run['rc'], $run['out']);
        $tracked = $this->trackedPaths($boxer);
        $this->assertContains('sugar-panels/src/SugarPanels.php', $tracked, 'R2 must rename path segments too');
        $this->assertContains('sugar-panels/tests/SugarPanelsTest.php', $tracked);
        $this->assertStringContainsString('final class SugarPanels', $this->read($boxer, 'sugar-panels/src/SugarPanels.php'));
        $this->assertStringContainsString('namespace SugarCraft\Panels;', $this->read($boxer, 'sugar-panels/src/SugarPanels.php'));
        $this->assertStringContainsString('SugarBoxerx', $this->read($boxer, 'sugar-panels/README.md'));
    }

    // ------------------------------------------------------------------
    // Case 5 — R4 compact identifier: slug replacement, $var/_ safe,
    // python_port/*.py gets the compact_new identity instead.
    // ------------------------------------------------------------------

    public function testR4CompactRulesAndPythonIdentity(): void
    {
        $root = $this->crushRepo();
        $run = $this->runTool($root, 'sugar-crush');
        $this->assertSame(0, $run['rc'], $run['out']);

        $readme = $this->read($root, 'candy-code/README.md');
        $this->assertStringContainsString('candy-code serve', $readme);
        $this->assertStringContainsString('$sugarcrushDir', $readme, 'R4 lookbehind must spare $vars');
        $this->assertStringContainsString('sugarcrush_x', $readme, 'R4 lookahead must spare longer idents');

        $tracked = $this->trackedPaths($root);
        $this->assertContains('candy-code/bin/candy-code', $tracked, 'bin/sugarcrush segment rides R4');
        $this->assertContains('candy-code/python_port/src/candycode/foo.py', $tracked, 'python_port path segment uses compact_new');
        $this->assertStringContainsString('import candycode', $this->read($root, 'candy-code/python_port/src/candycode/foo.py'));

        // R7 extra literal from the real map entry.
        $this->assertStringContainsString('docs at candy-code root', $readme);
    }

    // ------------------------------------------------------------------
    // Case 6 — R5 env prefixes, with SUGARCRAFT_* untouched (C.4).
    // ------------------------------------------------------------------

    public function testR5EnvPrefixes(): void
    {
        $root = $this->crushRepo();
        $run = $this->runTool($root, 'sugar-crush');
        $this->assertSame(0, $run['rc'], $run['out']);

        $readme = $this->read($root, 'candy-code/README.md');
        $this->assertStringContainsString('CANDY_CODE_MODEL', $readme);
        $this->assertStringContainsString('CANDY_CODE_SHARE_UPLOAD_URL', $readme);
        $this->assertStringContainsString('SUGARCRAFT_X', $readme, 'the sacred vendor prefix must never move');
    }

    // ------------------------------------------------------------------
    // Case 7 — @rename-keep line-skip.
    // ------------------------------------------------------------------

    public function testRenameKeepLineSurvivesVerbatim(): void
    {
        $root = $this->shineRepo();
        $run = $this->runTool($root, 'candy-shine');
        $this->assertSame(0, $run['rc'], $run['out']);

        $src = $this->read($root, 'sugar-markdown/src/Shine.php');
        $this->assertStringContainsString('// @rename-keep candy-shine verbatim', $src);
    }

    // ------------------------------------------------------------------
    // Case 8 — path renames for icons/ansi/docs; binary + .ansi BYTES intact.
    // ------------------------------------------------------------------

    public function testPathRenamesPreserveBinaryAndAnsiBytes(): void
    {
        $root = $this->shineRepo();
        $pngBefore = md5_file($root . '/media/icons/candy-shine.png');
        $ansiBefore = file_get_contents($root . '/ansi/candy-shine/candy-shine2-tc-l-1.ansi');

        $run = $this->runTool($root, 'candy-shine');
        $this->assertSame(0, $run['rc'], $run['out']);

        $tracked = $this->trackedPaths($root);
        $this->assertContains('media/icons/sugar-markdown.png', $tracked);
        $this->assertContains('ansi/sugar-markdown/sugar-markdown2-tc-l-1.ansi', $tracked, 'ansi prefix rule renames longer .ansi stems');
        $this->assertContains('docs/lib/sugar-markdown.html', $tracked);

        $this->assertSame($pngBefore, md5_file($root . '/media/icons/sugar-markdown.png'));
        $this->assertSame($ansiBefore, file_get_contents($root . '/ansi/sugar-markdown/sugar-markdown2-tc-l-1.ansi'));
        $this->assertStringContainsString('candy-shine', (string) $ansiBefore, 'fixture sanity: .ansi held the old slug');
    }

    // ------------------------------------------------------------------
    // Case 9 — C.3 exclusions (+ untracked .bak decoy, invisible to git).
    // ------------------------------------------------------------------

    public function testExcludedFilesAreNeverTouched(): void
    {
        $root = $this->shineRepo();
        $run = $this->runTool($root, 'candy-shine');
        $this->assertSame(0, $run['rc'], $run['out']);

        $this->assertStringContainsString('candy-shine', $this->read($root, 'plan_rename.md'));
        $this->assertStringContainsString('candy-shine', $this->read($root, 'docs/old/x.md'));
        $this->assertStringContainsString('candy-shine', $this->read($root, 'composer.lock'));
        // vendor rode the directory move but its content stayed pristine
        $this->assertStringContainsString('candy-shine', $this->read($root, 'sugar-markdown/vendor/autoload.php'));
        // the gitignored, never-tracked .bak keeps its bytes wherever it landed
        $bak = is_file($root . '/sugar-markdown/src/Bak.php.bak')
            ? $root . '/sugar-markdown/src/Bak.php.bak'
            : $root . '/candy-shine/src/Bak.php.bak';
        $this->assertFileExists($bak);
        $this->assertStringContainsString('candy-shine', (string) file_get_contents($bak));
    }

    // ------------------------------------------------------------------
    // Case 10 — --dry-run is read-only.
    // ------------------------------------------------------------------

    public function testDryRunChangesNothing(): void
    {
        $root = $this->shineRepo();
        $srcBefore = $this->read($root, 'candy-shine/src/Shine.php');

        $run = $this->runTool($root, 'candy-shine', ['--dry-run']);
        $this->assertSame(0, $run['rc'], $run['out']);
        $this->assertStringContainsString('[dry-run, nothing written]', $run['out']);
        $this->assertStringContainsString('rename  candy-shine/ -> sugar-markdown/', $run['out']);
        $this->assertStringContainsString('content candy-shine/src/Shine.php', $run['out']);

        $this->assertSame('', $this->porcelain($root));
        $this->assertContains('candy-shine/src/Shine.php', $this->trackedPaths($root));
        $this->assertSame($srcBefore, $this->read($root, 'candy-shine/src/Shine.php'));
        $this->assertDirectoryDoesNotExist($root . '/sugar-markdown');
    }

    // ------------------------------------------------------------------
    // Case 11 — second run on the renamed tree is a clean no-op.
    // ------------------------------------------------------------------

    public function testSecondRunOnRenamedTreeIsCleanNoOp(): void
    {
        $root = $this->shineRepo();
        $first = $this->runTool($root, 'candy-shine');
        $this->assertSame(0, $first['rc'], $first['out']);
        $this->git($root, 'add -A');
        $this->git($root, 'commit -qm renamed');

        $second = $this->runTool($root, 'candy-shine');
        $this->assertSame(0, $second['rc'], $second['out']);
        $this->assertStringContainsString('already renamed — clean no-op', $second['out']);
        $this->assertSame('', $this->porcelain($root));
        foreach ($this->trackedPaths($root) as $path) {
            $this->assertStringNotContainsString('candy-shine', $path, "leftover old-slug path after idempotent run: $path");
        }
    }

    // ------------------------------------------------------------------
    // Case 12 — preflight refusals: unknown slug, dirty tree, ns collision.
    // ------------------------------------------------------------------

    public function testPreflightRefusals(): void
    {
        $root = $this->shineRepo();
        $unknown = $this->runTool($root, 'no-such-lib');
        $this->assertSame(2, $unknown['rc']);
        $this->assertStringContainsString('no map entry', $unknown['out']);

        file_put_contents($root . '/codecov.yml', "# drifted\n", FILE_APPEND);
        $dirty = $this->runTool($root, 'candy-shine');
        $this->assertSame(2, $dirty['rc']);
        $this->assertStringContainsString('not clean', $dirty['out']);
        $this->git($root, 'checkout -- codecov.yml');

        $colliding = $this->shineRepo();
        $this->writeFile($colliding, 'candy-shine/src/Ghost.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            final class Ghost
            {
                public const HAUNT = 'SugarCraft\Markdown\Ghost';
            }
            PHP . "\n");
        $this->git($colliding, 'add -A');
        $this->git($colliding, 'commit -qm ghost');

        $clash = $this->runTool($colliding, 'candy-shine');
        $this->assertSame(2, $clash['rc']);
        $this->assertStringContainsString('already present', $clash['out']);
    }

    // ------------------------------------------------------------------
    // Case 13 — --verbose dumps matched lines as `path:line [Rn] text`.
    // ------------------------------------------------------------------

    public function testVerboseDryRunDumpsMatchedLines(): void
    {
        $root = $this->shineRepo();
        $run = $this->runTool($root, 'candy-shine', ['--dry-run', '--verbose']);
        $this->assertSame(0, $run['rc'], $run['out']);
        $this->assertMatchesRegularExpression('/Shine\.php:\d+ \[R1\]/', $run['out']);
        $this->assertMatchesRegularExpression('/codecov\.yml:\d+ \[R3\]/', $run['out']);
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /** @return array{rc:int, out:string} */
    private function runTool(string $root, string $old, array $flags = []): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->tool)
            . ' --old=' . escapeshellarg($old);
        foreach ($flags as $flag) {
            $cmd .= ' ' . escapeshellarg($flag);
        }
        $cmd .= ' 2>&1';

        $descriptors = [1 => ['pipe', 'w']];
        $env = [
            'PATH' => (string) getenv('PATH') . ':/usr/local/bin:/usr/bin:/bin',
            'HOME' => $root,
            'LANG' => 'C',
            'SUGARCRAFT_RENAME_ROOT' => $root,
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_CONFIG_SYSTEM' => '/dev/null',
        ];
        $process = proc_open(['/bin/sh', '-c', $cmd], $descriptors, $pipes, $root, $env);
        $this->assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $rc = proc_close($process);

        return ['rc' => $rc, 'out' => $out];
    }

    private function git(string $root, string $args): void
    {
        exec(
            'git -c user.name=fixture -c user.email=fixture@example.com -C '
            . escapeshellarg($root) . ' ' . $args . ' 2>&1',
            $output,
            $rc
        );
        $this->assertSame(0, $rc, 'git ' . $args . ' failed: ' . implode("\n", $output));
    }

    /**
     * @param array<string, string> $files relative path => contents
     */
    private function makeRepo(array $files): string
    {
        $root = $this->makeTmpTree('rename-lib-');
        $this->dirs[] = $root;
        foreach ($files as $rel => $contents) {
            $this->writeFile($root, $rel, $contents);
        }
        $this->git($root, 'init -q');
        $this->git($root, 'add -A');
        $this->git($root, 'commit -qm base');

        return $root;
    }

    private function writeFile(string $root, string $rel, string $contents): void
    {
        $path = $root . '/' . $rel;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }
        file_put_contents($path, $contents);
    }

    /** @return list<string> */
    private function trackedPaths(string $root): array
    {
        exec('git -C ' . escapeshellarg($root) . " ls-files 2>/dev/null", $lines);

        return array_values(array_filter(array_map('trim', $lines), static fn(string $l): bool => $l !== ''));
    }

    private function porcelain(string $root): string
    {
        exec('git -C ' . escapeshellarg($root) . " status --porcelain 2>/dev/null", $lines);

        return trim(implode("\n", array_map('trim', $lines)));
    }

    private function read(string $root, string $rel): string
    {
        $contents = file_get_contents($root . '/' . $rel);
        $this->assertIsString($contents, "expected readable $rel");

        return $contents;
    }

    private function shineRepo(): string
    {
        $root = $this->makeRepo([
            '.gitignore' => "*.bak\n",
            'candy-shine/composer.json' => <<<'JSON'
                {"name":"sugarcraft/candy-shine","description":"fixture","autoload":{"psr-4":{"SugarCraft\\Shine\\":"src/"}}}
                JSON . "\n",
            'candy-shine/src/Shine.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace SugarCraft\Shine;

                final class Shine
                {
                    public const REF = 'See SugarCraft\Shine\Foo and SugarCraft\ShineX too';
                    // @rename-keep candy-shine verbatim
                }
                PHP . "\n",
            'candy-shine/src/Lang.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                final class Lang
                {
                    protected const NAMESPACE = 'shine';
                }
                PHP . "\n",
            'candy-shine/bin/candy-shine' => <<<'PHP'
                #!/usr/bin/env php
                <?php

                echo "candy-shine running\n";
                PHP . "\n",
            'candy-shine/vendor/autoload.php' => "<?php // candy-shine vendor decoy\n",
            'docs/lib/candy-shine.html' => "<html>candy-shine</html>\n",
            'codecov.yml' => "flags:\n  - sugarcraft/candy-shine render-candy-shine candy-shiner xcandy-shine .candy-shine/ SugarCraft\\\\Shine\\\\ double\n",
            'media/icons/candy-shine.png' => "\x00\x89PNG\r\n\x1a\n candy-shine bytes \x00",
            'ansi/candy-shine/candy-shine2-tc-l-1.ansi' => "frame candy-shine art\n",
            'plan_rename.md' => "plan mentions candy-shine and CandyShine\n",
            'docs/old/x.md' => "old notes about candy-shine\n",
            'composer.lock' => "{\"content-hash\":\"candy-shine\"}\n",
        ]);
        // untracked-but-gitignored decoy: invisible to ls-files, must survive untouched
        $this->writeFile($root, 'candy-shine/src/Bak.php.bak', "<?php // candy-shine backup decoy\n");

        return $root;
    }

    private function crushRepo(): string
    {
        return $this->makeRepo([
            'composer.json' => <<<'JSON'
                {"name":"sugarcraft/monorepo-fixture","require":{"sugarcraft/sugar-crush":"@dev"},"repositories":[{"type":"path","url":"sugar-crush"}]}
                JSON . "\n",
            'sugar-crush/composer.json' => <<<'JSON'
                {"name":"sugarcraft/sugar-crush","description":"fixture","autoload":{"psr-4":{"SugarCraft\\Crush\\":"src/"}}}
                JSON . "\n",
            'sugar-crush/src/Crush.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace SugarCraft\Crush;

                final class Crush
                {
                }
                PHP . "\n",
            'sugar-crush/src/Lang.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                final class Lang
                {
                    protected const NAMESPACE = 'crush';
                }
                PHP . "\n",
            'sugar-crush/bin/sugarcrush' => <<<'PHP'
                #!/usr/bin/env php
                <?php

                echo "sugarcrush running\n";
                PHP . "\n",
            'sugar-crush/README.md' => implode("\n", [
                'see sugar-crush-web for the web app',
                'paths sugar-crush/ and sugar-crush. here',
                'SugarCrushWeb folds but SugarCrushx stays',
                'run sugarcrush serve with $sugarcrushDir and sugarcrush_x',
                'SUGARCRUSH_MODEL=1 SUGAR_CRUSH_SHARE_UPLOAD_URL=2 SUGARCRAFT_X=3',
                'docs at sugarcraft-crush root',
                '',
            ]),
            'sugar-crush/python_port/src/sugarcrush/foo.py' => "import sugarcrush\n",
        ]);
    }

    private function boxerRepo(): string
    {
        return $this->makeRepo([
            'sugar-boxer/composer.json' => <<<'JSON'
                {"name":"sugarcraft/sugar-boxer","description":"fixture","autoload":{"psr-4":{"SugarCraft\\Boxer\\":"src/"}}}
                JSON . "\n",
            'sugar-boxer/src/SugarBoxer.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace SugarCraft\Boxer;

                final class SugarBoxer
                {
                }
                PHP . "\n",
            'sugar-boxer/tests/SugarBoxerTest.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace SugarCraft\Boxer\Tests;

                final class SugarBoxerTest
                {
                }
                PHP . "\n",
            'sugar-boxer/README.md' => "SugarBoxerx stays while SugarBoxer moves\n",
        ]);
    }
}
