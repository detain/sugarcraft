<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use SugarCraft\Toast\ToastType;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ToastType::label() i18n method.
 */
final class ToastTypeLabelTest extends TestCase
{
    public function testLabelReturnsString(): void
    {
        foreach (ToastType::cases() as $type) {
            $label = $type->label();
            $this->assertIsString($label, "{$type->name}::label() must return a string");
            $this->assertNotEmpty($label, "{$type->name}::label() must not be empty");
        }
    }

    public function testLabelForError(): void
    {
        $this->assertSame('Error', ToastType::Error->label());
    }

    public function testLabelForWarning(): void
    {
        $this->assertSame('Warning', ToastType::Warning->label());
    }

    public function testLabelForInfo(): void
    {
        $this->assertSame('Info', ToastType::Info->label());
    }

    public function testLabelForSuccess(): void
    {
        $this->assertSame('Success', ToastType::Success->label());
    }

    public function testLabelIsConsistent(): void
    {
        // Calling label() multiple times should return the same result
        $this->assertSame(ToastType::Error->label(), ToastType::Error->label());
        $this->assertSame(ToastType::Warning->label(), ToastType::Warning->label());
        $this->assertSame(ToastType::Info->label(), ToastType::Info->label());
        $this->assertSame(ToastType::Success->label(), ToastType::Success->label());
    }
}
