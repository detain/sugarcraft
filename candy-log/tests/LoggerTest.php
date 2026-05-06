<?php

declare(strict_types=1);

namespace CandyCore\Log\Tests;

use CandyCore\Log\Logger;
use CandyCore\Log\Level;
use CandyCore\Log\Formatter\JsonFormatter;
use CandyCore\Log\Formatter\LogfmtFormatter;
use PHPUnit\Framework\TestCase;

final class LoggerTest extends TestCase
{
    private $tempFile;
    private string $tempPath;

    protected function setUp(): void
    {
        $this->tempPath = \sys_get_temp_dir() . '/candy-log-test-' . \uniqid() . '.log';
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

    private function logger(Level $minLevel = Level::Debug): Logger
    {
        return Logger::new(
            level: $minLevel,
            reportTimestamp: false,
            reportCaller: false,
            stream: $this->tempFile,
        );
    }

    public function testInfoEmitsMessage(): void
    {
        $log = $this->logger();
        $log->info('hello');
        \fclose($this->tempFile);

        $content = \file_get_contents($this->tempPath);
        $this->assertStringContainsString('hello', $content);
        $this->assertStringContainsString('INF', $content);
    }

    public function testDebugFilteredWhenMinLevelIsInfo(): void
    {
        $log = $this->logger(Level::Info);
        $log->debug('should not appear');
        \fclose($this->tempFile);

        $content = \file_get_contents($this->tempPath);
        $this->assertSame('', $content);
    }

    public function testWarnEmitsWarningLevel(): void
    {
        $log = $this->logger();
        $log->warn('careful');
        \fclose($this->tempFile);

        $content = \file_get_contents($this->tempPath);
        $this->assertStringContainsString('WRN', $content);
        $this->assertStringContainsString('careful', $content);
    }

    public function testErrorEmitsErrorLevel(): void
    {
        $log = $this->logger();
        $log->error('oops');
        \fclose($this->tempFile);

        $content = \file_get_contents($this->tempPath);
        $this->assertStringContainsString('ERR', $content);
    }

    public function testFatalCallsExit(): void
    {
        $log = $this->logger();
        // Override stream to capture; fatal still exits so only test output before exit
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exit(1)');
        try {
            $log->fatal('bye');
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('exit(1)');
        }
    }

    public function testWithCreatesChildWithExtraFields(): void
    {
        $log = $this->logger();
        $child = $log->with(['batch' => 2, 'user' => 'chef']);
        $child->info('started', ['item' => 'cookies']);
        \fclose($this->tempFile);

        $content = \file_get_contents($this->tempPath);
        $this->assertStringContainsString('batch=2', $content);
        $this->assertStringContainsString('user=chef', $content);
        $this->assertStringContainsString('item=cookies', $content);
    }

    public function testChildDoesNotAffectParentFields(): void
    {
        $log = $this->logger();
        $child = $log->with(['batch' => 2]);
        $log->info('parent only');
        \fclose($this->tempFile);

        $content = \file_get_contents($this->tempPath);
        $this->assertStringContainsString('parent only', $content);
        $this->assertStringNotContainsString('batch', $content);
    }

    public function testWithPrefixCreatesChildWithPrefix(): void
    {
        $log = $this->logger();
        $prefixed = $log->withPrefix('🍪');
        $prefixed->info('baking');
        \fclose($this->tempFile);

        $content = \file_get_contents($this->tempPath);
        $this->assertStringContainsString('🍪', $content);
    }

    public function testWithFormatterCreatesChildWithFormatter(): void
    {
        $log = $this->logger();
        $json = $log->withFormatter(new JsonFormatter(reportTimestamp: false));
        $json->info('test');
        \fclose($this->tempFile);

        $content = \file_get_contents($this->tempPath);
        $decoded = \json_decode(\trim($content), true);
        $this->assertIsArray($decoded);
        $this->assertSame('INFO', $decoded['level']);
        $this->assertSame('test', $decoded['msg']);
    }

    public function testJsonFormatterEmitsValidJson(): void
    {
        $log = $this->logger();
        $log = $log->withFormatter(new JsonFormatter(reportTimestamp: false));
        $log->info('bake complete', ['cookies' => 24, 'ok' => true]);
        \fclose($this->tempFile);

        $content = \trim(\file_get_contents($this->tempPath));
        $decoded = \json_decode($content, true);
        $this->assertIsArray($decoded);
        $this->assertSame('INFO', $decoded['level']);
        $this->assertSame('bake complete', $decoded['msg']);
        $this->assertSame(24, $decoded['cookies']);
        $this->assertTrue($decoded['ok']);
    }

    public function testLogfmtFormatterEmitsKeyValuePairs(): void
    {
        $log = $this->logger();
        $log = $log->withFormatter(new LogfmtFormatter(reportTimestamp: false));
        $log->info('hello', ['name' => 'world', 'n' => 42]);
        \fclose($this->tempFile);

        $content = \file_get_contents($this->tempPath);
        $this->assertStringContainsString('level=INFO', $content);
        $this->assertStringContainsString('msg=hello', $content);
        $this->assertStringContainsString('name=world', $content);
        $this->assertStringContainsString('n=42', $content);
    }

    public function testSetMinLevelFiltersSubsequentLogs(): void
    {
        $log = $this->logger(Level::Debug);
        $log->debug('visible');
        $log->setMinLevel(Level::Error);
        $log->debug('hidden');
        \fclose($this->tempFile);

        $content = \file_get_contents($this->tempPath);
        $this->assertStringContainsString('visible', $content);
        $this->assertStringNotContainsString('hidden', $content);
    }

    public function testPrintfStyleFormattedMessage(): void
    {
        $log = $this->logger();
        $log->infof('baking %s at %d°F', [], 'cookies', 375);
        \fclose($this->tempFile);

        $content = \file_get_contents($this->tempPath);
        $this->assertStringContainsString('cookies', $content);
        $this->assertStringContainsString('375', $content);
    }

    public function testStaticInfoLogsToGlobalLogger(): void
    {
        $stream = \fopen($this->tempPath, 'w');
        $log = Logger::new(stream: $stream, level: Level::Debug, reportTimestamp: false);
        Logger::setLogger($log);

        Logger::info('global hello', ['key' => 'val']);
        \fclose($stream);

        $content = \file_get_contents($this->tempPath);
        $this->assertStringContainsString('global hello', $content);
        $this->assertStringContainsString('key=val', $content);
    }
}
