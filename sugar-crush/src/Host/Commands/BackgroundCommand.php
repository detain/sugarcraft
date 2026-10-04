<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

use React\Promise\PromiseInterface;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\BackgroundSessionSpawnedMsg;
use SugarCraft\Crush\BackgroundSessionStoppedMsg;
use SugarCraft\Crush\Host\TitleService;
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
            return CommandResult::reply($text, 'Usage: /bg <task>');
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
        return 'Background sessions not configured. Set a BackgroundSupervisor to use /bg and /fork.';
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
     * would have the child acting outside the parent's jail.
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
            try {
                $session = $supervisor->spawnSession(
                    name: $name,
                    agent: $agent,
                    task: $task,
                    workingDirectory: $workingDirectory,
                    tags: $tags,
                    forkedSessionId: $forkedSessionId,
                );

                return \React\Promise\resolve(new BackgroundSessionSpawnedMsg($command, $name, $session->id));
            } catch (\Throwable $e) {
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

        return 'Usage: /bg stop <session-id>' . "\n"
            . ($active === []
                ? 'No active background sessions.'
                : 'Active background sessions: ' . implode(', ', $active));
    }

    /**
     * The answer to a settled spawn. Not session-scoped: the user asked for it
     * out loud, so it belongs in whatever transcript is in front of them now.
     */
    public static function spawnedNotice(BackgroundSessionSpawnedMsg $msg): string
    {
        if ($msg->error !== null) {
            return "Could not start background session '{$msg->name}': {$msg->error}";
        }

        return $msg->command === '/fork'
            ? "Forked into background session {$msg->sessionId} ('{$msg->name}') — use /agents to check status, /bg stop {$msg->sessionId} to cancel."
            : "Backgrounded as {$msg->sessionId} ('{$msg->name}') — use /agents to check status, /bg stop {$msg->sessionId} to cancel.";
    }

    /** The answer to a settled `/bg stop`. */
    public static function stopNotice(BackgroundSessionStoppedMsg $msg): string
    {
        $label = $msg->name === null ? $msg->sessionId : "{$msg->sessionId} ('{$msg->name}')";

        return match ($msg->outcome) {
            BackgroundStopOutcome::UnknownSession
                => "No background session {$msg->sessionId} in this run — /bg stop lists the active ones.",
            BackgroundStopOutcome::AlreadyFinished
                => "Background session {$label} had already finished; nothing to stop.",
            BackgroundStopOutcome::StoppedViaIpc
                => "Stopped background session {$label}.",
            BackgroundStopOutcome::StoppedViaSignal
                => "Stopped background session {$label} (its control socket was gone, so the daemon was signalled).",
            BackgroundStopOutcome::CouldNotStop
                => "Could not stop background session {$label}"
                    . ($msg->error !== null ? ": {$msg->error}" : ' — its daemon could not be reached or safely signalled.'),
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

        return $name === '' ? 'Background task' : $name;
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
