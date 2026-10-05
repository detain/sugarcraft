<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

use SugarCraft\Crush\Lang;
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
    /** The usage line every malformed `/skills` answers with. */
    public static function usage(): string
    {
        return Lang::t('host.skills.usage');
    }

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
                    : CommandResult::failure($text, self::usage(), 2),
                'accept' => $this->accept($context, $drafts, $text, \array_slice($words, 1)),
                'reject' => \count($words) === 2
                    ? $this->reject($drafts, $text, $words[1])
                    : CommandResult::failure($text, self::usage(), 2),
                default => CommandResult::failure($text, self::usage(), 2),
            };
        } catch (\Throwable $e) {
            return CommandResult::failure($text, Lang::t('host.skills.error', ['error' => PermissionsCommand::reportField($e->getMessage())]), 1);
        }
    }

    /** The drafts waiting for review, one line each. */
    public static function listing(ProposedSkills $drafts): string
    {
        $root = $drafts->root();
        if ($root === null) {
            return Lang::t('host.skills.no_home');
        }

        $list = $drafts->drafts();
        if ($list === []) {
            return Lang::t('host.skills.none', [
                'root' => PermissionsCommand::reportField($root),
                'setting' => \SugarCraft\Crush\Memory\DreamPass::SETTING_PROPOSE_SKILLS,
            ]);
        }

        $lines = [Lang::t(\count($list) === 1 ? 'host.skills.waiting.one' : 'host.skills.waiting.other', [
            'count' => \count($list),
            'root' => PermissionsCommand::reportField($root),
        ])];
        foreach ($list as $draft) {
            $lines[] = sprintf(
                '- `%s` (%s bytes, %s) — %s',
                $draft['name'],
                number_format($draft['bytes']),
                date('Y-m-d H:i', $draft['modified']),
                $draft['error'] === null
                    ? PermissionsCommand::reportField((string) $draft['description'])
                    : Lang::t('host.skills.does_not_load', ['error' => PermissionsCommand::reportField($draft['error'])]),
            );
        }
        $lines[] = '';
        $lines[] = Lang::t('host.skills.review_hint', ['dir' => ProposedSkills::LIVE_SUBDIR]);

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
            return CommandResult::failure($text, self::usage(), 2);
        }

        $path = $drafts->accept($names[0], $replace, $context->projectRoot());

        return CommandResult::reply($text, Lang::t('host.skills.accepted', [
            'name' => $names[0],
            'path' => PermissionsCommand::reportField($path),
        ]));
    }

    private function reject(ProposedSkills $drafts, string $text, string $name): CommandResult
    {
        $drafts->reject($name);

        return CommandResult::reply($text, Lang::t('host.skills.rejected', ['name' => $name]));
    }
}
