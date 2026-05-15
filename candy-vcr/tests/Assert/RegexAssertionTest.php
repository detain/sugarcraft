<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Assert;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Assert\RegexAssertion;

/**
 * @covers \SugarCraft\Vcr\Assert\RegexAssertion
 */
final class RegexAssertionTest extends TestCase
{
    public function testMatchingPatternPasses(): void
    {
        $assertion = new RegexAssertion('/^hello world!$/');
        $result = $assertion->compare('/^hello world!$/', "hello world!");
        $this->assertTrue($result['ok']);
        $this->assertSame('', $result['diff']);
    }

    public function testNonMatchingPatternFails(): void
    {
        $assertion = new RegexAssertion('/^hello world!$/');
        $result = $assertion->compare('/^hello world!$/', "hello there!");
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString("regex mismatch", $result['diff']);
    }

    public function testEmptyActualAgainstDotStar(): void
    {
        $assertion = new RegexAssertion('/.*/');
        $result = $assertion->compare('/.*/', '');
        $this->assertTrue($result['ok']);
    }

    public function testCaseInsensitiveFlag(): void
    {
        $assertion = new RegexAssertion('/hello/', caseInsensitive: true);
        $result = $assertion->compare('/hello/', "HELLO world");
        $this->assertTrue($result['ok']);
    }

    public function testCaseSensitiveByDefault(): void
    {
        $assertion = new RegexAssertion('/hello/');
        $result = $assertion->compare('/hello/', "HELLO world");
        $this->assertFalse($result['ok']);
    }

    public function testMultilineFlag(): void
    {
        $assertion = new RegexAssertion('/^world$/m');
        $result = $assertion->compare('/^world$/m', "hello\nworld\n");
        $this->assertTrue($result['ok']);
    }

    public function testMultilineDisabledByDefault(): void
    {
        $assertion = new RegexAssertion('/^world$/');
        $result = $assertion->compare('/^world$/', "hello\nworld\n");
        $this->assertFalse($result['ok']);
    }

    public function testDotAllFlag(): void
    {
        $assertion = new RegexAssertion('/hello.*world/s');
        $result = $assertion->compare('/hello.*world/s', "hello\nworld");
        $this->assertTrue($result['ok']);
    }

    public function testDotAllDisabledByDefault(): void
    {
        $assertion = new RegexAssertion('/hello.*world/');
        $result = $assertion->compare('/hello.*world/', "hello\nworld");
        $this->assertFalse($result['ok']);
    }

    public function testInvalidPatternThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid PCRE pattern');
        new RegexAssertion('/[invalid/');
    }

    public function testDiffMessageShowsPatternAndOutput(): void
    {
        $assertion = new RegexAssertion('/^expected$/');
        $result = $assertion->compare('/^expected$/', 'actual');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('/^expected$/', $result['diff']);
        $this->assertStringContainsString('actual', $result['diff']);
    }

    public function testDiffMessageTruncatesLongOutput(): void
    {
        $assertion = new RegexAssertion('/^expected$/');
        $longOutput = str_repeat('x', 200);
        $result = $assertion->compare('/^expected$/', $longOutput);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('…', $result['diff']);
        $this->assertStringContainsString('200 bytes', $result['diff']);
    }

    public function testComplexPatternMatch(): void
    {
        $assertion = new RegexAssertion('/^[A-Z][a-z]+ \d+: \d+ bytes$/');
        $result = $assertion->compare('/^[A-Z][a-z]+ \d+: \d+ bytes$/', 'Output 42: 100 bytes');
        $this->assertTrue($result['ok']);
    }

    public function testCaptureGroupsIgnored(): void
    {
        // Capture groups shouldn't affect matching
        $assertion = new RegexAssertion('/(hello) (world)/');
        $result = $assertion->compare('/(hello) (world)/', 'hello world');
        $this->assertTrue($result['ok']);
    }

    public function testAlternationMatch(): void
    {
        $assertion = new RegexAssertion('/foo|bar|baz/');
        $this->assertTrue($assertion->compare('/foo|bar|baz/', 'foo')['ok']);
        $this->assertTrue($assertion->compare('/foo|bar|baz/', 'bar')['ok']);
        $this->assertTrue($assertion->compare('/foo|bar|baz/', 'baz')['ok']);
        $this->assertFalse($assertion->compare('/foo|bar|baz/', 'qux')['ok']);
    }

    public function testQuantifiersMatch(): void
    {
        $assertion = new RegexAssertion('/a{3}b{2,4}c*/');
        $this->assertTrue($assertion->compare('/a{3}b{2,4}c*/', 'aaabbc')['ok']);
        $this->assertTrue($assertion->compare('/a{3}b{2,4}c*/', 'aaabbbbc')['ok']);
        $this->assertFalse($assertion->compare('/a{3}b{2,4}c*/', 'aabbc')['ok']);
    }

    public function testAnchorBehavior(): void
    {
        // No anchors - partial match should pass
        $assertion = new RegexAssertion('/world/');
        $this->assertTrue($assertion->compare('/world/', 'hello world')['ok']);

        // With anchors - full string must match
        $assertion = new RegexAssertion('/^hello world$/');
        $this->assertTrue($assertion->compare('/^hello world$/', 'hello world')['ok']);
        $this->assertFalse($assertion->compare('/^hello world$/', 'say hello world')['ok']);
    }

    public function testZeroWidthAssertions(): void
    {
        // Positive lookahead: matches end-of-string preceded by 'world'
        $assertion = new RegexAssertion('/(?<=world)!$/');
        $this->assertTrue($assertion->compare('/(?<=world)!$/', 'hello world!')['ok']);
        $this->assertFalse($assertion->compare('/(?<=world)!$/', 'hello world.')['ok']);
    }

    public function testEscapeSpecialCharacters(): void
    {
        $assertion = new RegexAssertion('/\$100\.00/');
        $this->assertTrue($assertion->compare('/\$100\.00/', '$100.00')['ok']);
        $this->assertFalse($assertion->compare('/\$100\.00/', '$10000')['ok']);
    }
}
