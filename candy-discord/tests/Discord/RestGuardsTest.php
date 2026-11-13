<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Tests\Discord;

use CandyCode\Bundles\Discord\Discord\Rest;
use CandyCode\Bundles\Discord\Discord\RestException;
use CandyCode\Bundles\Discord\Support\Log;
use CandyCode\Bundles\Discord\Tests\Fixture\FakeDiscordApi;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;

/**
 * The media-fetch trust boundary (§7.8): fetchBinary refuses anything
 * that is not https on a Discord media host BEFORE touching the network,
 * and names only the host in the refusal — a signed CDN query is a
 * bearer secret and never echoes. Plain http stays legal for the API
 * base itself so loopback fixtures can drive the send paths.
 */
final class RestGuardsTest extends TestCase
{
    public function testAPlainHttpMediaUrlIsRefusedBeforeAnyConnection(): void
    {
        $rest = new Rest('tok', Log::silent(), 'http://127.0.0.1:1');
        $error = null;
        $rest->fetchBinary('http://media.discordapp.net/attachments/1/2.bin')
            ->then(null, static function (\Throwable $e) use (&$error): void {
                $error = $e;
            });
        self::assertInstanceOf(\Throwable::class, $error);
        self::assertStringContainsString('media host required', $error->getMessage());
    }

    public function testAForeignHttpsHostIsRefusedAndItsSignedQueryNeverEchoes(): void
    {
        $rest = new Rest('tok', Log::silent(), 'http://127.0.0.1:1');
        $error = null;
        $rest->fetchBinary('https://evil.example/p?token=s3cret')
            ->then(null, static function (\Throwable $e) use (&$error): void {
                $error = $e;
            });
        self::assertInstanceOf(\Throwable::class, $error);
        self::assertStringContainsString('evil.example', $error->getMessage());
        self::assertStringNotContainsString('s3cret', $error->getMessage());
    }

    public function testTheApiBaseItselfAcceptsLoopbackHttpForFixtureDrives(): void
    {
        $loop = Loop::get();
        $api = new FakeDiscordApi($loop);
        $port = $api->listen();
        $rest = new Rest('tok', Log::silent(), 'http://127.0.0.1:' . $port);
        $application = null;
        $rest->application()->then(static function (array $row) use (&$application): void {
            $application = $row;
        });
        $deadline = microtime(true) + 3.0;
        while ($application === null && microtime(true) < $deadline) {
            $loop->addTimer(0.02, static function () use ($loop): void {
                $loop->stop();
            });
            $loop->run();
        }
        $api->stop();
        self::assertSame(['id' => 'app-9'], $application);
    }

    public function testRestExceptionIsFinalSoTheFailureShapeCannotBeSubclassed(): void
    {
        // The repo rule closes the exception vocabulary: public classes are
        // final unless extension is a contract, and nothing in the bundle
        // (or its tests) extends RestException — the finding's grep.
        self::assertTrue((new \ReflectionClass(RestException::class))->isFinal());
    }
}
