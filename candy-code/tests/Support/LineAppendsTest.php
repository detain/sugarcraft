<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Support\LineAppends;

final class LineAppendsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/lineappends-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir . '/sub');
        @rmdir($this->dir);
    }

    public function testAppendsLinesAndCreatesPrivateParent(): void
    {
        $path = $this->dir . '/sub/a.jsonl';
        self::assertTrue(LineAppends::append($path, "one\n"));
        self::assertTrue(LineAppends::append($path, "two\n"));
        self::assertSame("one\ntwo\n", file_get_contents($path));
        self::assertSame(0o700, fileperms($this->dir . '/sub') & 0o777);
    }

    public function testRefusesSymlinkedFile(): void
    {
        mkdir($this->dir, 0o700, true);
        file_put_contents($this->dir . '/target', '');
        symlink($this->dir . '/target', $this->dir . '/link');
        self::assertFalse(LineAppends::append($this->dir . '/link', "x\n"));
        self::assertSame('', file_get_contents($this->dir . '/target'));
        @unlink($this->dir . '/target');
    }
}
