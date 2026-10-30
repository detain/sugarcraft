<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Code\Cli\DoctorChecks;
use SugarCraft\Code\Commands\TranscriptTable;
use SugarCraft\Code\Lang;

/**
 * `/doctor` — the install checks, read from inside the running session.
 *
 * `candy-code doctor` answers "is this install sound?" before a session
 * exists; this answers the same question once one is running, which is when it
 * starts getting asked: a config edit made mid-session has just changed what
 * the permission policy row reports, a `bashSandbox` spelling has stopped the
 * jail from starting, or the FTS5 probe has begun warning on a box that did not
 * before. Leaving for the shell to find out is the wrong shape of work, and
 * asking the model to run it is a worse one — {@see \SugarCraft\Code\Tools\BuiltIn\Doctor}
 * is the model-facing tool for that, and it reads this same roster through the
 * CLI it shells out to.
 *
 * Every row comes from {@see DoctorChecks::run()}, so the two surfaces cannot
 * disagree: this method only formats. Arguments are IGNORED, for the reason
 * `/status` states — the report is already total, and an operand that asked for
 * LESS would read as a different answer from the same command rather than a
 * narrower one. A FAIL changes the verdict line, not the result: a command that
 * diagnosed a broken install has succeeded, and {@see CommandResult::reply()}
 * is the only honest carrier for a diagnosis the model must not be shown as an
 * error it caused.
 */
final class DoctorHostCommand implements HostCommand
{
    /**
     * Column ids are ENGLISH and never localised — the keys of
     * {@see self::headers()}; widths are the natural budget, overridable by
     * {@see TranscriptTable::fit()}. `Detail` is widest because it is the only
     * column that carries a sentence; `Check` is short because the labels are a
     * fixed roster, and `Status` is `FAIL`-wide by its floor — a clipped
     * `FAI…` is not a verdict.
     */
    private const COLUMNS = [
        'Status' => 8,
        'Check' => 20,
        'Detail' => 56,
    ];

    private const COLUMN_FLOORS = ['Status' => 6, 'Check' => 14];

    public function run(CommandContext $context, string $text): CommandResult
    {
        return CommandResult::reply($text, $this->report($context));
    }

    /**
     * The whole report as one transcript body: heading, one row per check, the
     * verdict line in the SAME words the CLI prints.
     */
    private function report(CommandContext $context): string
    {
        $checks = DoctorChecks::run($context->root);
        $failed = \array_filter($checks, static fn (array $check): bool => $check['status'] === 'FAIL');

        $labels = self::headers();
        $columns = TranscriptTable::fit(
            TranscriptTable::relabel(self::COLUMNS, $labels),
            TranscriptTable::paneWidth($context),
            TranscriptTable::relabel(self::COLUMN_FLOORS, $labels),
        );
        $table = TranscriptTable::headed($columns);

        foreach ($checks as $check) {
            $table = $table->row(
                TranscriptTable::cell($check['status'], $columns[$labels['Status']]),
                TranscriptTable::cell($check['label'], $columns[$labels['Check']]),
                TranscriptTable::cell($check['detail'], $columns[$labels['Detail']]),
            );
        }

        return "\n  " . Lang::t('host.doctor.heading') . "\n\n"
            . $table->render() . "\n\n"
            . TranscriptTable::indented(match (\count($failed)) {
                0 => Lang::t('cli.doctor.no_problems'),
                1 => Lang::t('cli.doctor.failed.one', ['count' => 1]),
                default => Lang::t('cli.doctor.failed.many', ['count' => \count($failed)]),
            });
    }

    /**
     * Each {@see COLUMNS} id's header in the active locale.
     *
     * @return array<string, string>
     */
    private static function headers(): array
    {
        return [
            'Status' => Lang::t('host.doctor.column_status'),
            'Check' => Lang::t('host.doctor.column_check'),
            'Detail' => Lang::t('host.doctor.column_detail'),
        ];
    }
}
