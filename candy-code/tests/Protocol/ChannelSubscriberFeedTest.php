<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Bundles\Bundle;
use SugarCraft\Code\Channels\ChannelRegistry;
use SugarCraft\Code\Channels\ChannelsStore;
use SugarCraft\Code\Host\SessionEvent;
use SugarCraft\Code\Host\SessionHub;
use SugarCraft\Code\Host\SessionHost;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Protocol\ServerContext;
use SugarCraft\Code\Protocol\SessionFeed;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Tests\Skills\TemporaryDirectoryTrait;

/**
 * GAP-3 seam pins (code-chat P1 step 5/8): the attachFeed closure's third call
 * hands every SessionEvent envelope to the booted channel registry — beside,
 * never instead of, the feed's own hearing lane. A workspace without a
 * ChannelRegistry service is a dark no-op; a subscriber that throws cannot
 * break the announce, and a disposed registry stops the lane while the feed
 * itself stays alive.
 *
 * Helper spellings here deliberately differ from ChannelRegistryTest: the
 * drift guard must keep two honest builders, not one shared crutch.
 */
final class ChannelSubscriberFeedTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private string $sandbox = '';
    private string $project = '';
    private string $configDir = '';
    private int $serial = 0;
    private static int $classSeq = 0;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/p1f-channel-feed-' . bin2hex(random_bytes(6));
        $this->project = $this->sandbox . '/project';
        mkdir($this->project, 0700, true);
        $this->configDir = $this->sandbox . '/cfg';
        mkdir($this->configDir, 0700, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox));
        }
    }

    public function testABeatReachesTheSubscriberThroughTheFeedSeamWithItsEnvelopeIntact(): void
    {
        $sink = $this->sinkFile();
        [$feed, $host] = $this->stageWith($sink, false);
        $this->beat($host, SessionEvent::MESSAGE_CREATED, ['text' => 'hello from the feed']);
        $rows = $this->sinkRows($sink);
        self::assertCount(1, $rows, 'the beat arrives once through the seam');
        $envelope = json_decode($rows[0], true);
        self::assertSame('s', $envelope['sessionId'], 'the serving session identity rides the envelope');
        self::assertSame(SessionEvent::MESSAGE_CREATED, $envelope['type']);
        self::assertSame('hello from the feed', $envelope['data']['text']);
    }

    public function testTheSubscriberLaneRunsBesideTheFeedAndNeverStarvesIt(): void
    {
        $sink = $this->sinkFile();
        [$feed, $host] = $this->stageWith($sink, false);
        $this->beat($host, SessionEvent::SUBAGENT_STARTED, ['id' => 'r1', 'task' => 'first']);
        self::assertCount(1, $feed->subagents(), 'the feed lane keeps hearing while a subscriber is wired');
        self::assertCount(1, $this->sinkRows($sink), 'the same beat is handed to the subscriber lane too');
    }

    public function testAWorkspaceWithoutAChannelServiceIsADarkNoOpForTheSeam(): void
    {
        $store = new EnhancedSessionStore($this->sandbox . '/session.db');
        $store->createSession('s', 'p', 'm');
        $workspace = WorkspaceContext::new(root: $this->project, sessionStore: $store, backend: new EchoBackend());
        $hub = SessionHub::new($workspace);
        $host = $hub->open('s');
        $context = ServerContext::new($hub, ServerConfig::new($this->sandbox)->withRoot($this->project), 'test', Loop::get());
        $feed = $context->feedFor($host);
        $this->beat($host, SessionEvent::SUBAGENT_STARTED, ['id' => 'r1', 'task' => 'first']);
        self::assertCount(1, $feed->subagents(), 'no registry service means no subscriber lane, not a broken feed');
    }

    public function testABlownSubscriberCannotBreakTheAnnounceNorTheFeed(): void
    {
        $sink = $this->sinkFile();
        [$feed, $host] = $this->stageWith($sink, true);
        $this->beat($host, SessionEvent::SUBAGENT_STARTED, ['id' => 'r1', 'task' => 'first']);
        self::assertCount(1, $feed->subagents(), 'the beat survives a subscriber that throws');
        self::assertSame('', trim((string) @file_get_contents($sink)), 'the blown subscriber wrote nothing');
    }

    public function testTheSeamGoesDarkAfterDisposeAllWhileTheFeedStaysAlive(): void
    {
        $sink = $this->sinkFile();
        $registry = ChannelRegistry::new();
        [$feed, $host] = $this->stageWith($sink, false, $registry);
        $this->beat($host, SessionEvent::MESSAGE_CREATED, ['text' => 'first']);
        $registry->disposeAll(static function (string $line): void {});
        $this->beat($host, SessionEvent::SUBAGENT_STARTED, ['id' => 'r1', 'task' => 'after']);
        self::assertCount(1, $this->sinkRows($sink), 'a disposed registry delivers nothing more');
        self::assertCount(1, $feed->subagents(), 'the feed itself never noticed the teardown');
    }

    /**
     * @return array{0: SessionFeed, 1: SessionHost}
     */
    private function stageWith(string $sink, bool $blows, ?ChannelRegistry $registry = null): array
    {
        [$bundle, $bundleName, $entryPath] = $this->channelBundle($sink, $blows);
        $registry ??= ChannelRegistry::new();
        $store = ChannelsStore::create(fn (): string => $this->configDir);
        $store->recordEntrypoint($entryPath);
        $registry->boot([$bundle], [$bundleName], $store, $this->project, Loop::get(), static function (string $line): void {});

        $db = new EnhancedSessionStore($this->sandbox . '/session-' . (++$this->serial) . '.db');
        $db->createSession('s', 'p', 'm');
        $workspace = WorkspaceContext::new(root: $this->project, sessionStore: $db, backend: new EchoBackend())
            ->withService(ChannelRegistry::class, $registry);
        $hub = SessionHub::new($workspace);
        $host = $hub->open('s');
        $context = ServerContext::new($hub, ServerConfig::new($this->sandbox)->withRoot($this->project), 'test', Loop::get());

        return [$context->feedFor($host), $host];
    }

    /**
     * @return array{0: Bundle, 1: string, 2: string}
     */
    private function channelBundle(string $sink, bool $blows): array
    {
        $dir = $this->sandbox . '/bundles/feed-' . (++$this->serial);
        mkdir($dir . '/.claude-plugin', 0777, true);
        $name = 'cc-p1-feed-' . getmypid() . '-' . $this->serial;
        $class = 'CcFeed' . getmypid() . '_' . (++self::$classSeq);
        $entryPath = $dir . '/gen/sub-' . $class . '.php';
        mkdir($dir . '/gen', 0777, true);
        $throwLine = $blows ? "throw new \\RuntimeException('feed subscriber blown');\n        " : '';
        $code = "<?php\nfinal class {$class} implements \\SugarCraft\\Code\\Channels\\SubscribesToSessionEvents\n{\n"
            . "    public function name(): string\n    {\n        return 'feed-chan';\n    }\n\n"
            . "    public function start(\\SugarCraft\\Code\\Channels\\ChannelContext \$context): void\n    {\n    }\n\n"
            . "    public function dispose(): void\n    {\n    }\n\n"
            . "    public function onEvent(array \$envelope): void\n    {\n        {$throwLine}"
            . "file_put_contents(" . var_export($sink, true) . ", json_encode(\$envelope) . PHP_EOL, FILE_APPEND | LOCK_EX);\n    }\n}\n";
        file_put_contents($entryPath, $code);
        file_put_contents($dir . '/.claude-plugin/plugin.json', json_encode([
            'name' => $name,
            'entrypoints' => ['channel' => [['file' => $entryPath, 'class' => $class]]],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return [Bundle::fromDir($dir, 'project'), $name, $entryPath];
    }

    private function sinkFile(): string
    {
        $sink = $this->sandbox . '/feed-sink-' . (++$this->serial) . '.log';
        touch($sink);

        return $sink;
    }

    /** @return list<string> */
    private function sinkRows(string $sink): array
    {
        return array_values(array_filter(array_map('trim', (array) @file($sink))));
    }

    /** @param array<string, mixed> $data */
    private function beat(SessionHost $host, string $type, array $data): void
    {
        $host->announce(SessionEvent::new($type, $data, 's', null, 1_700_000_000_000));
    }
}
