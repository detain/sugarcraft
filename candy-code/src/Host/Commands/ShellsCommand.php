<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Code\App\ShellSendSettledMsg;
use SugarCraft\Code\Background\BackgroundShellRegistry;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Message;
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
 * declared attach budget once through {@see ShellSend::relayReadyAsync} rather
 * than letting a fast `/shells send` after a spawn type into a FIFO nobody
 * is reading yet — but it is still ONE try: the sendBounded re-trial loop
 * belongs to the model's path, and a human who draws the honest not-ready
 * answer simply presses again. The wait runs on a loop timer, so the interface
 * loop keeps rendering and reading input while it waits (P4-12 CC-1).
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
        //
        // That wait runs on a loop timer, not in a sleep (P4-12 CC-1): this
        // method is on the interface loop, and a sleep here froze rendering
        // and input for the whole budget. A relay that has already attached
        // is still typed into at once; only the not-yet-attached case goes
        // off-turn, and its answer lands as its own row.
        // A send that finds an earlier send to this shell still waiting queues
        // behind it, even when the relay has attached by now: typing at once
        // would put these bytes ahead of the ones the earlier send still holds.
        $row = $registry->status($id, $owner);
        $behindRelay = $row !== null && (bool) ($row['interactive'] ?? false) && !ShellSend::relayAttached($row);
        if ($behindRelay || self::sendInFlight($registry, $id)) {
            $answer = self::sequenced($registry, $id, static fn (): PromiseInterface => self::relayGate($row)->then(
                static fn (bool $ready): ShellSendSettledMsg => new ShellSendSettledMsg(self::sendRow(
                    $ready
                        ? $registry->send($id, $decoded, 'human', $owner)
                        : ['ok' => false, 'error' => Lang::t('host.shells.send_not_ready', [
                            'id' => $id,
                            'ms' => (string) ShellSend::RELAY_ATTACH_BUDGET_MS,
                        ])],
                    $id,
                    $decoded,
                )),
            ));

            return CommandResult::echo($text)->withEffect(CommandEffect::async(
                static fn (): PromiseInterface => $answer,
                static fn (mixed $settled): ?Message => $settled instanceof ShellSendSettledMsg ? $settled->message : null,
            ));
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
     * The row a settled `send` answers with, worded and flagged the way the
     * on-turn answer is: a sent line, or the refusal the registry or the
     * attach budget gave, flagged as an error.
     *
     * @param array<string, mixed> $answer `send()`'s answer, or a refusal built here
     */
    private static function sendRow(array $answer, string $id, string $decoded): Message
    {
        if (($answer['ok'] ?? false) !== true) {
            return Message::assistant((string) ($answer['error'] ?? ''))->withUiOnly()->withError();
        }

        return Message::assistant(Lang::t('host.shells.send_sent', [
            'id' => $id,
            'bytes' => (string) \strlen($decoded),
            'cursor' => (string) (int) $answer['cursor'],
        ]))->withUiOnly();
    }

    /**
     * The last queued send per registry and shell id, kept until it settles.
     * A WeakMap so a dropped registry takes its queue with it.
     *
     * @var \WeakMap<BackgroundShellRegistry, array<string, PromiseInterface<mixed>>>|null
     */
    private static ?\WeakMap $sendTails = null;

    /**
     * Run $step once every earlier send to this shell has settled, and return
     * its promise. Each `/shells send` that waits on a relay used to poll on
     * its own timer, so two pending sends could settle in either order and
     * reach the shell reversed. Chaining each send behind the one before keeps
     * the bytes in dispatch order, and a failed earlier send does not hold up
     * the ones after it.
     *
     * @param \Closure(): PromiseInterface<mixed> $step
     *
     * @return PromiseInterface<mixed>
     */
    public static function sequenced(BackgroundShellRegistry $registry, string $id, \Closure $step): PromiseInterface
    {
        self::$sendTails ??= new \WeakMap();
        $tails = self::$sendTails[$registry] ?? [];
        $previous = $tails[$id] ?? null;
        // The next step starts on a loop tick, not inside the earlier send's
        // resolution: the earlier send's own listeners (its settled row) attach
        // before its promise settles, so they run before the next send's row.
        $next = static function () use ($step): PromiseInterface {
            $deferred = new Deferred();
            Loop::get()->futureTick(static function () use ($deferred, $step): void {
                $deferred->resolve(\React\Promise\resolve(null)->then(static fn (): PromiseInterface => $step()));
            });

            return $deferred->promise();
        };
        $settled = $previous === null
            ? $step()
            : $previous->then(static fn (): PromiseInterface => $next(), static fn (): PromiseInterface => $next());
        $tails[$id] = $settled;
        self::$sendTails[$registry] = $tails;

        // Clear the slot only while it still holds this send: a later send may
        // already have chained itself on, and that one owns the slot now.
        $release = static function () use ($registry, $id, $settled): void {
            $tails = self::$sendTails[$registry] ?? [];
            if (($tails[$id] ?? null) === $settled) {
                unset($tails[$id]);
                self::$sendTails[$registry] = $tails;
            }
        };
        $settled->then($release, $release);

        return $settled;
    }

    private static function sendInFlight(BackgroundShellRegistry $registry, string $id): bool
    {
        return isset(self::$sendTails[$registry][$id]);
    }

    /**
     * The readiness gate of one send: the relay's attach wait for a row that
     * is an interactive shell, and an open gate for anything else (a shell
     * that has since vanished is refused by the registry door, not here).
     *
     * @param array<string, mixed>|null $row
     *
     * @return PromiseInterface<bool>
     */
    private static function relayGate(?array $row): PromiseInterface
    {
        if ($row === null || (bool) ($row['interactive'] ?? false) !== true) {
            return \React\Promise\resolve(true);
        }

        return ShellSend::relayReadyAsync($row);
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
