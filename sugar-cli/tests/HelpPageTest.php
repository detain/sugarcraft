<?php

declare(strict_types=1);

namespace SugarCraft\Cli\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Cli\CommandSpec;
use SugarCraft\Cli\HelpPage;
use SugarCraft\Cli\HelpText;
use SugarCraft\Cli\Option;
use SugarCraft\Cli\Tests\Support\SampleSpec;
use SugarCraft\Cli\Tests\Support\SandboxTerminalColumns;
use SugarCraft\Cli\Theme;

final class HelpPageTest extends TestCase
{
    use SandboxTerminalColumns;

    protected function setUp(): void
    {
        $this->sandboxTerminalColumns();
    }

    protected function tearDown(): void
    {
        $this->restoreTerminalColumns();
    }

    public function testRootPageListsCommandsOptionsAndGroups(): void
    {
        $out = HelpText::fromSpec(SampleSpec::candyCode())->withTheme(Theme::plain())->withWidth(80)->render();

        self::assertSame(
            "USAGE\n"
            . "  candy-code [options] <command>\n"
            . "\n"
            . "An agentic coding assistant.\n"
            . "\n"
            . "COMMANDS\n"
            . "  run     Run one prompt\n"
            . "  config  Read settings\n"
            . "  doctor  Check the install's health\n"
            . "\n"
            . "OPTIONS\n"
            . "  -v, --verbose...    Print more detail\n"
            . "      --json          Emit JSON\n"
            . "      --tag <TAG>...  Attach a tag\n"
            . "\n"
            . "MODEL\n"
            . "  -m, --model <MODEL>    Model to use [env: CANDY_CODE_MODEL]\n"
            . "      --effort <EFFORT>  Reasoning effort [default: low] [possible values: low,\n"
            . "                         high]",
            $out,
        );
    }

    public function testSubcommandPageShowsRequiredOptionAndInheritedPersistentOption(): void
    {
        $out = HelpText::fromSpec(SampleSpec::candyCode(), 'run')->withTheme(Theme::plain())->withWidth(80)->render();

        self::assertSame(
            "USAGE\n"
            . "  candy-code run [options] --max-turns <MAX_TURNS> <arg>\n"
            . "\n"
            . "Run one prompt\n"
            . "\n"
            . "OPTIONS\n"
            . "  --max-turns <MAX_TURNS>  Turn limit [required]\n"
            . "\n"
            . "GLOBAL OPTIONS\n"
            . "  -v, --verbose...  Print more detail",
            $out,
        );
    }

    public function testNestedSubcommandPage(): void
    {
        $page = HelpText::fromSpec(SampleSpec::candyCode(), 'config', 'get');

        self::assertSame('get', $page->command()->name());
        self::assertSame('candy-code config get [options] <arg>', $page->usage());
        self::assertSame(
            "USAGE\n"
            . "  candy-code config get [options] <arg>\n"
            . "\n"
            . "Print one setting\n"
            . "\n"
            . "GLOBAL OPTIONS\n"
            . "  -v, --verbose...  Print more detail",
            $page->withTheme(Theme::plain())->withWidth(null)->render(),
        );
    }

    public function testFromSpecIsHelpPageNew(): void
    {
        $spec = SampleSpec::candyCode();

        self::assertEquals(HelpPage::new($spec, 'run'), HelpText::fromSpec($spec, 'run'));
    }

    public function testUnknownSubcommandPathThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Command "candy-code config" has no subcommand "set".');

        HelpText::fromSpec(SampleSpec::candyCode(), 'config', 'set');
    }

    public function testSectionsAreTheRenderInput(): void
    {
        $sections = HelpText::fromSpec(SampleSpec::candyCode(), 'run')->sections();

        self::assertSame([
            'options' => ['--max-turns <MAX_TURNS>' => 'Turn limit [required]'],
            'global options' => ['-v, --verbose...' => 'Print more detail'],
        ], $sections);
    }

    public function testLabelsReplaceEveryAddedWord(): void
    {
        $spec = CommandSpec::new('app')
            ->withOption(Option::value('mode')->withEnv('APP_MODE')->withChoices(['a', 'b'])->withDefault('a')->withRequired(false))
            ->withOption(Option::value('key')->withRequired())
            ->withSubcommand(CommandSpec::new('go'))
            ->withMaxArguments(1);

        $out = HelpText::fromSpec($spec)
            ->withLabels([
                'usage' => 'Aufruf',
                'commands' => 'Befehle',
                'options' => 'Optionen',
                'required' => 'Pflicht',
                'env' => 'Umgebung',
                'default' => 'Vorgabe',
                'possible values' => 'Werte',
                'command' => 'befehl',
                'arg' => 'datei',
            ])
            ->withTitleCase(false)
            ->withTheme(Theme::plain())
            ->withWidth(null)
            ->render();

        self::assertSame(
            "Aufruf\n"
            . "  app [Optionen] --key <KEY> [<befehl>] [<datei>]\n"
            . "\n"
            . "Befehle\n"
            . "  go  \n"
            . "\n"
            . "Optionen\n"
            . "  --mode <MODE>  [Umgebung: APP_MODE] [Vorgabe: a] [Werte: a, b]\n"
            . "  --key <KEY>    [Pflicht]",
            $out,
        );
    }

    public function testUnknownLabelThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown help label "flags"');

        HelpText::fromSpec(SampleSpec::candyCode())->withLabels(['flags' => 'Flags']);
    }

    public function testWidthBelowOneThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HelpText::fromSpec(SampleSpec::candyCode())->withWidth(0);
    }

    public function testWithersReturnNewInstances(): void
    {
        $page = HelpText::fromSpec(SampleSpec::candyCode());

        self::assertNotSame($page, $page->withTheme(Theme::plain()));
        self::assertNotSame($page, $page->withWidth(40));
        self::assertNotSame($page, $page->withLabels(['usage' => 'u']));
        self::assertNotSame($page, $page->withTitleCase(false));
    }

    public function testDefaultsRenderAsHelpText(): void
    {
        $spec = CommandSpec::new('app')
            ->withOption(Option::flag('on')->withDefault(true))
            ->withOption(Option::flag('off')->withDefault(false))
            ->withOption(Option::value('n')->withDefault(3))
            ->withOption(Option::value('empty')->withDefault(''))
            ->withOption(Option::value('list')->withRepeatable()->withDefault(['a', 'b']));

        $rows = HelpText::fromSpec($spec)->sections()['options'];

        self::assertSame([
            '--on' => '[default: true]',
            '--off' => '',
            '--n <N>' => '[default: 3]',
            '--empty <EMPTY>' => '[default: ""]',
            '--list <LIST>...' => '[default: a, b]',
        ], $rows);
    }

    public function testArgumentCountsShapeTheSynopsis(): void
    {
        $usage = static fn (CommandSpec $spec): string => HelpText::fromSpec($spec)->usage();

        self::assertSame('app [<arg>...]', $usage(CommandSpec::new('app')));
        self::assertSame('app', $usage(CommandSpec::new('app')->withMaxArguments(0)));
        self::assertSame('app <arg> <arg>', $usage(CommandSpec::new('app')->withMinArguments(2)->withMaxArguments(2)));
        self::assertSame('app <arg> [<arg>]', $usage(CommandSpec::new('app')->withMinArguments(1)->withMaxArguments(2)));
        self::assertSame('app <arg> [<arg>...]', $usage(CommandSpec::new('app')->withMinArguments(1)));
    }
}
