<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\ScriptUi\Application;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * scriptui-68: the allow-listed, read-only GUM_<COMMAND>_<OPTION> mirror.
 */
final class GumEnvMirrorTest extends TestCase
{
    private const VARS = [
        'GUM_STYLE_FOREGROUND', 'GUM_STYLE_BOLD', 'GUM_STYLE_TEXT', 'GUM_FORMAT_FOREGROUND',
        'CANDY_SCRIPTUI_FOREGROUND', 'GUM_CONFIRM_DEFAULT', 'GUM_STYLE_ALLOW_ENV',
    ];

    protected function tearDown(): void
    {
        foreach (self::VARS as $name) {
            putenv($name);
        }
    }

    private function runStyle(string ...$args): string
    {
        $out = new BufferedOutput();
        $status = (new Application())->run(new ArgvInput(['candy-scriptui', 'style', ...$args, 'hello']), $out);
        self::assertSame(0, $status);

        return $out->fetch();
    }

    public function testGumVariableThemesTheMatchingCommand(): void
    {
        putenv('GUM_STYLE_FOREGROUND=#ff0000');

        self::assertStringContainsString("\x1b[38;2;255;0;0m", $this->runStyle());
    }

    public function testGumFlagVariableWorksForBooleanFlags(): void
    {
        putenv('GUM_STYLE_BOLD=1');

        self::assertStringContainsString("\x1b[1m", $this->runStyle());
    }

    public function testMirrorIsCommandScoped(): void
    {
        putenv('GUM_FORMAT_FOREGROUND=#ff0000');

        self::assertStringNotContainsString("\x1b[38;2;255;0;0m", $this->runStyle());
    }

    public function testExplicitFlagBeatsEveryVariable(): void
    {
        putenv('GUM_STYLE_FOREGROUND=#ff0000');
        putenv('CANDY_SCRIPTUI_FOREGROUND=#00ff00');

        self::assertStringContainsString("\x1b[38;2;0;0;255m", $this->runStyle('--foreground=#0000ff'));
    }

    public function testNativeVariableBeatsTheGumMirror(): void
    {
        putenv('GUM_STYLE_FOREGROUND=#ff0000');
        putenv('CANDY_SCRIPTUI_FOREGROUND=#00ff00');

        $display = $this->runStyle();

        self::assertStringContainsString("\x1b[38;2;0;255;0m", $display);
        self::assertStringNotContainsString("\x1b[38;2;255;0;0m", $display);
    }

    public function testNonAllowListedGumVariablesAreIgnored(): void
    {
        // Fail-closed: capability and answer options never read the env, gum
        // spelling included. `text` is an argument, not even an option.
        putenv('GUM_STYLE_ALLOW_ENV=*');
        putenv('GUM_CONFIRM_DEFAULT=yes');

        self::assertSame('hello', Ansi::strip(trim($this->runStyle())));
        foreach (['allow-env', 'default', 'file', 'value', 'selected', 'out', 'type', 'shell', 'password'] as $forbidden) {
            self::assertNotContains($forbidden, Application::GUM_ENV_MIRROR_OPTIONS);
        }
    }

    public function testMirrorRosterIsTheNativeRosterPlusCursor(): void
    {
        $expected = [...Application::ENV_FALLBACK_OPTIONS, 'cursor'];
        sort($expected);
        $actual = Application::GUM_ENV_MIRROR_OPTIONS;
        sort($actual);

        self::assertSame($expected, $actual);
    }

    public function testVariableShapeIsGumsCommandScopedUpperSnake(): void
    {
        $option = (new Application())->find('choose')->getDefinition()->getOption('cursor-prefix');

        self::assertSame('GUM_CHOOSE_CURSOR_PREFIX', Application::gumEnvVar('choose', $option));
    }

    /**
     * Disclosure drift guard: the README table lists exactly the options the
     * mirror reads, each with the commands that define it.
     */
    public function testReadmeDisclosesTheMirrorTable(): void
    {
        $readme = (string) file_get_contents(__DIR__ . '/../README.md');
        $app = new Application();
        foreach (Application::GUM_ENV_MIRROR_OPTIONS as $name) {
            $commands = [];
            foreach ($app->all() as $command) {
                if ($command->getDefinition()->hasOption($name)) {
                    $commands[] = '`' . $command->getName() . '`';
                }
            }
            if ($commands === []) {
                continue;
            }
            sort($commands);
            $row = '| `' . $name . '` | `GUM_<COMMAND>_' . strtoupper(str_replace('-', '_', $name)) . '` | ' . implode(', ', $commands) . ' |';
            self::assertStringContainsString($row, $readme, "README mirror table is missing or stale for --{$name}");
        }
    }
}
