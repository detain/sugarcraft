<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui\Window;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Theme;
use SugarCraft\Code\Tui\Window\WindowAttention;
use SugarCraft\Code\Tui\Window\WindowEntry;
use SugarCraft\Code\Tui\Window\WindowRef;

/**
 * Attention-state laws of the window kit (P-TABS.a): rank is derived,
 * glyphs are the NO_COLOR channel and must never collide.
 */
final class WindowAttentionTest extends TestCase
{
    public function testRankIsStrictlyIncreasingInDeclarationOrder(): void
    {
        $ranks = [];
        foreach (WindowAttention::cases() as $state) {
            $ranks[] = $state->rank();
        }

        $sorted = $ranks;
        sort($sorted);
        self::assertSame($sorted, $ranks, 'rank() must rise with the cases() order (Idle first)');
        self::assertSame($ranks, array_unique($ranks), 'two states sharing a rank would make the picker tie-break silently');
        self::assertSame(0, WindowAttention::Idle->rank());
        self::assertSame(count($ranks) - 1, WindowAttention::Failed->rank());
    }

    public function testEveryStateCarriesADistinctGlyph(): void
    {
        $glyphs = [];
        foreach (WindowAttention::cases() as $state) {
            $glyphs[] = $state->glyph();
        }

        self::assertSame(count($glyphs), count(array_unique($glyphs)), 'glyphs are the NO_COLOR channel; a collision makes two states indistinguishable');
    }

    public function testNeedsAttentionIsTheTopOfTheRank(): void
    {
        $worstIdle = WindowAttention::Idle->needsAttention();
        $worstWorking = WindowAttention::Working->needsAttention();
        self::assertFalse($worstIdle);
        self::assertFalse($worstWorking);
        foreach ([WindowAttention::Waiting, WindowAttention::Unseen, WindowAttention::Failed] as $state) {
            self::assertTrue($state->needsAttention(), $state->name . ' must announce itself');
        }

        // The law that makes the two assertions above worth their pins:
        // needsAttention() must never be true at or below Working's rank.
        foreach (WindowAttention::cases() as $state) {
            if ($state->rank() <= WindowAttention::Working->rank()) {
                self::assertFalse($state->needsAttention());
            }
        }
    }

    public function testColorRidesTheThemeAndNeverTheGlyph(): void
    {
        $theme = Theme::default();
        self::assertSame($theme->shellError->toHex(), WindowAttention::Failed->color($theme)->toHex());
        self::assertSame($theme->shellWarning->toHex(), WindowAttention::Waiting->color($theme)->toHex());
        self::assertSame($theme->shellInfo->toHex(), WindowAttention::Unseen->color($theme)->toHex());
        self::assertSame($theme->shellPrimary->toHex(), WindowAttention::Working->color($theme)->toHex());
        self::assertSame($theme->shellMuted->toHex(), WindowAttention::Idle->color($theme)->toHex());
    }

    public function testBadgeEntriesCarryTheirAttentionIndependentlyOfPeers(): void
    {
        // A parent's badge count and a child's state must be settable
        // without disturbing either neighbour — WindowEntry::withAttention
        // and withBadge ride the immutability rails.
        $child = WindowEntry::agent(WindowRef::agent('run-1', 'explore', 's-1'));
        $parent = WindowEntry::session(WindowRef::session('s-1', 'work'), WindowAttention::Idle, 0, [$child]);

        $upgraded = $parent->withChild($child->withAttention(WindowAttention::Waiting));
        self::assertSame(WindowAttention::Waiting, $upgraded->child('run-1')?->attention);
        self::assertSame(WindowAttention::Idle, $parent->child('run-1')?->attention, 'the original must not move');
        self::assertSame(0, $parent->badge);
        self::assertSame(3, $parent->withBadge(3)->badge);
        self::assertSame(0, $parent->withBadge(-5)->badge, 'badge clamps at zero, never negative');
    }
}
