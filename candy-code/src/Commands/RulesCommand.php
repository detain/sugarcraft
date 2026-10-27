<?php

declare(strict_types=1);

namespace SugarCraft\Code\Commands;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Context\Rule;
use SugarCraft\Code\Context\RuleLoader;
use SugarCraft\Code\Context\RulesState;
use SugarCraft\Code\Lang;

/**
 * Implements the /rules command: list the operator's rule packs, or toggle one
 * for the rest of this session.
 *
 * Usage:
 *   /rules           — list every pack with the state it is in and why
 *   /rules <name>    — flip that pack and report which way it went
 *
 * Writing a rule is not this command's: `/newrule` (roadmap 5.14d,
 * {@see \SugarCraft\Code\Host\Commands\NewRulePrompt}) has the agent draft
 * one into the project tier, through the always-asked policy write path. The
 * listing's footer points there.
 *
 * This is a SugarCraft architecture type, not a port - charmbracelet/crush has
 * no `RulesCommand` symbol, so the repo's "Mirrors charmbracelet/..." convention
 * does not apply. The idiom it copies is {@see AgentsCommand}: a final class
 * holding its collaborators, an `execute()` that returns an exit code, and output
 * written to stdout for {@see \SugarCraft\Code\Chat::handleRulesCommand()} to
 * capture - which is the only correct shape now that the screen belongs to the
 * frame renderer.
 *
 * WHAT A PACK IS. Every rule file in the operator's four user directories
 * (`~/.candy-code/rulebooks/`, `~/.candy-code/rules/`, and the INT-2 imports
 * `~/.claude/rules/` and `~/.cursor/rules/`) is a pack, named by
 * its filename minus the extension - the identity {@see RuleLoader} already
 * derives and {@see Rule::$key} already carries. There is no registry of pack
 * names and no index file: the directories ARE the list, which is what makes a
 * pack addable by dropping a file and why this command can never offer a pack
 * that the filesystem does not have.
 *
 * WHY BOTH DIRECTORIES. `rules/` is standing instruction the operator left on
 * and `rulebooks/` is instruction they expect to switch, but nothing in the
 * loader enforces that split - a file is a pack in either place. Listing only
 * `rulebooks/` would make `/rules` unable to see half the bytes in the prompt,
 * and an operator who wants to toggle `rules/no-prose.md` should not have to
 * move the file to earn the toggle. The two are therefore both toggleable and
 * the listing names which directory each came from, because that is the one
 * fact that distinguishes them. The INT-2 foreign directories are listed on the
 * same principle - a pack the prompt carries is a pack `/rules` must be able to
 * silence - and they answer the "which directory" question with a provenance
 * badge (`[claude]` / `[cursor]`) where the native pair answers it with the
 * directory word.
 *
 * WHAT A TOGGLE DOES NOT TOUCH. Only the user tier - a project's
 * `.candy-code/rules` and a checkout's `RULES.md` are the repository's voice,
 * and a session-scoped set of names is not where a user grants or withholds a
 * repository's authority. And nothing is persisted: no `onConfigChange` call
 * exists anywhere in this class, because a pack that silently came back OFF
 * after a restart would be indistinguishable from one the operator never turned
 * on, and the reverse mistake costs a prompt the user did not ask for. The
 * session-only boundary is pinned by a test that compares `config.json`'s bytes
 * across a toggle, not by this paragraph.
 *
 * THE FRONTMATTER STILL WINS. A pack whose own file says `enabled: false` stays
 * out of the prompt under a toggle that would otherwise switch it on, so this
 * command says so in the row rather than letting `/rules terse` report "on" for
 * a pack the prompt will not carry. The conjunction itself is computed once, in
 * {@see RulesState::effectiveRule()}; what lives here is only the wording that
 * explains which half of it held a pack back.
 */
final class RulesCommand
{
    /**
     * The `/rules` listing: header text => cell budget, in cells.
     *
     * Budgets rather than a hand-rolled pad, for the two reasons
     * {@see AgentsCommand::COLUMNS} records (a long name must clip, and every
     * column must keep its own budget regardless of its neighbours). They sum,
     * with {@see TranscriptTable::maxCells()}'s border overhead, to 68 cells,
     * and like that table's they are a STARTING POINT, NOT A CEILING: the
     * transcript pane is `max(20, cols() - 6)`, so {@see listPacks()} runs them
     * through {@see TranscriptTable::fit()} against the live pane width rather
     * than assuming an 80-column terminal.
     */
    private const COLUMNS = [
        'Pack' => 22,
        'State' => 26,
        'Source' => 14,
    ];

    /**
     * The column {@see TranscriptTable::fit()} may not shrink.
     *
     * `State` carries the sentence that tells the operator whether to toggle,
     * including the ` (frontmatter)` suffix that distinguishes a pack they turned
     * off from one their own file disables. `Pack` and `Source` are on-disk text
     * where an ellipsis reads as the abbreviation it is.
     */
    private const COLUMN_FLOORS = ['State' => 26];

    public function __construct(
        private readonly RuleLoader $loader,
        private readonly RulesState $state,
    ) {
    }

    /**
     * Execute /rules: list with no argument, toggle with one.
     *
     * @param Chat|CommandContext $chat The session the command runs in, for the pane width the table fits to
     * @param array $args  Parsed command arguments (from CommandParser)
     * @return int         Exit code: 0 on success, non-zero on failure
     */
    public function execute(Chat|CommandContext $chat, array $args = []): int
    {
        if ($args === []) {
            return $this->listPacks($chat, $this->packs());
        }

        // Only the first argument is read. `/rules a b` is not a batch and not a
        // typo to guess at: it is one name plus a stray token, and quietly
        // ignoring the stray token would make `/rules terse r` look like a
        // successful toggle of a pack called "terse r".
        $name = (string) $args[0];

        return $this->toggle($name, $args);
    }

    /**
     * Every toggleable pack this loader can see, in the order the loader emits
     * them (filename order within each directory, `rules/` and `rulebooks/`
     * before the INT-2 imports in their load order).
     *
     * Built from the four user-tier methods rather than {@see RuleLoader::load()}
     * on purpose: `load()` returns ENABLED rules only, so a pack that is off - by
     * either route - would vanish from the listing, and a command whose whole job
     * is to switch things back on would list only the ones that need no switching.
     *
     * @return list<Rule>
     */
    private function packs(): array
    {
        return [
            ...$this->loader->loadUserRules(),
            ...$this->loader->loadUserRulebooks(),
            ...$this->loader->loadUserClaudeRules(),
            ...$this->loader->loadUserCursorRules(),
        ];
    }

    /**
     * The listing, and the empty-directory answer that goes with it.
     *
     * @param list<Rule> $packs
     */
    private function listPacks(Chat|CommandContext $chat, array $packs): int
    {
        if ($packs === []) {
            echo "\n" . TranscriptTable::indented(Lang::t('cmd.rules.empty')) . "\n";

            return 0;
        }

        $labels = self::headers();
        $columns = TranscriptTable::fit(
            TranscriptTable::relabel(self::COLUMNS, $labels),
            TranscriptTable::paneWidth($chat),
            TranscriptTable::relabel(self::COLUMN_FLOORS, $labels),
        );
        $table = TranscriptTable::headed($columns);

        foreach ($packs as $rule) {
            $table = $table->row(
                TranscriptTable::cell($rule->key, $columns[$labels['Pack']]),
                TranscriptTable::cell($this->stateLabel($rule), $columns[$labels['State']]),
                TranscriptTable::cell($this->sourceLabel($rule), $columns[$labels['Source']]),
            );
        }

        echo "\n  " . Lang::t('cmd.rules.heading') . "\n\n";
        echo $table->render() . "\n\n";
        echo TranscriptTable::indented(Lang::t('cmd.rules.footer')) . "\n";

        // C-CTX-1.b: an imported pack switched off by its own `alwaysApply: false`
        // with no globs looks in the table exactly like a pack its file disabled
        // the native way - but its file never said `enabled: false`, so the
        // footer's frontmatter sentence is not true of it. One aggregate line
        // carries the real reason for all of them at once, never one per row.
        $offByAlwaysApply = $this->countAlwaysApplyOff($packs);
        if ($offByAlwaysApply > 0) {
            echo "\n" . TranscriptTable::indented(Lang::t(
                $offByAlwaysApply === 1 ? 'cmd.rules.alwaysapply_hint_one' : 'cmd.rules.alwaysapply_hint',
                ['count' => $offByAlwaysApply],
            )) . "\n";
        }

        return 0;
    }

    /**
     * Imported packs whose parse-time disable came purely from `alwaysApply: false`
     * beside no path patterns ({@see Rule::DISABLED_ALWAYS_APPLY}). Native files
     * are not counted - their own `enabled` key is the explanation the State
     * column already names - and globbed imports are not off at all under
     * C-CTX-1.b, so they cannot enter the count.
     *
     * @param list<Rule> $packs
     */
    private function countAlwaysApplyOff(array $packs): int
    {
        $count = 0;
        foreach ($packs as $rule) {
            if ($rule->origin !== Rule::ORIGIN_NATIVE && $rule->disabledBy === Rule::DISABLED_ALWAYS_APPLY) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Flip one pack by name, or refuse a name no pack has.
     *
     * @param non-empty-list<mixed> $args
     */
    private function toggle(string $name, array $args): int
    {
        $known = [];
        // How many PACKS answer to this name, not merely whether one does. The four
        // user directories are separate walks, each de-duplicated by key inside
        // {@see RuleLoader::loadFromDirectory()}, so a key appears at most once per
        // directory and at most FOUR times across {@see packs()} — which is what
        // makes the count in {@see collisionNote()} an exact figure rather than a
        // guess.
        $matches = [];
        foreach ($this->packs() as $rule) {
            $known[$rule->key] = $rule;
            $matches[$rule->key] = ($matches[$rule->key] ?? 0) + 1;
        }

        if (!isset($known[$name])) {
            // An error, and a NON-ZERO exit, never a silent no-op: a typo that
            // printed nothing would read as a successful toggle of a pack that
            // does not exist, and the operator would find out at the next
            // prompt that the rules they just switched off are still in it.
            //
            // The two lines below compose transcript text OUTSIDE
            // {@see TranscriptTable::cell()} - and `cell()` is where this surface's ANSI
            // neutralisation lives, because {@see \SugarCraft\Core\Util\Width::truncate()}
            // opens with {@see Ansi::strip()}. A hand-composed line therefore has to say
            // the strip itself: `$name` is whatever the operator typed and the array keys
            // are filename stems read off the operator's disk, so without it an escape
            // sequence reaches the transcript intact and surfaces as literal bracket-31m
            // text - the same defect
            // {@see \SugarCraft\Code\Tests\Commands\NoRawAnsiInTranscriptTest} exists
            // for, acquired at runtime where that source-reading guard cannot see it.
            echo "\n  " . Lang::t('cmd.rules.unknown', ['name' => Ansi::strip($name)]) . "\n";
            $knownNames = implode(', ', array_map(Ansi::strip(...), array_keys($known)));

            echo '  ' . (count($known) === 1
                ? Lang::t('cmd.rules.only-pack', ['names' => $knownNames])
                : Lang::t('cmd.rules.available', ['names' => $knownNames])) . "\n\n";

            return 1;
        }

        if (count($args) > 1) {
            // The arity refusal quotes the operator's bytes back at them, which is
            // the same hand-composed-outside-a-cell route as the two lines above,
            // so it carries the same defence.
            echo "\n  " . Lang::t('cmd.rules.arity', [
                'args' => implode(' ', array_map(Ansi::strip(...), array_map('strval', $args))),
            ]) . "\n";
            echo '  ' . Lang::t('cmd.rules.untoggled') . "\n\n";

            return 1;
        }

        $nowEnabled = $this->state->toggle($name);
        $rule = $known[$name];

        // The success line echoes the same untrusted name the lookup matched on,
        // and a match says only that a FILE on disk is spelled this way — stems are
        // disk bytes, not vetted ones, so this strip rides the same rule as above.
        echo "\n  " . Lang::t('cmd.rules.toggled', [
            'name' => Ansi::strip($name),
            'state' => $nowEnabled ? Lang::t('cmd.rules.toggled.on') : Lang::t('cmd.rules.toggled.off'),
            'note' => $this->collisionNote($matches[$name]),
        ]) . "\n";
        echo '  ' . $this->effectLine($rule, $nowEnabled) . "\n\n";

        return 0;
    }

    /**
     * The disclosure a shared pack name owes the operator, or `''` for a name only
     * one pack answers to.
     *
     * WHY THIS EXISTS. `~/.candy-code/rules/focus.md` and
     * `~/.candy-code/rulebooks/focus.md` are two packs, and one toggle turns both
     * of them off — that is the design, it is deliberate, and it is pinned where the
     * bytes are decided ({@see \SugarCraft\Code\Tests\Context\RuleLoaderTest::testTheSameStemInBothUserDirectoriesStaysTwoPacksToggledByOneName()}).
     * What was NOT pinned, and what this fixes, is the sentence the operator reads:
     * `Pack focus: OFF for this session.` in the SINGULAR about an action that just
     * silenced two files. The listing is honest about it because it has a `Source`
     * column; the toggle reply had no such column, so a user who toggled from a
     * remembered name and moved on took away the wrong arithmetic.
     *
     * The count is stated as a number rather than only "several" because the maximum
     * is four and the operator can check it: the value is "did I just switch off more
     * than the file I meant", and one row of the listing per directory makes that
     * legible. Two user directories set the old maximum; INT-2's imports raised it
     * to one pack per walked user directory.
     */
    private function collisionNote(int $matchingPacks): string
    {
        return $matchingPacks > 1
            ? Lang::t('cmd.rules.collision', ['count' => $matchingPacks])
            : '';
    }

    /**
     * The state cell for one pack: what the prompt will do, and which half of the
     * conjunction decided it.
     *
     * Reads the EFFECTIVE bit out of {@see RulesState::effectiveRule()} rather
     * than re-deriving it here, so this column and the splice cannot drift apart.
     */
    private function stateLabel(Rule $rule): string
    {
        if (!$this->state->effectiveRule($rule)->enabled) {
            return $rule->enabled ? Lang::t('cmd.rules.state.off-session') : Lang::t('cmd.rules.state.off-frontmatter');
        }

        return Lang::t('cmd.rules.state.on');
    }

    /**
     * Which user directory a pack came from - the one fact the name alone cannot
     * carry, and the reason two files in the two directories are not one pack.
     * The INT-2 imports answer with their provenance badge instead of a
     * directory word: `~/.claude/rules` is neither `rules/` nor `rulebooks/`,
     * and the tool that wrote the file is the fact worth showing.
     */
    private function sourceLabel(Rule $rule): string
    {
        $badge = $rule->badge();
        if ($badge !== '') {
            return $badge;
        }

        return str_ends_with(dirname($rule->path), '/rulebooks') ? 'rulebooks' : 'rules';
    }

    /**
     * The sentence after a toggle: what just happened to the prompt, including
     * the case where nothing happened to it because the file itself says off.
     */
    private function effectLine(Rule $rule, bool $nowEnabled): string
    {
        if (!$rule->enabled) {
            return Lang::t('cmd.rules.effect.self-disabled');
        }

        return $nowEnabled
            ? Lang::t('cmd.rules.effect.in')
            : Lang::t('cmd.rules.effect.out');
    }

    /**
     * Each {@see COLUMNS} id's header in the active locale.
     *
     * @return array<string, string>
     */
    private static function headers(): array
    {
        return [
            'Pack' => Lang::t('cmd.rules.column.pack'),
            'State' => Lang::t('cmd.rules.column.state'),
            'Source' => Lang::t('cmd.rules.column.source'),
        ];
    }
}
