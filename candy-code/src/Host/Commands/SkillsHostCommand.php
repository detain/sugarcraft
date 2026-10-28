<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Code\Config\Settings\SettingsTier;
use SugarCraft\Code\Config\Settings\SettingsWriter;
use SugarCraft\Code\Commands\TranscriptTable;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Skills\LearnedSkills;
use SugarCraft\Code\Skills\ProposedSkills;
use SugarCraft\Code\Skills\SkillLoader;
use SugarCraft\Code\Skills\SkillManager;
use SugarCraft\Code\Skills\SkillProposal;
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
 * `/skills enable <name>` and `/skills disable <name>` (C-EXT-2.d) are the
 * command half of the on/off switch: they rewrite the user tier's
 * `disabledSkills` list through the workspace's {@see SettingsWriter} — the
 * same sanctioned door the settings view saves through — AND flip the
 * launch's live {@see SkillRegistry}, so the change bites this session now
 * and every later launch at boot. An unknown name is refused whole: neither
 * file nor registry moves. One launch's registry is shared with its forked
 * children, so a disable mid-turn is read by those children as still-on; the
 * next launch's boot discovery carries the truth.
 *
 * `/skills new <name> [description]` (C-EXT-3.c) is the write side of the
 * review queue: it scaffolds a draft `SKILL.md` through
 * {@see ProposedSkills::propose()} — the same door the dream pass writes
 * through, with the bundled `skill-creator` skill teaching the format — so
 * the draft lands in the queue awaiting review and `/skills accept` stays
 * the only promotion path. The reply prints the draft path.
 *
 * `/skills history <name>` and `/skills undo <name>` (C-EXT-3.e) are the
 * two doors the SkillWorkshop tool's announcement names, under owner ruling
 * R-EXT-2: a model-written learned skill goes LIVE immediately (through the
 * Write-class ask-gate, never through accept()), and every landing saves a
 * revision in the learned store, so `history` shows the window and `undo`
 * steps one revision back — restoring the previous bytes, or, for the sole
 * first revision, removing the skill whole. These verbs answer only for
 * skills the workshop wrote; an accepted draft or a hand-written skill has
 * no saved revisions and says so rather than pretending a history.
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
                // C-EXT-3.c: the scaffold needs a name; the words after it
                // are the one-line description, defaulted when absent.
                'new' => \count($words) >= 2
                    ? $this->newDraft($drafts, $text, \array_slice($words, 1))
                    : CommandResult::failure($text, self::usage(), 2),
                'proposed' => \count($words) <= 1
                    ? CommandResult::reply($text, self::listing($drafts))
                    : CommandResult::failure($text, self::usage(), 2),
                'accept' => $this->accept($context, $drafts, $text, \array_slice($words, 1)),
                'reject' => \count($words) === 2
                    ? $this->reject($drafts, $text, $words[1])
                    : CommandResult::failure($text, self::usage(), 2),
                // C-EXT-2.d: exactly one name, spelled with the case the
                // discovery registered — the same no-case-folding law the
                // installed table's On column answers under.
                'enable', 'disable' => \count($words) === 2
                    ? $this->toggleSkill($context, $text, $sub, $words[1])
                    : CommandResult::failure($text, self::usage(), 2),
                // C-EXT-3.e: the two doors R-EXT-2's announcement names, each
                // taking exactly one name — and both answer only for skills
                // the workshop wrote, because only its revisions make an
                // undo real. The store resolves its own owned home; a session
                // without one gets the store's refusal through the catch.
                'history' => \count($words) === 2
                    ? $this->history($text, $words[1])
                    : CommandResult::failure($text, self::usage(), 2),
                'undo' => \count($words) === 2
                    ? $this->undo($text, $words[1])
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
        $manager->loadAll($context->projectRoot(), \SugarCraft\Code\Cli\Bootstrap::bundles($context->projectRoot()));

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

    /** The body every `/skills new` scaffold starts from — frontmatter arrives from the proposal. */
    private const SCAFFOLD_BODY = <<<'MARKDOWN'
## When to use this skill

(Replace this line: the request shape or files that should load it.)

## Procedure

1. State the concrete steps, in order.
2. Keep every instruction general — a skill is prompt text in every
   session of whatever project installs it, so it must not name one
   project's private paths or endpoints.
3. Say what counts as done.

Edit this file, then `/skills accept <name>` makes it live; that typed
command is the only promotion path. The `skill-creator` skill explains
the keys the host honours: `allowed-tools`, `disallowed-tools`, `model`,
`effort`, `requires` gating, `paths` nudges, and `context: fork`, and
`${CLAUDE_SKILL_DIR}` substitutes with this skill's own directory at
load time. Full format: the host's shipped `docs/SKILLS.md`.
MARKDOWN;

    /**
     * C-EXT-3.c: the scaffold half of the review queue. The name arrives raw
     * and {@see ProposedSkills::propose()} sanitises it — a name that
     * sanitises to nothing, an over-cap description, a draft already waiting
     * under the sanitised name, or a missing owned home all throw, and the
     * run() catch turns any of them into a failure notice, so nothing here
     * duplicates those refusals. The write is the dream pass's own door: the
     * draft waits for review, and only `/skills accept` moves it live.
     *
     * @param list<string> $args the name and any following description words
     */
    private function newDraft(ProposedSkills $drafts, string $text, array $args): CommandResult
    {
        $description = \trim(\implode(' ', \array_slice($args, 1)));
        if ($description === '') {
            $description = Lang::t('host.skills.new.default_description');
        }

        $name = $drafts->propose(SkillProposal::new($args[0], $description, self::SCAFFOLD_BODY));

        return CommandResult::reply($text, Lang::t('host.skills.new.drafted', [
            'name' => $name,
            // propose() returning means the root exists; the ?? keeps types honest.
            'path' => PermissionsCommand::reportField(($drafts->root() ?? '') . '/' . $name . '/SKILL.md'),
        ]));
    }

    /**
     * `/skills history <name>` (C-EXT-3.e) — the active revision window of a
     * learned skill, oldest first so the head that `/skills undo` steps back
     * from reads last. Names, sizes and mtimes only: a listing that slurped
     * every body would be the walk this store refuses. The archived count
     * closes the window's other edge — R-EXT-2 bounds undo, not the record.
     * The store's own refusal (no owned home, a name that sanitises to
     * nothing) rides the catch in {@see self::run()} as a notice.
     */
    private function history(string $text, string $name): CommandResult
    {
        $record = LearnedSkills::new()->history($name);
        $shown = PermissionsCommand::reportField($record['name']);

        if ($record['revisions'] === []) {
            return CommandResult::reply($text, Lang::t('host.skills.history.no_revisions', ['name' => $shown]));
        }

        $lines = [Lang::t('host.skills.history.header', ['name' => $shown])];
        foreach ($record['revisions'] as $row) {
            $lines[] = Lang::t('host.skills.history.row', [
                'number' => $row['number'],
                'bytes' => number_format($row['bytes']),
                'modified' => date('Y-m-d H:i', $row['modified']),
            ]);
        }
        if (!$record['live']) {
            $lines[] = Lang::t('host.skills.history.not_live');
        }
        if ($record['archived'] > 0) {
            $lines[] = Lang::t('host.skills.history.archived', ['count' => $record['archived']]);
        }

        return CommandResult::reply($text, implode("\n", $lines));
    }

    /**
     * `/skills undo <name>` (C-EXT-3.e) — the door every SkillWorkshop
     * announcement names. It restores the previous saved revision, or removes
     * the skill whole when revision 1 was its first landing; nothing an undo
     * removes is unrecorded, because the step-back reads only what the store
     * itself wrote. A skill with no saved revisions refuses through the
     * store, not a guess here.
     */
    private function undo(string $text, string $name): CommandResult
    {
        $result = LearnedSkills::new()->undo($name);
        $shown = PermissionsCommand::reportField($result['name']);

        if ($result['action'] === 'removed') {
            $message = Lang::t('host.skills.undo.removed', ['name' => $shown]);
            if ($result['residue'] !== null) {
                $message .= Lang::t('host.skills.undo.removed_residue', [
                    'dir' => PermissionsCommand::reportField($result['residue']),
                ]);
            }

            return CommandResult::reply($text, $message);
        }

        return CommandResult::reply($text, Lang::t('host.skills.undo.restored', [
            'name' => $shown,
            'revision' => $result['revision'],
            'path' => PermissionsCommand::reportField($result['path']),
        ]));
    }

    /**
     * The on/off switch, both halves in one action: the persisted user-tier
     * list AND the launch's live registry. The name is checked against the
     * registry the session runs — the workspace's own when one is reachable
     * (the TUI and serve both carry it; `names()` includes registered-but-
     * disabled rows, so an already-off skill can be named for the enable
     * verb), else a fresh discovery in the shape
     * {@see self::installedListing()} runs, so the refusal of an unknown name
     * is honest in every driver. The write rides the sanctioned
     * {@see SettingsWriter} door; a throw there is a failure notice, never a
     * fatal, and the live flip happens only after the file agrees.
     */
    private function toggleSkill(CommandContext $context, string $text, string $verb, string $name): CommandResult
    {
        $disable = $verb === 'disable';

        $registry = $context->workspace?->skills;
        $live = $registry instanceof SkillRegistry;
        if (!$live) {
            $registry = new SkillRegistry();
            (new SkillManager(new SkillLoader(), $registry))->loadAll(
                $context->projectRoot(),
                \SugarCraft\Code\Cli\Bootstrap::bundles($context->projectRoot()),
            );
        }
        if (!\in_array($name, $registry->names(), true)) {
            return CommandResult::failure($text, Lang::t('host.skills.toggle_unknown', ['name' => PermissionsCommand::reportField($name)]), 1);
        }

        $writer = $context->workspace?->service(SettingsWriter::class);
        if (!$writer instanceof SettingsWriter) {
            return CommandResult::failure($text, Lang::t('host.skills.no_writer'), 1);
        }

        $current = $writer->current(SettingsTier::You)['disabledSkills'] ?? [];
        $list = \is_array($current) ? \array_values(\array_filter($current, 'is_string')) : [];
        if ($disable) {
            if (!\in_array($name, $list, true)) {
                $list[] = $name;
            }
        } else {
            $list = \array_values(\array_filter($list, static fn(string $entry): bool => $entry !== $name));
        }

        $refusal = $writer->refusal(SettingsTier::You, 'disabledSkills', $list);
        if ($refusal !== null) {
            return CommandResult::failure($text, $refusal, 1);
        }

        try {
            $writer->write(SettingsTier::You, ['disabledSkills' => $list]);
        } catch (\Throwable $e) {
            return CommandResult::failure($text, Lang::t('host.skills.write_failed', ['error' => PermissionsCommand::reportField($e->getMessage())]), 1);
        }

        if ($live) {
            if ($disable) {
                $registry->disable($name);
            } else {
                $registry->enable($name);
            }
        }

        return CommandResult::reply($text, Lang::t($disable ? 'host.skills.disabled_now' : 'host.skills.enabled_now', ['name' => PermissionsCommand::reportField($name)]));
    }
}
