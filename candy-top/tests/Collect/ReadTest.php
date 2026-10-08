<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\Paths;
use SugarCraft\Top\Collect\Read;
use SugarCraft\Top\Tests\Collect\Support\FixtureTree;

final class ReadTest extends TestCase
{
    private FixtureTree $tree;

    protected function setUp(): void
    {
        $this->tree = FixtureTree::empty();
    }

    protected function tearDown(): void
    {
        $this->tree->destroy();
    }

    public function testZeroIsAValueNotAnEmptyLine(): void
    {
        // Regression: strtok(...) ?: '' folded a sysfs "0" into "missing".
        $this->tree->write('flag', "0\n");

        $this->assertSame('0', Read::line($this->tree->root . '/flag'));
        $this->assertSame(0, Read::int($this->tree->root . '/flag'));
        $this->assertSame(0.0, Read::float($this->tree->root . '/flag'));
    }

    public function testMissingAndMalformedReadAsNull(): void
    {
        $this->tree->write('word', "abc\n");
        $this->tree->write('blank', "\n");

        $this->assertNull(Read::file($this->tree->root . '/nope'));
        $this->assertNull(Read::line($this->tree->root . '/blank'));
        $this->assertNull(Read::int($this->tree->root . '/word'));
        $this->assertNull(Read::float($this->tree->root . '/word'));
        $this->assertSame([], Read::entries($this->tree->root . '/nope'));
    }

    public function testEntriesAreNaturallySorted(): void
    {
        foreach (['hwmon10', 'hwmon2', 'hwmon1'] as $d) {
            $this->tree->write($d . '/name', 'x');
        }

        $this->assertSame(['hwmon1', 'hwmon2', 'hwmon10'], Read::entries($this->tree->root));
    }

    public function testPathsPrefixTheRoot(): void
    {
        $this->assertSame('/proc/stat', Paths::system()->proc('stat'));
        $this->assertSame('/sys/class/net', Paths::system()->sys('class/net'));
        $this->assertSame('/fx/proc', Paths::under('/fx/')->proc());
        $this->assertSame('/fx/etc/fstab', Paths::under('/fx')->path('/etc/fstab'));
    }
}
