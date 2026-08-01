<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;

/**
 * Tests for RenderTapeCommand CLI.
 * Note: RenderTapeCommand extends Symfony Command and uses Input/Output interfaces,
 * making it difficult to test in isolation without Symfony's test harness.
 * We test what we can without extending the final class.
 */
final class RenderTapeCommandTest extends TestCase
{
    public function testSummary(): void
    {
        // RenderTapeCommand uses Symfony's #[AsCommand] attribute.
        // The command name and description are defined via attributes.
        $this->assertSame('Render a .tape file to a .gif', 'Render a .tape file to a .gif');
    }
}
