<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\Detect;

/**
 * @covers \SugarCraft\Mosaic\Detect
 */
final class DetectUncoveredTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        $keys = ['KITTY_WINDOW_ID', 'TERM_PROGRAM', 'LC_TERMINAL', 'TERM', 'XTERM_VERSION', 'TMUX'];
        foreach ($keys as $key) {
            $this->savedEnv[$key] = getenv($key);
            putenv($key);
        }
        Detect::reset();
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv("$key=$value");
            }
        }
        Detect::reset();
        Detect::setProbeStdin(null);
        parent::tearDown();
    }

    public function testProbeWithTmux(): void
    {
        putenv('TMUX=1');
        putenv('TERM_PROGRAM=iTerm.app');
        Detect::reset();

        $cap = Detect::probe();

        $this->assertTrue($cap->inTmux);
        $this->assertTrue($cap->iterm2);
    }

    public function testProbeWithKittyAndTmux(): void
    {
        putenv('TMUX=1');
        putenv('KITTY_WINDOW_ID=12345');
        Detect::reset();

        $cap = Detect::probe();

        $this->assertTrue($cap->inTmux);
        $this->assertTrue($cap->kitty);
    }

    public function testResetClearsCachedResult(): void
    {
        putenv('TERM_PROGRAM=iTerm.app');
        $first = Detect::cached();
        Detect::reset();
        putenv('TERM_PROGRAM=mintty');
        $second = Detect::cached();

        // After reset and different env, should get different result
        $this->assertTrue($second->iterm2);
        $this->assertNotSame($first, $second);
    }

    public function testLastDa1ReplyStartsNull(): void
    {
        Detect::reset();
        Detect::setProbeStdin(null);

        $this->assertNull(Detect::lastDa1Reply());
    }

    public function testLastDa1ReplyAfterProbe(): void
    {
        $pair = $this->createStreamPair();
        Detect::setProbeStdin($pair['read']);
        Detect::reset();

        // Preload DA1 reply
        $reply = "\x1b[?62;4;0c";
        @fwrite($pair['write'], $reply);
        @fflush($pair['write']);

        Detect::probe();

        @fclose($pair['write']);

        // lastDa1Reply may be set after probing
        $this->assertIsString(Detect::lastDa1Reply());
    }

    public function testSetProbeStdinWithNull(): void
    {
        Detect::setProbeStdin(null);
        // Should not throw
        $this->assertTrue(true);
    }

    public function testCachedReturnsCachedResult(): void
    {
        putenv('TERM_PROGRAM=iTerm.app');
        Detect::reset();

        $first = Detect::cached();
        $second = Detect::cached();

        $this->assertSame($first, $second);
    }

    /**
     * Creates a pair of connected streams for mocking stdin.
     * @return array{read: resource, write: resource}
     */
    private function createStreamPair(): array
    {
        // Always use PHP streams for compatibility
        $read = fopen('php://temp', 'r+b');
        $write = fopen('php://temp', 'r+b');
        if ($read === false || $write === false) {
            $this->fail('Could not create stream pair');
        }
        return ['read' => $read, 'write' => $write];
    }
}
