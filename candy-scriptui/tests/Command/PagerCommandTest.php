<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Command;

use SugarCraft\ScriptUi\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for PagerCommand flag-plumbing that does not require a live TTY.
 *
 * Note: The full interactive execution requires Program::run() with a TTY.
 * These tests verify option configuration.
 */
final class PagerCommandTest extends TestCase
{
    /**
     * Verify PagerCommand is registered in the application and has the
     * expected name.
     */
    public function testCommandIsRegisteredWithCorrectName(): void
    {
        $app = new Application();
        $command = $app->find('pager');

        $this->assertSame('pager', $command->getName());
    }

    /**
     * Verify the expected options are configured on PagerCommand.
     * This catches typos in option names and verifies the flag plumbing.
     */
    public function testCommandHasExpectedOptions(): void
    {
        $app = new Application();
        $command = $app->find('pager');
        $definition = $command->getDefinition();

        // Core options
        $this->assertTrue($definition->hasOption('file'));
        $this->assertTrue($definition->hasOption('width'));
        $this->assertTrue($definition->hasOption('height'));
        $this->assertTrue($definition->hasOption('show-line-numbers'));
        $this->assertTrue($definition->hasOption('match'));
        $this->assertTrue($definition->hasOption('soft-wrap'));
        $this->assertTrue($definition->hasOption('show-help'));
        $this->assertTrue($definition->hasOption('timeout'));
    }

    /**
     * Verify --file (-f) accepts a value (the file path).
     */
    public function testFileOptionAcceptsValue(): void
    {
        $app = new Application();
        $command = $app->find('pager');
        $definition = $command->getDefinition();

        $option = $definition->getOption('file');
        $this->assertNotNull($option);
        $this->assertTrue($option->acceptValue());
    }

    /**
     * Verify --show-line-numbers is a flag (no value required).
     */
    public function testShowLineNumbersOptionIsFlag(): void
    {
        $app = new Application();
        $command = $app->find('pager');
        $definition = $command->getDefinition();

        $option = $definition->getOption('show-line-numbers');
        $this->assertNotNull($option);
        $this->assertFalse($option->acceptValue());
    }

    /**
     * Verify --soft-wrap is a flag (no value required).
     */
    public function testSoftWrapOptionIsFlag(): void
    {
        $app = new Application();
        $command = $app->find('pager');
        $definition = $command->getDefinition();

        $option = $definition->getOption('soft-wrap');
        $this->assertNotNull($option);
        $this->assertFalse($option->acceptValue());
    }


    public function testMissingFileFailsWithLocalizedMessageNotFatal(): void
    {
        $previous = \SugarCraft\Core\I18n\T::locale();
        \SugarCraft\Core\I18n\T::setLocale('en');
        try {
            $tester = new CommandTester(new \SugarCraft\ScriptUi\Command\PagerCommand());
            $status = $tester->execute(
                ['--file' => '/definitely/not/here.phpunit-' . getmypid()],
                ['decorated' => false],
            );
        } finally {
            \SugarCraft\Core\I18n\T::setLocale($previous);
        }

        $display = $tester->getDisplay();
        $this->assertSame(1, $status);
        $this->assertStringContainsString('could not read file:', $display);
        // Before the Lang import fix this path fataled: "Class ... not found".
        $this->assertStringNotContainsString('Class "', $display);
    }
}
