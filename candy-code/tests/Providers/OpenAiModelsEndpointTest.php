<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Providers;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OpenAI\Contracts\ClientContract;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use SugarCraft\Code\Providers\CustomProvider;
use SugarCraft\Code\Providers\HasModelCatalog;
use SugarCraft\Code\Providers\OpenAIProvider;
use SugarCraft\Code\Providers\OpenAiModelsEndpoint;
use SugarCraft\Code\Providers\SglangProvider;

/**
 * PRV-1 (§6.5): the shared `GET {base}/models` metadata read behind every
 * OpenAI-compatible catalog, and the provider plumbing that reaches it.
 * Nothing here touches a real server — the transport is mocked exactly like
 * {@see SglangServerInfoTest}.
 */
final class OpenAiModelsEndpointTest extends TestCase
{
    public function testAFullBodyAnswersTheIdsInServerOrder(): void
    {
        $ids = OpenAiModelsEndpoint::decode('{"object":"list","data":[{"id":"zeta-2","object":"model"},{"id":"alpha-1","object":"model"}]}');

        self::assertSame(['zeta-2', 'alpha-1'], $ids, 'the server order is the freshest truth; the palette sorts only its own merge');
    }

    public function testDecodeRefusesEveryUnusableShape(): void
    {
        self::assertNull(OpenAiModelsEndpoint::decode('not json at all'));
        self::assertNull(OpenAiModelsEndpoint::decode('[]'), 'a list body is not the documented object');
        self::assertNull(OpenAiModelsEndpoint::decode('{"object":"list"}'), 'no data key is unknown, not empty');
        self::assertNull(OpenAiModelsEndpoint::decode('{"data":[]}'), 'an empty catalog is indistinguishable from a refused route');
        self::assertNull(OpenAiModelsEndpoint::decode('{"data":[{"name":"no-id-field"}]}'), 'rows without ids answer nothing usable');
        self::assertSame(['ok'], OpenAiModelsEndpoint::decode('{"data":[{"id":"ok"},{"id":""},{"id":null},{"id":7}]}'), 'junk rows fall, real ids ride');
    }

    public function testFetchAsksModelsBesideTheChatRouteWithTheBearerKeyAndShortBound(): void
    {
        /** @var list<array{request: RequestInterface, options: array<string, mixed>}> $seen */
        $seen = [];
        $mock = new MockHandler([
            function (RequestInterface $request, array $options) use (&$seen): Response {
                $seen[] = ['request' => $request, 'options' => $options];

                return new Response(200, [], '{"data":[{"id":"gpt-5"}]}');
            },
        ]);

        $ids = OpenAiModelsEndpoint::fetch('https://api.openai.com/v1', 'sk-test', $mock);

        self::assertSame(['gpt-5'], $ids);
        self::assertCount(1, $seen);
        self::assertSame('https://api.openai.com/v1/models', (string) $seen[0]['request']->getUri(), 'the version root the base already carries is kept');
        self::assertSame('GET', $seen[0]['request']->getMethod());
        self::assertSame('Bearer sk-test', $seen[0]['request']->getHeaderLine('Authorization'));
        // A metadata read, so a TOTAL bound is right here (never on a
        // completion) — the ProviderConnectTimeoutTest exemption this file
        // is listed under.
        self::assertSame(OpenAiModelsEndpoint::DISCOVERY_TIMEOUT_SECONDS, $seen[0]['options']['timeout']);
        self::assertSame(OpenAiModelsEndpoint::DISCOVERY_TIMEOUT_SECONDS, $seen[0]['options']['connect_timeout']);
    }

    public function testFetchResolvesAgainstADeeperMountAndAKeylessBase(): void
    {
        $seen = [];
        $respond = function (RequestInterface $request) use (&$seen): Response {
            $seen[] = $request;

            return new Response(200, [], '{"data":[{"id":"m"}]}');
        };

        self::assertSame(['m'], OpenAiModelsEndpoint::fetch('https://proxy/sglang/v1/', null, new MockHandler([$respond])));
        self::assertSame('https://proxy/sglang/v1/models', (string) $seen[0]->getUri());
        self::assertFalse($seen[0]->hasHeader('Authorization'), 'no key, no header — not an empty Bearer');
    }

    public function testFetchDegradesToNullOnTransportAndServerFailures(): void
    {
        self::assertNull(OpenAiModelsEndpoint::fetch('http://unreachable.invalid', null, new MockHandler([
            new ConnectException('refused', new Request('GET', 'models')),
        ])));
        self::assertNull(OpenAiModelsEndpoint::fetch('https://api.openai.com/v1', 'sk-nope', new MockHandler([
            new Response(401, [], '{"error":{"message":"Incorrect API key"}}'),
        ])), 'a 401 is "no catalog", never a crash');
        self::assertNull(OpenAiModelsEndpoint::fetch('https://api.openai.com/v1', null, new MockHandler([
            new Response(200, [], '<html>a login portal, not JSON</html>'),
        ])));
    }

    public function testTheThreeOpenAiCompatibleTransportsSpeakForTheirCatalogs(): void
    {
        $client = new \GuzzleHttp\Client();

        $custom = new CustomProvider('hosted', 'https://gw.example/v1', 'm', 'k', $client, true, true);
        self::assertInstanceOf(HasModelCatalog::class, $custom);
        self::assertSame('https://gw.example/v1', $custom->catalogKey());

        $sglang = new SglangProvider('http://127.0.0.1:30000/v1', 'Qwen/Qwen3.8-Flash-Next-FP8', null, $client);
        self::assertInstanceOf(HasModelCatalog::class, $sglang);
        self::assertSame('http://127.0.0.1:30000/v1', $sglang->catalogKey());

        // The SDK seam carries no URL; the factory threads one. A bare
        // construction — embedders and tests — is "no catalog", never a
        // guess at api.openai.com.
        $bare = new OpenAIProvider($this->createMock(ClientContract::class), 'gpt-4o');
        self::assertNull($bare->catalogKey());
        self::assertNull($bare->listModels());
    }

    public function testTheOpenAiCatalogRidesTheThreadedBaseAndSurvivesRebinding(): void
    {
        $provider = new OpenAIProvider(
            $this->createMock(ClientContract::class),
            'gpt-4o',
            modelsBaseUrl: 'https://relay.example/v1/',
            modelsApiKey: 'sk-relay',
        );

        self::assertSame('https://relay.example/v1', $provider->catalogKey());

        // Rebinding the model must not drop the catalog threading —
        // withModel re-news through the whole property set.
        $rebound = $provider->withModel('gpt-5');
        self::assertSame('https://relay.example/v1', $rebound->catalogKey());
    }
}
