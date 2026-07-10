<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use SugarCraft\Testing\TestResult;

final class TestResultTest extends TestCase
{
    /**
     * @param list<\Closure> $cmds
     */
    private function makeResult(array $cmds): TestResult
    {
        return new TestResult(
            model: new \stdClass(),
            view: '',
            cmds: $cmds,
            output: '',
        );
    }

    public function testAssertCmdCountPassesOnExactCount(): void
    {
        $this->makeResult([static fn () => null, static fn () => null])->assertCmdCount(2);
        $this->assertTrue(true);
    }

    public function testAssertCmdCountFailsWithAssertionErrorOnMismatch(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/Expected 1 commands, but got 2/');
        $this->makeResult([static fn () => null, static fn () => null])->assertCmdCount(1);
    }

    public function testAssertNoCmdsPassesWhenEmpty(): void
    {
        $this->makeResult([])->assertNoCmds();
        $this->assertTrue(true);
    }

    public function testAssertNoCmdsFailsWithAssertionErrorWhenCmdsPresent(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/Expected no commands, but got 1/');
        $this->makeResult([static fn () => null])->assertNoCmds();
    }

    public function testAssertCmdContainsPassesWhenFilterMatches(): void
    {
        $marker = static fn () => null;
        $this->makeResult([static fn () => null, $marker])
            ->assertCmdContains(static fn (\Closure $cmd): bool => $cmd === $marker);
        $this->assertTrue(true);
    }

    public function testAssertCmdContainsFailsWithAssertionErrorWhenNoMatch(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/No command matched the given filter/');
        $this->makeResult([static fn () => null])
            ->assertCmdContains(static fn (\Closure $cmd): bool => false);
    }
}
