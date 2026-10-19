<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Context\ContextBreakdown;
use SugarCraft\Code\Context\ProjectMemoryWriter;
use SugarCraft\Code\Host\ContextSources;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Message;
use SugarCraft\Code\Memory\MemoryStore;
use SugarCraft\Code\Protocol\Methods\MemoryMethods;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * WEB-3a: `context.sources` answers the `/context` surface as structure —
 * one measured breakdown (VIS-2a's ContextBreakdown, no forked estimation),
 * the loader's own instruction-file record, both memory stores under the
 * `memory.list` grouping rule, the skills inventory, and the read-never-run
 * MCP census with its per-entry details (URLs) dropped.
 */
final class ContextSourcesTest extends TestCase
{
    use HomeSandboxTrait;

    private ProtocolFixture $fixture;

    private string $homeRoot = '';

    protected function setUp(): void
    {
        $this->homeRoot = \sys_get_temp_dir() . '/w4h-home-' . \uniqid('', true);
        $this->useHomeSandbox($this->homeRoot);
        \mkdir($this->homeRoot . '/.candy-code', 0o700, true);

        $storeDir = $this->homeRoot . '/memory';
        \mkdir($storeDir, 0o700, true);
        $this->fixture = ProtocolFixture::new(memoryStore: new MemoryStore($storeDir));
    }

    protected function tearDown(): void
    {
        $this->fixture->tearDown();
        $this->restoreHomeSandbox();
        $this->purgeTree($this->homeRoot);
    }

    // ── the wire surface ───────────────────────────────────────────────

    public function testTheSessionAnswerCarriesEverySectionAndTheMeasuredBreakdown(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        file_put_contents($this->fixture->root . '/CLAUDE.md', "# Repo rules\n\nUse tabs.\n");

        $answer = $client->call('context.sources', ['sessionId' => $sessionId]);

        self::assertSame(
            ['breakdown', 'instructionFiles', 'mcp', 'memorySections', 'skills', 'trust', 'untrusted'],
            (static function (array $a): array {
                ksort($a);

                return array_keys($a);
            })($answer),
        );
        $paths = array_column($answer['instructionFiles'] ?? [], 'path');
        self::assertContains($this->fixture->root . '/CLAUDE.md', $paths);
        self::assertIsArray($answer['breakdown']);
        self::assertSame(
            ['cacheBreaks', 'historyMessages', 'historyTokens', 'largest', 'lastCache', 'pruning', 'sections', 'sessionCache', 'toolCount', 'toolTokens', 'uiOnlyRows', 'window'],
            (static function (array $b): array {
                ksort($b);

                return array_keys($b);
            })($answer['breakdown']),
            'the breakdown serializes exactly its schema fields — instructionFiles rides top-level, once',
        );
    }

    public function testAnUnknownSessionIsRefusedNotGuessed(): void
    {
        $response = $this->fixture->client()->request('context.sources', ['sessionId' => 'no-such-session']);

        self::assertArrayNotHasKey('result', $response);
        self::assertSame('session_not_found', $response['error']['data']['kind'] ?? $response['error']['message']);
    }

    public function testMemorySectionsSpanBothStoresUnderTheMemoryListRule(): void
    {
        $shared = ProjectMemoryWriter::createForRoot($this->fixture->root);
        self::assertNotNull($shared);
        $shared->write('repo-wide note');
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $client->call('memory.add', ['content' => 'home note', 'scope' => 'user']);

        $items = $client->call('context.sources', ['sessionId' => $sessionId])['memorySections'];

        $contents = array_column($items, 'content');
        self::assertContains('repo-wide note', $contents);
        self::assertContains('home note', $contents);
        self::assertSame(MemoryMethods::SOURCE_REPO, $items[0]['source'], 'repo rows lead, exactly as memory.list groups them');
    }

    // ── the builder, pinned through its public API ─────────────────────

    public function testTheAnswerBadgesTiersAndRefusalsAndDropsNothingElse(): void
    {
        $answer = ContextSources::answer(
            workspace: null,
            breakdown: null,
            instructionFiles: [
                'files' => [
                    ['path' => '/repo/CLAUDE.md', 'bytes' => 2048, 'personal' => false],
                    ['path' => '/home/op/.candy-code/AGENTS.md', 'bytes' => 512, 'personal' => true],
                ],
                'refused' => [['path' => '/repo/imported.md', 'reason' => 'over the size ceiling']],
            ],
            mcpInventory: ['status' => 'trusted', 'path' => '/repo/.mcp.json', 'servers' => [], 'skipped' => [], 'error' => null],
        );

        self::assertSame([
            ['path' => '/repo/CLAUDE.md', 'bytes' => 2048, 'tier' => 'project'],
            ['path' => '/home/op/.candy-code/AGENTS.md', 'bytes' => 512, 'tier' => 'personal'],
        ], $answer['instructionFiles']);
        self::assertSame([['path' => '/repo/imported.md', 'reason' => 'over the size ceiling']], $answer['untrusted']);
        self::assertNull($answer['breakdown'], 'no session on screen: every session figure is honestly absent');
        self::assertSame([], $answer['memorySections']);
        self::assertSame([], $answer['skills']);
    }

    public function testTheMcpRowsKeepNameAndTransportAndNeverTheDetail(): void
    {
        // An http entry's detail IS its URL; server.hello publishes at most a
        // sha256 prefix of such hosts, and context.sources keeps the same
        // restraint — name and transport only.
        $answer = ContextSources::answer(
            workspace: null,
            breakdown: null,
            instructionFiles: null,
            mcpInventory: [
                'status' => 'trusted',
                'path' => '/repo/.mcp.json',
                'servers' => [
                    ['name' => 'api', 'type' => 'http', 'detail' => 'https://secret.example.internal/mcp'],
                    ['name' => 'fs', 'type' => 'stdio', 'detail' => 'npx fs-server'],
                ],
                'skipped' => ['bad (entry is string, not a named server object)'],
                'error' => null,
            ],
        );

        $json = json_encode($answer['mcp'], \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('secret.example.internal', $json);
        self::assertStringNotContainsString('npx fs-server', $json);
        self::assertSame([
            ['name' => 'api', 'transport' => 'http', 'trust' => 'trusted'],
            ['name' => 'fs', 'transport' => 'stdio', 'trust' => 'trusted'],
        ], $answer['mcp']['servers']);
        self::assertSame('trusted', $answer['mcp']['status']);
        self::assertNull($answer['instructionFiles'], 'an unpriced loader record stays null — the panel says "not measured", never "none"');
    }

    public function testTheBreakdownSerializationCarriesPruningAndBreaksUntouched(): void
    {
        $history = [Message::user('hi')];
        $breakdown = ContextBreakdown::measure($history, null, null, 8192, 12)
            ->withCacheBreaks(2, ['from' => 95, 'to' => 5]);

        $answer = ContextSources::answer(
            workspace: WorkspaceContext::new(root: $this->fixture->root, sessionStore: $this->fixture->store),
            breakdown: $breakdown,
            instructionFiles: null,
            mcpInventory: ['status' => 'untrusted', 'path' => '/repo/.mcp.json', 'servers' => [], 'skipped' => [], 'error' => null],
        );

        self::assertSame(['breaks' => 2, 'last' => ['from' => 95, 'to' => 5]], $answer['breakdown']['cacheBreaks']);
        self::assertSame(8192, $answer['breakdown']['window']);
        self::assertSame('untrusted', $answer['mcp']['status']);
        self::assertSame([], $answer['mcp']['servers']);
    }

    public function testTheSingleWriterRuleHoldsMeasureLivesOnlyInTheProvider(): void
    {
        // WEB-3a's law: the panel and the wire share ONE measurement. The
        // breakdown call may be constructed in exactly one production place —
        // ContextSources::measure — never forked back into the host command.
        $provider = (string) \file_get_contents(\dirname(__DIR__, 2) . '/src/Host/ContextSources.php');
        $command = (string) \file_get_contents(\dirname(__DIR__, 2) . '/src/Host/Commands/ContextHostCommand.php');
        $session = (string) \file_get_contents(\dirname(__DIR__, 2) . '/src/Host/SessionHost.php');

        self::assertSame(1, \substr_count($provider, 'ContextBreakdown::measure('));
        self::assertSame(0, \substr_count($command, 'ContextBreakdown::measure('), 'the host command must delegate, not re-measure');
        self::assertSame(0, \substr_count($session, 'ContextBreakdown::measure('), 'the session provider must delegate, not re-measure');
        self::assertStringContainsString('ContextSources::measure(', $command);
        self::assertStringContainsString('ContextSources::measure(', $session);
    }

    private function purgeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path) || (is_dir($path) && !is_link($path))) {
                $this->purgeTree($path);
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
