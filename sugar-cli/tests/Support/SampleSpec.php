<?php

declare(strict_types=1);

namespace SugarCraft\Cli\Tests\Support;

use SugarCraft\Cli\CommandSpec;
use SugarCraft\Cli\Option;
use SugarCraft\Cli\ValueType;

/**
 * The spec the generator tests (help, completion, man page) pin their
 * output against: options with short aliases, an env fallback, choices, a
 * default, repeatables, a group, a persistent option, a required option,
 * and subcommands two levels deep.
 */
final class SampleSpec
{
    public static function candyCode(): CommandSpec
    {
        return CommandSpec::new('candy-code')
            ->withDescription('An agentic coding assistant.')
            ->withOption(Option::flag('verbose')->withShort('v')->withRepeatable()->withPersistent()->withDescription('Print more detail'))
            ->withOption(Option::flag('json')->withDescription('Emit JSON'))
            ->withOption(Option::value('tag')->withRepeatable()->withDescription('Attach a tag'))
            ->withOption(Option::value('model')->withShort('m')->withEnv('CANDY_CODE_MODEL')->withDescription('Model to use')->withGroup('Model'))
            ->withOption(Option::value('effort')->withChoices(['low', 'high'])->withDefault('low')->withDescription('Reasoning effort')->withGroup('Model'))
            ->withSubcommand(
                CommandSpec::new('run')
                    ->withDescription('Run one prompt')
                    ->withOption(Option::value('max-turns')->withType(ValueType::Int)->withRequired()->withDescription('Turn limit'))
                    ->withMinArguments(1)
                    ->withMaxArguments(1),
            )
            ->withSubcommand(
                CommandSpec::new('config')
                    ->withDescription('Read settings')
                    ->withSubcommand(CommandSpec::new('get')->withDescription('Print one setting')->withMinArguments(1)->withMaxArguments(1)),
            )
            ->withSubcommand(CommandSpec::new('doctor')->withDescription("Check the install's health"));
    }
}
