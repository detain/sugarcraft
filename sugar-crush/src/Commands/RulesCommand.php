<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Commands;

use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Context\Rule;
use SugarCraft\Crush\Context\RuleLoader;
use SugarCraft\Crush\Context\RulesState;

/**
 * Implements the /rules command: list the operator's rule packs, or toggle one
 * for the rest of this session.
 *
 * Usage:
 *   /rules           — list every pack with the state it is in and why
 *   /rules <name>    — flip that pack and report which way it went
 *
 * This is a SugarCraft architecture type, not a port - charmbracelet/crush has
 * no `RulesCommand` symbol, so the repo's "Mirrors charmbracelet/..." convention
 * does not apply. The idiom it copies is {@see AgentsCommand}: a final class
 * holding its collaborators, an `execute()` that returns an exit code, and output
 * written to stdout for {@see \SugarCraft\Crush\Chat::handleRulesCommand()} to
 * capture - which is the only correct shape now that the screen belongs to the
 * frame renderer.
 *
 * WHAT A PACK IS. Every `*.md` file in the operator's two user directories
 * (`~/.sugar-crush/rulebooks/` and `~/.sugar-crush/rules/`) is a pack, named by
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
 * fact that distinguishes them.
 *
 * WHAT A TOGGLE DOES NOT TOUCH. Only the user tier - a project's
 * `.sugar-crush/rules` and a checkout's `RULES.md` are the repository's voice,
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
     * @param Chat  $chat  The current chat session, for the pane width the table fits to
     * @param array $args  Parsed command arguments (from CommandParser)
     * @return int         Exit code: 0 on success, non-zero on failure
     */
    public function execute(Chat $chat, array $args = []): int
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
     * them (filename order within each directory, `rules/` before `rulebooks/`).
     *
     * Built from the two user-tier methods rather than {@see RuleLoader::load()}
     * on purpose: `load()` returns ENABLED rules only, so a pack that is off - by
     * either route - would vanish from the listing, and a command whose whole job
     * is to switch things back on would list only the ones that need no switching.
     *
     * @return list<Rule>
     */
    private function packs(): array
    {
        return [...$this->loader->loadUserRules(), ...$this->loader->loadUserRulebooks()];
    }

    /**
     * The listing, and the empty-directory answer that goes with it.
     *
     * @param list<Rule> $packs
     */
    private function listPacks(Chat $chat, array $packs): int
    {
        if ($packs === []) {
            echo "\n  No rule packs found.\n";
            echo "  A pack is one markdown file in ~/.sugar-crush/rulebooks/ (or ~/.sugar-crush/rules/),\n";
            echo "  named by its filename: terse.md is toggled with /rules terse.\n\n";

            return 0;
        }

        $columns = TranscriptTable::fit(self::COLUMNS, TranscriptTable::paneWidth($chat), self::COLUMN_FLOORS);
        $table = TranscriptTable::headed($columns);

        foreach ($packs as $rule) {
            $table = $table->row(
                TranscriptTable::cell($rule->key, $columns['Pack']),
                TranscriptTable::cell($this->stateLabel($rule), $columns['State']),
                TranscriptTable::cell($this->sourceLabel($rule), $columns['Source']),
            );
        }

        echo "\n  Rule packs (session only — nothing here is written to config):\n\n";
        echo $table->render() . "\n\n";
        echo "  /rules <name> toggles one. A pack marked frontmatter is disabled by its\n";
        echo "  own file and stays out of the prompt either way.\n\n";

        return 0;
    }

    /**
     * Flip one pack by name, or refuse a name no pack has.
     *
     * @param non-empty-list<mixed> $args
     */
    private function toggle(string $name, array $args): int
    {
        $known = [];
        // How many PACKS answer to this name, not merely whether one does. The two
        // user directories are separate walks, each de-duplicated by key inside
        // {@see RuleLoader::loadFromDirectory()}, so a key appears at most once per
        // directory and at most TWICE across {@see packs()} — which is what makes
        // "both" in {@see collisionNote()} an exact figure rather than a guess.
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
            echo "\n  Unknown rule pack: {$name}\n";
            echo count($known) === 1
                ? "  The only pack here is: " . implode(', ', array_keys($known)) . "\n\n"
                : '  Available packs: ' . implode(', ', array_keys($known)) . "\n\n";

            return 1;
        }

        if (count($args) > 1) {
            echo "\n  /rules takes one pack name; read it as \"" . implode(' ', array_map('strval', $args)) . "\".\n";
            echo "  Nothing was toggled.\n\n";

            return 1;
        }

        $nowEnabled = $this->state->toggle($name);
        $rule = $known[$name];

        echo "\n  Pack {$name}: " . ($nowEnabled ? 'ON' : 'OFF') . " for this session."
            . $this->collisionNote($matches[$name]) . "\n";
        echo '  ' . $this->effectLine($rule, $nowEnabled) . "\n\n";

        return 0;
    }

    /**
     * The disclosure a shared pack name owes the operator, or `''` for a name only
     * one pack answers to.
     *
     * WHY THIS EXISTS. `~/.sugar-crush/rules/focus.md` and
     * `~/.sugar-crush/rulebooks/focus.md` are two packs, and one toggle turns both
     * of them off — that is the design, it is deliberate, and it is pinned where the
     * bytes are decided ({@see \SugarCraft\Crush\Tests\Context\RuleLoaderTest::testTheSameStemInBothUserDirectoriesStaysTwoPacksToggledByOneName()}).
     * What was NOT pinned, and what this fixes, is the sentence the operator reads:
     * `Pack focus: OFF for this session.` in the SINGULAR about an action that just
     * silenced two files. The listing is honest about it because it has a `Source`
     * column; the toggle reply had no such column, so a user who toggled from a
     * remembered name and moved on took away the wrong arithmetic.
     *
     * The count is stated as a number rather than only "several" because the maximum
     * is two and the operator can check it: the value is "did I just switch off more
     * than the file I meant", and one row of the listing per directory makes that
     * legible.
     */
    private function collisionNote(int $matchingPacks): string
    {
        return $matchingPacks > 1
            ? " (this name matches {$matchingPacks} packs — both toggled)"
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
            return $rule->enabled ? 'off (session)' : 'off (frontmatter)';
        }

        return 'on';
    }

    /**
     * Which user directory a pack came from - the one fact the name alone cannot
     * carry, and the reason two files in the two directories are not one pack.
     */
    private function sourceLabel(Rule $rule): string
    {
        return str_ends_with(dirname($rule->path), '/rulebooks') ? 'rulebooks' : 'rules';
    }

    /**
     * The sentence after a toggle: what just happened to the prompt, including
     * the case where nothing happened to it because the file itself says off.
     */
    private function effectLine(Rule $rule, bool $nowEnabled): string
    {
        if (!$rule->enabled) {
            return 'Its own frontmatter says enabled: false, so it stays out of the prompt'
                . ' until that line changes - the toggle could not override a file that disabled itself.';
        }

        return $nowEnabled
            ? 'It will be in the prompt from the next turn onward.'
            : 'It will be out of the prompt from the next turn onward.';
    }
}
