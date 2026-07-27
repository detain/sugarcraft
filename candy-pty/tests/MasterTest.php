<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Master;

final class MasterTest extends TestCase
{
    public function testConstructorSetsFdAndSlavePath(): void
    {
        $master = new Master(5, '/dev/pts/0');

        $this->assertSame(5, $master->fd);
        $this->assertSame('/dev/pts/0', $master->slavePath);
    }

    public function testFdIsReadonly(): void
    {
        $master = new Master(10, '/dev/pts/1');

        $reflection = new \ReflectionClass($master);
        $prop = $reflection->getProperty('fd');
        $this->assertTrue($prop->isReadOnly(), 'fd property must be readonly');
    }

    public function testSlavePathIsReadonly(): void
    {
        $master = new Master(10, '/dev/pts/1');

        $reflection = new \ReflectionClass($master);
        $prop = $reflection->getProperty('slavePath');
        $this->assertTrue($prop->isReadOnly(), 'slavePath property must be readonly');
    }

    public function testEqualsIdenticalInstances(): void
    {
        $a = new Master(5, '/dev/pts/0');
        $b = new Master(5, '/dev/pts/0');

        $this->assertSame($a->fd, $b->fd);
        $this->assertSame($a->slavePath, $b->slavePath);
    }

    public function testDifferentFdsProduceDifferentInstances(): void
    {
        $a = new Master(5, '/dev/pts/0');
        $b = new Master(6, '/dev/pts/0');

        $this->assertNotSame($a->fd, $b->fd);
    }

    public function testDifferentSlavePathsProduceDifferentInstances(): void
    {
        $a = new Master(5, '/dev/pts/0');
        $b = new Master(5, '/dev/pts/1');

        $this->assertNotSame($a->slavePath, $b->slavePath);
    }

    public function testFdCanBeZero(): void
    {
        $master = new Master(0, '/dev/pts/0');
        $this->assertSame(0, $master->fd);
    }

    public function testSlavePathCanBeEmpty(): void
    {
        $master = new Master(3, '');
        $this->assertSame('', $master->slavePath);
    }
}
