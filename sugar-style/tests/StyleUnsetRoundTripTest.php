<?php

declare(strict_types=1);

namespace SugarCraft\Style\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Align;
use SugarCraft\Style\Border;
use SugarCraft\Style\Style;
use SugarCraft\Style\VAlign;
use PHPUnit\Framework\TestCase;

/**
 * Findings 008 + 042: the unset* family's per-method sentinel/SGR-reset
 * halves. The shared reset idiom was pinned via a handful of members; the
 * rest drifted unexercised (the CALIBER patch-sentinel bug
 * class lives exactly here). This provider walks every `unset*` method:
 * set → unset → the accessor is back on its field default AND the rendered
 * bytes are back on the plain-Style baseline, byte-for-byte. Colour slots
 * additionally sweep every capability tier with a memo-warming render in
 * between, so a stale SGR cache cannot hide an un-cleared sentinel.
 */
final class StyleUnsetRoundTripTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: \Closure(Style): Style, 2: ?string, 3: mixed, 4?: \Closure(Style): Style}>
     */
    public static function unsetProvider(): array
    {
        $bordered = static fn (Style $s): Style => $s->border(Border::normal());

        return [
            'foreground'       => ['unsetForeground',       static fn (Style $s) => $s->foreground(Color::hex('#ff0000')),    'getForeground',       null],
            'background'       => ['unsetBackground',       static fn (Style $s) => $s->background(Color::hex('#0000ff')),    'getBackground',       null],
            'bold'             => ['unsetBold',             static fn (Style $s) => $s->bold(),                              'isBold',              false],
            'italic'           => ['unsetItalic',           static fn (Style $s) => $s->italic(),                            'isItalic',            false],
            'underline'        => ['unsetUnderline',        static fn (Style $s) => $s->underline(),                         'isUnderline',         false],
            'strikethrough'    => ['unsetStrikethrough',    static fn (Style $s) => $s->strikethrough(),                     'isStrikethrough',     false],
            'faint'            => ['unsetFaint',            static fn (Style $s) => $s->faint(),                             'isFaint',             false],
            // The alias leg: unsetDim delegates to unsetFaint on the dim() setter.
            'dim'              => ['unsetDim',              static fn (Style $s) => $s->dim(),                               'isFaint',             false],
            'blink'            => ['unsetBlink',            static fn (Style $s) => $s->blink(),                             'isBlink',             false],
            'reverse'          => ['unsetReverse',          static fn (Style $s) => $s->reverse(),                           'isReverse',           false],
            'overline'         => ['unsetOverline',         static fn (Style $s) => $s->overline(),                          'isOverline',          false],
            'invisible'        => ['unsetInvisible',        static fn (Style $s) => $s->invisible(),                         'isInvisible',         false],
            'width'            => ['unsetWidth',            static fn (Style $s) => $s->width(12),                           'getWidth',            null],
            'height'           => ['unsetHeight',           static fn (Style $s) => $s->height(6),                           'getHeight',           null],
            'maxWidth'         => ['unsetMaxWidth',         static fn (Style $s) => $s->maxWidth(4),                         'getMaxWidth',         null],
            'maxHeight'        => ['unsetMaxHeight',        static fn (Style $s) => $s->maxHeight(3),                        'getMaxHeight',        null],
            'border'           => ['unsetBorder',           $bordered,                                                       'getBorder',           null],
            'transform'        => ['unsetTransform',        static fn (Style $s) => $s->transform(static fn (string $t): string => strtoupper($t)), null, null],
            'hyperlink'        => ['unsetHyperlink',        static fn (Style $s) => $s->hyperlink('https://example.com'),    'getHyperlink',        null],
            'padding'          => ['unsetPadding',          static fn (Style $s) => $s->padding(1, 2, 3, 4),                 'getPadding',          [0, 0, 0, 0]],
            'margin'           => ['unsetMargin',           static fn (Style $s) => $s->margin(2),                           'getMargin',           [0, 0, 0, 0]],
            'tabWidth'         => ['unsetTabWidth',         static fn (Style $s) => $s->tabWidth(2),                         'getTabWidth',         Width::TAB_WIDTH],
            'align'            => ['unsetAlign',            static fn (Style $s) => $s->align(Align::Center),                'getAlign',            Align::Left],
            'verticalAlign'    => ['unsetVerticalAlign',    static fn (Style $s) => $s->verticalAlign(VAlign::Middle),       'getVerticalAlign',    VAlign::Top],
            'inline'           => ['unsetInline',           static fn (Style $s) => $s->inline(true),                        'isInline',            false],
            'marginBackground' => ['unsetMarginBackground', static fn (Style $s) => $s->marginBackground(Color::hex('#123456')), 'getMarginBackground', null],
            'colorWhitespace'  => ['unsetColorWhitespace',  static fn (Style $s) => $s->colorWhitespace(false),              null,                  null],
            // The side-flag legs turn ONE mask bit off (lipgloss
            // UnsetBorderTop) — they never drop the border object itself
            // (that is unsetBorder's job) and they are equal to the
            // flag-setter that writes false.
            'borderTop'        => ['unsetBorderTop',        $bordered,                                                        'getBorderSides',      [false, true, true, true], static fn (Style $s) => $bordered($s)->borderTop(false)],
            'borderRight'      => ['unsetBorderRight',      $bordered,                                                        'getBorderSides',      [true, false, true, true], static fn (Style $s) => $bordered($s)->borderRight(false)],
            'borderBottom'     => ['unsetBorderBottom',     $bordered,                                                        'getBorderSides',      [true, true, false, true], static fn (Style $s) => $bordered($s)->borderBottom(false)],
            'borderLeft'       => ['unsetBorderLeft',       $bordered,                                                        'getBorderSides',      [true, true, true, false], static fn (Style $s) => $bordered($s)->borderLeft(false)],
        ];
    }

    /**
     * @param \Closure(Style): Style $set
     * @param null|\Closure(Style): Style $comparator alternate baseline when the leg only
     *        resets one mask bit (border sides keep the border object)
     */
    #[DataProvider('unsetProvider')]
    public function testSetThenUnsetRestoresDefaultAndBaselineBytes(
        string $unsetMethod,
        \Closure $set,
        ?string $accessor,
        mixed $default,
        ?\Closure $comparator = null,
    ): void {
        $baseline = ($comparator ?? static fn (Style $s): Style => $s)(Style::new());
        $after    = $set(Style::new())->$unsetMethod();

        $this->assertSame(
            $baseline->render('hi'),
            $after->render('hi'),
            "{$unsetMethod}() must render the leg baseline byte-for-byte",
        );

        if ($accessor !== null) {
            $this->assertSame(
                $default,
                $after->$accessor(),
                "{$unsetMethod}() must restore the {$accessor}() default",
            );
        }
    }

    /**
     * Roster tripwire: every public unset* method on Style appears as a
     * provider leg — a 32nd member without a leg goes red here.
     */
    public function testProviderCoversEveryPublicUnsetMethod(): void
    {
        $live = [];
        foreach ((new \ReflectionClass(Style::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            if (!$m->isStatic() && str_starts_with($m->getName(), 'unset')) {
                $live[] = $m->getName();
            }
        }

        $legs = array_column(self::unsetProvider(), 0);

        $this->assertCount(31, $live, 'unset* roster moved off the 31 members this provider was cut for');
        $this->assertSame([], array_diff($live, $legs), 'provider missing legs for: ' . implode(', ', array_diff($live, $legs)));
        $this->assertSame([], array_diff($legs, $live), 'provider leg not (or no longer) a public unset* method');
    }

    /**
     * The colour-reset halves degraded per capability tier, with a
     * memo-warming render in between: set → render (caches tier bytes) →
     * unset → render must be plain again on every profile.
     */
    public function testColourUnsetsDegradeCleanlyOnEveryTier(): void
    {
        $slots = [
            'foreground'       => [static fn (Style $s): Style => $s->foreground(Color::hex('#ff0000')),       'unsetForeground'],
            'background'       => [static fn (Style $s): Style => $s->background(Color::hex('#0000ff')),       'unsetBackground'],
            'marginBackground' => [static fn (Style $s): Style => $s->marginBackground(Color::hex('#123456')), 'unsetMarginBackground'],
        ];

        foreach ($slots as $slot => [$set, $unset]) {
            foreach (ColorProfile::cases() as $profile) {
                $baseline = Style::new()->colorProfile($profile)->render('hi');
                $styled   = $set(Style::new()->colorProfile($profile));
                $styled->render('hi'); // warm any SGR memo on the set state
                $back     = $styled->$unset();
                $this->assertSame(
                    $baseline,
                    $back->render('hi'),
                    "{$slot}/{$unset}() must land back on baseline under {$profile->name}",
                );
            }
        }
    }

    /**
     * Finding 008's alias-parity half: the dim() setter rides the faint
     * sentinel and unsetDim() is unsetFaint() — both directions must clear
     * through both doors.
     */
    public function testUnsetDimIsAliasParityWithUnsetFaint(): void
    {
        $viaDim   = Style::new()->dim()->unsetDim();
        $viaFaint = Style::new()->faint()->unsetFaint();

        $this->assertFalse($viaDim->isFaint());
        $this->assertSame($viaFaint->render('hi'), $viaDim->render('hi'));
        // Crossed legs keep working too: dim() set, unsetFaint() clears it.
        $this->assertFalse(Style::new()->dim()->unsetFaint()->isFaint());
        $this->assertFalse(Style::new()->faint()->unsetDim()->isFaint());
        // And the set half of the alias still paints before the clear:
        $this->assertTrue(Style::new()->dim()->isFaint());
    }

    /**
     * Finding 042's accessor round-trip half: the unpinned single-side box
     * legs — set → read the side vector → unsetPadding()/unsetMargin() →
     * default vector — each byte-checked through render() as well.
     */
    public function testSingleSideBoxAccessorsRoundTrip(): void
    {
        $legs = [
            ['paddingTop',    4, 'getPadding', [4, 0, 0, 0]],
            ['paddingRight',  3, 'getPadding', [0, 3, 0, 0]],
            ['paddingBottom', 2, 'getPadding', [0, 0, 2, 0]],
            ['paddingLeft',   5, 'getPadding', [0, 0, 0, 5]],
            ['marginTop',     1, 'getMargin',  [1, 0, 0, 0]],
            ['marginRight',   1, 'getMargin',  [0, 1, 0, 0]],
            ['marginBottom',  2, 'getMargin',  [0, 0, 2, 0]],
            ['marginLeft',    3, 'getMargin',  [0, 0, 0, 3]],
        ];

        foreach ($legs as [$sideSetter, $value, $accessor, $expected]) {
            $set = Style::new()->$sideSetter($value);
            $this->assertSame($expected, $set->$accessor(), "{$sideSetter}() must write exactly one side");

            $cleared = $accessor === 'getPadding' ? $set->unsetPadding() : $set->unsetMargin();
            $this->assertSame([0, 0, 0, 0], $cleared->$accessor(), "unset of a {$sideSetter}() write must zero the whole vector");
            $this->assertSame('hi', $cleared->render('hi'), "{$sideSetter}() -> unset must restore baseline bytes");
        }
    }
}
