<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Field;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\Field\Color;

/**
 * E736 5.8 (r88-x4): xterm-256 swatch picker — hex boundary coercion onto
 * the cube, bubbles axis mapping, table semantics pinned to the xterm
 * formula (the same one candy-vt documents — computed locally, no dep),
 * snapshot frames with the hollow cursor cell.
 */
final class ColorTest extends TestCase
{
    // ------------------------------------------------------------------
    // Construction + value coercion
    // ------------------------------------------------------------------

    public function testNewStartsBlackUnfocused(): void
    {
        $c = Color::new('fg');
        $this->assertSame('#000000', $c->value());
        $this->assertSame(0, $c->red);
        $this->assertSame(0, $c->green);
        $this->assertSame(0, $c->blue);
        $this->assertFalse($c->isFocused());
    }

    public function testNewWithInitialSnaps(): void
    {
        $c = Color::new('fg', '#ff00ff');
        $this->assertSame('#ff00ff', $c->value());
        $this->assertSame(5, $c->red);
        $this->assertSame(0, $c->green);
        $this->assertSame(5, $c->blue);
    }

    public function testCubeLevelsAreTheXtermTable(): void
    {
        // The exact six cube levels; index formula 16 + 36r + 6g + b.
        $c = Color::new('fg', '#875fdf'); // 135, 95, 223 → nearest: 135, 95, 215
        $this->assertSame('#875fd7', $c->hex(), '223 snaps down to level 215');
        $this->assertSame(2, $c->red);   // 135
        $this->assertSame(1, $c->green); // 95
        $this->assertSame(4, $c->blue);  // 215
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function hexCoercions(): array
    {
        return [
            'exact corner'      => ['#ff0000', '#ff0000'],
            'bare six digits'   => ['00ff00', '#00ff00'],
            'upper case'        => ['#FFFFFF', '#ffffff'],
            'mid grey snaps low on ties' => ['#737373', '#5f5f5f'], // 115 ties 95/135 → 95
            'charlie brown'     => ['#123456', '#005f5f'],
            'near black'        => ['#050505', '#000000'],
            'near white'        => ['#fafafa', '#ffffff'],
            'just past tie down' => ['#747474', '#878787'], // 116 → 135
        ];
    }

    #[DataProvider('hexCoercions')]
    public function testWithValueSnapsOntoTheCube(string $input, string $expected): void
    {
        $this->assertSame($expected, Color::new('fg')->withValue($input)->hex());
    }

    /** @return array<string, array{0: string}> */
    public static function malformedHexes(): array
    {
        return [
            'short'     => ['#fff'],
            'long'      => ['#ffffff0'],
            'non-hex'   => ['xyzxyz'],
            'empty'     => [''],
            'spaces'    => ['#ff 00ff'],
            'hash only' => ['#'],
            'named'     => ['red'],
        ];
    }

    #[DataProvider('malformedHexes')]
    public function testWithValueRejectsMalformed(string $bad): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Color::new('fg')->withValue($bad);
    }

    public function testRevalidateIsANoOpByDesign(): void
    {
        $c = Color::new('fg');
        $this->assertSame($c, $c->revalidate());
        $this->assertNull($c->getError());
    }

    // ------------------------------------------------------------------
    // Keys — bubbles axis mapping
    // ------------------------------------------------------------------

    public function testGreenColumnKeys(): void
    {
        [$c, ] = Color::new('fg')->focus();
        [$c, ] = $c->update(new KeyMsg(KeyType::Right));
        $this->assertSame('#005f00', $c->hex());
        [$c, ] = $c->update(new KeyMsg(KeyType::Char, 'h'));
        [$c, ] = $c->update(new KeyMsg(KeyType::Char, 'h'));
        $this->assertSame('#000000', $c->hex(), 'saturates at 0');
        for ($i = 0; $i < 10; $i++) {
            [$c, ] = $c->update(new KeyMsg(KeyType::Char, 'l'));
        }
        $this->assertSame('#00ff00', $c->hex(), 'saturates at 5');
    }

    public function testBlueRowKeys(): void
    {
        [$c, ] = Color::new('fg', '#000000')->focus();
        [$c, ] = $c->update(new KeyMsg(KeyType::Down));
        $this->assertSame('#00005f', $c->hex(), 'down walks to the next row (bigger blue)');
        [$c, ] = $c->update(new KeyMsg(KeyType::Char, 'k'));
        $this->assertSame('#000000', $c->hex());
        for ($i = 0; $i < 10; $i++) {
            [$c, ] = $c->update(new KeyMsg(KeyType::Char, 'j'));
        }
        $this->assertSame('#0000ff', $c->hex());
    }

    public function testRedPlaneKeys(): void
    {
        [$c, ] = Color::new('fg')->focus();
        [$c, ] = $c->update(new KeyMsg(KeyType::PageUp));
        $this->assertSame('#5f0000', $c->hex());
        [$c, ] = $c->update(new KeyMsg(KeyType::PageDown));
        [$c, ] = $c->update(new KeyMsg(KeyType::PageDown));
        $this->assertSame('#000000', $c->hex(), 'saturates, never wraps negative');
        for ($i = 0; $i < 10; $i++) {
            [$c, ] = $c->update(new KeyMsg(KeyType::PageUp));
        }
        $this->assertSame('#ff0000', $c->hex());
    }

    public function testHomeEndMoveColumns(): void
    {
        [$c, ] = Color::new('fg', '#00af00')->focus(); // g=2
        [$h, ] = $c->update(new KeyMsg(KeyType::Home));
        $this->assertSame(0, $h->green);
        [$e, ] = $c->update(new KeyMsg(KeyType::End));
        $this->assertSame(5, $e->green);
    }

    public function testIgnoresKeysWhenUnfocused(): void
    {
        $c = Color::new('fg');
        [$d, $cmd] = $c->update(new KeyMsg(KeyType::Right));
        $this->assertSame($c, $d);
        $this->assertNull($cmd);
    }

    public function testConsumesOnlyWhatTheDefaultKeyMapBinds(): void
    {
        [$c, ] = Color::new('fg')->focus();
        $this->assertTrue($c->consumes(new KeyMsg(KeyType::Up)));
        $this->assertTrue($c->consumes(new KeyMsg(KeyType::Down)));
        $this->assertFalse($c->consumes(new KeyMsg(KeyType::Left)));
        $this->assertFalse($c->consumes(new KeyMsg(KeyType::PageUp)));
        $this->assertFalse($c->consumes(new KeyMsg(KeyType::Tab)));
        $this->assertFalse(Color::new('fg')->consumes(new KeyMsg(KeyType::Down)));
    }

    // ------------------------------------------------------------------
    // Render snapshots
    // ------------------------------------------------------------------

    /** A single swatch cell, non-cursor, 256-color. */
    private static function cell(int $index): string
    {
        return "\x1b[48;5;{$index}m  \x1b[0m";
    }

    public function testSnapshotFrameBlackPlaneWithHollowCursor(): void
    {
        $c = Color::new('fg'); // r=0, cursor (g0,b0)
        $rows = [];
        for ($b = 0; $b <= 5; $b++) {
            $cells = [];
            for ($g = 0; $g <= 5; $g++) {
                $index = 16 + 6 * $g + $b;
                $cells[] = $b === 0 && $g === 0
                    ? "\x1b[7;48;5;16m  \x1b[0m"
                    : self::cell($index);
            }
            $rows[] = implode('', $cells);
        }
        $this->assertSame(
            implode("\n", $rows) . "\n#000000  R0 G0 B0",
            $c->view(),
        );
    }

    public function testCursorCellTracksTheAxesAndPunchesHole(): void
    {
        [$c, ] = Color::new('fg')->focus();
        [$c, ] = $c->update(new KeyMsg(KeyType::Right)); // g=1
        [$c, ] = $c->update(new KeyMsg(KeyType::Down));  // b=1
        $hollow = "\x1b[7;48;5;23m  \x1b[0m"; // 16 + 6 + 1 = 23
        $this->assertStringContainsString($hollow, $c->view());
        // The red plane colors the whole grid: 16 + 36*r base. With r=2
        // (PgUp twice) the cursor sits on the plane-base index 88 itself,
        // so it appears HOLLOW; its green neighbours 94…118 stay painted.
        [$c2, ] = Color::new('fg')->focus();
        [$c2, ] = $c2->update(new KeyMsg(KeyType::PageUp));
        [$c2, ] = $c2->update(new KeyMsg(KeyType::PageUp));
        $this->assertStringContainsString("\x1b[7;48;5;88m  \x1b[0m", $c2->view(), 'cursor = 16+72+0+0');
        $this->assertStringContainsString("\x1b[48;5;94m  \x1b[0m", $c2->view(), 'next column painted');
        $this->assertStringContainsString("\x1b[48;5;118m  \x1b[0m", $c2->view(), 'last column painted');
    }

    public function testTruecolorPaintsRgbBackgrounds(): void
    {
        $c = Color::new('fg', '#ff87d7')->withTruecolor(); // 255,135,215
        $this->assertSame('#ff87d7', $c->hex());
        $view = $c->view();
        $this->assertStringNotContainsString('48;5;', $view, '256-color codes must not leak into truecolor mode');
        $this->assertStringContainsString("\x1b[7;48;2;255;135;215m  \x1b[0m", $view, 'cursor cell truecolor+hollow');
        $this->assertStringContainsString("\x1b[48;2;255;0;0m  \x1b[0m", $view, 'painted (non-cursor) cell of the same plane');
    }

    public function testTitleAndDescriptionRenderAboveThePlane(): void
    {
        $c = Color::new('fg')->withTitle('Tint')->withDescription('pick it');
        $rows = explode("\n", $c->view());
        $this->assertSame('Tint', $rows[0]);
        $this->assertSame('pick it', $rows[1]);
        $this->assertSame('#000000  R0 G0 B0', $rows[8]);
    }

    // ------------------------------------------------------------------
    // Contract surface + aliases + trait carry
    // ------------------------------------------------------------------

    public function testInterfaceAccessors(): void
    {
        $c = Color::new('fg', '#ff0000')->withTitle('T')->withDescription('D');
        $this->assertSame('fg', $c->key());
        $this->assertSame('#ff0000', $c->value());
        $this->assertSame('T', $c->getTitle());
        $this->assertSame('D', $c->getDescription());
        $this->assertFalse($c->isFocused());
        $this->assertFalse($c->skippable());
        $this->assertNull($c->getError());
        $this->assertFalse($c->isHidden([]));
    }

    public function testShortAliasesMatchLongForms(): void
    {
        $long  = Color::new('fg')->withTitle('T')->withDescription('D')->withTruecolor()->view();
        $short = Color::new('fg')->title('T')->desc('D')->truecolor()->view();
        $this->assertSame($long, $short);
    }

    public function testFieldsAreImmutable(): void
    {
        $c = Color::new('fg');
        [$d, ] = $c->focus();
        $this->assertNotSame($c, $d);
        $this->assertTrue($d->isFocused());
    }

    public function testTraitClosuresSurviveEveryRebuildPath(): void
    {
        [$c, ] = Color::new('fg')
            ->withTitleFunc(static fn (): string => 'DYN')
            ->withDescriptionFunc(static fn (): string => 'DSCF')
            ->withHideFunc(static fn (array $v): bool => true)
            ->focus();
        [$c, ] = $c->update(new KeyMsg(KeyType::Right));
        [$c, ] = $c->update(new KeyMsg(KeyType::PageUp));
        $this->assertSame(1, $c->red);
        $this->assertSame(1, $c->green);
        $this->assertSame('DYN', $c->getTitle(), 'titleFunc dropped on a key-rebuild');
        $this->assertSame('DSCF', $c->getDescription());
        $this->assertTrue($c->isHidden([]));
        $this->assertSame('DYN', $c->withValue('#ffffff')->getTitle(), 'knob-rebuild too');
    }
}
