<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Code\Commands\TranscriptTable;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Skills\ProposedSkills;
use SugarCraft\Code\Skills\SkillLoader;
use SugarCraft\Code\Skills\SkillManager;
use SugarCraft\Code\Skills\SkillRegistry;

/**
 * `/skills` — the skills surface, both questions it answers.
 *
 * BARE `/skills` (or `/skills installed`) lists every invocable skill this
 * session's discovery would load, in a {@see TranscriptTable}: name, tier
 * badge, whether the user's `disabledSkills` setting turns it off, how many
 * same-named files that row's skill shadowed, and the one-line description.
 * Before ISS-089 the command knew only the drafts below, and the installed
 * set was reachable from no command at all — only the Ctrl+S pane — so a
 * session with thirty loaded skills answered `/skills` as if none were.
 *
 * `/skills proposed` reviews the skill drafts the dream pass proposed
 * (roadmap 5.4-3, the opt-in `memory.dreamProposeSkills` mode); `/skills
 * accept <name> [--replace]` promotes one into `~/.candy-code/skills/<name>/`,
 * `/skills reject <name>` deletes it.
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
 * model-written and skill files are repository-written, so every field of
 * either listing goes through {@see PermissionsCommand::reportField()}.
 */
final class SkillsHostCommand implements HostCommand
{
    /**
     * The installed listing: header text => cell budget, in cells, the shape
     * {@see RulesCommand::COLUMNS} establishes — budgets are clip points,
     * {@see TranscriptTable::fit()} scales them to the live pane, and
     * {@see COLUMN_FLOORS} keeps the decisive columns whole at any width.
     */
    private const COLUMNS = [
        'Skill' => 20,
        'Tier' => 16,
        'On' => 5,
        'Skip' => 5,
        'What it does' => 32,
    ];

    /** @see RulesCommand::COLUMN_FLOORS — here the enabled flag carries the answer. */
    private const COLUMN_FLOORS = ['On' => 5, 'Skip' => 5];

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
        $sub = strtolower($words[0] ?? 'installed');

        try {
            return match ($sub) {
                // ISS-089: the bare form answers "what skills do I have",
                // which is the question with an answer for every session;
                // the drafts are the rarer question with an explicit word.
                'installed', 'list' => \count($words) <= 1
                    ? CommandResult::reply($text, self::installedListing($context))
                    : CommandResult::failure($text, self::usage(), 2),
                'proposed' => \count($words) <= 1
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

    /**
     * Every skill this session's discovery would load and would let the user
     * invoke, as one table.
     *
     * RE-DERIVED, not borrowed: the live registry is private to the backend
     * (`EngineBackend::$skillRegistry`), so the listing runs the same
     * {@see SkillManager::loadAll()} the launch runs — the discovery is
     * tier-scans over small directories and lazy bodies are not read (Stage-1
     * manifests only), which makes the honest answer cheap enough to type.
     * `disabledSkills` is read from the user config but DELIBERATELY NOT
     * applied through `disableFromConfig()`: `SkillRegistry::all()` filters
     * disabled names out of every listing accessor, and a table that vanishes
     * the rows for the one setting it advertises would leave the `On` column
     * without a `no` to ever show. The flag is therefore answered per row
     * against the config list itself, byte-exact — the same names the launch
     * would disable, no case folding neither side performs.
     */
    public static function installedListing(CommandContext $context): string
    {
        $loader = new SkillLoader();
        $registry = new SkillRegistry();
        $manager = new SkillManager($loader, $registry);
        $manager->loadAll($context->projectRoot());

        $disabled = \SugarCraft\Code\Cli\Bootstrap::readUserConfig()['disabledSkills'] ?? [];
        $disabled = \is_array($disabled) ? \array_values(\array_filter($disabled, 'is_string')) : [];

        $skills = $registry->getUserInvocable();
        if ($skills === []) {
            return Lang::t('host.skills.installed.none');
        }
        \usort($skills, static fn($a, $b): int => \strcasecmp($a->name, $b->name));

        // Attribute every skip to the skill whose NAME the lost file carried.
        // `SkillLoader::recordSkip()` keys by path, and every path it records
        // — native or foreign, parse failure or shadow loser — is the
        // `<name>/SKILL.md` convention that directory IS the skill name, so
        // the attribution is structural rather than a substring guess. What
        // matches no listed skill (a broken file whose name never loaded, a
        // refused directory) falls to the footer rather than vanishing.
        $byName = [];
        foreach ($skills as $skill) {
            $byName[\strtolower($skill->name)] = true;
        }
        $shadowed = [];
        $unattributed = 0;
        foreach ($loader->skipped() as $path => $reason) {
            $name = \strtolower(\basename(\dirname((string) $path)));
            if (isset($byName[$name])) {
                $shadowed[$name] = ($shadowed[$name] ?? 0) + 1;
            } else {
                ++$unattributed;
            }
        }
        $unattributed += \count($loader->refusedDirectories());

        $labels = [
            'Skill' => Lang::t('host.skills.installed.col.name'),
            'Tier' => Lang::t('host.skills.installed.col.tier'),
            'On' => Lang::t('host.skills.installed.col.on'),
            'Skip' => Lang::t('host.skills.installed.col.skip'),
            'What it does' => Lang::t('host.skills.installed.col.description'),
        ];
        $columns = TranscriptTable::fit(
            TranscriptTable::relabel(self::COLUMNS, $labels),
            $context->paneWidth(),
            TranscriptTable::relabel(self::COLUMN_FLOORS, $labels),
        );
        $table = TranscriptTable::headed($columns);
        foreach ($skills as $skill) {
            $table = $table->row(
                TranscriptTable::cell(
                    PermissionsCommand::reportField($skill->name),
                    $columns[$labels['Skill']],
                ),
                TranscriptTable::cell(
                    PermissionsCommand::reportField($skill->origin->badge($skill->source)),
                    $columns[$labels['Tier']],
                ),
                TranscriptTable::cell(
                    \in_array($skill->name, $disabled, true)
                        ? Lang::t('host.skills.installed.off')
                        : Lang::t('host.skills.installed.on'),
                    $columns[$labels['On']],
                ),
                TranscriptTable::cell(
                    (string) ($shadowed[\strtolower($skill->name)] ?? 0),
                    $columns[$labels['Skip']],
                ),
                TranscriptTable::cell(
                    PermissionsCommand::reportField(\preg_replace('/\s+/u', ' ', \trim($skill->description)) ?? ''),
                    $columns[$labels['What it does']],
                ),
            );
        }

        $lines = [Lang::t('host.skills.installed.header', ['count' => \count($skills)])];
        $lines[] = $table->render();
        if ($unattributed > 0) {
            $lines[] = '';
            $lines[] = Lang::t('host.skills.installed.skips', ['count' => $unattributed]);
        }
        $lines[] = '';
        $lines[] = Lang::t('host.skills.installed.footer');

        return implode("\n", $lines);
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
                'setting' => \SugarCraft\Code\Memory\DreamPass::SETTING_PROPOSE_SKILLS,
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
