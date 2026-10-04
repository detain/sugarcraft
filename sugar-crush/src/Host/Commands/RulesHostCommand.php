<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

use SugarCraft\Crush\Commands\RulesCommand;
use SugarCraft\Crush\Context\RuleLoader;

/**
 * `/rules` — list the operator's rule packs, or toggle one for this session
 * only (prompt_plan.md P6.S3); the session half of {@see RulesCommand}
 * (roadmap O-2h).
 *
 * The output is captured with `ob_start()` because {@see RulesCommand} writes
 * to stdout, and a non-zero exit becomes {@see CommandResult::failure()}, so a
 * bad pack name lands as a `Role::System` notice rather than as an assistant
 * reply the provider would be shown on the next turn.
 *
 * NO "not configured" degradation: the rules state is one every session has,
 * and the loader is built against {@see CommandContext::projectRoot()} — the
 * resolution the prompt splice uses, so the listing and the prompt cannot be
 * looking at different directories. A toggle mutates the shared
 * {@see \SugarCraft\Crush\Context\RulesState} in place, so the transcript and
 * the next prompt can never disagree about a pack.
 */
final class RulesHostCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        ob_start();
        $exitCode = (new RulesCommand(new RuleLoader($context->projectRoot()), $context->rulesState))
            ->execute($context, CommandText::words($text));
        $output = (string) ob_get_clean();

        return $exitCode === 0
            ? CommandResult::reply($text, $output)
            : CommandResult::failure($text, $output, $exitCode);
    }
}
