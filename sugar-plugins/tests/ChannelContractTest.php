<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SugarCraft\Plugins\Channel\AcceptsHttpRequests;
use SugarCraft\Plugins\Channel\ChannelInterface;
use SugarCraft\Plugins\Channel\DispatchesInboundEvents;
use SugarCraft\Plugins\Channel\CredentialWriter;
use SugarCraft\Plugins\Channel\SubscribesToSessionEvents;
use SugarCraft\Plugins\Channel\WritesCredentials;
use SugarCraft\Plugins\Plugin;
use SugarCraft\Plugins\PluginContext;

final class ChannelContractTest extends TestCase
{
    public function testChannelInterfaceExtendsPluginWithNoMethodOfItsOwn(): void
    {
        $this->assertTrue(is_a(ChannelInterface::class, Plugin::class, true));
        $own = array_filter(
            (new \ReflectionClass(ChannelInterface::class))->getMethods(),
            static fn(\ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === ChannelInterface::class,
        );
        $this->assertSame([], $own);
    }

    public function testEveryCapabilityInterfaceExtendsChannelInterface(): void
    {
        foreach ([AcceptsHttpRequests::class, SubscribesToSessionEvents::class, DispatchesInboundEvents::class] as $capability) {
            $this->assertTrue(is_a($capability, ChannelInterface::class, true), $capability . ' must extend ChannelInterface');
        }
    }

    public function testThePluginLifecycleSignaturesAreUnchanged(): void
    {
        $plugin = new \ReflectionClass(Plugin::class);

        $name = $plugin->getMethod('name');
        $this->assertSame('string', (string) $name->getReturnType());
        $this->assertCount(0, $name->getParameters());

        $start = $plugin->getMethod('start');
        $this->assertSame('void', (string) $start->getReturnType());
        $this->assertSame(PluginContext::class, (string) $start->getParameters()[0]->getType());

        $dispose = $plugin->getMethod('dispose');
        $this->assertSame('void', (string) $dispose->getReturnType());
        $this->assertCount(0, $dispose->getParameters());
    }

    public function testTheWebhookSignatureTakesPsr7AndReturnsPsr7(): void
    {
        $method = new \ReflectionMethod(AcceptsHttpRequests::class, 'handleRequest');

        $this->assertSame(ServerRequestInterface::class, (string) $method->getParameters()[0]->getType());
        $this->assertSame(ResponseInterface::class, (string) $method->getReturnType());
    }

    public function testTheSessionEventSignatureKeepsItsArrayShape(): void
    {
        $method = new \ReflectionMethod(SubscribesToSessionEvents::class, 'onEvent');

        $this->assertSame('array', (string) $method->getParameters()[0]->getType());
        $this->assertSame('void', (string) $method->getReturnType());
    }

    public function testTheInboundDispatchSignatureKeepsItsArrayShape(): void
    {
        $method = new \ReflectionMethod(DispatchesInboundEvents::class, 'dispatchInboundEvent');

        $this->assertSame('array', (string) $method->getParameters()[0]->getType());
        $this->assertSame('bool', (string) $method->getReturnType());
    }

    public function testWritesCredentialsExtendsPluginAndTakesAnOptionalWriter(): void
    {
        $this->assertTrue(is_a(WritesCredentials::class, Plugin::class, true));
        $method = new \ReflectionMethod(WritesCredentials::class, 'start');
        $this->assertSame(['context', 'writer'], array_map(static fn(\ReflectionParameter $p): string => $p->getName(), $method->getParameters()));
        $this->assertTrue($method->getParameters()[1]->allowsNull());
    }

    public function testCredentialWriterHasOneMutateMethod(): void
    {
        $methods = array_map(static fn(\ReflectionMethod $m): string => $m->getName(), (new \ReflectionClass(CredentialWriter::class))->getMethods());
        $this->assertSame(['mutate'], $methods);
    }
}
