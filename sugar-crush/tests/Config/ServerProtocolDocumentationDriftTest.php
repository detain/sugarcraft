<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Serve;
use SugarCraft\Crush\Host\EventLog;
use SugarCraft\Crush\Protocol\Client;
use SugarCraft\Crush\Protocol\Dispatcher;
use SugarCraft\Crush\Protocol\ErrorCode;
use SugarCraft\Crush\Protocol\IdempotencyCache;
use SugarCraft\Crush\Protocol\SessionFeed;
use SugarCraft\Crush\Server\Server;
use SugarCraft\Crush\Server\ServerConfig;
use SugarCraft\Crush\Server\Ws\Outbox;

/**
 * The figures `docs/SERVER.md` states about the `sugarcrush.v1` protocol are
 * the code's (roadmap O-3c). The method and event tables are generated
 * (`ProtocolSchemaDriftTest`); the prose around them — limits, watermarks,
 * close codes, defaults, retention — is written by hand, so each figure is
 * re-derived here from the constant that enforces it.
 */
final class ServerProtocolDocumentationDriftTest extends TestCase
{
    private static string $doc = '';

    public static function setUpBeforeClass(): void
    {
        self::$doc = (string) \file_get_contents(__DIR__ . '/../../docs/SERVER.md');
    }

    public function testTheLimitsTableIsTheCode(): void
    {
        $rows = [
            'maxClientFrameBytes' => self::mib(ServerConfig::MAX_CLIENT_MESSAGE_BYTES),
            'maxServerFrameBytes' => self::mib(Dispatcher::MAX_SERVER_FRAME_BYTES),
            'maxInflight' => (string) Dispatcher::MAX_INFLIGHT,
            'tickIntervalMs' => (string) (int) (Dispatcher::TICK_INTERVAL_SECONDS * 1000),
            'softBufferedBytes` / `maxBufferedBytes' => self::mib(Outbox::SOFT_WATERMARK_BYTES) . ' / ' . self::mib(Outbox::HARD_WATERMARK_BYTES),
            'maxSubscribersPerSession' => (string) SessionFeed::MAX_SUBSCRIBERS,
        ];
        foreach ($rows as $key => $value) {
            self::assertStringContainsString('| `' . $key . '` | ' . $value, self::$doc, $key);
        }
    }

    public function testEveryErrorCodeIsInTheErrorsTable(): void
    {
        foreach (ErrorCode::cases() as $code) {
            self::assertStringContainsString('| `' . $code->value . '` |', self::$doc, $code->name);
        }
    }

    public function testTheHandshakeAndRateFiguresAreTheCode(): void
    {
        self::assertStringContainsString('closed with `' . Dispatcher::CLOSE_NOT_INITIALIZED . '`', self::$doc);
        self::assertStringContainsString('a message over ' . (Dispatcher::MAX_PRE_HELLO_BYTES / 1024) . ' KiB before it closes', self::flat());
        self::assertStringContainsString(\sprintf('%d requests a second, with bursts of %d', (int) Client::RATE_PER_SECOND, (int) Client::RATE_BURST), self::flat());
        self::assertStringContainsString(
            \sprintf('kept %s, at most %s', self::minutes(IdempotencyCache::TTL_SECONDS), \number_format(IdempotencyCache::MAX_ENTRIES)),
            self::flat(),
        );
        self::assertStringContainsString('(up to ' . IdempotencyCache::MAX_KEY_LENGTH . ' characters)', self::flat());
    }

    public function testTheReplayAndBackpressureFiguresAreTheCode(): void
    {
        $flat = self::flat();
        self::assertStringContainsString('(' . EventLog::PAGE_SIZE . ' per loop turn)', $flat);
        self::assertStringContainsString(\number_format(EventLog::DEFAULT_RETAIN) . ' per session', $flat);
        self::assertStringContainsString('past **' . self::mib(Outbox::SOFT_WATERMARK_BYTES) . '** waiting', $flat);
        self::assertStringContainsString('past **' . self::mib(Outbox::HARD_WATERMARK_BYTES) . '**', $flat);
        self::assertSame(10.0, Outbox::OVERFLOW_GRACE_SECONDS, 'the page says "ten seconds later"');
        self::assertStringContainsString('ten seconds later', $flat);
        self::assertStringContainsString('closed with **`' . Outbox::CLOSE_TRY_AGAIN_LATER . '`**', $flat);
        self::assertStringContainsString('at most every ' . (int) SessionFeed::NARRATION_INTERVAL_SECONDS . ' s', $flat);
    }

    public function testTheServerDefaultsAndShutdownFiguresAreTheCode(): void
    {
        $flat = self::flat();
        self::assertStringContainsString('`server.maxConcurrentTurns` (' . ServerConfig::DEFAULT_MAX_CONCURRENT_TURNS . ')', $flat);
        self::assertStringContainsString('`server.maxOpenSessions` (' . ServerConfig::DEFAULT_MAX_OPEN_SESSIONS . ')', $flat);
        self::assertStringContainsString('`server.drainSeconds` (' . (int) ServerConfig::DEFAULT_DRAIN_SECONDS . ' s;', $flat);
        self::assertStringContainsString(
            \sprintf('waits %d s for that — or the configured drain plus %d s', (int) Serve::STOP_DRAIN_SECONDS, (int) Serve::STOP_DRAIN_MARGIN_SECONDS),
            $flat,
        );
        self::assertStringContainsString('closed with `' . Server::CLOSE_CREDENTIALS_ROTATED . '`', $flat);
    }

    /** The page with every line break folded to a space, so a figure may wrap. */
    private static function flat(): string
    {
        return (string) \preg_replace('/\s+/', ' ', self::$doc);
    }

    private static function mib(int $bytes): string
    {
        return ($bytes / 1_048_576) . ' MiB';
    }

    private static function minutes(int $seconds): string
    {
        $words = [5 => 'five'];

        return ($words[\intdiv($seconds, 60)] ?? (string) \intdiv($seconds, 60)) . ' minutes';
    }
}
