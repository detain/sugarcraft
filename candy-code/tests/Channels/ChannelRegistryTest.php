<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Channels;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Http\Message\ServerRequest;
use SugarCraft\Code\Bundles\Bundle;
use SugarCraft\Code\Channels\AcceptsHttpRequests;
use SugarCraft\Code\Channels\ChannelContext;
use SugarCraft\Code\Channels\ChannelInterface;
use SugarCraft\Code\Channels\ChannelRegistry;
use SugarCraft\Code\Channels\ChannelsStore;
use SugarCraft\Code\Channels\SubscribesToSessionEvents;
use SugarCraft\Code\Host\SessionEvent;
use SugarCraft\Code\Tests\Skills\TemporaryDirectoryTrait;

/**
 * Registry pins for the P1 channel surface (code-chat P1 step 4/8): collision
 * refusal both directions (ruling 2c), lifecycle order through boot/disposeAll,
 * the GAP-3 subscriber seam's JSON round-trip law, and the /channels/ route lane.
 *
 * Fixture channel classes are GENERATED into temp dirs and reach the registry
 * through the same loader door as real bundles, so every boot pin also proves
 * the trust gate end-to-end. Helper spellings here deliberately differ from
 * BundleChannelLoaderTest — the drift guard must keep two honest builders.
 *
 * @see \SugarCraft\Code\Channels\ChannelRegistry
 */
final class ChannelRegistryTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private string $scratch = '';

    private int $serial = 0;

    /** @var int process-wide fixture class serial — declared class names survive every test in the run */
    private static int $idSeq = 0;

    /** @var list<string> log lines collected by the closure boot() receives */
    private array $faults = [];

    protected function setUp(): void
    {
        $this->scratch = sys_get_temp_dir() . '/candy-code-registry-test-' . uniqid((string) getmypid(), true);
        mkdir($this->scratch, 0777, true);
        $this->faults = [];
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->scratch);
    }

    // ---- register(): the collision door, both polarities ----

    public function testRegisterAcceptsRouteSlugShapesAndKeepsTheFirstNameOwner(): void
    {
        $registry = ChannelRegistry::new();
        $first = $this->plainChannel('team.alpha-x');
        self::assertTrue($registry->register('bundle-a', $first), 'dots, dashes and underscores are addressable route slugs');
        $second = $this->plainChannel('team.alpha-x');
        $why = null;
        self::assertFalse($registry->register('bundle-b', $second, $why), 'ruling 2c: a name another accepted bundle owns is REFUSED, never overridden');
        self::assertSame('collision', $why);
        self::assertSame($first, $registry->channelNamed('team.alpha-x'), 'the first owner keeps the route');
    }

    public function testRegisterRefusesReservedBundleNamespacesEvenWithAVeryValidChannelName(): void
    {
        $registry = ChannelRegistry::new();
        foreach (ChannelRegistry::RESERVED_BUNDLES as $reserved) {
            $why = null;
            self::assertFalse($registry->register($reserved, $this->plainChannel('legit-name'), $why));
            self::assertSame('reserved-namespace', $why, 'a plugin may never speak for ' . $reserved);
        }
        self::assertNull($registry->channelNamed('legit-name'));
    }

    public function testRegisterRefusesNamesTheRouterCouldNeverAddress(): void
    {
        $registry = ChannelRegistry::new();
        foreach (['', '   ', 'spaced out', 'has/slash', '-leading-dash'] as $unaddressable) {
            $why = null;
            self::assertFalse($registry->register('bundle-a', $this->plainChannel($unaddressable), $why), var_export($unaddressable, true) . ' cannot be a route slug');
            self::assertSame('invalid-name', $why);
        }
    }

    // ---- boot(): lifecycle through the real loader door ----

    public function testAFirstLaunchQuarantinesSoNothingBootsUntilTheSecondRegistry(): void
    {
        [$bundleA, $bundleB, $trusted] = $this->twoBundleStage();
        $journal = $this->journal();
        $this->bakeWorkingChannel($bundleA, 'A-chan', $journal);
        $this->bakeWorkingChannel($bundleB, 'B-chan', $journal);
        [$bundleA, $bundleB] = [$this->refresh($bundleA), $this->refresh($bundleB)];
        $store = $this->freshLedger();
        $first = ChannelRegistry::new();
        $buckets = $first->boot([$bundleA, $bundleB], $trusted, $store, $this->scratch, Loop::get(), $this->collector());
        self::assertCount(2, $buckets['quarantined']);
        self::assertSame([], $first->startedNames(), 'first sight never starts');
        self::assertFileDoesNotExist($journal, 'start() bodies are exactly as dark as their class bytes');
        $second = ChannelRegistry::new();
        $second->boot([$bundleA, $bundleB], $trusted, $store, $this->scratch, Loop::get(), $this->collector());
        self::assertSame(['A-chan', 'B-chan'], $second->startedNames());
        $second->disposeAll($this->collector());
        $lines = array_values(array_filter(array_map('trim', file($journal) ?: [])));
        self::assertSame(['start:A-chan', 'start:B-chan', 'dispose:B-chan', 'dispose:A-chan'], $lines, 'dispose unwinds in reverse start order');
    }

    public function testBootRunsExactlyOncePerRegistry(): void
    {
        $registry = ChannelRegistry::new();
        $registry->boot([], [], $this->freshLedger(), null, Loop::get(), $this->collector());
        $this->expectException(\LogicException::class);
        $registry->boot([], [], $this->freshLedger(), null, Loop::get(), $this->collector());
    }

    public function testAChannelWhoseConstructorThrowsIsRefusedAndLogged(): void
    {
        [$bundleA] = $this->twoBundleStage();
        $this->bakeCtorThrower($bundleA);
        $bundleA = $this->refresh($bundleA);
        $registry = ChannelRegistry::new();
        $buckets = $registry->boot([$bundleA], $this->trustFor('alpha'), $this->seenLedger($bundleA), $this->scratch, Loop::get(), $this->collector());
        self::assertSame('constructor-failed', $buckets['refusals'][0]['reason'] ?? null);
        self::assertSame([], $registry->startedNames());
        self::assertNotSame([], $this->faultLinesCarrying('channel '), 'the ctor fault line reached the log with the class prefix');
    }

    public function testAClassThatIgnoresTheChannelContractIsRefusedNotWrapped(): void
    {
        [$bundleA] = $this->twoBundleStage();
        $this->bakeNonChannelClass($bundleA);
        $bundleA = $this->refresh($bundleA);
        $registry = ChannelRegistry::new();
        $buckets = $registry->boot([$bundleA], $this->trustFor('alpha'), $this->seenLedger($bundleA), $this->scratch, Loop::get(), $this->collector());
        self::assertSame('not-a-channel', $buckets['refusals'][0]['reason'] ?? null);
        self::assertNull($registry->channelNamed('anything-at-all'));
    }

    public function testAChannelWhoseStartThrowsIsUnregisteredAgain(): void
    {
        [$bundleA] = $this->twoBundleStage();
        $this->bakeStartThrower($bundleA);
        $bundleA = $this->refresh($bundleA);
        $registry = ChannelRegistry::new();
        $buckets = $registry->boot([$bundleA], $this->trustFor('alpha'), $this->seenLedger($bundleA), $this->scratch, Loop::get(), $this->collector());
        self::assertSame('start-failed', $buckets['refusals'][0]['reason'] ?? null);
        self::assertNull($registry->channelNamed('failing-start'), 'a half-lived channel must not keep its route');
        self::assertSame([], $registry->startedNames());
    }

    public function testTwoBundlesClaimingOneChannelNameLoseTheSecondToCollision(): void
    {
        [$bundleA, $bundleB, $trusted] = $this->twoBundleStage();
        $journal = $this->journal();
        $this->bakeWorkingChannel($bundleA, 'dupe-name', $journal);
        $this->bakeWorkingChannel($bundleB, 'dupe-name', $journal);
        [$bundleA, $bundleB] = [$this->refresh($bundleA), $this->refresh($bundleB)];
        $registry = ChannelRegistry::new();
        $buckets = $registry->boot([$bundleA, $bundleB], $trusted, $this->seenLedger($bundleA, $bundleB), $this->scratch, Loop::get(), $this->collector());
        self::assertSame('collision', $buckets['refusals'][0]['reason'] ?? null);
        self::assertSame(['dupe-name'], $registry->startedNames(), 'the bundle that got there first keeps the launch');
    }

    // ---- handleEvent(): the GAP-3 seam ----

    public function testASubscriberReceivesTheEnvelopeWithTextVerbatimAndTheFedSessionIdentity(): void
    {
        [$bundleA] = $this->twoBundleStage();
        $sink = $this->sink('events');
        $this->bakeSubscriber($bundleA, 'sub-chan', $sink, false);
        $bundleA = $this->refresh($bundleA);
        $registry = ChannelRegistry::new();
        $registry->boot([$bundleA], $this->trustFor('alpha'), $this->seenLedger($bundleA), $this->scratch, Loop::get(), $this->collector());
        // Field (iv) of the bridge notes: the seam carries prose VERBATIM —
        // bare line-leading '|>' included. PromptFence stays the producer's
        // job; this pin documents the delivered shape, it does not launder it.
        $registry->handleEvent('sess_wired', SessionEvent::new('message.delta', ['text' => "say |> directly"], 'other-session', null, 1), $this->collector());
        $delivered = json_decode(trim((string) file_get_contents($sink)), true);
        self::assertIsArray($delivered);
        self::assertSame('sess_wired', $delivered['sessionId'] ?? null, 'the fed identity wins over the nullable one on the event');
        self::assertSame('say |> directly', $delivered['data']['text'] ?? null);
    }

    public function testAnEnvelopeThatFailsTheJsonRoundTripLawIsNeverDelivered(): void
    {
        [$bundleA] = $this->twoBundleStage();
        $sink = $this->sink('breach');
        $this->bakeSubscriber($bundleA, 'sub-chan', $sink, false);
        $bundleA = $this->refresh($bundleA);
        $registry = ChannelRegistry::new();
        $registry->boot([$bundleA], $this->trustFor('alpha'), $this->seenLedger($bundleA), $this->scratch, Loop::get(), $this->collector());
        $registry->handleEvent('sess_bad', SessionEvent::new('message.delta', ['text' => "\xB1\x31 broken UTF-8"], null, null, 2), $this->collector());
        self::assertSame('', trim((string) file_get_contents($sink)), 'a lossy array would hand the channel a lie about what the session said');
        self::assertNotSame([], $this->faultLinesCarrying('JSON round-trip'));
    }

    public function testASubscriberThatThrowsCannotStarveItsSibling(): void
    {
        [$bundleA, $bundleB, $trusted] = $this->twoBundleStage();
        $this->bakeSubscriber($bundleA, 'sub-noisy', $this->sink('never'), true);
        $goodSink = $this->sink('good');
        $this->bakeSubscriber($bundleB, 'sub-quiet', $goodSink, false);
        [$bundleA, $bundleB] = [$this->refresh($bundleA), $this->refresh($bundleB)];
        $registry = ChannelRegistry::new();
        $registry->boot([$bundleA, $bundleB], $trusted, $this->seenLedger($bundleA, $bundleB), $this->scratch, Loop::get(), $this->collector());
        $registry->handleEvent('sess_pairs', SessionEvent::new('tick', [], null, null, 3), $this->collector());
        self::assertNotSame('', trim((string) file_get_contents($goodSink)), 'the feed outlives one faulty consumer (CWPD-9)');
        self::assertNotSame([], $this->faultLinesCarrying('channel subscriber'));
    }

    public function testTheSubscriberLaneGoesDarkAfterDisposeAll(): void
    {
        [$bundleA] = $this->twoBundleStage();
        $sink = $this->sink('postmortem');
        $this->bakeSubscriber($bundleA, 'sub-chan', $sink, false);
        $bundleA = $this->refresh($bundleA);
        $registry = ChannelRegistry::new();
        $registry->boot([$bundleA], $this->trustFor('alpha'), $this->seenLedger($bundleA), $this->scratch, Loop::get(), $this->collector());
        $registry->disposeAll($this->collector());
        $registry->handleEvent('sess_late', SessionEvent::new('message.delta', ['text' => 'hello'], null, null, 4), $this->collector());
        self::assertSame('', trim((string) file_get_contents($sink)), 'nothing is delivered after the teardown');
    }

    public function testDisposeAllIsIdempotentAndDisposesEachStartedChannelOnce(): void
    {
        [$bundleA, $bundleB, $trusted] = $this->twoBundleStage();
        $journal = $this->journal();
        $this->bakeWorkingChannel($bundleA, 'A-chan', $journal);
        $this->bakeWorkingChannel($bundleB, 'B-chan', $journal);
        [$bundleA, $bundleB] = [$this->refresh($bundleA), $this->refresh($bundleB)];
        $registry = ChannelRegistry::new();
        $registry->boot([$bundleA, $bundleB], $trusted, $this->seenLedger($bundleA, $bundleB), $this->scratch, Loop::get(), $this->collector());
        $registry->disposeAll($this->collector());
        $registry->disposeAll($this->collector());
        $lines = array_values(array_filter(array_map('trim', (array) @file($journal))));
        self::assertSame(['start:A-chan', 'start:B-chan', 'dispose:B-chan', 'dispose:A-chan'], $lines, 'a second teardown is a no-op — nobody is disposed twice and nobody is skipped');
        self::assertSame([], $registry->startedNames(), 'the roster is cleared by the first teardown and stays cleared');
        self::assertSame([], $this->faults, 'a double teardown must not fault on the second pass');
    }

    // ---- httpRouter(): the /channels/ route lane ----

    public function testTheRouteLaneReachesAWebhookChannelAndRefusesEverythingElseInItsOwnVoice(): void
    {
        [$bundleA, $bundleB, $trusted] = $this->twoBundleStage();
        $this->bakeWebhook($bundleA);
        $this->bakeWorkingChannel($bundleB, 'quiet-chan', $this->journal());
        [$bundleA, $bundleB] = [$this->refresh($bundleA), $this->refresh($bundleB)];
        $registry = ChannelRegistry::new();
        $registry->boot([$bundleA, $bundleB], $trusted, $this->seenLedger($bundleA, $bundleB), $this->scratch, Loop::get(), $this->collector());
        $router = $registry->httpRouter();
        $answered = $router($this->channelRequest('/channels/web-chan/inbound'));
        self::assertNotNull($answered, 'the channel that owns the route answers its own webhooks (HookWebhooks doctrine)');
        self::assertSame(200, $answered->getStatusCode());
        self::assertSame('channel-answered', (string) $answered->getBody());
        $notFound = $router($this->channelRequest('/channels/nobody-home'));
        self::assertSame(404, $notFound->getStatusCode());
        self::assertStringContainsString('channel_not_found', (string) $notFound->getBody(), 'unknown names 404 HERE — the SPA must never mask the route');
        $plainChannelUnderPrefix = $router($this->channelRequest('/channels/quiet-chan'));
        self::assertSame(404, $plainChannelUnderPrefix->getStatusCode(), 'a journal-only channel has no HTTP face either');
        $registry->disposeAll($this->collector());
        $stopped = $router($this->channelRequest('/channels/web-chan/inbound'));
        self::assertSame(503, $stopped->getStatusCode());
        self::assertStringContainsString('channels_stopped', (string) $stopped->getBody());
    }

    // ---- fixture builders (registry-domain spellings; kept distinct from the loader test's) ----

    private function collector(): \Closure
    {
        return function (string $line): void {
            $this->faults[] = $line;
        };
    }

    /** @return list<string> */
    private function faultLinesCarrying(string $needle): array
    {
        return array_values(array_filter($this->faults, static fn (string $l): bool => str_contains($l, $needle)));
    }

    /** @return array{0:Bundle,1:Bundle,2:list<string>} */
    private function twoBundleStage(): array
    {
        $bundleA = $this->makeBundleDir('alpha');
        $bundleB = $this->makeBundleDir('beta');
        return [$bundleA, $bundleB, [$bundleA->name, $bundleB->name]];
    }

    /** @return list<string> the minted names for one slug (single-bundle pins) */
    private function trustFor(string $slug): array
    {
        $dir = $this->scratch . '/bundles/' . $slug;
        $manifest = json_decode((string) file_get_contents($dir . '/.claude-plugin/plugin.json'), true);
        return [(string) ($manifest['name'] ?? '')];
    }

    /**
     * Bundle caches its manifest at fromDir(), and the bake* helpers rewrite
     * that manifest afterwards — every boot-path pin must re-derive the
     * object so the loader sees the entrypoints the bake planted.
     */
    private function refresh(Bundle $bundle): Bundle
    {
        $fresh = Bundle::fromDir($bundle->dir, 'project');
        self::assertNotNull($fresh, 'the baked fixture bundle must still parse: ' . $bundle->dir);
        return $fresh;
    }

    private function makeBundleDir(string $slug): Bundle
    {
        $dir = $this->scratch . '/bundles/' . $slug;
        mkdir($dir . '/.claude-plugin', 0777, true);
        $name = 'cc-p1-reg-' . $slug . '-' . getmypid() . '-' . (++$this->serial);
        file_put_contents($dir . '/.claude-plugin/plugin.json', (string) json_encode(['name' => $name, 'version' => '1.0.0']));
        $bundle = Bundle::fromDir($dir, 'project');
        self::assertNotNull($bundle);
        return $bundle;
    }

    private function bakeWorkingChannel(Bundle $bundle, string $routeName, string $journal): void
    {
        $members = '    public function start(\\SugarCraft\\Code\\Channels\\ChannelContext $context): void { file_put_contents($this->journal, "start:" . $this->name() . PHP_EOL, FILE_APPEND); }
    public function dispose(): void { file_put_contents($this->journal, "dispose:" . $this->name() . PHP_EOL, FILE_APPEND); }
';
        $this->plantClass($bundle, $this->classText($bundle, $routeName, 'ChannelInterface', $journal, $members));
    }

    private function bakeSubscriber(Bundle $bundle, string $routeName, string $sinkFile, bool $willThrow): void
    {
        $sink = var_export($sinkFile, true);
        $onEvent = $willThrow
            ? '    public function onEvent(array $envelope): void { throw new \\RuntimeException("subscriber blown"); }'
            : '    public function onEvent(array $envelope): void { file_put_contents(' . $sink . ', json_encode($envelope) . PHP_EOL, FILE_APPEND); }';
        $members = '    public function start(\\SugarCraft\\Code\\Channels\\ChannelContext $context): void {}
    public function dispose(): void {}
' . $onEvent . '
';
        $this->plantClass($bundle, $this->classText($bundle, $routeName, 'SubscribesToSessionEvents', '', $members));
    }

    private function bakeWebhook(Bundle $bundle): void
    {
        $members = '    public function start(\\SugarCraft\\Code\\Channels\\ChannelContext $context): void {}
    public function dispose(): void {}
    public function handleRequest(\\Psr\\Http\\Message\\ServerRequestInterface $request): \\Psr\\Http\\Message\\ResponseInterface { return new \\React\\Http\\Message\\Response(200, [], "channel-answered"); }
';
        $this->plantClass($bundle, $this->classText($bundle, 'web-chan', 'AcceptsHttpRequests', '', $members));
    }

    private function bakeCtorThrower(Bundle $bundle): void
    {
        $members = '    public function __construct() { throw new \\RuntimeException("ctor refused"); }
    public function start(\\SugarCraft\\Code\\Channels\\ChannelContext $context): void {}
    public function dispose(): void {}
';
        $this->plantClass($bundle, $this->classText($bundle, 'boom-ctor', 'ChannelInterface', '', $members));
    }

    private function bakeStartThrower(Bundle $bundle): void
    {
        $members = '    public function start(\\SugarCraft\\Code\\Channels\\ChannelContext $context): void { throw new \\RuntimeException("start refused"); }
    public function dispose(): void {}
';
        $this->plantClass($bundle, $this->classText($bundle, 'failing-start', 'ChannelInterface', '', $members));
    }

    private function bakeNonChannelClass(Bundle $bundle): void
    {
        $id = $this->nextId();
        $src = "class {$id}\n{\n}\n";
        $this->plantClass($bundle, $src, $id);
    }

    private function nextId(): string
    {
        return 'CcReg' . getmypid() . '_' . (++self::$idSeq);
    }

    private function classText(Bundle $bundle, string $routeName, string $shortContract, string $journal, string $members): string
    {
        $id = $this->nextId();
        $journalExport = var_export($journal, true);
        return "class {$id} implements \\SugarCraft\\Code\\Channels\\{$shortContract}\n{\n    private string \$journal = {$journalExport};\n    public function name(): string { return '" . $routeName . "'; }\n" . $members . "}\n";
    }

    /** @param string|null $forcedId when the source was built without classText() */
    private function plantClass(Bundle $bundle, string $classSrc, ?string $forcedId = null): void
    {
        $id = $forcedId ?? $this->classTextIdOf($classSrc);
        $dir = $bundle->dir . '/gen';
        @mkdir($dir, 0777, true);
        $file = $dir . '/chan-' . $id . '.php';
        file_put_contents($file, "<?php\n" . $classSrc);
        $manifestPath = $bundle->dir . '/.claude-plugin/plugin.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $manifest['entrypoints'] = ['channel' => [['file' => $file, 'class' => $id]]];
        file_put_contents($manifestPath, (string) json_encode($manifest));
    }

    private function classTextIdOf(string $classSrc): string
    {
        self::assertSame(1, preg_match('/^class (CcReg\\d+_\\d+)/', $classSrc, $m), 'fixture source must carry its minted id');
        return $m[1];
    }

    private function journal(): string
    {
        return $this->scratch . '/journal-' . (++$this->serial) . '.log';
    }

    private function sink(string $slug): string
    {
        $file = $this->scratch . '/sink-' . $slug . '-' . (++$this->serial);
        touch($file);
        return $file;
    }

    /** A ledger in which these bundles' entrypoints were ALREADY seen (launch two). */
    private function seenLedger(Bundle ...$bundles): ChannelsStore
    {
        $store = $this->freshLedger();
        foreach ($bundles as $bundle) {
            foreach ($bundle->entrypoints()['channel'] ?? [] as $entry) {
                $store->recordEntrypoint($entry['path']);
            }
        }
        return $store;
    }

    private function freshLedger(): ChannelsStore
    {
        $configDir = $this->scratch . '/cfg-' . (++$this->serial);
        mkdir($configDir, 0700, true);
        return ChannelsStore::create(static fn (): string => $configDir);
    }

    private function plainChannel(string $name): ChannelInterface
    {
        return new class($name) implements ChannelInterface {
            public function __construct(private readonly string $label)
            {
            }

            public function name(): string
            {
                return $this->label;
            }

            public function start(ChannelContext $context): void
            {
            }

            public function dispose(): void
            {
            }
        };
    }

    private function channelRequest(string $path): ServerRequest
    {
        return new ServerRequest('POST', 'http://127.0.0.1:7420' . $path);
    }
}
