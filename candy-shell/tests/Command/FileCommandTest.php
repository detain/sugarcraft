<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Tests\Command;

use SugarCraft\Shell\Application;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FileCommand flag-plumbing that does not require a live TTY.
 *
 * Note: The full interactive execution requires Program::run() with a TTY.
 * These tests verify option configuration.
 */
final class FileCommandTest extends TestCase
{
    /**
     * Verify FileCommand is registered in the application and has the
     * expected name.
     */
    public function testCommandIsRegisteredWithCorrectName(): void
    {
        $app = new Application();
        $command = $app->find('file');

        $this->assertSame('file', $command->getName());
    }

    /**
     * Verify the expected options are configured on FileCommand.
     * This catches typos in option names and verifies the flag plumbing.
     */
    public function testCommandHasExpectedOptions(): void
    {
        $app = new Application();
        $command = $app->find('file');
        $definition = $command->getDefinition();

        // Core options
        $this->assertTrue($definition->hasOption('height'));
        $this->assertTrue($definition->hasOption('header'));
        $this->assertTrue($definition->hasOption('all'));
        $this->assertTrue($definition->hasOption('directory'));
        $this->assertTrue($definition->hasOption('file'));
        $this->assertTrue($definition->hasOption('show-size'));
        $this->assertTrue($definition->hasOption('show-help'));
        $this->assertTrue($definition->hasOption('timeout'));
    }

    /**
     * Verify --all (-a) is a flag (no value required).
     */
    public function testAllOptionIsFlag(): void
    {
        $app = new Application();
        $command = $app->find('file');
        $definition = $command->getDefinition();

        $option = $definition->getOption('all');
        $this->assertNotNull($option);
        $this->assertFalse($option->acceptValue());
    }

    /**
     * Verify --directory is a flag (no value required).
     */
    public function testDirectoryOptionIsFlag(): void
    {
        $app = new Application();
        $command = $app->find('file');
        $definition = $command->getDefinition();

        $option = $definition->getOption('directory');
        $this->assertNotNull($option);
        $this->assertFalse($option->acceptValue());
    }

    /**
     * Verify --show-size is a flag (no value required).
     */
    public function testShowSizeOptionIsFlag(): void
    {
        $app = new Application();
        $command = $app->find('file');
        $definition = $command->getDefinition();

        $option = $definition->getOption('show-size');
        $this->assertNotNull($option);
        $this->assertFalse($option->acceptValue());
    }
}
