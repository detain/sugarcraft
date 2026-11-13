<?php

declare(strict_types=1);

namespace SugarCraft\Releases\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Releases\ReleaseSource;

final class ReleaseSourceTest extends TestCase
{
    public function testDefaultsDeriveEveryNameFromTheAppAndRepo(): void
    {
        $s = ReleaseSource::new('acme/tools', 'widget');
        self::assertSame('widget/', $s->tagPrefix);
        self::assertSame('widget.phar', $s->pharAsset);
        self::assertSame('widget.phar.sha256', $s->sidecarAsset());
        self::assertSame('widget.phar.minisig', $s->signatureAsset());
        self::assertSame('widget.phar.sha256.sig', $s->sidecarSignatureAsset());
        self::assertSame('widget/dev', $s->devTag());
        self::assertSame('https://api.github.com/repos/acme/tools/releases?per_page=100', $s->releasesUrl());
        self::assertSame('/repos/acme/tools/releases', $s->releasesPath());
        self::assertSame('', $s->publicKey, 'keyless unless a key is pinned');
        self::assertSame('widget-self-update', $s->userAgent);
    }

    public function testWithersReturnNewInstancesAndLeaveTheOriginalAlone(): void
    {
        $a = ReleaseSource::new('acme/tools', 'widget');
        $b = $a->withTagPrefix('w-')->withPharAsset('w.phar')->withPublicKey('KEY')->withComposerPackage('acme/widget')->withUserAgent('ua');
        self::assertNotSame($a, $b);
        self::assertSame('widget/', $a->tagPrefix);
        self::assertSame('w-dev', $b->devTag());
        self::assertSame('w.phar.sha256', $b->sidecarAsset());
        self::assertSame('KEY', $b->publicKey);
        self::assertSame('composer update acme/widget', $b->composerCommand());
        self::assertSame('ua', $b->userAgent);
    }

    public function testMalformedRepoOrAppIsRefused(): void
    {
        foreach ([['nope', 'a'], ['a/b/c', 'a'], ['a/b', ''], ['a/b', 'x/y'], ['a/b', 'x y']] as [$repo, $app]) {
            try {
                ReleaseSource::new($repo, $app);
                self::fail("accepted $repo / $app");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
