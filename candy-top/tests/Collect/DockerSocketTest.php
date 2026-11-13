<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\DockerSocket;

final class DockerSocketTest extends TestCase
{
    /** The sun_path budget differs per platform (candy-top-054). */
    public function testSunPathFollowsTheOs(): void
    {
        $this->assertSame(108, DockerSocket::sunPath('Linux'));
        $this->assertSame(104, DockerSocket::sunPath('Darwin'));
        $this->assertSame(104, DockerSocket::sunPath('FreeBSD'));
    }

    public function testUnknownPlatformKeepsTheLinuxBudget(): void
    {
        $this->assertSame(108, DockerSocket::sunPath('CYGWIN'));
    }

    /** Degradation contract unchanged: an unreachable path still answers "". */
    public function testUnreachablePathDegradesToEmpty(): void
    {
        $this->assertSame('', DockerSocket::fetch('/nonexistent-docker.sock'));
    }
}
