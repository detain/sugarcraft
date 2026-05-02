<?php

declare(strict_types=1);

namespace CandyCore\Prompt\Tests\Field;

use CandyCore\Core\KeyType;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Prompt\Field\FilePicker;
use PHPUnit\Framework\TestCase;

final class FilePickerFieldTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'candyprompt-fp-' . bin2hex(random_bytes(4));
        mkdir($this->root);
        file_put_contents($this->root . '/a.txt', 'a');
        file_put_contents($this->root . '/b.txt', 'b');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') as $f) {
            if (is_file($f)) unlink($f);
        }
        rmdir($this->root);
    }

    public function testKeyAndInitialValue(): void
    {
        $f = FilePicker::new('file', $this->root);
        $this->assertSame('file', $f->key());
        $this->assertNull($f->value());
    }

    public function testEnterSelectsFile(): void
    {
        [$f, ] = FilePicker::new('file', $this->root)->focus();
        [$f, ] = $f->update(new KeyMsg(KeyType::Enter));
        $this->assertSame(rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'a.txt', $f->value());
    }

    public function testConsumesEnterAndBackspaceWhenFocused(): void
    {
        [$f, ] = FilePicker::new('file', $this->root)->focus();
        $this->assertTrue($f->consumes(new KeyMsg(KeyType::Enter)));
        $this->assertTrue($f->consumes(new KeyMsg(KeyType::Backspace)));
        $this->assertFalse($f->consumes(new KeyMsg(KeyType::Char, 'a')));
    }

    public function testDoesNotConsumeWhenUnfocused(): void
    {
        $f = FilePicker::new('file', $this->root);
        $this->assertFalse($f->consumes(new KeyMsg(KeyType::Enter)));
    }

    public function testTitleInView(): void
    {
        $f = FilePicker::new('file', $this->root)->withTitle('Choose a file');
        $this->assertStringContainsString('Choose a file', $f->view());
    }

    public function testAllowedExtensionsForwarded(): void
    {
        $f = FilePicker::new('file', $this->root)->withAllowedExtensions(['md']);
        // Only .md files would be visible — we put none, so list is empty.
        $this->assertSame([], $f->picker->entries);
    }
}
