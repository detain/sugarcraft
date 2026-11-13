<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Files\Support\Home;

/**
 * CF-18 (closes 092): the launcher's home-directory cascade. POSIX HOME
 * wins when present; Windows-native USERPROFILE and the split
 * HOMEDRIVE+HOMEPATH pair cover shells that never export HOME; '/' is the
 * last-resort floor, not the first guess.
 */
final class HomeTest extends TestCase
{
    public function testPosixHomeWinsOverTheWindowsForms(): void
    {
        self::assertSame(
            '/home/alice',
            Home::resolve(['HOME' => '/home/alice', 'USERPROFILE' => 'C:\\Users\\alice', 'HOMEDRIVE' => 'C:', 'HOMEPATH' => '\\Users\\alice']),
        );
    }

    public function testUserProfileCarriesAWindowsShellWithoutHome(): void
    {
        self::assertSame(
            'C:\\Users\\alice',
            Home::resolve(['USERPROFILE' => 'C:\\Users\\alice', 'HOMEDRIVE' => 'C:', 'HOMEPATH' => '\\Users\\alice']),
        );
    }

    public function testTheSplitDriveAndPathPairJoinsWhenNoWholeHomeExists(): void
    {
        self::assertSame('C:\\Users\\alice', Home::resolve(['HOMEDRIVE' => 'C:', 'HOMEPATH' => '\\Users\\alice']));
    }

    public function testAPartialPairDoesNotResolve(): void
    {
        // HOMEPATH alone would name a path on an unknown volume — the pair
        // must be complete or the cascade falls through to the floor.
        self::assertSame('/', Home::resolve(['HOMEPATH' => '\\Users\\alice']));
        self::assertSame('/', Home::resolve(['HOMEDRIVE' => 'C:']));
    }

    public function testEmptyAndFalseValuesAreSkippedNotSpilled(): void
    {
        self::assertSame('/home/alice', Home::resolve(['HOME' => '', 'USERPROFILE' => '/home/alice']));
        self::assertSame('/', Home::resolve(['HOME' => false, 'USERPROFILE' => false]));
    }

    public function testEverythingUnsetLandsOnTheRootFloor(): void
    {
        self::assertSame('/', Home::resolve([]));
    }

    public function testTheLiveEnvironmentResolvesToADirectoryPath(): void
    {
        // No override — read the real env the way the launcher does. On any
        // host this must be an absolute path, never the empty string.
        $resolved = Home::resolve();

        self::assertNotSame('', $resolved);
        self::assertSame('/', $resolved[0] ?? '', 'the resolved home is an absolute path');
    }
}
