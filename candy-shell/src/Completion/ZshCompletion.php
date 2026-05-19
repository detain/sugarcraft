<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Completion;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;

/**
 * Generates Zsh completion script for a Symfony Console application.
 * Dynamically inspects the application to produce completions for all
 * registered commands and their flags.
 */
final class ZshCompletion
{
    public static function isSupportedShell(string $shell): bool
    {
        return $shell === 'zsh';
    }

    /**
     * @return list<string>
     */
    public static function validShells(): array
    {
        return ['zsh'];
    }

    public function generate(Application $application): string
    {
        $appName = $application->getName();
        $commands = $application->all();

        $commandNames = [];
        $commandOptions = [];
        foreach ($commands as $name => $command) {
            if ($name === '') {
                continue;
            }
            $commandNames[] = $name;
            $opts = $command->getDefinition()->getOptions();
            $optNames = [];
            foreach ($opts as $opt) {
                if (!$opt->isNegatable()) {
                    $optNames[] = '--' . $opt->getName();
                }
            }
            $commandOptions[$name] = implode("\n" . str_repeat(' ', 16), $optNames);
        }

        $rootCmds = implode("\n" . str_repeat(' ', 12), $commandNames);

        $cmdBlocks = [];
        foreach ($commandNames as $name) {
            $opts = $commandOptions[$name] ?? '';
            $cmdBlocks[] = <<<ZSH
        '{$name}:' "({$opts})" \\
ZSH;
        }

        $cmdBlocksStr = implode("\n", $cmdBlocks);

        $out = <<<ZSH
#compdef {$appName}

_{$appName}_commands() {
    local -a commands
    commands=(
        {$rootCmds}
    )
    _describe 'command' commands
}

_{$appName}_command() {
    local -a options
    case \$words[1] in
{$cmdBlocksStr}
    esac
}

_{$appName}() {
    local -a commands
    commands=({$rootCmds})

    if (( CURRENT == 2 )); then
        _describe 'command' commands
        return
    fi

    local -a cmd_options
    case \$words[1] in
{$cmdBlocksStr}
    esac

    _describe 'option' cmd_options
}

compdef _{$appName} {$appName}
ZSH;

        return $out;
    }
}
