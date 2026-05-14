<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Modal;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Components\Modal\ListModal;

final class ListModalTest extends TestCase
{
    public function testListModalWithOnEdit(): void
    {
        $items = ['Item 1', 'Item 2', 'Item 3'];
        $calledWith = null;
        $modal = ListModal::new($items)
            ->withOnEdit(function ($item, $index) use (&$calledWith) {
                $calledWith = ['item' => $item, 'index' => $index];
            });

        $this->assertNotNull($modal);
        $rendered = $modal->render();
        $this->assertStringContainsString('[E]dit', $rendered);
    }

    public function testListModalWithOnDelete(): void
    {
        $items = ['Item 1', 'Item 2', 'Item 3'];
        $calledWith = null;
        $modal = ListModal::new($items)
            ->withOnDelete(function ($item, $index) use (&$calledWith) {
                $calledWith = ['item' => $item, 'index' => $index];
            });

        $this->assertNotNull($modal);
        $rendered = $modal->render();
        $this->assertStringContainsString('[D]elete', $rendered);
    }

    public function testListModalRendersEditDeleteHints(): void
    {
        $items = ['Apple', 'Banana', 'Cherry'];
        $modal = ListModal::new($items)
            ->withOnEdit(fn() => null)
            ->withOnDelete(fn() => null);

        $rendered = $modal->render();
        $this->assertStringContainsString('[E]dit', $rendered);
        $this->assertStringContainsString('[D]elete', $rendered);
        $this->assertStringContainsString('Apple', $rendered);
        $this->assertStringContainsString('Banana', $rendered);
        $this->assertStringContainsString('Cherry', $rendered);
    }

    public function testListModalWithoutCallbacks(): void
    {
        $items = ['Alpha', 'Beta'];
        $modal = ListModal::new($items);
        $rendered = $modal->render();

        $this->assertStringNotContainsString('[E]dit', $rendered);
        $this->assertStringNotContainsString('[D]elete', $rendered);
    }
}
