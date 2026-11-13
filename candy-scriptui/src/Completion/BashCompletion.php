<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Completion;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;

/**
 * Mirrors charmbracelet/gum.completion (the bash emitter).
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
        // The bash handler binds as `complete -F <fn> <word>`: the function
        // name must follow the application too, so a renamed binary gets its
        // own handler instead of one nobody sources. Default name keeps the
        // historic '_candy_scriptui_main' bytes.
        $fnName = '_' . str_replace(['-', '.'], '_', $appName) . '_main';
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
{$fnName}() {
    # Under the `complete -F` contract bash calls the handler as
    # _fn NAME CUR WORDS INDEX - \$1 is the command NAME, \$2 is the
    # word being completed (scriptui-23; reading \$1 made every compgen
    # filter by the literal app name and COMPREPLY was always empty).
    local cur="\${2:-}"
    local word="\${COMP_WORDS[1]:-}"

    case "\$word" in
{$caseBlockStr}
    *)
        COMPREPLY=(\$(compgen -W "{$allCmds}" -- "\$cur"))
        ;;
    esac
}

complete -F {$fnName} {$appName}
BASH;

        return $script;
    }
}
