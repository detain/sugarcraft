<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;

final class BootstrapTest extends TestCase
{
    public function testAvailableProvidersIncludesEveryBuiltInType(): void
    {
        $providers = Bootstrap::availableProviders();

        foreach (['openai', 'anthropic', 'claude-code', 'sglang', 'bedrock', 'vertex', 'custom'] as $type) {
            $this->assertArrayHasKey($type, $providers);
        }
    }

    public function testAvailableProvidersIncludesProjectConfigProviders(): void
    {
        // .sugar-crush/config.dev.json (checked in) declares 'dev-sglang' as
        // its defaultProvider - the palette's Switch Model action needs it
        // listed here, not only the built-in generic 'sglang' type.
        $providers = Bootstrap::availableProviders();
        $this->assertArrayHasKey('dev-sglang', $providers);
        $this->assertSame('sglang', $providers['dev-sglang']['type'] ?? null);
    }

    public function testBackendForBuildsARealEngineBackendForAKnownType(): void
    {
        // 'sglang' construction only builds config + an HTTP client object
        // (SglangProvider::openAiCompatible()) - no eager network call, so
        // this is safe to build without a reachable server.
        $backend = Bootstrap::backendFor('sglang');
        $this->assertInstanceOf(\SugarCraft\Crush\Backend\EngineBackend::class, $backend);
    }

    public function testBackendForThrowsForAnUnknownProviderName(): void
    {
        $this->expectException(\Throwable::class);
        Bootstrap::backendFor('this-provider-does-not-exist');
    }
}
