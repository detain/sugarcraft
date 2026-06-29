<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Tests\Command;

use PHPUnit\Framework\TestCase;
use SugarCraft\Shell\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for ChooseCommand flag-plumbing that does not require a live TTY.
 */
final class ChooseCommandTest extends TestCase
{
    /**
     * When exactly one option is supplied and --select-if-one is set,
     * the command short-circuits to SUCCESS without entering Program::run().
     * This is the primary non-interactive code path exercised by the flag.
     */
    public function testSelectIfOneShortCircuitsWithSingleOption(): void
    {
        $app = new Application();
        $command = $app->find('choose');
        $tester = new CommandTester($command);

        $status = $tester->execute([
            'options' => ['only-option'],
            '--select-if-one' => true,
        ], ['decorated' => false]);

        $this->assertSame(0, $status);
        $this->assertSame('only-option', trim($tester->getDisplay()));
    }

    /**
     * --cursor-prefix is accepted as a valid option (aliasing --cursor).
     * The alias resolution is tested by verifying the flag is parsed without
     * error even when combined with --select-if-one.
     */
    public function testCursorPrefixFlagIsAccepted(): void
    {
        $app = new Application();
        $command = $app->find('choose');
        $tester = new CommandTester($command);

        $status = $tester->execute([
            'options' => ['only-option'],
            '--select-if-one' => true,
            '--cursor-prefix' => '[CURSOR] ',
        ], ['decorated' => false]);

        // With --select-if-one the short-circuit fires; cursor-prefix is accepted.
        $this->assertSame(0, $status);
        $this->assertSame('only-option', trim($tester->getDisplay()));
    }

    /**
     * --unselected-prefix is accepted as a valid option.
     */
    public function testUnselectedPrefixFlagIsAccepted(): void
    {
        $app = new Application();
        $command = $app->find('choose');
        $tester = new CommandTester($command);

        $status = $tester->execute([
            'options' => ['only-option'],
            '--select-if-one' => true,
            '--unselected-prefix' => '[ ] ',
        ], ['decorated' => false]);

        $this->assertSame(0, $status);
        $this->assertSame('only-option', trim($tester->getDisplay()));
    }
}
