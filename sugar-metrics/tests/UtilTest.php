<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Tests;

use SugarCraft\Metrics\Util;
use PHPUnit\Framework\TestCase;

final class UtilTest extends TestCase
{
    public function testTagKeyReturnsEmptyStringForEmptyTags(): void
    {
        $this->assertSame('', Util::tagKey([]));
    }

    public function testTagKeyFormatsSingleTagAsKeyEqualsValue(): void
    {
        $this->assertSame('method=GET', Util::tagKey(['method' => 'GET']));
    }

    public function testTagKeyJoinsMultipleTagsWithPipe(): void
    {
        $this->assertSame('a=1|b=2', Util::tagKey(['a' => '1', 'b' => '2']));
    }

    public function testTagKeySortsTagsByKeyForStableOutput(): void
    {
        $unordered = ['z' => 'last', 'a' => 'first', 'm' => 'middle'];
        $this->assertSame('a=first|m=middle|z=last', Util::tagKey($unordered));
    }

    public function testTagKeyProducesSameResultRegardlessOfInputOrder(): void
    {
        $tags1 = ['method' => 'GET', 'route' => '/api'];
        $tags2 = ['route' => '/api', 'method' => 'GET'];
        $this->assertSame(Util::tagKey($tags1), Util::tagKey($tags2));
    }

    public function testTagKeyHandlesValuesWithSpecialCharacters(): void
    {
        $this->assertSame('host=localhost:8080', Util::tagKey(['host' => 'localhost:8080']));
    }

    public function testTagKeySeparatorsInsideValuesDoNotForgeExtraPairs(): void
    {
        // The adversarial pair from the audit: unescaped serialization
        // made BOTH of these render the string 'x=a|y=b', collapsing two
        // distinct series into one storage/cardinality slot.
        $forged = Util::tagKey(['x' => 'a|y=b']);
        $genuine = Util::tagKey(['x' => 'a', 'y' => 'b']);

        $this->assertNotSame($forged, $genuine);
        $this->assertSame('x=a\\|y\\=b', $forged);
        $this->assertSame('x=a|y=b', $genuine);
    }

    public function testTagKeyEscapesSeparatorsInsideKeys(): void
    {
        $this->assertSame('a\\|b=c', Util::tagKey(['a|b' => 'c']));
        $this->assertSame('a\\=b=c', Util::tagKey(['a=b' => 'c']));
    }

    public function testTagKeyEscapesBackslashSoItCannotUnEscapeSeparators(): void
    {
        // The escape character itself must be escaped (first): value 'a\'
        // serializes to the doubled-backslash form, so it can never make
        // a following separator ambiguous or undo a pair escape.
        $this->assertSame('x=a\\\\', Util::tagKey(['x' => 'a\\']));
        $this->assertNotSame(
            Util::tagKey(['x' => 'a\\|y\\=b']),
            Util::tagKey(['x' => 'a|y=b']),
        );
    }
}
