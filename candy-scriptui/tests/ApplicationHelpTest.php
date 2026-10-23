<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\ScriptUi\Application;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * In-process pins for the docs-fix-1023 help paths:
 * - C2: bare / --version / --help invocations no longer route through
 *   find() with an empty command name (they used to die with an
 *   "ambiguous" CommandNotFoundException before the parent could act).
 * - M5: --show-help is a real --help alias (token injection).
 * - M2: HelpFormatter's #[Alias]/#[Example] block is rendered ahead of
 *   Symfony's help for commands that carry them, untouched otherwise.
 */
final class ApplicationHelpTest extends TestCase
{
    private string $previousLocale;

    protected function setUp(): void
    {
        $this->previousLocale = T::locale();
        T::setLocale('en');
    }

    protected function tearDown(): void
    {
        T::setLocale($this->previousLocale);
    }

    /** @param list<string> $argv */
    private static function runApp(array $argv, BufferedOutput $output): int
    {
        return (new Application())->run(new ArgvInput(array_merge(['candy-scriptui'], $argv)), $output);
    }

    public function testBareInvocationShowsTheListAndExitsZero(): void
    {
        $out = new BufferedOutput();

        $this->assertSame(0, self::runApp([], $out));
        $this->assertStringContainsString('choose', $out->fetch());
    }

    public function testVersionFlagIsHandledByTheParentAndExitsZero(): void
    {
        $out = new BufferedOutput();

        $this->assertSame(0, self::runApp(['--version'], $out));
        $this->assertStringContainsString('candy-scriptui', $out->fetch());
    }

    public function testBareHelpFlagExitsZero(): void
    {
        $out = new BufferedOutput();

        $this->assertSame(0, self::runApp(['--help'], $out));
    }

    public function testShowHelpIsARealHelpAliasOnArgvInput(): void
    {
        $out = new BufferedOutput();

        $this->assertSame(0, self::runApp(['style', '--show-help'], $out));
        $display = $out->fetch();
        $this->assertStringContainsString('Usage:', $display, '--show-help must render the command help');
        $this->assertStringContainsString('--foreground', $display);
    }

    /**
     * The CLI is ArgvInput in production; ArrayInput callers (tests, embedded
     * use) must get the same --show-help treatment. Symfony's run() pre-binds
     * the application definition, which a raw option-bearing ArrayInput can
     * never satisfy, so this exercises doRun() directly — the seam the fix
     * lives in — with a stub input carrying the first-argument contract.
     */
    public function testShowHelpWorksOnArrayInputToo(): void
    {
        $out = new BufferedOutput();
        $app = new Application();
        // Application is final (by design); ReflectionMethod is the honest way
        // to exercise the protected doRun seam without loosening the API.
        $doRun = new \ReflectionMethod($app, 'doRun');

        $code = $doRun->invoke($app, new ShowHelpArrayInput(), $out);

        $this->assertSame(0, $code);
        $display = $out->fetch();
        $this->assertStringContainsString('Usage:', $display);
        // The attribute help block must not double-render: --show-help is an
        // alias for --help, Symfony's help command still owns the full page.
        $this->assertSame(1, substr_count($display, 'Examples:'));
    }

    public function testExamplesBlockIsRenderedForAttributeCarryingCommands(): void
    {
        $out = new BufferedOutput();

        $this->assertSame(0, self::runApp(['style', '--help'], $out));
        $display = $out->fetch();
        $this->assertStringContainsString('Examples:', $display);
        $this->assertStringContainsString('candy-scriptui style --bold', $display);
    }

    public function testHelpForPlainCommandCarriesNoAttributeBlock(): void
    {
        $out = new BufferedOutput();

        // `completion` deliberately carries no #[Example]/#[Alias]; its help
        // must be Symfony stock only (no duplicate banner in front).
        $this->assertSame(0, self::runApp(['completion', '--help'], $out));
        $this->assertStringNotContainsString('Examples:', $out->fetch());
    }

    public function testTypoSuggestionIsRenderedExactlyOnce(): void
    {
        $out = new BufferedOutput();

        // 'confrim' is Levenshtein distance 2 from 'confirm' — outside
        // Symfony's own fuzzy window, inside TypoSuggester's.
        $this->assertSame(1, self::runApp(['confrim'], $out));
        $display = $out->fetch();
        $this->assertSame(
            1,
            substr_count($display, 'Did you mean'),
            'our message and Symfony alternatives must not stack two suggestions',
        );
        $this->assertStringContainsString('confirm', $display);
    }

    public function testAttributeInjectionPropertyNamesArePinned(): void
    {
        // The --show-help alias degrades to a no-op when Symfony renames the
        // private token containers; these pins make that rename CI-loud.
        $this->assertTrue(
            (new \ReflectionClass(ArgvInput::class))->hasProperty('tokens'),
            'ArgvInput::$tokens was renamed/removed — update Application::ARGV_TOKENS_PROPERTY',
        );
        $this->assertTrue(
            (new \ReflectionClass(ArrayInput::class))->hasProperty('parameters'),
            'ArrayInput::$parameters was renamed/removed — update Application::ARRAY_PARAMETERS_PROPERTY',
        );
    }
}

/**
 * Minimal streamable input standing in for `candy-scriptui style --show-help`:
 * reports the command name and the flag, accepts any bind, and never streams.
 */
final class ShowHelpArrayInput implements InputInterface, \Symfony\Component\Console\Input\StreamableInputInterface
{
    public function __toString(): string { return 'style --show-help'; }
    public function getFirstArgument(): string|null { return 'style'; }
    public function hasParameterOption(string|array $values, bool $onlyParams = false): bool { return $values === '--show-help' || $values === ['--help', '-h'] || (is_array($values) && in_array('--show-help', $values, true)); }
    public function getParameterOption(string|array $values, mixed $default = false, bool $onlyParams = false): mixed { return $default; }
    public function bind(\Symfony\Component\Console\Input\InputDefinition $definition): void {}
    public function validate(): void {}
    public function getArguments(): array { return []; }
    public function getArgument(string $name): mixed { return null; }
    public function setArgument(string $name, mixed $value): void {}
    public function hasArgument(string $name): bool { return false; }
    public function getOptions(): array { return []; }
    public function getOption(string $name): mixed { return $name === 'format' ? 'txt' : null; }
    public function setOption(string $name, mixed $value): void {}
    public function hasOption(string $name): bool { return false; }
    public function isInteractive(): bool { return false; }
    public function setInteractive(bool $interactive): void {}
    public function setStream($stream): void {}
    public function getStream() { return null; }
}

