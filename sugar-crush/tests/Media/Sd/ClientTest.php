<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Media\Sd;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Media\MediaRequest;
use SugarCraft\Crush\Media\Sd\CallableSdTransport;
use SugarCraft\Crush\Media\Sd\Endpoints;
use SugarCraft\Crush\Media\Sd\Client;
use SugarCraft\Crush\Media\Sd\HeaderAwareSdTransport;
use SugarCraft\Crush\Media\Sd\Response;
use SugarCraft\Crush\Media\Sd\SdException;
use SugarCraft\Crush\Media\Sd\SdTransport;
use SugarCraft\Crush\Media\Sd\SdTransportResult;

/**
 * W1.3 client-side laws: error mapping to SdException, wire-body edge
 * normalisation (data-URI strip, save_images derivation), precedence of the
 * base-URL chain, and the machine-lawed egress census (fact (c)).
 */
final class ClientTest extends TestCase
{
    /** @var list<array{0: string, 1: string, 2: array<string, mixed>, 3: array<string, scalar>}> */
    private array $calls = [];

    protected function tearDown(): void
    {
        putenv(Client::BASE_URL_ENV);
        parent::tearDown();
    }

    private function clientReturning(array $result): Client
    {
        return $this->clientRespondingWith(static fn (): array => $result);
    }

    /** @param callable(string, string, array, array): array $responder */
    private function clientRespondingWith(callable $responder): Client
    {
        $transport = new CallableSdTransport(
            function (string $method, string $path, array $json, array $query) use ($responder): array {
                $this->calls[] = [$method, $path, $json, $query];

                return $responder($method, $path, $json, $query);
            },
        );

        return Client::withBase('http://sd.test:7860', $transport);
    }

    // ------------------------------------------------------------------ errors

    public function testSdapiErrorEnvelopeLandsOnEverySdExceptionField(): void
    {
        $envelope = [
            'error' => 'RequestValidationError',
            'detail' => [[
                'loc' => ['body', 'steps'],
                'msg' => 'ensure this value is less than 150',
                'type' => 'value_error.number.not_lt',
            ]],
            'body' => ['prompt' => 'x', 'steps' => 400],
            'errors' => [['loc' => ['body', 'steps'], 'msg' => 'bad steps']],
        ];
        $client = $this->clientReturning(['status' => 422, 'body' => json_encode($envelope)]);

        try {
            $client->txt2img(['prompt' => 'x', 'steps' => 400]);
            self::fail('expected SdException');
        } catch (SdException $e) {
            self::assertSame(422, $e->status());
            self::assertSame('RequestValidationError', $e->error());
            self::assertStringContainsString('less than 150', $e->detail());
            self::assertStringContainsString('"steps"', $e->rawBody());
            self::assertCount(1, $e->errors());
        }
    }

    public function testNonJsonServerErrorKeepsRawDetailAndStatus(): void
    {
        $client = $this->clientReturning(['status' => 502, 'body' => '<html>Bad Gateway</html>', 'contentType' => 'text/html']);

        try {
            $client->sdModels();
            self::fail('expected SdException');
        } catch (SdException $e) {
            self::assertSame(502, $e->status());
            self::assertStringContainsString('Bad Gateway', $e->detail());
            self::assertSame('', $e->error());
        }
    }

    public function testEchoedRequestBodyIsTruncatedAndKeptOutOfTheMessage(): void
    {
        $huge = str_repeat('A', SdException::MAX_RETAINED_BODY_CHARS + 500);
        $client = $this->clientReturning(['status' => 400, 'body' => json_encode(['detail' => 'nope', 'body' => $huge])]);

        try {
            $client->txt2img(['prompt' => 'x']);
            self::fail('expected SdException');
        } catch (SdException $e) {
            self::assertStringEndsWith('…[truncated]', $e->rawBody());
            self::assertLessThanOrEqual(SdException::MAX_RETAINED_BODY_CHARS + 20, \strlen($e->rawBody()));
            self::assertStringNotContainsString('AAAA', $e->getMessage());
        }
    }

    public function testTransportThrowablesAreWrappedNeverRethrownRaw(): void
    {
        $client = $this->clientRespondingWith(static fn (): never => throw new \RuntimeException('socket said no'));

        try {
            $client->skip();
            self::fail('expected wrapped SdException');
        } catch (SdException $e) {
            self::assertSame(0, $e->status());
            self::assertStringContainsString('socket said no', $e->detail());
        }
    }

    // ----------------------------------------------------------- body edges

    public function testDataUriPrefixesAreStrippedAtTheClientEdge(): void
    {
        $client = $this->clientReturning(['status' => 200, 'body' => '{}']);
        $client->img2img([
            'init_images' => ['data:image/png;base64,QUJD', 'plainB64', 'https://host/still-a-url.png'],
            'mask' => 'data:image/png;BASE64,RElJ',
        ]);

        $sent = $this->calls[0][2];
        self::assertSame(['QUJD', 'plainB64', 'https://host/still-a-url.png'], $sent['init_images']);
        self::assertSame('RElJ', $sent['mask']);
    }

    public function testPngInfoStripsDataUriToo(): void
    {
        $client = $this->clientReturning(['status' => 200, 'body' => '{"info":""}']);
        $client->pngInfo('data:image/png;base64,WFla');

        self::assertSame('WFla', $this->calls[0][2]['image']);
    }

    public function testSaveImagesDerivesDoNotSaveFlagsUnlessCallerStatedThem(): void
    {
        $client = $this->clientReturning(['status' => 200, 'body' => '{}']);
        $client->txt2img(['prompt' => 'p', 'save_images' => true]);
        self::assertFalse($this->calls[0][2]['do_not_save_samples']);
        self::assertFalse($this->calls[0][2]['do_not_save_grid']);

        $this->calls = [];
        $client->txt2img(['prompt' => 'p', 'save_images' => true, 'do_not_save_samples' => true]);
        self::assertTrue($this->calls[0][2]['do_not_save_samples'], 'explicit field must win over derivation');

        $this->calls = [];
        $client->txt2img(['prompt' => 'p']);
        self::assertArrayNotHasKey('do_not_save_samples', $this->calls[0][2], 'no save_images stated, nothing derived');
    }

    public function testTypedMethodsSerializeMediaRequestThroughItsWireArray(): void
    {
        $client = $this->clientReturning(['status' => 200, 'body' => '{}']);
        $request = MediaRequest::new()->withPrompt('a cat')->withSteps(7);
        $client->txt2img($request);

        self::assertSame('a cat', $this->calls[0][2]['prompt']);
        self::assertSame(7, $this->calls[0][2]['steps']);
    }

    // ------------------------------------------------------------------ paths

    public function testEveryClientCallReachesTheTransportServerRelative(): void
    {
        $client = $this->clientReturning(['status' => 200, 'body' => '{}']);
        $client->txt2img(['prompt' => 'x']);
        $client->img2img(['prompt' => 'x', 'init_images' => ['QUJD']]);
        $client->progress(true);
        $client->interrupt();
        $client->options();
        $client->memory();

        foreach ($this->calls as [$method, $path]) {
            self::assertMatchesRegularExpression('#^/[a-z0-9/_-]+$#', $path, 'no absolute URL may reach the transport: ' . $path);
        }

        // progress polarity per §4.7: preview frames need skip_current_image=false.
        self::assertSame(['skip_current_image' => 'false'], $this->calls[2][3]);
        $this->calls = [];
        $client->progress(false);
        self::assertSame(['skip_current_image' => 'true'], $this->calls[0][3]);
    }

    public function testTwoStageInterruptRidesARealHeaderOnlyOnHeaderCapableTransports(): void
    {
        $headerAware = new class implements HeaderAwareSdTransport {
            /** @var list<array<string, string>> rows recorded by the header-aware path ONLY */
            public array $seenHeaders = [];

            public function request(string $method, string $path, array $json = [], array $query = []): SdTransportResult
            {
                return SdTransportResult::new(200, '{}', 'application/json');
            }

            public function requestWithHeaders(string $method, string $path, array $json = [], array $query = [], array $headers = []): SdTransportResult
            {
                $this->seenHeaders[] = $headers;

                return SdTransportResult::new(200, '{}', 'application/json');
            }
        };

        $client = Client::withBase('http://sd.test:7860', $headerAware);
        $client->interrupt(true);
        self::assertSame([['interrupt_after_current' => 'true']], $headerAware->seenHeaders);

        // Plain (immediate) interrupt stays on the generic seam.
        $client->interrupt(false);
        self::assertCount(1, $headerAware->seenHeaders, 'immediate interrupt must not fabricate a header row');

        $plainClient = $this->clientReturning(['status' => 200, 'body' => '{}']);

        $this->expectException(SdException::class);
        $this->expectExceptionMessage('header-capable transport');
        $plainClient->interrupt(true);
    }

    // ------------------------------------------------------------- base chain

    public function testExplicitOverrideOutranksTheEnvironmentVariable(): void
    {
        putenv(Client::BASE_URL_ENV . '=http://env-host:7860');

        // Env beats the layered settings when it speaks (the precedence hop
        // the env-roster guard exists for).
        self::assertSame('http://env-host:7860', Client::resolveBaseUrl());
        self::assertSame('http://explicit.test', Client::resolveBaseUrl('http://explicit.test'));
        // A blank override is "not stated", never "override with empty".
        self::assertSame('http://env-host:7860', Client::resolveBaseUrl('   '));
    }

    public function testAuthHeaderShapes(): void
    {
        self::assertSame([], Client::authHeaders(null));
        self::assertSame([], Client::authHeaders('  '));
        self::assertSame(
            ['Authorization' => 'Basic ' . base64_encode('user:pass')],
            Client::authHeaders('user:pass'),
        );
        self::assertSame(
            ['Authorization' => 'Bearer tok3n'],
            Client::authHeaders('tok3n'),
        );
    }

    // ------------------------------------------------------------------ census

    /**
     * Machine-lawed fact (c): NOTHING under src/Media references WebFetch's
     * permission-rule host FetchTarget in CODE (a docblock citing it as the
     * anti-pattern is exactly what the law wants). Token-level walk so comment
     * prose cannot false-positive and imports cannot hide.
     */
    public function testNoMediaCodeReferencesFetchTarget(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            \dirname(__DIR__, 3) . '/src/Media',
            \FilesystemIterator::SKIP_DOTS,
        ));
        $offenders = [];

        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            foreach (\token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (!\is_array($token)) {
                    continue;
                }

                if (!\in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                    && $token[1] === 'FetchTarget') {
                    $offenders[] = $file->getFilename();
                }
            }
        }

        self::assertSame([], $offenders, 'media egress must never import the WebFetch/FetchTarget dialing law');
    }

    public function testConfiguredWithoutAnyBaseRefusesWithAPointedMessage(): void
    {
        putenv(Client::BASE_URL_ENV);
        // Settings may legitimately carry sd.baseUrl on a developer box; only
        // pin the refusal when the whole chain is silent.
        if (Client::resolveBaseUrl() !== null) {
            $this->markTestSkipped('an sd.baseUrl is configured in this environment');
        }

        $this->expectException(SdException::class);
        $this->expectExceptionMessage('no SD base URL configured');
        Client::configured();
    }

    public function testReadoutPayloadShapeErrorsAreProtocolNotRaw(): void
    {
        $client = $this->clientReturning(['status' => 200, 'body' => '"a string"' ]);

        $this->expectException(SdException::class);
        $this->expectExceptionMessage('expected a JSON array payload');
        $client->samplers();
    }

    public function testAGenerationCallFeedsTheResponseBuilderEndToEnd(): void
    {
        $body = file_get_contents(__DIR__ . '/../../fixtures/sd/txt2img-response-single.json');
        self::assertNotFalse($body);
        $client = $this->clientReturning(['status' => 200, 'body' => $body, 'contentType' => 'application/json']);

        $parsed = Response::generation($client->txt2img(['prompt' => 'a cat']));

        self::assertCount(1, $parsed->artifacts());
        self::assertNull($parsed->grid());
        self::assertSame(12345, $parsed->artifacts()[0]->tokenValues()['seed']);
    }

    public function testTheEndpointRegistryIsWiredInTheClientPathFunnel(): void
    {
        // The client dials only registry members; an unregistered spelling
        // dies in resolve() before the transport is touched (calls stays 0).
        $this->expectException(SdException::class);
        $this->expectExceptionMessage('never guesses');
        Endpoints::resolve('/sdapi/v1/nope');
    }

    public function testHeaderAwareSeamExtendsTheFrozenGenericSeam(): void
    {
        // The widening is additive: every header-aware transport IS-A SdTransport,
        // so lane a's frozen consumers keep working untouched.
        self::assertTrue((new \ReflectionClass(HeaderAwareSdTransport::class))->isSubclassOf(SdTransport::class));
    }
}
