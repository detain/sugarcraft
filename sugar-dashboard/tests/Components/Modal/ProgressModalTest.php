<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\Modal;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dashboard\Components\Modal\ProgressModal;
use SugarCraft\Dashboard\Foundation\SizedItem;

/**
 * D-70 (finding sugar-dashboard-182 design leg): the progress dialog answers
 * the SizedItem channel and clips its painted row to the allocation.
 */
final class ProgressModalTest extends TestCase
{
    public function testImplementsTheSizedItemContract(): void
    {
        $modal = ProgressModal::new(7, 5);
        $this->assertInstanceOf(SizedItem::class, $modal);
        $natural = $modal->innerSize();
        $this->assertSame(Width::string($modal->render()), $natural[0]);
        $this->assertSame(1, $natural[1], 'a single painted row');
    }

    public function testSetSizeIsHonoredByInnerSize(): void
    {
        $modal = ProgressModal::new(7, 5)->setSize(24, 4);
        $this->assertSame([24, 4], $modal->innerSize());
    }

    public function testRowFitsTheWidthAllocation(): void
    {
        $modal = ProgressModal::new(7, 5, 'label that goes on and on')->setSize(24, 0);
        foreach (explode("\n", $modal->render()) as $line) {
            $this->assertLessThanOrEqual(24, Width::string($line));
        }
    }
}