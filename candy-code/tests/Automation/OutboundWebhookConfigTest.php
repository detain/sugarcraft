<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Automation;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Automation\OutboundWebhookConfig;
use SugarCraft\Code\Automation\WebhookEvent;

/**
 * C-AGT-5.e: the user-tier parser for `webhooksOut`. The law under test is
 * the fail-closed posture every violation is measured against: ANY malformed
 * entry throws, naming the endpoint, and NOTHING degraded is ever returned
 * — a half-parsed webhook map is an unaudited outbound door.
 *
 * The url polarity pair (https everywhere; http only to loopback LITERALS)
 * is the containment seam, so both halves are pinned: the accepted loopback
 * spellings AND the refused look-alikes (`localhost.evil.test` must not ride
 * the loopback exception, `128.x` must not count as 127/8).
 */
final class OutboundWebhookConfigTest extends TestCase
{
    private const GOOD_SECRET = 'a-long-enough-secret-value';

    public function testAWellFormedMapParsesToTheTypedShape(): void
    {
        $parsed = OutboundWebhookConfig::parse([
            'deploy' => ['url' => 'https://hooks.example.test/ci', 'secret' => self::GOOD_SECRET, 'events' => [WebhookEvent::TYPE_TURN_COMPLETED]],
            'local' => ['url' => 'http://127.0.0.1:8080/hook', 'secret' => self::GOOD_SECRET, 'events' => [WebhookEvent::TYPE_SESSION_ENDED, WebhookEvent::TYPE_SCHEDULE_FIRED]],
        ]);

        self::assertSame(['deploy', 'local'], array_keys($parsed));
        self::assertSame(['url', 'secret', 'events'], array_keys($parsed['deploy']));
        self::assertSame('https://hooks.example.test/ci', $parsed['deploy']['url']);
        self::assertSame([WebhookEvent::TYPE_TURN_COMPLETED], $parsed['deploy']['events']);
        self::assertCount(2, $parsed['local']['events']);
    }

    public function testAnEmptyMapIsValidAndMeansTheFeatureIsOff(): void
    {
        self::assertSame([], OutboundWebhookConfig::parse([]));
    }

    public function testDuplicateEventsCollapseKeepingFirstSeenOrder(): void
    {
        $parsed = OutboundWebhookConfig::parse([
            'dupe' => ['url' => 'https://a.test/h', 'secret' => self::GOOD_SECRET, 'events' => ['session.ended', 'turn.completed', 'session.ended']],
        ]);

        self::assertSame(['session.ended', 'turn.completed'], $parsed['dupe']['events']);
    }

    /**
     * @dataProvider loopbackHttpHosts
     */
    public function testPlainHttpIsAcceptedOnlyForLoopbackLiterals(string $host): void
    {
        $parsed = OutboundWebhookConfig::parse([
            'dev' => ['url' => "http://$host/hook", 'secret' => self::GOOD_SECRET, 'events' => ['turn.completed']],
        ]);

        self::assertSame("http://$host/hook", $parsed['dev']['url']);
    }

    /** @return array<string,array{string}> */
    public static function loopbackHttpHosts(): array
    {
        return [
            'localhost' => ['localhost'],
            'LOCALHOST case' => ['LOCALHOST'],
            'loopback v4 literal' => ['127.0.0.1'],
            'whole 127/8 block' => ['127.0.0.9'],
            'v6 compressed' => ['[::1]'],
            'v6 full spelling' => ['[0:0:0:0:0:0:0:1]'],
        ];
    }

    /**
     * @dataProvider refusedUrls
     */
    public function testTheRefusedUrlShapesThrowNamingTheEndpoint(string $url, string $needle): void
    {
        try {
            OutboundWebhookConfig::parse(['nope' => ['url' => $url, 'secret' => self::GOOD_SECRET, 'events' => ['turn.completed']]]);
            self::fail("expected a refusal for url \"$url\"");
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('nope', $e->getMessage());
            self::assertStringContainsString($needle, $e->getMessage());
        }
    }

    /** @return array<string,array{string,string}> */
    public static function refusedUrls(): array
    {
        return [
            'plain http to a public host' => ['http://hooks.example.test/ci', 'plain http is refused'],
            'look-alike host on http' => ['http://localhost.evil.test/hook', 'plain http is refused'],
            'non-loopback v4 on http' => ['http://128.0.0.1/hook', 'plain http is refused'],
            'numeric host that is not loopback' => ['http://1270.1/hook', 'plain http is refused'],
            'other scheme' => ['ftp://host/hook', 'scheme'],
            'no scheme at all' => ['/just/a/path', 'not an absolute'],
            'empty url' => ['', 'non-empty'],
            'credentials in the url' => ['https://user:pw@host.test/h', 'credentials'],
        ];
    }

    public function testNonStringSecretAndShortSecretAreRefused(): void
    {
        foreach ([['secret' => 'short'], ['secret' => 12345], ['secret' => str_repeat('s', OutboundWebhookConfig::MIN_SECRET_BYTES - 1)]] as $i => $row) {
            try {
                OutboundWebhookConfig::parse(['s' => ['url' => 'https://a.test/h'] + $row + ['events' => ['turn.completed']]]);
                self::fail("refusal $i expected");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('at least ' . OutboundWebhookConfig::MIN_SECRET_BYTES, $e->getMessage());
            }
        }

        // The exact floor passes.
        $parsed = OutboundWebhookConfig::parse(['s' => ['url' => 'https://a.test/h', 'secret' => str_repeat('s', OutboundWebhookConfig::MIN_SECRET_BYTES), 'events' => ['turn.completed']]]);
        self::assertCount(1, $parsed);
    }

    /**
     * @dataProvider brokenConfigs
     */
    public function testEveryOtherViolationFailsClosed(mixed $config, string $needle): void
    {
        try {
            OutboundWebhookConfig::parse($config);
            self::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($needle, $e->getMessage());
        }
    }

    /** @return array<string,array{mixed,string}> */
    public static function brokenConfigs(): array
    {
        $entry = ['url' => 'https://a.test/h', 'secret' => self::GOOD_SECRET, 'events' => ['turn.completed']];

        return [
            'a list instead of a map' => [[$entry], 'map of endpoint name'],
            'an uppercase name' => [['Deploy' => $entry], 'the name must be'],
            'a name starting with a hyphen' => [['-x' => $entry], 'the name must be'],
            'a too-long name' => [[str_repeat('a', 65) => $entry], 'the name must be'],
            'an entry that is a list' => [['bad' => ['https://a.test/h']], 'must be a map'],
            'an unknown setting' => [['bad' => $entry + ['headers' => []]], 'unknown setting "headers"'],
            'an empty events list' => [['bad' => ['url' => 'https://a.test/h', 'secret' => self::GOOD_SECRET, 'events' => []]], 'non-empty list'],
            'events as a map' => [['bad' => ['url' => 'https://a.test/h', 'secret' => self::GOOD_SECRET, 'events' => ['a' => 'turn.completed']]], 'non-empty list'],
            'an unknown event type' => [['bad' => ['url' => 'https://a.test/h', 'secret' => self::GOOD_SECRET, 'events' => ['turn.started']]], 'not one of'],
            'a non-string event' => [['bad' => ['url' => 'https://a.test/h', 'secret' => self::GOOD_SECRET, 'events' => [7]]], 'not one of'],
            'a missing url' => [['bad' => ['secret' => self::GOOD_SECRET, 'events' => ['turn.completed']]], 'url must be'],
            'scalar values' => ['webhooksOut', 'map of endpoint name'],
        ];
    }

    public function testTheLoopbackPredicateHasBothPolarities(): void
    {
        foreach (['localhost', 'LOCALHOST', '127.0.0.1', '127.255.255.254', '::1', '0:0:0:0:0:0:0:1'] as $yes) {
            self::assertTrue(OutboundWebhookConfig::isLoopbackHost($yes), $yes);
        }
        foreach (['128.0.0.1', '10.0.0.1', '::2', 'localhost.evil.test', 'example.test', '0.0.0.0'] as $no) {
            self::assertFalse(OutboundWebhookConfig::isLoopbackHost($no), $no);
        }
    }

    public function testThePinnedVocabularyIsTheDesignsFourEvents(): void
    {
        self::assertSame(
            ['turn.completed', 'session.ended', 'permission.asked', 'schedule.fired'],
            WebhookEvent::TYPES,
        );
        self::assertSame(16, OutboundWebhookConfig::MIN_SECRET_BYTES);
    }
}
