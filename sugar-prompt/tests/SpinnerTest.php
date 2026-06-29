<?php

declare(strict_types=1);

namespace SugarCraft\Prompt\Tests;

use SugarCraft\Bits\Spinner\Style as SpinnerStyle;
use SugarCraft\Prompt\Spinner;
use PHPUnit\Framework\TestCase;

final class SpinnerTest extends TestCase
{
    public function testNoOpWithoutAction(): void
    {
        // Without an action, run() returns immediately and doesn't error.
        Spinner::new()->withTitle('idle')->run();
        $this->assertTrue(true);
    }

    public function testRunInvokesAction(): void
    {
        $ran = false;
        $s = Spinner::new()
            ->withTitle('working')
            ->withStyle(SpinnerStyle::line())
            ->withAction(function () use (&$ran) {
                // Trivial action to keep the test fast.
                $ran = true;
            });
        // On systems without pcntl (or where fork fails) the action runs
        // inline. On systems with pcntl the parent waits for the child.
        // Either way, after run() the action should have executed.
        $s->run();
        // pcntl_fork case: the parent never observes $ran (child mutates
        // its own copy), so allow either branch.
        if (!function_exists('pcntl_fork')) {
            $this->assertTrue($ran);
        } else {
            $this->assertTrue(true);
        }
    }

    public function testWithersAreImmutable(): void
    {
        $a = Spinner::new();
        $b = $a->withTitle('x');
        $this->assertNotSame($a, $b);
    }

    public function testForkChildRunsActionViaSideChannel(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl required');
        }
        // On pcntl hosts the action runs in a forked child — in-memory
        // mutations are not visible to the parent. Demonstrate the
        // documented out-of-band communication pattern (tempfile).
        $tmp = tempnam(sys_get_temp_dir(), 'spinner_test_');
        $s = Spinner::new()
            ->withTitle('working')
            ->withStyle(SpinnerStyle::line())
            ->withAction(static function () use ($tmp) {
                file_put_contents($tmp, 'ran');
            });
        $s->run();
        $this->assertFileExists($tmp);
        $this->assertSame('ran', file_get_contents($tmp));
        unlink($tmp);
    }

    public function testRunReraisesActionFailure(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl required');
        }
        $s = Spinner::new()
            ->withTitle('failing')
            ->withStyle(SpinnerStyle::line())
            ->withAction(static function () {
                throw new \RuntimeException('boom');
            });
        $this->expectException(\RuntimeException::class);
        $s->run();
    }
}
