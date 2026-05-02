<?php

declare(strict_types=1);

namespace CandyCore\Shell\Tests\Model;

use CandyCore\Bits\TextInput\EchoMode;
use CandyCore\Core\KeyType;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Shell\Model\InputModel;
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
}
