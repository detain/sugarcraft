<?php

declare(strict_types=1);

namespace CandyCore\Wish\Tests\Middleware;

use CandyCore\Wish\Middleware\BubbleTea;
use CandyCore\Wish\Session;
use PHPUnit\Framework\TestCase;

final class BubbleTeaTest extends TestCase
{
    private function session(): Session
    {
        return new Session(
            user: 'alice', clientHost: '127.0.0.1', clientPort: 1, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm', cols: 80, rows: 24, tty: null,
            command: null, lang: 'C.UTF-8',
        );
    }

    public function testFactoryReceivesSessionAndProgramRunInvoked(): void
    {
        $observed = null;
        $ran      = false;
        $factory = function (Session $s) use (&$observed, &$ran) {
            $observed = $s;
            return new class($ran) {
                public function __construct(private bool &$ran) {}
                public function run(): void { $this->ran = true; }
            };
        };
        $mw = new BubbleTea($factory);
        $mw->handle($this->session(), fn() => null);
        $this->assertNotNull($observed);
        $this->assertSame('alice', $observed->user);
        $this->assertTrue($ran);
    }

    public function testRejectsFactoryReturningNonRunnable(): void
    {
        $mw = new BubbleTea(fn() => new \stdClass());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('run()');
        $mw->handle($this->session(), fn() => null);
    }
}
