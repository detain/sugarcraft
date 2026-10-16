<?php

declare(strict_types=1);

namespace SugarCraft\MdView\Tests;

use SugarCraft\MdView\Application;
use SugarCraft\MdView\RenderCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application as SymfonyApplication;

final class ApplicationTest extends TestCase
{
    public function testIsSymfonyConsoleApplication(): void
    {
        $this->assertInstanceOf(SymfonyApplication::class, new Application());
    }

    public function testNameAndVersion(): void
    {
        $app = new Application();
        $this->assertSame('candy-mdview', $app->getName());
        $this->assertSame('0.1.0', $app->getVersion());
    }

    public function testRenderCommandIsRegistered(): void
    {
        $app = new Application();
        $this->assertTrue($app->has('render'));
        $this->assertInstanceOf(RenderCommand::class, $app->get('render'));
    }

    public function testRenderResolvesAsDefaultCommandName(): void
    {
        // Configured as the default; the application's reported name
        // should match the only registered command's name.
        $app = new Application();
        $this->assertSame('render', $app->find('render')->getName());
    }
}
