<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Plugins\PluginContext;

final class PluginContextTest extends TestCase
{
    public function testForCarriesEveryIdentityAndSettingField(): void
    {
        $loop = Loop::get();
        $lines = [];
        $log = static function (string $line) use (&$lines): void {
            $lines[] = $line;
        };

        $context = PluginContext::for(
            'channels.example',
            'example',
            '/srv/project',
            $loop,
            $log,
            ['token_env' => 'EXAMPLE_TOKEN'],
            '/home/me/.local/state/example',
        );

        $this->assertSame('channels.example', $context->plugin);
        $this->assertSame('example', $context->name);
        $this->assertSame('/srv/project', $context->root);
        $this->assertSame($loop, $context->loop);
        $this->assertSame(['token_env' => 'EXAMPLE_TOKEN'], $context->config);
        $this->assertSame('/home/me/.local/state/example', $context->stateDir);

        ($context->log)('started');
        $this->assertSame(['started'], $lines);
    }

    public function testForDefaultsToNoRootNoStateDirNoConfigAndASilentLog(): void
    {
        $context = PluginContext::for('p', 'p', null, Loop::get());

        $this->assertNull($context->root);
        $this->assertNull($context->stateDir);
        $this->assertSame([], $context->config);
        $this->assertNull(($context->log)('discarded'));
    }

    public function testTheContextIsReadonly(): void
    {
        $context = PluginContext::for('p', 'p', null, Loop::get());

        $this->expectException(\Error::class);
        $context->name = 'renamed';
    }
}
