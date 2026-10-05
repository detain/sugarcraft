<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Agents\Live\AgentInbox;
use SugarCraft\Crush\Agents\Live\AgentMessage;
use SugarCraft\Crush\Agents\Live\AgentRunCards;
use SugarCraft\Crush\Agents\Live\MessageMode;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Crush\Tools\Catalog\BuiltInTool;
use SugarCraft\Crush\Tools\Catalog\ToolBuildContext;
use SugarCraft\Crush\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Crush\Tools\DelegatesToEngine;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Make a running sub-agent stop what it is about to do and read a message
 * first (roadmap 4.4; DeepSeek Harness's `interrupt_agent`, Claude Code's
 * "read your message before finishing its current work").
 *
 * It sends a {@see MessageMode::Interrupt} message to the run's mailbox. The
 * tool call the run is making finishes, every call of that step that has not
 * started yet is answered `Skipped to process an incoming message.`
 * ({@see \SugarCraft\Crush\Backend\TurnInbox::SKIPPED}), and the message is
 * delivered at the step boundary right after — the run keeps its
 * conversation, its mailbox and its sub-agents, and goes on from the
 * message. `SendMessage` steers without skipping anything; `Subagents`
 * `cancel` stops the run altogether.
 *
 * A finished run has no step to interrupt, so it is refused with the
 * `SendMessage` call that continues it instead.
 *
 * PERMISSION CLASS: no-ask, for `SendMessage`'s reason: it writes one line to
 * the mailbox of a run the session already started, framed as task
 * direction that approves nothing.
 */
#[BuiltInTool(name: 'InterruptAgent', permission: ToolPermissionClass::NoAsk, position: 32, gloss: 'make a running sub-agent skip the rest of its current step and read a message first')]
final readonly class InterruptAgentTool implements Tool, BuildsFromCatalog, DelegatesToEngine
{
    public const NAME = 'InterruptAgent';

    private function __construct(
        private ?EngineBackend $engine = null,
        private ?string $root = null,
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
        return new self($engine, $this->root);
    }

    /** The same tool, keeping its cards and mailboxes below $root (a test seam). */
    public function withRoot(?string $root): self
    {
        return new self($this->engine, $root);
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Interrupt a RUNNING sub-agent you launched: the tool call it is making finishes, the rest of the'
            . ' calls it planned for that step are skipped, and it reads your `text` before doing anything else.'
            . ' Use it when what the sub-agent is doing has become wrong or wasteful; to add direction without'
            . ' skipping anything use SendMessage, and to stop it altogether use Subagents `cancel`. Name the'
            . ' sub-agent by the id Task or Subagents `list` gave you. A finished sub-agent cannot be interrupted;'
            . ' SendMessage continues it.';
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
                    'description' => 'The running sub-agent: its run id or background `agent_id`, as Task or'
                        . ' Subagents `list` named it',
                ],
                'text' => [
                    'type' => 'string',
                    'description' => 'What it must read before it goes on: the correction or the new instruction,'
                        . ' self-contained',
                ],
            ],
            'required' => ['to', 'text'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $callId = (string) ($args['id'] ?? '');
        if ($this->engine === null) {
            return new ToolResult($callId, 'InterruptAgent is not bound to a session engine on this launch, so there is no sub-agent to interrupt', true);
        }
        $scope = $this->engine->sessionId();
        if ($scope === null) {
            return new ToolResult($callId, 'this conversation has no session, so it has no sub-agents to interrupt', true);
        }

        $to = trim(\is_string($args['to'] ?? null) ? $args['to'] : '');
        $text = \is_string($args['text'] ?? null) ? $args['text'] : '';
        if ($to === '' || trim($text) === '') {
            return new ToolResult($callId, 'InterruptAgent needs `to` (the running sub-agent) and a non-empty `text`', true);
        }
        if (\strlen($text) > AgentMessage::MAX_TEXT_BYTES) {
            return new ToolResult($callId, sprintf('a message is at most %d bytes', AgentMessage::MAX_TEXT_BYTES), true);
        }

        $card = AgentRunCards::findRun($scope, $to, AgentRunCards::MAIN, $this->root);
        if ($card === null) {
            return new ToolResult($callId, sprintf(
                '"%s" is not a sub-agent you launched; Subagents `list` names yours',
                \SugarCraft\Crush\Context\PromptFence::escape($to),
            ), true);
        }
        if (!AgentRunCards::isLive($card)) {
            return new ToolResult($callId, sprintf(
                'sub-agent %s has %s, so there is no step to interrupt; SendMessage to it continues the conversation',
                $card['runId'],
                AgentRunCards::statusOf($card),
            ), true);
        }

        $inbox = AgentInbox::forSession($card['inboxSession'], null, $this->root);
        try {
            if ($inbox === null) {
                throw new \RuntimeException('there is no mailbox directory on this launch');
            }
            $message = $inbox->send($card['runId'], AgentMessage::new(AgentMessage::FROM_PARENT, $text, MessageMode::Interrupt));
        } catch (\Throwable $e) {
            return new ToolResult($callId, sprintf('the interrupt could not be delivered: %s', $e->getMessage()), true);
        }

        return new ToolResult($callId, sprintf(
            'Interrupt queued for sub-agent %s (agent "%s", message id %s): once its current tool call returns, the'
            . ' rest of that step is skipped and it reads your message next. Its answer arrives with its report.',
            $card['runId'],
            $card['agent'],
            $message->msgId,
        ));
    }
}
