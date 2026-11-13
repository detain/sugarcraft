<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Field;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Forms\Field\RadioPills;

/**
 * SF-28 (sugar-forms-032): the click band and the window budget must cost
 * pills in TERMINAL CELLS — the same money renderLines spends via
 * Width::truncateAnsi — not codepoints. A wide-label set drifts every pill
 * after the first under codepoint math: the eye sees the third pill under
 * the cursor, the click answers with a dead zone.
 */
final class RadioPillsMouseTest extends TestCase
{
    private function focusPills(RadioPills $p): RadioPills
    {
        [$focused, $cmd] = $p->focus();
        self::assertNull($cmd);
        /** @var RadioPills $focused */
        return $focused;
    }

    private function tap(RadioPills $p, int $x): ?string
    {
        /** @var RadioPills $next */
        [$next, ] = $p->update(new MouseMsg($x, 1, MouseButton::Left, MouseAction::Press));
        return $next->cursor() === $p->cursor() ? null : $next->value();
    }

    /** @param list<string> $options */
    private function plainPillRow(array $options): string
    {
        $p = $this->focusPills(RadioPills::new('mode', ...$options));
        foreach (explode("\n", $p->view()) as $line) {
            $plain = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $line);
            if (str_contains($plain, $options[0])) {
                return $plain;
            }
        }
        self::fail('the pill row must render');
    }

    public function testWideLabelClickBandsMatchPaintedCells(): void
    {
        // 中文 (4 cells) / 日本語 (6) / 한국어 (6) pill to 6/8/8 cells, so
        // the painted bands are 1-6, gap 7-9, 10-17, gap 18-20, 21-28.
        // Under the retired codepoint math (4+2/3+2/3+2) the row ended at
        // col 22 with the last pill at 18-22: taps at 23+ answered "no
        // pill" while the eye saw 한국어 painted there, and the 18-20 dead
        // zone answered with the third option.
        $p = $this->focusPills(RadioPills::new('mode', '中文', '日本語', '한국어'));
        self::assertSame('日本語', $this->tap($p, 12), 'mid band of the painted second pill');
        self::assertSame('日本語', $this->tap($p, 17), 'right pad cell belongs to the pill');
        self::assertNull($this->tap($p, 19), 'the gap stays a dead zone at cell truth');
        self::assertSame('한국어', $this->tap($p, 21), 'leftmost cell of the painted third pill');
        self::assertSame('한국어', $this->tap($p, 25), 'a tap deep in the painted third pill selects it (codepoint math: past the row)');
        self::assertSame('한국어', $this->tap($p, 28), 'right pad of the last pill');
        self::assertNull($this->tap($p, 29), 'past the painted row is inert');
    }

    public function testWindowBudgetRespectsCells(): void
    {
        // width 20: the codepoint math believed 3 CJK pills cost 12+6 gaps
        // = 18 and shipped the whole roster unwindowed — 24 painted cells
        // overflowing a 20-cell budget the renderer then clipped mid-row.
        $p = $this->focusPills(
            RadioPills::new('mode', '中文', '日本語', '한국어')->withWidth(20)
        );
        foreach (explode("\n", $p->view()) as $line) {
            $plain = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $line);
            self::assertLessThanOrEqual(20, Width::of($plain), 'no row exceeds the declared budget: ' . $plain);
        }
        // The selection must stay visible inside the window it got.
        $first = (string) preg_replace('/\x1b\[[0-9;]*m/', '', explode("\n", $p->view())[0]);
        self::assertStringContainsString('中文', $first, 'the selected pill is never windowed out');
    }

    public function testEmojiClickZonesLandOnTheirPaintedPills(): void
    {
        $options = ['🎸 rock', '🎹 pop', '🥁 drums'];
        $row = $this->plainPillRow($options);
        $segments = preg_split('/   /', trim($row, ' ')) ?: [];
        self::assertCount(3, $segments, 'three pills separated by the three-cell gap');

        // Walk the painted row: every segment center must answer with its
        // own option. Column math derives from the RENDER, not the code —
        // render and click band are the two sides this law pins together.
        $p = $this->focusPills(RadioPills::new('mode', ...$options));
        $column = 1;
        foreach ($segments as $i => $segment) {
            $cost = Width::of($segment);
            $center = $column + intdiv($cost, 2);
            /** @var RadioPills $next */
            [$next, ] = $p->update(new MouseMsg($center, 1, MouseButton::Left, MouseAction::Press));
            self::assertSame($options[$i], $next->value(), "center tap of painted pill {$i} selects it");
            $column += $cost + 3;
        }
    }

    public function testArrowWalkStillCommits(): void
    {
        // Guard-rail for the same file: keyboard semantics untouched.
        $p = $this->focusPills(RadioPills::new('mode', '中文', '日本語', '한국어'));
        [$next, $cmd] = $p->update(new KeyMsg(KeyType::Right));
        self::assertNull($cmd);
        /** @var RadioPills $next */
        self::assertSame('日本語', $next->value());
    }
}
