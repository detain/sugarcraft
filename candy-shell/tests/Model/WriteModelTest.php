<?php

declare(strict_types=1);

namespace CandyCore\Shell\Tests\Model;

use CandyCore\Core\KeyType;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Shell\Model\WriteModel;
use PHPUnit\Framework\TestCase;

final class WriteModelTest extends TestCase
{
    public function testTypeWithEnterAndCtrlDSubmits(): void
    {
        $m = WriteModel::newPrompt();
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'a'));
        [$m, ] = $m->update(new KeyMsg(KeyType::Enter));
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'b'));
        [$m, $cmd] = $m->update(new KeyMsg(KeyType::Char, 'd', ctrl: true));
        $this->assertTrue($m->isSubmitted());
        $this->assertSame("a\nb", $m->value());
        $this->assertNotNull($cmd);
    }

    public function testEscAborts(): void
    {
        $m = WriteModel::newPrompt();
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'x'));
        [$m, ] = $m->update(new KeyMsg(KeyType::Escape));
        $this->assertTrue($m->isAborted());
        $this->assertSame('x', $m->value());
    }

    public function testCtrlCAborts(): void
    {
        $m = WriteModel::newPrompt();
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));
        $this->assertTrue($m->isAborted());
    }

    public function testIgnoresKeysAfterSubmit(): void
    {
        $m = WriteModel::newPrompt();
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'd', ctrl: true));
        [$m2, ] = $m->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertSame($m, $m2);
    }
}
