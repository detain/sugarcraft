<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Channels;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Code\Bundles\Bundle;
use SugarCraft\Code\Channels\ChannelInboundLog;
use SugarCraft\Code\Channels\ChannelRegistry;
use SugarCraft\Code\Channels\ChannelsStore;
use SugarCraft\Code\Tests\Skills\TemporaryDirectoryTrait;

/**
 * §9.5 Track B seats: the registry boot replays the durable inbound ledger
 * through the DispatchesInboundEvents capability, and the serve seat threads
 * the log without disturbing the byte-compat shape when it is absent.
 *
 * The ledger itself is pinned in ChannelInboundLogTest; helper spellings here
 * deliberately differ from ChannelRegistryTest — the drift guard must keep
 * two honest fixture builders, not one shared crutch.
 */
final class ChannelInboundReplayTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private string $inboxScratch = '';
    private string $inboxCfg = '';
    private string $inboxSink = '';
    private string $inboxJournal = '';
    private int $inboxSerial = 0;
    /** @var int process-wide fixture class serial — declared class names survive every test in the run */
    private static int $inboxIdSeq = 0;
    /** @var list<string> */
    private array $inboxFaults = [];

    protected function setUp(): void
    {
        $this->inboxScratch = \sys_get_temp_dir() . '/candy-code-inbox-replay-test-' . \uniqid((string) \getmypid(), true);
        \mkdir($this->inboxScratch, 0777, true);
        $this->inboxCfg = $this->inboxScratch . '/shared-cfg';
        \mkdir($this->inboxCfg, 0700, true);
        $this->inboxFaults = [];
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->inboxScratch);
    }

    public function testPendingRowIsDispatchedOnceAndAckedAcrossReboot(): void
    {
        $bundle = $this->inboxStageDispatch('ok');
        $ledger = $this->inboxLedger();
        self::assertSame('recorded', $ledger->record('inbox-ok-a', 'msg-1', ['text' => 'hello world']));

        $registry = $this->inboxBoot($bundle, $ledger);
        self::assertSame($ledger, $registry->inboundLog());

        $fresh = $this->inboxLedger();
        self::assertSame([], $fresh->pending()['records'], 'a rebooted reader sees the ack, not a pending row');
        self::assertSame('acked', $fresh->rows()['inbox-ok-a|msg-1']['state']);

        $sinkLines = \array_filter(\explode("\n", (string) \file_get_contents($this->inboxSink)));
        self::assertCount(1, $sinkLines, 'the delivery happens exactly once');
        self::assertStringContainsString('hello world', $sinkLines[0]);
        self::assertStringContainsString(
            'channel inbound replay: 1 acked, 0 faulted, 0 left pending',
            \implode("\n", $this->inboxFaults),
        );
    }

    public function testFalseVerdictKeepsTheRowPendingWithOneAttempt(): void
    {
        $bundle = $this->inboxStageDispatch('false');
        $ledger = $this->inboxLedger();
        $ledger->record('inbox-false-a', 'msg-1', ['text' => 'retry me please']);

        $this->inboxBoot($bundle, $ledger);

        $row = $this->inboxLedger()->rows()['inbox-false-a|msg-1'];
        self::assertSame('pending', $row['state']);
        self::assertSame(1, $row['attempts']);
        self::assertStringContainsString(
            'channel inbound replay: 0 acked, 1 faulted, 0 left pending',
            \implode("\n", $this->inboxFaults),
        );
    }

    public function testThrowingChannelNotesFailureAndLogsTheCause(): void
    {
        $bundle = $this->inboxStageDispatch('throw');
        $ledger = $this->inboxLedger();
        $ledger->record('inbox-throw-a', 'msg-1', ['text' => 'poison attempt']);

        $this->inboxBoot($bundle, $ledger);

        self::assertSame(1, $this->inboxLedger()->rows()['inbox-throw-a|msg-1']['attempts']);
        $causes = $this->inboxCarrying('channel inbound replay inbox-throw-a');
        self::assertNotEmpty($causes, 'the faulted delivery leaves a cause line on the server log surface');
    }

    public function testRowForAnUnstartedChannelStaysPending(): void
    {
        $bundle = $this->inboxStageDispatch('ok');
        $ledger = $this->inboxLedger();
        $ledger->record('ghost-channel', 'msg-9', ['text' => 'nobody owns this row']);

        $this->inboxBoot($bundle, $ledger);

        self::assertSame('pending', $this->inboxLedger()->rows()['ghost-channel|msg-9']['state']);
        self::assertStringContainsString(
            'channel inbound replay: 0 acked, 0 faulted, 1 left pending',
            \implode("\n", $this->inboxFaults),
        );
    }

    public function testStartedChannelWithoutTheCapabilityLeavesTheRowPending(): void
    {
        $bundle = $this->inboxStagePlain();
        $ledger = $this->inboxLedger();
        $ledger->record('inbox-plain-b', 'msg-1', ['text' => 'the host cannot speak for it']);

        $this->inboxBoot($bundle, $ledger);

        self::assertSame('pending', $this->inboxLedger()->rows()['inbox-plain-b|msg-1']['state']);
    }

    public function testCorruptLedgerAnnouncesTheResetShape(): void
    {
        $bundle = $this->inboxStageDispatch('ok');
        $writer = $this->inboxLedger();
        \file_put_contents($writer->path(), '{{{ not json at all');

        // A fresh construction is the reboot: the corrupt file quarantines at load.
        $ledger = $this->inboxLedger();
        $this->inboxBoot($bundle, $ledger);

        $announced = $this->inboxCarrying('was unreadable and has been quarantined');
        self::assertNotEmpty($announced, 'RESET rides the announce, never a silent empty replay');
        self::assertSame([], $this->inboxCarrying('channel inbound replay: '), 'an empty replay set never prints a summary');
        self::assertFileExists($writer->path() . '.corral-1700000000');
    }

    public function testBootWithoutTheLedgerKeepsTheByteCompatShape(): void
    {
        $bundle = $this->inboxStageDispatch('ok');

        $registry = $this->inboxBoot($bundle, null);

        self::assertNull($registry->inboundLog());
        self::assertStringContainsString('no-log', (string) \file_get_contents($this->inboxJournal));
    }

    public function testContextCarriesTheLedgerIntoEveryStartedChannel(): void
    {
        $bundle = $this->inboxStageDispatch('false');
        $ledger = $this->inboxLedger();

        $registry = $this->inboxBoot($bundle, $ledger);

        self::assertSame($ledger, $registry->inboundLog());
        self::assertStringContainsString('with-log', (string) \file_get_contents($this->inboxJournal));
    }

    // ------------------------------------------------------------------

    /**
     * Stage one bundle carrying a DispatchesInboundEvents fixture whose
     * baked verdict is 'ok', 'false', or 'throw'.
     */
    private function inboxStageDispatch(string $verdict): Bundle
    {
        $route = 'inbox-' . $verdict . '-a';
        $bundle = $this->inboxMakeBundle($route);
        $this->inboxSink = $this->inboxScratch . '/sink-' . (++$this->inboxSerial);
        $this->inboxJournal = $this->inboxScratch . '/journal-' . (++$this->inboxSerial);

        $body = match ($verdict) {
            'ok' => '        file_put_contents($this->sink, json_encode($payload) . PHP_EOL, FILE_APPEND);' . "\n\n        return true;",
            'false' => '        file_put_contents($this->sink, json_encode($payload) . PHP_EOL, FILE_APPEND);' . "\n\n        return false;",
            default => '        throw new \RuntimeException(\'the channel refused the delivery\');',
        };

        $members = <<<PHP
            public function start(\SugarCraft\Code\Channels\ChannelContext \$context): void
            {
                file_put_contents(\$this->journal, (\$context->inbound instanceof \SugarCraft\Code\Channels\ChannelInboundLog ? 'with-log' : 'no-log') . PHP_EOL, FILE_APPEND);
            }

            public function dispatchInboundEvent(array \$payload): bool
            {
        {$body}
            }

            public function dispose(): void
            {
            }
        PHP;

        $this->inboxPlant($bundle, $route, 'DispatchesInboundEvents', $members, $this->inboxSink, $this->inboxJournal);

        return $this->inboxRefresh($bundle);
    }

    private function inboxStagePlain(): Bundle
    {
        $route = 'inbox-plain-b';
        $bundle = $this->inboxMakeBundle($route);
        $this->inboxJournal = $this->inboxScratch . '/journal-' . (++$this->inboxSerial);

        $members = <<<PHP
            public function start(\SugarCraft\Code\Channels\ChannelContext \$context): void
            {
                file_put_contents(\$this->journal, (\$context->inbound instanceof \SugarCraft\Code\Channels\ChannelInboundLog ? 'with-log' : 'no-log') . PHP_EOL, FILE_APPEND);
            }

            public function dispose(): void
            {
            }
        PHP;

        $this->inboxPlant($bundle, $route, 'ChannelInterface', $members, null, $this->inboxJournal);

        return $this->inboxRefresh($bundle);
    }

    private function inboxBoot(Bundle $bundle, ?ChannelInboundLog $ledger): ChannelRegistry
    {
        $registry = ChannelRegistry::new();
        $registry->boot(
            [$bundle],
            [$bundle->name],
            $this->inboxSeen([$bundle]),
            $this->inboxScratch,
            Loop::get(),
            $this->inboxCollector(),
            $ledger,
        );

        return $registry;
    }

    /** Every call builds a reader over the SAME shared config dir — a fresh instance is a reboot. */
    private function inboxLedger(): ChannelInboundLog
    {
        return ChannelInboundLog::create(
            fn (): string => $this->inboxCfg,
            function (): int {
                return 1700000000;
            },
        );
    }

    /** @param list<Bundle> $bundles */
    private function inboxSeen(array $bundles): ChannelsStore
    {
        $config = $this->inboxScratch . '/seen-' . (++$this->inboxSerial);
        \mkdir($config, 0700, true);
        $store = ChannelsStore::create(fn (): string => $config);
        foreach ($bundles as $bundle) {
            foreach ($bundle->entrypoints()['channel'] ?? [] as $entry) {
                $store->recordEntrypoint($entry['path']);
            }
        }

        return $store;
    }

    private function inboxMakeBundle(string $slug): Bundle
    {
        $dir = $this->inboxScratch . '/bundles/' . $slug;
        \mkdir($dir . '/.claude-plugin', 0777, true);
        $name = 'cc-p4d-inb-' . $slug . '-' . \getmypid() . '-' . (++$this->inboxSerial);
        \file_put_contents($dir . '/.claude-plugin/plugin.json', json_encode(['name' => $name, 'version' => '1.0.0']));

        return Bundle::fromDir($dir, 'project');
    }

    private function inboxRefresh(Bundle $bundle): Bundle
    {
        return Bundle::fromDir($bundle->dir, 'project');
    }

    /**
     * Write the fixture class into the bundle and register it as the single
     * channel entrypoint, mirroring the P1 plant pattern under unique names.
     */
    private function inboxPlant(Bundle $bundle, string $route, string $contract, string $members, ?string $sink, string $journal): void
    {
        $id = 'CcInb' . \getmypid() . '_' . (++self::$inboxIdSeq);
        $dir = $bundle->dir . '/gen';
        \mkdir($dir, 0777, true);
        $file = $dir . '/chan-' . $id . '.php';

        $sinkLine = $sink === null ? '' : "    private string \$sink = '" . $sink . "';\n";

        $source = "<?php\n"
            . "class {$id} implements \\SugarCraft\\Code\\Channels\\{$contract}\n{\n"
            . $sinkLine
            . "    private string \$journal = '" . $journal . "';\n"
            . "    public function name(): string { return '{$route}'; }\n"
            . $members . "\n}\n";
        \file_put_contents($file, $source);

        $manifestPath = $bundle->dir . '/.claude-plugin/plugin.json';
        $manifest = json_decode((string) \file_get_contents($manifestPath), true);
        $manifest['entrypoints'] = ['channel' => [['file' => $file, 'class' => $id]]];
        \file_put_contents($manifestPath, json_encode($manifest));
    }

    private function inboxCollector(): \Closure
    {
        return function (string $line): void {
            $this->inboxFaults[] = $line;
        };
    }

    /** @return list<string> */
    private function inboxCarrying(string $needle): array
    {
        return \array_values(\array_filter(
            $this->inboxFaults,
            static fn (string $line): bool => \str_contains($line, $needle),
        ));
    }
}
