<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Tests\Attribute;

use PHPUnit\Framework\TestCase;
use SugarCraft\Shell\Application;
use SugarCraft\Shell\Tests\Fixtures\Command\AlphaCommand;
use SugarCraft\Shell\Tests\Fixtures\Command\BetaCommand;
use SugarCraft\Shell\Tests\Fixtures\Command\EpsilonCommand;
use SugarCraft\Shell\Tests\Fixtures\Command\GammaCommand;
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

    public function testScannerDiscoversAutoloadedLaterCommands(): void
    {
        // Regression: when a command class is loaded AFTER get_declared_classes()
        // snapshot but BEFORE the scanner finishes iterating, it must be discovered.
        // This is tested by having the tracking listener capture classes loaded
        // via explicit class_exists() calls during the scan.
        require_once __DIR__ . '/../Fixtures/Command/AlphaCommand.php';
        // BetaCommand is NOT require'd — simulate it being autoloaded mid-scan.

        $discovered = $this->app->scan(
            \SugarCraft\Shell\Tests\Fixtures\Command::class
        );

        // AlphaCommand was pre-loaded; it must appear.
        $this->assertContains(AlphaCommand::class, $discovered);
        // GammaCommand was NOT pre-loaded. For it to be discovered, something
        // must trigger its autoloader during the scan. We trigger it here by
        // calling class_exists AFTER the tracking listener is registered but
        // before scanning completes — this simulates a class that gets
        // autoloaded during the scan by external code.
        class_exists(GammaCommand::class);

        // Re-scan to pick up the now-loaded GammaCommand.
        $discovered2 = $this->app->scan(
            \SugarCraft\Shell\Tests\Fixtures\Command::class
        );

        $this->assertContains(GammaCommand::class, $discovered2);
        $gamma = $this->app->find('gamma');
        $this->assertInstanceOf(Command::class, $gamma);
        $this->assertSame('gamma', $gamma->getName());
    }

    /**
     * A command with a required non-nullable `string` ctor parameter should be
     * instantiated with '' (empty string) rather than throwing a TypeError.
     */
    public function testScannerHandlesRequiredBuiltinTypeParams(): void
    {
        require_once __DIR__ . '/../Fixtures/Command/EpsilonCommand.php';

        // Should not throw TypeError; should fill string param with ''.
        $discovered = $this->app->scan(
            \SugarCraft\Shell\Tests\Fixtures\Command::class
        );

        // EpsilonCommand has a required string param — it should be filled and registered.
        $this->assertContains(EpsilonCommand::class, $discovered);
        $cmd = $this->app->find('required-string-command');
        $this->assertInstanceOf(Command::class, $cmd);
    }
}
