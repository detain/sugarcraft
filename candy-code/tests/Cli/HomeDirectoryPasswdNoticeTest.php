<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Support\HomeDirectory;

/**
 * S-H03: a HOME-less launch that resolves through the passwd database is
 * detectable (the stderr notice on -p/run/serve keys off it).
 */
final class HomeDirectoryPasswdNoticeTest extends TestCase
{
    private string|false $home;
    private string|false $profile;

    protected function setUp(): void
    {
        $this->home = getenv('HOME');
        $this->profile = getenv('USERPROFILE');
    }

    protected function tearDown(): void
    {
        $this->home === false ? putenv('HOME') : putenv('HOME=' . $this->home);
        $this->profile === false ? putenv('USERPROFILE') : putenv('USERPROFILE=' . $this->profile);
    }

    public function testEnvHomeIsNotPasswdResolved(): void
    {
        putenv('HOME=/somewhere');

        $this->assertFalse(HomeDirectory::resolvedViaPasswd());
    }

    public function testUnsetHomeResolvedFromPasswdIsFlagged(): void
    {
        if (!function_exists('posix_getpwuid') || !is_array(posix_getpwuid(posix_geteuid()))) {
            $this->markTestSkipped('no passwd entry');
        }
        putenv('HOME');
        putenv('USERPROFILE');

        $this->assertTrue(HomeDirectory::resolvedViaPasswd());
        $this->assertSame(posix_getpwuid(posix_geteuid())['dir'], HomeDirectory::resolved());
    }
}
