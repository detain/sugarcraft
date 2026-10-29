<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Context\ProjectMemoryWriter;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\MemoryCommand;
use SugarCraft\Code\Memory\MemoryStore;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Support\PinsEnglishLocaleTrait;

/**
 * `/memory export [path]` (C-CTX-3.e) — one markdown bundle of every note in
 * both stores, jailed and 0600 like `/export`.
 *
 * The bundle law pinned here: every note — home and repository, user, project
 * and agent scopes — lands exactly once, frontmattered with the same fields
 * `MemoryStore::writeEntry()` persists, and NOTHING outside the project root
 * or the `~/.candy-code` shelf is ever written. The command arm itself is
 * read-only: a refused or completed export leaves both stores byte-intact.
 * Driving is direct (`MemoryCommand::run()` through a `CommandContext`), the
 * house shape for host commands — the jail and the file mode are the subject,
 * and neither cares who typed the line.
 */
final class MemoryExportCommandTest extends TestCase
{
    use PinsEnglishLocaleTrait;
    use HomeSandboxTrait;

    private string $sandbox = '';
    private string $home = '';
    private string $project = '';
    private string $storeDir = '';
    private MemoryStore $store;
    private string $origErrorLog = '';

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-memexport-' . bin2hex(random_bytes(6));
        $this->home = $this->useHomeSandbox($this->sandbox . '/home');
        $this->project = $this->sandbox . '/project';
        $this->storeDir = $this->sandbox . '/store';
        mkdir($this->project, 0700, true);
        mkdir($this->storeDir, 0700, true);

        $this->origErrorLog = (string) ini_get('error_log');
        ini_set('error_log', $this->sandbox . '/error.log');

        $this->store = new MemoryStore($this->storeDir);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->origErrorLog);
        $this->restoreHomeSandbox();
        if ($this->sandbox !== '' && is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox));
        }
    }

    private function memory(string $args): string
    {
        $context = CommandContext::new(
            sessionId: 'export-test',
            root: $this->project,
            memoryStore: $this->store,
        );

        return (string) (new MemoryCommand())->run($context, '/memory ' . $args)->rows[1]->content;
    }

    /** @return list<string> bundle files actually sitting in the exports shelf */
    private function shelfFiles(): array
    {
        $dir = $this->home . '/.candy-code/exports';
        if (!is_dir($dir)) {
            return [];
        }

        return array_values(array_filter(
            scandir($dir) ?: [],
            static fn (string $n): bool => str_starts_with($n, 'memory-') && str_ends_with($n, '.md'),
        ));
    }

    /** Seed the three tiers one export must carry: home user, home agent, repo project. */
    private function seedThreeTiers(): void
    {
        $this->store->add('user note body', 'user');
        $this->store->add('agent note body', 'agent', ['source:opencode']);
        $writer = ProjectMemoryWriter::createForRoot($this->project);
        $this->assertNotNull($writer);
        $writer->write('repo note body');
    }

    // ── 1. the empty store refuses without touching the filesystem ──────────

    public function testEmptyStoreAnswersExportNoneAndWritesNothing(): void
    {
        $reply = $this->memory('export');

        $this->assertStringContainsString('There are no memory notes to export.', $reply);
        $this->assertSame([], $this->shelfFiles());
    }

    // ── 2. the default path: the exports shelf, stamped name, 0600 ──────────

    public function testDefaultPathLandsInHomeShelfUnderAStampedName(): void
    {
        $this->seedThreeTiers();

        $reply = $this->memory('export');

        $files = $this->shelfFiles();
        $this->assertCount(1, $files, 'the default export writes exactly one shelf file');
        $this->assertStringContainsString(rtrim($this->home, '/') . '/.candy-code/exports/' . $files[0], $reply);

        // The name grammar: memory-<session>-<UTC stamp>.md, session id kept
        // filesystem-safe. Derive from scandir above, never a wildcard.
        $this->assertStringStartsWith('memory-export-test-', $files[0]);
        $this->assertStringEndsWith('.md', $files[0]);

        $full = $this->home . '/.candy-code/exports/' . $files[0];
        $this->assertSame(0600, fileperms($full) & 0777, 'a memory bundle lands mode 0600, like the store files it mirrors');

        $body = (string) file_get_contents($full);
        $this->assertStringStartsWith('# Candy-Code memory export', $body);
        $this->assertStringContainsString('**Exported 3** notes to', $reply);
        $this->assertSame(3, preg_match_all('/^id: /m', $body), 'one frontmatter id per note, and no note twice');
        $this->assertStringContainsString('## Home store', $body);
        $this->assertStringContainsString('## Repository store', $body);
        foreach (['user note body', 'agent note body', 'repo note body'] as $text) {
            $this->assertStringContainsString($text, $body);
        }
        // The agent tier — the notes the prompt never folds — is in the
        // bundle, with its provenance tag, because the USER owns it.
        $this->assertStringContainsString('scope: agent', $body);
        $this->assertStringContainsString("- 'source:opencode'", $body);
        $this->assertStringContainsString('The bundle holds every scope', $reply);

        // Read-only arm: every store still holds exactly what it held before.
        $this->assertCount(1, $this->store->list('user'));
        $this->assertCount(1, $this->store->list('agent'));
        $this->assertCount(1, ProjectMemoryWriter::forRoot($this->project)?->store()->list('project') ?? []);
    }

    // ── 3. the jail ──────────────────────────────────────────────────────────

    public function testPlainPathInsideTheProjectIsWrittenRelative(): void
    {
        $this->seedThreeTiers();

        $reply = $this->memory('export notes.md');

        $this->assertStringContainsString($this->project . '/notes.md', $reply);
        $this->assertFileExists($this->project . '/notes.md');
        $this->assertSame(0600, fileperms($this->project . '/notes.md') & 0777);
        $this->assertStringContainsString('id: ', (string) file_get_contents($this->project . '/notes.md'));
    }

    public function testTraversalPastTheProjectAndTheShelfIsRefusedAndWritesNothing(): void
    {
        $this->seedThreeTiers();

        $reply = $this->memory('export ../escaped-export.md');

        $this->assertStringContainsString('is outside this project and outside the', $reply);
        $this->assertStringContainsString('.candy-code/exports', $reply, 'the refusal names the only shelf the jail opens onto');
        $this->assertFileDoesNotExist($this->sandbox . '/escaped-export.md');
        $this->assertSame([], $this->shelfFiles(), 'a refused export writes nothing anywhere');
    }

    public function testHomeShelfPathThroughTildeIsAccepted(): void
    {
        $this->seedThreeTiers();

        $reply = $this->memory('export ~/.candy-code/exports/keep.md');

        $this->assertStringContainsString($this->home . '/.candy-code/exports/keep.md', $reply);
        $this->assertFileExists($this->home . '/.candy-code/exports/keep.md');
        $this->assertSame(0600, fileperms($this->home . '/.candy-code/exports/keep.md') & 0777);
    }

    // ── 4. name completion: directory targets and extensionless files ───────

    public function testDirectoryTargetTakesTheStampedNameAndBareFileGetsMd(): void
    {
        $this->seedThreeTiers();
        mkdir($this->project . '/bundle', 0700, true);

        $dirReply = $this->memory('export bundle');
        $this->assertStringContainsString($this->project . '/bundle/memory-export-test-', $dirReply);
        $this->assertCount(1, array_filter(scandir($this->project . '/bundle') ?: [], static fn (string $n): bool => str_starts_with($n, 'memory-')));

        $bareReply = $this->memory('export memoir');
        $this->assertStringContainsString($this->project . '/memoir.md', $bareReply);
        $this->assertFileExists($this->project . '/memoir.md');
    }

    // ── 5. no home, no default ───────────────────────────────────────────────

    public function testWithoutAnOwnedHomeTheDefaultLocationRefusesAndAsksForAPath(): void
    {
        $this->seedThreeTiers();
        $ghost = $this->sandbox . '/ghost-home';
        putenv('HOME=' . $ghost);

        try {
            $reply = $this->memory('export');
            $this->assertStringContainsString('Export needs your home directory', $reply);
            $this->assertFileDoesNotExist($ghost);

            // A project path still works without a home — only the default
            // location depends on one.
            $ok = $this->memory('export here.md');
            $this->assertStringContainsString($this->project . '/here.md', $ok);
        } finally {
            putenv('HOME=' . $this->home);
        }
    }

    // ── 6. help and usage surface ────────────────────────────────────────────

    public function testHelpListsTheExportVerbAndUsageAnswersBare(): void
    {
        $help = $this->memory('');
        $this->assertStringContainsString('/memory export [path]', $help);

        // With no notes at all the export arm answers the empty-store line,
        // never a usage line — `export` alone is a valid command.
        $this->assertStringContainsString('There are no memory notes to export.', $this->memory('export'));
    }
}
