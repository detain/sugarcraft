<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Tests\Command;

use SugarCraft\Shell\Application;
use PHPUnit\Framework\TestCase;

/**
 * Tests for WriteCommand flag-plumbing that does not require a live TTY.
 *
 * Note: The full interactive execution requires Program::run() with a TTY.
 * These tests verify option configuration.
 */
final class WriteCommandTest extends TestCase
{
    /**
     * Verify WriteCommand is registered in the application and has the
     * expected name.
     */
    public function testCommandIsRegisteredWithCorrectName(): void
    {
        $app = new Application();
        $command = $app->find('write');

        $this->assertSame('write', $command->getName());
    }

    /**
     * Verify the expected options are configured on WriteCommand.
     * This catches typos in option names and verifies the flag plumbing.
     */
    public function testCommandHasExpectedOptions(): void
    {
        $app = new Application();
        $command = $app->find('write');
        $definition = $command->getDefinition();

        // Core options
        $this->assertTrue($definition->hasOption('placeholder'));
        $this->assertTrue($definition->hasOption('width'));
        $this->assertTrue($definition->hasOption('height'));
        $this->assertTrue($definition->hasOption('value'));
        $this->assertTrue($definition->hasOption('char-limit'));
        $this->assertTrue($definition->hasOption('max-lines'));
        $this->assertTrue($definition->hasOption('prompt'));
        $this->assertTrue($definition->hasOption('show-line-numbers'));
        $this->assertTrue($definition->hasOption('show-cursor-line'));
        $this->assertTrue($definition->hasOption('header'));
        $this->assertTrue($definition->hasOption('end-of-buffer-character'));
        $this->assertTrue($definition->hasOption('cursor-mode'));
        $this->assertTrue($definition->hasOption('show-help'));
        $this->assertTrue($definition->hasOption('timeout'));
    }

    /**
     * Verify --show-line-numbers is a flag (no value required).
     */
    public function testShowLineNumbersOptionIsFlag(): void
    {
        $app = new Application();
        $command = $app->find('write');
        $definition = $command->getDefinition();

        $option = $definition->getOption('show-line-numbers');
        $this->assertNotNull($option);
        $this->assertFalse($option->acceptValue());
    }

    /**
     * Verify --show-cursor-line is a flag (no value required).
     */
    public function testShowCursorLineOptionIsFlag(): void
    {
        $app = new Application();
        $command = $app->find('write');
        $definition = $command->getDefinition();

        $option = $definition->getOption('show-cursor-line');
        $this->assertNotNull($option);
        $this->assertFalse($option->acceptValue());
    }
}
