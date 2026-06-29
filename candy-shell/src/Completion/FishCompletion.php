<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Completion;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;

/**
 * Generates Fish completion script for a Symfony Console application.
 * Dynamically inspects the application to produce completions for all
 * registered commands and their flags.
 */
final class FishCompletion
{
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
        $appName = CompletionEscaper::safeName($appNameRaw) ?? '';
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
