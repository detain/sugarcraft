<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\SelfUpdate;
use SugarCraft\Code\Cli\UpdateCheck;
use SugarCraft\Code\Cli\WindowsSwap;
use SugarCraft\Releases\WindowsSwap as LibWindowsSwap;

/**
 * S-L01: SelfUpdate / UpdateCheck / WindowsSwap are adapters over sugar-releases
 * (whose suite owns the engine pins). These pin what is candy-code's: the
 * release coordinates, the pinned key, the opt-out env, and that the app's
 * published constants cannot drift from the engine's source.
 */
final class ReleasesAdapterTest extends TestCase
{
    public function testSourceCarriesCandyCodesCoordinatesAndPinnedKey(): void
    {
        $source = SelfUpdate::source();

        self::assertSame('detain/sugarcraft', $source->repo);
        self::assertSame('candy-code/', $source->tagPrefix);
        self::assertSame(SelfUpdate::PHAR_ASSET, $source->pharAsset);
        self::assertSame(SelfUpdate::SIDECAR_ASSET, $source->sidecarAsset());
        self::assertSame(SelfUpdate::SIGNATURE_ASSET, $source->signatureAsset());
        self::assertSame(SelfUpdate::SIDECAR_SIGNATURE_ASSET, $source->sidecarSignatureAsset());
        self::assertSame(SelfUpdate::LATEST_TAG, $source->latestTag());
        self::assertSame(SelfUpdate::RELEASES_URL, $source->releasesUrl());
        self::assertSame(SelfUpdate::COMPOSER_COMMAND, $source->composerCommand());
        self::assertSame(SelfUpdate::PUBLIC_KEY_BASE64, $source->publicKey);
        self::assertSame('', SelfUpdate::source('')->publicKey, 'a test-supplied keyless state reaches the engine');
    }

    public function testComposerRefusalSpeaksThroughTheAppCatalogue(): void
    {
        $text = SelfUpdate::composerRefusal('some/other-root');

        self::assertStringContainsString('candy-code: self-update replaces a phar install', $text);
        self::assertStringContainsString(SelfUpdate::COMPOSER_COMMAND, $text);
        self::assertStringContainsString(SelfUpdate::PULL_AND_INSTALL_COMMAND, SelfUpdate::composerRefusal('sugarcraft/candy-code'));
    }

    public function testWindowsSwapAdapterSharesTheLibsHelperAndArgv(): void
    {
        self::assertSame(LibWindowsSwap::HELPER, WindowsSwap::HELPER);
        self::assertSame(
            LibWindowsSwap::launchCommand(42, '/p/x.new', '/p/x'),
            WindowsSwap::launchCommand(42, '/p/x.new', '/p/x'),
        );
    }

    public function testUpdateCheckKeepsTheAppsOptOutEnvAndCacheFile(): void
    {
        self::assertSame('CANDY_CODE_NO_UPDATE_CHECK', UpdateCheck::UPDATE_CHECK_OPT_OUT_ENV);
        self::assertSame('update-check.json', UpdateCheck::CACHE_FILE);
        self::assertSame(86_400, UpdateCheck::CHECK_INTERVAL_SECONDS);
    }
}
