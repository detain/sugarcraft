<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cli\RenderTapeCommand;

/**
 * Tests for RenderTapeCommand CLI.
 * RenderTapeCommand extends Symfony Command; its name and description come
 * from the #[AsCommand] attribute, so we assert them off a live instance
 * instead of comparing string literals to themselves.
 */
final class RenderTapeCommandTest extends TestCase
{
    public function testSummary(): void
    {
        $command = new RenderTapeCommand();

        $this->assertSame('render-tape', $command->getName());
        $this->assertSame('Render a .tape file to a .gif', $command->getDescription());
    }
}
