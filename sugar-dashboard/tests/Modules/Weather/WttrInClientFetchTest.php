<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Modules\Weather;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Module\BoundedCommand;
use SugarCraft\Dashboard\Modules\Weather\WeatherParseFailure;
use SugarCraft\Dashboard\Modules\Weather\WeatherTransportFailure;
use SugarCraft\Dashboard\Modules\Weather\WttrInClient;

/**
 * Scripted stream wrapper for the D-109 fetch-door legs: the client's
 * bounded read, typed transport failures, and typed parse failures are
 * driven with fake endpoints — never the live wttr.in.
 */
final class ScriptedWeatherStream
{
    /** @var resource|null */
    public $context;

    public static string $body = '';
    public static bool $openFails = false;
    public static bool $abortAfterBytes = false;
    public static int $maxRead = PHP_INT_MAX;

    private int $pos = 0;

    public static function reset(): void
    {
        self::$body = '';
        self::$openFails = false;
        self::$abortAfterBytes = false;
        self::$maxRead = PHP_INT_MAX;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        if (self::$openFails) {
            return false;
        }
        $this->pos = 0;
        return true;
    }

    public function stream_read(int $count): string|false
    {
        if (self::$abortAfterBytes && $this->pos >= 8) {
            return false;
        }
        $chunk = substr(self::$body, $this->pos, min($count, self::$maxRead));
        $this->pos += strlen($chunk);
        return $chunk;
    }

    public function stream_eof(): bool
    {
        if (self::$abortAfterBytes && $this->pos >= 8) {
            return false; // the stream lies "not finished" so the next read must surface the abort
        }
        return $this->pos >= strlen(self::$body);
    }
}

final class WttrInClientFetchTest extends TestCase
{
    private const PROTOCOL = 'wttrscript';

    protected function setUp(): void
    {
        if (!in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, ScriptedWeatherStream::class);
        }
        ScriptedWeatherStream::reset();
    }

    protected function tearDown(): void
    {
        ScriptedWeatherStream::reset();
    }

    private static function client(): WttrInClient
    {
        return new WttrInClient(self::PROTOCOL . '://host/%s');
    }

    /**
     * @param array<string, mixed> $j1
     */
    private static function j1(array $j1): string
    {
        return json_encode($j1, JSON_THROW_ON_ERROR);
    }

    public function testHappyPathParsesSnapshotEndToEnd(): void
    {
        ScriptedWeatherStream::$body = self::j1([
            'current_condition' => [['temp_C' => '7', 'weatherDesc' => [['value' => 'Clear']]]],
            'nearest_area' => [['areaName' => [['value' => 'Quito']]]],
        ]);

        $snapshot = self::client()->fetch('quito');

        $this->assertSame(7.0, $snapshot->tempC);
        $this->assertSame('Clear', $snapshot->condition);
        $this->assertSame('Quito', $snapshot->location);
    }

    public function testOverCapReplyAbortsHonestReason(): void
    {
        // Acceptance probe: a scripted fake stream over 1 MiB is refused
        // mid-read with the cap named in the reason — never accumulated.
        ScriptedWeatherStream::$body = str_repeat('A', BoundedCommand::MAX_OUTPUT_BYTES + 1024);

        try {
            self::client()->fetch('anywhere');
            $this->fail('expected WeatherTransportFailure for an over-cap reply');
        } catch (WeatherTransportFailure $e) {
            $this->assertStringContainsString('exceeded the ' . BoundedCommand::MAX_OUTPUT_BYTES . '-byte size cap', $e->getMessage());
        }
    }

    public function testFailedOpenIsTypedTransportFailure(): void
    {
        ScriptedWeatherStream::$openFails = true;

        try {
            self::client()->fetch('nowhere');
            $this->fail('expected WeatherTransportFailure');
        } catch (WeatherTransportFailure $e) {
            $this->assertStringStartsWith('wttr.in request failed:', $e->getMessage());
        }
    }

    public function testMidStreamAbortIsTypedTransportFailure(): void
    {
        ScriptedWeatherStream::$body = self::j1(['current_condition' => [['temp_C' => '1']]]);
        ScriptedWeatherStream::$abortAfterBytes = true;

        $this->expectException(WeatherTransportFailure::class);
        $this->expectExceptionMessage('aborted the reply mid-stream');

        self::client()->fetch('flaky');
    }

    public function testInvalidJsonIsTypedParseFailure(): void
    {
        ScriptedWeatherStream::$body = '<html>rate limited</html>';

        $this->expectException(WeatherParseFailure::class);
        $this->expectExceptionMessage('invalid JSON');

        self::client()->fetch('anywhere');
    }

    public function testNonArrayJsonIsTypedParseFailure(): void
    {
        ScriptedWeatherStream::$body = '"just a string"';

        $this->expectException(WeatherParseFailure::class);
        $this->expectExceptionMessage('not an object');

        self::client()->fetch('anywhere');
    }

    public function testUnparseableTempYieldsNullNotConfidentZero(): void
    {
        // The API hiccup leg: temp_C present but garbage used to cast to a
        // confident 0.0°C. The reading is now honestly null.
        ScriptedWeatherStream::$body = self::j1([
            'current_condition' => [['temp_C' => 'N/A', 'weatherDesc' => [['value' => 'Fog']]]],
        ]);

        $snapshot = self::client()->fetch('anywhere');

        $this->assertNull($snapshot->tempC);
        $this->assertSame('Fog', $snapshot->condition);
    }

    public function testMissingTempLegYieldsNull(): void
    {
        ScriptedWeatherStream::$body = self::j1(['current_condition' => [[]]]);

        $this->assertNull(self::client()->fetch('anywhere')->tempC);
    }

    public function testProductionEndpointPatternUnchanged(): void
    {
        // The seam must not have moved the production door: the default
        // pattern still targets the wttr.in J1 endpoint verbatim.
        $const = new \ReflectionClassConstant(WttrInClient::class, 'BASE_URL');

        $this->assertSame('https://wttr.in/%s?format=j1', $const->getValue());
    }
}
