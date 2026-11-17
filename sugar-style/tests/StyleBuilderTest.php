<?php

declare(strict_types=1);

namespace SugarCraft\Style\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Style\Align;
use SugarCraft\Style\Style;
use SugarCraft\Style\StyleBuilder;
use SugarCraft\Style\VAlign;

/**
 * Pins {@see StyleBuilder}: a chain built through builder() must equal the
 * same chain of Style setters, value for value and byte for byte.
 */
final class StyleBuilderTest extends TestCase
{
    public function testBuilderStartsFromTheSeedAndReturnsABuilder(): void
    {
        $seed = Style::new()->bold()->width(10);
        $builder = $seed->builder();

        self::assertInstanceOf(StyleBuilder::class, $builder);
        $built = $builder->fg('#ff8800')->build();
        self::assertTrue($built->isBold(), 'seed bold carries into the build');
        self::assertSame(10, $built->getWidth(), 'seed width carries into the build');
        self::assertEquals(Color::hex('#ff8800'), $built->getForeground());
    }

    public function testBuildNeverMutatesTheSeed(): void
    {
        $seed = Style::new();
        $built = $seed->builder()->bold()->width(4)->build();

        self::assertFalse($seed->isBold());
        self::assertNull($seed->getWidth());
        self::assertTrue($built->isBold());
        self::assertSame(4, $built->getWidth());
    }

    /**
     * @return array<string, array{\Closure(Style): Style, \Closure(StyleBuilder): StyleBuilder}>
     */
    public static function equivalentChains(): array
    {
        return [
            'colour and attributes' => [
                static fn (Style $s): Style => $s->foreground(Color::hex('#ff8800'))->background(Color::hex('#101010'))
                    ->bold()->italic()->underline()->strikethrough()->faint()->blink()->reverse(),
                static fn (StyleBuilder $b): StyleBuilder => $b->foreground(Color::hex('#ff8800'))->background(Color::hex('#101010'))
                    ->bold()->italic()->underline()->strikethrough()->faint()->blink()->reverse(),
            ],
            'hex-string aliases' => [
                static fn (Style $s): Style => $s->fg('#ff8800')->on('#000080'),
                static fn (StyleBuilder $b): StyleBuilder => $b->fg('#ff8800')->bg('#000080'),
            ],
            'sizes' => [
                static fn (Style $s): Style => $s->width(12)->height(3)->maxWidth(10)->maxHeight(2),
                static fn (StyleBuilder $b): StyleBuilder => $b->width(12)->height(3)->maxWidth(10)->maxHeight(2),
            ],
            'padding and margin shorthand' => [
                static fn (Style $s): Style => $s->padding(1, 2)->margin(3),
                static fn (StyleBuilder $b): StyleBuilder => $b->padding(1, 2)->margin(3),
            ],
            'alignment and profile' => [
                static fn (Style $s): Style => $s->align(Align::Right)->verticalAlign(VAlign::Bottom)
                    ->colorProfile(ColorProfile::Ansi256),
                static fn (StyleBuilder $b): StyleBuilder => $b->align(Align::Right)->verticalAlign(VAlign::Bottom)
                    ->colorProfile(ColorProfile::Ansi256),
            ],
            'inline, wrap and bound content' => [
                static fn (Style $s): Style => $s->inline()->wordWrap()->width(7)->setString('one two three'),
                static fn (StyleBuilder $b): StyleBuilder => $b->inline()->wordWrap()->width(7)->setString('one two three'),
            ],
        ];
    }

    /**
     * @param \Closure(Style): Style $chain
     * @param \Closure(StyleBuilder): StyleBuilder $builderChain
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('equivalentChains')]
    public function testBuilderMatchesTheChainedSetters(\Closure $chain, \Closure $builderChain): void
    {
        $seed = Style::new();
        $viaSetters = $chain($seed);
        $viaBuilder = $builderChain($seed->builder())->build();

        self::assertSame($viaSetters->render('hello world'), $viaBuilder->render('hello world'));
        self::assertSame($viaSetters->getPadding(), $viaBuilder->getPadding());
        self::assertSame($viaSetters->getMargin(), $viaBuilder->getMargin());
        self::assertSame($viaSetters->getWidth(), $viaBuilder->getWidth());
        self::assertSame($viaSetters->isInline(), $viaBuilder->isInline());
        self::assertSame($viaSetters->isBold(), $viaBuilder->isBold());
    }

    public function testBuilderRecordsTheSamePropsAsTheSetters(): void
    {
        $viaSetters = Style::new()->bold()->width(9)->strikethrough()->padding(2);
        $viaBuilder = Style::new()->builder()->bold()->width(9)->strikethrough()->padding(2)->build();

        foreach (['bold', 'width', 'strike', 'padding'] as $prop) {
            self::assertSame($viaSetters->isSet($prop), $viaBuilder->isSet($prop), $prop);
        }
        self::assertTrue($viaBuilder->isSet('strike'), 'strikethrough() records the strike prop');
    }

    public function testSetterOrderLastWriteWins(): void
    {
        $built = Style::new()->builder()->width(4)->width(9)->bold()->bold(false)->build();

        self::assertSame(9, $built->getWidth());
        self::assertFalse($built->isBold());
    }

    public function testBuildLeavesTheDraftUsable(): void
    {
        $builder = Style::new()->builder()->bold();
        $first = $builder->build();
        $second = $builder->width(5)->build();

        self::assertTrue($first->isBold());
        self::assertNull($first->getWidth(), 'an earlier build is not changed by later setters');
        self::assertSame(5, $second->getWidth());
    }

    public function testBuildDoesNotCopyTheRenderMemos(): void
    {
        $seed = Style::new()->bold()->foreground(Color::hex('#ff0000'));
        $seed->render('warm the memo');
        $built = $seed->builder()->width(6)->build();

        $memo = new \ReflectionProperty(Style::class, 'contentSgrMemo');
        self::assertNull($memo->getValue($built), 'the builder rebuilds the memo, it does not copy it');
        self::assertTrue($built->isBold());
    }

    public function testNonNegativeSizesAreValidatedLikeTheSetters(): void
    {
        foreach (['width', 'height', 'maxWidth', 'maxHeight'] as $method) {
            $viaSetter = self::catchMessage(static fn () => Style::new()->{$method}(-1));
            $viaBuilder = self::catchMessage(static fn () => Style::new()->builder()->{$method}(-1));
            self::assertNotNull($viaSetter, $method . ' setter rejects a negative size');
            self::assertSame($viaSetter, $viaBuilder, $method . ' builder rejects with the same message');
        }
    }

    public function testPaddingArityIsValidatedLikeTheSetter(): void
    {
        $viaSetter = self::catchMessage(static fn () => Style::new()->padding(1, 2, 3));
        $viaBuilder = self::catchMessage(static fn () => Style::new()->builder()->padding(1, 2, 3));

        self::assertNotNull($viaSetter);
        self::assertSame($viaSetter, $viaBuilder);
    }

    public function testColourNamesAreRefusedLikeTheSetter(): void
    {
        self::assertNotNull(self::catchMessage(static fn () => Style::new()->fg('navy')));
        self::assertNotNull(self::catchMessage(static fn () => Style::new()->builder()->fg('navy')));
    }

    /** @return ?string the exception message thrown by $fn, or null when it returns */
    private static function catchMessage(\Closure $fn): ?string
    {
        try {
            $fn();
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }
        return null;
    }
}
