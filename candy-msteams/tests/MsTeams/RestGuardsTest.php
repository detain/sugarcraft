<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Tests\MsTeams;

use CandyCode\Bundles\MsTeams\MsTeams\Rest;
use CandyCode\Bundles\MsTeams\MsTeams\RestException;
use CandyCode\Bundles\MsTeams\MsTeams\TokenBroker;
use Psr\Http\Message\StreamInterface;
use React\Http\Message\Response;
use CandyCode\Bundles\MsTeams\Support\Log;
use CandyCode\Bundles\MsTeams\Support\TextSafety;
use CandyCode\Bundles\MsTeams\Tests\Fixture\FakeTeamsApi;
use CandyCode\Bundles\MsTeams\Tests\Fixture\LoopbackConnector;
use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\Loop;
use React\Http\Browser;
use React\Socket\Connector;

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

    public function testA429IsRetriedHonouringRetryAfterAndThenLands(): void
    {
        $rest = $this->rest(insecure: true);
        $url = 'http://127.0.0.1:' . $this->api->port;
        $rest->sendActivity($url, 'c', ['type' => 'message', 'text' => 'warm'])->then(null, static function (): void {
        });
        self::runFor($this->loop, 800);
        $this->api->failActivityPosts(429, 1, '0');

        $sent = null;
        $rest->sendActivity($url, 'c', ['type' => 'message', 'text' => 'retried'])->then(static function (array $r) use (&$sent): void {
            $sent = $r;
        });
        self::runFor($this->loop, 500);

        self::assertIsArray($sent, 'the retry after the 429 must land');
        self::assertStringStartsWith('act_out_', (string) ($sent['id'] ?? ''));
        self::assertSame(3, $this->activityPosts(), 'warm-up, the throttled attempt, and the retry');
    }

    public function testA429RetriesStopAtTwoAndSurfacesOnce(): void
    {
        $rest = $this->rest(insecure: true);
        $url = 'http://127.0.0.1:' . $this->api->port;
        $rest->sendActivity($url, 'c', ['type' => 'message', 'text' => 'warm'])->then(null, static function (): void {
        });
        self::runFor($this->loop, 800);
        $this->api->failActivityPosts(429, 10, '0');

        $error = null;
        $rest->sendActivity($url, 'c', ['type' => 'message', 'text' => 'throttled'])->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });
        self::runFor($this->loop, 500);

        self::assertInstanceOf(RestException::class, $error);
        self::assertStringContainsString('429', (string) $error->getMessage());
        self::assertSame(4, $this->activityPosts(), 'warm-up plus the attempt and exactly two retries, then give up');
    }

    public function testRetryAfterIsCappedAndDefaultsToOneSecond(): void
    {
        self::assertSame(30.0, Rest::retryAfterSeconds('3600'));
        self::assertSame(2.5, Rest::retryAfterSeconds('2.5'));
        self::assertSame(1.0, Rest::retryAfterSeconds(''));
        self::assertSame(1.0, Rest::retryAfterSeconds('Wed, 21 Oct 2026 07:28:00 GMT'));
        self::assertSame(0.0, Rest::retryAfterSeconds('-4'));
    }

    public function testTheBearerRidesTheAllowlistedMediaHostAndNothingElse(): void
    {
        $http = new Browser(new LoopbackConnector(new Connector([], $this->loop), $this->api->port), $this->loop);
        $rest = $this->rest(http: $http);
        $bytes = null;
        $rest->fetchBinary('https://smba.trafficmanager.net/media/pic.png')->then(static function (string $b) use (&$bytes): void {
            $bytes = $b;
        });
        self::runFor($this->loop, 800);

        self::assertSame('FAKEPNGBYTES', $bytes, 'the allowlisted host is dialled through the loopback connector');
        $media = $this->api->lastCallFor('/media/', 'GET');
        self::assertIsArray($media);
        self::assertSame('Bearer bot-tok-1', $media['auth'], 'the bot bearer rides an allowlisted Bot Framework media host');

        $opted = $this->rest(insecure: true);
        $opted->fetchBinary('http://127.0.0.1:' . $this->api->port . '/media/loop.png')->then(static function (): void {
        });
        self::runFor($this->loop, 800);
        $loopback = $this->api->lastCallFor('/media/loop.png', 'GET');
        self::assertIsArray($loopback);
        self::assertSame('', $loopback['auth'], 'no other host, loopback included, receives the bearer');
    }

    public function testAServiceUrlOutsideTheBotFrameworkHostsGetsNoBearer(): void
    {
        $rest = $this->rest(insecure: true);
        $error = null;
        $rest->sendActivity('https://attacker.example', 'c', ['type' => 'message'])->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });
        self::runFor($this->loop, 50);

        self::assertInstanceOf(RestException::class, $error);
        self::assertStringContainsString('not a Bot Framework service host', (string) $error->getMessage());
        self::assertSame([], $this->api->calls, 'a stranger host must not mint a token or receive any request');
    }

    public function testAnErrorBodyIsRedactedBeforeItIsLogged(): void
    {
        $lines = [];
        $log = new Log(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $safety = (new TextSafety())->withSecrets(['app-pw-secret-99']);
        $rest = $this->rest(insecure: true, log: $log, safety: $safety);
        $this->api->failActivityPosts(400, 1, null, '{"error":"bad","echo":"app-pw-secret-99"}');

        $rest->sendActivity('http://127.0.0.1:' . $this->api->port, 'c', ['type' => 'message'])->then(null, static function (): void {
        });
        self::runFor($this->loop, 300);

        $joined = \implode("\n", $lines);
        self::assertStringContainsString('msteams_rest_error', $joined);
        self::assertStringNotContainsString('app-pw-secret-99', $joined, 'the app password must never reach a log line');
        self::assertStringContainsString('[redacted]', $joined);
    }

    public function testDrainLetsAnInFlightPostSettleBeforeItReturns(): void
    {
        $rest = $this->rest(insecure: true);
        $sent = null;
        $rest->sendActivity('http://127.0.0.1:' . $this->api->port, 'c', ['type' => 'message', 'text' => 'late'])->then(static function (array $r) use (&$sent): void {
            $sent = $r;
        });

        $rest->drain(5.0);

        self::assertIsArray($sent, 'the in-flight post must have settled by the time drain returned');
        self::assertSame(1, $this->activityPosts());
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

    public function testAFailedMediaFetchNeverCarriesTheSignatureIntoItsReason(): void
    {
        // msteams-20 leg 028: the signed query of a media URL is bearer
        // material; a wire failure that embeds the full request target must
        // have its signature cut out before the text rides the exception
        // (and through it the log). The developer path keeps the verbatim
        // cause as the attached previous Throwable.
        $signed = 'http://127.0.0.1:9/media/pic.png?notBefore=2026&sig=SUPERSECRETBEARER&&se=2030';
        $rejecting = new class extends Browser {
            public function __construct()
            {
            }

            public function get($url, array $headers = array())
            {
                return \React\Promise\reject(new \RuntimeException('Connection to ' . $url . ' refused'));
            }
        };

        $error = null;
        $this->rest(true, null, null, $rejecting)->fetchBinary($signed)->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });

        self::assertInstanceOf(RestException::class, $error);
        self::assertStringNotContainsString('SUPERSECRETBEARER', $error->getMessage());
        self::assertStringContainsString('[signature-redacted]', $error->getMessage());
        self::assertStringContainsString('media/pic.png', $error->getMessage(), 'the path label survives — scrubbing must not amputate the diagnosis');
        self::assertStringContainsString('SUPERSECRETBEARER', $error->getPrevious()->getMessage(), 'the attached cause stays verbatim for the developer path');
    }

    private ?TokenBroker $broker = null;

    private function rest(bool $insecure = false, ?Log $log = null, ?TextSafety $safety = null, ?Browser $http = null): Rest
    {
        $this->broker = new TokenBroker(
            ['appId' => 'a', 'appPassword' => 'p', 'tenantId' => 't'],
            Log::silent(),
            'http://127.0.0.1:' . $this->api->port,
        );

        return new Rest($this->broker, $log ?? Log::silent(), $insecure, $this->loop, $safety, $http);
    }

    private function activityPosts(): int
    {
        return \count(\array_filter($this->api->calls, static fn (array $c): bool => $c['method'] === 'POST' && \str_contains($c['path'], '/activities')));
    }

    private static function runFor(LoopInterface $loop, int $ms): void
    {
        $timer = $loop->addTimer($ms / 1000.0, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();
        $loop->cancelTimer($timer);
    }
    public function testADeclaredSizeOverTheBudgetRefusesBeforeAnyBodyByteIsRead(): void
    {
        // msteams-16 story one: trust-but-verify the Content-Length — an
        // over-budget declaration refuses at the header door, and the body
        // stream is never touched (the unreadable fake would throw).
        $stream = new UnreadableTrackStream();
        $http = new class ($stream) extends Browser {
            public function __construct(private readonly StreamInterface $bodyStream)
            {
            }

            public function get($url, array $headers = array())
            {
                return \React\Promise\resolve(new Response(200, ['Content-Length' => (string) (1024 * 1024)], $this->bodyStream));
            }
        };

        $error = null;
        $this->rest(true, null, null, $http)->fetchBinary('http://127.0.0.1:9/media/pic.png', 1024)->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });

        self::assertInstanceOf(RestException::class, $error);
        self::assertStringContainsString('declares 1048576 bytes against a 1024 byte budget', $error->getMessage());
        self::assertSame(0, $stream->reads, 'the declared-size door must refuse before a single body byte is read');
        self::assertTrue($stream->closed, 'the refused stream is closed, not left open on the wire');
        self::assertStringNotContainsString('127.0.0.1:9', $error->getMessage(), 'content-free: the error names the PATH, never the target');
    }

    public function testALyingContentLengthStillTripsTheDrainCapWithinOneChunk(): void
    {
        // msteams-16 story two: a small header with an endless stream must
        // die in the accumulator, at most one chunk past the cap — the old
        // shape buffered the whole lie first.
        $stream = new InfiniteZeroStream();
        $http = new class ($stream) extends Browser {
            public function __construct(private readonly StreamInterface $bodyStream)
            {
            }

            public function get($url, array $headers = array())
            {
                return \React\Promise\resolve(new Response(200, ['Content-Length' => '8'], $this->bodyStream));
            }
        };

        $error = null;
        $this->rest(true, null, null, $http)->fetchBinary('http://127.0.0.1:9/media/pic.png', 100_000)->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });

        self::assertInstanceOf(RestException::class, $error);
        self::assertStringContainsString('exceeds the 100000 byte budget', $error->getMessage());
        self::assertLessThanOrEqual(100_000 + Rest::MEDIA_CHUNK_BYTES, $stream->totalRead, 'the drain overshoot is bounded to one chunk');
        self::assertTrue($stream->closed);
    }

    public function testAStreamedBodyWithinTheBudgetArrivesWhole(): void
    {
        // The green flow: declared == actual within budget — the cap must
        // not shave a legal body (and an equality-edge declared size rides).
        $stream = new FiniteStream(str_repeat('x', 512));
        $http = new class ($stream) extends Browser {
            public function __construct(private readonly StreamInterface $bodyStream)
            {
            }

            public function get($url, array $headers = array())
            {
                return \React\Promise\resolve(new Response(200, ['Content-Length' => '512'], $this->bodyStream));
            }
        };

        $bytes = null;
        $this->rest(true, null, null, $http)->fetchBinary('http://127.0.0.1:9/media/pic.png', 512)->then(static function (string $b) use (&$bytes): void {
            $bytes = $b;
        });

        self::assertSame(str_repeat('x', 512), $bytes);
    }
}

/**
 * PSR-7 fake for msteams-16 story one: any read is a LAW BREACH (the
 * declared-size refusal must happen before the body is touched).
 */
final class UnreadableTrackStream implements StreamInterface
{
    public int $reads = 0;

    public bool $closed = false;

    public function __toString(): string
    {
        return '';
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function tell(): int
    {
        return 0;
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
    }

    public function rewind(): void
    {
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write($string): int
    {
        throw new \LogicException('not writable');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read($length): string
    {
        $this->reads++;

        throw new \LogicException('the declared-size door must refuse before any body byte is read');
    }

    public function getContents(): string
    {
        $this->reads++;

        throw new \LogicException('getContents bypasses the streaming cap');
    }

    public function getMetadata($key = null)
    {
        return null;
    }

    public function eof(): bool
    {
        return false;
    }
}

/** PSR-7 fake for msteams-16 story two: endless zero bytes, counted. */
final class InfiniteZeroStream implements StreamInterface
{
    public int $totalRead = 0;

    public bool $closed = false;

    public function __toString(): string
    {
        return '';
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function tell(): int
    {
        return 0;
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
    }

    public function rewind(): void
    {
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write($string): int
    {
        throw new \LogicException('not writable');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read($length): string
    {
        $this->totalRead += $length;

        return \str_repeat("\0", $length);
    }

    public function getContents(): string
    {
        throw new \LogicException('getContents bypasses the streaming cap');
    }

    public function getMetadata($key = null)
    {
        return null;
    }

    public function eof(): bool
    {
        return false;
    }
}

/** PSR-7 fake: a finite body read in caller-sized chunks. */
final class FiniteStream implements StreamInterface
{
    private int $at = 0;

    public function __construct(private readonly string $bytes)
    {
    }

    public function __toString(): string
    {
        return $this->bytes;
    }

    public function close(): void
    {
    }

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return \strlen($this->bytes);
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function tell(): int
    {
        return $this->at;
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
    }

    public function rewind(): void
    {
        $this->at = 0;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write($string): int
    {
        throw new \LogicException('not writable');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read($length): string
    {
        $chunk = \substr($this->bytes, $this->at, $length);
        $this->at += \strlen($chunk);

        return $chunk;
    }

    public function getContents(): string
    {
        $rest = \substr($this->bytes, $this->at);
        $this->at = \strlen($this->bytes);

        return $rest;
    }

    public function getMetadata($key = null)
    {
        return null;
    }

    public function eof(): bool
    {
        return $this->at >= \strlen($this->bytes);
    }
}
