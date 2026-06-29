<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Tests\Command;

use SugarCraft\Shell\Application;
use PHPUnit\Framework\TestCase;

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
}
