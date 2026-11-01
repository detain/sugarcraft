<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Code\Background\BackgroundShellRegistry;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Tools\BuiltIn\PtyRun;
use SugarCraft\Code\Tools\BuiltIn\ShellSend;

/**
 * `/shells` (C-TOOL-3.d) — the list / read / type / stop surface over the
 * launch's background shell table: `list` (also the bare form),
 * `output <id> [cursor]`, `send <id> <keys…>` (C-TOOL-6.k), `stop <id>`.
 *
 * READ-ONLY JUDGEMENT (the plan asks for it explicitly): LISTING and READING
 * are admitted in a read-only window — they consult this process's roster and
 * the two log files a shell writes, and change nothing; STOP is not, because
 * it runs the kill ladder on a live child, and SEND is not, because typing
 * into a live terminal is exactly the mutation the read-only door exists to
 * keep out — `isReadOnlyVerb()` leaving `send` out of the roster IS the
 * judgement, and the Chat arm C-TOOL-3.d wired consults nothing else.
 * Spawning is deliberately absent: the Shell tool with `background: true` is
 * the one spawn door, so this command never grows a second one (the plan's
 * no-new-spawn-sites law) — `send` only feeds a shell some Bash call already
 * started, through the SAME registry door the ShellSend tool uses, tagged
 * `human` so the row's audit counter sees the hand that pressed.
 *
 * A send here obeys the SAME readiness law as the tool — it waits the
 * declared attach budget once through {@see ShellSend::relayReady} rather
 * than letting a fast `/shells send` after a spawn type into a FIFO nobody
 * is reading yet — but it is still ONE try: the sendBounded re-trial loop
 * belongs to the model's path, and a human who draws the honest not-ready
 * answer simply presses again. Blocking the interface loop past the budget
 * for a kernel promise that may never land is not what a command should do.
 *
 * OWNERSHIP rides the rows C-TOOL-3.c tags: every read passes
 * `$context->currentSessionId()` as the owner, so the table a session lists
 * is its own shells plus the untagged ones, never another session's. A
 * foreign id answers exactly as an unknown one does — the registry's own
 * missing-id shape, no side effect, nothing here that lets a caller probe
 * which of the two cases fired.
 *
 * The registry is resolved through the workspace service chain (the same
 * memoized instance `Bootstrap::backgroundShells()` hands the Shell tool),
 * never constructed here — a second table would be a second reaper, and the
 * kill accounting would split.
 */
final class ShellsCommand implements HostCommand
{
    /** Sub-commands that only read; anything else here touches a live child. */
    public const READ_VERBS = ['list', 'output'];

    public function run(CommandContext $context, string $text): CommandResult
    {
        $registry = $context->workspace?->service(BackgroundShellRegistry::class);
        if (!$registry instanceof BackgroundShellRegistry) {
            return CommandResult::errorReply($text, Lang::t('host.shells.not_configured'));
        }
        $owner = $context->currentSessionId();

        $words = CommandText::tokens($text);
        $verb = strtolower($words[1] ?? 'list');

        return match ($verb) {
            'list' => CommandResult::reply($text, self::listing($registry, $owner)),
            'output' => self::output($text, $registry, $words, $owner),
            'send' => self::send($text, $registry, $words, $owner),
            'stop' => self::stop($text, $registry, $words, $owner),
            default => CommandResult::errorReply($text, Lang::t('host.shells.usage')),
        };
    }

    /** Whether `/shells …` as typed only reads (the Chat-side read-only gate asks this). */
    public static function isReadOnlyVerb(string $verb): bool
    {
        return \in_array(strtolower($verb), self::READ_VERBS, true);
    }

    /**
     * The `list` body: one line per visible shell — id, label, state, exit
     * code (or a dash while it runs), age — filtered to this session's rows
     * plus the untagged ones by the registry's own visibility law.
     */
    private static function listing(BackgroundShellRegistry $registry, ?string $owner): string
    {
        $ids = $registry->ownedBy($owner);
        if ($ids === []) {
            return Lang::t('host.shells.none');
        }
        $lines = [];
        foreach ($ids as $id) {
            $row = $registry->status($id, $owner);
            if ($row === null) {
                // The id left the table between the roster read and the
                // status read; saying nothing about it beats a ghost row.
                continue;
            }
            $lines[] = Lang::t('host.shells.row', [
                'id' => (string) $row['id'],
                'state' => self::stateLabel((string) $row['state']),
                'label' => (string) $row['label'],
                'exit' => (string) $row['state'] === 'running' ? '-' : self::exitColumn($row),
                'age' => self::ageColumn((int) ($row['ageSeconds'] ?? 0)),
            ]);
        }

        return $lines === [] ? Lang::t('host.shells.none') : implode("\n", $lines);
    }

    /**
     * The `output` body: one windowed read through the registry's own
     * `read()` — a fresh read tail-wins within the window exactly as the
     * tool-side reader does, and the printed cursor is the one to pass back
     * for the next slice.
     *
     * @param list<string> $words
     */
    private static function output(string $text, BackgroundShellRegistry $registry, array $words, ?string $owner): CommandResult
    {
        $id = $words[2] ?? '';
        if ($id === '') {
            return CommandResult::errorReply($text, Lang::t('host.shells.usage'));
        }
        $cursorArg = $words[3] ?? null;
        $cursor = 0;
        if ($cursorArg !== null) {
            if (preg_match('/^\d+$/', $cursorArg) === 1) {
                $cursor = (int) $cursorArg;
            } elseif (preg_match('/^\d+\.\d+$/', $cursorArg) === 1) {
                $cursor = $cursorArg;
            } else {
                return CommandResult::errorReply($text, Lang::t('host.shells.bad_cursor', ['cursor' => $cursorArg]));
            }
        }

        $read = $registry->read($id, $cursor, null, $owner);
        if ($read['ok'] !== true) {
            // Unknown id and foreign owner answer with the SAME string the
            // registry itself builds — this method cannot tell them apart,
            // so neither can the caller.
            return CommandResult::errorReply($text, (string) $read['error']);
        }

        $running = (string) $read['state'] === 'running';
        $head = Lang::t('host.shells.output_head', [
            'id' => $id,
            'state' => self::stateLabel((string) $read['state']),
            'exit' => $running ? '-' : ($read['exitCode'] === null ? ((string) ($read['exitNote'] ?? '?')) : (string) (int) $read['exitCode']),
        ]);
        $sections = [];
        if ((string) $read['stdout'] !== '') {
            $sections[] = Lang::t('host.shells.out_label') . "\n" . self::indent((string) $read['stdout']);
        }
        if ((string) $read['stderr'] !== '') {
            $sections[] = Lang::t('host.shells.err_label') . "\n" . self::indent((string) $read['stderr']);
        }
        if ($sections === []) {
            $sections[] = Lang::t('host.shells.no_output');
        }
        if ((int) $read['skippedBytes'] > 0) {
            $sections[] = Lang::t('host.shells.skipped', ['bytes' => (string) (int) $read['skippedBytes']]);
        }
        $sections[] = Lang::t('host.shells.cursor', ['cursor' => (string) $read['cursor']]);

        return CommandResult::reply($text, $head . "\n" . implode("\n", $sections));
    }

    /**
     * The `send` body (C-TOOL-6.k): the keys are the RAW rest of the line
     * after the id — a tokeniser would eat the very spacing the grammar is
     * meant to type — decoded through the shared PtyRun grammar, and written
     * by the registry's own door with origin `human`. The success answer
     * names the pre-write cursor so `/shells output <id> <cursor>` is the
     * exact follow-up; the engine's door refusals (unknown, foreign-owner,
     * plain-background, settled, oversize) pass through verbatim, which is
     * what keeps foreign ids indistinguishable from missing ones here too.
     *
     * @param list<string> $words
     */
    private static function send(string $text, BackgroundShellRegistry $registry, array $words, ?string $owner): CommandResult
    {
        $id = $words[2] ?? '';
        if ($id === '') {
            return CommandResult::errorReply($text, Lang::t('host.shells.usage'));
        }
        if (preg_match('/^sh-[0-9a-f]{8}$/', $id) !== 1) {
            return CommandResult::errorReply($text, Lang::t('host.shells.send_bad_id', ['id' => $id]));
        }
        $at = \strpos($text, $id);
        $rest = $at === false ? '' : \ltrim(\substr($text, $at + \strlen($id)));
        if ($rest === '') {
            return CommandResult::errorReply($text, Lang::t('host.shells.send_empty'));
        }
        // No second empty-check after the decode: the grammar only ADDS
        // (see ShellSend's own door), so a non-empty rest can never type
        // nothing — a refusal branch here would be a path no call reaches.
        $decoded = PtyRun::decode($rest);

        // The same readiness law the tool obeys (C-TOOL-6.k): before the
        // relay's pump has opened the input side, a write is answered OK and
        // the bytes die with the sender's fd. The human path waits the
        // declared budget once through the tool's shared predicate — a
        // typed command vanishing silently is worse than a two-second
        // pause — and answers honestly when the window passes without an
        // attach. The write-retry loop inside send() still absorbs the
        // two-statement gap the pump itself carries.
        $row = $registry->status($id, $owner);
        if ($row !== null && (bool) ($row['interactive'] ?? false) && !ShellSend::relayReady($row)) {
            return CommandResult::errorReply($text, Lang::t('host.shells.send_not_ready', [
                'id' => $id,
                'ms' => (string) ShellSend::RELAY_ATTACH_BUDGET_MS,
            ]));
        }

        $answer = $registry->send($id, $decoded, 'human', $owner);
        if ($answer['ok'] !== true) {
            return CommandResult::errorReply($text, (string) $answer['error']);
        }

        return CommandResult::reply($text, Lang::t('host.shells.send_sent', [
            'id' => $id,
            'bytes' => (string) \strlen($decoded),
            'cursor' => (string) (int) $answer['cursor'],
        ]));
    }

    /**
     * The `stop` body: one call through the registry's kill ladder (TERM to
     * the group, bounded grace, KILL to the same group, bounded confirm).
     * Foreign and unknown ids answer alike — `found` false either way.
     *
     * @param list<string> $words
     */
    private static function stop(string $text, BackgroundShellRegistry $registry, array $words, ?string $owner): CommandResult
    {
        $id = $words[2] ?? '';
        if ($id === '') {
            return CommandResult::errorReply($text, Lang::t('host.shells.usage'));
        }
        $result = $registry->stop($id, $owner);
        if ($result['found'] !== true) {
            return CommandResult::errorReply($text, Lang::t('host.shells.unknown', ['id' => $id]));
        }
        $exit = $result['exitCode'] === null ? '?' : (string) (int) $result['exitCode'];
        if ($result['wasRunning'] !== true) {
            return CommandResult::reply($text, Lang::t('host.shells.already_finished', ['id' => $id, 'exit' => $exit]));
        }
        if ($result['confirmed'] !== true) {
            return CommandResult::errorReply($text, Lang::t('host.shells.stop_unconfirmed', ['id' => $id, 'exit' => $exit]));
        }

        return CommandResult::reply($text, Lang::t('host.shells.stopped', ['id' => $id, 'exit' => $exit]));
    }

    /** The exit column for a settled row: the code, else the note, else a question mark. */
    private static function exitColumn(array $row): string
    {
        if ($row['exitCode'] !== null) {
            return (string) (int) $row['exitCode'];
        }

        return (string) ($row['exitNote'] ?? '?');
    }

    private static function stateLabel(string $state): string
    {
        return $state === 'running' ? Lang::t('host.shells.state_running') : Lang::t('host.shells.state_finished');
    }

    /** An age in seconds as the shortest honest human unit — same grammar as the schedule cadence labels. */
    private static function ageColumn(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . 'm';
        }

        return intdiv($seconds, 3600) . 'h';
    }

    /** Log text as the transcript shows it: two spaces in, trailing newline off. */
    private static function indent(string $bytes): string
    {
        $trimmed = rtrim($bytes, "\r\n");
        if ($trimmed === '') {
            return '';
        }

        return preg_replace('/^/m', '  ', $trimmed) ?? $trimmed;
    }
}
