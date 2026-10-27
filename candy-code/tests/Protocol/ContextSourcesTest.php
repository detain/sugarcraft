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
            ['buffer', 'cacheBreaks', 'categories', 'historyMessages', 'historyTokens', 'largest', 'lastCache', 'mcpServers', 'pruning', 'sections', 'sessionCache', 'toolCount', 'toolTokens', 'uiOnlyRows', 'window'],
            (static function (array $b): array {
                ksort($b);

                return array_keys($b);
            })($answer['breakdown']),
            'the breakdown serializes exactly its schema fields — instructionFiles rides top-level, once',
        );
    }

    public function testTheRealSessionCategoriesRideTheEnumVocabularyInOrder(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);

        $breakdown = $client->call('context.sources', ['sessionId' => $sessionId])['breakdown'];

        $cases = \SugarCraft\Code\Context\ContextCategory::cases();
        self::assertCount(count($cases), $breakdown['categories'], 'one row per category, the same order the panel walks');
        foreach ($cases as $i => $case) {
            $row = $breakdown['categories'][$i];
            self::assertSame($case->value, $row['key']);
            self::assertSame($case->label(), $row['label']);
            self::assertTrue($row['tokens'] === null || \is_int($row['tokens']));
            self::assertSame($row['tokens'] === null, $row['share'] === null, 'share is null exactly when tokens is');
            self::assertIsArray($row['sub']);
        }
        // The scripted backend reports no prompt layers, tools, or compaction
        // config: those rows are honestly null, the conversation row is not.
        $byKey = array_column($breakdown['categories'], null, 'key');
        self::assertNull($byKey['system-prompt']['tokens'], 'unmeasured parts say so, never an invented 0');
        self::assertIsInt($byKey['messages']['tokens']);
        self::assertIsInt($byKey['free-space']['tokens']);
        self::assertNull($breakdown['buffer'], 'no compaction config on this backend');
        self::assertNull($breakdown['mcpServers'], 'no tool list to split');
        // The summing law, read off the wire: content + buffer(0 here) + free
        // space is the window, and no measured byte goes unattributed.
        $content = 0;
        foreach ($breakdown['categories'] as $row) {
            if (!in_array($row['key'], ['autocompact-buffer', 'free-space'], true)) {
                $content += $row['tokens'] ?? 0;
            }
        }
        self::assertSame($breakdown['window'] - $byKey['free-space']['tokens'], $content);
    }

    public function testTheCategoriesSerializationPricesServersFilesAndNotes(): void
    {
        // A measured breakdown with everything on it: one prompt layer per
        // billing family, two MCP servers, an instruction document, a message.
        $sections = [
            ['label' => 'base', 'stability' => 'stable', 'sections' => 1, 'bytes' => 100, 'tokens' => 30],
            ['label' => 'project-instructions', 'stability' => 'volatile', 'sections' => 1, 'bytes' => 60, 'tokens' => 20],
            ['label' => 'skills', 'stability' => 'volatile', 'sections' => 1, 'bytes' => 40, 'tokens' => 10],
        ];
        $bridge = static fn (string $server, string $tool): \SugarCraft\Code\Tools\Tool => new class ($server, $tool) implements \SugarCraft\Code\Tools\Tool {
            public function __construct(private string $server, private string $tool)
            {
            }

            public function name(): string
            {
                return \SugarCraft\Code\Tools\McpToolBridge::wireNameFor($this->server, $this->tool);
            }

            public function description(): string
            {
                return 'Bridge ' . $this->tool . ' of ' . $this->server;
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => ['q' => ['type' => 'string']], 'required' => ['q']];
            }

            public function execute(array $args): \SugarCraft\Code\Tools\ToolResult
            {
                throw new \LogicException('measured, never executed');
            }
        };
        $breakdown = ContextBreakdown::measure(
            [Message::user('what is the build status?')],
            $sections,
            [$bridge('alpha', 'search'), $bridge('beta', 'run')],
            1000,
            40,
            instructionFiles: [
                'files' => [['path' => '/repo/CLAUDE.md', 'bytes' => 2048, 'personal' => false]],
                'refused' => [],
            ],
            compactorConfig: \SugarCraft\Code\Context\CompactorConfig::new()->withMode(\SugarCraft\Code\Context\CompactorConfig::MODE_OFF),
        );

        $answer = ContextSources::answer(
            workspace: null,
            breakdown: $breakdown,
            instructionFiles: null,
            mcpInventory: ['status' => 'absent', 'path' => '', 'servers' => [], 'skipped' => [], 'error' => null],
        );

        $byKey = array_column($answer['breakdown']['categories'], null, 'key');
        self::assertSame(30, $byKey['system-prompt']['tokens'], 'the base layer alone bills to the harness voice');
        self::assertSame(3, $byKey['system-prompt']['share'], 'whole percent of the window');
        self::assertSame(20, $byKey['memory-files']['tokens']);
        self::assertSame([['label' => '/repo/CLAUDE.md', 'tokens' => null, 'bytes' => 2048, 'personal' => false, 'note' => null]], $byKey['memory-files']['sub'], 'documents ride bytes, priced by size');
        self::assertSame(10, $byKey['skills']['tokens']);
        self::assertSame(
            ['alpha', 'beta', 'mcp_list/mcp_call', \SugarCraft\Code\Lang::t('cmd.context.category.mcp.instructions')],
            array_column($byKey['mcp-tools']['sub'], 'label'),
            'servers, then the dispatch sub-row (0 here — no dispatchers bound), then the instructions row',
        );
        $instructions = $byKey['mcp-tools']['sub'][3];
        self::assertNull($instructions['tokens']);
        self::assertNotNull($instructions['note'], 'the never-sent instructions row says why there is no figure');
        self::assertSame(40, $byKey['messages']['tokens']);
        self::assertSame('#1 user', $byKey['messages']['sub'][0]['label'], 'the largest-message drill-down rides the wire too');
        self::assertSame(0, $byKey['autocompact-buffer']['tokens'], 'compaction off is a measured 0');
        self::assertSame(0, $answer['breakdown']['buffer'], 'the top-level buffer is the same figure, one call');
        self::assertSame(array_map(
            static fn (array $s): array => ['name' => $s['name'], 'count' => $s['count'], 'tokens' => $s['tokens']],
            $breakdown->mcpServers,
        ), $answer['breakdown']['mcpServers'], 'mcpServers is the breakdown pass-through, name/count/tokens');
        self::assertSame(2, count($answer['breakdown']['mcpServers']));
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
