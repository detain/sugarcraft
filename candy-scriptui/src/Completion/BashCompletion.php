<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Completion;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;

/**
 * Generates Bash completion script for a Symfony Console application.
 * Dynamically inspects the application to produce completions for all
 * registered commands and their flags.
 */
final class BashCompletion
{
    /** Documented fallback app name used when Application::getName() is not shell-safe. */
    public const DEFAULT_APP_NAME = 'candy-scriptui';

    public static function isSupportedShell(string $shell): bool
    {
        return $shell === 'bash';
    }

    /**
     * @return list<string>
     */
    public static function validShells(): array
    {
        return ['bash'];
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
            $commandOptions[$safeName] = implode(' ', $optNames);
        }

        $allCmds = implode(' ', $commandNames);

        $caseBlocks = [];
        foreach ($commandNames as $name) {
            $opts = $commandOptions[$name] ?? '';
            $caseBlocks[] = "    \"{$name}\")\n        COMPREPLY=(\$(compgen -W \"{$opts}\" -- \"\$cur\"))\n        ;;";
        }
        $caseBlockStr = implode("\n", $caseBlocks);

        $script = <<<BASH
_candy_scriptui_main() {
    local cur="\${1:-}"
    local word="\${COMP_WORDS[1]:-}"

    case "\$word" in
{$caseBlockStr}
    *)
        COMPREPLY=(\$(compgen -W "{$allCmds}" -- "\$cur"))
        ;;
    esac
}

complete -F _candy_scriptui_main {$appName}
BASH;

        return $script;
    }
}
