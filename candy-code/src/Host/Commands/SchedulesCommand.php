<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Code\Background\Schedule;
use SugarCraft\Code\Background\ScheduleFactory;
use SugarCraft\Code\Background\ScheduleRegistry;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Permissions\PermissionMode;

/**
 * `/schedules` (alias `/schedule`) — the read and control surface for
 * AUT-1 rows: `list` (also the bare form), `add <name> <interval> <prompt>
 * [--mode <mode>] [--provider <name>] [--heartbeat]`, `enable <name>`,
 * `disable <name>`, `delete <name>`.
 *
 * C-AGT-5.c adds the `--heartbeat` switch: it stamps the created row's kind
 * (see {@see Schedule}'s heartbeat paragraph for the contract — the spawned
 * prompt gains the HEARTBEAT_OK instruction, and a run answering exactly that
 * reports nothing into the transcript). Listing marks heartbeat rows with
 * their own kind word; the switch never changes firing, mode, or cap maths.
 *
 * READ-ONLY JUDGEMENT (the plan asks for it explicitly): LISTING is admitted
 * in a read-only window — it reads this process's roster and one 0600 file,
 * and changes nothing; the mutating verbs are not, because adding,
 * enabling, disabling, and deleting persist consent-bearing rows that later
 * turns will act on. `/loop` is absent from the read-only set for the same
 * reason seen from the other side: it programs future turns.
 *
 * CREATION goes through {@see ScheduleFactory} since C-AGT-5.b — the same
 * single path the Schedule tool uses, so the mode ceiling, the model-row cap,
 * the root binding, and the one-interval arming cannot drift between the two
 * surfaces (a hand-rolled second creator was the whole reason the factory
 * exists). The created row carries the model tag and counts against its
 * project's cap from either door. The config-authority posture is unchanged
 * for the file itself: hand-written rows stay exactly as loaded, and a
 * corrupt file or a corrupt ROW never crashes the launch — both are listed
 * loudly here, and the good rows keep firing. A row whose permission mode the
 * floor coerced keeps firing too, announced beside itself as a note — the
 * coercion is loud, never silent.
 *
 * `enable`/`disable`/`delete` on a PERSISTED row write schedules.json back
 * through the registry before answering, so the change survives the session;
 * on an ephemeral row they touch only memory, and the reply says so.
 *
 * The listing spells each row's project binding (C-AGT-1) — the directory a
 * persisted row fires in, or the word `unbound` for a row no host will run.
 * Because the file is shared by every host on the machine, that is the one
 * piece of information an operator cannot get from the row's name. Since
 * C-AGT-5.b each row also carries its LAST fireDue outcome when this process
 * has evaluated it — fired, an error, or which skip applied — so the refusals
 * that used to be quiet (the serve host has no transcript) read beside the
 * row that earned them.
 */
final class SchedulesCommand implements HostCommand
{
    /** Sub-commands that read; anything else here mutates. */
    public const READ_VERBS = ['list'];

    public function run(CommandContext $context, string $text): CommandResult
    {
        $registry = $context->scheduleRegistry;
        if ($registry === null) {
            return CommandResult::errorReply($text, Lang::t('host.schedules.not_configured'));
        }
        // Read the roster as it stands on disk THIS instant: a host whose pump
        // has not ticked yet (no armed subscription while every row sat
        // disabled) must still list what the file says.
        $registry->reloadIfChanged();

        $words = CommandText::tokens($text);
        $verb = strtolower($words[1] ?? 'list');

        return match ($verb) {
            'list' => CommandResult::reply($text, self::listing($registry)),
            'add' => self::add($text, $context, $registry),
            'enable', 'disable' => self::flip($text, $registry, $words, $verb === 'enable'),
            'delete' => self::delete($text, $registry, $words),
            default => CommandResult::errorReply($text, Lang::t('host.schedules.usage')),
        };
    }

    /** Whether `/schedules …` as typed only reads (the Chat-side read-only gate asks this). */
    public static function isReadOnlyVerb(string $verb): bool
    {
        return \in_array(strtolower($verb), self::READ_VERBS, true);
    }

    /**
     * `add <name> <interval> <prompt> [--mode <mode>] [--provider <name>]` —
     * creation through the {@see ScheduleFactory}, the tool's own single path.
     * The interval takes `/loop`'s grammar ({@see LoopCommand::intervalSeconds});
     * everything the factory refuses (shape, ceiling, cap, missing root)
     * reaches the speaker verbatim, exactly as a caught tool error would —
     * these sentences are the factory's voice, pinned in its own tests, and
     * the command is a second mouth for the same policy, not a second policy.
     *
     * The flags are peeled out before the prompt is read, and the prompt that
     * remains rides VERBATIM (the multi-line contract `/loop` keeps): a flag
     * that arrives without a value is refused rather than swallowed into the
     * task text. `--mode` is not a floor-breaker either way — the factory's
     * ceiling refuses bypass-class spellings BY NAME from whichever door.
     */
    private static function add(string $text, CommandContext $context, ScheduleRegistry $registry): CommandResult
    {
        $argument = CommandText::argument($text);
        if (preg_match('/^add\s+(\S+)\s+(\S+)\s+(.+)$/s', $argument, $m) !== 1) {
            return CommandResult::errorReply($text, Lang::t('host.schedules.add_usage'));
        }
        $name = $m[1];
        $seconds = LoopCommand::intervalSeconds($m[2]);
        if ($seconds === null) {
            return CommandResult::errorReply($text, Lang::t('host.schedules.add_bad_interval', ['interval' => $m[2]]));
        }

        $mode = null;
        $provider = null;
        $rest = $m[3];
        // C-AGT-5.c: `--heartbeat` carries NO value, so it is peeled before
        // the mode/provider accounting below — that counter reads a flag as
        // misspelled unless a real value follows it, and a valueless switch
        // would trip its guard. Repeats are accepted and collapsed: the row is
        // heartbeat either way.
        $heartbeat = preg_match('/(?:^|\s)--heartbeat\b/', $rest) === 1;
        $rest = preg_replace('/(?:^|\s)--heartbeat\b/', '', $rest) ?? $rest;
        // A flag token not followed by a real value (end of string, or glued
        // to another flag) is a refusal, not a prompt word. Values never
        // start with '-': a mode/provider spelling cannot, and the guard
        // keeps `--mode --provider x` from stealing the second flag.
        $flags = preg_match_all('/(?:^|\s)--(mode|provider)(?:=|\s+)(?=[^\s-])/', $rest, $withValue) ?: 0;
        $spelled = preg_match_all('/(?:^|\s)--(mode|provider)\b/', $rest, $ignored) ?: 0;
        if ($spelled !== $flags) {
            preg_match('/(?:^|\s)--(mode|provider)\b/', $rest, $firstBad);

            return CommandResult::errorReply($text, Lang::t('host.schedules.add_flag', ['flag' => strtolower($firstBad[1] ?? 'mode')]));
        }
        $rest = preg_replace_callback(
            '/(?:^|\s)--(mode|provider)(?:=|\s+)(\S+)/',
            static function (array $hit) use (&$mode, &$provider): string {
                if (strtolower($hit[1]) === 'mode') {
                    $mode = $hit[2];
                } else {
                    $provider = $hit[2];
                }

                return '';
            },
            $rest,
        ) ?? $rest;
        $prompt = trim($rest);
        if ($prompt === '') {
            return CommandResult::errorReply($text, Lang::t('host.schedules.add_usage'));
        }

        try {
            $schedule = ScheduleFactory::create(
                [
                    'name' => $name,
                    'every' => $seconds,
                    'prompt' => $prompt,
                    'provider' => $provider,
                    'permission_mode' => $mode,
                    'kind' => $heartbeat ? Schedule::KIND_HEARTBEAT : Schedule::KIND_TASK,
                ],
                $context->root,
                $context->permissionGate?->mode() ?? PermissionMode::Default,
                $registry,
                time(),
            );
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return CommandResult::errorReply($text, $e->getMessage());
        }

        return CommandResult::reply($text, Lang::t('host.schedules.added', [
            'name' => $schedule->name,
            'every' => LoopCommand::everyLabel($schedule->everySeconds),
            'prompt' => $schedule->prompt,
            'next' => date('Y-m-d H:i:s', $schedule->nextDue),
        ]));
    }

    /**
     * The `list` body: one line per row (file or loop), then the load notes
     * — refusals, coerced permission modes, and unbound-root rows alike —
     * so a hand-edit mistake reads HERE rather than only in the absence of
     * a fire. A persisted row with no `root` is listed as unbound and its
     * note says plainly that no host will ever fire it.
     */
    public static function listing(ScheduleRegistry $registry): string
    {
        $rows = $registry->all();
        if ($rows === []) {
            $head = Lang::t('host.schedules.none');
        } else {
            $lines = [];
            foreach ($rows as $row) {
                $line = Lang::t('host.schedules.row', [
                    'name' => $row->name,
                    'state' => $row->enabled ? Lang::t('host.schedules.on') : Lang::t('host.schedules.off'),
                    // C-AGT-5.c: a heartbeat row keeps its (file) marker and
                    // gains a second kind word — which line rings, at a glance.
                    'kind' => $row->persisted
                        ? Lang::t('host.schedules.kind_file') . ($row->kind === Schedule::KIND_HEARTBEAT ? Lang::t('host.schedules.kind_heartbeat') : '')
                        : Lang::t('host.schedules.kind_loop'),
                    'every' => LoopCommand::everyLabel($row->everySeconds),
                    'next' => date('Y-m-d H:i:s', $row->nextDue),
                    // C-AGT-1: a file row's own binding, spelled, or the word
                    // that says no host will ever run it.
                    'root' => $row->root ?? Lang::t('host.schedules.unbound'),
                    'prompt' => self::clip($row->prompt),
                ]);
                // C-AGT-5.b: this process's last decision about the row, when
                // it has made one. Rows it never evaluated keep the legacy
                // line byte-for-byte (an outcome nothing recorded is not a
                // "no outcome" column — silence is the pre-pump truth).
                $outcome = $registry->lastOutcome($row->name);
                if ($outcome !== null) {
                    $line .= Lang::t('host.schedules.row_outcome', ['outcome' => self::outcomeLabel($outcome)]);
                }
                $lines[] = $line;
            }
            $head = implode("\n", $lines);
        }
        $errors = $registry->loadErrors();
        if ($errors !== []) {
            $head .= "\n" . Lang::t('host.schedules.errors_head') . "\n"
                . implode("\n", array_map(static fn (string $e): string => '  - ' . $e, $errors));
        }

        return $head;
    }

    /** @param list<string> $words */
    private static function flip(string $text, ScheduleRegistry $registry, array $words, bool $enable): CommandResult
    {
        $name = $words[2] ?? '';
        if ($name === '') {
            return CommandResult::errorReply($text, Lang::t('host.schedules.usage'));
        }
        $row = $registry->setEnabled($name, $enable);
        if ($row === null) {
            return CommandResult::errorReply($text, Lang::t('host.schedules.unknown', ['name' => $name]));
        }
        if (!$row->persisted) {
            return CommandResult::reply($text, Lang::t(
                $enable ? 'host.schedules.enabled_loop' : 'host.schedules.disabled_loop',
                ['name' => $name],
            ));
        }
        try {
            $registry->persist();
        } catch (\Throwable $e) {
            return CommandResult::errorReply($text, Lang::t('host.schedules.persist_failed', [
                'name' => $name,
                'error' => $e->getMessage(),
            ]));
        }

        return CommandResult::reply($text, Lang::t(
            $enable ? 'host.schedules.enabled' : 'host.schedules.disabled',
            ['name' => $name],
        ));
    }

    /** @param list<string> $words */
    private static function delete(string $text, ScheduleRegistry $registry, array $words): CommandResult
    {
        $name = $words[2] ?? '';
        if ($name === '') {
            return CommandResult::errorReply($text, Lang::t('host.schedules.usage'));
        }
        $row = $registry->get($name);
        if ($row === null) {
            return CommandResult::errorReply($text, Lang::t('host.schedules.unknown', ['name' => $name]));
        }
        $wasPersisted = $row->persisted;
        $registry->remove($name);
        if ($wasPersisted) {
            try {
                $registry->persist();
            } catch (\Throwable $e) {
                return CommandResult::errorReply($text, Lang::t('host.schedules.persist_failed', [
                    'name' => $name,
                    'error' => $e->getMessage(),
                ]));
            }

            return CommandResult::reply($text, Lang::t('host.schedules.deleted', ['name' => $name]));
        }
        // Only in-process: the row never touched disk, and this host's clock
        // is where it lived.
        return CommandResult::reply($text, Lang::t('host.schedules.deleted_loop', ['name' => $name]));
    }

    /** The recorded fireDue decision as the one word-plus-reason the row line shows. */
    private static function outcomeLabel(array $outcome): string
    {
        return match ($outcome['action']) {
            'fired' => Lang::t('host.schedules.outcome_fired'),
            'error' => Lang::t('host.schedules.outcome_error', ['error' => $outcome['error'] ?? '']),
            'skipped-foreign' => Lang::t('host.schedules.outcome_foreign'),
            'skipped-busy' => Lang::t('host.schedules.outcome_busy'),
            'skipped-locked' => Lang::t('host.schedules.outcome_locked'),
            'skipped-stale' => Lang::t('host.schedules.outcome_stale'),
            default => $outcome['action'],
        };
    }

    /** A prompt preview clipped to one line for the list — the row is the name, not an essay. */
    private static function clip(string $prompt): string
    {
        $single = preg_replace('/\s+/u', ' ', $prompt) ?? $prompt;
        if (mb_strlen($single) <= 60) {
            return $single;
        }

        return mb_substr($single, 0, 59) . '…';
    }
}
