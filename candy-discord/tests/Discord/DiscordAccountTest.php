<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Tests\Discord;

use CandyCode\Bundles\Discord\Bridge\ConversationRouter;
use CandyCode\Bundles\Discord\Candy\ServeBridge;
use CandyCode\Bundles\Discord\Discord\Buttons;
use CandyCode\Bundles\Discord\Discord\DiscordAccount;
use CandyCode\Bundles\Discord\Discord\Gateway;
use CandyCode\Bundles\Discord\Discord\Rest;
use CandyCode\Bundles\Discord\Support\CredentialStore;
use CandyCode\Bundles\Discord\Support\Log;
use CandyCode\Bundles\Discord\Support\PairingCodes;
use CandyCode\Bundles\Discord\Support\SessionMap;
use CandyCode\Bundles\Discord\Support\TextSafety;
use CandyCode\Bundles\Discord\Tests\Fixture\FakeDiscordApi;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;

/**
 * The account face driven through its private intake with fixture
 * messages (§7.1/§7.3/§7.4/§7.7/§7.8/§7.9/§7.10 surfaces): pairing
 * gates before anything reaches the router, silent drops for bots,
 * webhooks and unallowed guilds, outbound payload shape, approval
 * components, the thread probe, and the /pair text surface. The REST
 * leg rides a real Rest client pointed at a loopback fake; the serve
 * bridge is a placeholder that was never dialled — anything that
 * reaches it fails loudly through the face, which is itself the pin
 * that the gate opened.
 */
final class DiscordAccountTest extends TestCase
{
    private LoopInterface $loop;
    private FakeDiscordApi $api;
    private DiscordAccount $face;
    private CredentialStore $store;
    private PairingCodes $pairing;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->loop = Loop::get();
        $this->tempDir = \sys_get_temp_dir() . '/p2-account-' . \getmypid() . '-' . \uniqid('', true);
        \mkdir($this->tempDir, 0o700, true);
        $this->api = new FakeDiscordApi($this->loop);
        $this->api->listen();
        $this->store = new CredentialStore($this->tempDir . '/channels.json');
        $this->pairing = new PairingCodes($this->store, new TextSafety());
    }

    protected function tearDown(): void
    {
        $this->face?->cancelAllTimers();
        $this->api->stop();
        @\unlink($this->tempDir . '/sessions.db');
        @\rmdir($this->tempDir);
    }

    public function testAnUnpairedDmGetsItsCodeAndNothingReachesTheRouter(): void
    {
        $this->buildFace(['dmPolicy' => 'pairing']);
        $this->intake(self::dmMessage('u1', 'hello there'));
        $this->settle();
        $call = $this->api->lastCallFor('/messages');
        self::assertNotNull($call);
        self::assertStringContainsString('Pairing required.', (string) $call['body']['content']);
        self::assertSame(1, \preg_match('#\b[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{8}\b#', (string) $call['body']['content']));
        self::assertCount(1, $this->pairing->pending('default'));
    }

    public function testAPairedPeerPassesTheGateAndHitsTheServeWall(): void
    {
        $this->store->writeDiscordPairing('default', [], ['u1']);
        $this->buildFace(['dmPolicy' => 'pairing']);
        $this->intake(self::dmMessage('u1', 'hello there'));
        $this->settle(600);
        $call = $this->api->lastCallFor('/messages');
        self::assertNotNull($call);
        self::assertStringContainsString('Could not open a session', (string) $call['body']['content']);
    }

    public function testBotsWebhooksAndUnlistedGuildsAreDroppedSilently(): void
    {
        $this->store->writeDiscordPairing('default', [], ['u1', 'bot2']);
        $this->buildFace(['dmPolicy' => 'pairing']);
        $bot = self::dmMessage('bot2', 'beep');
        $bot['author']['bot'] = true;
        $this->intake($bot);
        $hook = self::dmMessage('u1', 'integration noise');
        $hook['webhook_id'] = 'wh-1';
        $this->intake($hook);
        $this->intake(self::dmMessage('u1', 'hello') + ['guild_id' => 'g9']);
        $this->settle();
        self::assertSame([], $this->api->calls);
    }

    public function testAGuildChannelNeedsItsOwnMention(): void
    {
        $this->buildFace([
            'groupPolicy' => 'allowlist',
            'guilds' => ['g1' => ['policy' => 'open', 'allowChannels' => ['c9']]],
        ]);
        $this->intake(['id' => 'm2', 'channel_id' => 'c9', 'guild_id' => 'g1',
            'author' => ['id' => 'u1'], 'content' => 'unmentioned', 'mentions' => []]);
        $this->settle();
        self::assertSame([], $this->api->calls);
        $this->intake(['id' => 'm3', 'channel_id' => 'c9', 'guild_id' => 'g1',
            'author' => ['id' => 'u1'], 'content' => '<@bot-9> hello', 'mentions' => [['id' => 'bot-9']]]);
        $this->settle(600);
        $call = $this->api->lastCallFor('/messages');
        self::assertNotNull($call);
        self::assertStringContainsString('Could not open a session', (string) $call['body']['content']);
    }

    public function testOutboundCarriesReplyReferenceMuteAndEmbedSuppression(): void
    {
        $this->buildFace(['suppressEmbeds' => true]);
        $this->resolve($this->face->deliver(['channelId' => 'c1', 'messageId' => 'm1'], 'hi'));
        $call = $this->api->lastCallFor('/messages');
        self::assertNotNull($call);
        self::assertSame('hi', $call['body']['content']);
        self::assertSame([], $call['body']['allowed_mentions']['parse']);
        self::assertSame(4, (int) $call['body']['flags'] & 4);
        self::assertSame(['message_id' => 'm1', 'fail_if_not_exists' => false], $call['body']['message_reference']);
        $this->resolve($this->face->deliver(['channelId' => 'c1', 'messageId' => ''], 'again'));
        $second = $this->api->lastCallFor('/messages');
        self::assertArrayNotHasKey('message_reference', $second['body']);
    }

    public function testTheApprovalPayloadIsComponentsV2WithVerdictIdsAndAnApproverRoster(): void
    {
        $this->buildFace(['approvalRoster' => ['boss'], 'approvals' => ['ttlMinutes' => 30]]);
        $this->face->promptApproval(['channelId' => 'c1', 'messageId' => 'm1'], 'sess_1',
            ['askId' => 'ask_1', 'tool' => 'Bash', 'arguments' => ['cmd' => 'ls']]);
        $this->settle();
        $call = $this->api->lastCallFor('/messages');
        self::assertNotNull($call);
        self::assertSame(17, (int) $call['body']['components'][0]['type']);
        self::assertNotSame(0, (int) $call['body']['flags'] & Buttons::FLAG_COMPONENTS_V2);
        $ids = [];
        foreach ($call['body']['components'][0]['components'] as $inner) {
            foreach (($inner['components'] ?? []) as $button) {
                $ids[] = $button['custom_id'];
                self::assertSame([['type' => 1, 'id' => 'boss']], $button['allowed_users']);
            }
        }
        self::assertSame(['ap:ask_1:once', 'ap:ask_1:always', 'ap:ask_1:deny'], $ids);
        $this->face->retireApproval('ask_1', 'Resolved.');
        $this->settle();
        $patch = $this->api->lastCallFor('/channels/c1/messages/msg_out');
        self::assertNotNull($patch);
        self::assertSame('PATCH', $patch['method']);
        self::assertSame('Resolved.', $patch['body']['content']);
        self::assertSame([], $patch['body']['components']);
    }

    public function testTheThreadProbeArchivesOnlyRealThreads(): void
    {
        $this->buildFace([]);
        $seen = new \ReflectionProperty(DiscordAccount::class, 'threadSeen');
        $seen->setAccessible(true);
        $seen->setValue($this->face, ['th1' => \time() - 99999, 'c9' => \time() - 99999]);
        self::assertSame(['th1', 'c9'], $this->face->idleThreads(\time()));
        $this->face->archiveThread('th1');
        $this->settle();
        $patch = $this->api->lastCallFor('/channels/th1');
        self::assertNotNull($patch);
        self::assertSame('PATCH', $patch['method']);
        self::assertTrue($patch['body']['archived']);
        $this->face->archiveThread('c9');
        $this->settle();
        foreach ($this->api->calls as $call) {
            if ($call['method'] === 'PATCH') {
                self::assertStringNotContainsString('/channels/c9', $call['path']);
            }
        }
        self::assertSame([], $this->face->idleThreads(\time()));
    }

    public function testPairApproveAndListSpeakThroughTheChannelSurface(): void
    {
        $this->buildFace(['dmPolicy' => 'pairing']);
        $mint = $this->pairing->request('default', 'u2');
        $code = (string) $mint['code'];
        $this->face->pairCommand(['channelId' => 'c1', 'messageId' => ''], ['approve', $code]);
        $this->settle();
        self::assertStringContainsString('Paired u2.', (string) $this->api->lastCallFor('/messages')['body']['content']);
        $this->face->pairCommand(['channelId' => 'c1', 'messageId' => ''], ['list']);
        $this->settle();
        self::assertStringContainsString('No pending pairings.', (string) $this->api->lastCallFor('/messages')['body']['content']);
    }

    public function testAnOversizedAttachmentIsDisclosedByNameNeverFetched(): void
    {
        $this->store->writeDiscordPairing('default', [], ['u1']);
        $this->buildFace(['media' => ['maxMb' => 1, 'images' => 4, 'documents' => 2]]);
        $gather = new \ReflectionMethod(DiscordAccount::class, 'collectMediaAndDispatch');
        $gather->setAccessible(true);
        $gather->invoke($this->face, 'discord:default:dm:u1', ['channelId' => 'c1', 'messageId' => 'm1'],
            'look', [['id' => 'a1', 'filename' => 'big.bin', 'content_type' => 'image/png', 'size' => 999999999]],
            'look', false, 'm1');
        $this->settle(600);
        $notice = null;
        foreach ($this->api->calls as $call) {
            if (\str_contains((string) ($call['body']['content'] ?? ''), 'Skipped 1 attachment(s)')) {
                $notice = $call;
            }
        }
        self::assertNotNull($notice, 'the disclosed-drop notice never landed');
        self::assertStringContainsString('big.bin', (string) $notice['body']['content']);
    }

    private function buildFace(array $cfg): void
    {
        $rest = new Rest('tok', Log::silent(), 'http://127.0.0.1:' . $this->apiPort());
        $gateway = new Gateway('tok', Log::silent(), $this->loop, 'ws://127.0.0.1:1/never');
        $router = new ConversationRouter(
            new ServeBridge('ws://127.0.0.1:1/never', '', 'subprotocol', Log::silent(), $this->loop),
            new SessionMap($this->tempDir . '/sessions.db'),
            Log::silent(),
            new TextSafety(),
            $this->loop,
        );
        $this->face = new DiscordAccount('default', $rest, $gateway, $router, $cfg, Log::silent(),
            $this->pairing, new TextSafety(), $this->loop);
        $own = new \ReflectionProperty(DiscordAccount::class, 'ownUserId');
        $own->setAccessible(true);
        $own->setValue($this->face, 'bot-9');
    }

    private function apiPort(): int
    {
        $port = new \ReflectionProperty(FakeDiscordApi::class, 'server');
        $port->setAccessible(true);
        $parts = \parse_url((string) $port->getValue($this->api)?->getAddress());

        return (int) ($parts['port'] ?? 0);
    }

    private function intake(array $message): void
    {
        $on = new \ReflectionMethod(DiscordAccount::class, 'onMessage');
        $on->setAccessible(true);
        $on->invoke($this->face, $message);
    }

    private static function dmMessage(string $authorId, string $content): array
    {
        return ['id' => 'm' . \substr(\md5($authorId . $content), 0, 6), 'channel_id' => 'c1',
            'author' => ['id' => $authorId], 'content' => $content, 'mentions' => []];
    }

    private function resolve(mixed $promise): void
    {
        $settled = false;
        \React\Promise\unwrap($promise instanceof \React\Promise\PromiseInterface ? $promise : \React\Promise\resolve(), 1);
        $settled = true;
        self::assertTrue($settled);
    }

    private function settle(int $ms = 300): void
    {
        $this->loop->addTimer($ms / 1000.0, function (): void {
            $this->loop->stop();
        });
        $this->loop->run();
    }
}
