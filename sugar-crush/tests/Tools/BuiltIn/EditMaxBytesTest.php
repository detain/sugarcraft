<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tools\BuiltIn\Edit;

/**
 * Edit enforces its own `$maxBytes` (audit F-T2).
 *
 * The constructor took the cap and nothing read it, so a 36 MB file edited
 * with `maxBytes: 1024` succeeded at a 650 MB peak. The refusal must come
 * before the read, name the cap so the model learns why, and leave the file
 * exactly as it was.
 *
 * @see Edit
 */
final class EditMaxBytesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/edit_max_bytes_' . uniqid('', true);
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->root);
    }

    public function testAFileOverMaxBytesIsRefusedNamingTheCapAndLeftUntouched(): void
    {
        $bytes = str_repeat("filler line\n", 340) . "needle\n";
        $bytes .= str_repeat('x', 4096 - strlen($bytes));
        $path = $this->root . '/four-kib.txt';
        file_put_contents($path, $bytes);
        $mtime = filemtime($path);

        $result = (new Edit($this->root, maxBytes: 1024))->execute([
            'id' => 'call_cap',
            'file_path' => 'four-kib.txt',
            'old_string' => 'needle',
            'new_string' => 'changed',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('1,024-byte', $result->content());
        $this->assertStringContainsString('4,096 bytes', $result->content());
        $this->assertStringContainsString('file left unchanged', $result->content());
        $this->assertNull($result->diff());
        clearstatcache(true, $path);
        $this->assertSame($bytes, file_get_contents($path));
        $this->assertSame($mtime, filemtime($path));
    }

    public function testAFileOfExactlyMaxBytesIsStillEdited(): void
    {
        $bytes = "needle\n" . str_repeat('y', 1024 - 7);
        file_put_contents($this->root . '/at-cap.txt', $bytes);

        $result = (new Edit($this->root, maxBytes: 1024))->execute([
            'id' => 'call_at_cap',
            'file_path' => 'at-cap.txt',
            'old_string' => 'needle',
            'new_string' => 'changed',
        ]);

        $this->assertFalse($result->isError(), $result->content());
        $this->assertSame(
            "changed\n" . str_repeat('y', 1024 - 7),
            file_get_contents($this->root . '/at-cap.txt'),
        );
    }

    /**
     * The production wiring (`Cli\Bootstrap`) passes no `maxBytes`, so the
     * default is the cap every real session gets.
     */
    public function testTheDefaultCapIsOneMebibyte(): void
    {
        file_put_contents($this->root . '/over.txt', "needle\n" . str_repeat('z', 1024 * 1024 - 6));

        $result = (new Edit($this->root))->execute([
            'id' => 'call_default',
            'file_path' => 'over.txt',
            'old_string' => 'needle',
            'new_string' => 'changed',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('1,048,576-byte', $result->content());
        $this->assertStringContainsString('1,048,577 bytes', $result->content());
    }
}
