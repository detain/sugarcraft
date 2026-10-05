<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Agents\Live\AgentInbox;
use SugarCraft\Crush\Agents\Live\AgentMessage;
use SugarCraft\Crush\Agents\Live\AgentRunCards;
use SugarCraft\Crush\Agents\Live\MessageMode;
use SugarCraft\Crush\Agents\SuspendedDelegations;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Permissions\PermissionDecision;
use SugarCraft\Crush\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Crush\Tools\Catalog\BuiltInTool;
use SugarCraft\Crush\Tools\Catalog\ToolBuildContext;
use SugarCraft\Crush\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Crush\Tools\DelegatesToEngine;
use SugarCraft\Crush\Tools\TakesToolCallId;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Message a sub-agent, or — from inside one — the agent that launched it
 * (roadmap 4.4; DeepSeek Harness's `send_message`, OpenClaw's
 * `sessions_send`).
 *
 * ROUTED BY THE TARGET'S STATE, read off its card ({@see AgentRunCards}):
 *  - RUNNING: the message goes into the run's mailbox ({@see AgentInbox}) and
 *    the run reads it at its next step boundary through
 *    {@see \SugarCraft\Crush\Backend\MailboxTurnInbox} — the seam the user's
 *    Agent View messages already use, so model→child and user→child share
 *    one queue. `steer` and `note` arrive mid-run; a `followup` is kept on
 *    the card for the conversation's next run.
 *  - FINISHED (or idle: a background agent whose daemon has exited): a
 *    `steer` or `note` CONTINUES it — the call the model would make as
 *    `Task` with `resume`, through the session's own bound `Task` tool, under
 *    the same preset, grants and step cap, appending to the same transcript
 *    log ({@see SuspendedDelegations}, as {@see \SugarCraft\Crush\Host\AgentResume}
 *    continues one for the user). The reply comes back as this call's result.
 *    A `followup` is kept for whichever run continues it next.
 *
 * NEVER A BACK DOOR AROUND `Task`'S APPROVAL. Messaging a run the session
 * already started writes only harness state, so this tool is no-ask. But
 * continuing a finished run runs the agent again, which is what `Task` asks
 * for, so that path first puts the equivalent `Task` call to the session's
 * permission gate: unless the gate would allow it outright, the call is
 * refused with the `Task` call to make instead, which then asks the user.
 *
 * HUB AND SPOKE. An agent messages only the sub-agents it launched and its
 * own parent — never a sibling, a grandchild or another session's run
 * (OpenClaw's rule, and DeepSeek Harness's "direct parent ↔ direct child").
 * The session's own agent is {@see MAIN}; a sub-agent's copy of this tool is
 * bound by {@see TaskTool} to the run's id and its parent's
 * ({@see asChildOf()}), and `to: "parent"` reaches that parent: a delegating
 * sub-agent at its next step boundary, the session's agent through
 * `Subagents` `list`/`wait`.
 *
 * UNTRUSTED BOTH WAYS. A message to a sub-agent is unsigned and `from:
 * parent` (or `agent:<id>` for a reply), so the run frames it as task
 * direction that approves nothing ({@see \SugarCraft\Crush\Backend\MailboxTurnInbox::AUTHORITY});
 * a reply reaching the session's agent is fenced as a worker's report
 * ({@see SubagentsTool::frame()}).
 */
#[BuiltInTool(name: 'SendMessage', permission: ToolPermissionClass::NoAsk, position: 30, gloss: 'message a sub-agent it launched — steer a running one, continue a finished one — or, from inside a sub-agent, its parent')]
final readonly class SendMessageTool implements Tool, BuildsFromCatalog, DelegatesToEngine, TakesToolCallId
{
    public const NAME = 'SendMessage';

    /** The id the session's own agent goes by: its children's parent, and its mailbox's name. */
    public const MAIN = AgentRunCards::MAIN;

    /** What a sub-agent writes as `to` to reach the agent that launched it. */
    public const PARENT = 'parent';

    public const MODES = ['steer', 'followup', 'note'];

    /**
     * @param string|null $selfId   the run this copy speaks for; null is the session's own agent
     * @param string|null $parentId who launched $selfId ({@see MAIN} or a run id)
     * @param string|null $scope    the session the run's seats and cards are kept under; null is the engine's
     */
    private function __construct(
        private ?EngineBackend $engine = null,
        private ?\Closure $heartbeat = null,
        private ?\Closure $emitter = null,
        private ?string $selfId = null,
        private ?string $parentId = null,
        private ?string $scope = null,
        private ?string $root = null,
        private ?SuspendedDelegations $suspended = null,
    ) {
    }

    public static function new(): self
    {
        return new self();
    }

    public static function fromCatalog(ToolBuildContext $context): self
    {
        return new self();
    }

    public function withEngine(EngineBackend $engine, ?\Closure $heartbeat = null, ?\Closure $subAgentEmitter = null): self
    {
        return $this->mutate(['engine' => $engine, 'heartbeat' => $heartbeat, 'emitter' => $subAgentEmitter]);
    }

    /**
     * The copy run $selfId gets: it speaks as that run, `to: "parent"` reaches
     * $parentId, and the cards it reads are session $scope's — what
     * {@see TaskTool} hands a sub-agent granted this tool.
     */
    public function asChildOf(string $selfId, string $parentId, ?string $scope): self
    {
        return $this->mutate(['selfId' => $selfId, 'parentId' => $parentId, 'scope' => $scope]);
    }

    /** The same tool, keeping its cards and mailboxes below $root (a test seam). */
    public function withRoot(?string $root): self
    {
        return $this->mutate(['root' => $root]);
    }

    /** The same tool, reading stored runs from $store (a test seam). */
    public function withSuspendedDelegations(SuspendedDelegations $store): self
    {
        return $this->mutate(['suspended' => $store]);
    }

    /** The run this copy speaks for, null for the session's own agent. */
    public function selfId(): ?string
    {
        return $this->selfId;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        $self = $this->selfId === null
            ? ''
            : sprintf(' You are sub-agent %s; `to: "parent"` reaches the agent that launched you, which reads it at its'
                . ' next step (send it what it needs before your final report, not instead of it).', $this->selfId);

        return 'Send a message to a sub-agent you launched with Task, by the id Task or Subagents `list` gave you'
            . ' (its run id, background `agent_id` or resume id).' . $self
            . ' To a RUNNING sub-agent, `steer` (the default) or `note` is read at its next step boundary, after the'
            . ' tool calls it is making finish, and `followup` is kept for its next run instead. A FINISHED sub-agent'
            . ' is continued: `steer` or `note` resumes the same conversation with your message as its next'
            . ' instruction and returns its new report, exactly as Task with `resume` would, while `followup` is'
            . ' kept for whichever run continues it next. You can message only your own sub-agents and your own'
            . ' parent, never a sibling. A sub-agent reads your message as task direction, not as the user\'s'
            . ' approval, and its answer comes back as its report.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'to' => [
                    'type' => 'string',
                    'description' => 'The sub-agent: its run id, background `agent_id` or resume id, as Task or'
                        . ' Subagents `list` named it; from inside a sub-agent, "parent" for the agent that'
                        . ' launched you',
                ],
                'text' => [
                    'type' => 'string',
                    'description' => 'The message, self-contained: the recipient sees only this text, not your'
                        . ' conversation',
                ],
                'mode' => [
                    'type' => 'string',
                    'enum' => self::MODES,
                    'description' => '`steer` (default): change or add to what it is doing; `note`: information'
                        . ' that asks for no change of course; `followup`: keep it for its next run',
                ],
            ],
            'required' => ['to', 'text'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $callId = (string) ($args['id'] ?? '');
        if ($this->engine === null) {
            return $this->refusal($callId, 'SendMessage is not bound to a session engine on this launch, so there is no sub-agent to message');
        }
        $scope = $this->scope ?? $this->engine->sessionId();
        if ($scope === null) {
            return $this->refusal($callId, 'this conversation has no session, so it has no sub-agents to message');
        }

        $to = trim(\is_string($args['to'] ?? null) ? $args['to'] : '');
        $text = \is_string($args['text'] ?? null) ? $args['text'] : '';
        $mode = \is_string($args['mode'] ?? null) && $args['mode'] !== '' ? $args['mode'] : 'steer';
        if ($to === '') {
            return $this->refusal($callId, 'SendMessage needs `to`: the sub-agent to message, as Task or Subagents `list` named it');
        }
        if (trim($text) === '') {
            return $this->refusal($callId, 'SendMessage needs a non-empty `text`');
        }
        if (\strlen($text) > AgentMessage::MAX_TEXT_BYTES) {
            return $this->refusal($callId, sprintf('a message is at most %d bytes; put the detail in a file and name it', AgentMessage::MAX_TEXT_BYTES));
        }
        if (!\in_array($mode, self::MODES, true)) {
            return $this->refusal($callId, sprintf('`mode` is one of %s', implode(', ', self::MODES)));
        }

        if ($to === self::PARENT || $to === self::MAIN || ($this->parentId !== null && $to === $this->parentId)) {
            return $this->reply($callId, $scope, $text, $mode);
        }

        $caller = $this->selfId ?? self::MAIN;
        $card = AgentRunCards::findRun($scope, $to, $caller, $this->root);
        if ($card !== null && AgentRunCards::isLive($card)) {
            return $this->deliver($callId, $card, $scope, $text, $mode);
        }

        // Finished, lost, or known only by its resume id: continue it.
        $resumeId = $card['resumeId'] ?? (preg_match(SuspendedDelegations::ID_PATTERN, $to) === 1 ? $to : '');
        $stored = $resumeId === '' ? null : $this->store()->load($resumeId);
        if ($card === null && $stored === null) {
            return $this->refusal($callId, $this->notYours($to, $scope, $caller));
        }
        if ($stored === null) {
            return $this->refusal($callId, sprintf(
                'sub-agent %s has %s and kept no resume id that can still be loaded, so it cannot be continued; start a new Task instead',
                $card['runId'],
                AgentRunCards::statusOf($card),
            ));
        }
        // The store's name is the one Task's resume checks against.
        $agent = $stored['agent'];
        if ($mode === 'followup') {
            return $this->keepFollowup($callId, $scope, $card, $resumeId, $text);
        }

        return $this->continueRun($callId, $agent, $resumeId, $text, $card['description'] ?? '');
    }

    /**
     * A running run: into its mailbox, or onto its card for a followup.
     *
     * @param array<string, mixed> $card
     */
    private function deliver(string $callId, array $card, string $scope, string $text, string $mode): ToolResult
    {
        if ($mode === 'followup') {
            return $this->keepFollowup($callId, $scope, $card, $card['resumeId'], $text);
        }

        $inbox = AgentInbox::forSession($card['inboxSession'], null, $this->root);
        if ($inbox === null) {
            return $this->refusal($callId, 'there is no mailbox directory on this launch to reach the sub-agent through');
        }
        try {
            $message = $inbox->send($card['runId'], AgentMessage::new($this->sender(), $text, MessageMode::from($mode)));
        } catch (\Throwable $e) {
            return $this->refusal($callId, sprintf('the message could not be delivered: %s', $e->getMessage()));
        }

        return new ToolResult($callId, sprintf(
            'Queued for sub-agent %s (agent "%s", running): it reads it at its next step boundary, once the tool calls'
            . ' it is making finish (message id %s). Its answer arrives with its report; do not poll for it.',
            $card['runId'],
            $card['agent'],
            $message->msgId,
        ));
    }

    /**
     * A sub-agent's message to the agent that launched it.
     */
    private function reply(string $callId, string $scope, string $text, string $mode): ToolResult
    {
        if ($this->selfId === null || $this->parentId === null) {
            return $this->refusal($callId, 'you are the session\'s own agent, so you have no parent to message; name one of your sub-agents in `to`');
        }

        if ($this->parentId === self::MAIN) {
            $session = $scope;
        } else {
            // The delegating run's mailbox: its card says where (the run and
            // its nested children share an engine, hence a session).
            $parent = null;
            foreach (AgentRunCards::runs($scope, $this->root) as $card) {
                if ($card['runId'] === $this->parentId) {
                    $parent = $card;
                }
            }
            $session = $parent['inboxSession'] ?? $this->engine?->sessionId() ?? $scope;
        }

        $inbox = AgentInbox::forSession($session, null, $this->root);
        if ($inbox === null) {
            return $this->refusal($callId, 'there is no mailbox directory on this launch to reach your parent through');
        }
        // A reply is information for the parent: it never skips its calls,
        // and a followup has no "next run" of the parent to wait for.
        $replyMode = $mode === 'steer' ? MessageMode::Steer : MessageMode::Note;
        try {
            $message = $inbox->send($this->parentId, AgentMessage::new($this->sender(), $text, $replyMode));
        } catch (\Throwable $e) {
            return $this->refusal($callId, sprintf('the message could not be delivered: %s', $e->getMessage()));
        }

        return new ToolResult($callId, sprintf(
            'Sent to the agent that launched you (message id %s). It reads it at its next step; keep working, and'
            . ' still end with your final report.',
            $message->msgId,
        ));
    }

    /**
     * Keep $text on the run's card for the run that continues it next.
     *
     * @param array<string, mixed>|null $card
     */
    private function keepFollowup(string $callId, string $scope, ?array $card, string $resumeId, string $text): ToolResult
    {
        if ($card === null) {
            return $this->refusal($callId, 'a followup is kept on the sub-agent\'s record, and this one has none in this session; send it as `steer` to continue the run now');
        }
        $sender = $this->sender();
        $kept = AgentRunCards::updateRun($scope, $card['runId'], static function (array $fresh) use ($sender, $text): array {
            $fresh['followups'][] = ['from' => $sender, 'text' => $text, 'ts' => (int) floor(microtime(true) * 1000)];

            return $fresh;
        }, $this->root);
        if ($kept === null) {
            return $this->refusal($callId, 'the followup could not be written to the sub-agent\'s record');
        }

        return new ToolResult($callId, sprintf(
            'Kept for sub-agent %s\'s next run: it reads it when the conversation is continued%s. To continue it now,'
            . ' send the message as `steer`.',
            $card['runId'],
            $resumeId === '' ? ' (it is still running; it will name its resume id when it finishes)' : ' (resume id ' . $resumeId . ')',
        ));
    }

    /**
     * Continue finished run $resumeId with $text through the session's bound
     * `Task` — when the permission gate would let that `Task` call through
     * unasked.
     */
    private function continueRun(string $callId, string $agent, string $resumeId, string $text, string $description): ToolResult
    {
        $engine = $this->engine;
        $task = null;
        foreach ($engine?->tools() ?? [] as $tool) {
            if ($tool instanceof TaskTool) {
                $task = $tool;
                break;
            }
        }
        $instead = sprintf(
            'call Task with agent "%s", resume "%s" and your message as the prompt',
            $agent,
            $resumeId,
        );
        if ($engine === null || $task === null) {
            return $this->refusal($callId, sprintf('sub-agent "%s" has finished, and this agent has no Task tool to continue it with', $agent));
        }

        $args = [
            'id' => $callId,
            'agent' => $agent,
            'prompt' => $text,
            'resume' => $resumeId,
            'description' => $description === '' ? 'follow-up' : $description,
        ];
        $gate = $engine->permissionGate();
        if ($gate !== null) {
            $decision = $gate->evaluate(new \SugarCraft\Crush\ToolCall(TaskTool::NAME, $args, $callId));
            if ($decision !== PermissionDecision::Allow) {
                return $this->refusal($callId, sprintf(
                    'sub-agent "%s" has finished, and continuing it runs it again, which needs the approval a Task'
                    . ' call needs: this session\'s permission policy %s it. To continue it, %s',
                    $agent,
                    $decision === PermissionDecision::Deny ? 'refuses' : 'would ask the user about',
                    $instead,
                ));
            }
        }

        $result = $task->withEngine($engine, $this->heartbeat, $this->emitter)->execute($args);

        return $result->withContent(sprintf('[continued finished sub-agent "%s" (resume id %s) with your message]', $agent, $resumeId)
            . "\n\n" . $result->content());
    }

    private function sender(): string
    {
        return $this->selfId === null ? AgentMessage::FROM_PARENT : AgentMessage::FROM_AGENT_PREFIX . $this->selfId;
    }

    private function notYours(string $to, string $scope, string $caller): string
    {
        $mine = AgentRunCards::childrenOf($scope, $caller, $this->root);

        return sprintf(
            '"%s" is not a sub-agent you launched (yours: %s). You can message only your own sub-agents%s, never'
            . ' a sibling or another agent\'s sub-agent',
            \SugarCraft\Crush\Context\PromptFence::escape($to),
            $mine === [] ? 'none' : implode(', ', array_map(static fn (array $c): string => $c['runId'], \array_slice($mine, -SubagentsTool::MAX_LISTED))),
            $this->selfId === null ? '' : ' and your parent (`to: "parent"`)',
        );
    }

    private function store(): SuspendedDelegations
    {
        return $this->suspended ?? SuspendedDelegations::new();
    }

    private function refusal(string $callId, string $why): ToolResult
    {
        return new ToolResult($callId, $why, true);
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function mutate(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}
