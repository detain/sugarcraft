<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Completion;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;

/**
 * Generates Fish completion script for a Symfony Console application.
 * Dynamically inspects the application to produce completions for all
 * registered commands and their flags.
 */
final class FishCompletion
{
    /** Documented fallback app name used when Application::getName() is not shell-safe. */
    public const DEFAULT_APP_NAME = 'candy-scriptui';

    public static function isSupportedShell(string $shell): bool
    {
        return $shell === 'fish';
    }

    /**
     * @return list<string>
     */
    public static function validShells(): array
    {
        return ['fish'];
    }

    public function generate(Application $application): string
    {
        $appNameRaw = $application->getName();
        // An unsafe application name cannot be echoed into a script meant
// to be eval'd — fall back to the shipped default instead of
// silently emitting a malformed/empty completion function name.
        $appName = CompletionEscaper::safeName($appNameRaw) ?? self::DEFAULT_APP_NAME;
        $commands = $application->all();

        $lines = [];
        $lines[] = "# fish completion for {$appName}";

        foreach ($commands as $name => $command) {
            if ($name === '') {
                continue;
            }
            // Skip commands whose names could inject shell code.
            $safeName = CompletionEscaper::safeName($name);
            if ($safeName === null) {
                continue;
            }
            $lines[] = "complete -c {$appName} -f -n '__fish_seen_subcommand_from {$safeName}' -l 'help'";
            $opts = $command->getDefinition()->getOptions();
            foreach ($opts as $opt) {
                if (!$opt->isNegatable()) {
                    $optName = CompletionEscaper::safeName($opt->getName());
                    if ($optName !== null) {
                        $lines[] = "complete -c {$appName} -f -n '__fish_seen_subcommand_from {$safeName}' -l '{$optName}'";
                    }
                }
            }
        }

        foreach ($commands as $name => $command) {
            if ($name === '') {
                continue;
            }
            $safeName = CompletionEscaper::safeName($name);
            if ($safeName === null) {
                continue;
            }
            $lines[] = "complete -c {$appName} -f -n '__fish_use_subcommand' -a '{$safeName}'";
        }

        return implode("\n", $lines) . "\n";
    }
}
