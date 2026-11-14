<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\Modal;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dashboard\Components\Modal\MultiselectModal;
use SugarCraft\Dashboard\Foundation\SizedItem;

/**
 * D-70 (finding sugar-dashboard-069): MultiselectModal answers the same
 * SizedItem channel the layout hosts measure, and its selection state has
 * a visible cursor surface.
 */
final class MultiselectModalTest extends TestCase
{
    public function testImplementsTheSizedItemContract(): void
    {
        $modal = MultiselectModal::new(['One', 'Two'], 'Pick');
        $this->assertInstanceOf(SizedItem::class, $modal);
        $natural = $modal->innerSize();
        $this->assertSame(3, $natural[1], 'title + two option rows');
        $this->assertSame(
            max(array_map(
                static fn(string $l): int => Width::string($l),
                explode("\n", $modal->render()),
            )),
            $natural[0],
            'innerSize width is the widest painted row',
        );
    }

    public function testSetSizeIsHonoredByInnerSize(): void
    {
        $modal = MultiselectModal::new(['One', 'Two'], 'Pick')->setSize(20, 6);
        $this->assertSame([20, 6], $modal->innerSize());
    }

    public function testFirstSelectedRowCarriesTheCursor(): void
    {
        $modal = MultiselectModal::new(['One', 'Two', 'Three'], 'Pick')
            ->withSelectedIndices([2, 0]);
        $lines = explode("\n", $modal->render());
        $this->assertSame('> [x] One', $lines[1], 'the first selected index decorates');
        $this->assertSame('  [x] Three', $lines[3], 'other checked rows keep the plain prefix');
    }

    public function testNoSelectionRendersNoCursor(): void
    {
        $modal = MultiselectModal::new(['One'], 'Pick');
        $this->assertStringNotContainsString('>', $modal->render());
    }

    public function testWindowKeepsTheCursorVisible(): void
    {
        $options = array_map(static fn(int $i): string => sprintf('Opt %02d', $i), range(1, 10));
        $modal = MultiselectModal::new($options, 'Pick')
            ->withSelectedIndices([9])
            ->setSize(20, 4);
        $lines = explode("\n", $modal->render());
        $this->assertCount(4, $lines, 'title + three windowed rows');
        $this->assertStringContainsString('> [x] Opt 10', $lines[3]);
        $this->assertStringNotContainsString('Opt 01', $lines[1] . $lines[2], 'window slid to the cursor');
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(20, Width::string($line));
        }
    }

    public function testRowsFitTheWidthAllocation(): void
    {
        $modal = MultiselectModal::new([str_repeat('X', 40)], 'Ti')->setSize(12, 0);
        foreach (explode("\n", $modal->render()) as $line) {
            $this->assertLessThanOrEqual(12, Width::string($line));
        }
    }

    public function testSelectionSurvivesTheToggleChain(): void
    {
        $modal = MultiselectModal::new(['A', 'B'])->toggle(1)->toggle(0);
        $this->assertSame('> [x] A', explode("\n", $modal->render())[0]);
    }
}
