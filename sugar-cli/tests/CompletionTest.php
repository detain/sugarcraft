<?php

declare(strict_types=1);

namespace SugarCraft\Cli\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Cli\CommandSpec;
use SugarCraft\Cli\Completion;
use SugarCraft\Cli\Option;
use SugarCraft\Cli\Shell;
use SugarCraft\Cli\Tests\Support\SampleSpec;

/**
 * The scripts are pinned byte for byte against tests/fixtures/completion-*.golden.
 * When this file landed, each fixture was checked with the shell's own
 * parser (`bash -n`, `zsh -n`, `fish -n`) and driven through its walk with
 * stubbed completion builtins; the pins keep that verified text from moving.
 */
final class CompletionTest extends TestCase
{
    /** @return iterable<string, array{Shell}> */
    public static function shells(): iterable
    {
        foreach (Shell::cases() as $shell) {
            yield $shell->value => [$shell];
        }
    }

    #[DataProvider('shells')]
    public function testScriptIsPinned(Shell $shell): void
    {
        self::assertStringEqualsFile(
            __DIR__ . '/fixtures/completion-' . $shell->value . '.golden',
            Completion::script(SampleSpec::candyCode(), $shell),
        );
    }

    #[DataProvider('shells')]
    public function testHostileNamesStayQuotedLiterals(Shell $shell): void
    {
        $spec = CommandSpec::new("my.app'x")
            ->withOption(Option::value('fmt')->withChoices(['$(touch pwned)', "it's"])->withDescription("say 'hi'\nthere"))
            ->withSubcommand(CommandSpec::new("s'u\$(x)")->withDescription('d'));

        $script = Completion::script($spec, $shell);

        // The value and the subcommand only ever appear inside single quotes,
        // with the embedded quote escaped in the shell's own way.
        [$value, $quote, $sub] = match ($shell) {
            Shell::Bash, Shell::Zsh => ["'\$(touch pwned)'", "'it'\\''s'", "'s'\\''u\$(x)"],
            Shell::Fish => ["'\$(touch pwned)'", "'it\\'s'", "'s\\'u\$(x)'"],
        };
        self::assertStringContainsString($value, $script);
        self::assertStringContainsString($quote, $script);
        self::assertStringContainsString($sub, $script);
        // The function name is derived, never the raw command name.
        self::assertStringContainsString('my_app_x', $script);
        // A newline in a description is flattened to a space, never a line break.
        self::assertStringNotContainsString("\nthere", $script);
        if ($shell !== Shell::Bash) {
            // bash shows no descriptions; zsh and fish carry the flattened text.
            self::assertStringContainsString(' there', $script);
        }
    }

    public function testShellParsesFromItsName(): void
    {
        self::assertSame(Shell::Zsh, Shell::tryFrom('zsh'));
        self::assertSame(Shell::Bash, Shell::from('bash'));
        self::assertSame(Shell::Fish, Shell::from('fish'));
        self::assertNull(Shell::tryFrom('powershell'));
    }
}
