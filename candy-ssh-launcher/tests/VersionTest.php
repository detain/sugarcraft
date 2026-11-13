<?php

declare(strict_types=1);

namespace SugarCraft\SshLauncher\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\SshLauncher\Support\Version;

/**
 * Behavioral pins for Support\Version (finding -043) — the class the
 * DOCSYNC era called the 0%-coverage outlier on a must-never-crash
 * `--version` path. Every failure mode must fall back (to CURRENT or to
 * null), never throw; the tag-prefix/`v` strip order is pinned so a flip
 * reddens; the archive-beats-compiled precedence the README documents is
 * measured, not asserted by grep.
 *
 * Real archives are built by a child `php -d phar.readonly=0` process
 * (the runner's own ini is never touched — house skip pattern per the
 * finding: hosts that cannot build phars skip the built-archive legs with
 * a dated reason instead of silently greening them).
 */
final class VersionTest extends TestCase
{
    /** @var list<string> */
    private array $archives = [];

    protected function tearDown(): void
    {
        foreach ($this->archives as $path) {
            @unlink($path);
        }
        $this->archives = [];
    }

    // -- the two pure in-process legs --------------------------------------

    public function testResolveWithoutArchiveIsTheCompiledInCurrent(): void
    {
        self::assertSame(Version::CURRENT, Version::resolve(''));
    }

    public function testResolveOfMissingArchiveFallsBackToCurrentWithoutThrowing(): void
    {
        self::assertSame(Version::CURRENT, Version::resolve('/nonexistent-dir/probe.phar'));
    }

    public function testFromPharMetadataRefusesEmptyAndMissingPaths(): void
    {
        self::assertNull(Version::fromPharMetadata(''));
        self::assertNull(Version::fromPharMetadata('/nonexistent-dir/probe.phar'));
    }

    public function testFromPharMetadataRefusesGarbageBytesAsCorruptArchive(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sshlaunch-version-garbage-') . '.phar';
        file_put_contents($path, 'just bytes, no phar structure whatsoever');
        $this->archives[] = $path;

        self::assertNull(
            Version::fromPharMetadata($path),
            'a corrupt archive must not crash --version; it answers null (resolve() then shows CURRENT)'
        );
        self::assertSame(Version::CURRENT, Version::resolve($path));
    }

    // -- built-archive legs (child-process builder, skip-gated) ------------

    public function testStampedBareVersionIsReadVerbatim(): void
    {
        $archive = $this->buildArchiveWithMetadata(['version' => '9.9.9']);
        self::assertSame('9.9.9', Version::fromPharMetadata($archive));
    }

    public function testArchiveStampBeatsTheCompiledInFallback(): void
    {
        // The DOCSYNC -039 precedence, measured: under an archive the stamp
        // answers, not CURRENT. The stamp value is deliberately unlike any
        // plausible CURRENT so the assertion stays true across releases.
        $archive = $this->buildArchiveWithMetadata(['version' => '9.9.9']);
        self::assertNotSame(Version::CURRENT, '9.9.9', 'stamp/CURRENT must stay distinguishable for this pin to mean anything');
        self::assertSame('9.9.9', Version::resolve($archive));
    }

    public function testTagPrefixAndVStripInOrder(): void
    {
        $archive = $this->buildArchiveWithMetadata(['version' => Version::TAG_PREFIX . 'v9.9.9']);
        // Flipping the strip order (v first, then prefix) leaves 'v9.9.9'
        // after the prefix strip — one v-removal does not run again — so
        // this leg reddens on the inversion.
        self::assertSame('9.9.9', Version::fromPharMetadata($archive));
    }

    public function testBareVStripWithoutPrefix(): void
    {
        $archive = $this->buildArchiveWithMetadata(['version' => 'v9.9.9']);
        self::assertSame('9.9.9', Version::fromPharMetadata($archive));
    }

    public function testLeadingVIsStrippedOnlyOnce(): void
    {
        // 'vv9.9.9' is a garbage stamp; the reader must not loop-strip.
        $archive = $this->buildArchiveWithMetadata(['version' => 'vv9.9.9']);
        self::assertNull(Version::fromPharMetadata($archive));
    }

    public function testNonComparableStampsAreRefused(): void
    {
        foreach (['9.9', '9.9.9.1', 'latest', ''] as $stamped) {
            $archive = $this->buildArchiveWithMetadata(['version' => $stamped]);
            self::assertNull(Version::fromPharMetadata($archive), "stamp {$stamped} is not a comparable X.Y.Z release");
            self::assertSame(Version::CURRENT, Version::resolve($archive), 'unparseable stamp falls back to CURRENT');
        }
    }

    public function testMetadataShapeViolationsRefuseToNull(): void
    {
        $noVersionKey = $this->buildArchiveWithMetadata(['built' => 'yesterday']);
        self::assertNull(Version::fromPharMetadata($noVersionKey));

        $wrongType = $this->buildArchiveWithMetadata(['version' => 22]);
        self::assertNull(Version::fromPharMetadata($wrongType), 'a non-string stamp is not a version');

        $scalarMetadata = $this->buildArchiveWithMetadata('v9.9.9');
        self::assertNull(Version::fromPharMetadata($scalarMetadata), 'non-array metadata answers null, never a type error');
    }

    public function testArchiveWithoutAnyMetadataRefusesToNull(): void
    {
        $archive = $this->buildArchiveWithMetadata(null);
        self::assertNull(Version::fromPharMetadata($archive));
        self::assertSame(Version::CURRENT, Version::resolve($archive));
    }

    /**
     * Build a minimal readable .phar stamped with $metadata via a child
     * PHP process; skip (never silently pass) when the host cannot build
     * archives at all.
     */
    private function buildArchiveWithMetadata(mixed $metadata): string
    {
        $path = sys_get_temp_dir() . '/sshlaunch-version-' . bin2hex(random_bytes(6)) . '.phar';
        $this->archives[] = $path;

        $script = <<<'PHP'
            $p = $argv[1];
            $metaJson = $argv[2] === '__NO_META__' ? null : $argv[2];
            try {
                $phar = new Phar($p);
                if ($metaJson !== null) {
                    $phar->setMetadata(json_decode($metaJson, true, 512, JSON_THROW_ON_ERROR));
                }
                $phar['payload.txt'] = 'probe';
                $phar->setStub("#!/usr/bin/env php<?php __HALT_COMPILER();");
            } catch (Throwable $e) {
                fwrite(STDERR, $e->getMessage());
                exit(1);
            }
            PHP;

        $metaArg = $metadata === null ? '__NO_META__' : json_encode($metadata, JSON_THROW_ON_ERROR);
        $proc = proc_open(
            [PHP_BINARY, '-d', 'phar.readonly=0', '-r', $script, $path, $metaArg],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($proc)) {
            $this->markTestSkipped('2026-11 sweep (sshl-37): php child spawn unavailable on this host — built-archive legs skipped');
        }
        $err = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0 || !is_file($path)) {
            $this->markTestSkipped('2026-11 sweep (sshl-37): this host cannot build phar archives (' . trim($err) . ')');
        }

        return $path;
    }
}
