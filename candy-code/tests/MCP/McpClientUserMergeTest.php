<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\MCP\McpClient;

/**
 * MCP-3 at the client level: the two-tier read, the shadow rule, and the
 * tier provenance the grant site's `admit` closure now receives.
 *
 * The sibling suite at `tests/Cli/BootstrapMcpUserScopeTest.php` drives the
 * production gate end to end; this one owns the mechanics BELOW the gate —
 * what `McpClient` itself decides when it is handed two paths — so a future
 * caller (the sub-agent rehydrate path, a script host) inherits the same
 * precedence and the same per-file failure sentences even with no Bootstrap
 * verdict above it. Neither path is re-adjudicated here: containment and
 * trust were decided at the grant site, and the client's contract is order
 * and shadowing, nothing else.
 */
final class McpClientUserMergeTest extends TestCase
{
    private string $tmpDir;
    private string $projectPath;
    private string $userPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/mcp_merge_' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o755, true);
        $this->projectPath = $this->tmpDir . '/project.json';
        $this->userPath = $this->tmpDir . '/user.json';
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->tmpDir);
    }

    public function testLoadConfigReturnsOneRowPerTierInPrecedenceOrder(): void
    {
        $this->writeConfig($this->projectPath, ['p1' => ['type' => 'git']]);
        $this->writeConfig($this->userPath, ['u1' => ['type' => 'git']]);

        $rows = $this->loadConfig(new McpClient($this->projectPath, userConfigPath: $this->userPath));

        self::assertCount(2, $rows);
        self::assertSame($this->projectPath, $rows[0]['path'], 'the project tier must be the FIRST row — precedence is the walk order, not a comparison');
        self::assertSame(['p1'], array_keys($rows[0]['servers']));
        self::assertSame($this->userPath, $rows[1]['path']);
        self::assertSame(['u1'], array_keys($rows[1]['servers']));
    }

    public function testTheEmptyProjectPathLeavesASingleUserRow(): void
    {
        $this->writeConfig($this->userPath, ['u1' => ['type' => 'git']]);

        $rows = $this->loadConfig(new McpClient('', userConfigPath: $this->userPath));

        self::assertCount(1, $rows);
        self::assertSame($this->userPath, $rows[0]['path'], 'a refused project arrives as the empty path and contributes no row — the gate above already spoke');
    }

    public function testAMissingUserFileIsSimplyNotARow(): void
    {
        $this->writeConfig($this->projectPath, ['p1' => ['type' => 'git']]);

        $rows = $this->loadConfig(new McpClient($this->projectPath, userConfigPath: $this->tmpDir . '/absent.json'));

        self::assertCount(1, $rows);
        self::assertSame($this->projectPath, $rows[0]['path']);
    }

    public function testTheProjectTierWinsASharedNameInTheStartLoop(): void
    {
        // Markers are ground truth outside the client's bookkeeping: which
        // file's COMMAND ran answers which entry was started.
        $projectMark = $this->tmpDir . '/from-project.mark';
        $userMark = $this->tmpDir . '/from-user.mark';
        $this->writeConfig($this->projectPath, [
            'dup' => ['command' => '/bin/sh', 'args' => ['-c', 'touch ' . $projectMark], 'startTimeout' => 0.5],
        ]);
        $this->writeConfig($this->userPath, [
            'dup' => ['command' => '/bin/sh', 'args' => ['-c', 'touch ' . $userMark], 'startTimeout' => 0.5],
            'extra' => ['type' => 'git'],
        ]);

        $client = new McpClient($this->projectPath, userConfigPath: $this->userPath);

        try {
            $client->startServers();

            self::assertFileExists($projectMark, 'the first tier owns the name');
            self::assertFileDoesNotExist($userMark, 'the shadowed entry is never even attempted');
            self::assertArrayHasKey('extra', $client->startedSnapshot());
            self::assertSame([], $client->refusedServers());
        } finally {
            $client->stopServers();
        }
    }

    public function testAShadowedUserEntryCannotEvenFailItsShapeCheck(): void
    {
        // "The higher tier owns the name, including the right to mis-shape
        // it": the shadow skip runs BEFORE the ISS-091 shape check, so a
        // malformed user entry under a project-owned name produces no
        // failure sentence — while a malformed user entry under a name
        // nobody owns still does.
        $this->writeConfig($this->projectPath, ['dup' => ['type' => 'git']]);
        $this->writeConfig($this->userPath, ['dup' => 'oops', 'bad' => 'nope']);

        $client = new McpClient($this->projectPath, userConfigPath: $this->userPath);

        // W4 weld cure (r77 SwallowingCatch family): fail() inside a try whose catch
        // is RuntimeException-wide is swallowed by its own net — ExpectationFailedException
        // extends RuntimeException. Capture, then assert OUTSIDE the trap.
        $caught = null;
        try {
            $client->startServers();
        } catch (\RuntimeException $e) {
            $caught = $e;
        } finally {
            $client->stopServers();
        }

        self::assertNotNull($caught, 'the un-shadowed malformed entry must still throw');
        self::assertStringContainsString('bad (entry is string', $caught->getMessage(), 'user-tier entries keep their own shape accountability');
        self::assertStringNotContainsString('dup (entry', $caught->getMessage(), 'a shadowed entry is invisible to EVERY report — the same invisibility the listing promises');
    }

    public function testAdmitSeesTheTierFileForEachEntry(): void
    {
        $seen = [];
        $admit = static function (string $name, mixed $type, array $entry, string $tierPath) use (&$seen): bool {
            $seen[$name] = $tierPath;

            return false;
        };

        $this->writeConfig($this->projectPath, ['p1' => ['type' => 'git']]);
        $this->writeConfig($this->userPath, ['u1' => ['type' => 'git']]);

        $client = new McpClient($this->projectPath, userConfigPath: $this->userPath, admit: $admit);
        $client->startServers();

        self::assertSame(
            ['p1' => $this->projectPath, 'u1' => $this->userPath],
            $seen,
            'the 4th admit argument is the file the entry was read from — provenance the grant site needs to keep its ceremony project-only',
        );
        self::assertSame([], $client->startedSnapshot(), 'a refused entry registers nothing in either tier');
        self::assertSame(['p1', 'u1'], $client->refusedServers());
    }

    public function testAThreeParameterAdmitClosureStillMountsAndFires(): void
    {
        // The signature widening must not break embedders written against
        // MCP-5: PHP hands extra arguments to user functions that ignore
        // them, so a 3-param closure still receives every entry exactly as
        // before — and the client never re-checks its own reflection.
        $seen = [];
        $admit = static function (string $name, mixed $type, array $entry) use (&$seen): bool {
            $seen[] = $name;

            return true;
        };

        $this->writeConfig($this->projectPath, ['p1' => ['type' => 'git']]);
        $this->writeConfig($this->userPath, ['u1' => ['type' => 'git']]);

        $client = new McpClient($this->projectPath, userConfigPath: $this->userPath, admit: $admit);

        try {
            $client->startServers();

            self::assertSame(['p1', 'u1'], $seen, 'a legacy closure fires once per entry, both tiers');
            self::assertSame(['p1', 'u1'], array_keys($client->startedSnapshot()));
        } finally {
            $client->stopServers();
        }
    }

    public function testLoadConfigNamesTheUserFileForItsOwnBrokenShapes(): void
    {
        $this->writeConfig($this->projectPath, ['p1' => ['type' => 'git']]);
        file_put_contents($this->userPath, '{not json');

        $caught = null;
        try {
            $this->loadConfig(new McpClient($this->projectPath, userConfigPath: $this->userPath));
        } catch (\RuntimeException $e) {
            $caught = $e;
        }
        self::assertNotNull($caught, 'a malformed user file must throw, not degrade to project-only');
        self::assertStringContainsString($this->userPath, $caught->getMessage(), 'the failure sentence names the file at fault — both tiers are diagnosable');
        self::assertStringContainsString('is not valid JSON', $caught->getMessage());

        file_put_contents($this->userPath, json_encode(['servers' => []], JSON_THROW_ON_ERROR));
        $caught = null;
        try {
            $this->loadConfig(new McpClient($this->projectPath, userConfigPath: $this->userPath));
        } catch (\RuntimeException $e) {
            $caught = $e;
        }
        self::assertNotNull($caught, 'a user file without mcpServers must throw');
        self::assertStringContainsString($this->userPath . ' has no "mcpServers" object', $caught->getMessage());
    }

    public function testABrokenProjectAbortsBeforeAnyUserDiagnostic(): void
    {
        // Tier order decides which error you get: the project file is read
        // first, and its throw preempts the user file's own brokenness. The
        // message naming the PROJECT is the observable, and the honest one —
        // fixing the user file under a broken project config changes nothing
        // until the project parses.
        file_put_contents($this->projectPath, '{project broken');
        file_put_contents($this->userPath, '{user broken');

        $caught = null;
        try {
            $this->loadConfig(new McpClient($this->projectPath, userConfigPath: $this->userPath));
        } catch (\RuntimeException $e) {
            $caught = $e;
        }
        self::assertNotNull($caught, 'a malformed project file must throw');
        self::assertStringContainsString($this->projectPath, $caught->getMessage());
        self::assertStringNotContainsString($this->userPath, $caught->getMessage());
    }

    /** @return list<array{path: string, servers: array<mixed>}> */
    private function loadConfig(McpClient $client): array
    {
        $method = new \ReflectionMethod(McpClient::class, 'loadConfig');

        /** @var list<array{path: string, servers: array<mixed>}> $rows */
        $rows = $method->invoke($client);

        return $rows;
    }

    /** @param array<string, mixed> $servers */
    private function writeConfig(string $path, array $servers): void
    {
        file_put_contents($path, json_encode(['mcpServers' => $servers], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }
}
