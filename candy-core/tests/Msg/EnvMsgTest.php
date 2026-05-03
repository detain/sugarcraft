<?php

declare(strict_types=1);

namespace CandyCore\Core\Tests\Msg;

use CandyCore\Core\Msg\EnvMsg;
use PHPUnit\Framework\TestCase;

final class EnvMsgTest extends TestCase
{
    public function testGetReturnsSetValue(): void
    {
        $msg = new EnvMsg(['HOME' => '/home/user', 'TERM' => 'xterm-256color']);
        $this->assertSame('/home/user', $msg->get('HOME'));
        $this->assertSame('xterm-256color', $msg->get('TERM'));
    }

    public function testGetReturnsNullForUnknownKey(): void
    {
        $msg = new EnvMsg([]);
        $this->assertNull($msg->get('UNKNOWN_KEY'));
    }

    public function testGetReturnsDefaultForUnknownKey(): void
    {
        $msg = new EnvMsg(['HOME' => '/home/user']);
        $this->assertSame('fallback', $msg->get('MISSING', 'fallback'));
    }

    public function testGetIgnoresDefaultWhenKeyExists(): void
    {
        $msg = new EnvMsg(['LANG' => 'en_US.UTF-8']);
        $this->assertSame('en_US.UTF-8', $msg->get('LANG', 'C'));
    }

    public function testEmptyStringValueIsReturnedNotDefault(): void
    {
        $msg = new EnvMsg(['EMPTY' => '']);
        $this->assertSame('', $msg->get('EMPTY', 'default'));
    }

    public function testVarsAreAccessible(): void
    {
        $vars = ['A' => '1', 'B' => '2'];
        $msg = new EnvMsg($vars);
        $this->assertSame($vars, $msg->vars);
    }
}
