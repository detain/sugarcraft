<?php

declare(strict_types=1);

namespace SugarCraft\Code\Commands;

use SugarCraft\Code\Chat;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Hooks\HookManager;

/**
 * Implements `/hooks` (roadmap VIS-3a): the launch's hook chain, read-only.
 *
 * Usage:
 *   /hooks — every registered hook by event, its matcher, whether it is
 *            disabled or a shipped built-in, and the matchers that could not
 *            be evaluated (the registry fail-OPENS those into "treated as
 *            matching", so naming them is the user's only cue to narrow one)
 *
 * READ-ONLY like `/context` and `/notices`: it reads the chain and prints;
 * it never registers, disables or executes a hook, and calls no model.
 *
 * The wording lives in {@see compose()}, kept pure on the array
 * {@see HookManager::inspect()} yields so a test pins a report without
 * building a chain. Every name and matcher is foreign text (a hooks.yaml is
 * whatever its author wrote), so each goes through
 * {@see Chat::reportField()} like every other quoted transcript field.
 *
 * This is a SugarCraft architecture type, not a port — charmbracelet/crush
 * has no `/hooks`; the surface answers the question `docs/HOOKS.md` cannot:
 * which entries actually reached THIS launch.
 */
final class HooksCommand
{
    /**
     * The table: header text => cell budget, in cells. `Event` fits the
     * longest {@see \SugarCraft\Code\Hooks\HookEvent} name; `Hook` and
     * `Matcher` carry foreign text and are the two columns
     * {@see TranscriptTable::fit()} may clip.
     */
    private const COLUMNS = [
        'Event' => 17,
        'Hook' => 22,
        'Matcher' => 18,
        'State' => 20,
    ];

    /** `Event` names an enum case (clipping it invents events); `State` is a label. */
    private const COLUMN_FLOORS = ['Event' => 17, 'State' => 12];

    /**
     * Render an inspection report.
     *
     * @param array{hooks: array<string, list<array{name: string, matcher: string, disabled: bool, builtIn: bool}>>, matcherFailures: array<string, string>}|null $inspect
     *        the chain as {@see HookManager::inspect()} read it, or null when
     *        it could not be read at all — "not measured", never a confident
     *        empty table
     */
    public static function compose(?array $inspect, int $paneWidth): string
    {
        if ($inspect === null) {
            return Lang::t('cmd.hooks.unmeasured');
        }

        $lines = [Lang::t('cmd.hooks.heading'), ''];

        $labels = self::headers();
        $columns = TranscriptTable::fit(
            TranscriptTable::relabel(self::COLUMNS, $labels),
            $paneWidth,
            TranscriptTable::relabel(self::COLUMN_FLOORS, $labels),
        );
        $table = TranscriptTable::headed($columns);
        $rows = 0;
        foreach ($inspect['hooks'] as $event => $hooks) {
            foreach ($hooks as $hook) {
                ++$rows;
                $state = $hook['disabled']
                    ? Lang::t('cmd.hooks.state.disabled')
                    : Lang::t('cmd.hooks.state.enabled');
                if ($hook['builtIn']) {
                    $state .= ' ' . Lang::t('cmd.hooks.built-in');
                }
                $table = $table->row(
                    TranscriptTable::cell($event, $columns[$labels['Event']]),
                    TranscriptTable::cell(Chat::reportField($hook['name']), $columns[$labels['Hook']]),
                    TranscriptTable::cell(Chat::reportField($hook['matcher']), $columns[$labels['Matcher']]),
                    TranscriptTable::cell($state, $columns[$labels['State']]),
                );
            }
        }

        if ($rows === 0) {
            $lines[] = '  - ' . Lang::t('cmd.hooks.none');
        } else {
            $lines[] = $table->render();
        }

        if ($inspect['matcherFailures'] !== []) {
            $lines[] = '';
            $lines[] = Lang::t('cmd.hooks.failures');
            foreach ($inspect['matcherFailures'] as $matcher => $error) {
                $lines[] = '  ' . Lang::t('cmd.hooks.failure.row', [
                    'matcher' => Chat::reportField((string) $matcher),
                    'error' => Chat::reportField($error),
                ]);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Each {@see COLUMNS} id's header in the active locale.
     *
     * @return array<string, string>
     */
    private static function headers(): array
    {
        return [
            'Event' => Lang::t('cmd.hooks.column.event'),
            'Hook' => Lang::t('cmd.hooks.column.hook'),
            'Matcher' => Lang::t('cmd.hooks.column.matcher'),
            'State' => Lang::t('cmd.hooks.column.state'),
        ];
    }
}
