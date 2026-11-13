<?php

/**
 * The stamp contract, from the consumer's side (gifview-05).
 *
 * Support\Version owns one grammar; normalizeStamp() is its single
 * implementation and isStampable() is the door the producer
 * (tools/build-phar.php) validates through, so no artifact can ever carry
 * a stamp this reader would silently drop. fromPharMetadata() then only
 * reads metadata and delegates. The archive arm builds a real (child-made)
 * phar and reads the stamped value back — a discarded Phar::running(false)
 * or a metadata-field rename reddens it.
 */

declare(strict_types=1);

namespace SugarCraft\GifView\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\GifView\Support\Version;

final class VersionTest extends TestCase
{
    /** @var list<string> temp dirs to sweep */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            foreach ((array) glob($dir . '/*') as $file) {
                if (is_string($file)) {
                    @unlink($file);
                }
            }
            @rmdir($dir);
        }
        $this->dirs = [];
    }

    /** @return array<string, array{string, ?string}> */
    public static function stampGrammar(): array
    {
        $seven = 'abcdef1';
        $forty = str_repeat('0a1b2c3d4e', 4);

        return [
            'full release tag'          => ['candy-gifview/v0.2.0', '0.2.0'],
            'v-prefixed bare version'   => ['v0.2.0', '0.2.0'],
            'bare comparable release'   => ['1.2.3', '1.2.3'],
            'dev channel short sha'     => ['dev-' . $seven, 'dev-' . $seven],
            'dev pasted full sha'       => ['dev-' . $forty, 'dev-' . $forty],
            'dev build fallback'        => ['dev-build', 'dev-build'],
            'dev non-hex tail refused'  => ['dev-sho', null],
            'foreign tag refused'       => ['release-0.2', null],
            'empty refused'             => ['', null],
            'bare v strips to nothing'  => ['v', null],
        ];
    }

    #[DataProvider('stampGrammar')]
    public function testNormalizeStampSpeaksTheWholeGrammar(string $stamped, ?string $expected): void
    {
        $this->assertSame($expected, Version::normalizeStamp($stamped), $stamped);
    }

    #[DataProvider('stampGrammar')]
    public function testIsStampableAgreesWithNormalizeStamp(string $stamped, ?string $expected): void
    {
        $this->assertSame($expected !== null, Version::isStampable($stamped), $stamped);
    }

    public function testResolveFallsBackOutsideEveryArchive(): void
    {
        $this->assertSame(Version::CURRENT, Version::resolve(''), 'no archive answers CURRENT');
        $this->assertSame(
            Version::CURRENT,
            Version::resolve(sys_get_temp_dir() . '/gifview-no-such-archive.phar'),
            'a missing/unparseable archive falls back rather than throwing',
        );
    }

    public function testAStampedArchiveReadsBackThroughTheConsumer(): void
    {
        $built = $this->stampArchive('candy-gifview/v9.9.9');
        if ($built === null) {
            $this->markTestSkipped('phar.readonly=0 unavailable even in a child — archive stamp legs cannot run here');
        }

        $this->assertSame('9.9.9', Version::fromPharMetadata($built), 'tag prefix and v must strip on the round trip');
        $this->assertSame('9.9.9', Version::resolve($built), 'resolve() must prefer the archive stamp over CURRENT');
    }

    public function testAnUnstampableArchiveMetadataReadsAsNull(): void
    {
        $built = $this->stampArchive('release-0.2');
        if ($built === null) {
            $this->markTestSkipped('phar.readonly=0 unavailable even in a child — archive stamp legs cannot run here');
        }

        $this->assertNull(
            Version::fromPharMetadata($built),
            'a foreign-grammar stamp parses to null — which is exactly why the producer refuses it at build time',
        );
        $this->assertSame(Version::CURRENT, Version::resolve($built));
    }

    /**
     * Build a throwaway archive whose metadata carries $version via a child
     * (`php -d phar.readonly=0`), or return null when this host refuses.
     */
    private function stampArchive(string $version): ?string
    {
        $dir = sys_get_temp_dir() . '/gifview-versiontest-' . uniqid('', true);
        if (!mkdir($dir, 0o755, true)) {
            self::fail('cannot create the temp archive dir');
        }
        $this->dirs[] = $dir;

        $maker = $dir . '/make.php';
        $target = $dir . '/stamped.phar';
        file_put_contents($maker, implode("\n", [
            '<?php',
            '$phar = new Phar(' . var_export($target, true) . ', 0, \'candy-gifview.phar\');',
            '$phar->startBuffering();',
            '$phar->addFromString(\'x.txt\', \'x\');',
            '$phar->setMetadata([\'version\' => ' . var_export($version, true) . ']);',
            '$phar->setStub("<?php __HALT_COMPILER();");',
            '$phar->stopBuffering();',
            'echo "BUILT\\n";',
        ]));

        $proc = proc_open(
            ['timeout', '30', \PHP_BINARY, '-d', 'phar.readonly=0', $maker],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!\is_resource($proc)) {
            return null;
        }
        // Drain both pipes so the child can never block on a full buffer
        // (and never writes into a closed pipe).
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        if ($code !== 0 || !is_file($target)) {
            // Host cannot write archives: the caller's skip is the disclosure.
            return null;
        }

        return $target;
    }
}
