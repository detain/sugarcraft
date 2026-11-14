<?php

declare(strict_types=1);

namespace SugarCraft\CandyImage\Tests\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\CandyImage\Support\Version;

/**
 * Behavioural coverage for the phar version reader — the arms
 * PharBuildScriptTest's source-text greps can never reach (a renamed stamp
 * key keeps those green while breaking the contract, which is exactly how
 * candy-image-002 shipped). The grammar matrix pins normalizeStamp() directly
 * (no archive needed); one round-trip mints real stamped archives in a child
 * `php -d phar.readonly=0` process so the metadata key itself is proven
 * end-to-end.
 */
final class VersionTest extends TestCase
{
    /** Compiled-in fallback every failure arm must fall back to. */
    private const FALLBACK = '0.1.0';

    public function testReleaseStampsNormalizeToBareVersions(): void
    {
        self::assertSame('9.9.9', Version::normalizeStamp('candy-image/v9.9.9'), 'tag prefix and v both strip');
        self::assertSame('7.0.1', Version::normalizeStamp('v7.0.1'), 'a bare v prefix strips');
        self::assertSame('9.9.9', Version::normalizeStamp('9.9.9'), 'a bare release passes through');
        self::assertSame('9.9.9', Version::normalizeStamp('candy-image/9.9.9'), 'prefix alone strips without a v');
    }

    public function testDevelopmentStampsSurviveVerbatim(): void
    {
        self::assertSame('dev-build', Version::normalizeStamp('dev-build'), "git-can't-answer fallback self-reports");
        self::assertSame('dev-1a2b3c4', Version::normalizeStamp('dev-1a2b3c4'), 'the CI rolling-dev shortsha self-reports');
        self::assertSame('dev-' . \str_repeat('a', 40), Version::normalizeStamp('dev-' . \str_repeat('a', 40)), 'a pasted full 40-hex HEAD parses');
        self::assertSame('dev-1a2b3c4', Version::normalizeStamp('candy-image/dev-1a2b3c4'), 'the tag prefix strips off a dev form too');
    }

    /**
     * @return array<array{string}>
     */
    public static function refusingStamps(): array
    {
        return [
            ['banana'],
            ['dev-'],
            ['dev-xy'],          // too short
            ['dev-zzzzzzz'],     // hex-shaped but not hex
            ['dev-12345'],       // five digits, under the 7-hex floor
            ['1.2'],
            ['1.2.3.4'],
            ['9.9.9x'],
            [''],
            ['v'],
            ['candy-image/'],
            ['DEV-build'],
        ];
    }

    #[DataProvider('refusingStamps')]
    public function testEveryOtherStampNormalizesToNull(string $stamped): void
    {
        self::assertNull(Version::normalizeStamp($stamped), "neither a release nor a dev identity: $stamped");
    }

    public function testFromPharMetadataRefusesEveryShapeThatIsNotAStampedArchive(): void
    {
        $plain = \tempnam(\sys_get_temp_dir(), 'ciimg') . '.phar';
        self::assertNotFalse(\file_put_contents($plain, 'not a phar, a plain file wearing its name'));
        $dir = \sys_get_temp_dir() . '/ciimg-dir-' . \bin2hex(\random_bytes(4));
        self::assertTrue(\mkdir($dir, 0o700, true));

        try {
            self::assertNull(Version::fromPharMetadata(''), 'the empty host-archive answer reads as no stamp');
            self::assertNull(Version::fromPharMetadata($dir . '/never-created.phar'), 'a missing path is not a stamp');
            self::assertNull(Version::fromPharMetadata($plain), 'a .phar-named plain file must refuse, not raise');
            self::assertNull(Version::fromPharMetadata($dir), 'a directory is not an archive');
        } finally {
            @\unlink($plain);
            @\rmdir($dir);
        }
    }

    public function testResolveUsesCompiledFallbackWithoutAStamp(): void
    {
        self::assertSame(self::FALLBACK, Version::resolve('', self::FALLBACK), 'outside an archive the compiled number speaks');
        $plain = \tempnam(\sys_get_temp_dir(), 'ciimg');
        try {
            self::assertSame(self::FALLBACK, Version::resolve($plain, self::FALLBACK), 'an unreadable archive must not crash --version');
        } finally {
            @\unlink($plain);
        }
    }

    /**
     * The candy-image-002 contract, end-to-end: a stamp minted the way the
     * release and dev channels mint it reads back through the public entry
     * point — tagged releases decoration-free, dev forms verbatim (they beat
     * the compiled fallback), unparseable stamps fall through to it.
     */
    public function testStampedArchivesRoundTripThroughTheReader(): void
    {
        $paths = [];
        try {
            $release = self::mintStampedPhar('candy-image/v9.9.9');
            $paths[] = $release;
            self::assertSame('9.9.9', Version::resolve($release, self::FALLBACK));

            $dev = self::mintStampedPhar('dev-1a2b3c4');
            $paths[] = $dev;
            self::assertSame('dev-1a2b3c4', Version::resolve($dev, self::FALLBACK), 'the rolling-dev stamp must surface through --version (README promises it)');

            $junk = self::mintStampedPhar('banana');
            $paths[] = $junk;
            self::assertSame(self::FALLBACK, Version::resolve($junk, self::FALLBACK), 'an unparseable stamp falls back, never throws');
        } finally {
            @\array_map('unlink', $paths);
        }
    }

    /**
     * Mint a throwaway archive carrying the given metadata stamp. Builds run
     * in a child `php -d phar.readonly=0` process (the ini is PHP_INI_SYSTEM,
     * not ini_set-able in-process), so this executes — never skips — on every
     * runner, mirroring candy-top's shared child-mint pattern.
     */
    private static function mintStampedPhar(string $stamp): string
    {
        $out = \sys_get_temp_dir() . '/ciimg-mint-' . \bin2hex(\random_bytes(4)) . '.phar';
        $src = \dirname(__DIR__, 2) . '/src';

        $put = [];
        foreach (['CIIMG_PHAR_OUT' => $out, 'CIIMG_PHAR_SRC' => $src, 'CIIMG_PHAR_STAMP' => $stamp] as $k => $v) {
            $put[$k] = $v;
        }

        $code = '$p = new Phar(getenv("CIIMG_PHAR_OUT")); $p->buildFromDirectory(getenv("CIIMG_PHAR_SRC")); $p->setMetadata(["version" => getenv("CIIMG_PHAR_STAMP")]);';
        $cmd = [\PHP_BINARY, '-d', 'phar.readonly=0', '-r', $code];

        $proc = \proc_open($cmd, ['1' => ['pipe', 'w'], '2' => ['pipe', 'w']], $pipes, null, $put + self::childEnv());
        self::assertIsResource($proc);
        $err = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $rc = \proc_close($proc);
        self::assertSame(0, $rc, "mint child failed for stamp '$stamp': $err");
        self::assertFileExists($out);

        return $out;
    }

    /**
     * The child inherits the parent env (PHP_BINARY path lookups), so carry
     * the real environment through rather than replacing it wholesale.
     *
     * @return array<string, string>
     */
    private static function childEnv(): array
    {
        $env = [];
        foreach ($_ENV + $_SERVER as $k => $v) {
            if (\is_string($k) && \is_string($v)) {
                $env[$k] = $v;
            }
        }

        return $env;
    }
}
