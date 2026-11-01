<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Media;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Media\Capability\EndpointFamily;
use SugarCraft\Code\Media\Capability\MediaAffordance;
use SugarCraft\Code\Media\Sd\Client;
use SugarCraft\Code\Media\Video\JobClient;
use SugarCraft\Code\Media\Voice\VoiceConfig;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * Plan W6.7 gates: the serve-mode media flags are a PURE derivation from
 * config ∩ static capability doors — no sockets, no dials (the boot-time
 * law, plan :844). The table below pins every door the advertisement walks,
 * including the two the shipped tools carry verbatim (the C-1 image door,
 * the W5.3 video-contract door) and the fail-open roster.
 *
 * The never-both structural leg was the loud tripwire for the W7 sglang
 * adapter: it fired the day sglang-diffusion became BOTH implemented AND
 * video-contracted, and this file was re-read on purpose — the tables below
 * now pin each family's exact offer set instead of forbidding the pair.
 */
final class MediaAffordanceTest extends TestCase
{
    use HomeSandboxTrait;

    private string $home = '';

    private const BASE = 'http://gpu.example:7860';

    protected function setUp(): void
    {
        $this->home = \sys_get_temp_dir() . '/crush-affordance-' . \bin2hex(\random_bytes(6));
        \mkdir($this->home . '/.candy-code', 0o700, true);
        $this->useHomeSandbox($this->home);
        MediaAffordance::resetForTesting();
        \putenv(Client::BASE_URL_ENV);
    }

    protected function tearDown(): void
    {
        MediaAffordance::resetForTesting();
        \putenv(Client::BASE_URL_ENV);
        $this->restoreHomeSandbox();
        self::purge($this->home);
    }

    private static function purge(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        foreach ((array) \scandir($dir) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $path = $dir . '/' . $entry;
                \is_dir($path) ? self::purge($path) : \unlink($path);
            }
        }
        \rmdir($dir);
    }

    private static function hash(string $host): string
    {
        return \substr(\hash('sha256', $host), 0, MediaAffordance::HOST_HASH_CHARS);
    }

    /**
     * @return array<string, array{array<array-key, mixed>, string|null, array{image: bool, video: bool, transcribe: bool, baseUrlHostOnly: string|null}, string}>
     */
    public static function flagTables(): array
    {
        $off = ['image' => false, 'video' => false, 'transcribe' => false, 'baseUrlHostOnly' => null];
        $imageOnly = ['image' => true, 'video' => false, 'transcribe' => false, 'baseUrlHostOnly' => self::hash('gpu.example')];
        $bothOffers = ['image' => true, 'video' => true, 'transcribe' => false, 'baseUrlHostOnly' => self::hash('gpu.example')];

        return [
            'no server configured' => [[], null, $off, 'nothing is offered over no base URL'],
            'sdapi default' => [[], self::BASE, $imageOnly, 'sdapi is the default image door and carries no video contract'],
            'sglang declared' => [
                ['mediaFamily' => 'sglang-diffusion'],
                self::BASE,
                $bothOffers,
                'the W7 adapter made sglang the first family offering both: image via /v1/images, video via the job contract',
            ],
            'roster video-only under sdapi' => [
                ['mediaKinds' => ['video']],
                self::BASE,
                ['image' => false, 'video' => false, 'transcribe' => false, 'baseUrlHostOnly' => self::hash('gpu.example')],
                'roster shuts image; sdapi carries no video contract so video stays false',
            ],
            'empty roster fails open' => [['mediaKinds' => []], self::BASE, $imageOnly, 'an empty mediaKinds roster means everything the host can do (W1.8)'],
            'string roster' => [['mediaKinds' => 'image'], self::BASE, $imageOnly, 'a bare string is one kind, tolerant like configuredMediaKinds'],
            'unparseable family falls through' => [['mediaFamily' => 'not-a-family'], self::BASE, $imageOnly, 'a family that fails to parse is not this door\'s business (mirrors both tools)'],
            'recognised unimplemented family' => [
                ['mediaFamily' => 'comfyui'],
                self::BASE,
                ['image' => false, 'video' => false, 'transcribe' => false, 'baseUrlHostOnly' => self::hash('gpu.example')],
                'comfyui parses, is not implemented, and carries no contract — both doors shut by name',
            ],
            'schemeless host:port' => [[], 'gpu.example:7860', $imageOnly, 'a bare host:port base still resolves a host'],
            'empty host' => [
                [],
                'http://',
                $off,
                'a base with no host component resolves nothing — flags stay off, hash stays null',
            ],
            'voice endpoint without an sd server' => [
                ['voice.baseUrl' => 'https://voice.example/v1'],
                null,
                ['image' => false, 'video' => false, 'transcribe' => true, 'baseUrlHostOnly' => null],
                'the voice door is independent of the SD base — naming an endpoint is the whole predicate, and no key is needed to advertise it (C-TUI-6.n)',
            ],
        ];
    }

    #[DataProvider('flagTables')]
    public function testThePureDerivationTable(array $settings, ?string $base, array $expected, string $why): void
    {
        self::assertSame($expected, MediaAffordance::flags($settings, $base), $why);
    }

    public function testUppercaseHostHashesLowercased(): void
    {
        $flags = MediaAffordance::flags([], 'http://GPU.Example:7860/sdapi/v1');
        self::assertSame(self::hash('gpu.example'), $flags['baseUrlHostOnly'], 'the same server must hash the same however it is spelled');
    }

    public function testTheHostNeverRidesTheFlagsInClear(): void
    {
        $wire = (string) \json_encode(MediaAffordance::flags([], self::BASE));
        self::assertStringNotContainsString('gpu.example', $wire);
        self::assertStringNotContainsString('7860', $wire);
        self::assertStringNotContainsString('http', $wire, 'plan :839 — hash or flag only, never the URL');
    }

    public function testSglangIsTheOnlyFamilyBothOfferingImagesAndVideo(): void
    {
        // The W7 pre-adapter tripwire demanded this exact table be re-read
        // when a both-offers family appeared; it is that re-read, landed.
        foreach (EndpointFamily::cases() as $family) {
            $flags = MediaAffordance::flags(['mediaFamily' => $family->value], self::BASE);
            self::assertSame(
                $family === EndpointFamily::SglangDiffusion,
                $flags['image'] && $flags['video'],
                'family ' . $family->value . ' changed its both-offers status — sglang is the single '
                . 'image+video family by design, so any shift here is a deliberate door edit',
            );
        }
    }

    public function testTheDoorsAreReadFromTheirSingleAuthorities(): void
    {
        // The table above would stay green under a re-spelled door; these
        // assert the flags consult the SAME authorities the tools do.
        $sdapi = MediaAffordance::flags([], self::BASE);
        self::assertSame(EndpointFamily::SdApi->isImplemented(), $sdapi['image']);

        $sglang = MediaAffordance::flags(['mediaFamily' => 'sglang-diffusion'], self::BASE);
        self::assertNotNull(JobClient::videoContract(EndpointFamily::SglangDiffusion), 'the contract exists…');
        self::assertTrue($sglang['video'], '…and since W7 isImplemented gates it open too — the flag now matches the tool door');
    }

    public function testCurrentReadsTheEnvironmentBaseOnceAndHoldsIt(): void
    {
        \putenv(Client::BASE_URL_ENV . '=http://first.example:7860');
        self::assertSame(self::hash('first.example'), MediaAffordance::current()['baseUrlHostOnly']);
        self::assertTrue(MediaAffordance::current()['image']);

        \putenv(Client::BASE_URL_ENV . '=http://second.example:7860');
        self::assertSame(
            self::hash('first.example'),
            MediaAffordance::current()['baseUrlHostOnly'],
            'a mid-process edit must not flip flags before the next start (boot-time law, plan :844)',
        );

        MediaAffordance::resetForTesting();
        self::assertSame(
            self::hash('second.example'),
            MediaAffordance::current()['baseUrlHostOnly'],
            'the next serve start re-reads',
        );
    }

    public function testCurrentAnswersOffWhenNothingIsConfigured(): void
    {
        self::assertSame(
            ['image' => false, 'video' => false, 'transcribe' => false, 'baseUrlHostOnly' => null],
            MediaAffordance::current(),
            'an empty sandbox home is an unconfigured server',
        );
    }

    public function testTheTranscribeFlagIsTheVoiceConfigEnabledDoorVerbatim(): void
    {
        // The flag must answer from the SAME predicate the transport itself
        // refuses on (VoiceConfig::enabled() — voice.baseUrl set), so the
        // advertisement and the call cannot drift: a named endpoint opens
        // the door with or without a key, whitespace is no endpoint, and a
        // lone key is silence.
        foreach ([
            [[], false],
            [['voice.apiKey' => 'sk-not-a-door'], false],
            [['voice.baseUrl' => '   '], false],
            [['voice.baseUrl' => 'https://voice.example/v1'], true],
            [['voice.baseUrl' => 'https://voice.example/v1', 'voice.apiKey' => 'sk-whatever'], true],
        ] as [$settings, $expected]) {
            $flags = MediaAffordance::flags((array) $settings, self::BASE);
            self::assertSame(
                $expected,
                $flags['transcribe'],
                'voice door drifted from VoiceConfig::enabled() over ' . \json_encode($settings),
            );
            self::assertSame(
                VoiceConfig::fromSettings((array) $settings)->enabled(),
                $flags['transcribe'],
                'the flag and the transport authority must answer the same',
            );
        }
    }

    public function testTheVoiceEndpointNeverRidesTheFlagsInClear(): void
    {
        // plan :839's privacy law extends to the new door: the voice URL is
        // an endpoint like any other and never appears in the advertisement,
        // only the SD host's hash does (and only when an SD base exists).
        $token = 'vhost-' . \bin2hex(\random_bytes(6));
        $wire = (string) \json_encode(MediaAffordance::flags(
            ['voice.baseUrl' => 'http://' . $token . ':7861/v1'],
            self::BASE,
        ));
        self::assertStringNotContainsString($token, $wire, 'a configured voice endpoint is advertised as a flag, never as a host');
        self::assertStringNotContainsString('http', $wire);
    }
}
