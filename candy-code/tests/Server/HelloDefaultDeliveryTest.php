<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Config\Settings\SessionSettings;
use SugarCraft\Code\Config\Settings\UiSettings;
use SugarCraft\Code\Protocol\Methods\ServerMethods;
use SugarCraft\Code\Protocol\Schema\MethodSchemas;
use SugarCraft\Code\Protocol\Schema\ProtocolSchema;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * `server.hello`'s `defaults.delivery`: what a client's composer offers first
 * for a prompt sent while a turn runs — the `queueMode` setting the TUI's
 * Enter reads (decision D6, `steer` unless set), in the wire's words
 * (`followup` is the wire's `queue`).
 */
final class HelloDefaultDeliveryTest extends TestCase
{
    use HomeSandboxTrait;

    private string $home = '';

    protected function setUp(): void
    {
        $this->home = \sys_get_temp_dir() . '/crush-hello-delivery-' . \bin2hex(\random_bytes(6));
        \mkdir($this->home . '/.candy-code', 0o700, true);
        $this->useHomeSandbox($this->home);
        SessionSettings::reset();
        UiSettings::forget();
    }

    protected function tearDown(): void
    {
        SessionSettings::reset();
        UiSettings::forget();
        Bootstrap::useConfigPath(null);
        $this->restoreHomeSandbox();
        ProtocolFixture::removeTree($this->home);
    }

    public function testTheDefaultIsSteerAndTheHelloSaysSo(): void
    {
        self::assertSame('steer', ServerMethods::defaultDelivery());

        $fixture = ProtocolFixture::new();
        try {
            $hello = \SugarCraft\Code\Tests\Server\Support\WireClient::open($fixture->dispatcher, 'c-hello')->hello();
            self::assertSame('steer', $hello['defaults']['delivery'] ?? null);
            $schema = MethodSchemas::result(ServerMethods::HELLO);
            self::assertNotNull($schema);
            self::assertSame([], ProtocolSchema::errors($hello, $schema, ServerMethods::HELLO));
        } finally {
            $fixture->tearDown();
        }
    }

    public function testItFollowsTheQueueModeSetting(): void
    {
        foreach (['followup' => 'queue', 'interrupt' => 'interrupt', 'steer' => 'steer'] as $mode => $wire) {
            SessionSettings::apply(['queueMode' => $mode], []);
            UiSettings::forget();
            self::assertSame($wire, ServerMethods::defaultDelivery(), $mode);
        }
    }
}
