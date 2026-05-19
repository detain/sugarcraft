<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Completion;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;

/**
 * Generates Bash completion script for a Symfony Console application.
 * Dynamically inspects the application to produce completions for all
 * registered commands and their flags.
 */
final class BashCompletion
{
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
            $commandOptions[$name] = implode(' ', $optNames);
        }

        $allCmds = implode(' ', $commandNames);

        $caseBlocks = [];
        foreach ($commandNames as $name) {
            $opts = $commandOptions[$name] ?? '';
            $caseBlocks[] = "    \"{$name}\")\n        COMPREPLY=(\$(compgen -W \"{$opts}\" -- \"\$cur\"))\n        ;;";
        }
        $caseBlockStr = implode("\n", $caseBlocks);

        $script = <<<BASH
_candyshell_main() {
    local cur="\${1:-}"
    local word="\${COMP_WORDS[1]:-}"

    case "\$word" in
{$caseBlockStr}
    *)
        COMPREPLY=(\$(compgen -W "{$allCmds}" -- "\$cur"))
        ;;
    esac
}

complete -F _candyshell_main {$appName}
BASH;

        return $script;
    }
}
