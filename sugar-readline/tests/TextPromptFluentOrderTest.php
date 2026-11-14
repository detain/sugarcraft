<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\TextareaPrompt;
use SugarCraft\Readline\TextPrompt;

/**
 * Finding 092: withDefault()/withCharLimit() (and TextareaPrompt's
 * withDefault()/withMaxLines()) clamped only when the limit was applied
 * FIRST, and withHidden(false) silently reset a configured mask glyph to
 * '*'. The cure is order-independence — whichever setter runs last
 * enforces the clamp — plus a dedicated withMask() whose glyph survives
 * hidden toggles.
 */
final class TextPromptFluentOrderTest extends TestCase
{
    private static function textCursor(TextPrompt $p): int
    {
        $property = new \ReflectionProperty(TextPrompt::class, 'cursor');
        return $property->getValue($p);
    }

    public function testDefaultFirstNowClampsLikeLimitFirst(): void
    {
        $long = \str_repeat('a', 10);
        $defaultFirst = TextPrompt::new('> ')->withDefault($long)->withCharLimit(4);
        $limitFirst = TextPrompt::new('> ')->withCharLimit(4)->withDefault($long);

        $this->assertSame('aaaa', $defaultFirst->value());
        $this->assertSame($limitFirst->value(), $defaultFirst->value());
        $this->assertSame(self::textCursor($limitFirst), self::textCursor($defaultFirst));
        $this->assertSame($limitFirst->view(), $defaultFirst->view());
    }

    public function testLimitFirstClampLawUnchanged(): void
    {
        $p = TextPrompt::new('> ')->withCharLimit(3)->withDefault('abcdef');
        $this->assertSame('abc', $p->value());
        $this->assertSame(3, self::textCursor($p));
    }

    public function testEmptyBufferUntouchedByLimit(): void
    {
        $p = TextPrompt::new('> ')->withCharLimit(2);
        $this->assertSame('', $p->value());
        $this->assertSame(0, self::textCursor($p));
    }

    public function testMultibyteClampSurvivesEitherOrder(): void
    {
        // Four ü + b: 5 chars, 9 bytes — proves the clamp is char-counted
        // on both paths, not just the original withDefault one.
        $value = "\u{fc}\u{fc}\u{fc}\u{fc}b";
        $defaultFirst = TextPrompt::new('> ')->withDefault($value)->withCharLimit(3);
        $limitFirst = TextPrompt::new('> ')->withCharLimit(3)->withDefault($value);

        $this->assertSame("\u{fc}\u{fc}\u{fc}", $defaultFirst->value());
        $this->assertSame($limitFirst->value(), $defaultFirst->value());
        $this->assertSame(3, self::textCursor($defaultFirst));
    }

    public function testMaxLinesFirstAndLinesFirstYieldIdenticalTextarea(): void
    {
        $value = "alpha\nbeta\ngamma";
        $defaultFirst = TextareaPrompt::new('> ')->withDefault($value)->withMaxLines(2);
        $maxFirst = TextareaPrompt::new('> ')->withMaxLines(2)->withDefault($value);

        $this->assertSame(['alpha', 'beta'], $defaultFirst->lines());
        $this->assertSame($maxFirst->lines(), $defaultFirst->lines());
        $this->assertSame($maxFirst->value(), $defaultFirst->value());
    }

    public function testGrowThenShrinkEndsSliced(): void
    {
        $p = TextareaPrompt::new('> ')->withMaxLines(5)->withDefault("a\nb\nc");
        $this->assertSame(['a', 'b', 'c'], $p->lines());
        $p = $p->withMaxLines(2);
        $this->assertSame(['a', 'b'], $p->lines());
    }

    public function testMaskSurvivesHiddenOffToggle(): void
    {
        $p = TextPrompt::new('PIN: ')->withMask('#')->withHidden(true)
            ->handleChar('1')->handleChar('2')->handleChar('3');
        $this->assertStringContainsString('###', $p->view());

        $shown = $p->withHidden(false);
        $this->assertStringContainsString('123', $shown->view());
        $this->assertStringNotContainsString('***', $shown->view());

        // Back to hidden: the configured glyph is still in place.
        $this->assertStringContainsString('###', $shown->withHidden(true)->view());
        $this->assertStringNotContainsString('***', $shown->withHidden(true)->view());
    }

    public function testExplicitMaskArgumentStillSetsTheGlyph(): void
    {
        $p = TextPrompt::new('PIN: ')->withMask('#')->withHidden(true, '@')
            ->handleChar('1')->handleChar('2');
        $this->assertStringContainsString('@@', $p->view());
        $this->assertStringNotContainsString('##', $p->view());
    }

    public function testLegacyDefaultStillMasksWithStar(): void
    {
        $p = TextPrompt::new('PIN: ')->withHidden(true)->handleChar('x');
        $this->assertStringContainsString('*', $p->view());
    }
}
