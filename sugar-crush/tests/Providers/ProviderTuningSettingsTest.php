<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\Settings\SettingsSchema;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CustomProvider;
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * Roadmap N-P4a, the transport half: `connectTimeoutSeconds` (the persisted
 * form of `SUGARCRUSH_CONNECT_TIMEOUT`), `streamIdleTimeoutSeconds` and the
 * `custom` provider's `temperature`.
 *
 * Neither bound may become a total deadline — {@see ProviderConnectTimeoutTest}
 * pins that for every provider; this file pins only that each setting
 * reaches the option it replaces, and that nonsense falls back.
 */
final class ProviderTuningSettingsTest extends TestCase
{
    use HomeSandboxTrait;

    private string $dir;

    private string|false $originalConnectEnv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/sc_provider_tuning_' . bin2hex(random_bytes(6));
        $this->useHomeSandbox($this->dir . '/home');

        $this->originalConnectEnv = getenv('SUGARCRUSH_CONNECT_TIMEOUT');
        putenv('SUGARCRUSH_CONNECT_TIMEOUT');
    }

    protected function tearDown(): void
    {
        $this->originalConnectEnv === false
            ? putenv('SUGARCRUSH_CONNECT_TIMEOUT')
            : putenv('SUGARCRUSH_CONNECT_TIMEOUT=' . $this->originalConnectEnv);
        $this->restoreHomeSandbox();
        $this->removeTree($this->dir);

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // connectTimeoutSeconds
    // -------------------------------------------------------------------------

    public function testTheDefaultIsTheConstantTheSchemaNames(): void
    {
        $constant = (new \ReflectionClassConstant(SglangProvider::class, 'CONNECT_TIMEOUT_SECONDS'))->getValue();

        self::assertSame($constant, self::connectBound());
        self::assertSame($constant, SettingsSchema::byKey('connectTimeoutSeconds')?->default);
        self::assertSame('SUGARCRUSH_CONNECT_TIMEOUT', SettingsSchema::byKey('connectTimeoutSeconds')?->envVar);
    }

    public function testThePersistedSettingSetsTheClientsConnectBound(): void
    {
        Bootstrap::writeUserConfig(['connectTimeoutSeconds' => 4.5]);

        self::assertSame(4.5, self::connectBound());
    }

    public function testTheEnvVarOutranksTheSettingWhenItSaysSomething(): void
    {
        Bootstrap::writeUserConfig(['connectTimeoutSeconds' => 4.5]);

        putenv('SUGARCRUSH_CONNECT_TIMEOUT=2.5');
        self::assertSame(2.5, self::connectBound());

        // A rejected env value is not a statement: it falls through to the
        // persisted one rather than straight to the default.
        foreach (['0', 'soon', '', '0.0001'] as $bogus) {
            putenv('SUGARCRUSH_CONNECT_TIMEOUT=' . $bogus);
            self::assertSame(4.5, self::connectBound(), var_export($bogus, true) . ' must fall through to the setting');
        }
    }

    #[DataProvider('nonsenseConnect')]
    public function testANonsenseSettingFallsBackToTheDefault(mixed $value): void
    {
        Bootstrap::writeUserConfig(['connectTimeoutSeconds' => $value]);

        self::assertSame(
            (new \ReflectionClassConstant(SglangProvider::class, 'CONNECT_TIMEOUT_SECONDS'))->getValue(),
            self::connectBound(),
        );
    }

    /** @return array<string, array{0: mixed}> */
    public static function nonsenseConnect(): array
    {
        return [
            'zero would mean "transport default", the 300 s hang' => [0],
            'sub-millisecond truncates to 0 on the wire' => [0.0004],
            'negative' => [-3],
            'word' => ['fast'],
            'bool' => [true],
        ];
    }

    /** The streaming path takes the CLIENT's bound, so both transports agree. */
    public function testTheStreamingPathUsesTheClientsConnectBound(): void
    {
        Bootstrap::writeUserConfig(['connectTimeoutSeconds' => 4.5]);
        $client = self::clientOf(SglangProvider::openAiCompatible('https://sglang.invalid/v1'));

        Bootstrap::writeUserConfig(['connectTimeoutSeconds' => 9.0]);
        $options = self::captureHandlerOptions($client, ['stream' => true]);

        self::assertSame(4.5, $options['timeout'], 'the stream path must not disagree with the client it rides on');
    }

    // -------------------------------------------------------------------------
    // streamIdleTimeoutSeconds
    // -------------------------------------------------------------------------

    public function testTheStreamReadIdleBoundIsReadPerRequest(): void
    {
        $client = self::clientOf(SglangProvider::openAiCompatible('https://sglang.invalid/v1'));
        $default = (new \ReflectionClassConstant(SglangProvider::class, 'STREAM_READ_IDLE_TIMEOUT_SECONDS'))->getValue();

        self::assertSame($default, self::captureHandlerOptions($client, ['stream' => true])['read_timeout']);
        self::assertSame((float) $default, (float) SettingsSchema::byKey('streamIdleTimeoutSeconds')?->default);

        // Saved AFTER the client was built: the next request sees it.
        Bootstrap::writeUserConfig(['streamIdleTimeoutSeconds' => 900]);
        self::assertSame(900.0, self::captureHandlerOptions($client, ['stream' => true])['read_timeout']);

        // Under the floor a read bound would abort a thinking model.
        Bootstrap::writeUserConfig(['streamIdleTimeoutSeconds' => 5]);
        self::assertSame($default, self::captureHandlerOptions($client, ['stream' => true])['read_timeout']);

        // And it never reaches a non-streaming request, where `timeout` /
        // `read_timeout` would be a total deadline on curl.
        Bootstrap::writeUserConfig(['streamIdleTimeoutSeconds' => 900]);
        self::assertArrayNotHasKey('read_timeout', self::captureHandlerOptions($client, []));
    }

    // -------------------------------------------------------------------------
    // temperature (custom provider)
    // -------------------------------------------------------------------------

    public function testTheCustomProviderSendsTheConfiguredTemperature(): void
    {
        self::assertSame(CustomProvider::DEFAULT_TEMPERATURE, $this->sentTemperature(new CompleteRequest(model: 'm', messages: [new UserMessage('hi')])));

        Bootstrap::writeUserConfig(['temperature' => 0.2]);
        self::assertSame(0.2, $this->sentTemperature(new CompleteRequest(model: 'm', messages: [new UserMessage('hi')])));

        self::assertSame(
            1.5,
            $this->sentTemperature(new CompleteRequest(model: 'm', messages: [new UserMessage('hi')], temperature: 1.5)),
            'a request that names a temperature still wins',
        );

        foreach ([2.5, -0.1, 'warm', true] as $bogus) {
            Bootstrap::writeUserConfig(['temperature' => $bogus]);
            self::assertSame(
                CustomProvider::DEFAULT_TEMPERATURE,
                $this->sentTemperature(new CompleteRequest(model: 'm', messages: [new UserMessage('hi')])),
                var_export($bogus, true) . ' must fall back',
            );
        }
    }

    // -------------------------------------------------------------------------
    // helpers
    // -------------------------------------------------------------------------

    private static function connectBound(): float
    {
        return self::clientOf(SglangProvider::openAiCompatible('https://sglang.invalid/v1'))->getConfig('connect_timeout');
    }

    private function sentTemperature(CompleteRequest $request): float
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], (string) json_encode([
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ]))]));
        $stack->push(Middleware::history($history));
        $provider = new CustomProvider(
            'custom',
            'https://api.example.com',
            'm',
            null,
            new Client(['base_uri' => 'https://api.example.com/', 'handler' => $stack]),
            false,
            true,
        );

        $provider->complete($request);
        self::assertCount(1, $history);
        $body = json_decode((string) $history[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);

        return (float) $body['temperature'];
    }

    /**
     * @param array<string, mixed> $requestOptions
     *
     * @return array<string, mixed>
     */
    private static function captureHandlerOptions(Client $client, array $requestOptions): array
    {
        $stack = $client->getConfig('handler');
        self::assertInstanceOf(HandlerStack::class, $stack);

        $captured = [];
        $stack->setHandler(static function (RequestInterface $request, array $options) use (&$captured): PromiseInterface {
            $captured = $options;

            return new FulfilledPromise(new Response(200, [], 'ok'));
        });

        $client->request('POST', 'chat/completions', $requestOptions + ['json' => ['probe' => true]]);

        return $captured;
    }

    private static function clientOf(object $provider): Client
    {
        $property = new \ReflectionProperty($provider, 'httpClient');
        $client = $property->getValue($provider);
        \assert($client instanceof Client);

        return $client;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
