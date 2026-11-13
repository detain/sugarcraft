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
use CandyCode\Bundles\Discord\Tests\Fixture\FakeMediaCdn;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use SugarCraft\Code\Backend\PendingAsk;

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
    private ?Gateway $faceGateway = null;
    private ?Log $faceLog = null;
    private TextSafety $faceSafety;
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
        $this->faceGateway = null;
        $this->faceLog = null;
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
        $this->face->deliver(['channelId' => 'c1', 'messageId' => 'm1'], 'hi');
        $this->settle();
        $call = $this->api->lastCallFor('/messages');
        self::assertNotNull($call);
        self::assertSame('hi', $call['body']['content']);
        self::assertSame([], $call['body']['allowed_mentions']['parse']);
        self::assertSame(4, (int) $call['body']['flags'] & 4);
        self::assertSame(['message_id' => 'm1', 'fail_if_not_exists' => false], $call['body']['message_reference']);
        $this->face->deliver(['channelId' => 'c1', 'messageId' => ''], 'again');
        $this->settle();
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

    public function testTheApprovalCardRidesTheRedactionChoke(): void
    {
        $this->buildFace(['approvalRoster' => ['boss']]);
        $this->faceSafety->addSecret('s3cr3t-token');
        $this->face->promptApproval(['channelId' => 'c1', 'messageId' => 'm1'], 'sess_1', [
            'askId' => 'ask_r',
            'tool' => 'Bash s3cr3t-token',
            'reason' => 'reading with s3cr3t-token',
            'arguments' => ['cmd' => 'ls -la /home/s3cr3t-token'],
        ]);
        $this->settle();
        $call = $this->api->lastCallFor('/messages');
        self::assertNotNull($call);
        $wire = (string) \json_encode($call['body']);
        self::assertStringNotContainsString('s3cr3t-token', $wire, 'the card must leave the secret redacted');
        self::assertStringContainsString('[redacted]', $wire);
    }

    public function testATtlBeyondTheCoreCeilingArmsTheCeilingAndLogsLoudly(): void
    {
        $lines = [];
        $this->buildFace(['approvals' => ['ttlMinutes' => 120]], new Log(
            static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        ));
        $this->face->promptApproval(['channelId' => 'c1', 'messageId' => 'm1'], 'sess_1',
            ['askId' => 'ask_clamp', 'tool' => 'Bash', 'arguments' => []]);
        $this->settle();
        self::assertSame((float) PendingAsk::EXPIRY_SECONDS, $this->armedApprovalInterval('ask_clamp'),
            'the card window must never outlive the core expiry that governs the answer');
        $clamps = \array_values(\array_filter($lines,
            static fn (string $line): bool => \str_contains($line, '"event":"approval_ttl_clamped"')));
        self::assertCount(1, $clamps, 'a clamped posture is announced exactly once');
        self::assertStringContainsString('"configuredMinutes":120', $clamps[0]);
        self::assertStringContainsString('"armedSeconds":' . PendingAsk::EXPIRY_SECONDS, $clamps[0]);
        self::assertNull($this->api->lastCallFor('/channels/c1/messages/msg_out'),
            'clamping arms a shorter clock, it does not retire the card early');
    }

    public function testTheDefaultTtlArmsTheCoreWindowQuietly(): void
    {
        $lines = [];
        $this->buildFace([], new Log(
            static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        ));
        $this->face->promptApproval(['channelId' => 'c1', 'messageId' => 'm1'], 'sess_1',
            ['askId' => 'ask_default', 'tool' => 'Bash', 'arguments' => []]);
        $this->settle();
        self::assertSame((float) PendingAsk::EXPIRY_SECONDS, $this->armedApprovalInterval('ask_default'));
        self::assertSame([], \array_filter($lines,
            static fn (string $line): bool => \str_contains($line, 'approval_ttl_clamped')),
            'a co-TTL default needs no announcement');
    }

    public function testTheSixtySecondFloorStillBindsBelowTheCeiling(): void
    {
        $lines = [];
        $this->buildFace(['approvals' => ['ttlMinutes' => 1]], new Log(
            static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        ));
        $this->face->promptApproval(['channelId' => 'c1', 'messageId' => 'm1'], 'sess_1',
            ['askId' => 'ask_floor', 'tool' => 'Bash', 'arguments' => []]);
        $this->settle();
        self::assertSame(60.0, $this->armedApprovalInterval('ask_floor'));
        self::assertSame([], \array_filter($lines,
            static fn (string $line): bool => \str_contains($line, 'approval_ttl_clamped')));
    }

    /**
     * The interval the ask's retirement timer was armed with — the clock
     * law is pinned at arm time, no waiting on wall clock.
     */
    private function armedApprovalInterval(string $askId): float
    {
        $timers = new \ReflectionProperty(DiscordAccount::class, 'approvalTimers');
        $timers->setAccessible(true);
        $timer = $timers->getValue($this->face)[$askId] ?? null;
        self::assertInstanceOf(TimerInterface::class, $timer);

        return $timer->getInterval();
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
        $this->buildFace(['dmPolicy' => 'pairing', 'approvalRoster' => ['boss']]);
        $mint = $this->pairing->request('default', 'u2');
        $code = (string) $mint['code'];
        $this->face->pairCommand(['channelId' => 'c1', 'messageId' => ''], ['approve', $code], 'boss', true);
        $this->settle();
        self::assertStringContainsString('Paired u2.', (string) $this->api->lastCallFor('/messages')['body']['content']);
        $this->face->pairCommand(['channelId' => 'c1', 'messageId' => ''], ['list'], 'boss', true);
        $this->settle();
        self::assertStringContainsString('No pending pairings.', (string) $this->api->lastCallFor('/messages')['body']['content']);
    }

    public function testPairingIsAnApproversActAndCodesListOnlyInADirectMessage(): void
    {
        // Mirrors candy-msteams: an empty roster answers nobody, and a non-approver
        // (even one who passed the channel floor) can neither redeem nor list codes.
        $this->buildFace(['dmPolicy' => 'pairing', 'approvalRoster' => ['boss']]);
        $code = (string) $this->pairing->request('default', 'u2')['code'];
        $this->face->pairCommand(['channelId' => 'c1', 'messageId' => ''], ['approve', $code], 'u3', true);
        $this->settle();
        self::assertStringContainsString('Only an approver can manage pairings', (string) $this->api->lastCallFor('/messages')['body']['content']);
        self::assertCount(1, $this->pairing->pending('default'), 'a refused approve must leave the code pending');

        $this->face->pairCommand(['channelId' => 'c1', 'messageId' => ''], ['list'], 'boss', false);
        $this->settle();
        self::assertStringContainsString('Use a direct message to list pairing codes', (string) $this->api->lastCallFor('/messages')['body']['content']);
        self::assertStringNotContainsString($code, (string) $this->api->lastCallFor('/messages')['body']['content']);

        $this->buildFace(['dmPolicy' => 'pairing']);
        $this->face->pairCommand(['channelId' => 'c1', 'messageId' => ''], ['approve', $code], 'boss', true);
        $this->settle();
        self::assertStringContainsString('Only an approver can manage pairings', (string) $this->api->lastCallFor('/messages')['body']['content'], 'an empty roster answers nobody');
        self::assertCount(1, $this->pairing->pending('default'));
    }

    public function testARepliedCommandIsAppliedOnceEvenWhenDiscordReplaysIt(): void
    {
        // A RESUME replays MESSAGE_CREATE; a command must not run twice.
        $this->store->writeDiscordPairing('default', [], ['u1']);
        $this->buildFace(['dmPolicy' => 'pairing']);
        $stop = self::dmMessage('u1', '/stop');
        $this->intake($stop);
        $this->intake($stop);
        $this->settle();
        $answers = \array_filter($this->api->calls, static fn (array $c): bool => \str_contains((string) ($c['body']['content'] ?? ''), 'Nothing is running here.'));
        self::assertCount(1, $answers, 'the replayed /stop must not answer twice');
    }

    public function testAnOversizedAttachmentIsDisclosedByNameNeverFetched(): void
    {
        $this->store->writeDiscordPairing('default', [], ['u1']);
        $this->buildFace(['media' => ['maxMb' => 1, 'images' => 4, 'documents' => 2]]);
        $gather = new \ReflectionMethod(DiscordAccount::class, 'collectMediaAndDispatch');
        $gather->setAccessible(true);
        $gather->invoke($this->face,
            [['id' => 'a1', 'filename' => 'big.bin', 'content_type' => 'image/png', 'url' => 'https://media.discordapp.net/attachments/1/2/big.bin', 'size' => 999999999]],
            'discord:default:dm:u1', ['channelId' => 'c1', 'messageId' => 'm1'], 'look', false, 'm1');
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

    public function testTheBatchBudgetRefusesPastTheReservationAndTheSecondMessageStillStacksAfterRelease(): void
    {
        // §7.8 batch budget: two 900 KiB reservations fit the 2 MB total,
        // the third is refused BEFORE dialing; once the batch settles the
        // reservations are released, so the next message gets the SAME
        // bounded share — concurrent messages stack bounded, not on top of
        // leftovers from batches that already finished.
        $cdn = new FakeMediaCdn($this->loop);
        $cdn->declaredLength = 943718;
        $cdn->streamBytes = 943718;
        $cdn->streamChunkBytes = 65536;
        $this->store->writeDiscordPairing('default', [], ['u1']);
        $this->buildFace(['media' => ['maxMb' => 100, 'maxTotalMb' => 2, 'images' => 4, 'documents' => 2]], null, $cdn->connector());
        $gather = new \ReflectionMethod(DiscordAccount::class, 'collectMediaAndDispatch');
        $gather->setAccessible(true);
        $gather->invoke($this->face, $this->threeImages('one'), 'discord:default:dm:u1', ['channelId' => 'c1', 'messageId' => 'm1'], 'look one', false, 'm1');
        $this->settle(1200);
        $gather->invoke($this->face, $this->threeImages('two'), 'discord:default:dm:u1', ['channelId' => 'c1', 'messageId' => 'm2'], 'look two', false, 'm2');
        $this->settle(1200);
        $cdn->stop();
        self::assertSame(4, $cdn->requests, 'exactly two dials per message may fit the 2 MB budget');
        $skips = \array_values(\array_filter($this->api->calls, static fn (array $c): bool => \str_contains((string) ($c['body']['content'] ?? ''), 'Skipped 1 attachment(s)')));
        self::assertCount(2, $skips, 'each message must disclose exactly one budget skip');
        self::assertStringContainsString('three-one.png (past the 2 MB batch budget)', (string) $skips[0]['body']['content']);
        self::assertStringContainsString('three-two.png (past the 2 MB batch budget)', (string) $skips[1]['body']['content'],
            'a stranded in-flight reservation would skip ALL of message two, not just its third row');
    }

    public function testAMediaBatchOutstandingConcurrentlyBlocksTheNextMessageDownToBudget(): void
    {
        // Both messages down-select while the first batch is still in
        // flight: the shared reservation lets message one take the budget
        // and message two must be refused on ALL rows, loudly, before dial.
        $cdn = new FakeMediaCdn($this->loop);
        $cdn->declaredLength = 943718;
        $cdn->streamBytes = 943718;
        $cdn->streamChunkBytes = 65536;
        $this->store->writeDiscordPairing('default', [], ['u1']);
        $this->buildFace(['media' => ['maxMb' => 100, 'maxTotalMb' => 2, 'images' => 4, 'documents' => 2]], null, $cdn->connector());
        $gather = new \ReflectionMethod(DiscordAccount::class, 'collectMediaAndDispatch');
        $gather->setAccessible(true);
        $gather->invoke($this->face, $this->threeImages('one'), 'discord:default:dm:u1', ['channelId' => 'c1', 'messageId' => 'm1'], 'look one', false, 'm1');
        $gather->invoke($this->face, $this->threeImages('two'), 'discord:default:dm:u1', ['channelId' => 'c1', 'messageId' => 'm2'], 'look two', false, 'm2');
        $this->settle(1200);
        $cdn->stop();
        self::assertSame(2, $cdn->requests, 'the second concurrent message must not dial past the shared budget');
        $notice = null;
        foreach ($this->api->calls as $call) {
            if (\str_contains((string) ($call['body']['content'] ?? ''), 'Skipped 3 attachment(s)')) {
                $notice = $call;
            }
        }
        self::assertNotNull($notice, 'message two must disclose its three budget skips');
    }

    public function testTheSettledBytesRecheckRefusesALyingDeclaredSizeBeforeBase64(): void
    {
        // The declarations all fit (100 KiB each under the 2 MB total) so
        // three jobs dial — but the ACTUAL bytes are the truth: the first
        // 1.9 MB stream fills the budget and the two settled rows past it
        // are dropped with notes BEFORE any base64 doubling happens.
        $cdn = new FakeMediaCdn($this->loop);
        $cdn->declaredLength = 1939866;
        $cdn->streamBytes = 1939866;
        $cdn->streamChunkBytes = 65536;
        $this->store->writeDiscordPairing('default', [], ['u1']);
        $this->buildFace(['media' => ['maxMb' => 100, 'maxTotalMb' => 2, 'images' => 4, 'documents' => 2]], null, $cdn->connector());
        $gather = new \ReflectionMethod(DiscordAccount::class, 'collectMediaAndDispatch');
        $gather->setAccessible(true);
        $smallDeclared = static function (string $tag): array {
            return [
                ['id' => 'a1', 'filename' => "one-{$tag}.png", 'content_type' => 'image/png', 'url' => "https://media.discordapp.net/attachments/1/2/one-{$tag}.png", 'size' => 102400],
                ['id' => 'a2', 'filename' => "two-{$tag}.png", 'content_type' => 'image/png', 'url' => "https://media.discordapp.net/attachments/1/2/two-{$tag}.png", 'size' => 102400],
                ['id' => 'a3', 'filename' => "three-{$tag}.png", 'content_type' => 'image/png', 'url' => "https://media.discordapp.net/attachments/1/2/three-{$tag}.png", 'size' => 102400],
            ];
        };
        $gather->invoke($this->face, $smallDeclared('lied'), 'discord:default:dm:u1', ['channelId' => 'c1', 'messageId' => 'm1'], 'look', false, 'm1');
        $this->settle(2000);
        $cdn->stop();
        self::assertSame(3, $cdn->requests, 'all three declared-small rows may dial');
        $notice = null;
        foreach ($this->api->calls as $call) {
            if (\str_contains((string) ($call['body']['content'] ?? ''), 'Skipped 2 attachment(s)')) {
                $notice = $call;
            }
        }
        self::assertNotNull($notice, 'the two settled rows past the budget must be skipped with notes');
        self::assertStringContainsString('past the 2 MB batch budget', (string) $notice['body']['content']);
    }

    /** @return list<array<string,mixed>> three 900 KiB image rows under $tag */
    private function threeImages(string $tag): array
    {
        $row = static fn (string $name): array => [
            'id' => 'a-' . $name,
            'filename' => "{$name}-{$tag}.png",
            'content_type' => 'image/png',
            'url' => "https://media.discordapp.net/attachments/1/2/{$name}-{$tag}.png",
            'size' => 943718,
        ];

        return [$row('one'), $row('two'), $row('three')];
    }

    /**
     * N5: a face that deployed globally retracts exactly its global roster
     * when the channel tears it down — the deploy path scoped it global, so
     * the walk-back must list and delete global, never a guild.
     */
    public function testDisablingAFaceUndeploysItsGlobalCommands(): void
    {
        $this->buildFace([]);
        $gateway = $this->faceGateway;
        self::assertNotNull($gateway);
        $gateway->applicationId = 'app-9';

        $this->face->stop();
        $this->settle();

        $deletes = [];
        $listedGlobal = false;
        foreach ($this->api->calls as $call) {
            if ($call['method'] === 'GET' && $call['path'] === '/applications/app-9/commands') {
                $listedGlobal = true;
            }
            if ($call['method'] === 'DELETE') {
                $deletes[] = $call['path'];
            }
        }
        self::assertTrue($listedGlobal, 'the undeploy walk must list the global roster');
        self::assertSame(
            ['/applications/app-9/commands/cmd1', '/applications/app-9/commands/cmd2'],
            $deletes,
        );
    }

    /**
     * N5: a guild-scoped face walks back each listed guild and touches no
     * global roster — the scope law mirrors the deploy exactly.
     */
    public function testAGuildScopedFaceUndeploysOnlyItsGuild(): void
    {
        $this->buildFace(['guilds' => ['g1' => []]]);
        $gateway = $this->faceGateway;
        self::assertNotNull($gateway);
        $gateway->applicationId = 'app-9';

        $this->face->stop();
        $this->settle();

        $deletes = [];
        foreach ($this->api->calls as $call) {
            if ($call['method'] === 'DELETE') {
                $deletes[] = $call['path'];
            }
            self::assertNotSame('/applications/app-9/commands', $call['path'], 'no global listing for a guild-scoped face');
        }
        self::assertSame(
            [
                '/applications/app-9/guilds/g1/commands/cmd1',
                '/applications/app-9/guilds/g1/commands/cmd2',
            ],
            $deletes,
        );
    }

    /**
     * N5: a deployless face (appCommands false) retracts nothing and says
     * nothing — no listing, no deletes, no notice, teardown still lands.
     */
    public function testADeploylessFaceRetractsNothingAndSaysNothing(): void
    {
        $lines = [];
        $this->buildFace(['appCommands' => false], new Log(
            static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        ));
        $gateway = $this->faceGateway;
        self::assertNotNull($gateway);
        $gateway->applicationId = 'app-9';

        $this->face->stop();
        $this->settle();

        foreach ($this->api->calls as $call) {
            self::assertFalse(
                \str_ends_with($call['path'], '/commands')
                    || (bool) \preg_match('#/commands/cmd[0-9]+$#', $call['path']),
                'a never-deployed face must not retract: ' . $call['method'] . ' ' . $call['path'],
            );
        }
        self::assertSame([], \array_filter($lines, static fn (string $l): bool => \str_contains($l, 'discord_slash_undeploy')));
        // stop() returning without throwing is itself the teardown proof;
        // stopped() reads the reconnect ladder's give-up flag, not teardown.
    }

    /**
     * N5: the retraction is a COURTESY — one transport failure notices once,
     * and the teardown proceeds regardless (the gateway is stopped either
     * way; stop() returning normally is itself the never-blocks assertion).
     */
    public function testAFailingUndeployNoticesOnceAndTeardownProceeds(): void
    {
        $lines = [];
        $this->buildFace([], new Log(
            static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        ));
        $gateway = $this->faceGateway;
        self::assertNotNull($gateway);
        $gateway->applicationId = 'app-9';
        $this->api->failNextWith = 503;

        $this->face->stop();
        $this->settle();

        $notices = \array_filter($lines, static fn (string $l): bool => \str_contains($l, 'discord_slash_undeploy_failed'));
        self::assertCount(1, $notices, 'the failure notices exactly once: ' . \implode(' | ', $lines));
        self::assertSame(
            [],
            \array_filter($lines, static fn (string $l): bool => \str_contains($l, 'discord_slash_undeployed')),
        );
        $listed = false;
        foreach ($this->api->calls as $call) {
            if ($call['method'] === 'GET' && $call['path'] === '/applications/app-9/commands') {
                $listed = true;
            }
        }
        self::assertTrue($listed, 'the retraction was attempted: the notice follows a real leg, and stop() itself never blocks');
    }

    /**
     * N5 keystone for loud-ONCE: two failing legs (both guild listings die
     * under the persistent knob) still produce a single notice — without the
     * guard this test sees two and the face would shout per leg on teardown.
     */
    public function testALoudOnceNoticeDoesNotStutterAcrossLegs(): void
    {
        $lines = [];
        $this->buildFace(['guilds' => ['g1' => [], 'g2' => []]], new Log(
            static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        ));
        $gateway = $this->faceGateway;
        self::assertNotNull($gateway);
        $gateway->applicationId = 'app-9';
        $this->api->failAllWith = 500;

        $this->face->stop();
        $this->settle();

        $notices = \array_filter($lines, static fn (string $l): bool => \str_contains($l, 'discord_slash_undeploy_failed'));
        self::assertCount(1, $notices, 'two dead legs must still notice once: ' . \implode(' | ', $lines));
        $g1 = false;
        $g2 = false;
        foreach ($this->api->calls as $call) {
            if ($call['method'] === 'GET' && $call['path'] === '/applications/app-9/guilds/g1/commands') {
                $g1 = true;
            }
            if ($call['method'] === 'GET' && $call['path'] === '/applications/app-9/guilds/g2/commands') {
                $g2 = true;
            }
        }
        self::assertTrue($g1, 'the first guild leg was attempted before the single notice');
        self::assertTrue($g2, 'the second guild leg was attempted before the single notice');
    }

    private function buildFace(array $cfg, ?Log $faceLog = null, ?\React\Socket\ConnectorInterface $mediaConnector = null): void
    {
        $rest = new Rest('tok', Log::silent(), 'http://127.0.0.1:' . $this->apiPort(), $mediaConnector);
        $this->faceGateway = new Gateway('tok', Log::silent(), $this->loop, 'ws://127.0.0.1:1/never');
        $router = new ConversationRouter(
            new ServeBridge('ws://127.0.0.1:1/never', '', 'subprotocol', Log::silent(), $this->loop),
            new SessionMap($this->tempDir . '/sessions.db'),
            Log::silent(),
            new TextSafety(),
            $this->loop,
        );
        $this->faceLog = $faceLog ?? Log::silent();
        $this->faceSafety = new TextSafety();
        $this->face = new DiscordAccount('default', $rest, $this->faceGateway, $router, $cfg, $this->faceLog,
            $this->pairing, $this->faceSafety, $this->loop);
        $router->addChannel($this->face, 'default');
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

    private function settle(int $ms = 300): void
    {
        $this->loop->addTimer($ms / 1000.0, function (): void {
            $this->loop->stop();
        });
        $this->loop->run();
    }
}
