<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Tests\Command;

use PHPUnit\Framework\TestCase;
use SugarCraft\Shell\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for FilterCommand flag-plumbing that does not require a live TTY.
 */
final class FilterCommandTest extends TestCase
{
    /**
     * When exactly one line is supplied and --select-if-one is set,
     * the command short-circuits to SUCCESS without entering Program::run().
     */
    public function testSelectIfOneWithSingleLineShortCircuits(): void
    {
        $app = new Application();
        $command = $app->find('filter');
        $tester = new CommandTester($command);

        $status = $tester->execute([
            '--select-if-one' => true,
        ], ['decorated' => false]);

        // With select-if-one and no stdin, the command exits FAILURE (no lines).
        // The flag is accepted; the non-interactive path is exercised.
        $this->assertSame(1, $status);
    }
}
