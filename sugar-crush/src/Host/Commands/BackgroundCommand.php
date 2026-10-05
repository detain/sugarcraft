<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

use React\Promise\PromiseInterface;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\BackgroundSessionSpawnedMsg;
use SugarCraft\Crush\BackgroundSessionStoppedMsg;
use SugarCraft\Crush\Host\TitleService;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Sessions\BackgroundStopOutcome;
use SugarCraft\Crush\Sessions\BackgroundSupervisor;

/**
 * `/bg <task>` (alias `/background`) — dispatch a task onto the
 * {@see BackgroundSupervisor} and hand the prompt straight back (crush_feat.md
 * §5 E3); `/bg stop <id>` stops one (audit BG-1). Moved out of `Chat` in
 * roadmap O-2h; `/fork` ({@see ForkCommand}) shares its spawn.
 *
 * Claude Code's `/background` with no argument backgrounds the LIVE
 * conversation; that has no counterpart here, because `spawnSession()` hands
 * the child a task string, not a transcript, so an argument-less `/bg` is
 * answered with usage rather than silently backgrounding something else.
 *
 * `/bg stop` shares its first word with ordinary prose, so the parsing rule is
 * deliberately narrow: exactly `stop` followed by ONE token that is a session
 * id this supervisor knows or one shaped like the ids it mints. Anything else —
 * "/bg stop the dev server and rebuild" — is a task, as it always was. A bare
 * `/bg stop` answers usage plus the active ids.
 *
 * Both the spawn and the stop run OFF-TURN ({@see CommandEffect::async()}):
 * `spawnSession()` blocks on a socket accept for up to 5 s and `stopSession()`
 * may wait on its signal rungs, neither of which may freeze the loop. The
 * answer — a session id, or why there is none — lands later as its own row.
 */
final class BackgroundCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        $supervisor = $context->backgroundSupervisor;
        if ($supervisor === null) {
            return CommandResult::reply($text, self::notConfigured());
        }

        $task = CommandText::argument($text);
        if ($task === '') {
            return CommandResult::reply($text, Lang::t('host.bg.usage'));
        }

        if (strcasecmp($task, 'stop') === 0) {
            return CommandResult::reply($text, self::stopUsage($supervisor));
        }

        $stopTarget = self::stopTarget($supervisor, $task);
        if ($stopTarget !== null) {
            return CommandResult::echo($text)->withEffect(CommandEffect::async(
                self::stopThunk($supervisor, $stopTarget),
                static fn (mixed $msg): ?string => $msg instanceof BackgroundSessionStoppedMsg ? self::stopNotice($msg) : null,
            ));
        }

        return CommandResult::echo($text)->withEffect(
            self::spawnEffect($context, '/bg', self::sessionName($task), $task, null),
        );
    }

    /** The sentence `/bg` and `/fork` answer with when no supervisor is wired. */
    public static function notConfigured(): string
    {
        return Lang::t('host.bg.not_configured');
    }

    /**
     * The off-turn spawn of a background session, with the row it lands as.
     * No reply row is written up front on purpose: the only honest thing to
     * say then is "asked to spawn", and the real answer arrives with the spawn.
     *
     * @param string      $command         '/bg' or '/fork', echoed back in the answer
     * @param string|null $forkedSessionId the transcript clone the session continues (the daemon
     *                                     loads it as history and saves the reply into it); null for `/bg`
     */
    public static function spawnEffect(CommandContext $context, string $command, string $name, string $task, ?string $forkedSessionId): CommandEffect
    {
        $supervisor = $context->backgroundSupervisor ?? throw new \LogicException('A background spawn needs a supervisor.');

        return CommandEffect::async(
            self::spawnThunk($supervisor, $context->agentManager, $context->projectRoot(), $command, $name, $task, $forkedSessionId),
            static fn (mixed $msg): ?string => $msg instanceof BackgroundSessionSpawnedMsg ? self::spawnedNotice($msg) : null,
        );
    }

    /**
     * The thunk that actually spawns the session and resolves with its
     * {@see BackgroundSessionSpawnedMsg}.
     *
     * The agent is a registered "default", then whatever IS registered, then a
     * synthesised stand-in. The middle arm is what a real launch takes:
     * `spawnSession()` feeds the agent's provider and model straight into the
     * daemon's command line, so before the launch registered its roster every
     * daemon ran as the literal "unknown"/"unknown". Pinned by
     * `ChatTest::testBackgroundSpawnRunsTheDaemonAsARosterAgentNotTheUnknownStandIn()`.
     * The stand-in stays for embedders with no manager.
     *
     * The session is spawned into the SAME tree this run is rooted at: a
     * `--root <lib>` run that backgrounded work into the enclosing monorepo
     * would have the child acting outside the parent's jail — unless the
     * agent's preset says `isolation: worktree` (roadmap 4.9): then it works
     * in a git worktree of that tree, created here, off-turn, on a branch of
     * its own and marked named (a session the person started is never swept
     * by the stale-worktree cleanup). A tree that cannot be created fails the
     * spawn rather than running the session in the shared checkout.
     *
     * A failed spawn RESOLVES rather than rejects: a rejection would surface
     * as candy-core's generic `ExceptionMsg` and lose the command context the
     * answer needs.
     *
     * @return \Closure(): PromiseInterface<BackgroundSessionSpawnedMsg>
     */
    public static function spawnThunk(
        BackgroundSupervisor $supervisor,
        ?AgentManager $agentManager,
        string $root,
        string $command,
        string $name,
        string $task,
        ?string $forkedSessionId,
    ): \Closure {
        $agent = $agentManager?->get('default')
            ?? ($agentManager?->all()[0] ?? null)
            ?? self::defaultAgent();
        $workingDirectory = $root !== '' ? $root : '.';
        $tags = $forkedSessionId === null ? null : ['fork', 'session:' . $forkedSessionId];

        return static function () use ($supervisor, $command, $name, $task, $agent, $workingDirectory, $tags, $forkedSessionId): PromiseInterface {
            $worktrees = null;
            $worktreeId = null;
            try {
                $worktree = null;
                if ($agent->isolation === \SugarCraft\Crush\Agents\Isolation::Worktree) {
                    $worktrees = \SugarCraft\Crush\Agents\WorktreeManager::new($workingDirectory);
                    $worktreeId = 'bg-' . bin2hex(random_bytes(6));
                    $worktree = $worktrees->createWorktree($worktreeId);
                }
                $session = $supervisor->spawnSession(
                    name: $name,
                    agent: $agent,
                    task: $task,
                    workingDirectory: $worktree ?? $workingDirectory,
                    tags: $tags,
                    forkedSessionId: $forkedSessionId,
                );
                // Named only once the session exists: a spawn that failed
                // leaves an unnamed, unused tree, released below.
                if ($worktree !== null) {
                    $worktrees?->markWorktreeNamed((string) $worktreeId);
                }

                return \React\Promise\resolve(new BackgroundSessionSpawnedMsg($command, $name, $session->id, worktree: $worktree));
            } catch (\Throwable $e) {
                if ($worktrees !== null && $worktreeId !== null) {
                    try {
                        $worktrees->releaseIfUnused($worktreeId);
                    } catch (\Throwable) {
                        // Already reported on the notice seam by the manager.
                    }
                }

                return \React\Promise\resolve(new BackgroundSessionSpawnedMsg($command, $name, null, $e->getMessage()));
            }
        };
    }

    /**
     * The thunk that stops $sessionId and resolves with its
     * {@see BackgroundSessionStoppedMsg} — never rejects, for the reason
     * {@see spawnThunk()} gives.
     *
     * @return \Closure(): PromiseInterface<BackgroundSessionStoppedMsg>
     */
    public static function stopThunk(?BackgroundSupervisor $supervisor, string $sessionId): \Closure
    {
        return static function () use ($supervisor, $sessionId): PromiseInterface {
            $name = $supervisor?->getSession($sessionId)?->name;
            try {
                $outcome = $supervisor === null
                    ? BackgroundStopOutcome::CouldNotStop
                    : $supervisor->stopSession($sessionId);

                return \React\Promise\resolve(new BackgroundSessionStoppedMsg($sessionId, $outcome, $name));
            } catch (\Throwable $e) {
                return \React\Promise\resolve(new BackgroundSessionStoppedMsg(
                    $sessionId,
                    BackgroundStopOutcome::CouldNotStop,
                    $name,
                    $e->getMessage(),
                ));
            }
        };
    }

    /** The session id `/bg stop <id>` names, or null when $argument is a task. */
    public static function stopTarget(BackgroundSupervisor $supervisor, string $argument): ?string
    {
        if (preg_match('/^stop\s+(\S+)$/i', $argument, $m) !== 1) {
            return null;
        }

        $id = $m[1];
        if ($supervisor->getSession($id) !== null || preg_match(BackgroundSupervisor::SESSION_ID_PATTERN, $id) === 1) {
            return $id;
        }

        return null;
    }

    /** Usage for a bare `/bg stop`, listing what there is to stop. */
    public static function stopUsage(BackgroundSupervisor $supervisor): string
    {
        $active = [];
        foreach ($supervisor->getActiveSessions() as $id => $session) {
            $active[] = "{$id} ('{$session->name}')";
        }

        return Lang::t('host.bg.stop_usage') . "\n"
            . ($active === []
                ? Lang::t('host.bg.none_active')
                : Lang::t('host.bg.active', ['sessions' => implode(', ', $active)]));
    }

    /**
     * The answer to a settled spawn. Not session-scoped: the user asked for it
     * out loud, so it belongs in whatever transcript is in front of them now.
     */
    public static function spawnedNotice(BackgroundSessionSpawnedMsg $msg): string
    {
        if ($msg->error !== null) {
            return Lang::t('host.bg.start_failed', ['name' => $msg->name, 'error' => $msg->error]);
        }

        $where = $msg->worktree === null ? '' : Lang::t('host.bg.worktree', ['path' => $msg->worktree]);

        return Lang::t($msg->command === '/fork' ? 'host.bg.forked' : 'host.bg.backgrounded', [
            'id' => $msg->sessionId,
            'name' => $msg->name,
        ]) . $where;
    }

    /** The answer to a settled `/bg stop`. */
    public static function stopNotice(BackgroundSessionStoppedMsg $msg): string
    {
        $label = $msg->name === null ? $msg->sessionId : "{$msg->sessionId} ('{$msg->name}')";

        return match ($msg->outcome) {
            BackgroundStopOutcome::UnknownSession
                => Lang::t('host.bg.stop.unknown', ['id' => $msg->sessionId]),
            BackgroundStopOutcome::AlreadyFinished
                => Lang::t('host.bg.stop.already_finished', ['session' => $label]),
            BackgroundStopOutcome::StoppedViaIpc
                => Lang::t('host.bg.stop.stopped', ['session' => $label]),
            BackgroundStopOutcome::StoppedViaSignal
                => Lang::t('host.bg.stop.signalled', ['session' => $label]),
            BackgroundStopOutcome::CouldNotStop
                => $msg->error !== null
                    ? Lang::t('host.bg.stop.failed', ['session' => $label, 'error' => $msg->error])
                    : Lang::t('host.bg.stop.unreachable', ['session' => $label]),
        };
    }

    /**
     * A one-line, control-character-free session name derived from the task:
     * the task is typed by the user and can carry pasted escape sequences or
     * newlines, and the name lands in a status list one row per session.
     */
    public static function sessionName(string $task): string
    {
        $name = TitleService::sanitizeTitle($task);

        return $name === '' ? Lang::t('host.bg.default_name') : $name;
    }

    /**
     * The stand-in agent a background session runs as when no agent manager is
     * wired. Named "default" so a later, real registration replaces it.
     */
    public static function defaultAgent(): Agent
    {
        return new Agent(
            name: 'default',
            description: 'Background session agent',
            prompt: '',
            model: 'unknown',
            provider: 'unknown',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: true,
        );
    }
}
