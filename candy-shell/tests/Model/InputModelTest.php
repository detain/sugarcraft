<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Tests\Model;

use SugarCraft\Forms\TextInput\EchoMode;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Shell\Model\InputModel;
use PHPUnit\Framework\TestCase;

final class InputModelTest extends TestCase
{
    public function testTypeAndSubmit(): void
    {
        $m = InputModel::newPrompt();
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'a'));
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'b'));
        [$m, $cmd] = $m->update(new KeyMsg(KeyType::Enter));
        $this->assertTrue($m->isSubmitted());
        $this->assertSame('ab', $m->value());
        $this->assertNotNull($cmd);
    }

    public function testEscAborts(): void
    {
        $m = InputModel::newPrompt();
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'x'));
        [$m, ] = $m->update(new KeyMsg(KeyType::Escape));
        $this->assertTrue($m->isAborted());
        $this->assertSame('x', $m->value());
    }

    public function testPasswordMode(): void
    {
        $m = InputModel::newPrompt(password: true);
        $this->assertSame(EchoMode::Password, $m->input->echoMode);
    }

    public function testIgnoresKeysAfterSubmit(): void
    {
        $m = InputModel::newPrompt();
        [$m, ] = $m->update(new KeyMsg(KeyType::Enter));
        [$m2, ] = $m->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertSame($m, $m2);
    }

    public function testPlaceholderForwarded(): void
    {
        $m = InputModel::newPrompt('your name');
        $this->assertSame('your name', $m->input->placeholder);
    }

    public function testViewWithHeaderPrependsHeader(): void
    {
        $m = InputModel::newPrompt(header: 'Enter your name:');
        $view = $m->view();
        $this->assertStringStartsWith('Enter your name:', $view);
    }

    public function testViewWithoutHeaderIsJustBody(): void
    {
        $m = InputModel::newPrompt();
        $body = $m->input->view();
        $this->assertSame($body, $m->view());
    }

    public function testCharLimitRestrictsInput(): void
    {
        $m = InputModel::newPrompt(charLimit: 3);
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'a'));
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'b'));
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'c'));
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'd')); // should be ignored
        $this->assertSame('abc', $m->value());
    }

    public function testPreFilledValueIsEditable(): void
    {
        $m = InputModel::newPrompt(value: 'hello');
        $this->assertSame('hello', $m->value());
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertSame('hellox', $m->value());
    }
}
