<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Plugins\Trust\TrustList;

final class TrustListTest extends TestCase
{
    public function testAnExactNameIsContained(): void
    {
        $trust = new TrustList(['candy-discord', 'candy-msteams']);

        $this->assertTrue($trust->contains('candy-discord'));
        $this->assertTrue($trust->contains('candy-msteams'));
    }

    public function testMatchingIsCaseSensitive(): void
    {
        $trust = new TrustList(['Demo']);

        $this->assertFalse($trust->contains('demo'));
        $this->assertFalse($trust->contains('DEMO'));
        $this->assertTrue($trust->contains('Demo'));
    }

    public function testAPartialNameIsNotContained(): void
    {
        $trust = new TrustList(['candy-discord']);

        $this->assertFalse($trust->contains('candy'));
        $this->assertFalse($trust->contains('candy-discord-extra'));
    }

    public function testAnEmptyListContainsNothingAndReportsItsNames(): void
    {
        $empty = new TrustList([]);

        $this->assertFalse($empty->contains(''));
        $this->assertSame([], $empty->names());
        $this->assertSame(['a', 'b'], (new TrustList(['a', 'b']))->names());
    }
}
