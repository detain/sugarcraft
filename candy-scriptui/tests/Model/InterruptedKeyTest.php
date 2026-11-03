<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Model;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\ScriptUi\Model\ChooseModel;
use SugarCraft\ScriptUi\Model\ConfirmModel;
use SugarCraft\ScriptUi\Model\FileModel;
use SugarCraft\ScriptUi\Model\FilterModel;
use SugarCraft\ScriptUi\Model\InputModel;
use SugarCraft\ScriptUi\Model\PagerModel;
use SugarCraft\ScriptUi\Model\WriteModel;
use PHPUnit\Framework\TestCase;

/**
 * Cluster 4 (2026-11-03): an Esc abort and a Ctrl+C cancellation are
 * different user intents. Raw mode clears ISIG, so a typed Ctrl+C
 * reaches the model as a KeyMsg — every interactive model must record
 * it (`$interrupted`) while keeping `aborted` for the Esc path, so the
 * commands can map Esc -> its own abort code and Ctrl+C -> 130.
 */
final class InterruptedKeyTest extends TestCase
{
    private static function ctrlC(): KeyMsg
    {
        return new KeyMsg(KeyType::Char, 'c', ctrl: true);
    }

    public function testConfirmEscAbortIsNotAnInterruption(): void
    {
        $model = ConfirmModel::newPrompt('Deploy?');
        [$next, ] = $model->update(new KeyMsg(KeyType::Escape));
        $this->assertTrue($next->isAborted());
        $this->assertFalse($next->isInterrupted());
    }

    public function testConfirmCtrlCRecordsInterruption(): void
    {
        $model = ConfirmModel::newPrompt('Deploy?');
        [$next, ] = $model->update(self::ctrlC());
        $this->assertTrue($next->isAborted());
        $this->assertTrue($next->isInterrupted());
    }

    public function testChooseEscVersusCtrlC(): void
    {
        $model = ChooseModel::fromOptions(['a', 'b', 'c']);
        [$esc, ] = $model->update(new KeyMsg(KeyType::Escape));
        $this->assertTrue($esc->isAborted());
        $this->assertFalse($esc->isInterrupted());

        [$ctrl, ] = $model->update(self::ctrlC());
        $this->assertTrue($ctrl->isAborted());
        $this->assertTrue($ctrl->isInterrupted());
    }

    public function testFilterEscVersusCtrlC(): void
    {
        $model = FilterModel::fromOptions(['one', 'two']);
        [$esc, ] = $model->update(new KeyMsg(KeyType::Escape));
        $this->assertTrue($esc->isAborted());
        $this->assertFalse($esc->isInterrupted());

        [$ctrl, ] = $model->update(self::ctrlC());
        $this->assertTrue($ctrl->isAborted());
        $this->assertTrue($ctrl->isInterrupted());
    }

    public function testInputEscVersusCtrlC(): void
    {
        $model = InputModel::newPrompt();
        [$esc, ] = $model->update(new KeyMsg(KeyType::Escape));
        $this->assertTrue($esc->isAborted());
        $this->assertFalse($esc->isInterrupted());

        [$ctrl, ] = $model->update(self::ctrlC());
        $this->assertTrue($ctrl->isAborted());
        $this->assertTrue($ctrl->isInterrupted());
    }

    public function testWriteEscVersusCtrlC(): void
    {
        $model = WriteModel::newPrompt();
        [$esc, ] = $model->update(new KeyMsg(KeyType::Escape));
        $this->assertTrue($esc->isAborted());
        $this->assertFalse($esc->isInterrupted());

        [$ctrl, ] = $model->update(self::ctrlC());
        $this->assertTrue($ctrl->isAborted());
        $this->assertTrue($ctrl->isInterrupted());
    }

    public function testFileEscVersusCtrlC(): void
    {
        $model = FileModel::newPrompt(sys_get_temp_dir());
        [$esc, ] = $model->update(new KeyMsg(KeyType::Escape));
        $this->assertTrue($esc->isAborted());
        $this->assertFalse($esc->isInterrupted());

        [$ctrl, ] = $model->update(self::ctrlC());
        $this->assertTrue($ctrl->isAborted());
        $this->assertTrue($ctrl->isInterrupted());
    }

    public function testPagerQuitKeyIsNotAnInterruption(): void
    {
        $model = PagerModel::fromContent("line one\nline two\n");
        [$esc, ] = $model->update(new KeyMsg(KeyType::Escape));
        $this->assertTrue($esc->isExited());
        $this->assertFalse($esc->isInterrupted());

        [$q, ] = $model->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertTrue($q->isExited());
        $this->assertFalse($q->isInterrupted());
    }

    public function testPagerCtrlCRecordsInterruption(): void
    {
        $model = PagerModel::fromContent("line one\nline two\n");
        [$ctrl, ] = $model->update(self::ctrlC());
        $this->assertTrue($ctrl->isExited());
        $this->assertTrue($ctrl->isInterrupted());
    }
}
