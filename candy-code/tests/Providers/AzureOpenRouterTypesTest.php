<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Providers;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use OpenAI\Contracts\ClientContract;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CustomProvider;
use SugarCraft\Code\Providers\HasModelCatalog;
use SugarCraft\Code\Providers\OpenAIProvider;
use SugarCraft\Code\Providers\ProviderFactory;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * C-PRV-2.c: the two new first-class provider types.
 *
 * `azure` reuses {@see OpenAIProvider} on Azure's credential shape — the
 * key travels in an `api-key` header, `Authorization` is never sent, the
 * base is derived from the resource name, and the catalog door stays shut
 * (Azure's model list is a control-plane API, not `GET {base}/models`).
 * `openrouter` is a preset of {@see CustomProvider}: the OpenAI-compatible
 * base plus the HTTP-Referer/X-Title attribution pair, with an operator
 * `headers` block able to override each key — the same `headers` key a
 * plain custom provider now also accepts.
 *
 * No live dials anywhere: the chat arm is pinned through the SDK's own
 * transport value objects (reflection, the headers they would send), the
 * Responses arm through the `responsesClient` MockHandler seam, exactly as
 * {@see OpenAiResponsesWireTest} pins the openai responses wire.
 */
final class AzureOpenRouterTypesTest extends TestCase
{
    use HomeSandboxTrait;

    private string $azureRouterSandboxHome = '';

    protected function setUp(): void
    {
        parent::setUp();

        // Factory legs read user-tier settings through
        // Bootstrap::readUserConfig(); an empty sandboxed home keeps the
        // operator's real config out of every result below (same law as
        // OpenAiResponsesWireTest).
        $this->azureRouterSandboxHome = sys_get_temp_dir() . '/provider_azure_router_home_' . uniqid('', true);
        $this->useHomeSandbox($this->azureRouterSandboxHome);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        @rmdir($this->azureRouterSandboxHome);

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    //  Helpers (names file-unique per DuplicatedTestHelperDrift discipline)
    // -------------------------------------------------------------------------

    /**
     * The header map the SDK chat transport would send, keyed by the
     * literal names given to withHttpHeader()/the factory.
     *
     * @return array<string, string>
     */
    private function azureChatTransportHeaders(OpenAIProvider $provider): array
    {
        $client = (new \ReflectionProperty(OpenAIProvider::class, 'client'))->getValue($provider);
        $transporter = (new \ReflectionProperty(\OpenAI\Client::class, 'transporter'))->getValue($client);
        $headers = (new \ReflectionProperty(\OpenAI\Transporters\HttpTransporter::class, 'headers'))->getValue($transporter);

        /** @var array<string, string> $map */
        $map = $headers->toArray();

        return $map;
    }

    /** The base the SDK chat transport would dial, as a string. */
    private function azureChatTransportBaseUri(OpenAIProvider $provider): string
    {
        $client = (new \ReflectionProperty(OpenAIProvider::class, 'client'))->getValue($provider);
        $transporter = (new \ReflectionProperty(\OpenAI\Client::class, 'transporter'))->getValue($client);
        $baseUri = (new \ReflectionProperty(\OpenAI\Transporters\HttpTransporter::class, 'baseUri'))->getValue($transporter);

        return $baseUri->toString();
    }

    /**
     * A Responses-arm provider speaking through a MockHandler client,
     * built in the Azure credential shape the factory would use.
     *
     * @param list<GuzzleResponse> $queued
     */
    private function azureResponsesProvider(array $queued): OpenAIProvider
    {
        $this->azureResponsesSightings = [];
        $mock = new MockHandler($queued);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->azureResponsesSightings));

        return new OpenAIProvider(
            $this->createMock(ClientContract::class),
            'dep-x',
            [],
            modelsBaseUrl: 'https://res1.openai.azure.com/openai/v1',
            modelsApiKey: null,
            api: OpenAIProvider::API_RESPONSES,
            responsesClient: new GuzzleClient(['handler' => $stack]),
            providerName: 'azure',
            apiKeyHeader: 'sk-azure-probe',
        );
    }

    /** @var list<array<string, mixed>> */
    private array $azureResponsesSightings = [];

    /** @return array<string, mixed> */
    private function azureResponsesDoc(): array
    {
        return [
            'id' => 'resp_azure_ok',
            'status' => 'completed',
            'output' => [
                [
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [
                        ['type' => 'output_text', 'text' => 'Azure answers'],
                    ],
                ],
            ],
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 4,
                'total_tokens' => 14,
            ],
        ];
    }

    // -------------------------------------------------------------------------
    //  azure: chat arm through the factory
    // -------------------------------------------------------------------------

    public function testAzureResourceBuildsAnOpenAiProviderNamedAzure(): void
    {
        $provider = (new ProviderFactory())->create([
            'type' => 'azure',
            'apiKey' => 'sk-azure-probe',
            'resource' => 'res1',
            'model' => 'dep-x',
        ]);

        $this->assertInstanceOf(OpenAIProvider::class, $provider);
        // The type reports itself, not the class it reuses — attribution
        // for transcripts, telemetry and price lookups keys on this.
        $this->assertSame('azure', $provider->name());

        $headers = $this->azureChatTransportHeaders($provider);
        $this->assertSame('sk-azure-probe', $headers['api-key']);
        // The Azure endpoint answers a Bearer request with 401s masked as
        // model-not-found; sending only api-key is the whole point.
        $this->assertArrayNotHasKey('Authorization', $headers);

        $this->assertStringContainsString('res1.openai.azure.com/openai/v1', $this->azureChatTransportBaseUri($provider));
    }

    public function testAzureCatalogDoorStaysShut(): void
    {
        $provider = (new ProviderFactory())->create([
            'type' => 'azure',
            'apiKey' => 'sk-azure-probe',
            'resource' => 'res1',
        ]);

        // Azure's model list lives behind the control-plane API, not
        // `GET {base}/models`; a null catalog key is the honest "ask me
        // nothing" and pins the apiKeyHeader door in askableCatalogBase().
        $this->assertNull($provider->catalogKey());
    }

    public function testAzureWithoutResourceOrBaseUrlRefusesNamingTheTemplate(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        try {
            (new ProviderFactory())->create(['type' => 'azure', 'apiKey' => 'sk-x']);
        } catch (\InvalidArgumentException $e) {
            // The schema cannot express a one-of; this message is the
            // operator's only guide, so it must name both spellings and
            // show the derived host.
            $this->assertStringContainsString('resource', $e->getMessage());
            $this->assertStringContainsString('https://{resource}.openai.azure.com/openai/v1', $e->getMessage());
            $this->assertStringContainsString('baseUrl', $e->getMessage());

            throw $e;
        }
    }

    public function testAzureRejectsAnUnknownApiSpelling(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ProviderFactory())->create([
            'type' => 'azure',
            'apiKey' => 'sk-x',
            'resource' => 'res1',
            'api' => 'completions',
        ]);
    }

    // -------------------------------------------------------------------------
    //  azure: Responses arm on the wire
    // -------------------------------------------------------------------------

    public function testAzureResponsesArmSendsApiKeyHeaderAndNoAuthorization(): void
    {
        $provider = $this->azureResponsesProvider([
            new GuzzleResponse(200, [], json_encode($this->azureResponsesDoc(), JSON_THROW_ON_ERROR)),
        ]);

        $response = $provider->complete(new CompleteRequest(
            model: 'dep-x',
            messages: [new UserMessage('Hi')],
        ));

        $this->assertSame('Azure answers', $response->content);

        $request = $this->azureResponsesSightings[0]['request'];
        $this->assertSame('sk-azure-probe', $request->getHeaderLine('api-key'));
        $this->assertFalse($request->hasHeader('Authorization'));
        // The resource-derived base, not api.openai.com.
        $this->assertSame('https://res1.openai.azure.com/openai/v1/responses', (string) $request->getUri());
    }

    // -------------------------------------------------------------------------
    //  openrouter: the custom preset
    // -------------------------------------------------------------------------

    public function testOpenRouterPresetCarriesTheAttributionHeaders(): void
    {
        $provider = (new ProviderFactory())->create(['type' => 'openrouter', 'apiKey' => 'or-key-probe']);

        $this->assertInstanceOf(CustomProvider::class, $provider);
        $this->assertSame('openrouter', $provider->name());
        // /models is already implemented for the custom shape; the preset
        // inherits it (HasModelCatalog), which is all this step owes there.
        $this->assertInstanceOf(HasModelCatalog::class, $provider);
        $this->assertSame('https://openrouter.ai/api/v1', $provider->catalogKey());

        $this->assertSame([
            'HTTP-Referer' => 'https://github.com/sugarcraft/candy-code',
            'X-Title' => 'candy-code',
        ], $provider->defaultHeaders());
    }

    public function testOpenRouterOperatorHeadersOverrideThePresetKeyForKey(): void
    {
        $provider = (new ProviderFactory())->create([
            'type' => 'openrouter',
            'apiKey' => 'or-key-probe',
            'headers' => ['X-Title' => 'mine-probe'],
        ]);

        $headers = $provider->defaultHeaders();
        $this->assertSame('mine-probe', $headers['X-Title']);
        // Override is per key, not wholesale: the other preset row survives.
        $this->assertSame('https://github.com/sugarcraft/candy-code', $headers['HTTP-Referer']);
    }

    public function testOpenRouterAcceptsAnExplicitBaseUrl(): void
    {
        $provider = (new ProviderFactory())->create([
            'type' => 'openrouter',
            'apiKey' => 'or-key-probe',
            'baseUrl' => 'https://gw.internal/v1/',
        ]);

        $this->assertSame('https://gw.internal/v1', $provider->catalogKey());
    }

    // -------------------------------------------------------------------------
    //  custom: the new headers key
    // -------------------------------------------------------------------------

    public function testCustomProviderCarriesAnOperatorHeadersBlock(): void
    {
        $provider = (new ProviderFactory())->create([
            'type' => 'custom',
            'name' => 'gw',
            'baseUrl' => 'https://gw.test/v1',
            'model' => 'm',
            'apiKey' => 'sk-c-probe',
            'headers' => ['X-Probe' => 'ok'],
        ]);

        $this->assertInstanceOf(CustomProvider::class, $provider);
        // The built Authorization/Content-Type are the provider's own;
        // defaultHeaders() is exactly the operator-supplied map.
        $this->assertSame(['X-Probe' => 'ok'], $provider->defaultHeaders());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function malformedHeaderBlocks(): array
    {
        return [
            'owned authorization' => [['Authorization' => 'x'], 'builds that header itself'],
            'owned content-type' => [['Content-Type' => 'x'], 'builds that header itself'],
            // HTTP header names are case-insensitive, so the owned-name
            // refusal goes through strtolower — an uppercase spelling must
            // not smuggle the auth header past the floor.
            'case-variant owned name' => [['AUTHORIZATION' => 'Bearer smuggled'], 'builds that header itself'],
            'empty name' => [['' => 'x'], 'header names must be'],
            'untrimmed name' => [['X-Probe ' => 'x'], 'header names must be'],
            'colon in name' => [['X-Bad: Host' => 'x'], 'header names must be'],
            'empty value' => [['X-Probe' => ''], 'needs a non-empty string value'],
            'non-string value' => [['X-Probe' => 42], 'needs a non-empty string value'],
            'control char in value' => [["X-Probe" => "bad\rvalue"], 'control characters'],
        ];
    }

    /**
     * @param array<array-key, mixed> $headers
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedHeaderBlocks')]
    public function testCustomProviderRefusesMalformedHeaderBlocks(array $headers, string $needle): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($needle, '/') . '/');

        (new ProviderFactory())->create([
            'type' => 'custom',
            'name' => 'gw',
            'baseUrl' => 'https://gw.test/v1',
            'model' => 'm',
            'headers' => $headers,
        ]);
    }

    public function testCustomProviderRefusesAHeadersBlockThatIsNotAMap(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a map');

        (new ProviderFactory())->create([
            'type' => 'custom',
            'name' => 'gw',
            'baseUrl' => 'https://gw.test/v1',
            'model' => 'm',
            'headers' => 'nope',
        ]);
    }

    // -------------------------------------------------------------------------
    //  defaultConfig arms
    // -------------------------------------------------------------------------

    public function testDefaultConfigAzureArmReadsTheEnvAndLeavesResourceNull(): void
    {
        $previous = getenv('AZURE_OPENAI_API_KEY');
        putenv('AZURE_OPENAI_API_KEY=az-sentinel-unique');

        try {
            $config = (new ProviderFactory())->defaultConfig('azure');
            $this->assertSame('azure', $config['type']);
            $this->assertSame('az-sentinel-unique', $config['apiKey']);
            // The resource has no sane global default; null means the
            // builder's one-of refusal is what an unset block meets.
            $this->assertArrayHasKey('resource', $config);
            $this->assertNull($config['resource']);
            $this->assertSame('gpt-4o', $config['model']);
        } finally {
            putenv(false === $previous ? 'AZURE_OPENAI_API_KEY' : 'AZURE_OPENAI_API_KEY=' . $previous);
        }
    }

    public function testDefaultConfigOpenRouterArmUsesVendorModelSpellings(): void
    {
        $previous = getenv('OPENROUTER_API_KEY');
        putenv('OPENROUTER_API_KEY=or-sentinel-unique');

        try {
            $config = (new ProviderFactory())->defaultConfig('openrouter');
            $this->assertSame('openrouter', $config['type']);
            $this->assertSame('or-sentinel-unique', $config['apiKey']);
            // OpenRouter ids are vendor/model; this one exists in their
            // catalogue and matches the OpenAI price-table shape.
            $this->assertSame('openai/gpt-4o', $config['model']);
        } finally {
            putenv(false === $previous ? 'OPENROUTER_API_KEY' : 'OPENROUTER_API_KEY=' . $previous);
        }
    }
}
