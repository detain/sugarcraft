<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Memory;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Memory\ForeignMemoryImporter;
use SugarCraft\Crush\Memory\MemoryStore;

final class ForeignMemoryImporterTest extends TestCase
{
    private string $tempDir;
    private string $storeDir;
    private string $projectRoot;
    private MemoryStore $store;
    private ForeignMemoryImporter $importer;
    private string $origHome;
    private string $origErrorLog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/foreign_memory_' . uniqid((string) getmypid(), true);
        $this->storeDir = $this->tempDir . '/store';
        $this->projectRoot = $this->tempDir . '/project';
        mkdir($this->storeDir, 0777, true);
        mkdir($this->projectRoot, 0777, true);

        // The default ~/.claude lookup must not read the real machine's memory.
        $this->origHome = $_SERVER['HOME'] ?? '/root';
        $_SERVER['HOME'] = $this->tempDir . '/default-empty-home';
        putenv('HOME=' . $_SERVER['HOME']);
        mkdir($_SERVER['HOME'], 0777, true);

        // Keep the skip-a-malformed-file error_log() calls out of the suite's stderr.
        $this->origErrorLog = (string) ini_get('error_log');
        ini_set('error_log', $this->tempDir . '/error.log');

        $this->store = new MemoryStore($this->storeDir);
        $this->importer = new ForeignMemoryImporter($this->store);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->origErrorLog);
        $_SERVER['HOME'] = $this->origHome;
        putenv('HOME=' . $_SERVER['HOME']);
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testImportClaudeCodeImportsEntriesTaggedWithProvenance(): void
    {
        $home = $this->claudeMemoryDir($this->projectRoot);
        file_put_contents(
            $home . '/pattern_one.md',
            "---\ndescription: Ship-as-you-go cadence\n---\nOne PR per change-set.\n"
        );

        $count = $this->importer->importClaudeCode($this->projectRoot, $this->tempDir . '/claude');

        $this->assertSame(1, $count);
        // MemoryScope::Local is stored under the 'agent' scope directory.
        $entries = $this->store->list('agent');
        $this->assertCount(1, $entries);
        $this->assertSame("Ship-as-you-go cadence\n\nOne PR per change-set.", $entries[0]->content());
        $this->assertSame(['source:claude'], $entries[0]->tags());
    }

    public function testImportClaudeCodeSkipsGeneratedIndexFile(): void
    {
        $dir = $this->claudeMemoryDir($this->projectRoot);
        file_put_contents($dir . '/MEMORY.md', "---\ndescription: Index\n---\n- [a](a.md)\n");
        file_put_contents($dir . '/a.md', "---\ndescription: A\n---\nbody a\n");

        $count = $this->importer->importClaudeCode($this->projectRoot, $this->tempDir . '/claude');

        $this->assertSame(1, $count);
        $this->assertSame("A\n\nbody a", $this->store->list('agent')[0]->content());
    }

    public function testImportClaudeCodeSkipsFilesWithoutFrontmatter(): void
    {
        $dir = $this->claudeMemoryDir($this->projectRoot);
        file_put_contents($dir . '/notes.md', "just a plain note, no frontmatter\n");

        $this->assertSame(0, $this->importer->importClaudeCode($this->projectRoot, $this->tempDir . '/claude'));
        $this->assertSame([], $this->store->list('agent'));
    }

    public function testImportClaudeCodeFallsBackToFilenameWhenDescriptionIsMissing(): void
    {
        $dir = $this->claudeMemoryDir($this->projectRoot);
        file_put_contents($dir . '/feedback_pr_size.md', "---\ntype: preference\n---\nBundle 2-4 items.\n");

        $this->importer->importClaudeCode($this->projectRoot, $this->tempDir . '/claude');

        $this->assertSame("feedback_pr_size\n\nBundle 2-4 items.", $this->store->list('agent')[0]->content());
    }

    /**
     * A YAML `description:` decoding to a list must not be concatenated as the
     * literal "Array" (nor raise an Array-to-string conversion warning).
     */
    public function testImportClaudeCodeFallsBackToFilenameWhenDescriptionIsNotAString(): void
    {
        $dir = $this->claudeMemoryDir($this->projectRoot);
        file_put_contents($dir . '/listy.md', "---\ndescription:\n  - one\n  - two\n---\nbody\n");

        $count = $this->importer->importClaudeCode($this->projectRoot, $this->tempDir . '/claude');

        $this->assertSame(1, $count);
        $this->assertSame("listy\n\nbody", $this->store->list('agent')[0]->content());
    }

    public function testImportClaudeCodeRecordsOriginSessionIdAsATag(): void
    {
        $dir = $this->claudeMemoryDir($this->projectRoot);
        file_put_contents(
            $dir . '/origin.md',
            "---\ndescription: With origin\nmetadata:\n  originSessionId: abc-123\n---\nbody\n"
        );

        $this->importer->importClaudeCode($this->projectRoot, $this->tempDir . '/claude');

        $this->assertSame(['source:claude', 'origin:abc-123'], $this->store->list('agent')[0]->tags());
    }

    /**
     * A `metadata:` block that is not a map, or an origin id that is not a
     * scalar, yields no origin tag rather than a bogus one.
     */
    public function testImportClaudeCodeDropsNonScalarOrigin(): void
    {
        $dir = $this->claudeMemoryDir($this->projectRoot);
        file_put_contents($dir . '/a.md', "---\ndescription: A\nmetadata: not-a-map\n---\nbody\n");
        file_put_contents(
            $dir . '/b.md',
            "---\ndescription: B\nmetadata:\n  originSessionId:\n    nested: yes\n---\nbody\n"
        );

        $count = $this->importer->importClaudeCode($this->projectRoot, $this->tempDir . '/claude');

        $this->assertSame(2, $count);
        foreach ($this->store->list('agent') as $entry) {
            $this->assertSame(['source:claude'], $entry->tags());
        }
    }

    public function testImportClaudeCodeSkipsMalformedYamlButImportsTheRest(): void
    {
        $dir = $this->claudeMemoryDir($this->projectRoot);
        file_put_contents($dir . '/broken.md', "---\ndescription: \"unterminated\nfoo: [bar\n---\nbody\n");
        file_put_contents($dir . '/good.md', "---\ndescription: Good\n---\nbody good\n");

        $count = $this->importer->importClaudeCode($this->projectRoot, $this->tempDir . '/claude');

        $this->assertSame(1, $count);
        $this->assertSame("Good\n\nbody good", $this->store->list('agent')[0]->content());
    }

    public function testImportClaudeCodeReturnsZeroWhenTheForeignDirectoryIsAbsent(): void
    {
        $this->assertSame(0, $this->importer->importClaudeCode($this->projectRoot, $this->tempDir . '/nope'));
        $this->assertSame([], $this->store->list('agent'));
    }

    /**
     * Claude Code never writes a trailing separator into its project slug, so a
     * caller passing a trailing-slash root must resolve to the same directory.
     */
    public function testImportClaudeCodeIgnoresATrailingSlashOnTheProjectRoot(): void
    {
        $dir = $this->claudeMemoryDir($this->projectRoot);
        file_put_contents($dir . '/a.md', "---\ndescription: A\n---\nbody\n");

        $count = $this->importer->importClaudeCode($this->projectRoot . '/', $this->tempDir . '/claude');

        $this->assertSame(1, $count);
    }

    public function testImportClaudeCodeDefaultsToClaudeHomeUnderHome(): void
    {
        $_SERVER['HOME'] = $this->tempDir . '/fake-home';
        putenv('HOME=' . $_SERVER['HOME']);
        $dir = $this->claudeMemoryDir($this->projectRoot, $this->tempDir . '/fake-home/.claude');
        file_put_contents($dir . '/a.md', "---\ndescription: From home\n---\nbody\n");

        $count = $this->importer->importClaudeCode($this->projectRoot);

        $this->assertSame(1, $count);
        $this->assertSame("From home\n\nbody", $this->store->list('agent')[0]->content());
    }

    /**
     * Claude Code dashes EVERY non-alphanumeric character, not only `/`
     * (audit 15d-06): `.`, `_` and spaces too, with no collapsing, so the
     * leading `/` stays a leading `-` and `/.claude` becomes `--claude`. The
     * importer looked up the `/`-only spelling and found nothing; in the
     * audit's repro it imported a decoy sitting at that spelling instead.
     * The decoy is still here, and must lose to the real directory.
     */
    public function testSlugMatchesClaudeCodeForDottedAndUnderscoredPaths(): void
    {
        $project = $this->tempDir . '/web.site_x';
        file_put_contents(
            $this->claudeMemoryDir($project) . '/deploy.md',
            "---\ndescription: Deploy via make\n---\nUse make deploy.\n",
        );
        file_put_contents(
            $this->legacyClaudeMemoryDir($project) . '/decoy.md',
            "---\ndescription: Decoy at the dotted slug\n---\nwrong directory\n",
        );

        $this->assertSame(1, $this->importer->importClaudeCode($project, $this->tempDir . '/claude'));
        $this->assertSame(['Deploy via make'], $this->importedTitles());
    }

    /**
     * Literal slugs, so the expectation is not the same regex as the code
     * under test: each right-hand side is what Claude Code 2.1.287's own
     * `replace(/[^a-zA-Z0-9]/g, "-")` + 200-character cap + hash suffix
     * produced for the left-hand path, run under node. The first two are also
     * spellings this host's real `~/.claude/projects/` holds.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function claudeSlugs(): iterable
    {
        yield 'dotted host name' => ['/home/sites/webhooks.interserver.net', '-home-sites-webhooks-interserver-net'];
        yield 'dot-directory doubles the dash' => [
            '/home/sites/phlix/phlix-server/.claude/worktrees/gifted-mcclintock-809258',
            '-home-sites-phlix-phlix-server--claude-worktrees-gifted-mcclintock-809258',
        ];
        yield 'underscore, space, dot-dir' => ['/srv/web.site_x/.claude/wt y', '-srv-web-site-x--claude-wt-y'];
        yield 'BMP char is one dash, astral char is a surrogate pair' => ["/srv/caf\u{e9}/\u{1F600}x", '-srv-caf----x'];
        yield 'over 200 characters is cut and hashed' => [
            '/srv/' . str_repeat('deep.dir/', 30) . 'project',
            '-srv-' . substr(str_repeat('deep-dir-', 30), 0, 195) . '-ps3jym',
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('claudeSlugs')]
    public function testSlugSpellingMatchesClaudeCode(string $projectRoot, string $slug): void
    {
        file_put_contents($this->memoryDirAtSlug($slug) . '/a.md', "---\ndescription: Found\n---\nbody\n");

        $this->assertSame(1, $this->importer->importClaudeCode($projectRoot, $this->tempDir . '/claude'));
        $this->assertSame(['Found'], $this->importedTitles());
    }

    /**
     * A tree laid out for the lookup this class did before audit 15d-06 (only
     * `/` dashed) still imports when Claude Code's spelling holds nothing.
     */
    public function testImportClaudeCodeFallsBackToTheLegacySlugSpelling(): void
    {
        $project = $this->tempDir . '/web.site_x';
        file_put_contents(
            $this->legacyClaudeMemoryDir($project) . '/old.md',
            "---\ndescription: From the old spelling\n---\nbody\n",
        );

        $this->assertSame(1, $this->importer->importClaudeCode($project, $this->tempDir . '/claude'));
        $this->assertSame(['From the old spelling'], $this->importedTitles());
    }

    /**
     * A path with nothing but letters, digits and `/` spells both slugs the
     * same; that one directory is read once, not once per spelling.
     */
    public function testAPathWhoseSpellingsCoincideIsImportedOnce(): void
    {
        file_put_contents($this->memoryDirAtSlug('-srv-plain-project') . '/a.md', "---\ndescription: Once\n---\nbody\n");

        $this->assertSame(1, $this->importer->importClaudeCode('/srv/plain-project', $this->tempDir . '/claude'));
        $this->assertSame(['Once'], $this->importedTitles());
    }

    /**
     * The listing read the directory as a glob pattern (the 15d-22 shape): a
     * Claude home under `claude[1]` matched the sibling `claude1` and imported
     * its notes instead of its own. Dot-files stay out, as glob's `*.md` kept
     * them out.
     */
    public function testAClaudeHomeContainingGlobMetacharactersListsItsOwnNotes(): void
    {
        $project = $this->tempDir . '/project';
        $own = $this->claudeMemoryDir($project, $this->tempDir . '/claude[1]');
        file_put_contents($own . '/b.md', "---\ndescription: Own B\n---\nbody\n");
        file_put_contents($own . '/a.md', "---\ndescription: Own A\n---\nbody\n");
        file_put_contents($own . '/.hidden.md', "---\ndescription: Hidden\n---\nbody\n");
        file_put_contents($own . '/notes.txt', "---\ndescription: Not markdown\n---\nbody\n");
        file_put_contents(
            $this->claudeMemoryDir($project, $this->tempDir . '/claude1') . '/sibling.md',
            "---\ndescription: Sibling tree\n---\nbody\n",
        );

        $this->assertSame(2, $this->importer->importClaudeCode($project, $this->tempDir . '/claude[1]'));
        $this->assertSame(['Own A', 'Own B'], $this->importedTitles());
    }

    /** The same listing, reached through the opencode tier's checkout path. */
    public function testAnOpencodeCheckoutContainingGlobMetacharactersListsItsOwnNotes(): void
    {
        $root = $this->tempDir . '/proj[1]';
        mkdir($root . '/.opencode/memory', 0777, true);
        file_put_contents($root . '/.opencode/memory/own.md', "own note\n");
        mkdir($this->tempDir . '/proj1/.opencode/memory', 0777, true);
        file_put_contents($this->tempDir . '/proj1/.opencode/memory/sibling.md', "sibling note\n");

        $this->assertSame(1, $this->importer->importOpencode($root));
        $this->assertSame(['# own'], $this->importedTitles());
    }

    public function testImportOpencodeImportsWholeFilesWithFilenameTitles(): void
    {
        $dir = $this->projectRoot . '/.opencode/memory';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/now.md', "current focus: wave 1\n");
        file_put_contents($dir . '/archive.md', "older notes\n");

        $count = $this->importer->importOpencode($this->projectRoot);

        $this->assertSame(2, $count);
        $entries = $this->store->list('agent');
        $this->assertCount(2, $entries);
        $contents = array_map(static fn($e) => $e->content(), $entries);
        $this->assertContains("# now\n\ncurrent focus: wave 1", $contents);
        $this->assertContains("# archive\n\nolder notes", $contents);
        foreach ($entries as $entry) {
            $this->assertSame(['source:opencode'], $entry->tags());
        }
    }

    /**
     * opencode memory files have no frontmatter, so a file that happens to
     * start with `---` is still imported whole rather than skipped.
     */
    public function testImportOpencodeDoesNotRequireFrontmatter(): void
    {
        $dir = $this->projectRoot . '/.opencode/memory';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/raw.md', "---\nnot: parsed\n---\nbody\n");

        $count = $this->importer->importOpencode($this->projectRoot);

        $this->assertSame(1, $count);
        $this->assertSame("# raw\n\n---\nnot: parsed\n---\nbody", $this->store->list('agent')[0]->content());
    }

    public function testImportOpencodeReturnsZeroWhenTheForeignDirectoryIsAbsent(): void
    {
        $this->assertSame(0, $this->importer->importOpencode($this->projectRoot));
        $this->assertSame([], $this->store->list('agent'));
    }

    public function testImportOpencodeIgnoresATrailingSlashOnTheProjectRoot(): void
    {
        $dir = $this->projectRoot . '/.opencode/memory';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/a.md', "body\n");

        $this->assertSame(1, $this->importer->importOpencode($this->projectRoot . '/'));
    }

    /**
     * Imports are not idempotent -- MemoryStore mints a fresh id per add() --
     * which is why de-duplication is the caller's sentinel-file job.
     */
    public function testRepeatedImportDuplicatesEntries(): void
    {
        $dir = $this->projectRoot . '/.opencode/memory';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/a.md', "body\n");

        $this->importer->importOpencode($this->projectRoot);
        $this->importer->importOpencode($this->projectRoot);

        $this->assertCount(2, $this->store->list('agent'));
    }

    /**
     * Build the Claude Code memory directory for $projectRoot under $claudeHome
     * (default: this test's fake ~/.claude), mirroring the real
     * `<home>/projects/<slug>/memory` layout. The slug is Claude Code's: every
     * non-alphanumeric byte becomes `-` (these fixture paths are ASCII and far
     * below the 200-character cap; uniqid()'s `.` makes the old `/`-only
     * spelling differ from it, so every test here exercises the real lookup).
     */
    private function claudeMemoryDir(string $projectRoot, ?string $claudeHome = null): string
    {
        return $this->memoryDirAtSlug(
            (string) preg_replace('/[^A-Za-z0-9]/', '-', rtrim($projectRoot, '/')),
            $claudeHome,
        );
    }

    /** The slug this importer looked up before audit 15d-06: only `/` dashed. */
    private function legacyClaudeMemoryDir(string $projectRoot, ?string $claudeHome = null): string
    {
        return $this->memoryDirAtSlug('-' . ltrim(str_replace('/', '-', rtrim($projectRoot, '/')), '-'), $claudeHome);
    }

    private function memoryDirAtSlug(string $slug, ?string $claudeHome = null): string
    {
        $dir = ($claudeHome ?? $this->tempDir . '/claude') . '/projects/' . $slug . '/memory';
        mkdir($dir, 0777, true);

        return $dir;
    }

    /** @return list<string> the first line of every imported entry, sorted */
    private function importedTitles(): array
    {
        $titles = array_map(static fn($e): string => strtok($e->content(), "\n"), $this->store->list('agent'));
        sort($titles);

        return $titles;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
