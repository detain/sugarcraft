<?php

declare(strict_types=1);

namespace CandyCore\Shell\Tests\Model;

use CandyCore\Shell\Model\FileModel;
use PHPUnit\Framework\TestCase;

final class FileModelTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/candy-shell-file-' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
        file_put_contents($this->tmp . '/visible.txt', 'a');
        file_put_contents($this->tmp . '/.hidden', 'h');
        mkdir($this->tmp . '/subdir');
    }

    protected function tearDown(): void
    {
        @unlink($this->tmp . '/visible.txt');
        @unlink($this->tmp . '/.hidden');
        @rmdir($this->tmp . '/subdir');
        @rmdir($this->tmp);
    }

    public function testDefaultsHideDotfiles(): void
    {
        $model = FileModel::newPrompt(cwd: $this->tmp);
        $entries = $model->picker->entries;
        $names = array_map(static fn ($e) => $e->name, $entries);
        $this->assertContains('visible.txt', $names);
        $this->assertNotContains('.hidden', $names);
    }

    public function testAllFlagShowsDotfiles(): void
    {
        $model = FileModel::newPrompt(cwd: $this->tmp, showHidden: true);
        $names = array_map(static fn ($e) => $e->name, $model->picker->entries);
        $this->assertContains('.hidden', $names);
    }

    public function testDirectoryFlagAllowsDirSelection(): void
    {
        $model = FileModel::newPrompt(cwd: $this->tmp, allowDirs: true, allowFiles: false);
        $this->assertTrue($model->picker->dirAllowed);
        $this->assertFalse($model->picker->fileAllowed);
    }

    public function testShowSizeFlagPropagates(): void
    {
        $model = FileModel::newPrompt(cwd: $this->tmp, showSize: true);
        $this->assertTrue($model->picker->showSize);
    }
}
