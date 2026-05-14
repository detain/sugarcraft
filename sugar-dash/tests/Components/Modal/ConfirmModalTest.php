<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Modal;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Components\Modal\ConfirmModal;

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
}
