<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\McpDecision;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Cli\Subcommands;
use SugarCraft\Code\Support\HomeDirectory;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * C-MCP-4.d — `candy-code mcp import <source> <path> --write user`, the print-only
 * law's single named exception (plan §6.1 ruling: user tier only, collisions
 * refused, project tier NEVER).
 *
 * The DEFAULT path keeps every one of its E710 pins in
 * {@see SubcommandsMcpImportTest}, byte for byte; THIS file is the new door.
 * What it holds the implementation to:
 *
 * - the write lands at McpDecision::mcpUserConfigPath() — inside a home only
 *   HomeDirectory::owned() vouched for — at mode 0600, via AtomicFileWriter's
 *   temp-then-rename (the perms pin is what reddens if the mode argument is
 *   dropped, because the ambient umask alone does NOT promise 0600);
 * - a name the existing file already declares aborts the WHOLE write, lists
 *   the names, and leaves the file byte-identical — merging or overwriting
 *   silently is the accident this pin exists to catch;
 * - a corrupt existing file is refused, never repaired (same posture as
 *   `login`'s merge-read);
 * - `--write project` (and any other tier) exits 2 having attempted nothing,
 *   and a `claude-project` import with `--write user` writes the USER tier
 *   and still no `.mcp.json` in the repo — the nested READ source does not
 *   smuggle a project write;
 * - the HOME fixture is pinned meaningful BEFORE each expectation
 *   (`HomeDirectory::owned()` must answer the sandbox, or every green byte
 *   here would be vacuous — the ForeignSkillDiscoveryTest precedent);
 * - the `claude-project` READ source: `projects["<root>"].mcpServers` is
 *   found for the named root only, the per-project read earns its note, the
 *   top-level `mcpServers` block of the same file is never the answer, and a
 *   root with no block is refused named.
 *
 * stderr is not capturable in-process; the notes channel is asserted through
 * the JSON envelope's `notes` array here, as the print-path file does.
 */
final class SubcommandsMcpImportWriteTest extends TestCase
{
    use HomeSandboxTrait;

    private string $rootDir;
    private string $homeDir;

    protected function setUp(): void
    {
        $this->rootDir = (string) realpath(sys_get_temp_dir()) . '/pg-cmcp4d-' . uniqid((string) getmypid(), true);
        self::assertTrue(mkdir($this->rootDir, 0700, true) !== false);

        // lane-cf ordering law: snapshot-and-arm HOME before anything else
        // reads the environment, so a later test never inherits this sandbox.
        $this->homeDir = $this->rootDir . '/home';
        self::assertTrue(mkdir($this->homeDir, 0700, true) !== false);
        self::useHomeSandbox($this->homeDir);
    }

    protected function tearDown(): void
    {
        self::restoreHomeSandbox();
        self::removeImportSandbox($this->rootDir);
    }

    public function testTheFlagPublishesTheUserTierFileAtModeSixHundred(): void
    {
        $doc = $this->foreignDoc('one.json', ['mcpServers' => ['alpha' => ['command' => 'npx', 'args' => ['-y', 'srv']]]]);
        self::assertSame($this->homeDir, HomeDirectory::owned(), 'the sandbox home must be what owned() vouches for, or nothing below means anything');

        [$rc, $stdout] = $this->dispatchWrite(['mcp', 'import', 'claude', $doc, '--write', 'user', '--output-format', 'json']);
        self::assertSame(NonInteractive::EXIT_OK, $rc, $stdout);

        $decoded = json_decode(trim($stdout), true);
        self::assertIsArray($decoded, $stdout);
        $file = $this->homeDir . '/.candy-code/mcp.json';
        self::assertSame($file, $decoded['result']['written'] ?? null, 'the envelope names the file it wrote');
        self::assertFileExists($file);

        // The file's mcpServers map and the envelope's document are the SAME
        // map — the write never forks from what the operator sees printed.
        $saved = json_decode((string) file_get_contents($file), true);
        self::assertIsArray($saved);
        self::assertSame($decoded['result']['mcpServers'] ?? null, $saved['mcpServers'] ?? null);
        self::assertSame(['alpha'], array_keys((array) ($saved['mcpServers'] ?? [])));
        self::assertSame('npx', $saved['mcpServers']['alpha']['command'] ?? null);
        self::assertSame(['-y', 'srv'], $saved['mcpServers']['alpha']['args'] ?? null);

        // The mode is the law's own half: a world-readable MCP file is a
        // secret file. AtomicFileWriter settles it BEFORE the publish rename,
        // so no window carries the looser umask answer.
        self::assertSame(0600, fileperms($file) & 0o777);
        self::assertSame(0700, fileperms($this->homeDir . '/.candy-code') & 0o777, 'the created tier directory stays private too');
    }

    public function testTheWritePathStillPrintsTheSameDocumentBare(): void
    {
        $doc = $this->foreignDoc('one.json', ['mcpServers' => ['alpha' => ['command' => 'npx']]]);
        self::assertSame($this->homeDir, HomeDirectory::owned());

        [$rc, $stdout] = $this->dispatchWrite(['mcp', 'import', 'claude', $doc, '--write', 'user']);
        self::assertSame(NonInteractive::EXIT_OK, $rc, $stdout);

        // Redirect-exact: stdout under the flag is exactly what the print
        // path prints — the write is an ADDITION, never a substitution.
        $printed = json_decode(trim($stdout), true);
        self::assertIsArray($printed, $stdout);
        self::assertSame(['alpha'], array_keys((array) ($printed['mcpServers'] ?? [])));
        $saved = json_decode((string) file_get_contents($this->homeDir . '/.candy-code/mcp.json'), true);
        self::assertSame($saved['mcpServers'] ?? null, $printed['mcpServers'] ?? null);
    }

    public function testAnExistingFileIsAppendedToAndItsOtherKeysSurvive(): void
    {
        $doc = $this->foreignDoc('import.json', ['mcpServers' => ['alpha' => ['command' => 'npx']]]);
        $file = $this->seedUserTier(['mcpServers' => ['keeper' => ['command' => 'keep-me']], 'other' => 'untouched']);

        [$rc, $stdout] = $this->dispatchWrite(['mcp', 'import', 'claude', $doc, '--write', 'user', '--output-format', 'json']);
        self::assertSame(NonInteractive::EXIT_OK, $rc, $stdout);

        $saved = json_decode((string) file_get_contents($file), true);
        self::assertSame(['keeper', 'alpha'], array_keys((array) ($saved['mcpServers'] ?? [])), 'the file keeps its order and the import rides in at the tail');
        self::assertSame(['command' => 'keep-me'], $saved['mcpServers']['keeper'] ?? null, 'an existing entry is carried, not re-translated');
        self::assertSame('untouched', $saved['other'] ?? null, 'keys outside mcpServers are not collateral of an append');
        // Appending never quietly re-loosens the mode.
        self::assertSame(0600, fileperms($file) & 0o777);
    }

    public function testACollisionRefusesTheWholeWriteAndChangesNothing(): void
    {
        $doc = $this->foreignDoc('import.json', ['mcpServers' => [
            'beta' => ['command' => 'new-b'],
            'gamma' => ['command' => 'new-g'],
        ]]);
        $file = $this->seedUserTier(['mcpServers' => ['beta' => ['command' => 'old-b']]]);
        $before = (string) md5_file($file);

        [$rc, $stdout] = $this->dispatchWrite(['mcp', 'import', 'claude', $doc, '--write', 'user', '--output-format', 'json']);
        self::assertSame(NonInteractive::EXIT_FAILURE, $rc, $stdout);

        $decoded = json_decode(trim($stdout), true);
        self::assertIsArray($decoded);
        self::assertSame('mcp-config', $decoded['error']['type'] ?? null, $stdout);
        $message = (string) ($decoded['error']['message'] ?? '');
        self::assertStringContainsString($file, $message, 'the refusal names the file the operator must go edit');
        self::assertStringContainsString('already declares beta — refusing the whole write', $message);
        self::assertStringNotContainsString('gamma', $message, 'only the colliding names are listed — gamma is not what stopped the write');
        self::assertArrayNotHasKey('result', $decoded);

        self::assertSame($before, (string) md5_file($file), 'the whole write aborts — not the colliding entry, not the colliding half');
        $saved = json_decode((string) file_get_contents($file), true);
        self::assertSame(['beta'], array_keys((array) ($saved['mcpServers'] ?? [])), 'gamma never landed either');
        self::assertSame('old-b', $saved['mcpServers']['beta']['command'] ?? null, 'the old bytes are the only bytes');
    }

    public function testACorruptExistingFileIsRefusedAndNeverRepaired(): void
    {
        $doc = $this->foreignDoc('import.json', ['mcpServers' => ['alpha' => ['command' => 'npx']]]);

        // Both corrupt shapes: unparseable, and parseable-but-wrong-container.
        $garbage = $this->seedUserTierRaw('not json at all {');
        [$rc, $stdout] = $this->dispatchWrite(['mcp', 'import', 'claude', $doc, '--write', 'user', '--output-format', 'json']);
        self::assertSame(NonInteractive::EXIT_FAILURE, $rc, $stdout);
        $message = (string) (json_decode(trim($stdout), true)['error']['message'] ?? '');
        self::assertStringContainsString('refusing the write rather than repairing it', $message);
        self::assertSame('not json at all {', (string) file_get_contents($garbage), 'refused means the file is not touched');

        $wrongShape = $this->seedUserTierRaw('{"servers": {}}');
        [$rc, $stdout] = $this->dispatchWrite(['mcp', 'import', 'claude', $doc, '--write', 'user', '--output-format', 'json']);
        self::assertSame(NonInteractive::EXIT_FAILURE, $rc, $stdout);
        self::assertStringContainsString('refusing the write rather than repairing it', (string) (json_decode(trim($stdout), true)['error']['message'] ?? ''));
        self::assertSame('{"servers": {}}', (string) file_get_contents($wrongShape));
    }

    public function testAnUnownedHomeRefusesBeforeAnythingIsAttempted(): void
    {
        $doc = $this->foreignDoc('one.json', ['mcpServers' => ['alpha' => ['command' => 'npx']]]);
        self::assertSame($this->homeDir, HomeDirectory::owned(), 'fixture half 1: this home is vouched for BEFORE the flip');
        self::assertTrue(chmod($this->homeDir, 0777) !== false);
        // The mode flip must survive into PHP's per-process stat cache: the
        // pin above just primed it with the 0700 answer, and without this
        // clear the fixture lies the other way — owned() keeps vouching for
        // a directory the kernel says is world-writable (measured on this
        // box: chmod alone does not invalidate the cached fileperms).
        clearstatcache();
        self::assertNull(HomeDirectory::owned(), 'fixture half 2: the flip landed where owned() reads');
        try {
            self::assertSame('', McpDecision::mcpUserConfigPath(), 'fixture: a world-writable home must make owned() answer null');

            [$rc, $stdout] = $this->dispatchWrite(['mcp', 'import', 'claude', $doc, '--write', 'user', '--output-format', 'json']);
            self::assertSame(NonInteractive::EXIT_CONFIG, $rc, $stdout);
            $decoded = json_decode(trim($stdout), true);
            self::assertSame('usage', $decoded['error']['type'] ?? null, $stdout);
            self::assertStringContainsString('not verifiably yours', (string) ($decoded['error']['message'] ?? ''));
            self::assertFileDoesNotExist($this->homeDir . '/.candy-code', 'the door closed before the directory was even created');
        } finally {
            self::assertTrue(chmod($this->homeDir, 0700) !== false);
            clearstatcache();
        }
    }

    public function testEveryOtherTierExitsTwoHavingAttemptedNothing(): void
    {
        $doc = $this->foreignDoc('one.json', ['mcpServers' => ['alpha' => ['command' => 'npx']]]);
        $repo = $this->rootDir . '/repo';
        self::assertTrue(mkdir($repo, 0700, true) !== false);
        $repoBefore = scandir($repo);

        self::assertSame($this->homeDir, HomeDirectory::owned());
        [$rc, $stdout] = $this->dispatchWrite(['mcp', 'import', 'claude', $doc, '--write', 'project', '--output-format', 'json']);
        self::assertSame(NonInteractive::EXIT_CONFIG, $rc, $stdout);
        $message = (string) (json_decode(trim($stdout), true)['error']['message'] ?? '');
        self::assertStringContainsString('mcp import --write project: only --write user is accepted — the project tier is never written', $message);

        [$rc, $stdout] = $this->dispatchWrite(['mcp', 'import', 'claude', $doc, '--write', 'galaxy', '--output-format', 'json']);
        self::assertSame(NonInteractive::EXIT_CONFIG, $rc, $stdout);

        // ArgvParser's strict-value door for a flag-shaped or missing value
        // answers through ParsedArgs::$usageError, which bin/candy-code
        // consumes on the real CLI — the in-process dispatch path carries no
        // parser error by construction (the same shape every other
        // subcommand value flag keeps: attach, serve, session). What the verb
        // sees is "no --write at all", and the honest answer to THAT is the
        // print path writing nothing:
        [$rc, $stdout] = $this->dispatchWrite(['mcp', 'import', 'claude', $doc, '--write', '--output-format', 'json']);
        self::assertSame(NonInteractive::EXIT_OK, $rc, $stdout);

        self::assertFileDoesNotExist($this->homeDir . '/.candy-code', 'a refused tier writes nothing anywhere');
        self::assertSame($repoBefore, scandir($repo));
        self::assertFileDoesNotExist($repo . '/.mcp.json');
    }

    public function testTheDefaultPathStillWritesNoHomeFile(): void
    {
        $doc = $this->foreignDoc('one.json', ['mcpServers' => ['alpha' => ['command' => 'npx']]]);
        self::assertSame($this->homeDir, HomeDirectory::owned());

        [$rc, $stdout] = $this->dispatchWrite(['mcp', 'import', 'claude', $doc]);
        self::assertSame(NonInteractive::EXIT_OK, $rc, $stdout);
        self::assertStringContainsString('"alpha"', trim($stdout));
        self::assertFileDoesNotExist($this->homeDir . '/.candy-code', 'without the flag the verb is still print-only — E710 half-intact');
    }

    public function testClaudeProjectReadsThePerProjectBlockForTheNamedRootOnly(): void
    {
        $repo = $this->rootDir . '/repo';
        self::assertTrue(mkdir($repo, 0700, true) !== false);
        $doc = $this->foreignDoc('claude.json', [
            'projects' => [
                $repo => ['mcpServers' => ['proj' => ['type' => 'local', 'command' => ['npx', '-y', 'p']]]],
                '/some/other/checkout' => ['mcpServers' => ['stray' => ['command' => 'x']]],
            ],
            'mcpServers' => ['top' => ['command' => 'never-this']],
        ]);

        [$rc, $stdout] = $this->dispatchWrite(['--root', $repo, 'mcp', 'import', 'claude-project', $doc, '--output-format', 'json']);
        self::assertSame(NonInteractive::EXIT_OK, $rc, $stdout);
        $decoded = json_decode(trim($stdout), true);
        self::assertIsArray($decoded);
        self::assertSame(
            ['proj' => ['type' => 'stdio', 'command' => 'npx', 'args' => ['-y', 'p']]],
            $decoded['result']['mcpServers'] ?? null,
            'the answer is the named root block, alias re-typed and argv split like every other source',
        );
        $notes = (array) ($decoded['result']['notes'] ?? []);
        self::assertContains(
            'read "projects"["' . $repo . '"]."mcpServers" — the per-project block of claude-project config, not its top level',
            $notes,
        );
        self::assertNotContains('moved the "mcp" block to "mcpServers"', $notes, 'the container is already this port\'s spelling — no rename note');

        // A root the file has no block for is refused, named.
        $elsewhere = $this->rootDir . '/elsewhere';
        self::assertTrue(mkdir($elsewhere, 0700, true) !== false);
        [$rc, $stdout] = $this->dispatchWrite(['--root', $elsewhere, 'mcp', 'import', 'claude-project', $doc, '--output-format', 'json']);
        self::assertSame(NonInteractive::EXIT_FAILURE, $rc, $stdout);
        $message = (string) (json_decode(trim($stdout), true)['error']['message'] ?? '');
        self::assertStringContainsString('no "mcpServers" block under "projects" for "' . $elsewhere . '"', $message);
        self::assertStringNotContainsString('stray', $message, 'another root\'s block is never a fallback');
    }

    public function testClaudeProjectWithTheFlagWritesTheUserTierAndNeverAProjectFile(): void
    {
        $repo = $this->rootDir . '/repo';
        self::assertTrue(mkdir($repo, 0700, true) !== false);
        $repoBefore = scandir($repo);
        $doc = $this->foreignDoc('claude.json', [
            'projects' => [$repo => ['mcpServers' => ['proj' => ['command' => 'p']]]],
        ]);
        self::assertSame($this->homeDir, HomeDirectory::owned());

        [$rc, $stdout] = $this->dispatchWrite(['--root', $repo, 'mcp', 'import', 'claude-project', $doc, '--write', 'user', '--output-format', 'json']);
        self::assertSame(NonInteractive::EXIT_OK, $rc, $stdout);

        $file = $this->homeDir . '/.candy-code/mcp.json';
        self::assertFileExists($file, 'the flag writes what it always writes: the user tier');
        self::assertSame(0600, fileperms($file) & 0o777);
        self::assertSame($repoBefore, scandir($repo), 'a nested READ source never smuggles a project-tier WRITE');
        self::assertFileDoesNotExist($repo . '/.mcp.json');
    }

    /**
     * @param list<string> $args
     *
     * @return array{0:int,1:string} [exit code, captured stdout]
     */
    private function dispatchWrite(array $args): array
    {
        ob_start();
        $rc = Subcommands::dispatch(ArgvParser::parse(['candy-code', ...$args]));
        $stdout = (string) ob_get_clean();

        return [$rc, $stdout];
    }

    /**
     * @param array<string, mixed> $document
     */
    private function foreignDoc(string $name, array $document): string
    {
        $path = $this->rootDir . '/' . $name;
        self::assertIsInt(file_put_contents($path, (string) json_encode($document)));

        return $path;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function seedUserTier(array $document): string
    {
        return $this->seedUserTierRaw((string) json_encode($document, JSON_PRETTY_PRINT));
    }

    private function seedUserTierRaw(string $bytes): string
    {
        $file = $this->homeDir . '/.candy-code/mcp.json';
        self::assertTrue(is_dir($this->homeDir . '/.candy-code') || mkdir($this->homeDir . '/.candy-code', 0700, true) !== false);
        self::assertIsInt(file_put_contents($file, $bytes));

        return $file;
    }

    private static function removeImportSandbox(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                self::removeImportSandbox($path);
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
