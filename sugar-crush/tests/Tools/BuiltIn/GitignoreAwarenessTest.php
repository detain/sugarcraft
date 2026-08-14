<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tools\BuiltIn\Glob;
use SugarCraft\Crush\Tools\BuiltIn\Grep;

/**
 * crush_code.md Phase 8 item 7 — `.gitignore`-awareness for `Glob`/`Grep`.
 *
 * The defect: both tools walked into `vendor/`, `node_modules/`, `.git/`,
 * build output and everything else the project had told git to ignore. Two
 * costs, not one — the model burns the turn's context on generated files it
 * did not ask for, and a `.gitignore`d local config file (the canonical place
 * a project keeps its credentials) is surfaced verbatim into the transcript.
 *
 * {@see \SugarCraft\Crush\Tests\Tools\IgnoreRulesTest} pins the matcher's
 * syntax; this pins that the two tools actually USE it, announce what they
 * hid, and honour the override.
 */
final class GitignoreAwarenessTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = realpath(sys_get_temp_dir()) . '/crush-ignaware-' . uniqid('', true);
        mkdir($this->root . '/src', 0o777, true);
        file_put_contents($this->root . '/src/App.php', "<?php // needle\n");
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);
    }

    // =========================================================================
    // Glob
    // =========================================================================

    public function testGlobSkipsAGitignoredFile(): void
    {
        file_put_contents($this->root . '/.gitignore', "secret.php\n");
        file_put_contents($this->root . '/secret.php', "<?php\n");

        $matched = $this->globbed('**/*.php');

        $this->assertContains($this->root . '/src/App.php', $matched);
        $this->assertNotContains($this->root . '/secret.php', $matched);
    }

    /** The single-level fast path is a different branch, and finds files too. */
    public function testTheNonRecursiveFastPathIsFilteredAsWell(): void
    {
        file_put_contents($this->root . '/.gitignore', "generated.php\n");
        file_put_contents($this->root . '/generated.php', "<?php\n");
        file_put_contents($this->root . '/kept.php', "<?php\n");

        $matched = $this->globbed('*.php');

        $this->assertContains($this->root . '/kept.php', $matched);
        $this->assertNotContains($this->root . '/generated.php', $matched);
    }

    public function testGlobPrunesAGitignoredDirectoryRatherThanFilteringIt(): void
    {
        file_put_contents($this->root . '/.gitignore', "build/\n");
        mkdir($this->root . '/build/deep', 0o777, true);
        file_put_contents($this->root . '/build/deep/Gen.php', "<?php\n");

        $this->assertNotContains($this->root . '/build/deep/Gen.php', $this->globbed('**/*.php'));
    }

    public function testGlobHonoursANestedGitignore(): void
    {
        mkdir($this->root . '/pkg', 0o777, true);
        file_put_contents($this->root . '/pkg/.gitignore', "local.php\n");
        file_put_contents($this->root . '/pkg/local.php', "<?php\n");
        file_put_contents($this->root . '/local.php', "<?php\n");

        $matched = $this->globbed('**/*.php');

        $this->assertNotContains($this->root . '/pkg/local.php', $matched);
        $this->assertContains($this->root . '/local.php', $matched, 'the nested rule stays in its subtree');
    }

    public function testGlobHonoursANegation(): void
    {
        file_put_contents($this->root . '/.gitignore', "*.gen.php\n!keep.gen.php\n");
        file_put_contents($this->root . '/drop.gen.php', "<?php\n");
        file_put_contents($this->root . '/keep.gen.php', "<?php\n");

        $matched = $this->globbed('**/*.php');

        $this->assertNotContains($this->root . '/drop.gen.php', $matched);
        $this->assertContains($this->root . '/keep.gen.php', $matched);
    }

    public function testGlobHonoursARootAnchoredPattern(): void
    {
        file_put_contents($this->root . '/.gitignore', "/config.php\n");
        file_put_contents($this->root . '/config.php', "<?php\n");
        file_put_contents($this->root . '/src/config.php', "<?php\n");

        $matched = $this->globbed('**/*.php');

        $this->assertNotContains($this->root . '/config.php', $matched);
        $this->assertContains($this->root . '/src/config.php', $matched, 'anchored means top level only');
    }

    /**
     * A quietly shortened list reads as a complete one, so the model concludes
     * "that file does not exist" from a result that simply never looked. The
     * escape hatch cannot be spelled in the pattern here — an ignore rule is
     * not a directory name — so the note has to name the argument.
     */
    public function testGlobAnnouncesWhatGitignoreHidAndHowToSeeIt(): void
    {
        file_put_contents($this->root . '/.gitignore', "secret.php\n");
        file_put_contents($this->root . '/secret.php', "<?php\n");

        $content = $this->globResult('**/*.php')->content();

        $this->assertStringContainsString('gitignored:', $content);
        $this->assertStringContainsString('include_ignored', $content);
    }

    public function testGlobSaysNothingWhenNothingWasIgnored(): void
    {
        $this->assertStringNotContainsString('gitignored:', $this->globResult('**/*.php')->content());
    }

    public function testIncludeIgnoredSearchesTheIgnoredFilesAnyway(): void
    {
        file_put_contents($this->root . '/.gitignore', "secret.php\n");
        file_put_contents($this->root . '/secret.php', "<?php\n");

        $result = (new Glob())->execute([
            'pattern' => '**/*.php',
            'path' => $this->root,
            'description' => 'find every php file including ignored ones',
            'include_ignored' => true,
        ]);

        $this->assertContains($this->root . '/secret.php', self::linesOf($result->content()));
        $this->assertStringNotContainsString('gitignored:', $result->content());
    }

    /**
     * `"false"` is what a model sends when it means false, and a bare cast
     * turns that string into true — silently disabling the filter.
     */
    public function testTheStringFalseDoesNotTurnTheFilterOff(): void
    {
        file_put_contents($this->root . '/.gitignore', "secret.php\n");
        file_put_contents($this->root . '/secret.php', "<?php\n");

        $result = (new Glob())->execute([
            'pattern' => '**/*.php',
            'path' => $this->root,
            'description' => 'find php files',
            'include_ignored' => 'false',
        ]);

        $this->assertNotContains($this->root . '/secret.php', self::linesOf($result->content()));
    }

    /** Pointing `path` at an ignored directory is a deliberate request for it. */
    public function testPointingPathInsideAnIgnoredDirectorySearchesIt(): void
    {
        file_put_contents($this->root . '/.gitignore', "build/\n");
        mkdir($this->root . '/build/deep', 0o777, true);
        file_put_contents($this->root . '/build/deep/Gen.php', "<?php\n");

        $result = (new Glob(root: $this->root))->execute([
            'pattern' => '**/*.php',
            'path' => 'build',
            'description' => 'search the build output deliberately',
        ]);

        $this->assertContains($this->root . '/build/deep/Gen.php', self::linesOf($result->content()));
    }

    // =========================================================================
    // The symlink hard stop
    // =========================================================================

    /**
     * The failure this prevents is not a slow walk, it is a walk that never
     * ends: `a/link -> a` is a cycle, and a recursive iterator following it
     * hangs the agent's turn outright.
     */
    public function testASymlinkCycleTerminates(): void
    {
        mkdir($this->root . '/loop', 0o777, true);
        file_put_contents($this->root . '/loop/One.php', "<?php\n");
        symlink($this->root . '/loop', $this->root . '/loop/self');

        $result = (new Glob(maxMatches: 0, maxOutputBytes: 0))->execute([
            'pattern' => '**/*.php',
            'path' => $this->root,
            'description' => 'walk a cyclic tree',
        ]);

        $this->assertFalse($result->isError(), $result->content());
        $this->assertContains($this->root . '/loop/One.php', self::linesOf($result->content()));
        $this->assertNotContains($this->root . '/loop/self/One.php', self::linesOf($result->content()));
    }

    public function testTheRefusedSymlinkIsAnnouncedWithItsOwnHatch(): void
    {
        mkdir($this->root . '/real', 0o777, true);
        file_put_contents($this->root . '/real/One.php', "<?php\n");
        symlink($this->root . '/real', $this->root . '/link');

        $content = $this->globResult('**/*.php')->content();

        $this->assertStringContainsString('symlinks:', $content);
        $this->assertStringContainsString('Point path at the link', $content);
    }

    public function testSeedingTheWalkAtTheLinkIsTheHatch(): void
    {
        mkdir($this->root . '/real', 0o777, true);
        file_put_contents($this->root . '/real/One.php', "<?php\n");
        symlink($this->root . '/real', $this->root . '/link');

        $result = (new Glob())->execute([
            'pattern' => '**/*.php',
            'path' => $this->root . '/link',
            'description' => 'search through the link deliberately',
        ]);

        $this->assertNotSame([], self::linesOf($result->content()));
    }

    /**
     * Not hypothetical, and not synthetic: SugarCraft is a composer path-repo
     * monorepo, so `sugar-crush/vendor/sugarcraft/candy-core` is a symlink to
     * `../../../candy-core`, whose own `vendor/sugarcraft/` links back out to
     * more siblings. Following those is a genuine cycle, so this runs against
     * the real tree rather than a fixture of one.
     */
    public function testTheMonorepoPathRepoSymlinksAreNotFollowed(): void
    {
        $linkFarm = dirname(__DIR__, 3) . '/vendor/sugarcraft';
        if (!is_dir($linkFarm)) {
            $this->markTestSkipped('composer install has not run, so there are no path-repo symlinks');
        }

        $links = array_filter(glob($linkFarm . '/*') ?: [], 'is_link');
        if ($links === []) {
            $this->markTestSkipped('no path-repo symlinks in this checkout');
        }

        // Every default guard off, so ONLY the symlink stop is left standing.
        $result = (new Glob(maxMatches: 0, maxOutputBytes: 0, prunedDirs: []))->execute([
            'pattern' => '**/composer.json',
            'path' => $linkFarm,
            'description' => 'walk the path-repo link farm',
            'include_ignored' => true,
        ]);

        $this->assertFalse($result->isError(), $result->content());
        $this->assertStringContainsString('symlinks:', $result->content());

        foreach (self::linesOf($result->content()) as $path) {
            $this->assertLessThan(
                2,
                substr_count($path, '/vendor/sugarcraft/'),
                'the walk re-entered a sibling library through its own vendor link',
            );
        }
    }

    // =========================================================================
    // Grep
    // =========================================================================

    public function testGrepSkipsHitsInGitignoredFiles(): void
    {
        file_put_contents($this->root . '/.gitignore', "secrets.env\n");
        file_put_contents($this->root . '/secrets.env', "needle=hunter2\n");

        $content = $this->grepped('needle');

        $this->assertStringContainsString('src/App.php', $content);
        $this->assertStringNotContainsString('hunter2', $content, 'an ignored credential file reached the model');
    }

    public function testGrepAnnouncesTheHitsGitignoreHid(): void
    {
        file_put_contents($this->root . '/.gitignore', "secrets.env\n");
        file_put_contents($this->root . '/secrets.env', "needle=hunter2\n");

        $content = $this->grepped('needle');

        $this->assertStringContainsString('gitignored:', $content);
        $this->assertStringContainsString('include_ignored', $content);
    }

    public function testGrepSaysNothingWhenNothingWasHidden(): void
    {
        $this->assertStringNotContainsString('gitignored:', $this->grepped('needle'));
    }

    public function testGrepIncludeIgnoredSearchesThemAnyway(): void
    {
        file_put_contents($this->root . '/.gitignore', "secrets.env\n");
        file_put_contents($this->root . '/secrets.env', "needle=hunter2\n");

        $result = (new Grep())->execute([
            'pattern' => 'needle',
            'path' => $this->root,
            'description' => 'search everything including ignored files',
            'include_ignored' => true,
        ]);

        $this->assertStringContainsString('hunter2', $result->content());
        $this->assertStringNotContainsString('gitignored:', $result->content());
    }

    public function testGrepHonoursNestedGitignoresAndNegations(): void
    {
        mkdir($this->root . '/pkg', 0o777, true);
        file_put_contents($this->root . '/.gitignore', "*.gen\n");
        file_put_contents($this->root . '/pkg/.gitignore', "!keep.gen\n");
        file_put_contents($this->root . '/drop.gen', "needle\n");
        file_put_contents($this->root . '/pkg/keep.gen', "needle\n");

        $content = $this->grepped('needle');

        $this->assertStringNotContainsString('drop.gen', $content);
        $this->assertStringContainsString('keep.gen', $content, 'the nested negation re-includes it');
    }

    public function testGrepSkipsTheDefaultExcludedDirectoriesWithoutAnyGitignore(): void
    {
        mkdir($this->root . '/vendor/acme', 0o777, true);
        file_put_contents($this->root . '/vendor/acme/Dep.php', "// needle\n");

        $content = $this->grepped('needle');

        $this->assertStringContainsString('src/App.php', $content);
        $this->assertStringNotContainsString('vendor/acme/Dep.php', $content);
    }

    /** Same doctrine as Glob's prune note: a silent omission is a wrong answer. */
    public function testGrepNamesTheDefaultDirectoriesItSkipped(): void
    {
        mkdir($this->root . '/vendor/acme', 0o777, true);
        file_put_contents($this->root . '/vendor/acme/Dep.php', "// needle\n");

        $content = $this->grepped('needle');

        $this->assertStringContainsString('skipped:', $content);
        $this->assertStringContainsString('vendor', $content);
    }

    public function testGrepSaysNothingAboutDirectoriesThatAreNotThere(): void
    {
        $this->assertStringNotContainsString('skipped:', $this->grepped('needle'));
    }

    public function testPointingGrepInsideAnExcludedDirectorySearchesIt(): void
    {
        mkdir($this->root . '/vendor/acme', 0o777, true);
        file_put_contents($this->root . '/vendor/acme/Dep.php', "// needle\n");

        $result = (new Grep(root: $this->root))->execute([
            'pattern' => 'needle',
            'path' => 'vendor/acme',
            'description' => 'search vendor deliberately',
        ]);

        $this->assertStringContainsString('Dep.php', $result->content());
    }

    /**
     * `grep -r` follows a symlink only when it is named on the command line —
     * which is exactly the bounded hatch. `-R` would follow every link it met,
     * and on this monorepo's path repos that is a non-terminating walk.
     */
    public function testGrepDoesNotFollowASymlinkedDirectory(): void
    {
        mkdir($this->root . '/real', 0o777, true);
        file_put_contents($this->root . '/real/Hit.php', "// needle\n");
        symlink($this->root . '/real', $this->root . '/link');

        $content = $this->grepped('needle');

        $this->assertStringContainsString('real/Hit.php', $content);
        $this->assertStringNotContainsString('link/Hit.php', $content);
    }

    public function testGrepFollowsTheSymlinkWhenItIsTheSearchRoot(): void
    {
        mkdir($this->root . '/real', 0o777, true);
        file_put_contents($this->root . '/real/Hit.php', "// needle\n");
        symlink($this->root . '/real', $this->root . '/link');

        $result = (new Grep())->execute([
            'pattern' => 'needle',
            'path' => $this->root . '/link',
            'description' => 'search through the link deliberately',
        ]);

        $this->assertStringContainsString('Hit.php', $result->content());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function globResult(string $pattern): \SugarCraft\Crush\Tools\ToolResult
    {
        return (new Glob())->execute([
            'pattern' => $pattern,
            'path' => $this->root,
            'description' => 'find matching files',
        ]);
    }

    /** @return list<string> */
    private function globbed(string $pattern): array
    {
        $result = $this->globResult($pattern);
        $this->assertFalse($result->isError(), $result->content());

        return self::linesOf($result->content());
    }

    private function grepped(string $pattern): string
    {
        $result = (new Grep())->execute([
            'pattern' => $pattern,
            'path' => $this->root,
            'description' => 'search for the needle',
        ]);

        $this->assertFalse($result->isError(), $result->content());

        return $result->content();
    }

    /** @return list<string> */
    private static function linesOf(string $content): array
    {
        return array_values(array_filter(
            explode("\n", $content),
            static fn (string $line): bool => $line !== '' && !str_starts_with($line, '... ['),
        ));
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            // is_link() FIRST: is_dir() follows a symlink, so recursing would
            // empty the link's target instead of removing the link.
            if (is_link($path) || !is_dir($path)) {
                unlink($path);
                continue;
            }
            self::removeTree($path);
        }

        rmdir($dir);
    }
}
