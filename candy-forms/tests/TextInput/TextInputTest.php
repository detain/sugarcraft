<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\TextInput;

use PHPUnit\Framework\TestCase;

/**
 * @covers TextInput
 */
final class TextInputTest extends TestCase
{
    public function testPlaceholderReturnsTodoMessage(): void
    {
        $input = new \SugarCraft\Forms\TextInput\TextInput();
        $this->assertSame('TODO: move from sugar-bits in Phase 2', $input->placeholder());
    }
}
