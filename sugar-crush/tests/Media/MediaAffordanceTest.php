<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Media;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Media\Capability\EndpointFamily;
use SugarCraft\Crush\Media\Capability\MediaAffordance;
use SugarCraft\Crush\Media\Sd\Client;
use SugarCraft\Crush\Media\Video\JobClient;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

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
        \mkdir($this->home . '/.sugar-crush', 0o700, true);
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
     * @return array<string, array{array<array-key, mixed>, string|null, array{image: bool, video: bool, baseUrlHostOnly: string|null}, string}>
     */
    public static function flagTables(): array
    {
        $off = ['image' => false, 'video' => false, 'baseUrlHostOnly' => null];
        $imageOnly = ['image' => true, 'video' => false, 'baseUrlHostOnly' => self::hash('gpu.example')];
        $bothOffers = ['image' => true, 'video' => true, 'baseUrlHostOnly' => self::hash('gpu.example')];

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
                ['image' => false, 'video' => false, 'baseUrlHostOnly' => self::hash('gpu.example')],
                'roster shuts image; sdapi carries no video contract so video stays false',
            ],
            'empty roster fails open' => [['mediaKinds' => []], self::BASE, $imageOnly, 'an empty mediaKinds roster means everything the host can do (W1.8)'],
            'string roster' => [['mediaKinds' => 'image'], self::BASE, $imageOnly, 'a bare string is one kind, tolerant like configuredMediaKinds'],
            'unparseable family falls through' => [['mediaFamily' => 'not-a-family'], self::BASE, $imageOnly, 'a family that fails to parse is not this door\'s business (mirrors both tools)'],
            'recognised unimplemented family' => [
                ['mediaFamily' => 'comfyui'],
                self::BASE,
                ['image' => false, 'video' => false, 'baseUrlHostOnly' => self::hash('gpu.example')],
                'comfyui parses, is not implemented, and carries no contract — both doors shut by name',
            ],
            'schemeless host:port' => [[], 'gpu.example:7860', $imageOnly, 'a bare host:port base still resolves a host'],
            'empty host' => [
                [],
                'http://',
                $off,
                'a base with no host component resolves nothing — flags stay off, hash stays null',
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
            ['image' => false, 'video' => false, 'baseUrlHostOnly' => null],
            MediaAffordance::current(),
            'an empty sandbox home is an unconfigured server',
        );
    }
}
