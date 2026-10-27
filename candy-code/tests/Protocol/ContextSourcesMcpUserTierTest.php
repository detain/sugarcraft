<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\McpDecision;
use SugarCraft\Code\Host\ContextSources;

/**
 * C-MCP-4.a: the `context.sources` mcp census reports BOTH tiers, each row
 * badged with the file that declared it, plus the launch memo's liveness —
 * never a probe. The rows come from the REAL {@see McpDecision::mcpServerInventory()}
 * fed temp project and user files, so the answer cannot drift from what
 * `candy-code mcp list` and the launch itself see (MCP-3 precedence mirrored).
 */
final class ContextSourcesMcpUserTierTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/c-mcp4a-' . \uniqid('', true);
        \mkdir($this->dir, 0o700, true);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            @unlink((string) $entry);
        }
        @rmdir($this->dir);
    }

    public function testUserRowsCarryTheirTierAndFollowTheProjectRows(): void
    {
        $answer = ContextSources::answer(
            workspace: null,
            breakdown: null,
            instructionFiles: null,
            mcpInventory: $this->censusFromFiles(
                project: ['solo' => ['type' => 'stdio', 'command' => 's']],
                user: ['extra' => ['type' => 'http', 'url' => 'http://127.0.0.1:9/mcp']],
            ),
        );

        self::assertSame('trusted', $answer['mcp']['status']);
        self::assertSame('trusted', $answer['mcp']['userStatus']);
        self::assertSame([
            ['name' => 'solo', 'transport' => 'stdio', 'trust' => 'trusted', 'tier' => 'project', 'liveness' => null],
            ['name' => 'extra', 'transport' => 'http', 'trust' => 'trusted', 'tier' => 'user', 'liveness' => null],
        ], $answer['mcp']['servers'], 'user rows append after the project rows, badged by tier — the http URL still never travels');
    }

    public function testANameTheProjectDeclaresNeverAppearsAsAUserRow(): void
    {
        $answer = ContextSources::answer(
            workspace: null,
            breakdown: null,
            instructionFiles: null,
            mcpInventory: $this->censusFromFiles(
                project: ['shared' => ['type' => 'stdio', 'command' => 'p']],
                user: ['shared' => ['type' => 'stdio', 'command' => 'u'], 'extra' => ['type' => 'stdio', 'command' => 'e']],
            ),
        );

        self::assertSame(['shared'], array_column(array_filter($answer['mcp']['servers'], static fn (array $r): bool => $r['tier'] === 'project'), 'name'));
        self::assertSame(['extra'], array_column(array_filter($answer['mcp']['servers'], static fn (array $r): bool => $r['tier'] === 'user'), 'name'), 'the census shadows the duplicate exactly like the launch — listing it would promise a server that never starts');
    }

    public function testAnUndecodableUserFileSurfacesItsErrorWithoutSilencingTheProject(): void
    {
        $answer = ContextSources::answer(
            workspace: null,
            breakdown: null,
            instructionFiles: null,
            mcpInventory: $this->censusFromFiles(
                project: ['solo' => ['type' => 'stdio', 'command' => 's']],
                user: null,
                rawUser: '{"mcpServers": ',
            ),
        );

        self::assertSame('trusted', $answer['mcp']['status']);
        self::assertIsString($answer['mcp']['userError']);
        self::assertStringContainsString('is not valid JSON', $answer['mcp']['userError']);
        self::assertSame([], $answer['mcp']['userSkipped']);
        self::assertSame([['name' => 'solo', 'transport' => 'stdio', 'trust' => 'trusted', 'tier' => 'project', 'liveness' => null]], $answer['mcp']['servers']);
    }

    public function testLivenessIsRememberedNeverProbedAndNullWithoutTheMemo(): void
    {
        $inventory = $this->censusFromFiles(
            project: ['fs' => ['type' => 'stdio', 'command' => 'f']],
            user: ['extra' => ['type' => 'stdio', 'command' => 'e']],
        );

        $cold = ContextSources::answer(
            workspace: null,
            breakdown: null,
            instructionFiles: null,
            mcpInventory: $inventory,
            mcpLiveness: null,
        );
        self::assertSame([null, null], array_column($cold['mcp']['servers'], 'liveness'), 'no memoised client: every row answers null, and nothing was started to ask');

        $warm = ContextSources::answer(
            workspace: null,
            breakdown: null,
            instructionFiles: null,
            mcpInventory: $inventory,
            mcpLiveness: ['fs' => ['transport' => 'stdio', 'up' => true, 'tools' => 5]],
        );
        self::assertSame(
            [['up' => true, 'tools' => 5], null],
            array_column($warm['mcp']['servers'], 'liveness'),
            'the snapshot fills the servers it names; a declared-but-never-started server stays null',
        );
    }

    /**
     * The real census over temp files — same entry the TUI serve path and
     * `candy-code mcp list` share, so the answer's rows are the listing's rows.
     *
     * @param array<string, array<string, mixed>>|null $project
     * @param array<string, array<string, mixed>>|null $user
     * @return array<string, mixed>
     */
    private function censusFromFiles(?array $project, ?array $user, ?string $rawUser = null): array
    {
        $projectPath = $this->dir . '/project.json';
        $userPath = $this->dir . '/user.json';
        \file_put_contents(
            $projectPath,
            (string) json_encode(['mcpServers' => $project ?? []], \JSON_THROW_ON_ERROR),
        );
        \file_put_contents($userPath, $rawUser ?? (string) json_encode(['mcpServers' => $user ?? []], \JSON_THROW_ON_ERROR));

        return McpDecision::mcpServerInventory([
            'status' => Bootstrap::MCP_TRUSTED,
            'path' => $projectPath,
            'userStatus' => Bootstrap::MCP_TRUSTED,
            'userPath' => $userPath,
        ]);
    }
}
