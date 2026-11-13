<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests\Style;

use PHPUnit\Framework\TestCase;
use SugarCraft\Markdown\Render\BlockKind;
use SugarCraft\Markdown\Style\StyleCascade;
use SugarCraft\Markdown\Style\StyleSheet;
use SugarCraft\Style\Style;

final class StyleSheetTest extends TestCase
{
    public function testBaseReturnsDeterministicDefaults(): void
    {
        $sheet = StyleSheet::base();

        // BlockQuote at depth 0 gets neutral style (theme controls styling).
        // The StyleSheet provides depth-based overrides, not theme replacements.
        $bq = $sheet->for(BlockKind::BlockQuote, 0);
        // No italic by default - that comes from the theme.
        $this->assertFalse($bq->isItalic());

        // Paragraph at depth 0 also gets neutral style.
        $para = $sheet->for(BlockKind::Paragraph, 0);
        $this->assertFalse($para->isBold());
        $this->assertFalse($para->isItalic());
    }

    public function testForReturnsNearestAncestorStyle(): void
    {
        $sheet = StyleSheet::base()
            ->withBlockKindAtDepth(BlockKind::Paragraph, Style::new()->bold(), 0)
            ->withBlockKindAtDepth(BlockKind::Paragraph, Style::new()->underline(), 2);

        // Depth 2 returns the explicitly set underline style.
        $s2 = $sheet->for(BlockKind::Paragraph, 2);
        $this->assertTrue($s2->isUnderline());
        $this->assertFalse($s2->isBold());

        // Depth 1 falls back to depth 0 (bold).
        $s1 = $sheet->for(BlockKind::Paragraph, 1);
        $this->assertTrue($s1->isBold());
        $this->assertFalse($s1->isUnderline());

        // Depth 0 is the explicitly set bold.
        $s0 = $sheet->for(BlockKind::Paragraph, 0);
        $this->assertTrue($s0->isBold());
    }

    public function testWithBlockKindSetsStyleAtDepthZero(): void
    {
        $sheet = StyleSheet::base()
            ->withBlockKind(BlockKind::BlockQuote, Style::new()->bold());

        $style = $sheet->for(BlockKind::BlockQuote, 0);
        $this->assertTrue($style->isBold());
        // Base style also has italic for blockquote, but withBlockKind
        // should override the depth-0 entry (not inherit from base).
        $this->assertFalse($style->isItalic());
    }

    public function testWithBlockKindAtDepthSetsStyleAtSpecificDepth(): void
    {
        $sheet = StyleSheet::base()
            ->withBlockKindAtDepth(BlockKind::List, Style::new()->bold(), 3);

        // Depth 0 gets base style (not bold for List by default).
        $s0 = $sheet->for(BlockKind::List, 0);
        $this->assertFalse($s0->isBold());

        // Depth 3 gets the explicitly set bold.
        $s3 = $sheet->for(BlockKind::List, 3);
        $this->assertTrue($s3->isBold());
    }

    public function testForUnknownKindReturnsEmptyStyle(): void
    {
        $sheet = StyleSheet::base();
        // SM-S55 (MDTEST-08): assert the unasserted byte no-op, not just the
        // type — a regression that gave unknown kinds a visible style flips
        // red here, while the old instanceof leg passed on any Style.
        $style = $sheet->for(BlockKind::Table, 0);
        $this->assertInstanceOf(Style::class, $style);
        $this->assertSame('x', $style->render('x'), 'unknown kinds must render their input byte-identically');
    }

    public function testForDepthAboveAllRegisteredEntriesReturnsEmptyStyle(): void
    {
        // SM-S55 (MDTEST-08): drive the two no-hit legs of for() directly —
        // public construction cannot reach them because base() seeds every
        // BlockKind case at depth 0. Sheet one: kind absent entirely (early
        // return). Sheet two: kind present, every entry deeper than the
        // requested depth (walk-up miss). Both legs must yield the exact
        // byte no-op, which the old instanceof check never asserted.
        $inject = static function (array $styles): StyleSheet {
            $ref  = new \ReflectionClass(StyleSheet::class);
            $sheet = $ref->newInstanceWithoutConstructor();
            $ref->getProperty('styles')->setValue($sheet, $styles);
            return $sheet;
        };

        $bold = Style::new()->bold();

        $missingKind = $inject([])->for(BlockKind::Heading, 0);
        $this->assertFalse($missingKind->isBold());
        $this->assertSame('x', $missingKind->render('x'), 'absent kinds must fall to the empty style byte-exactly');

        $deepOnly = $inject(['Heading' => [3 => $bold]])->for(BlockKind::Heading, 1);
        $this->assertFalse($deepOnly->isBold(), 'a walk-up miss must NOT inherit the deeper entry');
        $this->assertSame('x', $deepOnly->render('x'), 'a walk-up miss must fall to the empty style byte-exactly');

        // Control leg: the same sheet answers at-or-below the entry, so the
        // two asserts above cannot be flattered by a stubbed-to-empty for().
        $this->assertTrue($inject(['Heading' => [3 => $bold]])->for(BlockKind::Heading, 3)->isBold());
    }

    public function testStylesForReturnsAllDepths(): void
    {
        $sheet = StyleSheet::base()
            ->withBlockKindAtDepth(BlockKind::Heading, Style::new()->bold(), 0)
            ->withBlockKindAtDepth(BlockKind::Heading, Style::new()->italic(), 2)
            ->withBlockKindAtDepth(BlockKind::Heading, Style::new()->underline(), 4);

        $styles = $sheet->stylesFor(BlockKind::Heading);
        $this->assertCount(3, $styles);
        $this->assertTrue($styles[0]->isBold());
        $this->assertTrue($styles[2]->isItalic());
        $this->assertTrue($styles[4]->isUnderline());
    }

    public function testMutateCreatesNewInstance(): void
    {
        $sheet = StyleSheet::base();
        $newSheet = $sheet->withBlockKind(BlockKind::Paragraph, Style::new()->bold());

        $this->assertNotSame($sheet, $newSheet);
        // Original unchanged.
        $this->assertFalse($sheet->for(BlockKind::Paragraph, 0)->isBold());
        // New sheet has the change.
        $this->assertTrue($newSheet->for(BlockKind::Paragraph, 0)->isBold());
    }

    public function testWithBlockKindAtDepthNegativeClampsToZero(): void
    {
        $sheet = StyleSheet::base()
            ->withBlockKindAtDepth(BlockKind::Paragraph, Style::new()->bold(), -5);
        // Negative depth should clamp to 0.
        $this->assertTrue($sheet->for(BlockKind::Paragraph, 0)->isBold());
    }

    public function testForWithDepthBeyondSetStyles(): void
    {
        // Set a style at depth 2, then ask for depth 5 - should return nearest (depth 2).
        $sheet = StyleSheet::base()
            ->withBlockKindAtDepth(BlockKind::Heading, Style::new()->bold(), 2);
        $style = $sheet->for(BlockKind::Heading, 5);
        // Should find the style at depth 2 as nearest ancestor.
        $this->assertTrue($style->isBold());
    }
}
