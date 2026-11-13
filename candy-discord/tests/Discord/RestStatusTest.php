<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Tests\Discord;

use CandyCode\Bundles\Discord\Discord\Rest;
use CandyCode\Bundles\Discord\Discord\RestException;
use CandyCode\Bundles\Discord\Support\Log;
use CandyCode\Bundles\Discord\Tests\Fixture\FakeDiscordApi;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;

/**
 * F3 (P4 hardening): the status arms of Rest::request() must run on LIVE
 * statuses. Under the react/http default the Browser REJECTS 4xx/5xx before
 * any handler sees the Response, so a real 429 would arrive as an opaque
 * transport error — status 0, 'failed:' prose — and the rate-limit signal
 * would die unhandled behind a message-text match. These pins drive genuine
 * 4xx/5xx status lines through the loopback fake and read the live ->status
 * off the raised RestException; they are red under the rejecting default by
 * construction, which is exactly the latent-dead state they retire.
 */
final class RestStatusTest extends TestCase
{
    private LoopInterface $loop;
    private FakeDiscordApi $api;
    private int $port;

    protected function setUp(): void
    {
        $this->loop = Loop::get();
        $this->api = new FakeDiscordApi($this->loop);
        $this->port = $this->api->listen();
    }

    protected function tearDown(): void
    {
        $this->api->stop();
    }

    public function testAnErrorBodyEchoingTheBotTokenIsLoggedRedacted(): void
    {
        $this->api->failNextWith = 400;
        $this->api->failBody = '{"message":"bad auth Bot SECRET-TOKEN-VALUE"}';
        $lines = [];
        $rest = new Rest('SECRET-TOKEN-VALUE', new Log(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }), 'http://127.0.0.1:' . $this->port);

        $rest->application()->then(null, static function (): void {
        });
        $this->settle();

        $logged = \implode("\n", $lines);
        self::assertStringContainsString('discord_rest_error', $logged);
        self::assertStringNotContainsString('SECRET-TOKEN-VALUE', $logged, 'the bot token must never reach a log line');
        self::assertStringContainsString('[redacted]', $logged);
    }

    public function testAFortyTwoNineSurfacesAsALiveRateLimitAndNeverLoops(): void
    {
        $this->api->failNextWith = 429;

        $error = null;
        $this->rest()->application()->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });
        $this->settle();

        self::assertInstanceOf(RestException::class, $error);
        self::assertSame(429, $error->status, 'the live status must reach the arm, not the rejection wrapper');
        self::assertStringContainsString('discord 429 rate limited', $error->getMessage());
        self::assertCount(1, $this->api->calls, 'surfaced once — nothing behind the handler retries the flood guard');
    }

    public function testAServerErrorSurfacesItsLiveStatus(): void
    {
        $this->api->failNextWith = 503;

        $error = null;
        $this->rest()->application()->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });
        $this->settle();

        self::assertInstanceOf(RestException::class, $error);
        self::assertSame(503, $error->status);
        self::assertStringContainsString('discord 503 on GET /applications/@me', $error->getMessage());
    }

    public function testAFortyOhFourStillSurfacesItsLiveStatusOnDelete(): void
    {
        $this->api->failNextWith = 404;

        $error = null;
        $this->rest()->delete('/applications/app-9/commands/cmd1')->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });
        $this->settle();

        self::assertInstanceOf(RestException::class, $error);
        self::assertSame(404, $error->status);
        self::assertStringContainsString('discord 404 on DELETE /applications/app-9/commands/cmd1', $error->getMessage());
    }

    private function rest(): Rest
    {
        return new Rest('tok', Log::silent(), 'http://127.0.0.1:' . $this->port);
    }

    private function settle(int $ms = 400): void
    {
        $timer = $this->loop->addTimer($ms / 1000.0, function (): void {
            $this->loop->stop();
        });
        $this->loop->run();
        $this->loop->cancelTimer($timer);
    }
}
