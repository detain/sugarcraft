<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\Settings\SettingsSchema;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\TransientFailure;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * Roadmap N-P4a: the retry policy's two constants are the
 * `providerRetryAttempts` / `providerRetryBaseBackoffMs` settings.
 *
 * Pins the parsing (nonsense falls back, never clamps), the bound that makes
 * the knobs safe — no honoured pair can sleep longer than half the smallest
 * idle ceiling an operator may choose, measured from the SCHEMA's ranges so a
 * widened range reds here — and the wiring: {@see Runtime}'s retry loop
 * really makes the configured number of calls.
 */
final class RetrySettingsTest extends TestCase
{
    use HomeSandboxTrait;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/sc_retry_settings_' . bin2hex(random_bytes(6));
        $this->useHomeSandbox($this->dir . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        $this->removeTree($this->dir);

        parent::tearDown();
    }

    public function testTheDefaultsAreTheConstantsTheSchemaNames(): void
    {
        self::assertSame(TransientFailure::MAX_ATTEMPTS, TransientFailure::maxAttempts());
        self::assertSame(TransientFailure::BASE_BACKOFF_MICROSECONDS, TransientFailure::baseBackoffMicroseconds());

        self::assertSame(TransientFailure::MAX_ATTEMPTS, SettingsSchema::byKey('providerRetryAttempts')?->default);
        self::assertSame(TransientFailure::BASE_BACKOFF_MICROSECONDS, 1000 * SettingsSchema::byKey('providerRetryBaseBackoffMs')?->default);
    }

    public function testThePersistedSettingsAreRead(): void
    {
        Bootstrap::writeUserConfig(['providerRetryAttempts' => 5, 'providerRetryBaseBackoffMs' => 250]);

        self::assertSame(5, TransientFailure::maxAttempts());
        self::assertSame(250_000, TransientFailure::baseBackoffMicroseconds());
        self::assertSame(250_000 * (1 + 2 + 4 + 8), TransientFailure::totalBackoffMicroseconds());
    }

    #[DataProvider('attempts')]
    public function testAttemptsParsing(mixed $value, int $expected): void
    {
        self::assertSame($expected, TransientFailure::maxAttempts(['providerRetryAttempts' => $value]));
    }

    /** @return array<string, array{0: mixed, 1: int}> */
    public static function attempts(): array
    {
        $default = TransientFailure::MAX_ATTEMPTS;

        return [
            'one never retries' => [1, 1],
            'the ceiling' => [TransientFailure::MAX_RETRY_ATTEMPTS_SETTING, TransientFailure::MAX_RETRY_ATTEMPTS_SETTING],
            'numeric string' => ['2', 2],
            'integral float' => [4.0, 4],
            'zero' => [0, $default],
            'past the ceiling' => [TransientFailure::MAX_RETRY_ATTEMPTS_SETTING + 1, $default],
            'fraction' => [2.5, $default],
            'word' => ['many', $default],
            'bool' => [true, $default],
            'null' => [null, $default],
        ];
    }

    #[DataProvider('backoffs')]
    public function testBackoffParsing(mixed $value, int $expectedMicros): void
    {
        self::assertSame($expectedMicros, TransientFailure::baseBackoffMicroseconds(['providerRetryBaseBackoffMs' => $value]));
    }

    /** @return array<string, array{0: mixed, 1: int}> */
    public static function backoffs(): array
    {
        $default = TransientFailure::BASE_BACKOFF_MICROSECONDS;

        return [
            'zero retries at once' => [0, 0],
            'the ceiling' => [TransientFailure::MAX_BASE_BACKOFF_MS_SETTING, TransientFailure::MAX_BASE_BACKOFF_MS_SETTING * 1000],
            'numeric string' => ['125', 125_000],
            'past the ceiling' => [TransientFailure::MAX_BASE_BACKOFF_MS_SETTING + 1, $default],
            'negative' => [-1, $default],
            'word' => ['soon', $default],
        ];
    }

    public function testTheScheduleFollowsBothSettings(): void
    {
        $config = ['providerRetryAttempts' => 4, 'providerRetryBaseBackoffMs' => 100];

        self::assertSame(100_000, TransientFailure::backoffMicroseconds(1, $config));
        self::assertSame(200_000, TransientFailure::backoffMicroseconds(2, $config));
        self::assertSame(400_000, TransientFailure::backoffMicroseconds(3, $config));
        self::assertSame(0, TransientFailure::backoffMicroseconds(4, $config), 'no wait is owed after the last attempt');
        self::assertSame(0, TransientFailure::backoffMicroseconds(1, ['providerRetryAttempts' => 1]), 'one attempt owes no wait at all');
    }

    /**
     * The safety argument for exposing the knobs at all: no frame is written
     * while a retry sequence sleeps, so its longest silence must stay well
     * under the SMALLEST idle ceiling an operator may set — derived from the
     * schema's own ranges, so widening either range without re-reading this
     * reds here.
     */
    public function testNoHonouredPairCanSleepPastHalfTheSmallestIdleCeiling(): void
    {
        $attempts = SettingsSchema::byKey('providerRetryAttempts');
        $backoff = SettingsSchema::byKey('providerRetryBaseBackoffMs');
        $idle = SettingsSchema::byKey('turnIdleTimeoutSeconds');
        self::assertNotNull($attempts);
        self::assertNotNull($backoff);
        self::assertNotNull($idle);

        self::assertSame(TransientFailure::MAX_RETRY_ATTEMPTS_SETTING, $attempts->max);
        self::assertSame(TransientFailure::MAX_BASE_BACKOFF_MS_SETTING, $backoff->max);
        self::assertSame(EngineBackend::MIN_TURN_IDLE_TIMEOUT_SECONDS, $idle->min);

        $worst = TransientFailure::totalBackoffMicroseconds([
            'providerRetryAttempts' => $attempts->max,
            'providerRetryBaseBackoffMs' => $backoff->max,
        ]);
        self::assertSame($worst, TransientFailure::maxTotalBackoffMicroseconds());
        self::assertLessThanOrEqual((int) ($idle->min * 1_000_000 / 2), $worst);
    }

    /** The wiring: Runtime's batch loop makes exactly the configured number of calls. */
    public function testTheRuntimeRetryLoopHonoursTheConfiguredAttempts(): void
    {
        Bootstrap::writeUserConfig(['providerRetryAttempts' => 1]);
        $provider = new ScriptedProvider([self::transientServerError(), new CompleteResponse(content: 'never reached')]);

        try {
            iterator_to_array(self::retryRuntime($provider)->run(self::retryApp($provider)));
            self::fail('one attempt means the first transient failure surfaces');
        } catch (ServerException) {
        }

        self::assertCount(1, $provider->requests, 'providerRetryAttempts: 1 must not retry');

        Bootstrap::writeUserConfig(['providerRetryAttempts' => 2, 'providerRetryBaseBackoffMs' => 0]);
        $provider = new ScriptedProvider([self::transientServerError(), new CompleteResponse(content: 'recovered')]);

        $messages = iterator_to_array(self::retryRuntime($provider)->run(self::retryApp($provider)));

        self::assertCount(2, $provider->requests);
        self::assertSame('recovered', $messages[0]->content());
    }

    /** The streaming loop reads the same setting. */
    public function testTheStreamingRetryLoopHonoursTheConfiguredAttempts(): void
    {
        Bootstrap::writeUserConfig(['providerRetryAttempts' => 1]);
        $provider = new ScriptedProvider([self::transientServerError(), new CompleteResponse(content: 'never reached')], streams: true);

        try {
            iterator_to_array(self::retryRuntime($provider)->run(self::retryApp($provider)));
            self::fail('one attempt means the first transient failure surfaces');
        } catch (ServerException) {
        }

        self::assertCount(1, $provider->requests);
    }

    private static function transientServerError(): ServerException
    {
        return new ServerException('503 Service Unavailable', new Request('POST', 'https://example.invalid/v1'), new Response(503));
    }

    private static function retryRuntime(ScriptedProvider $provider): Runtime
    {
        return new Runtime($provider, new HookManager(new HookRegistry()));
    }

    private static function retryApp(ScriptedProvider $provider): App
    {
        return App::new($provider, 'test-model')->withMessages([new UserMessage('go')]);
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
