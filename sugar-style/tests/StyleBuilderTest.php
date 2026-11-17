<?php

declare(strict_types=1);

namespace SugarCraft\Style\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Style\Align;
use SugarCraft\Style\Border;
use SugarCraft\Style\Style;
use SugarCraft\Style\StyleBuilder;
use SugarCraft\Style\UnderlineStyle;
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
        $built = $builder->setFg('#ff8800')->build();
        self::assertTrue($built->isBold(), 'seed bold carries into the build');
        self::assertSame(10, $built->getWidth(), 'seed width carries into the build');
        self::assertEquals(Color::hex('#ff8800'), $built->getForeground());
    }

    public function testBuildNeverMutatesTheSeed(): void
    {
        $seed = Style::new();
        $built = $seed->builder()->setBold()->setWidth(4)->build();

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
            'color and attributes' => [
                static fn (Style $s): Style => $s->foreground(Color::hex('#ff8800'))->background(Color::hex('#101010'))
                    ->bold()->italic()->underline()->strikethrough()->faint()->blink()->reverse(),
                static fn (StyleBuilder $b): StyleBuilder => $b->setForeground(Color::hex('#ff8800'))->setBackground(Color::hex('#101010'))
                    ->setBold()->setItalic()->setUnderline()->setStrikethrough()->setFaint()->setBlink()->setReverse(),
            ],
            'hex-string color forms' => [
                static fn (Style $s): Style => $s->fg('#ff8800')->on('#000080'),
                static fn (StyleBuilder $b): StyleBuilder => $b->setFg('#ff8800')->setBg('#000080'),
            ],
            'rapid blink, overline, invisible' => [
                static fn (Style $s): Style => $s->rapidBlink()->overline()->invisible(),
                static fn (StyleBuilder $b): StyleBuilder => $b->setRapidBlink()->setOverline()->setInvisible(),
            ],
            'sizes' => [
                static fn (Style $s): Style => $s->width(12)->height(3)->maxWidth(10)->maxHeight(2),
                static fn (StyleBuilder $b): StyleBuilder => $b->setWidth(12)->setHeight(3)->setMaxWidth(10)->setMaxHeight(2),
            ],
            'padding and margin shorthand' => [
                static fn (Style $s): Style => $s->padding(1, 2)->margin(3),
                static fn (StyleBuilder $b): StyleBuilder => $b->setPadding(1, 2)->setMargin(3),
            ],
            'padding and margin glyphs and margin background' => [
                static fn (Style $s): Style => $s->paddingChar('.')->marginChar('#')->marginBackground(Color::hex('#202020')),
                static fn (StyleBuilder $b): StyleBuilder => $b->setPaddingChar('.')->setMarginChar('#')->setMarginBackground(Color::hex('#202020')),
            ],
            'alignment and profile' => [
                static fn (Style $s): Style => $s->align(Align::Right)->verticalAlign(VAlign::Bottom)
                    ->colorProfile(ColorProfile::Ansi256),
                static fn (StyleBuilder $b): StyleBuilder => $b->setAlign(Align::Right)->setVerticalAlign(VAlign::Bottom)
                    ->setColorProfile(ColorProfile::Ansi256),
            ],
            'inline, wrap and bound content' => [
                static fn (Style $s): Style => $s->inline()->wordWrap()->width(7)->setString('one two three'),
                static fn (StyleBuilder $b): StyleBuilder => $b->setInline()->setWordWrap()->setWidth(7)->setString('one two three'),
            ],
            'color whitespace and tab width' => [
                static fn (Style $s): Style => $s->colorWhitespace(false)->tabWidth(2),
                static fn (StyleBuilder $b): StyleBuilder => $b->setColorWhitespace(false)->setTabWidth(2),
            ],
            'underline shape switches underline on' => [
                static fn (Style $s): Style => $s->underlineStyle(UnderlineStyle::Curly)->underlineColor('#ff0000'),
                static fn (StyleBuilder $b): StyleBuilder => $b->setUnderlineStyle(UnderlineStyle::Curly)->setUnderlineColor('#ff0000'),
            ],
            'underline shape None leaves the flag alone' => [
                static fn (Style $s): Style => $s->underlineStyle(UnderlineStyle::None),
                static fn (StyleBuilder $b): StyleBuilder => $b->setUnderlineStyle(UnderlineStyle::None),
            ],
            'explicit underline(false) after a shape' => [
                static fn (Style $s): Style => $s->underlineStyle(UnderlineStyle::Double)->underline(false),
                static fn (StyleBuilder $b): StyleBuilder => $b->setUnderlineStyle(UnderlineStyle::Double)->setUnderline(false),
            ],
            'null clears colors and sizes' => [
                static fn (Style $s): Style => $s->foreground(Color::hex('#ff0000'))->foreground(null)
                    ->background(Color::hex('#00ff00'))->background(null)
                    ->width(9)->width(null)->maxHeight(4)->maxHeight(null),
                static fn (StyleBuilder $b): StyleBuilder => $b->setForeground(Color::hex('#ff0000'))->setForeground(null)
                    ->setBackground(Color::hex('#00ff00'))->setBackground(null)
                    ->setWidth(9)->setWidth(null)->setMaxHeight(4)->setMaxHeight(null),
            ],
            'null clears the string colors' => [
                static fn (Style $s): Style => $s->fg('#ff0000')->fg(null)->bg('#00ff00')->bg(null)->underlineColor(null),
                static fn (StyleBuilder $b): StyleBuilder => $b->setFg('#ff0000')->setFg(null)->setBg('#00ff00')->setBg(null)->setUnderlineColor(null),
            ],
            'last write wins across flags' => [
                static fn (Style $s): Style => $s->bold()->bold(false)->italic()->faint(false)->faint(),
                static fn (StyleBuilder $b): StyleBuilder => $b->setBold()->setBold(false)->setItalic()->setFaint(false)->setFaint(),
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
        self::assertSame(self::propsSet($viaSetters), self::propsSet($viaBuilder), 'the same props are recorded as set');
    }

    /**
     * @param \Closure(Style): Style $chain
     * @param \Closure(StyleBuilder): StyleBuilder $builderChain
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('equivalentChains')]
    public function testBuilderMatchesTheChainedSettersOnAStyledSeed(\Closure $chain, \Closure $builderChain): void
    {
        $seed = Style::new()->bold()->width(30)->padding(1)->border(Border::rounded())->hyperlink('https://example.test');
        $viaSetters = $chain($seed);
        $viaBuilder = $builderChain($seed->builder())->build();

        self::assertSame($viaSetters->render('hello world'), $viaBuilder->render('hello world'));
        self::assertSame(self::propsSet($viaSetters), self::propsSet($viaBuilder));
    }

    public function testSeedStructuralStateCarriesThroughBuild(): void
    {
        $seed = Style::new()
            ->border(Border::rounded())
            ->borderTopForeground(Color::hex('#ff0000'))
            ->borderLeftBackground(Color::hex('#0000ff'))
            ->hyperlink('https://example.test/docs', 'docs')
            ->transform(static fn (string $s): string => strtoupper($s))
            ->foreground(Color::hex('#112233'));

        $built = $seed->builder()->setBold()->setWidth(12)->build();
        $expected = $seed->bold()->width(12);

        self::assertSame($expected->render('structural'), $built->render('structural'));
        self::assertSame($seed->getBorder(), $built->getBorder());
        self::assertSame($seed->getBorderSides(), $built->getBorderSides());
        self::assertSame($seed->getHyperlink(), $built->getHyperlink());
        self::assertSame($seed->getBorderForeground(), $built->getBorderForeground());
        self::assertSame(self::propsSet($expected), self::propsSet($built));
    }

    public function testBuilderRecordsTheSamePropsAsTheSetters(): void
    {
        $viaSetters = Style::new()->bold()->width(9)->strikethrough()->padding(2);
        $viaBuilder = Style::new()->builder()->setBold()->setWidth(9)->setStrikethrough()->setPadding(2)->build();

        foreach (['bold', 'width', 'strike', 'padding'] as $prop) {
            self::assertSame($viaSetters->isSet($prop), $viaBuilder->isSet($prop), $prop);
        }
        self::assertTrue($viaBuilder->isSet('strike'), 'setStrikethrough() records the strike prop');
    }

    public function testSetterOrderLastWriteWins(): void
    {
        $built = Style::new()->builder()->setWidth(4)->setWidth(9)->setBold()->setBold(false)->build();

        self::assertSame(9, $built->getWidth());
        self::assertFalse($built->isBold());
    }

    public function testBuildLeavesTheDraftUsable(): void
    {
        $builder = Style::new()->builder()->setBold();
        $first = $builder->build();
        $second = $builder->setWidth(5)->build();

        self::assertTrue($first->isBold());
        self::assertNull($first->getWidth(), 'an earlier build is not changed by later setters');
        self::assertSame(5, $second->getWidth());
    }

    public function testBuildDoesNotCopyTheRenderMemos(): void
    {
        $seed = Style::new()->bold()->foreground(Color::hex('#ff0000'));
        $seed->render('warm the memo');
        $built = $seed->builder()->setWidth(6)->build();

        $memo = new \ReflectionProperty(Style::class, 'contentSgrMemo');
        self::assertNull($memo->getValue($built), 'the builder rebuilds the memo, it does not copy it');
        self::assertTrue($built->isBold());
    }

    public function testNonNegativeSizesAreValidatedLikeTheSetters(): void
    {
        foreach (['width', 'height', 'maxWidth', 'maxHeight'] as $method) {
            $viaSetter = self::catchMessage(static fn () => Style::new()->{$method}(-1));
            $viaBuilder = self::catchMessage(static fn () => Style::new()->builder()->{'set' . ucfirst($method)}(-1));
            self::assertNotNull($viaSetter, $method . ' setter rejects a negative size');
            self::assertSame($viaSetter, $viaBuilder, $method . ' builder rejects with the same message');
        }
    }

    public function testNegativeTabWidthIsValidatedLikeTheSetter(): void
    {
        $viaSetter = self::catchMessage(static fn () => Style::new()->tabWidth(-1));
        $viaBuilder = self::catchMessage(static fn () => Style::new()->builder()->setTabWidth(-1));

        self::assertNotNull($viaSetter);
        self::assertSame($viaSetter, $viaBuilder);
    }

    public function testPaddingArityIsValidatedLikeTheSetter(): void
    {
        $viaSetter = self::catchMessage(static fn () => Style::new()->padding(1, 2, 3));
        $viaBuilder = self::catchMessage(static fn () => Style::new()->builder()->setPadding(1, 2, 3));

        self::assertNotNull($viaSetter);
        self::assertSame($viaSetter, $viaBuilder);
    }

    public function testColorNamesAreRefusedLikeTheSetter(): void
    {
        self::assertNotNull(self::catchMessage(static fn () => Style::new()->fg('navy')));
        self::assertNotNull(self::catchMessage(static fn () => Style::new()->builder()->setFg('navy')));
    }

    /** @return array<string,mixed> the propsSet bookkeeping of a Style, keyed by prop */
    private static function propsSet(Style $style): array
    {
        $prop = new \ReflectionProperty(Style::class, 'propsSet');
        $set = $prop->getValue($style);
        ksort($set);
        return $set;
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
