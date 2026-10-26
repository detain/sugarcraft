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
use SugarCraft\Code\Providers\ModelCatalog;
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

    /**
     * PRV-1 (MINOR-1): the factory always threads the SDK-default root so
     * the catalog asks where the wire talks, but an unauthenticated GET of
     * api.openai.com/models is a guaranteed 401 — on palette open it must
     * spend no bytes, exactly like the `models all` door (whose comment
     * names the same rule: "an anonymous api.openai.com call … no bytes
     * spent"). The other polarities are the door's edges: a key in hand
     * makes the same root askable, and an EXPLICIT base without a key is a
     * self-hosted endpoint the operator named, so it still rides keyless.
     */
    public function testTheOpenAiCatalogDoorRefusesAKeylessAskAgainstThePublicApi(): void
    {
        $keyless = new OpenAIProvider(
            $this->createMock(ClientContract::class),
            'gpt-4o',
            modelsBaseUrl: OpenAIProvider::SDK_DEFAULT_BASE,
            modelsApiKey: null,
        );
        self::assertNull($keyless->catalogKey(), 'no credential means the public root is unaskable — no memo key, no fetch, no bytes');
        self::assertNull($keyless->listModels());

        $emptyKey = new OpenAIProvider(
            $this->createMock(ClientContract::class),
            'gpt-4o',
            modelsBaseUrl: OpenAIProvider::SDK_DEFAULT_BASE,
            modelsApiKey: '',
        );
        self::assertNull($emptyKey->catalogKey(), 'the empty string is as uncredentialed as null');

        $sloppy = new OpenAIProvider(
            $this->createMock(ClientContract::class),
            'gpt-4o',
            modelsBaseUrl: 'HTTPS://API.OPENAI.COM/v1/',
            modelsApiKey: null,
        );
        self::assertNull($sloppy->catalogKey(), 'case and trailing-slash noise on the public root still trips the door');

        $armed = new OpenAIProvider(
            $this->createMock(ClientContract::class),
            'gpt-4o',
            modelsBaseUrl: OpenAIProvider::SDK_DEFAULT_BASE,
            modelsApiKey: 'sk-real',
        );
        self::assertSame(OpenAIProvider::SDK_DEFAULT_BASE, $armed->catalogKey(), 'with a key the default root is exactly what is worth asking');
        self::assertSame(OpenAIProvider::SDK_DEFAULT_BASE, $armed->withModel('gpt-5')->catalogKey(), 'rebinding keeps the credential threading — and with it the door polarity');

        $relay = new OpenAIProvider(
            $this->createMock(ClientContract::class),
            'gpt-4o',
            modelsBaseUrl: 'https://relay.example/v1',
            modelsApiKey: null,
        );
        self::assertSame('https://relay.example/v1', $relay->catalogKey(), 'a configured non-default base rides keyless, as `models all` already asks it');
    }

    // ------------------------------------------------------------------
    // fx/palette-sec (plan-reverify-1026): the endpoint is untrusted input
    // ------------------------------------------------------------------

    /**
     * A malicious configured provider answers /v1/models with escape-carrying
     * ids. Every one is swept at {@see OpenAiModelsEndpoint::decode()} — the
     * single point the wire's bytes enter the process — because BOTH
     * consumers (the Ctrl+P model palette, whose row bodies renderPalette()
     * paints raw, and the `models all` table) read this list, and the palette
     * cannot sweep at paint time without desynchronising the
     * {@see \SugarCraft\Fuzzy\Highlighter} indices computed over the same
     * label. OSC-8 in both terminators, SGR, DCS, BEL, CR/LF, NUL and DEL
     * all fall; the visible text survives.
     */
    public function testHostileIdsAreSweptToControlFreeLabelsAtIngest(): void
    {
        $rows = [
            "\x1b]8;;https://evil.example\x07pwned"            => 'pwned',
            "\x1b]8;;https://evil.example\x1b\\linked"          => 'linked',
            "\x1b[31mred\x1b[0m-model"                          => 'red-model',
            "\x1bP1;2qdevice-control\x1b\\tail"                 => 'tail',
            "a\x07b"                                            => 'ab',
            "two\r\nlines"                                      => 'two lines',
            "nul\x00del\x7fb"                                   => 'nuldelb',
            "plain-model-v2"                                    => 'plain-model-v2',
            "Qwen/Qwen3.8-Flash-Next"                           => 'Qwen/Qwen3.8-Flash-Next',
        ];
        $body = json_encode(['data' => array_map(
            static fn(string $id): array => ['id' => $id],
            array_keys($rows),
        )], JSON_UNESCAPED_SLASHES);

        $ids = OpenAiModelsEndpoint::decode($body);

        self::assertSame(array_values($rows), $ids, 'every id lands as its control-free label, in server order');
        foreach ($ids as $id) {
            self::assertStringNotContainsString("\x1b", $id, 'no raw ESC may survive the ingest sweep');
            self::assertSame([], preg_grep('/[\x00-\x08\x0b-\x1f\x7f]/', [$id]), 'no C0/DEL byte may survive the ingest sweep');
        }
    }

    /**
     * An id that is NOTHING but an escape sequence sweeps to the empty
     * string and falls like the other unusable rows — a wire answer made
     * only of control bytes is "no catalog", never a blank clickable row.
     * The body spells the escapes the JSON way (`\u001b`), because a raw
     * control byte inside a JSON string is a malformed document that
     * json_decode refuses long before the catalog sees it.
     */
    public function testAnIdOfPureEscapesFallsRatherThanEnteringTheCatalog(): void
    {
        $ids = OpenAiModelsEndpoint::decode('{"data":[{"id":"\\u001b[31m"},{"id":"\\u001b]8;;x\\u0007"}]}');

        self::assertNull($ids, 'rows whose whole content is escape scaffolding leave nothing paintable');
    }

    /**
     * End-to-end through the fetch transport and the catalog merge the
     * palette consumes: the hostile row that reaches
     * {@see ModelCatalog::merge()} — and therefore renderPalette()'s row
     * body and the `models all` print loop — is the swept label, byte-clean.
     */
    public function testTheFetchedCatalogThePaletteMergesCarriesNoEscapeBytes(): void
    {
        $mock = new MockHandler([
            new Response(200, [], '{"data":[{"id":"\\u001b]8;;https://evil\\u0007hostile-row"},{"id":"honest-row"}]}'),
        ]);

        $live = OpenAiModelsEndpoint::fetch('https://evil.example/v1', null, $mock);
        self::assertNotNull($live);

        $merged = ModelCatalog::merge(['configured-model'], $live, ['litellm-model']);

        self::assertSame(['configured-model', 'hostile-row', 'honest-row', 'litellm-model'], $merged);
        foreach ($merged as $label) {
            self::assertSame(0, preg_match('/[\x00-\x1b\x7f]/', $label), 'no row the palette or `models all` paints carries a control byte');
        }
    }
}
