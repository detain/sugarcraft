<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SugarCraft\Fuzzy\Matcher\SahilmMatcher;
use SugarCraft\Fuzzy\SahilmScoring;

#[CoversClass(SahilmScoring::class)]
#[CoversClass(SahilmMatcher::class)]
final class SahilmScoringTest extends TestCase
{
    #[Test]
    public function testCanonicalWeightsAreTheHistoricalConstants(): void
    {
        $s = SahilmScoring::canonical();

        $this->assertSame(1, $s->matchScore);
        $this->assertSame(5, $s->consecutiveBonus);
        $this->assertSame(10, $s->separatorBonus);
        $this->assertSame(10, $s->camelBonus);
        $this->assertSame(15, $s->firstCharBonus);
        $this->assertSame(1, $s->lowerCaseBonus);
        $this->assertEquals(new SahilmScoring(), $s);
        $this->assertEquals(new SahilmScoring(), SahilmScoring::new());
        $this->assertEquals(new SahilmScoring(camelBonus: 3), SahilmScoring::new(camelBonus: 3));
    }

    #[Test]
    public function testWithersReturnNewInstancesAndChangeOneField(): void
    {
        $base = SahilmScoring::canonical();

        $cases = [
            'matchScore' => $base->withMatchScore(7),
            'consecutiveBonus' => $base->withConsecutiveBonus(7),
            'separatorBonus' => $base->withSeparatorBonus(7),
            'camelBonus' => $base->withCamelBonus(7),
            'firstCharBonus' => $base->withFirstCharBonus(7),
            'lowerCaseBonus' => $base->withLowerCaseBonus(7),
        ];

        foreach ($cases as $field => $changed) {
            $this->assertNotSame($base, $changed);
            foreach (array_keys($cases) as $other) {
                $expected = $other === $field ? 7 : $base->{$other};
                $this->assertSame($expected, $changed->{$other}, "$field wither touched $other");
            }
        }

        // Zero is a real value, not "unset".
        $this->assertSame(0, $base->withFirstCharBonus(0)->firstCharBonus);
        $this->assertEquals(SahilmScoring::canonical(), $base);
    }

    #[Test]
    public function testMatcherDefaultsToCanonicalWeights(): void
    {
        $m = new SahilmMatcher();

        $this->assertEquals(SahilmScoring::canonical(), $m->scoring());
        $this->assertFalse($m->caseSensitive());
        $this->assertEquals(
            (new SahilmMatcher(false, SahilmScoring::canonical()))->match('fb', 'foo_Bar'),
            $m->match('fb', 'foo_Bar'),
        );
    }

    #[Test]
    public function testInjectedWeightsChangeTheScore(): void
    {
        // 'foo' vs 'foobar': f = 1+15(first)+1(lower); o = 1+5+1; o = 1+5+1 → 31.
        $this->assertSame(31, (new SahilmMatcher())->match('foo', 'foobar')?->score);

        $noFirst = new SahilmMatcher(false, SahilmScoring::canonical()->withFirstCharBonus(0));
        $this->assertSame(16, $noFirst->match('foo', 'foobar')?->score);

        $tuned = SahilmMatcher::new(scoring: new SahilmScoring(matchScore: 2, consecutiveBonus: 0, lowerCaseBonus: 0));
        $this->assertSame(2 + 15 + 2 + 2, $tuned->match('foo', 'foobar')?->score);
        $this->assertSame([0, 1, 2], $tuned->match('foo', 'foobar')?->matchedIndices);
    }

    #[Test]
    public function testSeparatorAndCamelWeightsApply(): void
    {
        $sep = new SahilmMatcher(false, SahilmScoring::canonical()->withSeparatorBonus(100));
        $camel = new SahilmMatcher(false, SahilmScoring::canonical()->withCamelBonus(100));
        $base = new SahilmMatcher();

        $this->assertSame($base->match('b', 'a_b')->score + 90, $sep->match('b', 'a_b')?->score);
        $this->assertSame($base->match('a', 'Ba')->score + 90, $camel->match('a', 'Ba')?->score);
    }

    #[Test]
    public function testNewForwardsCaseSensitivity(): void
    {
        $cs = SahilmMatcher::new(true);

        $this->assertTrue($cs->caseSensitive());
        $this->assertNull($cs->match('F', 'foo'));
        $this->assertNotNull(SahilmMatcher::new()->match('F', 'foo'));
    }
}
