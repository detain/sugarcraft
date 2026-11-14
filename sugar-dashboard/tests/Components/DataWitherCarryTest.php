<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Dashboard\Components\Form\CommandPalette;
use SugarCraft\Dashboard\Components\Form\Editor;
use SugarCraft\Dashboard\Components\Form\Input;
use SugarCraft\Dashboard\Components\Form\Slider;
use SugarCraft\Dashboard\Components\Form\Textarea;
use SugarCraft\Dashboard\Components\Nav\Breadcrumb;
use SugarCraft\Dashboard\Components\Nav\Navbar;
use SugarCraft\Dashboard\Components\Nav\Scrollbar;
use SugarCraft\Dashboard\Components\Nav\Stepper;
use SugarCraft\Dashboard\Components\Tree\Tree;
use SugarCraft\Dashboard\Components\Tree\TreeNode;
use SugarCraft\Dashboard\Components\Tree\TreeViz;

/**
 * D-17 (finding sugar-dashboard-016, data leg): the Form/Nav/Tree wither
 * family rebuilt via `new self(...)`, dropping the allocation held in
 * setSize-owned fields outside the constructor — one wither after sizing
 * silently returned the component to its natural box. Every converted
 * wither now re-applies the dims (the Bar.php in-lib exemplar).
 *
 * The pin is the carry identity: size-then-wither must equal
 * wither-then-size byte for byte. Before the fix the wither erased the
 * box, so the two orderings diverged on every dim-aware class.
 * CommandPalette/Tree/TreeViz read their dims zero times today (the
 * honoring leg belongs to the 146 store family / D-145 seat), so the
 * identity there keeps the carry honest for when they do.
 */
final class DataWitherCarryTest extends TestCase
{
    /**
     * @param array<string,callable> $withers
     */
    private function assertCarryIdentity(object $base, array $withers, int $w, int $h, string $label): void
    {
        $sized = $base->setSize($w, $h);
        foreach ($withers as $name => $with) {
            $viaSize = $with($sized);
            $viaWither = $with($base)->setSize($w, $h);
            $this->assertSame(
                $viaSize->render(),
                $viaWither->render(),
                "$label.$name: wither after setSize dropped the allocation"
            );
            $this->assertSame($viaSize->innerSize(), $viaWither->innerSize(), "$label.$name innerSize diverged");
        }
    }

    public function testEditorFullWitherSweepCarriesTheBox(): void
    {
        $base = Editor::new("alpha line\nbeta line\ngamma line\ncontent keeps flowing\nfor the tall box test");
        $this->assertCarryIdentity(
            $base,
            [
                'withContent' => static fn (Editor $x): Editor => $x->withContent('gamma'),
                'withLineNumbers' => static fn (Editor $x): Editor => $x->withLineNumbers(true),
                'withWordWrap' => static fn (Editor $x): Editor => $x->withWordWrap(true),
                'withReadOnly' => static fn (Editor $x): Editor => $x->withReadOnly(true),
                'withShowCursor' => static fn (Editor $x): Editor => $x->withShowCursor(false),
                'withTextColor' => static fn (Editor $x): Editor => $x->withTextColor(Color::hex('#88CCFF')),
                'withStyle' => static fn (Editor $x): Editor => $x->withStyle('bold'),
            ],
            40,
            14,
            'editor'
        );
        $sized = $base->setSize(40, 14);
        $this->assertNotSame($base->withReadOnly(true)->render(), $sized->withReadOnly(true)->render(), 'pin teeth: box differs from natural');
    }

    public function testFormFamilySweepsCarryTheBox(): void
    {
        $this->assertCarryIdentity(
            Input::new('v'),
            [
                'withPlaceholder' => static fn (Input $x): Input => $x->withPlaceholder('p'),
                'withValue' => static fn (Input $x): Input => $x->withValue('z'),
                'withLabel' => static fn (Input $x): Input => $x->withLabel('L'),
            ],
            20,
            7,
            'input'
        );
        $this->assertCarryIdentity(
            Textarea::new("one\ntwo\nthree\nfour", 4),
            [
                'withPlaceholder' => static fn (Textarea $x): Textarea => $x->withPlaceholder('p'),
                'withValue' => static fn (Textarea $x): Textarea => $x->withValue('z'),
                'withRows' => static fn (Textarea $x): Textarea => $x->withRows(6),
            ],
            24,
            9,
            'textarea'
        );
        $this->assertCarryIdentity(
            Slider::new(0.5),
            [
                'withValue' => static fn (Slider $x): Slider => $x->withValue(0.25),
                'withShowValue' => static fn (Slider $x): Slider => $x->withShowValue(false),
                'withChars' => static fn (Slider $x): Slider => $x->withChars('o', '='),
            ],
            16,
            1,
            'slider'
        );
        $slider = Slider::new(0.5)->setSize(16, 1);
        $this->assertNotSame(Slider::new(0.5)->withShowValue(false)->render(), $slider->withShowValue(false)->render(), 'pin teeth: slider box differs');
    }

    public function testPaletteSweepKeepsCarryHonest(): void
    {
        $this->assertCarryIdentity(
            CommandPalette::new([]),
            [
                'withQuery' => static fn (CommandPalette $x): CommandPalette => $x->withQuery('q'),
                'withBorderChar' => static fn (CommandPalette $x): CommandPalette => $x->withBorderChar('>'),
            ],
            30,
            10,
            'palette'
        );
    }

    public function testNavFamilySweepsCarryTheBox(): void
    {
        $this->assertCarryIdentity(
            Navbar::new([['label' => 'Home', 'active' => true], ['label' => 'About']]),
            [
                'withBrand' => static fn (Navbar $x): Navbar => $x->withBrand('Acme'),
                'withActiveItem' => static fn (Navbar $x): Navbar => $x->withActiveItem(1),
            ],
            40,
            1,
            'navbar'
        );
        $this->assertCarryIdentity(
            Breadcrumb::new(['Home', 'Library']),
            [
                'withSeparator' => static fn (Breadcrumb $x): Breadcrumb => $x->withSeparator('>'),
                'withActiveIndex' => static fn (Breadcrumb $x): Breadcrumb => $x->withActiveIndex(0),
            ],
            30,
            1,
            'breadcrumb'
        );
        $crumbs = Breadcrumb::new(['Home', 'Library'])->setSize(30, 1);
        $this->assertNotSame(Breadcrumb::new(['Home', 'Library'])->withSeparator('>')->render(), $crumbs->withSeparator('>')->render(), 'pin teeth');

        $this->assertCarryIdentity(
            Stepper::new([['label' => 'One', 'status' => 'active'], ['label' => 'Two', 'status' => 'pending']]),
            [
                'withShowNumbers' => static fn (Stepper $x): Stepper => $x->withShowNumbers(false),
                'withActiveStep' => static fn (Stepper $x): Stepper => $x->withActiveStep(1),
            ],
            40,
            3,
            'stepper'
        );
        $this->assertCarryIdentity(
            Scrollbar::new(0.5),
            [
                'withShowArrows' => static fn (Scrollbar $x): Scrollbar => $x->withShowArrows(true),
                'withPosition' => static fn (Scrollbar $x): Scrollbar => $x->withPosition(0.9),
            ],
            1,
            12,
            'scrollbar'
        );
        $bar = Scrollbar::new(0.5)->setSize(1, 12);
        $this->assertNotSame(Scrollbar::new(0.5)->withPosition(0.9)->render(), $bar->withPosition(0.9)->render(), 'pin teeth: scrollbar box differs');
    }

    public function testTreeFamilyKeepsCarryHonest(): void
    {
        $this->assertCarryIdentity(
            Tree::new([TreeNode::new('Root'), TreeNode::new('Leaf')]),
            [
                'withShowLines' => static fn (Tree $x): Tree => $x->withShowLines(false),
                'withNodeColor' => static fn (Tree $x): Tree => $x->withNodeColor(Color::hex('#FFAA00')),
                'withBranchChars' => static fn (Tree $x): Tree => $x->withBranchChars('++', '--'),
                'withCollapsedColor' => static fn (Tree $x): Tree => $x->withCollapsedColor(Color::hex('#FFAA00')),
                'withNodeCollapsed' => static fn (Tree $x): Tree => $x->withNodeCollapsed('Root', true),
            ],
            30,
            8,
            'tree'
        );
        $this->assertCarryIdentity(
            TreeViz::new(),
            [
                'withShowLines' => static fn (TreeViz $x): TreeViz => $x->withShowLines(false),
                'withShowLabels' => static fn (TreeViz $x): TreeViz => $x->withShowLabels(true),
                'withOrientation' => static fn (TreeViz $x): TreeViz => $x->withOrientation('vertical'),
            ],
            24,
            8,
            'treeviz'
        );
    }
}
