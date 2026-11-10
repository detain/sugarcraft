<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Tests\MsTeams;

use CandyCode\Bundles\MsTeams\MsTeams\Rest;
use CandyCode\Bundles\MsTeams\MsTeams\RestException;
use CandyCode\Bundles\MsTeams\MsTeams\TokenBroker;
use CandyCode\Bundles\MsTeams\Support\Log;
use CandyCode\Bundles\MsTeams\Tests\Fixture\FakeTeamsApi;
use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\Loop;

/**
 * §8.6 transport floors for the outbound leg: serviceUrl is activity data
 * and must pass the https door before a byte leaves; the media allowlist
 * names the P3.5 deferral out loud; 429 surfaces, 401 invalidates the
 * cached token — and no leg here loops or retries.
 */
final class RestGuardsTest extends TestCase
{
    private LoopInterface $loop;

    private FakeTeamsApi $api;

    protected function setUp(): void
    {
        $this->loop = Loop::get();
        $this->api = new FakeTeamsApi($this->loop);
        $this->api->listen();
    }

    protected function tearDown(): void
    {
        $this->api->stop();
    }

    public function testAPlainHttpRemoteServiceUrlRefusesWithoutAnyDial(): void
    {
        $rest = $this->rest();
        $error = null;
        $rest->sendActivity('http://not-allowed.example', 'conv-1', ['type' => 'message'])->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });
        self::runFor($this->loop, 50);

        self::assertInstanceOf(RestException::class, $error);
        self::assertStringContainsString('https required', (string) $error->getMessage());
        self::assertStringContainsString('not-allowed.example', (string) $error->getMessage());
        self::assertSame([], $this->api->calls, 'a refused serviceUrl must not even mint a token');
    }

    public function testLoopbackHttpPassesOnlyWithTheOptIn(): void
    {
        $url = 'http://127.0.0.1:' . $this->api->port;

        $strict = $this->rest();
        $error = null;
        $strict->sendActivity($url, 'c', ['type' => 'message'])->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });
        self::runFor($this->loop, 50);
        self::assertInstanceOf(RestException::class, $error);
        self::assertStringContainsString('insecureLoopbackProxy', (string) $error->getMessage());

        $opted = $this->rest(insecure: true);
        $sent = null;
        $opted->sendActivity($url, 'c', ['type' => 'message', 'text' => 'hi'])->then(static function (array $r) use (&$sent): void {
            $sent = $r;
        });
        self::runFor($this->loop, 800);
        self::assertSame('act_out_1', $sent['id'] ?? null, 'the opted-in loopback post must land');
        $call = $this->api->lastCallFor('/v3/conversations/c/activities', 'POST');
        self::assertIsArray($call);
        self::assertSame('Bearer bot-tok-1', $call['auth'], 'the bot token rides every activity post');
    }

    public function testARateLimitSurfacesOnceAndNeverLoops(): void
    {
        $rest = $this->rest(insecure: true);
        $url = 'http://127.0.0.1:' . $this->api->port;
        $rest->sendActivity($url, 'c', ['type' => 'message', 'text' => 'warm'])->then(null, static function (): void {
        });
        self::runFor($this->loop, 800);
        $this->api->failNextWith = 429;

        $error = null;
        $rest->sendActivity($url, 'c', ['type' => 'message', 'text' => 'throttled'])->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });
        self::runFor($this->loop, 300);

        self::assertInstanceOf(RestException::class, $error);
        self::assertStringContainsString('429', (string) $error->getMessage());
        $posts = \count(\array_filter($this->api->calls, static fn (array $c): bool => \str_contains($c['path'], '/activities')));
        self::assertSame(2, $posts, 'obey-once posture: exactly one attempt per call, no retry loop');
    }

    public function testAStaleTokenResponseInvalidatesTheCache(): void
    {
        $rest = $this->rest(insecure: true);
        $url = 'http://127.0.0.1:' . $this->api->port;
        $rest->sendActivity($url, 'c', ['type' => 'message', 'text' => 'first'])->then(null, static function (): void {
        });
        self::runFor($this->loop, 800);
        $this->api->failNextWith = 401;

        $rest->sendActivity($url, 'c', ['type' => 'message', 'text' => 'stale'])->then(null, static function (): void {
        });
        self::runFor($this->loop, 300);

        self::assertFalse($this->broker->hasFreshToken(), 'a 401 must drop the cached bot token');
    }

    public function testGraphHostedMediaRefusesWithTheDisclosedP35Prose(): void
    {
        $rest = $this->rest(insecure: true);
        $error = null;
        $rest->fetchBinary('https://tenant-my.sharepoint.com/personal/x/Documents/file.pdf')->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });

        self::assertInstanceOf(RestException::class, $error);
        self::assertStringContainsString('deferred to P3.5', (string) $error->getMessage());
        self::assertStringContainsString('tenant-my.sharepoint.com', (string) $error->getMessage());
    }

    public function testPlatformHostedAndLoopbackMediaFetch(): void
    {
        $rest = $this->rest(insecure: true);
        $bytes = null;
        $rest->fetchBinary('http://127.0.0.1:' . $this->api->port . '/media/pic.png')->then(static function (string $b) use (&$bytes): void {
            $bytes = $b;
        });
        self::runFor($this->loop, 800);
        self::assertSame('FAKEPNGBYTES', $bytes);

        // The signed-query law: a refusal names the PATH/host, never the query.
        $error = null;
        $rest->fetchBinary('https://botframework.com.somewhatbutno.example/pic?sig=bearerish')->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });
        self::assertInstanceOf(RestException::class, $error);
        self::assertStringNotContainsString('bearerish', $error->getMessage());
    }

    private ?TokenBroker $broker = null;

    private function rest(bool $insecure = false): Rest
    {
        $this->broker = new TokenBroker(
            ['appId' => 'a', 'appPassword' => 'p', 'tenantId' => 't'],
            Log::silent(),
            'http://127.0.0.1:' . $this->api->port,
        );

        return new Rest($this->broker, Log::silent(), $insecure);
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
