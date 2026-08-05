<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentPoolConfig;
use SugarCraft\Crush\Agents\ExecutorType;

/**
 * Tests for AgentPoolConfig - worker pool configuration.
 */
final class AgentPoolConfigTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Default values
    // -------------------------------------------------------------------------

    public function testDefaultMaxConcurrent(): void
    {
        $config = new AgentPoolConfig();
        $this->assertSame(5, $config->maxConcurrent);
    }

    public function testDefaultTimeoutSeconds(): void
    {
        $config = new AgentPoolConfig();
        $this->assertSame(300, $config->defaultTimeoutSeconds);
    }

    public function testDefaultMaxRetries(): void
    {
        $config = new AgentPoolConfig();
        $this->assertSame(2, $config->maxRetries);
    }

    public function testDefaultStopOnFirstFailure(): void
    {
        $config = new AgentPoolConfig();
        $this->assertFalse($config->stopOnFirstFailure);
    }

    public function testDefaultExecutorType(): void
    {
        $config = new AgentPoolConfig();
        $this->assertSame(ExecutorType::Process, $config->executorType);
    }

    // -------------------------------------------------------------------------
    // Custom values via constructor
    // -------------------------------------------------------------------------

    public function testCustomValues(): void
    {
        $config = new AgentPoolConfig(
            maxConcurrent: 10,
            defaultTimeoutSeconds: 600,
            maxRetries: 3,
            stopOnFirstFailure: true,
            executorType: ExecutorType::Async,
        );

        $this->assertSame(10, $config->maxConcurrent);
        $this->assertSame(600, $config->defaultTimeoutSeconds);
        $this->assertSame(3, $config->maxRetries);
        $this->assertTrue($config->stopOnFirstFailure);
        $this->assertSame(ExecutorType::Async, $config->executorType);
    }

    // -------------------------------------------------------------------------
    // withMaxConcurrent()
    // -------------------------------------------------------------------------

    public function testWithMaxConcurrent(): void
    {
        $original = new AgentPoolConfig(maxConcurrent: 5);
        $modified = $original->withMaxConcurrent(10);

        $this->assertSame(5, $original->maxConcurrent);
        $this->assertSame(10, $modified->maxConcurrent);
    }

    public function testWithMaxConcurrentPreservesOtherFields(): void
    {
        $original = new AgentPoolConfig(
            maxConcurrent: 5,
            defaultTimeoutSeconds: 600,
            maxRetries: 3,
            stopOnFirstFailure: true,
            executorType: ExecutorType::Hybrid,
        );
        $modified = $original->withMaxConcurrent(20);

        $this->assertSame(5, $original->maxConcurrent);
        $this->assertSame(20, $modified->maxConcurrent);
        $this->assertSame(600, $modified->defaultTimeoutSeconds);
        $this->assertSame(3, $modified->maxRetries);
        $this->assertTrue($modified->stopOnFirstFailure);
        $this->assertSame(ExecutorType::Hybrid, $modified->executorType);
    }

    // -------------------------------------------------------------------------
    // withDefaultTimeoutSeconds()
    // -------------------------------------------------------------------------

    public function testWithDefaultTimeoutSeconds(): void
    {
        $original = new AgentPoolConfig(defaultTimeoutSeconds: 300);
        $modified = $original->withDefaultTimeoutSeconds(7200);

        $this->assertSame(300, $original->defaultTimeoutSeconds);
        $this->assertSame(7200, $modified->defaultTimeoutSeconds);
    }

    // -------------------------------------------------------------------------
    // withMaxRetries()
    // -------------------------------------------------------------------------

    public function testWithMaxRetries(): void
    {
        $original = new AgentPoolConfig(maxRetries: 2);
        $modified = $original->withMaxRetries(5);

        $this->assertSame(2, $original->maxRetries);
        $this->assertSame(5, $modified->maxRetries);
    }

    // -------------------------------------------------------------------------
    // withStopOnFirstFailure()
    // -------------------------------------------------------------------------

    public function testWithStopOnFirstFailureTrue(): void
    {
        $original = new AgentPoolConfig(stopOnFirstFailure: false);
        $modified = $original->withStopOnFirstFailure(true);

        $this->assertFalse($original->stopOnFirstFailure);
        $this->assertTrue($modified->stopOnFirstFailure);
    }

    public function testWithStopOnFirstFailureFalse(): void
    {
        $original = new AgentPoolConfig(stopOnFirstFailure: true);
        $modified = $original->withStopOnFirstFailure(false);

        $this->assertTrue($original->stopOnFirstFailure);
        $this->assertFalse($modified->stopOnFirstFailure);
    }

    // -------------------------------------------------------------------------
    // withExecutorType()
    // -------------------------------------------------------------------------

    public function testWithExecutorTypeProcess(): void
    {
        $original = new AgentPoolConfig(executorType: ExecutorType::Async);
        $modified = $original->withExecutorType(ExecutorType::Process);

        $this->assertSame(ExecutorType::Async, $original->executorType);
        $this->assertSame(ExecutorType::Process, $modified->executorType);
    }

    public function testWithExecutorTypeHybrid(): void
    {
        $original = new AgentPoolConfig(executorType: ExecutorType::Process);
        $modified = $original->withExecutorType(ExecutorType::Hybrid);

        $this->assertSame(ExecutorType::Process, $original->executorType);
        $this->assertSame(ExecutorType::Hybrid, $modified->executorType);
    }

    // -------------------------------------------------------------------------
    // Immutability - with*() returns new instance
    // -------------------------------------------------------------------------

    public function testWithMethodsReturnNewInstance(): void
    {
        $original = new AgentPoolConfig();

        $this->assertNotSame($original, $original->withMaxConcurrent(10));
        $this->assertNotSame($original, $original->withDefaultTimeoutSeconds(600));
        $this->assertNotSame($original, $original->withMaxRetries(5));
        $this->assertNotSame($original, $original->withStopOnFirstFailure(true));
        $this->assertNotSame($original, $original->withExecutorType(ExecutorType::Async));
    }

    // -------------------------------------------------------------------------
    // Zero values are valid
    // -------------------------------------------------------------------------

    public function testZeroMaxConcurrent(): void
    {
        $config = new AgentPoolConfig(maxConcurrent: 0);
        $this->assertSame(0, $config->maxConcurrent);
    }

    public function testZeroTimeoutSeconds(): void
    {
        $config = new AgentPoolConfig(defaultTimeoutSeconds: 0);
        $this->assertSame(0, $config->defaultTimeoutSeconds);
    }

    public function testZeroMaxRetries(): void
    {
        $config = new AgentPoolConfig(maxRetries: 0);
        $this->assertSame(0, $config->maxRetries);
    }

    // -------------------------------------------------------------------------
    // Boundary values
    // -------------------------------------------------------------------------

    public function testLargeMaxConcurrent(): void
    {
        $config = new AgentPoolConfig(maxConcurrent: 100);
        $this->assertSame(100, $config->maxConcurrent);
    }

    public function testLargeTimeoutSeconds(): void
    {
        $config = new AgentPoolConfig(defaultTimeoutSeconds: 86400);
        $this->assertSame(86400, $config->defaultTimeoutSeconds);
    }
}
