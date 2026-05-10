<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Tests\Model;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Shell\Model\FileModel;
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

    public function testInitReturnsNull(): void
    {
        $model = FileModel::newPrompt(cwd: $this->tmp);
        $this->assertNull($model->init());
    }

    public function testViewReturnsString(): void
    {
        $model = FileModel::newPrompt(cwd: $this->tmp);
        $this->assertIsString($model->view());
    }

    public function testSelectedReturnsNullWhenNoSelection(): void
    {
        $model = FileModel::newPrompt(cwd: $this->tmp);
        $this->assertNull($model->selected());
    }

    public function testIsAbortedIsFalseInitially(): void
    {
        $model = FileModel::newPrompt(cwd: $this->tmp);
        $this->assertFalse($model->isAborted());
    }

    public function testIsSubmittedIsFalseInitially(): void
    {
        $model = FileModel::newPrompt(cwd: $this->tmp);
        $this->assertFalse($model->isSubmitted());
    }

    public function testEscapeSetsAborted(): void
    {
        $model = FileModel::newPrompt(cwd: $this->tmp);
        [$next, $cmd] = $model->update(new KeyMsg(KeyType::Escape));
        $this->assertTrue($next->isAborted());
        $this->assertNotNull($cmd);
    }

    public function testCtrlCAborts(): void
    {
        $model = FileModel::newPrompt(cwd: $this->tmp);
        [$next, $cmd] = $model->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));
        $this->assertTrue($next->isAborted());
        $this->assertNotNull($cmd);
    }

    public function testUpdateWhenAlreadyAbortedReturnsSelf(): void
    {
        $model = FileModel::newPrompt(cwd: $this->tmp);
        [$aborted, ] = $model->update(new KeyMsg(KeyType::Escape));
        [$again, $cmd] = $aborted->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertSame($aborted, $again);
        $this->assertNull($cmd);
    }
}
