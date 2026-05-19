<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Tests\Attribute;

use PHPUnit\Framework\TestCase;
use SugarCraft\Shell\Application;
use SugarCraft\Shell\Tests\Fixtures\Command\AlphaCommand;
use SugarCraft\Shell\Tests\Fixtures\Command\BetaCommand;
use Symfony\Component\Console\Command\Command;

final class CommandScannerTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        $this->app = new Application();
    }

    public function testScannerFindsCommandAttributedClasses(): void
    {
        require_once __DIR__ . '/../Fixtures/Command/AlphaCommand.php';
        require_once __DIR__ . '/../Fixtures/Command/BetaCommand.php';

        $discovered = $this->app->scan(
            \SugarCraft\Shell\Tests\Fixtures\Command::class
        );

        $this->assertContains(AlphaCommand::class, $discovered);
        $this->assertContains(BetaCommand::class, $discovered);
        $this->assertCount(2, $discovered);
    }

    public function testScannedCommandsAreRegisteredInApplication(): void
    {
        require_once __DIR__ . '/../Fixtures/Command/AlphaCommand.php';
        require_once __DIR__ . '/../Fixtures/Command/BetaCommand.php';

        $this->app->scan(\SugarCraft\Shell\Tests\Fixtures\Command::class);

        $alpha = $this->app->find('alpha');
        $beta = $this->app->find('beta');

        $this->assertInstanceOf(Command::class, $alpha);
        $this->assertInstanceOf(Command::class, $beta);
        $this->assertSame('alpha', $alpha->getName());
        $this->assertSame('beta', $beta->getName());
    }

    public function testScannedCommandHasDescription(): void
    {
        require_once __DIR__ . '/../Fixtures/Command/AlphaCommand.php';

        $this->app->scan(\SugarCraft\Shell\Tests\Fixtures\Command::class);

        $alpha = $this->app->find('alpha');
        $this->assertSame('Alpha test command.', $alpha->getDescription());
    }
}
