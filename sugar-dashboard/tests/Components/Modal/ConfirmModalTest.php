<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\Modal;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dashboard\Components\Modal\ConfirmModal;
use SugarCraft\Dashboard\Foundation\SizedItem;

final class ConfirmModalTest extends TestCase
{
    public function testOkFactory(): void
    {
        $modal = ConfirmModal::ok('Are you sure?');
        $this->assertNotNull($modal);
        $rendered = $modal->render();
        $this->assertStringContainsString('Are you sure?', $rendered);
        $this->assertStringContainsString('[OK]', $rendered);
        // OK modal should NOT have cancel button
        $this->assertStringNotContainsString('[Cancel]', $rendered);
    }

    public function testYesNoFactory(): void
    {
        $modal = ConfirmModal::yesNo('Continue?');
        $this->assertNotNull($modal);
        $rendered = $modal->render();
        $this->assertStringContainsString('Continue?', $rendered);
        $this->assertStringContainsString('[Yes]', $rendered);
        $this->assertStringContainsString('[No]', $rendered);
    }

    public function testDangerStillWorks(): void
    {
        $modal = ConfirmModal::danger('Delete this?');
        $this->assertNotNull($modal);
        $rendered = $modal->render();
        $this->assertStringContainsString('Delete this?', $rendered);
        $this->assertStringContainsString('⚠ Delete', $rendered);
        $this->assertStringContainsString('[Cancel]', $rendered);
    }

    public function testNewCreatesConfirmModal(): void
    {
        $modal = ConfirmModal::new('Confirm this');
        $this->assertNotNull($modal);
        $rendered = $modal->render();
        $this->assertStringContainsString('Confirm this', $rendered);
        $this->assertStringContainsString('[Confirm]', $rendered);
        $this->assertStringContainsString('[Cancel]', $rendered);
    }

    public function testImplementsTheSizedItemContract(): void
    {
        $modal = ConfirmModal::new('Confirm this');
        $this->assertInstanceOf(SizedItem::class, $modal);
        $natural = $modal->innerSize();
        $this->assertSame(Width::string($modal->render()), $natural[0]);
        $this->assertSame(1, $natural[1], 'a single painted row');
    }

    public function testSetSizeIsHonoredByInnerSize(): void
    {
        $modal = ConfirmModal::new('Confirm this')->setSize(24, 5);
        $this->assertSame([24, 5], $modal->innerSize());
    }

    public function testRowFitsTheWidthAllocation(): void
    {
        $modal = ConfirmModal::new('A very long confirmation message that overruns any dialog')->setSize(20, 0);
        $this->assertLessThanOrEqual(20, Width::string($modal->render()));
    }
}
