<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Code\Commands\TranscriptTable;
use SugarCraft\Code\Context\Rule;
use SugarCraft\Code\Context\RuleLoader;
use SugarCraft\Code\Context\RulesState;
use SugarCraft\Code\Lang;

/**
 * `/output-style` — list the output styles on disk, or select one for this
 * session only (C-CTX-4.e).
 *
 * Usage:
 *   /output-style            — list every style with the state it is in
 *   /output-style <name>     — make that style the one active selection
 *   /output-style off        — clear the selection
 *
 * The idiom it copies is {@see RulesHostCommand}: capture the echoed output
 * with `ob_start()` because the listing writes to stdout, and a non-zero exit
 * becomes {@see CommandResult::failure()} so a bad name lands as a system
 * notice rather than as an assistant reply the provider would be shown on the
 * next turn.
 *
 * WHY THE LOGIC LIVES HERE AND NOT IN A `Commands\` SIBLING. `/rules` grew
 * that split while the TUI owned the screen; this command has exactly one
 * surface, and the two halves it needs — the walk and the slot on the shared
 * {@see \SugarCraft\Code\Context\RulesState} — are both one call deep already.
 * A second class would be a place for state to hide, not a place for state to
 * live.
 *
 * NO "not configured" degradation: the rules state is one every session has,
 * and the loader is built against {@see CommandContext::projectRoot()} — the
 * resolution the prompt splice uses, so the listing and the prompt cannot be
 * looking at different style directories. Selection mutates the shared
 * {@see RulesState} in place through its single slot, so the transcript and
 * the next prompt can never disagree about a style, and the one-of-one law is
 * the field's shape: a second selection REPLACES the first rather than
 * stacking beside it.
 *
 * WHAT A STYLE IS. Every markdown file in `~/.candy-code/styles/` or
 * `<repo>/.candy-code/styles/`, named by filename minus the extension — the
 * identity {@see RuleLoader::loadUserStyles()} and its project twin already
 * derive. There is no registry and no index file: the directories ARE the
 * list, which is what makes a style addable by dropping a file and this
 * command unable to offer a style the filesystem does not have.
 *
 * WHY THE LISTING READS THE WALKS, NOT `load()`. {@see RuleLoader::load()}
 * emits ENABLED rules only, so an unselected style — disabled by the very
 * selection machinery this command drives — would vanish from the listing, and
 * a command whose whole job is to switch styles ON would list only the one
 * already on. The two style walks return every style file with its parsed
 * frontmatter, and the State column then reports the selection's answer per
 * row, read back through {@see RulesState::effectiveRule()} so the column and
 * the splice cannot drift apart.
 */
final class OutputStyleHostCommand implements HostCommand
{
    /**
     * Column budgets keyed by the same display names {@see headers()} maps to
     * locale strings ({@see \SugarCraft\Code\Commands\RulesCommand::COLUMNS} for
     * the widths law).
     */
    private const COLUMNS = [
        'Style' => 22,
        'State' => 26,
        'Source' => 14,
    ];

    private const COLUMN_FLOORS = ['State' => 26];

    public function run(CommandContext $context, string $text): CommandResult
    {
        $args = CommandText::words($text);

        ob_start();
        try {
            $exitCode = $args === []
                ? $this->listStyles($context)
                : $this->select($context, (string) $args[0], $args);
        } finally {
            $output = (string) ob_get_clean();
        }

        return $exitCode === 0
            ? CommandResult::reply($text, $output)
            : CommandResult::failure($text, $output, $exitCode);
    }

    /**
     * Every style file either styles directory holds, in load order: the
     * operator's own walk first, the project's after — the same ordering the
     * splice sees, so a shared name lists user-first exactly as it rides
     * user-first.
     *
     * @return list<Rule>
     */
    private function styles(CommandContext $context): array
    {
        $loader = new RuleLoader($context->projectRoot());

        return [
            ...$loader->loadUserStyles(),
            ...$loader->loadProjectStyles(),
        ];
    }

    private function listStyles(CommandContext $context): int
    {
        $styles = $this->styles($context);
        if ($styles === []) {
            echo "\n" . TranscriptTable::indented(Lang::t('host.output_style.empty')) . "\n";

            return 0;
        }

        $active = $context->rulesState->activeStyle();
        $labels = self::headers();
        $columns = TranscriptTable::fit(
            TranscriptTable::relabel(self::COLUMNS, $labels),
            TranscriptTable::paneWidth($context),
            TranscriptTable::relabel(self::COLUMN_FLOORS, $labels),
        );
        $table = TranscriptTable::headed($columns);

        foreach ($styles as $rule) {
            $table = $table->row(
                TranscriptTable::cell($rule->key, $columns[$labels['Style']]),
                TranscriptTable::cell($this->stateLabel($rule, $active), $columns[$labels['State']]),
                TranscriptTable::cell($this->sourceLabel($rule), $columns[$labels['Source']]),
            );
        }

        echo "\n  " . Lang::t('host.output_style.heading') . "\n\n";
        echo $table->render() . "\n\n";
        echo TranscriptTable::indented(Lang::t('host.output_style.footer')) . "\n";

        return 0;
    }

    /**
     * Select one style by key — or clear the slot with the reserved word
     * `off` — and report which way it went.
     *
     * @param list<string> $args
     */
    private function select(CommandContext $context, string $name, array $args): int
    {
        // Only the first argument is read. `/output-style terse now` is not a
        // batch and not a typo to guess at: it is one name plus a stray token,
        // and quietly ignoring the stray token would make the reply read as a
        // selection of a style called "terse now".
        if (count($args) > 1) {
            // The arity refusal quotes the operator's bytes back at them — a
            // hand-composed line outside TranscriptTable::cell(), so it says
            // the ANSI strip itself, exactly as RulesCommand::toggle() argues.
            echo "\n  " . Lang::t('host.output_style.arity', [
                'args' => implode(' ', array_map(Ansi::strip(...), array_map('strval', $args))),
            ]) . "\n\n";

            return 1;
        }

        if ($name === 'off') {
            if ($context->rulesState->activeStyle() === null) {
                echo "\n  " . Lang::t('host.output_style.nothing_active') . "\n\n";

                return 0;
            }

            $context->rulesState->clearStyle();
            echo "\n  " . Lang::t('host.output_style.cleared') . "\n\n";

            return 0;
        }

        $known = [];
        // How many FILES answer to this name, not merely whether one does. The
        // two styles directories are separate walks, each de-duplicated by
        // realpath, so a key appears once per directory and at most twice
        // across styles() — the count below is exact, and selecting a shared
        // name rides both files, which the note says out loud.
        $matches = [];
        foreach ($this->styles($context) as $rule) {
            $known[$rule->key] = $rule;
            $matches[$rule->key] = ($matches[$rule->key] ?? 0) + 1;
        }

        if (!isset($known[$name])) {
            // An error, and a NON-ZERO exit, never a silent no-op: a typo that
            // printed nothing would read as a successful selection, and the
            // operator would find out at the next prompt that the style they
            // meant never rode. The lookup matched nothing, but the typed
            // bytes still reach the transcript, so they are stripped here.
            echo "\n  " . Lang::t('host.output_style.unknown', ['name' => Ansi::strip($name)]) . "\n";
            $knownNames = implode(', ', array_map(Ansi::strip(...), array_keys($known)));

            echo '  ' . Lang::t('host.output_style.available', ['names' => $knownNames]) . "\n\n";

            return 1;
        }

        $context->rulesState->setStyle($name);
        $note = $matches[$name] > 1 ? Lang::t('host.output_style.matches', ['count' => $matches[$name]]) : '';
        // A file whose OWN frontmatter says enabled: false was not put on the
        // prompt by this keystroke — the selection recorded the intent and the
        // frontmatter still decides. Say which of the two the operator just
        // moved, because "ACTIVE" alone would promise bytes that do not ride.
        $selfDisabled = $known[$name]->enabled ? '' : Lang::t('host.output_style.self_disabled');

        echo "\n  " . Lang::t('host.output_style.set', [
            'name' => Ansi::strip($name),
            'note' => $note . $selfDisabled,
        ]) . "\n\n";

        return 0;
    }

    /**
     * The state cell for one style: ACTIVE iff the session's single selection
     * answers to this key AND the file's own frontmatter agrees; "off
     * (frontmatter)" names the file's own no; plain "off" names the slot's.
     *
     * The two halves are exactly the conjunction
     * {@see RulesState::effectiveRule()} applies to a style — the walk hands
     * over the frontmatter bit unpunctured, and the slot is read from the same
     * state the splice consults — so this column and the prompt cannot drift
     * apart, which is the law {@see \SugarCraft\Code\Commands\RulesCommand::stateLabel()}
     * pins for the pack family.
     */
    private function stateLabel(Rule $rule, ?string $active): string
    {
        if (!$rule->enabled) {
            return Lang::t('host.output_style.state_off_frontmatter');
        }

        return $active !== null && $rule->key === $active
            ? Lang::t('host.output_style.state_active')
            : Lang::t('host.output_style.state_off');
    }

    /**
     * Which styles directory a file came from — the one fact the name alone
     * cannot carry, and the reason two files in the two directories are not
     * one row.
     */
    private function sourceLabel(Rule $rule): string
    {
        return $rule->tier === 'user' ? 'user' : 'project';
    }

    /**
     * Each {@see COLUMNS} id's header in the active locale.
     *
     * @return array<string, string>
     */
    private static function headers(): array
    {
        return [
            'Style' => Lang::t('host.output_style.column_style'),
            'State' => Lang::t('host.output_style.column_state'),
            'Source' => Lang::t('host.output_style.column_source'),
        ];
    }
}
