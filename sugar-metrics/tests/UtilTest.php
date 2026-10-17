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
}
