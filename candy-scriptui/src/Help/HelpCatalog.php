<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Help;

use SugarCraft\ScriptUi\Attribute\Example;
use SugarCraft\ScriptUi\Lang;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * The help surface's i18n plumbing (scriptui-59): command descriptions,
 * option / argument texts, `#[Example]` captions and the Aliases / Examples /
 * Help labels resolve through `lang/<locale>.php` like every other
 * user-facing string.
 *
 * The English literal stays in the command class (it is the fallback and the
 * source `en.php` is derived from); this class layers the catalog on top at
 * registration time, so a command author writes ONE English string and a
 * missing translation degrades to it. `HelpDriftTest` pins that `en.php`
 * carries every key with exactly the in-code text, so a new flag without a
 * catalog row goes red instead of shipping English-only.
 *
 * Key shape:
 *  - `help.cmd.<command>`            command description
 *  - `help.opt.<command>.<option>`   option text specific to one command
 *  - `help.opt.<option>`             option text shared by commands (tried second)
 *  - `help.arg.<command>.<argument>` argument text
 *  - `help.example.<command>.<n>`    caption of the n-th `#[Example]` (0-based)
 *  - `help.label.aliases|examples|help` section labels
 */
final class HelpCatalog
{
    /**
     * The catalog rows the current command classes call for, as
     * key => English. Source of both `tools/gen-help-catalog.php` and
     * `HelpDriftTest`. A text shared by several commands for the same option
     * becomes ONE `help.opt.<option>` row; commands that word it differently
     * get their own `help.opt.<command>.<option>` row.
     *
     * Call it under the English locale (the in-class literals are English;
     * under another locale the commands already carry translated text).
     *
     * @return array<string, string>
     */
    public static function derive(\Symfony\Component\Console\Application $app): array
    {
        $rows = [];
        /** @var array<string, array<string, string>> $byOption option => command => text */
        $byOption = [];
        foreach ($app->all() as $command) {
            if (!self::owns($command) || (string) $command->getName() === '') {
                continue;
            }
            $name = (string) $command->getName();
            $rows['help.cmd.' . $name] = $command->getDescription();
            foreach ($command->getDefinition()->getArguments() as $argument) {
                $rows['help.arg.' . $name . '.' . $argument->getName()] = $argument->getDescription();
            }
            foreach ((new \ReflectionClass($command))->getAttributes(Example::class) as $i => $attribute) {
                $caption = $attribute->newInstance()->description;
                if ($caption !== '') {
                    $rows['help.example.' . $name . '.' . $i] = $caption;
                }
            }
            foreach ($command->getDefinition()->getOptions() as $option) {
                $byOption[$option->getName()][$name] = $option->getDescription();
            }
        }
        foreach ($byOption as $option => $texts) {
            $counts = array_count_values($texts);
            arsort($counts);
            $shared = (string) array_key_first($counts);
            // A one-command option stays under its command key: a shared row
            // nobody shares would just be indirection.
            if (count($texts) > 1) {
                $rows['help.opt.' . $option] = $shared;
            }
            foreach ($texts as $command => $text) {
                if (count($texts) === 1 || $text !== $shared) {
                    $rows['help.opt.' . $command . '.' . $option] = $text;
                }
            }
        }
        $rows['help.label.aliases'] = 'Aliases:';
        $rows['help.label.examples'] = 'Examples:';
        $rows['help.label.help'] = 'Help:';

        return $rows;
    }

    /** Resolve `$key`, or null when the active catalogs carry no row for it. */
    public static function lookup(string $key): ?string
    {
        // Lang::t registers the namespace lazily; an unknown key comes back
        // verbatim (core contract), which is how a missing row is detected.
        $text = Lang::t($key);

        return $text === $key || $text === 'scriptui.' . $key ? null : $text;
    }

    /** True for commands this package ships (scanned third-party ones have no rows). */
    public static function owns(Command $command): bool
    {
        return str_starts_with($command::class, 'SugarCraft\\ScriptUi\\');
    }

    /** Candidate keys for an option, most specific first. */
    public static function optionKeys(string $command, string $option): array
    {
        return ['help.opt.' . $command . '.' . $option, 'help.opt.' . $option];
    }

    /**
     * Swap every translatable string on `$command` for its catalog row,
     * leaving anything without one on the English literal.
     */
    public static function localize(Command $command): void
    {
        if (!self::owns($command) || $command->getName() === null || $command->getName() === '') {
            return;
        }
        $name = (string) $command->getName();

        $description = self::lookup('help.cmd.' . $name);
        if ($description !== null) {
            $command->setDescription($description);
        }

        $definition = $command->getDefinition();

        $arguments = [];
        foreach ($definition->getArguments() as $argument) {
            $text = self::lookup('help.arg.' . $name . '.' . $argument->getName());
            $arguments[] = $text === null ? $argument : new InputArgument(
                $argument->getName(),
                self::argumentMode($argument),
                $text,
                $argument->isRequired() ? null : $argument->getDefault(),
            );
        }

        $options = [];
        foreach ($definition->getOptions() as $option) {
            $text = null;
            foreach (self::optionKeys($name, $option->getName()) as $key) {
                $text = self::lookup($key);
                if ($text !== null) {
                    break;
                }
            }
            $options[] = $text === null ? $option : new InputOption(
                $option->getName(),
                $option->getShortcut(),
                self::optionMode($option),
                $text,
                $option->acceptValue() ? $option->getDefault() : null,
            );
        }

        $definition->setArguments($arguments);
        $definition->setOptions($options);
    }

    /** Localized caption of the n-th example of `$command` (English fallback). */
    public static function exampleCaption(Command $command, int $index, Example $example): string
    {
        return self::lookup('help.example.' . $command->getName() . '.' . $index) ?? $example->description;
    }

    /** Section label with an English fallback. */
    public static function label(string $name, string $english): string
    {
        return self::lookup('help.label.' . $name) ?? $english;
    }

    private static function argumentMode(InputArgument $argument): int
    {
        return ($argument->isRequired() ? InputArgument::REQUIRED : InputArgument::OPTIONAL)
            | ($argument->isArray() ? InputArgument::IS_ARRAY : 0);
    }

    private static function optionMode(InputOption $option): int
    {
        $mode = !$option->acceptValue()
            ? InputOption::VALUE_NONE
            : ($option->isValueRequired() ? InputOption::VALUE_REQUIRED : InputOption::VALUE_OPTIONAL);

        return $mode
            | ($option->isArray() ? InputOption::VALUE_IS_ARRAY : 0)
            | ($option->isNegatable() ? InputOption::VALUE_NEGATABLE : 0);
    }
}
