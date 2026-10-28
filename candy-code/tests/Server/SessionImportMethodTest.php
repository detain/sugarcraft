<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use Ratchet\RFC6455\Messaging\Frame;
use React\Stream\ThroughStream;
use SugarCraft\Code\Server\Ws\Connection;
use SugarCraft\Code\Server\Ws\MessageHandler;
use SugarCraft\Code\Protocol\Methods\SessionMethods;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;

/**
 * C-SES-7.e — `session.import` over the wire: the C-SES-7.a importer runs
 * in-process against the transcript the request carries, the answer is the
 * new summary, byte-identical resends land on the same session (idempotency
 * is the importer's digest-keyed ledger, not the dispatcher's replay memo),
 * and every refusal is typed.
 *
 * The oversized-content arms sit together here on purpose: the method bound
 * ({@see SessionMethods::IMPORT_MAX_CONTENT_BYTES}) is the typed, in-protocol
 * door, and the transport cap ({@see ServerConfig::MAX_CLIENT_FRAME_BYTES})
 * is the outer one. The fragmented arm proves the outer door cannot be
 * bypassed by splitting the payload across frames — the reassembled total is
 * censused at the read seam, before dispatch, handler-neutral: an oversized
 * import closes with 1009 exactly as any other method would, and the
 * dispatcher never runs.
 */
final class SessionImportMethodTest extends TestCase
{
    private ?ProtocolFixture $fixture = null;

    protected function tearDown(): void
    {
        $this->fixture?->tearDown();
        $this->fixture = null;
        parent::tearDown();
    }

    /**
     * One Claude Code JSONL transcript, three human-visible rows, the body
     * and title carried by $marker (shape pinned upstream in
     * TranscriptReadersTest as of Claude 2.1.x).
     */
    private function markedClaudeTranscript(string $marker): string
    {
        $rows = [
            ['type' => 'user', 'message' => ['role' => 'user', 'content' => 'please plan the ' . $marker . ' migration']],
            ['type' => 'assistant', 'message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'the ' . $marker . ' plan is two phases']]]],
            ['type' => 'assistant', 'message' => ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'name' => 'Bash', 'input' => ['command' => 'ls'], 'id' => 't1']]]],
        ];
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = \json_encode($row + ['timestamp' => '2025-10-01T12:00:00.000Z'], JSON_THROW_ON_ERROR);
        }

        return \implode("\n", $lines);
    }

    /** @param array<string, mixed> $row */
    private static function isNotice(array $row): bool
    {
        return ($row['uiOnly'] ?? false) === true;
    }

    public function testAnImportAnswersTheNewSummaryAndTheSessionLists(): void
    {
        $fixture = $this->fixture = ProtocolFixture::new();
        $client = $fixture->client();

        $summary = $client->call('session.import', ['content' => $this->markedClaudeTranscript('harbour')]);

        self::assertTrue($summary['open'], 'a fresh import opens the session through the hub, like session.create');
        self::assertIsString($summary['id']);
        self::assertSame('', (string) $summary['name'], 'a format with no title law leaves the name empty; the call can supply one');
        self::assertFalse($summary['archived']);
        self::assertFalse($summary['ephemeral']);

        $list = $client->call('session.list', []);
        self::assertSame([$summary['id']], \array_map(static fn (array $row): string => (string) $row['id'], $list['items']));

        // Body search reaches the imported words — the FTS fold ran at save.
        $found = $client->call('session.list', ['query' => 'harbour']);
        self::assertCount(1, $found['items'], 'imported bodies are searchable with no rebuild');
    }

    public function testAByteIdenticalResendLandsOnTheSameSessionAndAppendsNothing(): void
    {
        $fixture = $this->fixture = ProtocolFixture::new();
        $client = $fixture->client();
        $source = $this->markedClaudeTranscript('tundra');

        $first = $client->call('session.import', ['content' => $source]);
        $second = $client->call('session.import', ['content' => $source]);

        self::assertSame($first['id'], $second['id'], 'the digest key IS the idempotency');
        self::assertCount(1, $client->call('session.list', [])['items'], 'one session, not two');
        self::assertSame(['session.created', 'session.updated'], $client->eventTypes(), 'a replay is an update, never a second creation');

        $rows = $client->call('session.get', ['sessionId' => $first['id']])['messages'];
        self::assertSame(1, \count(\array_filter(
            $rows,
            static fn (array $row): bool => self::isNotice($row) && \str_contains((string) ($row['content'] ?? ''), 'untrusted'),
        )), 'the untrusted-material notice rides once (imported tool activity is itself notice-shaped)');
        $third = $client->call('session.import', ['content' => $source]);
        self::assertSame($first['id'], $third['id']);
        self::assertSame(
            \count($rows),
            \count($client->call('session.get', ['sessionId' => $first['id']])['messages']),
            'a replay appends nothing, notice included',
        );
    }

    /**
     * The growth law: the source key is the digest of the FIRST LINE, so a
     * resend that only APPENDS lines is the same source — the ledger turns
     * it into a delta append on the same session. A resend that shrank the
     * source is a typed refusal, never a truncation of the import.
     */
    public function testAGrownResendAppendsOnlyItsDeltaAndAShrunkOneIsRefused(): void
    {
        $fixture = $this->fixture = ProtocolFixture::new();
        $client = $fixture->client();
        $source = $this->markedClaudeTranscript('prairie');
        $grown = $source . "\n" . \json_encode([
            'type' => 'assistant',
            'timestamp' => '2025-10-01T12:05:00.000Z',
            'message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'the follow-up on the prairie plan']]],
        ], JSON_THROW_ON_ERROR);

        $first = $client->call('session.import', ['content' => $source]);
        $second = $client->call('session.import', ['content' => $grown]);

        self::assertSame($first['id'], $second['id'], 'the ledger follows the grown source to the same session');

        $rows = $client->call('session.get', ['sessionId' => $first['id']])['messages'];
        $texts = \implode('|', \array_map(static fn (array $row): string => (string) ($row['content'] ?? ''), $rows));
        self::assertStringContainsString('follow-up on the prairie plan', $texts, 'the delta arrived');
        self::assertSame(1, \substr_count($texts, 'untrusted'), 'the notice was not re-prepended');
        self::assertSame(1, \substr_count($texts, 'two phases'), 'the original rows were not re-appended');

        $shrunk = $client->request('session.import', ['content' => $source]);
        self::assertSame(-32602, $shrunk['error']['code'] ?? null, (string) \json_encode($shrunk));
        self::assertSame('import_refused', $shrunk['error']['data']['kind'] ?? null, 'a shrunken source is refused typed');
    }

    /**
     * The other half of the identity law: a body whose HEAD line changed is
     * a different source and lands in a NEW session — never a mutation of
     * the one the old head already owns.
     */
    public function testAReshapedHeadLineIsANewSourceLandingInANewSession(): void
    {
        $fixture = $this->fixture = ProtocolFixture::new();
        $client = $fixture->client();
        $source = $this->markedClaudeTranscript('prairie');

        $first = $client->call('session.import', ['content' => $source]);
        $rehead = $this->markedClaudeTranscript('mesa');
        $second = $client->call('session.import', ['content' => $rehead]);

        self::assertNotSame($first['id'], $second['id'], 'a different head digest is a different source');
        self::assertCount(2, $client->call('session.list', [])['items']);
    }

    public function testAnExplicitFormatAndNameAreHonoured(): void
    {
        $fixture = $this->fixture = ProtocolFixture::new();
        $client = $fixture->client();

        $summary = $client->call('session.import', [
            'content' => $this->markedClaudeTranscript('beacon'),
            'format' => 'claude-code',
            'name' => 'the keeper log',
        ]);

        self::assertSame('the keeper log', $summary['name'], 'the call name wins over the derived title');

        $wrongFormat = $client->request('session.import', ['content' => $this->markedClaudeTranscript('wrong'), 'format' => 'not-a-format']);
        self::assertSame(-32602, $wrongFormat['error']['code'] ?? null, (string) \json_encode($wrongFormat));
    }

    public function testAMalformedBodyIsRefusedTypedAndNothingIsHalfWritten(): void
    {
        $fixture = $this->fixture = ProtocolFixture::new();
        $client = $fixture->client();

        $answer = $client->request('session.import', ['content' => 'this is not any transcript shape at all']);

        self::assertSame(-32602, $answer['error']['code'] ?? null, (string) \json_encode($answer));
        self::assertSame('import_refused', $answer['error']['data']['kind'] ?? null, 'the importer refusal never leaks as an internal error');
        self::assertSame([], $client->call('session.list', [])['items'], 'and nothing was half-written');
    }

    public function testContentPastTheMethodBoundIsRefusedTypedBeforeTheImporterRuns(): void
    {
        $fixture = $this->fixture = ProtocolFixture::new();
        $client = $fixture->client();

        // The bound is under the wire cap by design; the params door answers
        // in-protocol so a client learns WHICH limit it hit.
        $answer = $client->request('session.import', ['content' => \str_repeat('x', SessionMethods::IMPORT_MAX_CONTENT_BYTES + 1)]);

        self::assertSame(-32602, $answer['error']['code'] ?? null, (string) \json_encode($answer));
        self::assertSame([], $client->call('session.list', [])['items']);
    }

    public function testTheImportBoundIsTheVerifiedFrameCapLessHeadroom(): void
    {
        // Doc-block truth: the method bound mirrors the transport ceiling
        // this lane measured (the reassembled inbound cap lives at the read
        // seam, not in WsServer's loop), and it never exceeds the cap it
        // mirrors — the wire can carry no larger payload anyway.
        self::assertSame(
            ServerConfig::MAX_CLIENT_FRAME_BYTES - SessionMethods::EXPORT_FRAME_HEADROOM_BYTES,
            SessionMethods::IMPORT_MAX_CONTENT_BYTES,
        );
        self::assertLessThan(ServerConfig::MAX_CLIENT_FRAME_BYTES, SessionMethods::IMPORT_MAX_CONTENT_BYTES);
    }

    /**
     * The CONFIRM leg of the inbound-cap question: the read seam feeds one
     * shared reassembler into dispatch, and a message split into two legal
     * fragments whose TOTAL exceeds the cap closes with 1009 the way the
     * whole-frame oversized message does (pinned end-to-end by
     * WsUpgradeTest against a real socket). No method — import included —
     * bypasses it, because the seam does not know what a method is.
     */
    public function testFragmentedOversizedContentCloses1009BeforeAnyHandlerSeesIt(): void
    {
        $handler = new class () implements MessageHandler {
            /** @var list<string> */
            public array $payloads = [];

            public function onOpen(Connection $connection): void
            {
            }

            public function onMessage(Connection $connection, string $payload): void
            {
                $this->payloads[] = $payload;
            }

            public function onClose(Connection $connection, int $code): void
            {
            }
        };

        $toClient = new ThroughStream();
        $wire = '';
        $toClient->on('data', static function (string $chunk) use (&$wire): void {
            $wire .= $chunk;
        });
        $fromClient = new ThroughStream();
        new Connection('import-frag-c', 'bearer', $toClient, $fromClient, $handler, ServerConfig::MAX_CLIENT_FRAME_BYTES);

        $body = '{"jsonrpc":"2.0","id":1,"method":"session.import","params":{"content":"' . \str_repeat('x', ServerConfig::MAX_CLIENT_FRAME_BYTES + 4) . '"}}';
        $half = \intdiv(\strlen($body), 2);
        // Fragment 1: FIN=0, opcode TEXT. Fragment 2: FIN=1, opcode CONT,
        // pushing the TOTAL (16,777,234 bytes) past the cap. Each fragment
        // alone is legal — only the reassembled size may sentence it.
        $fromClient->write(self::maskedFrame(0x1, false, \substr($body, 0, $half)));
        $fromClient->write(self::maskedFrame(0x0, true, \substr($body, $half)));

        self::assertSame(1009, self::wireCloseCode($wire), 'CLOSE_TOO_BIG answers the TOTAL, not the fragment');
        self::assertSame([], $handler->payloads, 'and no method ran — the seam is handler-neutral');
    }

    /** One masked WS frame with correct short/16-bit/64-bit length fields. */
    private static function maskedFrame(int $opcode, bool $fin, string $payload): string
    {
        $length = \strlen($payload);
        $head = \chr(($fin ? 0x80 : 0x00) | $opcode);
        if ($length <= 125) {
            $head .= \chr(0x80 | $length);
        } elseif ($length <= 0xFFFF) {
            $head .= \chr(0x80 | 126) . \pack('n', $length);
        } else {
            $head .= \chr(0x80 | 127) . \pack('J', $length);
        }

        $key = \random_bytes(4);
        $mask = \substr(\str_repeat($key, intdiv($length, 4) + 1), 0, $length);

        return $head . $key . ($payload ^ $mask);
    }

    /** The close code of the server frame in $wire, else 0. */
    private static function wireCloseCode(string $wire): int
    {
        if (\strlen($wire) < 4 || (\ord($wire[0]) & 0x0f) !== Frame::OP_CLOSE) {
            return 0;
        }

        return (int) \unpack('n', \substr($wire, 2, 2))[1];
    }
}
