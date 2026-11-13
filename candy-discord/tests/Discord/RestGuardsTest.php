<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Tests\Discord;

use CandyCode\Bundles\Discord\Discord\Rest;
use CandyCode\Bundles\Discord\Discord\RestException;
use CandyCode\Bundles\Discord\Support\Log;
use CandyCode\Bundles\Discord\Tests\Fixture\FakeDiscordApi;
use CandyCode\Bundles\Discord\Tests\Fixture\FakeMediaCdn;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;

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
        $rest->fetchBinary('http://media.discordapp.net/attachments/1/2.bin', 1048576)
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
        $rest->fetchBinary('https://evil.example/p?token=s3cret', 1048576)
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

    public function testADeclaredLengthOverBudgetRejectsWithZeroBodyReads(): void
    {
        $loop = Loop::get();
        $cdn = new FakeMediaCdn($loop);
        $cdn->declaredLength = 50 * 1048576;
        $cdn->streamBytes = 50 * 1048576;
        $cdn->streamChunkBytes = 8192;
        $cdn->streamDelayMs = 150;
        $rest = new Rest('tok', Log::silent(), 'http://127.0.0.1:1', $cdn->connector());
        $error = null;
        $rest->fetchBinary('https://media.discordapp.net/attachments/1/2.bin?signed=s3cretq', 1048576)
            ->then(null, static function (\Throwable $e) use (&$error): void {
                $error = $e;
            });
        self::runUntil(static fn (): bool => $error !== null, 1500, $loop);
        $cdn->stop();
        self::assertInstanceOf(RestException::class, $error);
        self::assertStringContainsString('exceeds maximum', (string) $error->getMessage());
        self::assertStringNotContainsString('s3cretq', (string) $error->getMessage(), 'signed query stays unechoed');
        self::assertSame(0, $cdn->bodyBytesSent, 'a declared oversize must reject before consuming any body');
    }

    public function testALyingStreamWithoutContentLengthAbortsJustPastTheCap(): void
    {
        $loop = Loop::get();
        $cdn = new FakeMediaCdn($loop);
        $cdn->declaredLength = null;
        $cdn->streamBytes = 8 * 1048576;
        $cdn->streamChunkBytes = 8192;
        $cap = 1048576;
        $rest = new Rest('tok', Log::silent(), 'http://127.0.0.1:1', $cdn->connector());
        $error = null;
        $rest->fetchBinary('https://media.discordapp.net/attachments/1/2.bin', $cap)
            ->then(null, static function (\Throwable $e) use (&$error): void {
                $error = $e;
            });
        self::runUntil(static fn (): bool => $error !== null, 3000, $loop);
        $cdn->stop();
        self::assertInstanceOf(RestException::class, $error);
        self::assertStringContainsString('exceeds maximum', (string) $error->getMessage());
        self::assertLessThanOrEqual($cap + 2 * $cdn->streamChunkBytes, $cdn->bodyBytesSent,
            'the abort must be bounded: cap plus at most a chunk in flight, not the whole ocean');
    }

    public function testAStreamedBodyWithinBudgetResolvesWithItsExactBytes(): void
    {
        $loop = Loop::get();
        $cdn = new FakeMediaCdn($loop);
        $cdn->declaredLength = 4096;
        $cdn->streamBytes = 4096;
        $cdn->streamChunkBytes = 1024;
        $rest = new Rest('tok', Log::silent(), 'http://127.0.0.1:1', $cdn->connector());
        $bytes = null;
        $rest->fetchBinary('https://media.discordapp.net/attachments/1/2.bin', 1048576)
            ->then(static function (string $body) use (&$bytes): void {
                $bytes = $body;
            });
        self::runUntil(static fn (): bool => $bytes !== null, 1500, $loop);
        $cdn->stop();
        self::assertSame(\str_repeat('x', 4096), $bytes);
    }

    /** Pump the loop until $done or the millisecond deadline; false = timed out. */
    private static function runUntil(callable $done, int $ms, LoopInterface $loop): bool
    {
        $deadline = \microtime(true) + $ms / 1000.0;
        while (!$done() && \microtime(true) < $deadline) {
            $loop->addTimer(0.01, static function () use ($loop): void {
                $loop->stop();
            });
            $loop->run();
        }

        return $done();
    }
}
