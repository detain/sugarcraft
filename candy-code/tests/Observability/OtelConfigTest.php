<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Observability;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Observability\EventSink;
use SugarCraft\Code\Observability\NullEventSink;
use SugarCraft\Code\Observability\OtelConfig;
use SugarCraft\Code\Observability\OtelSink;

/**
 * OBS-1: the OTLP configuration is environment-only and dark by default.
 * No endpoint means no exporter is ever BUILT — the build callback is not
 * invoked, so the dark path cannot reach the network even by accident.
 */
final class OtelConfigTest extends TestCase
{
    /** @var list<string> */
    private const MANAGED = [
        OtelConfig::ENDPOINT_ENV,
        OtelConfig::HEADERS_ENV,
        OtelConfig::CANDY_CODE_OTEL_TIMEOUT_ENV,
        OtelConfig::CANDY_CODE_OTEL_BATCH_ENV,
    ];

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (self::MANAGED as $name) {
            $this->savedEnv[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
        $this->savedEnv = [];
    }

    public function testUnsetEndpointReadsAsDark(): void
    {
        $config = OtelConfig::fromEnvironment();

        $this->assertNull($config->endpoint);
        $this->assertFalse($config->exportsEvents());
        $this->assertSame(OtelConfig::DEFAULT_EXPORT_TIMEOUT_SECONDS, $config->exportTimeoutSeconds);
        $this->assertSame(OtelConfig::DEFAULT_BATCH_SIZE, $config->batchSize);
    }

    public function testEmptyAndBlankEndpointsStayDark(): void
    {
        foreach (['', '   ', "\t"] as $blank) {
            putenv(OtelConfig::ENDPOINT_ENV . '=' . $blank);

            $this->assertFalse(OtelConfig::fromEnvironment()->exportsEvents(), "blank form rejected: {$blank}");
        }
    }

    public function testPresentEndpointLightsTheConfiguration(): void
    {
        putenv(OtelConfig::ENDPOINT_ENV . '= http://localhost:4318 ');

        $config = OtelConfig::fromEnvironment();

        $this->assertTrue($config->exportsEvents());
        $this->assertSame('http://localhost:4318', $config->endpoint, 'surrounding whitespace is trimmed');
    }

    public function testDarkSinkBuildsNoExporterWhatsoever(): void
    {
        $built = false;
        $sink = OtelConfig::sinkFromEnvironment(
            /** @param OtelConfig $c */
            static function (OtelConfig $c) use (&$built): ?EventSink {
                $built = true;

                return null;
            },
        );

        $this->assertFalse($built, 'the factory must never run while dark');
        $this->assertInstanceOf(NullEventSink::class, $sink);
    }

    public function testLitSinkIsTheFactoryProductAndRefusalsFallBackToNull(): void
    {
        putenv(OtelConfig::ENDPOINT_ENV . '=http://collector:4318');

        $installed = new NullEventSink();
        $this->assertSame($installed, OtelConfig::sinkFromEnvironment(static fn (): EventSink => $installed));
        $this->assertInstanceOf(NullEventSink::class, OtelConfig::sinkFromEnvironment(static fn (): ?EventSink => null));
    }

    public function testTheProductionSeamAnswersNullWhenTheEnvironmentIsDark(): void
    {
        // The dark-by-default pin: with no endpoint this static must build
        // NOTHING but the null sink — no client, no registration.
        $this->assertInstanceOf(NullEventSink::class, Bootstrap::observabilitySink());
    }

    public function testHeadersParsePairwiseAndDropMalformedPairs(): void
    {
        putenv(OtelConfig::ENDPOINT_ENV . '=http://collector:4318');
        putenv(OtelConfig::HEADERS_ENV . '= Authorization=Bearer x=y ,novalue, , =orphan-key, api-key =k2 ');

        $config = OtelConfig::fromEnvironment();

        $this->assertSame(
            ['Authorization' => 'Bearer x=y', 'api-key' => 'k2'],
            $config->headers,
            'first = splits; pair-less junk dropped; keys and values trimmed',
        );
    }

    public function testTimeoutAcceptsPositiveFractionalAndFallsBackOnNonsense(): void
    {
        putenv(OtelConfig::ENDPOINT_ENV . '=http://collector:4318');

        foreach ([['2.5', 2.5], ['abc', 3.0], ['0', 3.0], ['-1', 3.0], ['', 3.0]] as [$raw, $want]) {
            putenv(OtelConfig::CANDY_CODE_OTEL_TIMEOUT_ENV . '=' . $raw);

            $this->assertSame($want, OtelConfig::fromEnvironment()->exportTimeoutSeconds, "timeout form {$raw}");
        }
    }

    public function testBatchSizeClampsIntoRangeAndReadsJunkAsDefault(): void
    {
        putenv(OtelConfig::ENDPOINT_ENV . '=http://collector:4318');

        foreach ([['8', 8], ['0', 1], ['-7', 1], ['99999', 2048], ['2.5', 64], ['x', 64]] as [$raw, $want]) {
            putenv(OtelConfig::CANDY_CODE_OTEL_BATCH_ENV . '=' . $raw);

            $this->assertSame($want, OtelConfig::fromEnvironment()->batchSize, "batch form {$raw}");
        }
    }

    public function testDarkFactoryShapeIsStableForTheConfigTheSinkCarries(): void
    {
        putenv(OtelConfig::ENDPOINT_ENV . '=http://collector:4318');
        $seen = null;
        OtelConfig::sinkFromEnvironment(
            /** @param OtelConfig $c */
            static function (OtelConfig $c) use (&$seen): EventSink {
                $seen = $c;

                return OtelSink::withTransport(static fn (string $body): bool => true);
            },
        );

        $this->assertInstanceOf(OtelConfig::class, $seen);
        $this->assertSame('http://collector:4318', $seen?->endpoint);
    }
}
