<?php

/**
 * C-1 (cc-review-1111 CC-TEAM-B): the approval-answer gate. Before this
 * lane, answerApproval checked ONLY a configured approver roster — an
 * empty roster (the default) let any clicker, any forged custom_id, and
 * any cross-conversation answer reach permission.respond. These pins
 * prove the gate: a button/card answer must carry the per-ask nonce
 * minted at presentation AND arrive in the ask's own conversation; a
 * text /approve must arrive in that conversation (its sender passed the
 * account's dmPolicy/guild floor upstream); a configured roster still
 * rejects first on the wire; retirement clears the nonce so a stale card
 * cannot double-answer; and the documented post-restart /approve
 * recovery still binds through the session's own conversation.
 */

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Tests\Bridge;

use CandyCode\Bundles\Discord\Bridge\ConversationRouter;
use CandyCode\Bundles\Discord\Bridge\PlatformFace;
use CandyCode\Bundles\Discord\Candy\ServeBridge;
use CandyCode\Bundles\Discord\Discord\Buttons;
use CandyCode\Bundles\Discord\Support\Log;
use CandyCode\Bundles\Discord\Support\OutboundChunker;
use CandyCode\Bundles\Discord\Support\SessionMap;
use CandyCode\Bundles\Discord\Support\TextSafety;
use CandyCode\Bundles\Discord\Tests\Fixture\FakeServe;
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

    /** @var array<string,string> askId → presentation ref channel */
    public array $promptRefs = [];

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

    public function promptApproval(array $ref, string $sessionId, array $ask, string $nonce = ''): void
    {
        $this->prompted[] = $ask;
        $askId = (string) ($ask['askId'] ?? '');
        $this->promptNonces[$askId] = $nonce;
        $this->promptRefs[$askId] = (string) ($ref['channelId'] ?? '');
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

    private const DM_KEY = 'discord:default:dm:peer-1';

    private const CHANNEL_KEY = 'discord:default:chan-9';

    protected function setUp(): void
    {
        $this->loop = new StreamSelectLoop();
        $this->serve = new FakeServe($this->loop, 'tok-123456');
        $port = $this->serve->listen();
        $this->bridge = new ServeBridge('ws://127.0.0.1:' . $port . '/', 'tok-123456', 'subprotocol', Log::silent(), $this->loop);

        $this->tempDir = \sys_get_temp_dir() . '/f-c1-gate-' . \getmypid() . '-' . \uniqid('', true);
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

    public function testThePayloadCarriesTheNonceInEveryCustomId(): void
    {
        $payload = Buttons::approvalPayload(['askId' => 'ask_9', 'tool' => 'Bash'], 'summary', [], 'cafebabe01');
        $buttons = $payload['components'][0]['components'][1]['components'];
        self::assertCount(3, $buttons);
        foreach ($buttons as $button) {
            self::assertSame(1, \preg_match('#^ap:ask_9:(once|always|deny):cafebabe01$#', (string) $button['custom_id']), 'every button carries the per-ask nonce: ' . (string) $button['custom_id']);
        }
    }

    public function testALegacyThreeSegmentCustomIdParsesWithAnEmptyNonce(): void
    {
        $parsed = Buttons::parseButton([
            'type' => Buttons::INTERACTION_TYPE_BUTTON,
            'data' => ['custom_id' => 'ap:ask_9:once'],
        ]);
        self::assertNotNull($parsed);
        self::assertSame('ask_9', $parsed['askId']);
        self::assertSame('once', $parsed['verdict']);
        self::assertSame('', $parsed['nonce'], 'a pre-C-1 (or forged) custom id parses WITHOUT a nonce — the gate then refuses it');
    }

    public function testANonceBearingCustomIdRoundTrips(): void
    {
        $parsed = Buttons::parseButton([
            'type' => Buttons::INTERACTION_TYPE_BUTTON,
            'data' => ['custom_id' => 'ap:ask_9:deny:cafebabe01'],
        ]);
        self::assertNotNull($parsed);
        self::assertSame('reject', $parsed['verdict']);
        self::assertSame('cafebabe01', $parsed['nonce']);
    }

    // ── the gate ──

    public function testAPresentedCardCarriesAStoredNonceAndAnswersWhenBound(): void
    {
        $nonce = $this->presentAsk();
        self::assertNotSame('', $nonce, 'presentation must mint a per-ask nonce');

        $this->router->answerByAskId('ask_9', 'once', ['userId' => 'peer-1', 'name' => 'P'], $nonce, self::DM_KEY, 'button');
        self::runFor($this->loop, 300);

        self::assertSame(1, $this->serve->respondSeen, 'a legitimate bound click answers — documented empty-roster = same-conversation everyone stands');
        self::assertSame('once', $this->serve->lastReply);
    }

    public function testAButtonAnswerWithAForgedNonceIsRefused(): void
    {
        $this->presentAsk();

        $this->router->answerByAskId('ask_9', 'once', ['userId' => 'peer-1', 'name' => 'P'], 'cafebabe01', self::DM_KEY, 'button');
        self::runFor($this->loop, 300);

        self::assertSame(0, $this->serve->respondSeen, 'a guessed nonce must never reach the permission wire');
        self::assertContainsRefusal();
    }

    public function testAlwaysWithoutAConfiguredApproverIsRefusedAndTheAskStaysOpen(): void
    {
        $nonce = $this->presentAsk();

        $this->router->answerByAskId('ask_9', 'always', ['userId' => 'peer-1', 'name' => 'P'], $nonce, self::DM_KEY, 'button');
        self::runFor($this->loop, 300);

        self::assertSame(0, $this->serve->respondSeen, 'a persistent grant with no named approver must not reach the wire');
        $said = \implode("\n", $this->face->delivered);
        self::assertStringContainsString(\SugarCraft\Code\Lang::t('channels.discord.approval.always_needs_approver'), $said);
    }

    public function testAButtonAnswerWithoutANonceIsRefused(): void
    {
        $this->presentAsk();

        $this->router->answerByAskId('ask_9', 'once', ['userId' => 'peer-1', 'name' => 'P'], '', self::DM_KEY, 'button');
        self::runFor($this->loop, 300);

        self::assertSame(0, $this->serve->respondSeen, 'the button face has no nonce-less spelling — stale pre-C-1 cards die fail-closed');
        self::assertContainsRefusal();
    }

    public function testAButtonAnswerFromAnotherConversationIsRefused(): void
    {
        $nonce = $this->presentAsk();

        // Even with the right nonce, an answer arriving on a different
        // conversation key is not the conversation the card lives in.
        $this->router->answerByAskId('ask_9', 'once', ['userId' => 'peer-1', 'name' => 'P'], $nonce, self::CHANNEL_KEY, 'button');
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

        $this->router->answerByAskId('ask_9', 'once', ['userId' => 'rando', 'name' => 'R'], $nonce, self::DM_KEY, 'button');
        self::runFor($this->loop, 300);

        self::assertSame(1, $this->serve->respondSeen, 'the pre-existing roster wall keeps its ask-resolving reject shape');
        self::assertSame('reject', $this->serve->lastReply);
        self::assertStringContainsString('is not an approver', (string) $this->serve->lastNote);
    }

    public function testARosteredInsiderStillNeedsNonceAndBinding(): void
    {
        $nonce = $this->presentAsk(['approvals' => ['approvers' => ['boss']]]);

        $this->router->answerByAskId('ask_9', 'once', ['userId' => 'boss', 'name' => 'B'], 'forged', self::DM_KEY, 'button');
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
        $nonce = $this->presentAsk(['approvals' => ['approvers' => ['boss']]]);
        $this->router->answerByAskId('ask_9', 'once', ['userId' => 'boss', 'name' => 'B'], $nonce, self::DM_KEY, 'button');
        self::runFor($this->loop, 300);
        self::assertSame(1, $this->serve->respondSeen);

        $this->pushSettled($this->envelope('permission.resolved', 81, ['askId' => 'ask_9', 'reply' => 'once']));

        // A second click on the same (now retired) card: the nonce died
        // with retirement, so the stale click is refused fail-closed
        // instead of reaching an already-resolved ask. Routed straight to
        // answerApproval (the askSession row is gone post-retirement, so
        // answerByAskId would stop at 'unknown ask' — this spelling pins
        // that the NONCE itself was cleared, and the deny line is visible).
        $this->router->answerApproval('sess_fixture_1', 'ask_9', 'always', ['userId' => 'boss', 'name' => 'B'], $nonce, self::DM_KEY, 'button');
        self::runFor($this->loop, 300);

        self::assertSame(1, $this->serve->respondSeen, 'retirement must clear the binding — the old card is dead');
        self::assertContainsRefusal();
    }

    // ── harness ──

    public function testBootPostureWarnsOnceOnOpenRosterOnly(): void
    {
        $capture = static function (array &$rows): Log {
            return new Log(static function (string $line) use (&$rows): void {
                $rows[] = $line;
            });
        };

        $openRows = [];
        new ConversationRouter($this->bridge, $this->map, $capture($openRows), new TextSafety(), $this->loop, ['approvals' => ['enabled' => true]]);
        $open = \array_values(\array_filter($openRows, static fn (string $r): bool => \str_contains($r, 'approvals_open_roster')));
        self::assertCount(1, $open, 'an enabled approval posture with an empty roster must announce itself exactly once at boot');

        $gatedRows = [];
        new ConversationRouter($this->bridge, $this->map, $capture($gatedRows), new TextSafety(), $this->loop, ['approvals' => ['enabled' => true, 'approvers' => ['boss']]]);
        self::assertSame([], \array_filter($gatedRows, static fn (string $r): bool => \str_contains($r, 'approvals_open_roster')), 'a configured roster is not the open posture — no warning');

        $offRows = [];
        new ConversationRouter($this->bridge, $this->map, $capture($offRows), new TextSafety(), $this->loop, ['approvals' => ['enabled' => false]]);
        self::assertSame([], \array_filter($offRows, static fn (string $r): bool => \str_contains($r, 'approvals_open_roster')), 'buttons-off posture is a presentation choice, not an open-roster boot warning');
    }

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
        $this->router->inbound('discord', self::DM_KEY, ['channelId' => 'chan-9', 'messageId' => $inboundId], $text, $isMention, $inboundId);
        self::runFor($this->loop, 300);
    }

    private function assertContainsRefusal(): void
    {
        foreach ($this->face->delivered as $chunk) {
            if (\str_contains($chunk, \SugarCraft\Code\Lang::t('channels.discord.approval.refused'))) {
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

    public function testEveryRawRestSendSiteCarriesARedactionToken(): void
    {
        // Asserted allowlist (discord-12): every DiscordAccount site that
        // puts channel content on the wire must carry, within a few lines,
        // either @redaction-choke (the text passed through safety->outbound)
        // or @allow-raw-send (an acknowledged fixed-string surface). A new
        // untokenised content send fails this test.
        $root = \dirname(__DIR__, 2) . '/src';
        $sites = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        for ($iterator->rewind(); $iterator->valid(); $iterator->next()) {
            $file = $iterator->current();
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $lines = \file($file->getPathname());
            foreach ($lines as $index => $line) {
                if (\strpos((string) $line, '->rest->send(') === false) {
                    continue;
                }
                $window = \implode("\n", \array_slice($lines, \max(0, $index - 5), 6));
                self::assertMatchesRegularExpression(
                    '/@(?:redaction-choke|allow-raw-send)/',
                    $window,
                    \sprintf('%s:%d sends channel content without a redaction token', $file->getFilename(), $index + 1),
                );
                $sites[] = $file->getFilename() . ':' . ($index + 1);
            }
        }
        self::assertCount(5, $sites, 'the raw-send roster changed shape: ' . \implode(', ', $sites));
    }
}
