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
 *   /hooks log [n] — the newest runs of configured (non-built-in) hooks from
 *            the hook-log file (C-EXT-6.b), this session's unless the host
 *            carries no session id; [n] caps how many rows are shown
 *
 * READ-ONLY like `/context` and `/notices`: it reads the chain and the log
 * file and prints; it never registers, disables or executes a hook, and
 * calls no model.
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
     * The hook-log table (C-EXT-6.b): header text => cell budget. `Time`
     * carries the log's own `Y-m-d H:i:s` stamp, `Verdict` one of the four
     * {@see \SugarCraft\Code\Hooks\HookResult} action words, `ms` a rounded
     * integer; `Hook` and `Message` carry foreign text (hook names come from
     * whatever YAML its author wrote, messages from whatever the script
     * printed) and are the two columns {@see TranscriptTable::fit()} may clip.
     */
    private const LOG_COLUMNS = [
        'Time' => 19,
        'Event' => 17,
        'Hook' => 22,
        'Verdict' => 8,
        'ms' => 6,
        'Message' => 30,
    ];

    /** Time and Event are derived facts — clipping one invents a timestamp. */
    private const LOG_COLUMN_FLOORS = ['Time' => 19, 'Event' => 17];

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
     * Render hook-log rows (the {@see \SugarCraft\Code\Hooks\HookLog::tail()}
     * output, already oldest-first and session-filtered by the reader). Pure
     * wording over row arrays, so a test pins the table without a log file —
     * the same contract {@see compose()} keeps for the chain panel.
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function composeLog(array $rows, int $paneWidth): string
    {
        $lines = [Lang::t('cmd.hooks.log.heading'), ''];

        $labels = self::logHeaders();
        $columns = TranscriptTable::fit(
            TranscriptTable::relabel(self::LOG_COLUMNS, $labels),
            $paneWidth,
            TranscriptTable::relabel(self::LOG_COLUMN_FLOORS, $labels),
        );
        $table = TranscriptTable::headed($columns);

        if ($rows === []) {
            $lines[] = '  - ' . Lang::t('cmd.hooks.log.none');

            return implode("\n", $lines);
        }

        foreach ($rows as $row) {
            $table = $table->row(
                TranscriptTable::cell((string) ($row['ts'] ?? ''), $columns[$labels['Time']]),
                TranscriptTable::cell((string) ($row['event'] ?? ''), $columns[$labels['Event']]),
                TranscriptTable::cell(Chat::reportField((string) ($row['hook'] ?? '')), $columns[$labels['Hook']]),
                TranscriptTable::cell(self::verdictLabel((string) ($row['verdict'] ?? '')), $columns[$labels['Verdict']]),
                TranscriptTable::cell((string) (int) ($row['ms'] ?? 0), $columns[$labels['ms']]),
                TranscriptTable::cell(Chat::reportField((string) ($row['message'] ?? '')), $columns[$labels['Message']]),
            );
        }

        $lines[] = $table->render();

        return implode("\n", $lines);
    }

    /**
     * The four HookResult actions get catalogue labels; anything else (a
     * verdict no shipped result can produce) is echoed verbatim rather than
     * mislabelled — a log surface that guesses is worse than one that quotes.
     */
    private static function verdictLabel(string $action): string
    {
        return match ($action) {
            'allow' => Lang::t('cmd.hooks.log.verdict.allow'),
            'deny' => Lang::t('cmd.hooks.log.verdict.deny'),
            'modify' => Lang::t('cmd.hooks.log.verdict.modify'),
            'ask' => Lang::t('cmd.hooks.log.verdict.ask'),
            default => $action,
        };
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

    /**
     * Each {@see LOG_COLUMNS} id's header in the active locale.
     *
     * @return array<string, string>
     */
    private static function logHeaders(): array
    {
        return [
            'Time' => Lang::t('cmd.hooks.log.column.time'),
            'Event' => Lang::t('cmd.hooks.log.column.event'),
            'Hook' => Lang::t('cmd.hooks.log.column.hook'),
            'Verdict' => Lang::t('cmd.hooks.log.column.verdict'),
            'ms' => Lang::t('cmd.hooks.log.column.ms'),
            'Message' => Lang::t('cmd.hooks.log.column.message'),
        ];
    }
}
