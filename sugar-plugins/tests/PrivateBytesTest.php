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

    public function testAVanishedFileIsLeftToTheRequireNotThisGate(): void
    {
        $this->assertNull(PrivateBytes::refusal($this->dir . '/absent.php'));
    }
}
