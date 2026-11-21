<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Protocol\CallContext;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\MethodSpec;
use SugarCraft\Code\Protocol\Params;
use SugarCraft\Code\Protocol\RpcError;
use SugarCraft\Code\Protocol\Scope;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;

/**
 * P4-12 CC-1 row f: a method may answer with a promise (a `bg.spawn` launch
 * waits on its daemon's handshake). The answer goes out when the promise
 * settles, a refusal leaves as its RpcError, an unexpected failure is logged
 * and answered as `internal`, and an idempotent retry that lands while the
 * first is still pending shares that answer instead of running the method
 * a second time.
 */
final class DispatcherPromiseAnswerTest extends TestCase
{
    /** @var list<Deferred<mixed>> one per run of the test method, in the order the calls arrived */
    private array $runs = [];

    /** @var list<string> */
    private array $logged = [];

    private ?ProtocolFixture $served = null;

    protected function tearDown(): void
    {
        // The fixture's dispatcher arms timers on the shared loop; stopping it
        // here hands the loop back empty, as every dispatcher suite does.
        $this->served?->tearDown();
        parent::tearDown();
    }

    private function fixture(): ProtocolFixture
    {
        $fixture = $this->served = ProtocolFixture::new(log: function (string $line): void {
            $this->logged[] = $line;
        });
        $fixture->dispatcher->registry()->add(MethodSpec::new(
            'test.defer',
            Scope::Write,
            'answers when the test releases it',
            function (CallContext $call, Params $params): PromiseInterface {
                $deferred = new Deferred();
                $this->runs[] = $deferred;

                return $deferred->promise();
            },
            true,
        ));

        return $fixture;
    }

    public function testADeferredAnswerGoesOutWhenItSettles(): void
    {
        $fixture = $this->fixture();
        $client = $fixture->client('defer-ok');

        $client->raw('{"jsonrpc":"2.0","id":"d1","method":"test.defer","params":{}}');
        self::assertNull($client->response('d1'), 'nothing is answered while the method is pending');

        $this->runs[0]->resolve(['answer' => 42]);
        $fixture->run(0.01);

        self::assertSame(['answer' => 42], $client->response('d1')['result'] ?? null);
    }

    public function testARefusalFromADeferredAnswerLeavesAsItsRpcError(): void
    {
        $fixture = $this->fixture();
        $client = $fixture->client('defer-refused');

        $client->raw('{"jsonrpc":"2.0","id":"d2","method":"test.defer","params":{}}');
        $this->runs[0]->reject(RpcError::notFound('no such thing', 'thing_not_found'));
        $fixture->run(0.01);

        $answer = $client->response('d2');
        self::assertSame(ErrorCode::NotFound->value, $answer['error']['code'] ?? null, (string) json_encode($answer));
        self::assertSame('thing_not_found', $answer['error']['data']['kind'] ?? null);
    }

    public function testAnUnexpectedRejectionIsAnInternalErrorWhoseCauseIsLoggedNotSent(): void
    {
        $fixture = $this->fixture();
        $client = $fixture->client('defer-boom');

        $client->raw('{"jsonrpc":"2.0","id":"d3","method":"test.defer","params":{}}');
        $this->runs[0]->reject(new \RuntimeException('the path /home/owner/secret broke'));
        $fixture->run(0.01);

        $answer = $client->response('d3');
        self::assertSame('internal error', $answer['error']['message'] ?? null);
        self::assertStringNotContainsString('secret', (string) json_encode($answer), 'the cause stays off the wire');
        self::assertCount(1, $this->logged, 'the cause reaches the server log once');
        self::assertStringContainsString('the path /home/owner/secret broke', $this->logged[0]);
    }

    public function testARetryThatLandsWhileTheFirstIsPendingSharesItsAnswer(): void
    {
        $fixture = $this->fixture();
        $client = $fixture->client('defer-retry');

        $client->raw('{"jsonrpc":"2.0","id":"pend-1","method":"test.defer","params":{"idempotencyKey":"k-pending"}}');
        $client->raw('{"jsonrpc":"2.0","id":"pend-2","method":"test.defer","params":{"idempotencyKey":"k-pending"}}');
        self::assertCount(1, $this->runs, 'a pending retry must not start the method a second time');

        $this->runs[0]->resolve(['bgId' => 'sess_one']);
        $fixture->run(0.01);

        self::assertSame(['bgId' => 'sess_one'], $client->response('pend-1')['result'] ?? null);
        self::assertSame(['bgId' => 'sess_one'], $client->response('pend-2')['result'] ?? null);
    }

    public function testAFailedIdempotentCallIsNotRememberedSoItsRetryRunsAgain(): void
    {
        $fixture = $this->fixture();
        $client = $fixture->client('defer-fail-retry');

        $client->raw('{"jsonrpc":"2.0","id":"f1","method":"test.defer","params":{"idempotencyKey":"k-fail"}}');
        $this->runs[0]->reject(RpcError::of(ErrorCode::Internal, 'the launch failed', 'launch_failed'));
        $fixture->run(0.01);

        $client->raw('{"jsonrpc":"2.0","id":"f2","method":"test.defer","params":{"idempotencyKey":"k-fail"}}');
        self::assertCount(2, $this->runs, 'a refused call leaves its key free for the retry');
        $this->runs[1]->resolve(['bgId' => 'sess_retry']);
        $fixture->run(0.01);

        self::assertSame(['bgId' => 'sess_retry'], $client->response('f2')['result'] ?? null);
    }
}
