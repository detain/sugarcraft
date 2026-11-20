<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Plugins\Tests\Support\Scratch;
use SugarCraft\Plugins\Trust\PrivateBytes;

final class PrivateBytesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = Scratch::dir('private');
        chmod($this->dir, 0755);
    }

    protected function tearDown(): void
    {
        Scratch::remove($this->dir);
    }

    public function testAPrivateFileInAPrivateDirectoryIsAccepted(): void
    {
        $file = Scratch::put($this->dir . '/Impl.php', '<?php');
        chmod($file, 0600);

        $this->assertNull(PrivateBytes::refusal($file));
    }

    public function testAWorldWritableFileIsRefused(): void
    {
        $file = Scratch::put($this->dir . '/Impl.php', '<?php');
        chmod($file, 0666);

        $this->assertSame('world-writable', PrivateBytes::refusal($file));
    }

    public function testAWorldWritableParentDirectoryIsRefused(): void
    {
        $file = Scratch::put($this->dir . '/Impl.php', '<?php');
        chmod($file, 0644);
        chmod($this->dir, 0777);

        $this->assertSame('world-writable', PrivateBytes::refusal($file));
    }

    public function testAGroupWritableFileIsAcceptedBecauseOnlyWorldWriteIsRefused(): void
    {
        // Matches candy-code's refuseLooseBytes, whose mode check also tests only
        // the world-write bit, so a 0664 file in a 0755 directory is private.
        $file = Scratch::put($this->dir . '/Impl.php', '<?php');
        chmod($file, 0664);

        $this->assertNull(PrivateBytes::refusal($file));
    }

    public function testAGroupWritableParentDirectoryIsAcceptedBecauseOnlyWorldWriteIsRefused(): void
    {
        $file = Scratch::put($this->dir . '/Impl.php', '<?php');
        chmod($file, 0644);
        chmod($this->dir, 0775);

        $this->assertNull(PrivateBytes::refusal($file));
    }

    public function testAFileOwnedByAnotherUidIsRefused(): void
    {
        $file = Scratch::put($this->dir . '/Impl.php', '<?php');
        chmod($file, 0600);
        $owner = (int) fileowner($file);

        $this->assertSame('foreign-owner', PrivateBytes::refusal($file, processUid: $owner + 1));
    }

    public function testAFileOwnedByTheProcessUidIsAccepted(): void
    {
        $file = Scratch::put($this->dir . '/Impl.php', '<?php');
        chmod($file, 0600);

        $this->assertNull(PrivateBytes::refusal($file, processUid: (int) fileowner($file)));
    }

    public function testAVanishedFileIsLeftToTheRequireNotThisGate(): void
    {
        $this->assertNull(PrivateBytes::refusal($this->dir . '/absent.php'));
    }
}
