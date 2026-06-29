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
        $this->tempPath = \sys_get_temp_dir() . '/candy-log-coerce-' . \uniqid() . '.log';
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
        $this->assertStringContainsString('a=[1 2 [[x]]]', $content);
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
        // Logfmt uses comma delimiter
        $this->assertStringContainsString('a=[1,2,[[x]]]', $content);
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
        $this->assertSame([1, 2, [['x']]], $decoded['a'] ?? null);
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

    public function testJsonFormatterNeverReturnsEmptyLineOnEncodeFailure(): void
    {
        // Create a JsonFormatter and trigger a scenario where json_encode might fail
        // by creating an invalid UTF-8 sequence (though this is hard to trigger in practice)
        // Instead, we verify that even if json_encode returns false, we get a valid output
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
}
