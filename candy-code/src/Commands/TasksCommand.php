<?php

declare(strict_types=1);

namespace SugarCraft\Code\Commands;

use SugarCraft\Code\Agents\Live\AgentLiveRegistry;
use SugarCraft\Code\Agents\Live\AgentLiveState;
use SugarCraft\Code\Background\BackgroundShellRegistry;
use SugarCraft\Code\Background\BackgroundSupervisor;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\CommandResult;
use SugarCraft\Code\Host\Commands\CommandText;
use SugarCraft\Code\Host\Commands\HostCommand;
use SugarCraft\Code\Lang;

/**
 * `/tasks`: the work this session has running in the background, in one list.
 *
 * Three kinds are listed: background shells (the registry's rows this session
 * can see), background sessions (the supervisor's active ones) and sub-agent
 * runs (the live agent registry, scoped to this session). Each row carries its
 * state and elapsed time, so a forgotten job is never a mystery.
 *
 *   /tasks                  the list
 *   /tasks tail <shell-id>  the last lines a shell has written
 *   /tasks stop <shell-id>  stop a shell, through the same door as /shells stop
 *
 * Shells are the only kind with a tail and a stop here: a session is stopped
 * with its own command, and a sub-agent run is cancelled from its own view.
 * Workflow runs have no live registry to list (a run is a blocking call), so
 * they are not in the list.
 */
final class TasksCommand implements HostCommand
{
    /** @param ?AgentLiveRegistry $agents the session's live sub-agent registry, if wired */
    public function __construct(private readonly ?AgentLiveRegistry $agents = null)
    {
    }

    /** Verbs that only read; `stop` touches a live child and so is a write. */
    public const READ_VERBS = ['list', 'tail'];

    /** Lines of shell output `tail` shows. */
    private const TAIL_LINES = 20;

    /** Whether `/tasks <verb>` only reads, for the read-only session gate. */
    public static function isReadOnlyVerb(string $verb): bool
    {
        return \in_array(\strtolower($verb), self::READ_VERBS, true);
    }

    public function run(CommandContext $context, string $text): CommandResult
    {
        $words = CommandText::tokens($text);
        $verb = \strtolower($words[1] ?? 'list');
        $owner = $context->sessionId;
        $registry = $context->workspace?->service(BackgroundShellRegistry::class);

        return match ($verb) {
            'list' => CommandResult::reply($text, $this->listing($context, $registry instanceof BackgroundShellRegistry ? $registry : null, $owner)),
            'tail' => $this->tail($text, $registry, $words, $owner),
            'stop' => $this->stop($text, $registry, $words, $owner),
            default => CommandResult::errorReply($text, Lang::t('host.shells.usage')),
        };
    }

    private function listing(CommandContext $context, ?BackgroundShellRegistry $registry, ?string $owner): string
    {
        $groups = self::groups($registry, $context->backgroundSupervisor, $this->agents, $owner, \microtime(true));

        $sections = [];
        foreach (['shells', 'sessions', 'agents'] as $kind) {
            if ($groups[$kind] === []) {
                continue;
            }
            $sections[] = self::heading($kind);
            $sections = [...$sections, ...$groups[$kind]];
        }

        return $sections === [] ? Lang::t('host.tasks.none') : implode("\n", $sections);
    }

    /**
     * The three kinds of background work, as formatted rows, grouped by kind.
     *
     * This is the one assembly both surfaces read: `/tasks` prints the groups
     * under their headings, and the `/tasks` sidebar pane
     * ({@see \SugarCraft\Code\Tui\Components\TasksPane}) paints the same rows,
     * so the two cannot disagree about what is running. Pure read: nothing is
     * started, stopped or written.
     *
     * @param ?BackgroundShellRegistry $registry the shell registry, if the session has one
     * @param ?BackgroundSupervisor $supervisor the background supervisor, if wired
     * @param ?AgentLiveRegistry $agents the session's live sub-agent registry, if wired
     * @param ?string $owner the session whose shells and agent runs are listed
     * @param float $now the clock agent ages are measured against (microtime)
     * @return array{shells: list<string>, sessions: list<string>, agents: list<string>}
     */
    public static function groups(
        ?BackgroundShellRegistry $registry,
        ?BackgroundSupervisor $supervisor,
        ?AgentLiveRegistry $agents,
        ?string $owner,
        float $now,
    ): array {
        $shells = [];
        if ($registry !== null) {
            foreach ($registry->ownedBy($owner) as $id) {
                $row = $registry->status($id, $owner);
                if ($row === null || (string) $row['state'] !== 'running') {
                    continue;
                }
                $shells[] = Lang::t('host.tasks.shell_row', [
                    'id' => (string) $row['id'],
                    'label' => Chat::reportField((string) $row['label']),
                    'age' => self::age((int) ($row['ageSeconds'] ?? 0)),
                ]);
            }
        }

        $sessions = [];
        if ($supervisor !== null) {
            foreach ($supervisor->activeSessions() as $id => $session) {
                $sessions[] = Lang::t('host.tasks.session_row', [
                    'id' => (string) $id,
                    'name' => Chat::reportField($session->name),
                    'state' => $session->status->value,
                    'age' => $session->elapsedDisplay(),
                ]);
            }
        }

        $runs = [];
        if ($agents !== null) {
            foreach ($agents->forSession($owner) as $state) {
                if ($state->status !== AgentLiveState::STATUS_RUNNING && $state->status !== AgentLiveState::STATUS_QUEUED) {
                    continue;
                }
                $runs[] = Lang::t('host.tasks.agent_row', [
                    'id' => $state->id,
                    'name' => Chat::reportField($state->name),
                    'state' => $state->status,
                    'age' => self::age($state->startedAt === null ? 0 : (int) max(0, $now - $state->startedAt)),
                ]);
            }
        }

        return ['shells' => $shells, 'sessions' => $sessions, 'agents' => $runs];
    }

    /** @param list<string> $words */
    private function tail(string $text, ?BackgroundShellRegistry $registry, array $words, ?string $owner): CommandResult
    {
        $id = $words[2] ?? '';
        if ($id === '' || $registry === null) {
            return CommandResult::errorReply($text, Lang::t('host.shells.usage'));
        }
        $read = $registry->read($id, 0, null, $owner);
        if (($read['ok'] ?? false) !== true) {
            return CommandResult::errorReply($text, Lang::t('host.shells.unknown', ['id' => $id]));
        }
        $output = \rtrim((string) ($read['stdout'] ?? '') . (string) ($read['stderr'] ?? ''));
        if ($output === '') {
            return CommandResult::reply($text, Lang::t('host.tasks.tail_empty', ['id' => $id]));
        }
        $lines = \explode("\n", $output);

        return CommandResult::reply($text, Chat::reportField(\implode("\n", \array_slice($lines, -self::TAIL_LINES))));
    }

    /** @param list<string> $words */
    private function stop(string $text, ?BackgroundShellRegistry $registry, array $words, ?string $owner): CommandResult
    {
        $id = $words[2] ?? '';
        if ($id === '' || $registry === null) {
            return CommandResult::errorReply($text, Lang::t('host.shells.usage'));
        }
        $result = $registry->stop($id, $owner);
        if (($result['found'] ?? false) !== true) {
            return CommandResult::errorReply($text, Lang::t('host.shells.unknown', ['id' => $id]));
        }
        $exit = $result['exitCode'] === null ? '?' : (string) (int) $result['exitCode'];
        if (($result['wasRunning'] ?? false) !== true) {
            return CommandResult::reply($text, Lang::t('host.shells.already_finished', ['id' => $id, 'exit' => $exit]));
        }
        if (($result['confirmed'] ?? false) !== true) {
            return CommandResult::errorReply($text, Lang::t('host.shells.stop_unconfirmed', ['id' => $id, 'exit' => $exit]));
        }

        return CommandResult::reply($text, Lang::t('host.shells.stopped', ['id' => $id, 'exit' => $exit]));
    }

    /**
     * Literal keys rather than a built key: the lang parity scan only sees
     * literal `Lang::t()` arguments, so a concatenated prefix looks missing.
     */
    public static function heading(string $kind): string
    {
        return match ($kind) {
            'shells' => Lang::t('host.tasks.shells_heading'),
            'sessions' => Lang::t('host.tasks.sessions_heading'),
            default => Lang::t('host.tasks.agents_heading'),
        };
    }

    private static function age(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }

        return \intdiv($seconds, 60) . 'm' . ($seconds % 60) . 's';
    }
}
