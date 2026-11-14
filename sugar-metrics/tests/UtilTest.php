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

    public function testNonFiniteTokenSpellsSpecialsCanonically(): void
    {
        // finding 018: sprintf('%.6f', -INF) is "INF" — the sign flips and
        // no line backend may ship that. The shared table is the one
        // source of truth; pin every branch byte-exactly.
        $this->assertSame('NaN', Util::nonFiniteToken(NAN));
        $this->assertSame('+Inf', Util::nonFiniteToken(\INF));
        $this->assertSame('-Inf', Util::nonFiniteToken(-\INF));
        $this->assertNull(Util::nonFiniteToken(0.0));
        $this->assertNull(Util::nonFiniteToken(-0.0));
        $this->assertNull(Util::nonFiniteToken(1e15));
        $this->assertNull(Util::nonFiniteToken(9.9e-7));
    }

    public function testNeedsExponentFormBoundaries(): void
    {
        // The two "fixed six decimals would lie" regions: at or past 1e15
        // (%.6f prints six digits of noise) and sub-microsecond magnitudes
        // (%.6f collapses them to 0.000000). Zero is deliberately exempt —
        // rendering it in exponent form would be the uglier lie.
        $this->assertTrue(Util::needsExponentForm(1e15));
        $this->assertTrue(Util::needsExponentForm(-1e15));
        $this->assertTrue(Util::needsExponentForm(1e16));
        $this->assertTrue(Util::needsExponentForm(9.9e-7));
        $this->assertTrue(Util::needsExponentForm(-9.9e-7));
        $this->assertFalse(Util::needsExponentForm(0.0));
        $this->assertFalse(Util::needsExponentForm(1e-6));
        $this->assertFalse(Util::needsExponentForm(999999999999999.0));
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
