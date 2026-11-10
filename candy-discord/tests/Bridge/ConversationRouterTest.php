<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Tests\Bridge;

use CandyCode\Bundles\Discord\Bridge\ConversationRouter;
use CandyCode\Bundles\Discord\Bridge\PlatformFace;
use CandyCode\Bundles\Discord\Candy\ServeBridge;
use CandyCode\Bundles\Discord\Support\Keys;
use CandyCode\Bundles\Discord\Support\Log;
use CandyCode\Bundles\Discord\Support\OutboundChunker;
use CandyCode\Bundles\Discord\Support\QueueGate;
use CandyCode\Bundles\Discord\Support\SessionMap;
use CandyCode\Bundles\Discord\Support\TextSafety;
use CandyCode\Bundles\Discord\Tests\Fixture\FakeServe;
use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/** The router's channel face for tests: records everything, dials nothing. */
final class RecordingRouterFace implements PlatformFace
{
    /** @var list<string> */
    public array $delivered = [];

    /** @var list<array> */
    public array $prompted = [];

    /** @var array<string,string> */
    public array $retired = [];

    public int $typingCount = 0;

    public function name(): string
    {
        return 'discord';
    }

    public function textChunkLimit(): int
    {
        return 2000;
    }

    public function maxLinesPerMessage(): int
    {
        return 17;
    }

    public function deliver(array $ref, string $chunk): PromiseInterface
    {
        $this->delivered[] = $chunk;

        return resolve(null);
    }

    public function promptApproval(array $ref, string $sessionId, array $ask): void
    {
        $this->prompted[] = $ask;
    }

    public function retireApproval(string $askId, string $line): void
    {
        $this->retired[$askId] = $line;
    }

    public function typing(array $ref): void
    {
        $this->typingCount++;
    }
}

/**
 * Behaviour pins for the router: delivery vocabulary, dedupe, the exec-approval
 * doors, the /model leg, and the media-refusal retry — all over the loopback
 * FakeServe with a temp-dir SessionMap. No real network, no model paths.
 */
final class ConversationRouterTest extends TestCase
{
    private LoopInterface $loop;

    private FakeServe $serve;

    private ServeBridge $bridge;

    private SessionMap $map;

    private RecordingRouterFace $face;

    private ConversationRouter $router;

    private string $tempDir = '';

    private const DM_KEY = 'discord:default:dm:peer-1';

    /** @var array<string,mixed> */
    private array $policy = [];

    protected function setUp(): void
    {
        $this->loop = new StreamSelectLoop();
        $this->serve = new FakeServe($this->loop, 'tok-123456');
        $port = $this->serve->listen();
        $this->bridge = new ServeBridge('ws://127.0.0.1:' . $port . '/', 'tok-123456', 'subprotocol', Log::silent(), $this->loop);

        $this->tempDir = sys_get_temp_dir() . '/p2-router-' . getmypid() . '-' . uniqid('', true);
        $this->map = new SessionMap($this->tempDir . '/map/map.sqlite');

        $this->face = new RecordingRouterFace();
        $this->router = new ConversationRouter($this->bridge, $this->map, Log::silent(), new TextSafety(), $this->loop, $this->policy);
        $this->router->addChannel($this->face, 'default');

        $this->connect();
    }

    protected function tearDown(): void
    {
        $this->bridge->stop();
        $this->serve->stop();
        if ($this->tempDir !== '') {
            $this->removeTree($this->tempDir);
        }
    }

    public function testTheFirstInboundQueuesAndDedupesById(): void
    {
        $this->inboundDm('hello serve', false, 'm-1');
        self::runFor($this->loop, 300);

        self::assertSame(1, $this->serve->sendSeen);
        self::assertSame('hello serve', $this->serve->seenText);
        self::assertSame('queue', $this->serve->lastDelivery);

        $this->inboundDm('a repeat', false, 'm-1');
        self::runFor($this->loop, 300);

        self::assertSame(1, $this->serve->sendSeen, 'a re-delivered inbound id must never double-prompt the session');
    }

    public function testAMentionWhileTheSessionIsBusySteers(): void
    {
        $this->inboundDm('start work', false, 'm-1');
        self::runFor($this->loop, 300);

        $this->serve->pushEvent($this->envelope('session.status', 50, ['status' => 'busy']));
        self::runFor($this->loop, 200);

        $this->inboundDm('steer me', true, 'm-2');
        self::runFor($this->loop, 300);

        self::assertSame('steer', $this->serve->lastDelivery);
        self::assertSame(2, $this->serve->sendSeen);
    }

    public function testCollectModeCoalescesQuietPromptsIntoOneSend(): void
    {
        $this->inboundDm('bind me', false, 'm-0');
        self::runFor($this->loop, 300);
        self::assertSame(1, $this->serve->sendSeen);

        $this->router->setQueueGate(new QueueGate($this->loop, 40, 20));
        $this->inboundDm('one', false, 'm-1');
        $this->inboundDm('two', false, 'm-2');
        self::runFor($this->loop, 500);

        self::assertSame(2, $this->serve->sendSeen, 'collect must flush both prompts as ONE send');
        self::assertSame("one\n\ntwo", $this->serve->seenText);
    }

    public function testInterruptModeCancelsThenQueuesTheMention(): void
    {
        $this->inboundDm('bind me', false, 'm-1');
        self::runFor($this->loop, 300);

        $this->serve->pushEvent($this->envelope('session.status', 55, ['status' => 'busy']));
        self::runFor($this->loop, 200);

        $this->inboundDm('stop that', true, 'm-2');
        self::runFor($this->loop, 300);

        self::assertSame(1, $this->serve->cancelSeen);
        self::assertSame('queue', $this->serve->lastDelivery);
    }

    public function testAnAskPresentsApprovalButtons(): void
    {
        $this->inboundDm('bind me', false, 'm-1');
        self::runFor($this->loop, 300);

        $this->serve->pushEvent($this->envelope('permission.requested', 80, ['askId' => 'ask_9', 'tool' => 'Bash', 'arguments' => ['cmd' => 'ls']]));
        self::runFor($this->loop, 300);

        self::assertCount(1, $this->face->prompted);
        self::assertSame('ask_9', $this->face->prompted[0]['askId'] ?? null);
    }

    public function testDisabledApprovalsFallBackToSameChatText(): void
    {
        $this->inboundDm('bind me', false, 'm-1');
        self::runFor($this->loop, 300);

        $this->serve->pushEvent($this->envelope('permission.requested', 80, ['askId' => 'ask_9', 'tool' => 'Bash', 'arguments' => []]));
        self::runFor($this->loop, 300);

        self::assertSame([], $this->face->prompted, 'buttons must not render when approvals are disabled');
        self::assertContains('/approve ask_9 once|always|deny', $this->face->delivered);
    }

    public function testTheTargetDoorKeepsChannelAsksQuietWhenDmOnly(): void
    {
        $channelKey = Keys::channelKey('discord', 'default', 'chan-9');
        $this->router->inbound('discord', $channelKey, ['channelId' => 'chan-9'], 'bind on channel', false, 'm-1');
        self::runFor($this->loop, 300);

        $this->serve->pushEvent($this->envelope('permission.requested', 80, ['askId' => 'ask_9', 'tool' => 'Bash', 'arguments' => []]));
        self::runFor($this->loop, 300);

        self::assertSame([], $this->face->prompted, 'a dm-only target must keep channel-conversation asks unrendered');
        self::assertSame([], $this->face->delivered);
    }

    public function testTheRosterRefusesStrangers(): void
    {
        $this->inboundDm('bind me', false, 'm-1');
        self::runFor($this->loop, 300);

        $this->router->answerApproval('sess_fixture_1', 'ask_9', 'once', ['userId' => 'rando', 'name' => 'R']);
        self::runFor($this->loop, 300);

        self::assertSame(1, $this->serve->respondSeen);
        self::assertSame('reject', $this->serve->lastReply);
        self::assertStringContainsString('is not an approver', (string) $this->serve->lastNote);
    }

    public function testTheModelArmRunsTheBuiltInAndDeliversItsRows(): void
    {
        $this->inboundDm('bind me', false, 'm-1');
        self::runFor($this->loop, 300);

        $this->router->runCommand('discord', self::DM_KEY, ['channelId' => 'chan-9'], ['name' => 'model', 'args' => ['sonnet']]);
        self::runFor($this->loop, 300);

        self::assertSame(1, $this->serve->execSeen);
        self::assertSame('switched', $this->face->delivered[count($this->face->delivered) - 1]);
    }

    public function testAMediaRefusalRetriesOnceWithoutAttachments(): void
    {
        $this->inboundDm('bind me', false, 'm-1');
        self::runFor($this->loop, 300);

        $this->serve->refuseMediaOnce = true;
        $this->router->inbound(
            'discord',
            self::DM_KEY,
            ['channelId' => 'chan-9'],
            'with file',
            false,
            'm-2',
            [['name' => 'a.png', 'mime' => 'image/png', 'data' => 'AA==']],
            [],
        );
        self::runFor($this->loop, 400);

        self::assertSame(2, $this->serve->sendSeen, 'refused media must be retried exactly once without attachments');
        self::assertContains('Attachments refused by the server — sent without them.', $this->face->delivered);
        self::assertFalse($this->serve->lastHasMedia);
    }

    public function testAResolvedAskRetiresOnEveryFaceAndStopsRepeating(): void
    {
        $this->inboundDm('bind me', false, 'm-1');
        self::runFor($this->loop, 300);

        $this->serve->pushEvent($this->envelope('permission.requested', 80, ['askId' => 'ask_9', 'tool' => 'Bash', 'arguments' => []]));
        self::runFor($this->loop, 200);
        $this->serve->pushEvent($this->envelope('permission.resolved', 81, ['askId' => 'ask_9', 'reply' => 'once']));
        self::runFor($this->loop, 200);

        self::assertArrayHasKey('ask_9', $this->face->retired);
        self::assertStringContainsString('Approved', $this->face->retired['ask_9']);

        $this->serve->pushEvent($this->envelope('permission.requested', 82, ['askId' => 'ask_9', 'tool' => 'Bash', 'arguments' => []]));
        self::runFor($this->loop, 200);
        self::assertCount(1, $this->face->prompted, 'a resolved ask must not present again on a cursor skip');
    }

    public function testAssistantOutputChunksStayWithinTheFaceBudget(): void
    {
        $this->inboundDm('bind me', false, 'm-1');
        self::runFor($this->loop, 300);

        $blob = str_repeat("line of output\n", 400);
        $this->serve->pushEvent($this->envelope('assistant.completed', 90, ['content' => $blob]));
        self::runFor($this->loop, 400);

        self::assertGreaterThan(1, count($this->face->delivered), 'a fat reply must arrive chunked');
        foreach ($this->face->delivered as $chunk) {
            self::assertLessThanOrEqual(OutboundChunker::len($chunk), 2000);
        }
    }

    private function connect(): void
    {
        $started = false;
        $this->bridge->start()->then(static function () use (&$started): void {
            $started = true;
        });
        for ($tick = 0; $tick < 20 && !$started; $tick++) {
            self::runFor($this->loop, 100);
        }
        if (!$started) {
            self::fail('serve handshake never admitted');
        }
    }

    /** @param array<string,mixed> $data */
    private function envelope(string $type, ?int $seq, array $data): array
    {
        return [
            'sessionId' => 'sess_fixture_1',
            'seq' => $seq,
            'type' => $type,
            'ts' => 1,
            'durable' => $seq !== null,
            'data' => $data,
        ];
    }

    private function inboundDm(string $text, bool $isMention, string $inboundId): void
    {
        $this->router->inbound('discord', self::DM_KEY, ['channelId' => 'chan-9', 'messageId' => $inboundId], $text, $isMention, $inboundId);
    }

    private static function runFor(LoopInterface $loop, int $ms): void
    {
        $timer = $loop->addTimer($ms / 1000.0, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();
        $loop->cancelTimer($timer);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
