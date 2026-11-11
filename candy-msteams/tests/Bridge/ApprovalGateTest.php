<?php

/**
 * C-1 (cc-review-1111 CC-TEAM-B), Teams twin of the discord bundle's
 * ApprovalGateTest: the approval-answer gate. Before this lane,
 * answerApproval checked ONLY a configured approver roster — an empty
 * roster (the default) let any submitter, any hand-crafted invoke `value`,
 * and any cross-conversation answer reach permission.respond (Teams
 * invokes arrive signed but their payload is forgeable data — see the
 * Cards header law). These pins prove the gate: the card face must carry
 * the per-ask nonce minted at presentation AND answer inside the ask's
 * own conversation; the text /approve must answer inside that
 * conversation; a configured roster still rejects first on the wire;
 * retirement clears the nonce; and the documented post-restart /approve
 * recovery still binds through the session's own conversation.
 */

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Tests\Bridge;

use CandyCode\Bundles\MsTeams\Bridge\ConversationRouter;
use CandyCode\Bundles\MsTeams\Bridge\PlatformFace;
use CandyCode\Bundles\MsTeams\Candy\ServeBridge;
use CandyCode\Bundles\MsTeams\MsTeams\Cards;
use CandyCode\Bundles\MsTeams\Support\Log;
use CandyCode\Bundles\MsTeams\Support\OutboundChunker;
use CandyCode\Bundles\MsTeams\Support\SessionMap;
use CandyCode\Bundles\MsTeams\Support\TextSafety;
use CandyCode\Bundles\MsTeams\Tests\Fixture\FakeServe;
use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/** Records presentations (incl. the C-1 nonce) and deliveries; dials nothing. */
final class GatedRouterFace implements PlatformFace
{
    /** @var list<string> */
    public array $delivered = [];

    /** @var list<array> */
    public array $prompted = [];

    /** @var array<string,string> askId → nonce the router minted */
    public array $promptNonces = [];

    public function name(): string
    {
        return 'msteams';
    }

    public function textChunkLimit(): int
    {
        return 4000;
    }

    public function maxLinesPerMessage(): int
    {
        return 30;
    }

    public function deliver(array $ref, string $chunk): PromiseInterface
    {
        $this->delivered[] = $chunk;

        return resolve(null);
    }

    public function promptApproval(array $ref, string $sessionId, array $ask, string $nonce = ''): void
    {
        $this->prompted[] = $ask;
        $this->promptNonces[(string) ($ask['askId'] ?? '')] = $nonce;
    }

    public function retireApproval(string $askId, string $line): void
    {
        $this->delivered[] = '[retired ' . $askId . '] ' . $line;
    }

    public function typing(array $ref): void
    {
    }
}

final class ApprovalGateTest extends TestCase
{
    private LoopInterface $loop;

    private FakeServe $serve;

    private ServeBridge $bridge;

    private SessionMap $map;

    private GatedRouterFace $face;

    private ConversationRouter $router;

    private string $tempDir = '';

    private const DM_KEY = 'msteams:default:dm:peer-1';

    private const CHANNEL_KEY = 'msteams:default:19:team-9@thread.tacv2';

    protected function setUp(): void
    {
        $this->loop = new StreamSelectLoop();
        $this->serve = new FakeServe($this->loop, 'tok-123456');
        $port = $this->serve->listen();
        $this->bridge = new ServeBridge('ws://127.0.0.1:' . $port . '/', 'tok-123456', 'subprotocol', Log::silent(), $this->loop);

        $this->tempDir = \sys_get_temp_dir() . '/f-c1-teams-' . \getmypid() . '-' . \uniqid('', true);
        $this->map = new SessionMap($this->tempDir . '/map/map.sqlite');
        $this->face = new GatedRouterFace();
    }

    protected function tearDown(): void
    {
        $this->bridge->stop();
        $this->serve->stop();
        if ($this->tempDir !== '') {
            $this->removeTree($this->tempDir);
        }
    }

    // ── payload shapes ──

    public function testTheCardCarriesTheNonceInEveryActionData(): void
    {
        $card = Cards::approvalCard(['askId' => 'ask_9', 'tool' => 'Bash'], 'summary', [], 'cafebabe01');
        self::assertCount(3, $card['actions']);
        foreach ($card['actions'] as $action) {
            self::assertSame('cafebabe01', $action['data']['nonce'] ?? null, 'every submit action carries the per-ask nonce');
        }
    }

    public function testALegacySubmitValueParsesWithAnEmptyNonce(): void
    {
        $parsed = Cards::parseSubmitValue([Cards::DATA_KEY => 'ask_9', 'verdict' => 'once']);
        self::assertNotNull($parsed);
        self::assertSame('once', $parsed['verdict']);
        self::assertSame('', $parsed['nonce'], 'a pre-C-1 (or hand-crafted) value parses WITHOUT a nonce — the gate then refuses it');
    }

    public function testANonceBearingSubmitValueRoundTrips(): void
    {
        $parsed = Cards::parseSubmitValue([Cards::DATA_KEY => 'ask_9', 'verdict' => 'deny', 'nonce' => 'cafebabe01']);
        self::assertNotNull($parsed);
        self::assertSame('reject', $parsed['verdict']);
        self::assertSame('cafebabe01', $parsed['nonce']);
    }

    // ── the gate ──

    public function testAPresentedCardCarriesAStoredNonceAndAnswersWhenBound(): void
    {
        $nonce = $this->presentAsk();
        self::assertNotSame('', $nonce, 'presentation must mint a per-ask nonce');

        $this->router->answerByAskId('ask_9', 'once', ['userId' => 'peer-1', 'name' => 'P'], $nonce, self::DM_KEY, 'card');
        self::runFor($this->loop, 300);

        self::assertSame(1, $this->serve->respondSeen, 'a legitimate bound submit answers — documented empty-roster = same-conversation everyone stands');
        self::assertSame('once', $this->serve->lastReply);
    }

    public function testACardSubmitWithAForgedNonceIsRefused(): void
    {
        $this->presentAsk();

        $this->router->answerByAskId('ask_9', 'always', ['userId' => 'peer-1', 'name' => 'P'], 'cafebabe01', self::DM_KEY, 'card');
        self::runFor($this->loop, 300);

        self::assertSame(0, $this->serve->respondSeen, 'a crafted value naming the ask id must never reach the permission wire');
        self::assertContainsRefusal();
    }

    public function testACardSubmitWithoutANonceIsRefused(): void
    {
        $this->presentAsk();

        $this->router->answerByAskId('ask_9', 'once', ['userId' => 'peer-1', 'name' => 'P'], '', self::DM_KEY, 'card');
        self::runFor($this->loop, 300);

        self::assertSame(0, $this->serve->respondSeen, 'the card face has no nonce-less spelling — stale pre-C-1 cards die fail-closed');
        self::assertContainsRefusal();
    }

    public function testACardSubmitFromAnotherConversationIsRefused(): void
    {
        $nonce = $this->presentAsk();

        $this->router->answerByAskId('ask_9', 'once', ['userId' => 'peer-1', 'name' => 'P'], $nonce, self::CHANNEL_KEY, 'card');
        self::runFor($this->loop, 300);

        self::assertSame(0, $this->serve->respondSeen);
        self::assertContainsRefusal();
    }

    public function testATextAnswerFromAnotherConversationIsRefused(): void
    {
        $this->presentAsk();

        $this->router->answerApproval('sess_fixture_1', 'ask_9', 'once', ['userId' => 'peer-1', 'name' => 'P'], '', self::CHANNEL_KEY, 'text');
        self::runFor($this->loop, 300);

        self::assertSame(0, $this->serve->respondSeen, 'a cross-conversation /approve cannot answer someone else session-ask');
        self::assertContainsRefusal();
    }

    public function testAConfiguredRosterStillRejectsFirstOnTheWire(): void
    {
        $nonce = $this->presentAsk(['approvals' => ['approvers' => ['boss']]]);

        $this->router->answerByAskId('ask_9', 'once', ['userId' => 'rando', 'name' => 'R'], $nonce, self::DM_KEY, 'card');
        self::runFor($this->loop, 300);

        self::assertSame(1, $this->serve->respondSeen, 'the pre-existing roster wall keeps its ask-resolving reject shape');
        self::assertSame('reject', $this->serve->lastReply);
        self::assertStringContainsString('is not an approver', (string) $this->serve->lastNote);
    }

    public function testARosteredInsiderStillNeedsNonceAndBinding(): void
    {
        $nonce = $this->presentAsk(['approvals' => ['approvers' => ['boss']]]);

        $this->router->answerByAskId('ask_9', 'once', ['userId' => 'boss', 'name' => 'B'], 'forged', self::DM_KEY, 'card');
        self::runFor($this->loop, 300);

        self::assertSame(0, $this->serve->respondSeen, 'being on the roster is not a licence to answer with a forged card');
        self::assertContainsRefusal();
    }

    public function testTheNeverPresentedTextAnswerBindsToTheSessionsOwnConversation(): void
    {
        // Post-restart recovery (§7.5): the ask was never presented in
        // THIS process; a /approve arriving on the session's own
        // conversation binds lazily and answers.
        $this->bootRouter();
        $this->inboundDm('bind me', false, 'm-1');

        $this->router->answerApproval('sess_fixture_1', 'ask_restart', 'once', ['userId' => 'peer-1', 'name' => 'P'], '', self::DM_KEY, 'text');
        self::runFor($this->loop, 300);

        self::assertSame(1, $this->serve->respondSeen, 'the documented expired-/approve recovery must survive the new gate');
        self::assertSame('once', $this->serve->lastReply);
    }

    public function testARetiredAskCannotBeDoubleAnsweredByItsOldCard(): void
    {
        $nonce = $this->presentAsk();
        $this->router->answerByAskId('ask_9', 'once', ['userId' => 'peer-1', 'name' => 'P'], $nonce, self::DM_KEY, 'card');
        self::runFor($this->loop, 300);
        self::assertSame(1, $this->serve->respondSeen);

        $this->pushSettled($this->envelope('permission.resolved', 81, ['askId' => 'ask_9', 'reply' => 'once']));

        $this->router->answerApproval('sess_fixture_1', 'ask_9', 'always', ['userId' => 'peer-1', 'name' => 'P'], $nonce, self::DM_KEY, 'card');
        self::runFor($this->loop, 300);

        self::assertSame(1, $this->serve->respondSeen, 'retirement must clear the binding — the old card is dead');
        self::assertContainsRefusal();
    }

    // ── harness ──

    /** @param array<string,mixed> $policy */
    private function bootRouter(array $policy = []): void
    {
        $this->router = new ConversationRouter($this->bridge, $this->map, Log::silent(), new TextSafety(), $this->loop, $policy);
        $this->router->addChannel($this->face, 'default');

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

    /** @param array<string,mixed> $policy */
    private function presentAsk(array $policy = []): string
    {
        $this->bootRouter($policy);
        $this->inboundDm('bind me', false, 'm-1');
        $this->pushSettled($this->envelope('permission.requested', 80, ['askId' => 'ask_9', 'tool' => 'Bash', 'arguments' => []]));
        self::assertCount(1, $this->face->prompted);

        return $this->face->promptNonces['ask_9'] ?? '';
    }

    /** @param array<string,mixed> $data */
    private function envelope(string $type, ?int $seq, array $data): array
    {
        return ['sessionId' => 'sess_fixture_1', 'seq' => $seq, 'type' => $type, 'ts' => 1, 'durable' => $seq !== null, 'data' => $data];
    }

    private function pushSettled(array $envelope): void
    {
        $this->serve->pushEvent($envelope);
        self::runFor($this->loop, 250);
    }

    private function inboundDm(string $text, bool $isMention, string $inboundId): void
    {
        $this->router->inbound('msteams', self::DM_KEY, ['conversationId' => 'conv-9', 'serviceUrl' => 'https://s.test', 'activityId' => $inboundId], $text, $isMention, $inboundId);
        self::runFor($this->loop, 300);
    }

    private function assertContainsRefusal(): void
    {
        foreach ($this->face->delivered as $chunk) {
            if (\str_contains($chunk, 'Approval answer refused')) {
                return;
            }
        }
        self::fail('expected the loud deny line delivered to the ask conversation; got: ' . \json_encode(\array_map(
            static fn (string $s): string => OutboundChunker::len($s) . 'b:' . \substr($s, 0, 40),
            $this->face->delivered,
        )));
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
        if (!\is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            if ($item->isDir()) {
                @\rmdir($item->getPathname());
            } else {
                @\unlink($item->getPathname());
            }
        }
        @\rmdir($dir);
    }
}
