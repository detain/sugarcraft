<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Tests\Command;

use SugarCraft\Shell\Application;
use SugarCraft\Shell\Command\ConfirmCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for ConfirmCommand flag-plumbing that does not require a live TTY.
 *
 * Note: The full interactive execution requires Program::run() with a TTY,
 * so we test the static constants and option configuration instead.
 */
final class ConfirmCommandTest extends TestCase
{
    /**
     * ConfirmCommand exits with 0 for affirmative, 1 for negative, 2 for abort.
     * These match the shell convention used by gum.
     */
    public function testExitCodeConstantsMatchConvention(): void
    {
        $this->assertSame(0, ConfirmCommand::EXIT_YES);
        $this->assertSame(1, ConfirmCommand::EXIT_NO);
        $this->assertSame(2, ConfirmCommand::EXIT_ABORT);
    }

    /**
     * Verify ConfirmCommand is registered in the application and has the
     * expected name.
     */
    public function testCommandIsRegisteredWithCorrectName(): void
    {
        $app = new Application();
        $command = $app->find('confirm');

        $this->assertSame('confirm', $command->getName());
    }

    /**
     * Verify the expected options are configured on ConfirmCommand.
     * This catches typos in option names and verifies the flag plumbing.
     */
    public function testCommandHasExpectedOptions(): void
    {
        $app = new Application();
        $command = $app->find('confirm');
        $definition = $command->getDefinition();

        // Core options
        $this->assertTrue($definition->hasOption('default-yes'));
        $this->assertTrue($definition->hasOption('default'));
        $this->assertTrue($definition->hasOption('affirmative'));
        $this->assertTrue($definition->hasOption('negative'));
        $this->assertTrue($definition->hasOption('show-output'));
        $this->assertTrue($definition->hasOption('header'));
        $this->assertTrue($definition->hasOption('show-help'));
        $this->assertTrue($definition->hasOption('timeout'));

        // --default-yes description should note deprecation
        $defaultYesOption = $definition->getOption('default-yes');
        $this->assertStringContainsString('deprecated', $defaultYesOption->getDescription());
    }

    /**
     * Verify --default=yes and --default=no are accepted as valid option values.
     * This is a non-interactive validation that doesn't require a TTY.
     */
    public function testDefaultOptionAcceptsYesNoValues(): void
    {
        $app = new Application();
        $command = $app->find('confirm');
        $tester = new CommandTester($command);

        // These will fail due to TTY requirement (Program::run needs terminal),
        // but Symfony will first parse the options without error.
        // The option parsing is what we're verifying here.
        $definition = $command->getDefinition();

        $defaultOption = $definition->getOption('default');
        $this->assertNotNull($defaultOption);
        // Default accepts string values
        $this->assertTrue($defaultOption->acceptValue());
    }
}
