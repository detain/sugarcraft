<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Memory;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Context\Compaction\StateSummaryTemplate;
use SugarCraft\Crush\Context\CompactorConfig;
use SugarCraft\Crush\Context\ContextCompactor;
use SugarCraft\Crush\HistoryCompactedMsg;
use SugarCraft\Crush\Host\CompactionService;
use SugarCraft\Crush\Memory\CompactionJournal;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\Memory\SecretRedactor;
use SugarCraft\Crush\Message;

/**
 * Roadmap 5.4-1: every model-written compaction summary is appended to a
 * tagged, per-project JSONL journal under the home memory directory.
 */
final class CompactionJournalTest extends TestCase
{
    private string $dir;

    private string|false $originalHome;

    private mixed $originalServerHome = null;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-journal-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/home', 0o700, true);
        mkdir($this->dir . '/repo', 0o700, true);
        $this->originalHome = getenv('HOME');
        $this->originalServerHome = $_SERVER['HOME'] ?? null;
        putenv('HOME=' . $this->dir . '/home');
        $_SERVER['HOME'] = $this->dir . '/home';
    }

    protected function tearDown(): void
    {
        $this->originalHome === false ? putenv('HOME') : putenv('HOME=' . $this->originalHome);
        if ($this->originalServerHome === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = $this->originalServerHome;
        }
        exec('rm -rf ' . escapeshellarg($this->dir) . ' 2>&1');
    }

    public function testAnAppendedCompactionReadsBackTaggedWithItsRecordsAndState(): void
    {
        $journal = CompactionJournal::at($this->dir . '/j/journal.jsonl', 'app-0123');

        $this->assertTrue($journal->append('c1', [
            'k1' => 'asked: the router',
            'k2' => 'asked: the cache',
            StateSummaryTemplate::SUMMARY_KEY => "## Goal\nship it",
        ], [CompactionService::TRIGGER_MANUAL]));

        $entries = $journal->entries();
        $this->assertCount(1, $entries);
        $this->assertSame(['compaction', 'manual'], $entries[0]['tags']);
        $this->assertSame('app-0123', $entries[0]['project']);
        $this->assertSame('c1', $entries[0]['compaction']);
        $this->assertSame(['asked: the router', 'asked: the cache'], $entries[0]['records']);
        $this->assertSame("## Goal\nship it", $entries[0]['state']);
        $this->assertMatchesRegularExpression('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\z/', $entries[0]['at']);
        $this->assertSame(0o600, fileperms($journal->path()) & 0o777, 'owner-only, like the rest of the memory directory');
    }

    public function testItIsAppendOnlyAndFiltersByTagAndLimit(): void
    {
        $journal = CompactionJournal::at($this->dir . '/journal.jsonl');
        $journal->append('c1', ['k' => 'one'], ['manual']);
        $journal->append('c2', ['k' => 'two'], ['auto']);
        $journal->append('c3', ['k' => 'three'], ['auto']);

        $this->assertSame(['c1', 'c2', 'c3'], array_column($journal->entries(), 'compaction'));
        $this->assertSame(['c2', 'c3'], array_column($journal->entries('auto'), 'compaction'));
        $this->assertSame(['c3'], array_column($journal->entries(null, 1), 'compaction'));
        $this->assertNull($journal->entries()[0]['project']);
    }

    public function testNothingIsWrittenForAnEmptySummary(): void
    {
        $journal = CompactionJournal::at($this->dir . '/journal.jsonl');

        $this->assertFalse($journal->append('c1', []));
        $this->assertFalse($journal->append('c2', ['k' => '   ']));
        $this->assertFileDoesNotExist($journal->path());
    }

    public function testSecretsAreRedactedBeforeTheyReachTheDisk(): void
    {
        $journal = CompactionJournal::at($this->dir . '/journal.jsonl');
        $journal->append('c1', ['k' => 'used key sk-ant-abcdefghijklmnopqrstuvwxyz0123 to call']);

        $raw = (string) file_get_contents($journal->path());
        $this->assertStringNotContainsString('sk-ant-abcdefghijklmnop', $raw);
        $this->assertStringContainsString(SecretRedactor::MARKER, $journal->entries()[0]['records'][0]);
    }

    public function testALineThatIsNotOursIsSkipped(): void
    {
        $journal = CompactionJournal::at($this->dir . '/journal.jsonl');
        file_put_contents($journal->path(), "not json\n{\"tags\":\"x\"}\n");
        $journal->append('c1', ['k' => 'kept']);

        $this->assertSame(['c1'], array_column($journal->entries(), 'compaction'));
    }

    public function testTheStoreJournalLivesBesideTheHomeStateKeyedByProject(): void
    {
        mkdir($this->dir . '/home/memory', 0o700, true);
        $store = MemoryStore::forProject($this->dir . '/home/memory', $this->dir . '/repo');
        $journal = CompactionJournal::forStore($store);

        $this->assertSame(
            $this->dir . '/home/memory/.compaction-journal-' . $store->projectKey() . '.jsonl',
            $journal->path(),
        );
        $this->assertStringEndsWith('/.compaction-journal-shared.jsonl', CompactionJournal::forStore(new MemoryStore($this->dir . '/home/memory'))->path());
    }

    /** The model route journals its summaries as the promise settles — tagged with the trigger. */
    public function testAModelWrittenSummaryIsJournalledAndARejectedOneIsNot(): void
    {
        $journal = CompactionJournal::at($this->dir . '/journal.jsonl', 'p');
        $service = CompactionService::new()->withJournal($journal);
        $this->assertSame($journal, $service->journal());

        $this->summarise($service, "1.\nasked: recorded one\n2.\nasked: recorded two", null);
        $this->summarise($service, "1.\nasked: parked one", 'parked prompt');
        $this->summarise($service, str_repeat('x', 100_000), null);

        $entries = $journal->entries();
        $this->assertCount(2, $entries, 'the reply that was not smaller than its source is rejected and not journalled');
        $this->assertSame(['compaction', 'manual'], $entries[0]['tags']);
        $this->assertSame(['compaction', 'auto'], $entries[1]['tags']);
        $this->assertStringContainsString('recorded one', $entries[0]['records'][0]);
        $this->assertNotNull($entries[0]['state'], 'the state block rides along');
    }

    /** Reachable from a real launch: the workspace's compaction service carries the journal. */
    public function testTheLaunchRegistersAJournalledCompactionService(): void
    {
        $service = Bootstrap::workspace($this->dir . '/repo')->service(CompactionService::class);

        $this->assertInstanceOf(CompactionService::class, $service);
        $this->assertNotNull($service->journal());
        $this->assertStringStartsWith($this->dir . '/home/.sugar-crush/memory/.compaction-journal-', $service->journal()->path());
    }

    private function summarise(CompactionService $service, string $reply, ?string $parked): void
    {
        $history = [];
        for ($i = 1; $i <= 6; $i++) {
            $history[] = Message::user("question {$i}");
            $history[] = Message::assistant("answer {$i} " . str_repeat('detail ', 60));
        }
        if ($parked !== null) {
            $history[] = Message::user($parked);
        }
        $backend = new class ($reply) implements Backend {
            public function __construct(private readonly string $reply)
            {
            }

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant($this->reply);
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                return \React\Promise\resolve(Message::assistant($this->reply));
            }
        };

        $request = $service->buildSummarizationRequest(
            $backend,
            new ContextCompactor(CompactorConfig::new()->withRecentPreserveCount(2)),
            $history,
            $parked,
        );
        $this->assertNotNull($request);
        $landed = null;
        ($request['promise'])()->then(static function ($msg) use (&$landed): void {
            $landed = $msg;
        });
        $this->assertInstanceOf(HistoryCompactedMsg::class, $landed);
    }
}
