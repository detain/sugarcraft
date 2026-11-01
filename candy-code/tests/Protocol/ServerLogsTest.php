<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Protocol\CallContext;
use SugarCraft\Code\Protocol\Client;
use SugarCraft\Code\Protocol\Dispatcher;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\Request;
use SugarCraft\Code\Protocol\RpcError;
use SugarCraft\Code\Protocol\Scope;
use SugarCraft\Code\Server\LogTail;
use SugarCraft\Code\Server\Ws\Outbox;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Tests\Server\Support\WireClient;

/**
 * C-WEB-5.f: the `server.logs` wire door. Log lines quote prompts and paths,
 * so the whole design is one gate plus two caps: Scope::Admin is the ONLY
 * principal that ever sees them (owner ruling, plan §6.1 R13 — ErrorLog's
 * never-onto-the-wire law holds everywhere else), every answer is flattened
 * and clipped through {@see LogTail}, and the answer stops at a line count
 * and a byte total that keep the newest tail and trim the oldest head.
 *
 * The scope-refusal leg runs the same requireScope choke the Dispatcher
 * runs before any handler ({@see ShellsInputTest}'s shape): a wire client
 * today is always owner-scoped at open, so the refusal is pinned at the
 * door the wire actually enforces, not at the hello.
 */
final class ServerLogsTest extends TestCase
{
    private ProtocolFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ProtocolFixture::new();
    }

    protected function tearDown(): void
    {
        $this->fixture->tearDown();
        unset($this->fixture);
    }

    public function testTheRosterGatesTheLogTailAtAdminScopeWithoutSideEffects(): void
    {
        $spec = Dispatcher::methods()->get('server.logs');
        self::assertNotNull($spec, 'server.logs is on the roster');
        self::assertSame(Scope::Admin, $spec->scope, 'log lines can quote prompts and paths; admin is the only door (R13)');
        self::assertFalse($spec->sideEffects, 'reading the tail changes nothing the server does');
    }

    public function testAClientWithoutAdminScopeIsRefusedAtTheScopeChoke(): void
    {
        $wire = WireClient::open($this->fixture->dispatcher, 'limited');
        $wire->hello();
        $writeOnly = new Client($wire->connection, Outbox::for($wire->connection), [Scope::Write->value], static fn (): float => 0.0);
        $call = new CallContext($writeOnly, new Request(1, true, 'server.logs', []), $this->fixture->context, $this->fixture->dispatcher->registry());

        try {
            $call->requireScope($this->scopeOf('server.logs'));
            self::fail('a write-scoped client must not stand at the log door');
        } catch (RpcError $error) {
            self::assertSame(ErrorCode::Forbidden, $error->errorCode);
            self::assertSame(-32003, $error->errorCode->value);
            self::assertSame('forbidden', $error->kind);
            self::assertSame('this needs the admin scope', $error->getMessage());
        }
    }

    public function testAForegroundServerWithNoLogAnswersEmptyWithTheReason(): void
    {
        $client = $this->fixture->client();

        // The fresh fixture state dir carries no server.log — exactly the
        // foreground launch that never wrote one.
        self::assertSame(['lines' => [], 'reason' => LogTail::REASON_NO_LOG], $client->call('server.logs'));
    }

    public function testTheAnswerCarriesTheFlattenedLastLines(): void
    {
        \file_put_contents(
            $this->fixture->dir . '/server.log',
            "first\nalpha\x01\x1fbeta\n" . \str_repeat('x', 300) . "\n",
        );
        $client = $this->fixture->client();

        $result = $client->call('server.logs', ['lines' => 2]);
        self::assertSame([
            'lines' => ['alpha beta', \str_repeat('x', 200)],
            'reason' => null,
        ], $result, 'control runs collapse and each line clips to the ErrorLog bound');
    }

    public function testTheByteCapTrimsTheOldestLinesAndKeepsTheNewest(): void
    {
        $lines = [];
        for ($index = 1; $index <= 200; ++$index) {
            $lines[] = \sprintf('%04d|', $index) . \str_repeat('z', 160);
        }
        \file_put_contents($this->fixture->dir . '/server.log', \implode("\n", $lines) . "\n");
        $client = $this->fixture->client();

        $result = $client->call('server.logs', ['lines' => 200]);
        $kept = $result['lines'];

        self::assertCount(200, $lines, 'the window covers every written line');
        self::assertLessThan(200, \count($kept), 'the byte cap must bind before the line cap');
        self::assertLessThanOrEqual(LogTail::ANSWER_MAX_BYTES, \strlen(\implode("\n", $kept)));
        self::assertSame($lines[199], $kept[\count($kept) - 1], 'the newest line always survives');
        self::assertGreaterThan('0001|', $kept[0], 'the trim takes from the oldest end');
    }

    public function testLinesOutsideTheWindowIsInvalidParams(): void
    {
        $client = $this->fixture->client();

        $response = $client->request('server.logs', ['lines' => 0]);
        self::assertArrayHasKey('error', $response);
        self::assertSame(-32602, $response['error']['code']);
        self::assertStringContainsString('lines', $response['error']['message']);
    }

    private function scopeOf(string $method): Scope
    {
        $spec = Dispatcher::methods()->get($method);
        self::assertNotNull($spec);

        return $spec->scope;
    }
}
