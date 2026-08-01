<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Tests\Model;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Shell\Model\WriteModel;
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

    public function testViewWithHeaderPrependsHeader(): void
    {
        $m = WriteModel::newPrompt(header: 'Enter your text:');
        $view = $m->view();
        $this->assertStringStartsWith('Enter your text:', $view);
    }

    public function testViewWithoutHeaderIsJustBody(): void
    {
        $m = WriteModel::newPrompt();
        $body = $m->area->view();
        $this->assertSame($body, $m->view());
    }

    public function testPreFilledValueIsEditable(): void
    {
        $m = WriteModel::newPrompt(value: "hello\nworld");
        $this->assertSame("hello\nworld", $m->value());
        [$m, ] = $m->update(new KeyMsg(KeyType::Enter));
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, '!'));
        $this->assertSame("hello\nworld\n!", $m->value());
    }

    public function testWidthAndHeightPassedToArea(): void
    {
        $m = WriteModel::newPrompt(width: 40, height: 10);
        // Just verify it doesn't throw and produces a view
        $view = $m->view();
        $this->assertNotEmpty($view);
    }
}
