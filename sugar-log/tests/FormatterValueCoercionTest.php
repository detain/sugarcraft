<?php

declare(strict_types=1);

namespace SugarCraft\Log\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Log\Formatter\JsonFormatter;
use SugarCraft\Log\Formatter\LogfmtFormatter;
use SugarCraft\Log\Formatter\TextFormatter;
use SugarCraft\Log\Level;
use SugarCraft\Log\Logger;

/**
 * Tests for formatter value coercion edge cases.
 * Covers: nested arrays, objects without __toString, DateTime values,
 * and JsonFormatter encode-failure protection.
 */
final class FormatterValueCoercionTest extends TestCase
{
    private string $tempPath;
    /** @var resource */
    private $tempFile;

    protected function setUp(): void
    {
        $this->tempPath = \sys_get_temp_dir() . '/sugar-log-coerce-' . \uniqid() . '.log';
        $this->tempFile = \fopen($this->tempPath, 'w');
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->tempFile)) {
            \fclose($this->tempFile);
        }
        if (\file_exists($this->tempPath)) {
            \unlink($this->tempPath);
        }
    }

    public function testNestedArrayThroughTextFormatter(): void
    {
        $log = Logger::new(
            formatter: new TextFormatter(false, null, false, false),
            level: Level::Debug,
            reportTimestamp: false,
            reportCaller: false,
            stream: $this->tempFile,
        );
        $log->info('test', ['a' => [1, 2, ['x']]]);
        \fclose($this->tempFile);

        $content = \file_get_contents($this->tempPath);
        // Array rendered with space delimiter, nested [x] stays as [x]
        $this->assertStringContainsString('a=[1 2 [x]]', $content);
    }

    public function testNestedArrayThroughLogfmtFormatter(): void
    {
        $log = Logger::new(
            formatter: new LogfmtFormatter(false),
            level: Level::Debug,
            reportTimestamp: false,
            reportCaller: false,
            stream: $this->tempFile,
        );
        $log->info('test', ['a' => [1, 2, ['x']]]);
        \fclose($this->tempFile);

        $content = \file_get_contents($this->tempPath);
        // Logfmt uses comma delimiter, nested [x] stays as [x]
        $this->assertStringContainsString('a=[1,2,[x]]', $content);
    }

    public function testNestedArrayThroughJsonFormatter(): void
    {
        $log = Logger::new(
            formatter: new JsonFormatter(false),
            level: Level::Debug,
            reportTimestamp: false,
            reportCaller: false,
            stream: $this->tempFile,
        );
        $log->info('test', ['a' => [1, 2, ['x']]]);
        \fclose($this->tempFile);

        $content = \file_get_contents($this->tempPath);
        $decoded = \json_decode(\trim($content), true);
        $this->assertIsArray($decoded);
        // Input [1, 2, ['x']] remains [1, 2, ['x']] in JSON
        $this->assertSame([1, 2, ['x']], $decoded['a'] ?? null);
    }

    public function testObjectWithoutToStringThroughTextFormatter(): void
    {
        $log = Logger::new(
            formatter: new TextFormatter(false, null, false, false),
            level: Level::Debug,
            reportTimestamp: false,
            reportCaller: false,
            stream: $this->tempFile,
        );
        $log->info('test', ['obj' => new \stdClass()]);
        \fclose($this->tempFile);

        $content = \file_get_contents($this->tempPath);
        // stdClass should be rendered as "stdClass"
        $this->assertStringContainsString('obj=stdClass', $content);
    }

    public function testObjectWithoutToStringThroughLogfmtFormatter(): void
    {
        $log = Logger::new(
            formatter: new LogfmtFormatter(false),
            level: Level::Debug,
            reportTimestamp: false,
            reportCaller: false,
            stream: $this->tempFile,
        );
        $log->info('test', ['obj' => new \stdClass()]);
        \fclose($this->tempFile);

        $content = \file_get_contents($this->tempPath);
        $this->assertStringContainsString('obj=stdClass', $content);
    }

    public function testObjectWithoutToStringThroughJsonFormatter(): void
    {
        $log = Logger::new(
            formatter: new JsonFormatter(false),
            level: Level::Debug,
            reportTimestamp: false,
            reportCaller: false,
            stream: $this->tempFile,
        );
        $log->info('test', ['obj' => new \stdClass()]);
        \fclose($this->tempFile);

        $content = \file_get_contents($this->tempPath);
        $decoded = \json_decode(\trim($content), true);
        $this->assertIsArray($decoded);
        // JsonFormatter should return class name as string for object without __toString
        $this->assertSame('stdClass', $decoded['obj'] ?? null);
    }

    public function testDateTimeImmutableDoesNotThrow(): void
    {
        $log = Logger::new(
            formatter: new TextFormatter(false, null, false, false),
            level: Level::Debug,
            reportTimestamp: false,
            reportCaller: false,
            stream: $this->tempFile,
        );
        // This should NOT throw - DateTimeImmutable doesn't have __toString
        $log->info('test', ['date' => new \DateTimeImmutable('2026-01-15')]);
        \fclose($this->tempFile);

        $content = \file_get_contents($this->tempPath);
        // DateTimeImmutable renders as its class name
        $this->assertStringContainsString('DateTimeImmutable', $content);
    }

    public function testJsonFormatterOrdinaryContextAlwaysWritesValidJsonLine(): void
    {
        // The name this test carried claimed an encode-failure scenario; there is
        // none — ValueCoercion normalises everything the encoder could choke on
        // (non-finite floats to 'INF'/'-INF'/'NAN', resources to null, cycles to
        // '[...]') BEFORE encoding, which is exactly why the per-field belt in
        // JsonFormatter is a by-construction-unreachable defence. What this pins
        // is the ordinary-shape guarantee: a context line is never empty and is
        // always decodable.
        $log = Logger::new(
            formatter: new JsonFormatter(false),
            level: Level::Debug,
            reportTimestamp: false,
            reportCaller: false,
            stream: $this->tempFile,
        );
        $log->info('test', ['normal' => 'value']);
        \fclose($this->tempFile);

        $content = \file_get_contents($this->tempPath);
        $this->assertNotSame('', \trim($content));
        // Should be valid JSON
        $decoded = \json_decode(\trim($content), true);
        $this->assertIsArray($decoded);
        $this->assertSame('value', $decoded['normal'] ?? null);
    }

    public function testBoolAndNullCoercion(): void
    {
        $log = Logger::new(
            formatter: new TextFormatter(false, null, false, false),
            level: Level::Debug,
            reportTimestamp: false,
            reportCaller: false,
            stream: $this->tempFile,
        );
        $log->info('test', ['yes' => true, 'no' => false, 'nil' => null]);
        \fclose($this->tempFile);

        $content = \file_get_contents($this->tempPath);
        $this->assertStringContainsString('yes=true', $content);
        $this->assertStringContainsString('no=false', $content);
        $this->assertStringContainsString('nil=null', $content);
    }

    // -------------------------------------------------------------------------
    // ValueCoercion::stringify() edge cases
    // -------------------------------------------------------------------------

    public function testStringifyResource(): void
    {
        $resource = \fopen(__FILE__, 'r');
        $result = \SugarCraft\Log\Formatter\ValueCoercion::stringify($resource);
        $this->assertSame('resource', $result);
        \fclose($resource);
    }

    public function testStringifyCallable(): void
    {
        $result = \SugarCraft\Log\Formatter\ValueCoercion::stringify(fn() => 42);
        $this->assertSame('Closure', $result);
    }

    public function testStringifyAnonymousClass(): void
    {
        $obj = new class {};
        $result = \SugarCraft\Log\Formatter\ValueCoercion::stringify($obj);
        $this->assertSame('object', $result);
    }

    public function testStringifyMaxDepthExceeded(): void
    {
        // Create deeply nested array beyond MAX_DEPTH (4)
        // At depth=5 (exceeds MAX_DEPTH=4), returns '[...]'
        // But this '[...]' is then wrapped by parent array processing
        // Final output: "[1 [2 [3 [4 [[...]]]]]]"
        $deep = [1, [2, [3, [4, [5]]]]];
        $result = \SugarCraft\Log\Formatter\ValueCoercion::stringify($deep);
        $this->assertSame('[1 [2 [3 [4 [[...]]]]]]', $result);
    }

    // -------------------------------------------------------------------------
    // ValueCoercion::coerce() for JsonFormatter
    // -------------------------------------------------------------------------

    public function testCoerceResourceReturnsNull(): void
    {
        $resource = \fopen(__FILE__, 'r');
        $result = \SugarCraft\Log\Formatter\ValueCoercion::coerce($resource);
        $this->assertNull($result);
        \fclose($resource);
    }

    public function testCoerceObjectWithToString(): void
    {
        $obj = new class {
            public function __toString(): string
            {
                return 'custom-string';
            }
        };
        $result = \SugarCraft\Log\Formatter\ValueCoercion::coerce($obj);
        $this->assertSame('custom-string', $result);
    }

    public function testCoerceObjectWithoutToStringReturnsClassName(): void
    {
        // RuntimeException has __toString, so use stdClass instead
        $obj = new \stdClass();
        $result = \SugarCraft\Log\Formatter\ValueCoercion::coerce($obj);
        $this->assertSame('stdClass', $result);
    }

    public function testCoerceNestedArray(): void
    {
        $result = \SugarCraft\Log\Formatter\ValueCoercion::coerce(['a' => 1, 'b' => [2, 3]]);
        $this->assertSame(['a' => 1, 'b' => [2, 3]], $result);
    }

    public function testCoerceIntactJsonTypes(): void
    {
        $this->assertSame(42, \SugarCraft\Log\Formatter\ValueCoercion::coerce(42));
        $this->assertSame(3.14, \SugarCraft\Log\Formatter\ValueCoercion::coerce(3.14));
        $this->assertSame(true, \SugarCraft\Log\Formatter\ValueCoercion::coerce(true));
        $this->assertSame('string', \SugarCraft\Log\Formatter\ValueCoercion::coerce('string'));
        $this->assertNull(\SugarCraft\Log\Formatter\ValueCoercion::coerce(null));
    }

    // -------------------------------------------------------------------------
    // [SL-32] Shortest-roundtrip float text
    // -------------------------------------------------------------------------

    public function testFloatsStringifyWithShortestRoundtripDigits(): void
    {
        // The text arm used to honour php.ini `precision` (this host: 14),
        // so 1.0000000000000002 collapsed to "1" on some distros and not
        // others. serialize_precision=-1 digits are now pinned for the
        // representative cases, including the exponent form Go's %v prints.
        $coerce = \SugarCraft\Log\Formatter\ValueCoercion::class;
        $this->assertSame('0.1', $coerce::stringify(0.1));
        $this->assertSame('1.0000000000000002', $coerce::stringify(1.0000000000000002));
        $this->assertSame('1.0e+25', $coerce::stringify(1e25));
        $this->assertSame('1', $coerce::stringify(1.0));
        $this->assertSame('INF', $coerce::stringify(INF));
        $this->assertSame('-INF', $coerce::stringify(-INF));
        $this->assertSame('NAN', $coerce::stringify(NAN));
    }

    public function testFloatBytesAgreeAcrossTextLogfmtAndJsonArms(): void
    {
        // One float, one spelling, everywhere: the collapse-prone edge value
        // must reach the wire identically in all three formatter arms.
        $context = ['edge' => 1.0000000000000002, 'big' => 1e25, 'whole' => 1.0];

        $textHandle = \fopen('php://memory', 'r+');
        Logger::new(new TextFormatter(reportTimestamp: false), stream: $textHandle)
            ->withPrefix('')->info('m', $context);
        \rewind($textHandle);
        $text = \stream_get_contents($textHandle);
        \fclose($textHandle);

        $logfmtHandle = \fopen('php://memory', 'r+');
        Logger::new(new LogfmtFormatter(reportTimestamp: false), stream: $logfmtHandle)
            ->info('m', $context);
        \rewind($logfmtHandle);
        $logfmt = \stream_get_contents($logfmtHandle);
        \fclose($logfmtHandle);

        $jsonHandle = \fopen('php://memory', 'r+');
        Logger::new(new JsonFormatter(reportTimestamp: false), stream: $jsonHandle)
            ->info('m', $context);
        \rewind($jsonHandle);
        $json = \stream_get_contents($jsonHandle);
        \fclose($jsonHandle);

        foreach (['edge=1.0000000000000002', 'big=1.0e+25', 'whole=1'] as $needle) {
            $this->assertStringContainsString($needle, $text, 'text arm: ' . $needle);
            $this->assertStringContainsString($needle, $logfmt, 'logfmt arm: ' . $needle);
        }
        $this->assertStringContainsString('"edge":1.0000000000000002', $json);
        $this->assertStringContainsString('"big":1.0e+25', $json);
        $this->assertStringContainsString('"whole":1', $json);
    }

    // -------------------------------------------------------------------------
    // JsonFormatter json_encode failure fallback
    // -------------------------------------------------------------------------

    public function testJsonFormatterEmptyContextKeepsEnvelopeShape(): void
    {
        // Formerly "HandlesEncodeFailure" — it handled no failure: an empty
        // context encodes trivially. The honest claim it pins is the envelope
        // floor: even the barest record carries level (and decodes).
        $formatter = new JsonFormatter(false);
        $line = $formatter->format(Level::Info, 'test', [], new \DateTimeImmutable(), null, null);

        $decoded = \json_decode(\trim($line), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('level', $decoded);
        $this->assertSame('test', $decoded['msg']);
    }
}
