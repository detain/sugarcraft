<?php

declare(strict_types=1);

namespace CandyCore\Shell\Tests\Model;

use CandyCore\Core\KeyType;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Shell\Model\ConfirmModel;
use PHPUnit\Framework\TestCase;

final class ConfirmModelTest extends TestCase
{
    public function testYKeyCommitsYes(): void
    {
        $m = ConfirmModel::newPrompt('Continue?');
        [$m, $cmd] = $m->update(new KeyMsg(KeyType::Char, 'y'));
        $this->assertTrue($m->isSubmitted());
        $this->assertTrue($m->answer());
        $this->assertNotNull($cmd);
    }

    public function testNKeyCommitsNo(): void
    {
        $m = ConfirmModel::newPrompt('Continue?');
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'n'));
        $this->assertTrue($m->isSubmitted());
        $this->assertFalse($m->answer());
    }

    public function testEnterCommitsCurrentDefault(): void
    {
        $m = ConfirmModel::newPrompt('Continue?', default: true);
        [$m, ] = $m->update(new KeyMsg(KeyType::Enter));
        $this->assertTrue($m->isSubmitted());
        $this->assertTrue($m->answer());
    }

    public function testArrowsToggle(): void
    {
        $m = ConfirmModel::newPrompt();
        // Default false; left arrow toggles to true (yes is the left side).
        [$m, ] = $m->update(new KeyMsg(KeyType::Left));
        [$m, ] = $m->update(new KeyMsg(KeyType::Enter));
        $this->assertTrue($m->answer());
    }

    public function testEscAborts(): void
    {
        $m = ConfirmModel::newPrompt();
        [$m, ] = $m->update(new KeyMsg(KeyType::Escape));
        $this->assertTrue($m->isAborted());
        $this->assertFalse($m->isSubmitted());
    }

    public function testCtrlCAborts(): void
    {
        $m = ConfirmModel::newPrompt();
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));
        $this->assertTrue($m->isAborted());
    }
}
