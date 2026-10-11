<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Server;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Media\Capability\MediaAffordance;
use SugarCraft\Crush\Media\Sd\Client;
use SugarCraft\Crush\Protocol\Methods\ServerMethods;
use SugarCraft\Crush\Protocol\Schema\MethodSchemas;
use SugarCraft\Crush\Protocol\Schema\ProtocolSchema;
use SugarCraft\Crush\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Crush\Tests\Server\Support\WireClient;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * `server.hello`'s `features.media` (plan_crush_media W6.7): the web client
 * must KNOW whether to offer generation without dialing the SD server — so
 * the handshake carries the boot-time flags, derived here over the REAL
 * dispatcher against the REAL result schema.
 *
 * Every test resets the boot memo: the flags resolve once per process by
 * design (the no-refresh law, plan :844), and the sandbox home is what the
 * first read sees.
 */
final class HelloMediaFlagsTest extends TestCase
{
    use HomeSandboxTrait;

    private string $home = '';

    protected function setUp(): void
    {
        $this->home = \sys_get_temp_dir() . '/crush-hello-media-' . \bin2hex(\random_bytes(6));
        \mkdir($this->home . '/.sugar-crush', 0o700, true);
        $this->useHomeSandbox($this->home);
        MediaAffordance::resetForTesting();
        \putenv(Client::BASE_URL_ENV);
    }

    protected function tearDown(): void
    {
        MediaAffordance::resetForTesting();
        \putenv(Client::BASE_URL_ENV);
        Bootstrap::useConfigPath(null);
        $this->restoreHomeSandbox();
        ProtocolFixture::removeTree($this->home);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function hello(): array
    {
        $fixture = ProtocolFixture::new();
        try {
            return WireClient::open($fixture->dispatcher, 'c-media')->hello();
        } finally {
            $fixture->tearDown();
        }
    }

    public function testAnUnconfiguredServerOffersNoMedia(): void
    {
        $hello = $this->hello();

        self::assertSame(
            ['image' => false, 'video' => false, 'baseUrlHostOnly' => null],
            $hello['features']['media'] ?? null,
            'an empty config is silence about media, never an assumption of it',
        );
        self::assertSame([], ProtocolSchema::errors($hello, MethodSchemas::result(ServerMethods::HELLO), ServerMethods::HELLO));
    }

    public function testAConfiguredSdServerOffersImagesNotVideoOverAHashedHost(): void
    {
        \file_put_contents(
            $this->home . '/.sugar-crush/config.json',
            (string) \json_encode(['sd.baseUrl' => 'http://GPU-HOST-EXAMPLE:7860']),
        );
        MediaAffordance::resetForTesting();

        $hello = $this->hello();
        $media = $hello['features']['media'] ?? null;

        self::assertIsArray($media);
        self::assertTrue($media['image'], 'sdapi over a configured base is the shipped image door');
        self::assertFalse($media['video'], 'the default sdapi family carries no video job contract — the flag says so');
        self::assertSame(\substr(\hash('sha256', 'gpu-host-example'), 0, MediaAffordance::HOST_HASH_CHARS), $media['baseUrlHostOnly']);
        self::assertStringNotContainsString('gpu-host-example', (string) \json_encode($hello), 'plan :839 — the handshake never names the host');
        self::assertSame([], ProtocolSchema::errors($hello, MethodSchemas::result(ServerMethods::HELLO), ServerMethods::HELLO));
    }

    public function testASglangFamilyBaseOffersImagesAndVideo(): void
    {
        \file_put_contents(
            $this->home . '/.sugar-crush/config.json',
            (string) \json_encode(['sd.baseUrl' => 'http://GPU-HOST-EXAMPLE:7860', 'mediaFamily' => 'sglang-diffusion']),
        );
        MediaAffordance::resetForTesting();

        $media = $this->hello()['features']['media'] ?? null;

        self::assertIsArray($media);
        self::assertTrue($media['image'], 'the W7 adapter opened the sglang image door');
        self::assertTrue($media['video'], 'sglang is the first both-offers family — /v1/videos rides the job contract');
        self::assertSame(\substr(\hash('sha256', 'gpu-host-example'), 0, MediaAffordance::HOST_HASH_CHARS), $media['baseUrlHostOnly']);
    }

    public function testTheEnvironmentBaseCountsAsConfigured(): void
    {
        \putenv(Client::BASE_URL_ENV . '=http://env-side.example:7860');
        MediaAffordance::resetForTesting();

        $media = $this->hello()['features']['media'] ?? null;

        self::assertIsArray($media);
        self::assertTrue($media['image'], 'the env base is the same single authority Client::resolveBaseUrl() answers from');
        self::assertSame(\substr(\hash('sha256', 'env-side.example'), 0, MediaAffordance::HOST_HASH_CHARS), $media['baseUrlHostOnly']);
    }
}
