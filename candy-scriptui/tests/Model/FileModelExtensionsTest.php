<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Model;

use PHPUnit\Framework\TestCase;
use SugarCraft\ScriptUi\Application;
use SugarCraft\ScriptUi\Model\FileModel;
use SugarCraft\ScriptUi\Runtime\OptionList;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * scriptui-45 (finding 063): `file --extensions` must reach the picker's
 * readdir predicate — repeatable AND comma-split spellings are equal.
 */
final class FileModelExtensionsTest extends TestCase
{
    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            exec('rm -rf ' . escapeshellarg($this->dir));
            $this->dir = '';
        }
    }

    private function makeTree(): string
    {
        $this->dir = sys_get_temp_dir() . '/scriptui-ext-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/pics', 0777, true);
        file_put_contents($this->dir . '/a.png', 'x');
        file_put_contents($this->dir . '/b.jpg', 'x');
        file_put_contents($this->dir . '/c.csv', 'x');
        file_put_contents($this->dir . '/NOTES.TXT', 'x');
        file_put_contents($this->dir . '/.hidden.png', 'x');

        return $this->dir;
    }

    public function testOnlyListedExtensionsRender(): void
    {
        $model = FileModel::newPrompt(cwd: $this->makeTree(), allowedExtensions: ['png', 'jpg']);
        $view = $model->view();

        self::assertStringContainsString('a.png', $view);
        self::assertStringContainsString('b.jpg', $view);
        self::assertStringNotContainsString('c.csv', $view);
        self::assertStringNotContainsString('NOTES.TXT', $view);
        self::assertStringNotContainsString('.hidden.png', $view);
    }

    public function testDirectoriesAlwaysRideThrough(): void
    {
        $model = FileModel::newPrompt(cwd: $this->makeTree(), allowedExtensions: ['csv']);
        $view = $model->view();

        self::assertStringContainsString('c.csv', $view);
        self::assertStringContainsString('pics', $view, 'a filtered listing must still allow descending');
    }

    public function testDotsAndCaseAreNormalised(): void
    {
        $model = FileModel::newPrompt(cwd: $this->makeTree(), allowedExtensions: ['.PNG', 'Txt']);
        $view = $model->view();

        self::assertStringContainsString('a.png', $view);
        self::assertStringContainsString('NOTES.TXT', $view);
        self::assertStringNotContainsString('b.jpg', $view);
    }

    public function testRepeatAndCommaSpellingsAreEqual(): void
    {
        $dir = $this->makeTree();
        $one  = FileModel::newPrompt(cwd: $dir, allowedExtensions: ['png', 'jpg']);
        $many = FileModel::newPrompt(cwd: $dir, allowedExtensions: OptionList::expand(['.png,.jpg']));

        self::assertSame($one->view(), $many->view());
    }

    public function testEmptyListIsTheHistoricalNoFilter(): void
    {
        $model = FileModel::newPrompt(cwd: $this->makeTree(), allowedExtensions: []);
        $view = $model->view();

        self::assertStringContainsString('c.csv', $view);
        self::assertStringContainsString('NOTES.TXT', $view);
    }

    public function testCommandRegistersTheOptionOnTheRealRunPath(): void
    {
        // Pre-fix this died with 'The "--extensions" option does not exist.'
        // Registered now, the run proceeds to the honest headless refusal
        // (display never carries the unknown-option sentence).
        $app = new Application();
        $app->setAutoExit(false);
        $out = new BufferedOutput();

        $status = $app->run(new ArgvInput(['candy-scriptui', 'file', '--extensions=.png,.jpg', $this->makeTree()]), $out);

        self::assertSame(1, $status);
        self::assertStringNotContainsString('does not exist', $out->fetch());
    }
}
