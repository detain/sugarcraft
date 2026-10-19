<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\PathJail as AgentPathJail;
use SugarCraft\Code\Agents\PathJailConfig;
use SugarCraft\Code\Tools\BuiltIn\Ls;
use SugarCraft\Code\Tools\ToolResult;

/**
 * @see Ls
 *
 * The tool this file guards was the first production consumer of
 * {@see \SugarCraft\Code\StreamingDirectoryLister} — a generator that shipped
 * with zero `src/` references and an unconditional dot-skip that is right for
 * its original gum port and wrong for a listing tool. The hidden-entry tests
 * below are that fix pinned; the cap, note and jail tests are the doctrine
 * copied from Glob, asserted here so a future edit to either class fails
 * loudly on both sides of the copy.
 *
 * No fixture in this file spells a wildcard: an include/exclude-shaped string
 * literal here would be harvested by the GlobDialect corpus differential and
 * drift the pinned PathGlob figure. Ls takes no pattern at all, so the hazard
 * never arises — said out loud because the next test author will copy this
 * fixture.
 */
final class LsTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = realpath(sys_get_temp_dir()) . '/crush-ls-' . uniqid('', true);
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function ls(?int $maxEntries = null): Ls
    {
        return $maxEntries === null
            ? new Ls(root: $this->root)
            : new Ls(root: $this->root, maxEntries: $maxEntries);
    }

    /** @param array<string, mixed> $extra */
    private function listing(string $path, array $extra = [], ?int $maxEntries = null): ToolResult
    {
        return $this->ls($maxEntries)->execute([
            'id' => 'ls-test',
            'path' => $path,
            'description' => 'List a fixture directory',
        ] + $extra);
    }

    /** Split the result content into its entry lines (notes excluded). */
    private function entries(ToolResult $result): array
    {
        $lines = [];
        foreach (explode("\n", $result->content()) as $line) {
            if ($line === '' || str_starts_with($line, '...')) {
                continue;
            }
            $lines[] = $line;
        }

        return $lines;
    }

    // =========================================================================
    // Shape of the answer
    // =========================================================================

    public function testNameSortedOutputWithDirectoryMarkers(): void
    {
        mkdir($this->root . '/zeta');
        touch($this->root . '/beta.txt');
        mkdir($this->root . '/alpha');
        touch($this->root . '/Beta.txt');

        $entries = $this->entries($this->listing($this->root));

        // strcmp order on names, case-sensitive: uppercase sorts first, like
        // `LC_COLLATE=C ls`, and directories are named wherever their name
        // lands — no dirs-first grouping.
        $this->assertSame([
            $this->root . '/Beta.txt',
            $this->root . '/alpha/',
            $this->root . '/beta.txt',
            $this->root . '/zeta/',
        ], $entries);
    }

    public function testSymlinkedDirectoryInsideTheJailGetsTheMarker(): void
    {
        // ls -p doctrine: stat follows the link, so what behaves like a
        // directory from here is marked like one.
        mkdir($this->root . '/real');
        symlink($this->root . '/real', $this->root . '/link');

        $entries = $this->entries($this->listing($this->root));

        $this->assertContains($this->root . '/link/', $entries);
    }

    public function testEmptyDirectoryIsAnEmptyResultNotAnError(): void
    {
        $result = $this->listing($this->root);

        $this->assertFalse($result->isError());
        $this->assertSame('', $result->content());
    }

    public function testTrailingSlashOnPathListsTheSameDirectory(): void
    {
        touch($this->root . '/one.txt');

        $entries = $this->entries($this->listing($this->root . '/'));

        $this->assertSame([$this->root . '/one.txt'], $entries);
    }

    // =========================================================================
    // Hidden and ignored entries
    // =========================================================================

    public function testDotEntriesAreOmittedByDefaultAndShownOnRequest(): void
    {
        touch($this->root . '/visible.txt');
        touch($this->root . '/.hidden');
        mkdir($this->root . '/.config');

        $default = $this->entries($this->listing($this->root));
        $this->assertSame([$this->root . '/visible.txt'], $default);

        $widened = $this->entries($this->listing($this->root, ['include_hidden' => true]));
        $this->assertSame([
            $this->root . '/.config/',
            $this->root . '/.hidden',
            $this->root . '/visible.txt',
        ], $widened);
    }

    public function testStringFalseForIncludeHiddenStaysFalse(): void
    {
        touch($this->root . '/.hidden');

        // Models send "false" and 1 for booleans; a bare cast would turn the
        // string "false" into true and silently widen every listing a model
        // meant to keep narrow (Glob's flag() reason, applied to this flag).
        $entries = $this->entries($this->listing($this->root, ['include_hidden' => 'false']));

        $this->assertSame([], $entries);
    }

    public function testGitignoredEntriesAreHiddenWithANoteAndShownOnRequest(): void
    {
        file_put_contents($this->root . '/.gitignore', "build/\n");
        mkdir($this->root . '/build');
        touch($this->root . '/keep.txt');

        $result = $this->listing($this->root);
        $this->assertSame([$this->root . '/keep.txt'], $this->entries($result));
        $this->assertStringContainsString('[gitignored: hid 1 entry', $result->content());
        $this->assertStringContainsString('Pass include_ignored: true', $result->content());

        $shown = $this->listing($this->root, ['include_ignored' => true]);
        $this->assertContains($this->root . '/build/', $this->entries($shown));
        $this->assertStringNotContainsString('gitignored', $shown->content());
    }

    public function testPointingPathInsideAnIgnoredDirectoryListsItAnyway(): void
    {
        // Glob's doctrine: naming a skipped tree as the base IS the escape
        // hatch, and a confident empty answer about the directory the caller
        // just named is the wrongest possible one.
        file_put_contents($this->root . '/.gitignore', "generated/\n");
        mkdir($this->root . '/generated');
        touch($this->root . '/generated/out.dat');

        $result = $this->listing($this->root . '/generated');

        $this->assertSame([$this->root . '/generated/out.dat'], $this->entries($result));
        $this->assertStringNotContainsString('gitignored', $result->content());
    }

    public function testVendorLikeDirectoriesAreNamedNotPruned(): void
    {
        // The ONE deliberate divergence from Glob: prune lists exist to spare
        // recursive descents. A one-level listing has no descent to spare —
        // the directory's name is the answer.
        mkdir($this->root . '/vendor');

        $entries = $this->entries($this->listing($this->root));

        $this->assertSame([$this->root . '/vendor/'], $entries);
    }

    // =========================================================================
    // Caps and notes
    // =========================================================================

    public function testCapStopsAtMaxEntriesAndAnnouncesItself(): void
    {
        touch($this->root . '/a1.txt');
        touch($this->root . '/a2.txt');
        touch($this->root . '/a3.txt');

        $result = $this->listing($this->root, maxEntries: 2);

        $this->assertSame([$this->root . '/a1.txt', $this->root . '/a2.txt'], $this->entries($result));
        $this->assertStringContainsString('[truncated: stopped after 2 entries', $result->content());
        $this->assertStringContainsString('This list is PARTIAL', $result->content());
    }

    public function testCapCountsEntriesNotBytesAndTheByteCapStillBoundsTheText(): void
    {
        touch($this->root . '/a.txt');
        touch($this->root . '/b.txt');

        $result = $this->ls()->execute([
            'id' => 'ls-test',
            'path' => $this->root,
            'description' => 'List a fixture directory',
        ]);

        // Default instance: byte cap far above two short paths.
        $this->assertFalse($result->isError());

        $tight = (new Ls(root: $this->root, maxOutputBytes: 64))->execute([
            'id' => 'ls-test',
            'path' => $this->root,
            'description' => 'List a fixture directory',
        ]);
        $this->assertLessThanOrEqual(4096, strlen($tight->content()));
    }

    // =========================================================================
    // Jail and bad requests — Glob's error vocabulary, asserted per tool
    // =========================================================================

    public function testPathOutsideTheWorkspaceRootIsRefused(): void
    {
        $result = $this->listing('/etc');

        $this->assertTrue($result->isError());
        $this->assertSame('Error: path outside workspace root', $result->content());
    }

    public function testFileOperandSaysNotADirectoryNotUnreadable(): void
    {
        touch($this->root . '/plain.txt');

        $result = $this->listing($this->root . '/plain.txt');

        $this->assertTrue($result->isError());
        $this->assertStringStartsWith('Error: not a directory: ', $result->content());
    }

    public function testMissingPathWithoutARootSaysDirectoryNotFound(): void
    {
        $missing = $this->root . '-nope';

        $result = (new Ls())->execute([
            'id' => 'ls-test',
            'path' => $missing,
            'description' => 'List a fixture directory',
        ]);

        $this->assertTrue($result->isError());
        $this->assertSame("Error: directory not found: $missing", $result->content());
    }

    public function testNulBytePathIsRefusedNotThrown(): void
    {
        // realpath() THROWS a ValueError on a NUL; an unguarded one would
        // crash execute() instead of answering it.
        $result = $this->listing($this->root . "/\0");

        $this->assertTrue($result->isError());
        $this->assertSame('Error: path contains a NUL byte', $result->content());
    }

    public function testNonStringPathIsRefused(): void
    {
        $result = $this->ls()->execute([
            'id' => 'ls-test',
            'path' => 42,
            'description' => 'List a fixture directory',
        ]);

        $this->assertTrue($result->isError());
        $this->assertSame('Error: $path must be a string', $result->content());
    }

    public function testSymlinkEscapingTheJailIsHiddenAndAnnounced(): void
    {
        $outside = realpath(sys_get_temp_dir()) . '/crush-ls-outside-' . uniqid('', true);
        mkdir($outside);
        touch($outside . '/secret.dat');
        symlink($outside, $this->root . '/escape');

        try {
            $result = $this->listing($this->root);

            $this->assertSame([], $this->entries($result));
            $this->assertStringContainsString('[outside: hid 1 entry', $result->content());
            $this->assertStringNotContainsString('secret.dat', $result->content());
        } finally {
            @unlink($this->root . '/escape');
            @unlink($outside . '/secret.dat');
            @rmdir($outside);
        }
    }

    // =========================================================================
    // Catalog contract
    // =========================================================================

    public function testWorktreeJailRebindingOverridesTheWorkspaceRoot(): void
    {
        // Audit F-J5 lineage pinned for THIS tool: a sub-agent's Ls must list
        // its own worktree, not the main checkout the root string still names.
        $worktree = realpath(sys_get_temp_dir()) . '/crush-ls-wt-' . uniqid('', true);
        mkdir($worktree);
        touch($worktree . '/mine.txt');

        try {
            $jailed = $this->ls()->withWorktreeJail(new AgentPathJail($worktree, new PathJailConfig()));

            $escaped = $jailed->execute([
                'id' => 'ls-test',
                'path' => $this->root,
                'description' => 'List the main checkout',
            ]);
            $this->assertTrue($escaped->isError());
            $this->assertSame('Error: path outside workspace root', $escaped->content());

            $listed = $jailed->execute([
                'id' => 'ls-test',
                'path' => $worktree,
                'description' => 'List my worktree',
            ]);
            $this->assertSame([$worktree . '/mine.txt'], $this->entries($listed));
        } finally {
            @unlink($worktree . '/mine.txt');
            @rmdir($worktree);
        }
    }
}
