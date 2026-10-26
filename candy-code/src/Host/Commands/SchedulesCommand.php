<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Code\Lang;
use SugarCraft\Code\Background\ScheduleRegistry;

/**
 * `/schedules` (alias `/schedule`) — the read and control surface for
 * AUT-1 rows: `list` (also the bare form), `enable <name>`, `disable <name>`,
 * `delete <name>`.
 *
 * READ-ONLY JUDGEMENT (the plan asks for it explicitly): LISTING is admitted
 * in a read-only window — it reads this process's roster and one 0600 file,
 * and changes nothing; the mutating verbs are not, because enabling,
 * disabling, and deleting persist consent-bearing rows that later turns will
 * act on. `/loop` is absent from the read-only set for the same reason seen
 * from the other side: it programs future turns.
 *
 * CREATION is deliberately file-first: rows arrive by writing
 * `~/.candy-code/schedules.json` (or a `/loop` for the ephemeral kind). The
 * plan's row is config-authority posture — the same ruling the user-scope
 * `mcp.json` landed on — so there is no `add` verb here to duplicate the
 * grammar of a JSON file in a command parser. A corrupt file or a corrupt ROW
 * never crashes the launch; both are listed loudly here, and the good rows
 * keep firing. A row whose permission mode the floor coerced keeps firing
 * too, announced beside itself as a note — the coercion is loud, never
 * silent.
 *
 * `enable`/`disable`/`delete` on a PERSISTED row write schedules.json back
 * through the registry before answering, so the change survives the session;
 * on an ephemeral row they touch only memory, and the reply says so.
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
     * The `list` body: one line per row (file or loop), then the load notes
     * — refusals and coerced permission modes alike — so a hand-edit mistake
     * reads HERE rather than only in the absence of a fire.
     */
    public static function listing(ScheduleRegistry $registry): string
    {
        $rows = $registry->all();
        if ($rows === []) {
            $head = Lang::t('host.schedules.none');
        } else {
            $lines = [];
            foreach ($rows as $row) {
                $lines[] = Lang::t('host.schedules.row', [
                    'name' => $row->name,
                    'state' => $row->enabled ? Lang::t('host.schedules.on') : Lang::t('host.schedules.off'),
                    'kind' => $row->persisted ? Lang::t('host.schedules.kind_file') : Lang::t('host.schedules.kind_loop'),
                    'every' => LoopCommand::everyLabel($row->everySeconds),
                    'next' => date('Y-m-d H:i:s', $row->nextDue),
                    'prompt' => self::clip($row->prompt),
                ]);
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
