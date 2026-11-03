<?php

declare(strict_types=1);

namespace SugarCraft\MdView\Tests;

use SugarCraft\Core\I18n\T;
use SugarCraft\MdView\Application;
use SugarCraft\MdView\RenderCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application as SymfonyApplication;

final class ApplicationTest extends TestCase
{
    private string $savedLocale = 'en';

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        $this->savedLocale = T::locale();
        // T::detect() reads $_SERVER FIRST, so a stale inherited LANG=en_US
        // under putenv() would shadow the test value.
        foreach (['LC_ALL', 'LC_MESSAGES', 'LANG'] as $key) {
            $this->savedEnv[$key] = getenv($key);
            putenv($key);
            unset($_SERVER[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_SERVER[$key]);
            } else {
                putenv($key . '=' . $value);
                $_SERVER[$key] = $value;
            }
        }
        T::setLocale($this->savedLocale);
    }
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

    public function testConstructorAdoptsDetectedLocale(): void
    {
        // KM6: building the application must adopt the environment's
        // locale, not leave T pinned to 'en' while sixteen locales ship.
        // T::normalize() keeps the region ("de_DE.UTF-8" → "de-de"), and
        // the lookup ladder (exact → base → en) falls back to de.php.
        putenv('LANG=de_DE.UTF-8');
        $_SERVER['LANG'] = 'de_DE.UTF-8';
        new Application();
        $this->assertSame('de-de', T::locale());
    }
}
