<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Session\Import;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Session\Import\ImportFormat;
use SugarCraft\Code\Session\Import\ImportRefusedException;
use SugarCraft\Code\Session\Import\ImportedTranscript;
use SugarCraft\Code\Session\Import\SessionImporter;
use SugarCraft\Code\Session\SessionQuery;

/**
 * C-SES-7.a — the importer end-to-end against a real (temporary) session
 * store: an imported transcript becomes a MAIN `import:<format>` session
 * whose body search finds it with no rebuild, its first row is the UI-only
 * untrusted-material notice, it carries no usage rows, a re-import is a
 * no-op, a grown source appends only the delta, and the bounds refuse
 * typed. Fixture shapes are the ones pinned in TranscriptReadersTest's
 * docblocks (Claude Code project JSONL as of 2.1.x, Codex CLI 0.46 rollout).
 *
 * @see SessionImporter
 */
final class SessionImporterTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/crush_imp7a_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0700, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (glob($this->tempDir . '/*') ?: [] as $leftover) {
            @unlink($leftover);
        }
        @rmdir($this->tempDir);
    }

    private function importerStore(): EnhancedSessionStore
    {
        return new EnhancedSessionStore($this->tempDir . '/sessions.db');
    }

    /** One Claude Code JSONL transcript, three human-visible rows. */
    private function importerClaudeSource(string $marker): string
    {
        $rows = [
            ['type' => 'user', 'message' => ['role' => 'user', 'content' => 'please plan the ' . $marker . ' migration']],
            ['type' => 'assistant', 'message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'the ' . $marker . ' plan is two phases']]]],
            ['type' => 'assistant', 'message' => ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'name' => 'Bash', 'input' => ['command' => 'ls'], 'id' => 't1']]]],
        ];
        $lines = [];
            foreach ($rows as $row) {
                $lines[] = json_encode($row + ['timestamp' => '2025-10-01T12:00:00.000Z'], JSON_THROW_ON_ERROR);
            }

            return implode("\n", $lines);
    }

    public function testAnImportedTranscriptBecomesABodySearchableMainSession(): void
    {
        $store = $this->importerStore();

        $result = SessionImporter::new()->importString(
            $store,
            $this->importerClaudeSource('numbat'),
            null,
            null,
            'src-numbat',
        );

        self::assertSame(ImportFormat::ClaudeCode, $result->format);
        $meta = $store->session($result->sessionId);
        self::assertNotNull($meta);
        self::assertSame('import:claude-code', $meta['provider']);
        self::assertSame('main', $meta['kind']);

        $hits = $store->listSessionsFiltered((SessionQuery::new())->withBodySearch('numbat'));
        self::assertSame([$result->sessionId], array_map(static fn($row): string => $row->id, $hits));

        $transcript = $store->loadTranscript($result->sessionId);
        self::assertNotNull($transcript);
        self::assertCount(4, $transcript);
        self::assertTrue($transcript[0]['uiOnly']);
        self::assertStringContainsString('Claude Code', (string) $transcript[0]['content']);
        self::assertStringContainsString('untrusted', (string) $transcript[0]['content']);
    }

    public function testTheImportCarriesNoUsageRowsSoTheSessionReadsUnpriced(): void
    {
        $store = $this->importerStore();

        $result = SessionImporter::new()->importString($store, $this->importerClaudeSource('kelp'), null, null, 'src-kelp');

        self::assertNull($store->usageTotals($result->sessionId));
    }

    public function testReimportingTheSameBytesWritesNothingAndReportsZero(): void
    {
        $store = $this->importerStore();
        $importer = SessionImporter::new();
        $source = $this->importerClaudeSource('tundra');

        $first = $importer->importString($store, $source, null, null, 'src-tundra');
        $second = $importer->importString($store, $source, null, null, 'src-tundra');

        self::assertSame($first->sessionId, $second->sessionId);
        self::assertSame(0, $second->appended);
        self::assertSame($first->digest, $second->digest);
        self::assertCount(4, $store->loadTranscript($second->sessionId));
    }

    public function testAGrownSourceAppendsOnlyTheDeltaRows(): void
    {
        $store = $this->importerStore();
        $importer = SessionImporter::new();
        $source = $this->importerClaudeSource('prairie');

        $first = $importer->importString($store, $source, null, null, 'src-prairie');

        $grown = $source . "\n" . json_encode([
            'type' => 'user',
            'timestamp' => '2025-10-01T13:00:00.000Z',
            'message' => ['role' => 'user', 'content' => 'and the wagons tail'],
        ], JSON_THROW_ON_ERROR);
        $second = $importer->importString($store, $grown, null, null, 'src-prairie');

        self::assertSame($first->sessionId, $second->sessionId);
        self::assertSame(1, $second->appended);
        self::assertSame(4, $second->items);
        $transcript = $store->loadTranscript($second->sessionId);
        self::assertNotNull($transcript);
        self::assertCount(5, $transcript);
        self::assertSame('and the wagons tail', $transcript[4]['content']);
    }

    public function testASourceThatShrankBetweenImportsIsRefusedTyped(): void
    {
        $store = $this->importerStore();
        $importer = SessionImporter::new();

        $importer->importString($store, $this->importerClaudeSource('harbor'), null, null, 'src-harbor');

        $shrunken = json_encode([
            'type' => 'user',
            'timestamp' => '2025-10-01T12:00:00.000Z',
            'message' => ['role' => 'user', 'content' => 'just one row now'],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(ImportRefusedException::class);
        $importer->importString($store, $shrunken, null, null, 'src-harbor');
    }

    public function testBothBoundsRefuseTypedBeforeAnythingIsPersisted(): void
    {
        $store = $this->importerStore();
        $source = $this->importerClaudeSource('mesa');

        try {
            SessionImporter::new(maxItems: 2)->importString($store, $source, null, null, 'src-mesa');
            self::fail('expected the item bound to refuse');
        } catch (ImportRefusedException $e) {
            self::assertStringContainsString('2', $e->getMessage());
        }

        try {
            SessionImporter::new(maxBytes: 10)->importString($store, $source, null, null, 'src-mesa');
            self::fail('expected the byte bound to refuse');
        } catch (ImportRefusedException $e) {
            self::assertStringContainsString('10', $e->getMessage());
        }

        self::assertSame([], $store->listSessionsFiltered((SessionQuery::new())->withBodySearch('mesa')));
    }

    public function testImportFileReadsTheNamedTranscriptFromDisk(): void
    {
        $store = $this->importerStore();
        $path = $this->tempDir . '/rollout-2025-10-01-codex.jsonl';
        file_put_contents($path, implode("\n", [
            json_encode(['type' => 'session_meta', 'payload' => ['id' => 's1', 'cwd' => '/tmp', 'cli_version' => '0.46.0']]),
            json_encode(['type' => 'response_item', 'timestamp' => '2025-10-01T12:00:00.000Z', 'payload' => ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'codex says canyon']]]]),
        ]));

        $result = SessionImporter::new()->importFile($store, $path);

        self::assertSame(ImportFormat::Codex, $result->format);
        self::assertSame('import:codex', $store->session($result->sessionId)['provider']);
        self::assertNotEmpty(array_map(
            static fn($row): string => $row->id,
            $store->listSessionsFiltered((SessionQuery::new())->withBodySearch('canyon')),
        ));
    }

    public function testAPathThatIsNotAReadableFileIsRefusedTyped(): void
    {
        $this->expectException(ImportRefusedException::class);
        SessionImporter::new()->importFile($this->importerStore(), $this->tempDir . '/ghost.jsonl');
    }

    public function testShapesNoReaderRecognisesAreRefusedNotGuessed(): void
    {
        $store = $this->importerStore();

        foreach (['hello world', '[not json', '{"unrelated": 1}', '{"type": "mystery", "message": {}}'] as $raw) {
            try {
                SessionImporter::new()->importString($store, $raw, null, null, 'src-' . md5($raw));
                self::fail('expected a refusal for ' . $raw);
            } catch (ImportRefusedException $e) {
                self::assertStringContainsString('import', $e->getMessage());
            }
        }
    }

    public function testTheOpenCodeExportTitleNamesTheSessionWhenTheCallerDoesNot(): void
    {
        $store = $this->importerStore();
        $raw = json_encode([
            'info' => ['id' => 'ses_imported', 'title' => 'a quarter report', 'time' => ['created' => 1759320000000]],
            'messages' => [[
                'info' => ['role' => 'user', 'time' => ['created' => 1759320000000]],
                'parts' => [['type' => 'text', 'text' => 'the fjord numbers']],
            ]],
        ], JSON_THROW_ON_ERROR);

        $result = SessionImporter::new()->importString($store, $raw, null, null, 'src-opencode');

        self::assertSame(ImportFormat::OpenCode, $result->format);
        self::assertSame('a quarter report', $result->title);
        self::assertSame('a quarter report', $store->session($result->sessionId)['name']);
        self::assertSame('import:opencode', $store->session($result->sessionId)['provider']);
    }

    public function testTheLedgerIsASideTableOnTheSessionDatabasePath(): void
    {
        $store = $this->importerStore();
        $result = SessionImporter::new()->importString($store, $this->importerClaudeSource('atoll'), null, null, 'src-atoll');

        self::assertInstanceOf(ImportedTranscript::class, $result);
        // A second store over the same file sees the same ledger row: the
        // import identity survives the process, exactly like the sessions.
        $reopen = new EnhancedSessionStore($this->tempDir . '/sessions.db');
        $again = SessionImporter::new()->importString($reopen, $this->importerClaudeSource('atoll'), null, null, 'src-atoll');
        self::assertSame($result->sessionId, $again->sessionId);
        self::assertSame(0, $again->appended);
    }
}
