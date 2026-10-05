<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

use SugarCraft\Crush\Skills\ProposedSkills;

/**
 * `/skills` — review the skill drafts the dream pass proposed (roadmap 5.4-3,
 * the opt-in `memory.dreamProposeSkills` mode): `/skills` or
 * `/skills proposed` lists them, `/skills accept <name> [--replace]` promotes
 * one into `~/.sugar-crush/skills/<name>/`, `/skills reject <name>` deletes it.
 *
 * THE ONLY PROMOTION PATH. A draft is model output written by an unattended
 * turn; making it a skill turns it into prompt text every later session reads
 * as the operator's own. So promotion is a slash command — typed by the user
 * in the TUI, or sent by a protocol client's `command.exec` — and no tool or
 * agent reaches {@see ProposedSkills::accept()}. The draft store is the
 * workspace's {@see ProposedSkills} service when one is registered, else the
 * owned home's.
 *
 * Every refusal (no such draft, a name that is not a draft name, a clash with
 * a live skill, a draft the loader rejects) is a {@see CommandResult::failure()}
 * notice, never an assistant reply the provider would be shown. Draft text is
 * model-written, so every field of the listing goes through
 * {@see PermissionsCommand::reportField()}.
 */
final class SkillsHostCommand implements HostCommand
{
    public const USAGE = 'Usage: /skills [proposed] · /skills accept <name> [--replace] · /skills reject <name>';

    public function run(CommandContext $context, string $text): CommandResult
    {
        $service = $context->workspace?->service(ProposedSkills::class);
        $drafts = $service instanceof ProposedSkills ? $service : ProposedSkills::new();
        $words = CommandText::words($text);
        $sub = strtolower($words[0] ?? 'proposed');

        try {
            return match ($sub) {
                'proposed', 'list' => \count($words) <= 1
                    ? CommandResult::reply($text, self::listing($drafts))
                    : CommandResult::failure($text, self::USAGE, 2),
                'accept' => $this->accept($context, $drafts, $text, \array_slice($words, 1)),
                'reject' => \count($words) === 2
                    ? $this->reject($drafts, $text, $words[1])
                    : CommandResult::failure($text, self::USAGE, 2),
                default => CommandResult::failure($text, self::USAGE, 2),
            };
        } catch (\Throwable $e) {
            return CommandResult::failure($text, 'Skills: ' . PermissionsCommand::reportField($e->getMessage()), 1);
        }
    }

    /** The drafts waiting for review, one line each. */
    public static function listing(ProposedSkills $drafts): string
    {
        $root = $drafts->root();
        if ($root === null) {
            return 'No skill drafts: there is no home directory this user owns to keep them in.';
        }

        $list = $drafts->drafts();
        if ($list === []) {
            return sprintf(
                'No skill drafts are waiting in `%s`. The dream pass proposes them only with `%s` on.',
                PermissionsCommand::reportField($root),
                \SugarCraft\Crush\Memory\DreamPass::SETTING_PROPOSE_SKILLS,
            );
        }

        $lines = [sprintf('%d skill %s waiting in `%s`:', \count($list), \count($list) === 1 ? 'draft' : 'drafts', PermissionsCommand::reportField($root))];
        foreach ($list as $draft) {
            $lines[] = sprintf(
                '- `%s` (%s bytes, %s) — %s',
                $draft['name'],
                number_format($draft['bytes']),
                date('Y-m-d H:i', $draft['modified']),
                $draft['error'] === null
                    ? PermissionsCommand::reportField((string) $draft['description'])
                    : 'does not load as a skill: ' . PermissionsCommand::reportField($draft['error']),
            );
        }
        $lines[] = '';
        $lines[] = 'Read one before accepting it. `/skills accept <name>` makes it live in `~/'
            . ProposedSkills::LIVE_SUBDIR . '/<name>/` (from the next launch); `/skills reject <name>` deletes it.';

        return implode("\n", $lines);
    }

    /** @param list<string> $args */
    private function accept(CommandContext $context, ProposedSkills $drafts, string $text, array $args): CommandResult
    {
        $replace = false;
        $names = [];
        foreach ($args as $arg) {
            if ($arg === '--replace') {
                $replace = true;
                continue;
            }
            $names[] = $arg;
        }
        if (\count($names) !== 1) {
            return CommandResult::failure($text, self::USAGE, 2);
        }

        $path = $drafts->accept($names[0], $replace, $context->projectRoot());

        return CommandResult::reply($text, sprintf(
            'Accepted the skill draft `%s`: it is now `%s`, loaded from the next launch.',
            $names[0],
            PermissionsCommand::reportField($path),
        ));
    }

    private function reject(ProposedSkills $drafts, string $text, string $name): CommandResult
    {
        $drafts->reject($name);

        return CommandResult::reply($text, sprintf('Rejected and deleted the skill draft `%s`.', $name));
    }
}
