<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Tests\MsTeams;

use CandyCode\Bundles\MsTeams\MsTeams\RestException;
use CandyCode\Bundles\MsTeams\MsTeams\TokenBroker;
use CandyCode\Bundles\MsTeams\Support\Log;
use CandyCode\Bundles\MsTeams\Tests\Fixture\FakeTeamsApi;
use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\Loop;

/**
 * §8.2: the client_credentials exchange against the configurable auth base,
 * exercised over a loopback fake — mint, cache, in-flight dedupe, forced
 * re-mint, loud refusal. Never a real token endpoint.
 */
final class TokenBrokerTest extends TestCase
{
    private LoopInterface $loop;

    private FakeTeamsApi $api;

    private string $base = '';

    protected function setUp(): void
    {
        $this->loop = Loop::get();
        $this->api = new FakeTeamsApi($this->loop);
        $this->base = 'http://127.0.0.1:' . $this->api->listen();
    }

    protected function tearDown(): void
    {
        $this->api->stop();
    }

    public function testTheExchangeMintsAndRidesTheV2Form(): void
    {
        $broker = new TokenBroker(['appId' => 'a-1', 'appPassword' => 'shh', 'tenantId' => 't-9'], Log::silent(), $this->base);
        $token = null;
        $broker->token()->then(static function (string $t) use (&$token): void {
            $token = $t;
        });
        self::runFor($this->loop, 400);

        self::assertSame('bot-tok-1', $token);
        $call = $this->api->lastCallFor('/oauth2/v2.0/token', 'POST');
        self::assertIsArray($call);
        self::assertStringContainsString('grant_type=client_credentials', $call['body']);
        self::assertStringContainsString('client_id=a-1', $call['body']);
        self::assertStringContainsString('scope=api.botframework.com%2F.default', $call['body']);
        self::assertTrue($broker->hasFreshToken());
    }

    public function testTheSecondCallReusesTheCacheWithoutADial(): void
    {
        $broker = new TokenBroker(['appId' => 'a', 'appPassword' => 'p', 'tenantId' => 't'], Log::silent(), $this->base);
        $broker->token();
        $broker->token();
        self::runFor($this->loop, 400);

        self::assertSame(1, $this->api->tokensMinted);
    }

    public function testConcurrentCallsShareOneExchange(): void
    {
        $broker = new TokenBroker(['appId' => 'a', 'appPassword' => 'p', 'tenantId' => 't'], Log::silent(), $this->base);
        $first = $broker->token();
        $second = $broker->token();
        self::assertSame($first, $second, 'in-flight dedupe must hand out the same promise');
        self::runFor($this->loop, 400);
        self::assertSame(1, $this->api->tokensMinted);
    }

    public function testInvalidationForcesAFreshExchange(): void
    {
        $broker = new TokenBroker(['appId' => 'a', 'appPassword' => 'p', 'tenantId' => 't'], Log::silent(), $this->base);
        $broker->token();
        self::runFor($this->loop, 400);
        $broker->invalidate();
        self::assertFalse($broker->hasFreshToken());
        $broker->token();
        self::runFor($this->loop, 400);

        self::assertSame(2, $this->api->tokensMinted);
    }

    public function testARefusedExchangeRejectsWithoutEchoingTheSecret(): void
    {
        $broker = new TokenBroker(['appId' => 'a', 'appPassword' => 'super-secret-pw', 'tenantId' => 't'], Log::silent(), $this->base);
        $this->api->failNextWith = 401;
        $error = null;
        $broker->token()->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });
        self::runFor($this->loop, 400);

        self::assertInstanceOf(RestException::class, $error);
        self::assertStringNotContainsString('super-secret-pw', $error->getMessage());
        self::assertFalse($broker->hasFreshToken());
    }

    private static function runFor(LoopInterface $loop, int $ms): void
    {
        $timer = $loop->addTimer($ms / 1000.0, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();
        $loop->cancelTimer($timer);
    }
}
