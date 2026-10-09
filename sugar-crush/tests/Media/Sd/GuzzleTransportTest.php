<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Media\Sd;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use SugarCraft\Crush\Media\Sd\GuzzleTransport;
use SugarCraft\Crush\Media\Sd\SdException;
use SugarCraft\Crush\Media\Sd\UrlGuard;

/**
 * W1.3 production transport over Guzzle's MockHandler — no sockets, no
 * timers. The house connect-bound middleware from HttpClientDefaults wraps
 * the injected handler rather than replacing it, so these pins exercise the
 * real client-construction path including the E646-shaped options plumbing.
 */
final class GuzzleTransportTest extends TestCase
{
    /** @var list<array{request: RequestInterface, options: array<string, mixed>}> */
    private array $history = [];

    private function transport(array $responses, array $headers = []): GuzzleTransport
    {
        $stack = HandlerStack::create(new MockHandler($responses));

        return new GuzzleTransport(
            UrlGuard::forBase('http://sd.test:7860'),
            $headers,
            ['handler' => $stack],
        );
    }

    private function transportWithHistory(array $responses, array $headers = []): GuzzleTransport
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));

        return new GuzzleTransport(
            UrlGuard::forBase('http://sd.test:7860'),
            $headers,
            ['handler' => $stack],
        );
    }

    public function testSuccessfulRoundtripCarriesStatusBodyAndContentType(): void
    {
        $transport = $this->transport([
            new Response(200, ['Content-Type' => 'application/json'], '{"ok":true}'),
        ]);
        $result = $transport->request('GET', '/sdapi/v1/cmd-flags');

        self::assertTrue($result->is2xx());
        self::assertSame(200, $result->status);
        self::assertSame('{"ok":true}', $result->body);
        self::assertTrue($result->isJson());
    }

    public function testNonJsonContentTypeIsReportedNotRewritten(): void
    {
        $transport = $this->transport([
            new Response(502, ['Content-Type' => 'text/html'], '<html>proxy</html>'),
        ]);
        $result = $transport->request('GET', '/sdapi/v1/cmd-flags');

        self::assertSame(502, $result->status);
        self::assertFalse($result->isJson());
    }

    public function testBasicAuthHeaderPresentIffCredentialsConfigured(): void
    {
        $withCreds = $this->transportWithHistory(
            [new Response(200, [], '{}')],
            ['Authorization' => 'Basic ' . base64_encode('user:pass')],
        );
        $withCreds->request('GET', '/sdapi/v1/options');

        $authorized = $this->history[0]['request']->getHeaderLine('Authorization');
        self::assertSame('Basic ' . base64_encode('user:pass'), $authorized);

        $this->history = [];
        $plain = $this->transportWithHistory([new Response(200, [], '{}')]);
        $plain->request('GET', '/sdapi/v1/options');

        self::assertSame('', $this->history[0]['request']->getHeaderLine('Authorization'));
    }

    public function testOffOriginRedirectIsRefusedAndNeverDialled(): void
    {
        $transport = $this->transportWithHistory([
            new Response(302, ['Location' => 'http://evil.test/collect']),
            new Response(200, [], '{"should":"never-run"}'),
        ]);

        try {
            $transport->request('GET', '/sdapi/v1/options');
            self::fail('expected off-origin redirect refusal');
        } catch (SdException $e) {
            self::assertStringContainsString('outside the configured origin', $e->getMessage());
        }

        // The pin that matters: the second canned response was never consumed
        // — Guzzle did not dial the hostile Location even once.
        self::assertCount(1, $this->history);
    }

    public function testSameOriginRedirectIsFollowedBoundedAndNormalizedToGet(): void
    {
        $transport = $this->transportWithHistory([
            new Response(302, ['Location' => '/moved/sdapi/v1/options']),
            new Response(200, [], '{"followed":true}'),
        ]);
        $result = $transport->request('POST', '/moved/sdapi/v1/options', ['body' => 'dropped-on-302']);

        self::assertSame('{"followed":true}', $result->body);
        self::assertCount(2, $this->history);
        self::assertSame('GET', $this->history[1]['request']->getMethod());
        self::assertSame('http://sd.test:7860/moved/sdapi/v1/options', (string) $this->history[1]['request']->getUri());
    }

    public function testRedirectLoopIsCutByHopBoundNotByClock(): void
    {
        $responses = [];
        for ($i = 0; $i <= GuzzleTransport::MAX_REDIRECT_HOPS; $i++) {
            $responses[] = new Response(302, ['Location' => '/round' . $i]);
        }
        $transport = $this->transport($responses);

        $this->expectException(SdException::class);
        $this->expectExceptionMessage('redirect loop');
        $transport->request('GET', '/round0');
    }

    public function testConnectFailureBecomesSdExceptionWithStatusZero(): void
    {
        $transport = $this->transport([
            new ConnectException('Connection refused', new Request('GET', '/x')),
        ]);

        try {
            $transport->request('GET', '/sdapi/v1/progress');
            self::fail('expected transport SdException');
        } catch (SdException $e) {
            self::assertSame(0, $e->status());
            self::assertStringContainsString('Connection refused', $e->detail());
        }
    }

    public function testJsonBodyAndQueryArePlacedByGuzzle(): void
    {
        $transport = $this->transportWithHistory([new Response(200, [], '{}')]);
        $transport->request('POST', '/sdapi/v1/progress', ['a' => 1], ['skip_current_image' => 'false']);

        $request = $this->history[0]['request'];
        self::assertSame('skip_current_image=false', $request->getUri()->getQuery());
        self::assertStringContainsString('"a":1', (string) $request->getBody());
        self::assertStringContainsString('application/json', $request->getHeaderLine('Content-Type'));
    }

    public function testNoTotalRequestTimeoutIsEverSelfInitiated(): void
    {
        // E646 / §10.1-2 as narrowed by R1 MAJOR-2: the default dial stays
        // connect-bounded; the ONE total-timeout door opens only on an
        // explicit caller-supplied budget (the discovery GET ladder).
        // A hardcoded numeric timeout anywhere in this class would be a
        // wall-clock kill on long renders — the source may mention the
        // option ONLY inside the null-guarded conditional.
        $source = (string) file_get_contents(__DIR__ . '/../../../src/Media/Sd/GuzzleTransport.php');
        self::assertMatchesRegularExpression(
            '/if \(\$totalTimeoutSeconds !== null\) \{\s*\$options\[\'timeout\'\] = \$totalTimeoutSeconds;/',
            $source,
            "the only 'timeout' write must sit behind the caller-budget guard",
        );
        self::assertStringNotContainsString("'timeout' =>", $source);
        self::assertStringNotContainsString('usleep', $source);
    }

    public function testBudgetFlowsIntoGuzzleOptionsOnlyWhenSupplied(): void
    {
        // Functional polarity pair of the structural pin above: a default
        // generation-shape dial carries no total in the per-request options,
        // while an explicitly budgeted discovery-shape GET carries exactly it.
        $plain = $this->transportWithHistory([new Response(200, [], '{}')]);
        $plain->request('POST', '/sdapi/v1/txt2img', ['prompt' => 'x']);
        self::assertArrayNotHasKey('timeout', $this->history[0]['options'], 'generation POSTs keep E646 connect-bound-only semantics');

        $this->history = [];
        $budgeted = $this->transportWithHistory([new Response(200, [], '{}')]);
        $budgeted->request('GET', '/sdapi/v1/cmd-flags', [], [], 3.0);
        self::assertSame(3.0, $this->history[0]['options']['timeout'], 'a caller-supplied budget must reach Guzzle as the total timeout');
    }
}
