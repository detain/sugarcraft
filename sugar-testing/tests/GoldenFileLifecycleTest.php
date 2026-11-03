<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Testing\Snapshot\Assertions;
use SugarCraft\Testing\Snapshot\GoldenFile;
use SugarCraft\Testing\Snapshot\GoldenFileExistsException;

/**
 * Pins the create/update/save split that replaced the old conflation.
 *
 * Before the cure, a first baseline was written with a direct, unlocked,
 * clobbering file_put_contents: an unreadable file read back as "missing"
 * and UPDATE_GOLDENS then overwrote it blind, and two concurrent creates
 * could interleave a half-written baseline. These pins keep each of those
 * doors shut: no-clobber creation enforced by link semantics, atomic
 * writes, unreadable ≠ missing, and symlinked goldens refused outright.
 */
final class GoldenFileLifecycleTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sugar-testing-golden-' . getmypid() . '-' . bin2hex(random_bytes(4));
        @mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $node) {
            /** @var \SplFileInfo $node */
            if ($node->isLink() || $node->isFile()) {
                @unlink($node->getPathname());
            } else {
                @rmdir($node->getPathname());
            }
        }
        @rmdir($this->dir);

        putenv('UPDATE_GOLDENS');
        putenv('SUGAR_TESTING_UPDATE_GOLDENS');
    }

    public function testLoadReturnsNullOnlyForAGenuinelyMissingFile(): void
    {
        self::assertNull(GoldenFile::load($this->dir . '/absent.golden'));
    }

    public function testCreateWritesABaselineThatReadsBackByteIdentical(): void
    {
        $path = $this->dir . '/first.golden';

        GoldenFile::create($path, "FRAME\x1b[0m");

        self::assertSame("FRAME\x1b[0m", GoldenFile::load($path));
    }

    public function testCreateRefusesToClobberAnExistingBaseline(): void
    {
        $path = $this->dir . '/winner.golden';
        GoldenFile::save($path, 'ORIGINAL');

        $this->expectException(GoldenFileExistsException::class);
        $this->expectExceptionMessage('already exists');

        try {
            GoldenFile::create($path, 'ATTACKER');
        } finally {
            // The winner's bytes must survive a refused create regardless
            // of how the exception lands.
            self::assertSame('ORIGINAL', file_get_contents($path));
        }
    }

    public function testRefusedCreateLeavesNoTempResidue(): void
    {
        $path = $this->dir . '/winner.golden';
        GoldenFile::save($path, 'ORIGINAL');

        try {
            GoldenFile::create($path, 'ATTACKER');
            self::fail('expected GoldenFileExistsException');
        } catch (GoldenFileExistsException) {
            // ok
        }

        self::assertSame([], glob($this->dir . '/.golden-tmp-*'), 'the losing create must clean up its temp file');
    }

    public function testSecondConcurrentCreateLosesAndFirstWins(): void
    {
        // The no-clobber guarantee is filesystem-enforced, not
        // check-then-act: two creates for the same fresh path — exactly
        // the six-way first-baseline race measured pre-cure — leave a
        // single consistent winner rather than an interleaved write.
        $path = $this->dir . '/race.golden';

        GoldenFile::create($path, 'FIRST');

        try {
            GoldenFile::create($path, 'SECOND');
            self::fail('expected GoldenFileExistsException from the losing create');
        } catch (GoldenFileExistsException) {
            // ok
        }

        self::assertSame('FIRST', GoldenFile::load($path));
    }

    public function testUpdateIsTheDeliberateRebaselinePath(): void
    {
        $path = $this->dir . '/pin.golden';
        GoldenFile::create($path, 'V1');

        GoldenFile::update($path, 'V2');

        self::assertSame('V2', GoldenFile::load($path));
    }

    public function testLoadRefusesASymlinkedGoldenAndSparesTheVictim(): void
    {
        $victim = $this->dir . '/victim.txt';
        file_put_contents($victim, 'SECRET');

        $link = $this->dir . '/evil.golden';
        self::assertTrue(symlink($victim, $link));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('symbolic link');

        GoldenFile::load($link);
    }

    public function testCreateRefusesASymlinkedGoldenAndSparesTheVictim(): void
    {
        $victim = $this->dir . '/victim2.txt';
        file_put_contents($victim, 'SECRET');

        $link = $this->dir . '/evil2.golden';
        self::assertTrue(symlink($victim, $link));

        try {
            GoldenFile::create($link, 'FORGED');
            self::fail('expected a refusal to write through a planted symlink');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('symbolic link', $e->getMessage());
        }

        self::assertSame('SECRET', file_get_contents($victim), 'the victim must be untouched');
    }

    public function testSaveRefusesASymlinkedGolden(): void
    {
        $victim = $this->dir . '/victim3.txt';
        file_put_contents($victim, 'SECRET');

        $link = $this->dir . '/evil3.golden';
        self::assertTrue(symlink($victim, $link));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('symbolic link');

        GoldenFile::save($link, 'FORGED');
    }

    public function testUnreadableGoldenThrowsInsteadOfMasqueradingAsMissing(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            self::markTestSkipped('running as root — mode 000 is not restrictive');
        }

        $path = $this->dir . '/locked.golden';
        file_put_contents($path, 'BASELINE');
        chmod($path, 0000);

        try {
            GoldenFile::load($path);
            self::fail('an unreadable golden must raise, not read as null');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Failed to read golden file', $e->getMessage());
        } finally {
            chmod($path, 0644);
        }
    }

    public function testResolveRejectsParentDirectoryEscape(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('escapes the fixtures');

        GoldenFile::resolve($this->dir, '../outside.golden');
    }

    public function testResolveRejectsWindowsStyleBackslashSmuggle(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('escapes the fixtures');

        GoldenFile::resolve($this->dir, '..\\outside.golden');
    }

    public function testResolveRejectsASymlinkedSubdirectoryJailBreak(): void
    {
        $outside = $this->dir . '/outside-target';
        mkdir($outside, 0700);
        mkdir($this->dir . '/fixtures', 0700);
        self::assertTrue(symlink($outside, $this->dir . '/fixtures/sub'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('escapes the fixtures');

        GoldenFile::resolve($this->dir, 'sub/x.golden');
    }

    public function testResolveAcceptsNestedPlainRelatives(): void
    {
        $resolved = GoldenFile::resolve($this->dir, 'group/case.golden');

        self::assertSame($this->dir . '/fixtures/group/case.golden', $resolved);
    }

    /**
     * The create path is guarded by a read-back check that can fail — a
     * behavioural pin, not a counter pin: PHPUnit's instance-scoped
     * numberOfAssertionsPerformed() never sees assertions made through a
     * helper's static Assert:: facade, so the counter is the wrong sensor.
     * What matters is the lifecycle: create lands the bytes, the golden then
     * passes matching content, and mismatching content fails without the
     * flag silently rewriting anything.
     */
    public function testAssertGoldenAnsiCreatesTheMissingGoldenAndThenGuardsIt(): void
    {
        $path = $this->dir . '/created.golden';
        putenv('SUGAR_TESTING_UPDATE_GOLDENS=1');

        Assertions::assertGoldenAnsi($path, 'HELLO');
        self::assertSame('HELLO', GoldenFile::load($path));

        // Same content with the flag still set: an existing golden is a
        // match, never a rewrite opportunity.
        Assertions::assertGoldenAnsi($path, 'HELLO');
        self::assertSame('HELLO', GoldenFile::load($path));

        putenv('SUGAR_TESTING_UPDATE_GOLDENS=0');
        Assertions::assertGoldenAnsi($path, 'HELLO');

        try {
            Assertions::assertGoldenAnsi($path, 'OTHER');
            self::fail('a mismatching actual must not pass against a created golden');
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            self::assertStringContainsString('mismatch', $e->getMessage());
        }

        self::assertSame('HELLO', GoldenFile::load($path), 'the failed comparison must leave the golden untouched');
    }

    public function testAssertGoldenAnsiMissingWithoutFlagFailsWithACreatePointer(): void
    {
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $this->expectExceptionMessage('UPDATE_GOLDENS=1');

        Assertions::assertGoldenAnsi($this->dir . '/never.golden', 'HELLO');
    }

    public function testAssertGoldenAnsiMismatchPointsToDeleteToRebaseline(): void
    {
        $path = $this->dir . '/stale.golden';
        GoldenFile::save($path, 'OLD');
        putenv('UPDATE_GOLDENS=1'); // legacy spelling must NOT turn a mismatch into an overwrite

        try {
            Assertions::assertGoldenAnsi($path, 'NEW');
            self::fail('a mismatching golden must fail even with UPDATE_GOLDENS set');
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            self::assertStringContainsString('create-only', $e->getMessage());
            self::assertStringContainsString('delete the file', $e->getMessage());
        }

        self::assertSame('OLD', GoldenFile::load($path));
    }
}
