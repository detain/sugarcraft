<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Assert;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Assert\ContainsAssertion;

/**
 * @covers \SugarCraft\Vcr\Assert\ContainsAssertion
 */
final class ContainsAssertionTest extends TestCase
{
    public function testSubstringFoundAtStartPasses(): void
    {
        $assertion = new ContainsAssertion();
        $result = $assertion->compare('hello', 'hello world');
        $this->assertTrue($result['ok']);
        $this->assertSame('', $result['diff']);
    }

    public function testSubstringFoundAtEndPasses(): void
    {
        $assertion = new ContainsAssertion();
        $result = $assertion->compare('world', 'hello world');
        $this->assertTrue($result['ok']);
        $this->assertSame('', $result['diff']);
    }

    public function testSubstringFoundInMiddlePasses(): void
    {
        $assertion = new ContainsAssertion();
        $result = $assertion->compare('lo wo', 'hello world');
        $this->assertTrue($result['ok']);
        $this->assertSame('', $result['diff']);
    }

    public function testExactMatchPasses(): void
    {
        $assertion = new ContainsAssertion();
        $result = $assertion->compare('hello world', 'hello world');
        $this->assertTrue($result['ok']);
        $this->assertSame('', $result['diff']);
    }

    public function testSubstringNotFoundFails(): void
    {
        $assertion = new ContainsAssertion();
        $result = $assertion->compare('goodbye', 'hello world');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('substring not found', $result['diff']);
        $this->assertStringContainsString('goodbye', $result['diff']);
    }

    public function testEmptyExpectedAlwaysPasses(): void
    {
        $assertion = new ContainsAssertion();
        $result = $assertion->compare('', 'hello world');
        $this->assertTrue($result['ok']);
        $this->assertSame('', $result['diff']);
    }

    public function testEmptyExpectedWithEmptyActualPasses(): void
    {
        $assertion = new ContainsAssertion();
        $result = $assertion->compare('', '');
        $this->assertTrue($result['ok']);
        $this->assertSame('', $result['diff']);
    }

    public function testEmptyActualWithNonEmptyExpectedFails(): void
    {
        $assertion = new ContainsAssertion();
        $result = $assertion->compare('hello', '');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('substring not found', $result['diff']);
    }

    public function testCaseSensitiveMatching(): void
    {
        $assertion = new ContainsAssertion();
        $result = $assertion->compare('Hello', 'hello world');
        $this->assertFalse($result['ok']);
    }

    public function testCaseSensitiveMatchingPasses(): void
    {
        $assertion = new ContainsAssertion();
        $result = $assertion->compare('hello', 'hello world');
        $this->assertTrue($result['ok']);
    }

    public function testMultibyteCharacterSupport(): void
    {
        $assertion = new ContainsAssertion();
        $result = $assertion->compare('こんにちは', 'hello こんにちは world');
        $this->assertTrue($result['ok']);
        $this->assertSame('', $result['diff']);
    }

    public function testAnsiEscapeSequenceNotMatchedAsText(): void
    {
        $assertion = new ContainsAssertion();
        $ansiOutput = "\x1b[1mbold text\x1b[0m";
        $result = $assertion->compare('bold text', $ansiOutput);
        $this->assertTrue($result['ok']);
    }

    public function testPartialMatchWithinAnsiSequence(): void
    {
        $assertion = new ContainsAssertion();
        // Test that ANSI escape sequence bytes are not matched as literal substring
        // The string "1m" appears in the escape sequence \x1b[1m but when we look for
        // just "1m" as a substring in the full output "say 1m hello", it should match
        // because str_contains does literal byte comparison, not semantic ANSI parsing
        $output = "say 1m hello";
        $result = $assertion->compare('1m', $output);
        $this->assertTrue($result['ok']);
    }

    public function testDiffMessageShowsSubstringAndOutput(): void
    {
        $assertion = new ContainsAssertion();
        $result = $assertion->compare('notfound', 'some output');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('notfound', $result['diff']);
        $this->assertStringContainsString('some output', $result['diff']);
    }

    public function testDiffMessageTruncatesLongOutput(): void
    {
        $assertion = new ContainsAssertion();
        $longOutput = str_repeat('x', 200);
        $result = $assertion->compare('notfound', $longOutput);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('…', $result['diff']);
        $this->assertStringContainsString('200 bytes', $result['diff']);
    }

    public function testLongSubstringTruncatedInDiff(): void
    {
        $assertion = new ContainsAssertion();
        $longSubstring = str_repeat('a', 100);
        $result = $assertion->compare($longSubstring, 'short');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('…', $result['diff']);
    }

    public function testOverlappingSubstrings(): void
    {
        $assertion = new ContainsAssertion();
        // 'aaa' appears twice, overlapping
        $result = $assertion->compare('aaa', 'aaaa');
        $this->assertTrue($result['ok']);
    }

    public function testSingleCharacterMatch(): void
    {
        $assertion = new ContainsAssertion();
        $result = $assertion->compare('x', 'hex value');
        $this->assertTrue($result['ok']);
    }

    public function testSingleCharacterNotFound(): void
    {
        $assertion = new ContainsAssertion();
        $result = $assertion->compare('z', 'hex value');
        $this->assertFalse($result['ok']);
    }

    public function testWhitespaceInSubstring(): void
    {
        $assertion = new ContainsAssertion();
        $result = $assertion->compare('hello world', "say hello world here");
        $this->assertTrue($result['ok']);
    }

    public function testNewlineInSubstring(): void
    {
        $assertion = new ContainsAssertion();
        $result = $assertion->compare("hello\nworld", "say hello\nworld here");
        $this->assertTrue($result['ok']);
    }

    public function testSpecialCharactersInSubstring(): void
    {
        $assertion = new ContainsAssertion();
        $result = $assertion->compare('foo@bar.com', 'contact: foo@bar.com');
        $this->assertTrue($result['ok']);
    }

    public function testEmptyDiffWhenPassing(): void
    {
        $assertion = new ContainsAssertion();
        $result = $assertion->compare('test', 'this is a test string');
        $this->assertSame('', $result['diff']);
    }

    public function testUnicodeCharactersPreserved(): void
    {
        $assertion = new ContainsAssertion();
        $result = $assertion->compare('Émoji', 'Contains Émoji character');
        $this->assertTrue($result['ok']);
    }
}