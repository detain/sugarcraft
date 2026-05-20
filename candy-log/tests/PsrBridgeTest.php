<?php

declare(strict_types=1);

namespace SugarCraft\Log\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use SugarCraft\Log\Level;
use SugarCraft\Log\Logger;
use SugarCraft\Log\PsrBridge;
use SugarCraft\Log\Hook\HookRegistry;

final class PsrBridgeTest extends TestCase
{
    private string $tempPath;
    private $tempFile;

    protected function setUp(): void
    {
        $this->tempPath = \sys_get_temp_dir() . '/candy-log-psr-test-' . \uniqid() . '.log';
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

    public function testInfoLogsToWrappedLogger(): void
    {
        $logger = Logger::new(stream: $this->tempFile, level: Level::Debug, reportTimestamp: false);
        $bridge = new PsrBridge($logger);

        $bridge->info('hello psr');

        \fclose($this->tempFile);
        $content = \file_get_contents($this->tempPath);

        $this->assertStringContainsString('hello psr', $content);
        $this->assertStringContainsString('INF', $content);
    }

    public function testEmergencyMapsToFatal(): void
    {
        $logger = Logger::new(stream: $this->tempFile, level: Level::Debug, reportTimestamp: false);
        $bridge = new PsrBridge($logger);

        $this->expectException(\RuntimeException::class);
        try {
            $bridge->emergency('panic');
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('exit(1)');
        }
    }

    public function testDebugMapsToDebugLevel(): void
    {
        $logger = Logger::new(stream: $this->tempFile, level: Level::Debug, reportTimestamp: false);
        $bridge = new PsrBridge($logger);

        $bridge->debug('debug message');

        \fclose($this->tempFile);
        $content = \file_get_contents($this->tempPath);

        $this->assertStringContainsString('debug message', $content);
        $this->assertStringContainsString('DBG', $content);
    }

    public function testWarningMapsToWarnLevel(): void
    {
        $logger = Logger::new(stream: $this->tempFile, level: Level::Debug, reportTimestamp: false);
        $bridge = new PsrBridge($logger);

        $bridge->warning('careful');

        \fclose($this->tempFile);
        $content = \file_get_contents($this->tempPath);

        $this->assertStringContainsString('careful', $content);
        $this->assertStringContainsString('WRN', $content);
    }

    public function testErrorMapsToErrorLevel(): void
    {
        $logger = Logger::new(stream: $this->tempFile, level: Level::Debug, reportTimestamp: false);
        $bridge = new PsrBridge($logger);

        $bridge->error('oops');

        \fclose($this->tempFile);
        $content = \file_get_contents($this->tempPath);

        $this->assertStringContainsString('oops', $content);
        $this->assertStringContainsString('ERR', $content);
    }

    public function testPsrLevelStringAcceptedByLogMethod(): void
    {
        $logger = Logger::new(stream: $this->tempFile, level: Level::Debug, reportTimestamp: false);
        $bridge = new PsrBridge($logger);

        $bridge->log(LogLevel::INFO, 'via log method');

        \fclose($this->tempFile);
        $content = \file_get_contents($this->tempPath);

        $this->assertStringContainsString('via log method', $content);
    }
}
