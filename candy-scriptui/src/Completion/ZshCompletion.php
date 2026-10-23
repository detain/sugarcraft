<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Completion;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;

/**
 * Generates Zsh completion script for a Symfony Console application.
 * Dynamically inspects the application to produce completions for all
 * registered commands and their flags.
 */
final class ZshCompletion
{
    /** Documented fallback app name used when Application::getName() is not shell-safe. */
    public const DEFAULT_APP_NAME = 'candy-scriptui';

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
        $appNameRaw = $application->getName();
        // An unsafe application name cannot be echoed into a script meant
// to be eval'd — fall back to the shipped default instead of
// silently emitting a malformed/empty completion function name.
        $appName = CompletionEscaper::safeName($appNameRaw) ?? self::DEFAULT_APP_NAME;
        $commands = $application->all();

        $commandNames = [];
        $commandOptions = [];
        foreach ($commands as $name => $command) {
            if ($name === '') {
                continue;
            }
            // Skip commands whose names could inject shell code.
            $safeName = CompletionEscaper::safeName($name);
            if ($safeName === null) {
                continue;
            }
            $commandNames[] = $safeName;
            $opts = $command->getDefinition()->getOptions();
            $optNames = [];
            foreach ($opts as $opt) {
                if (!$opt->isNegatable()) {
                    $optName = CompletionEscaper::safeName($opt->getName());
                    if ($optName !== null) {
                        $optNames[] = '--' . $optName;
                    }
                }
            }
            $commandOptions[$safeName] = implode("\n" . str_repeat(' ', 16), $optNames);
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
