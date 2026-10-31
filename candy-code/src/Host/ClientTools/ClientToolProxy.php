<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\ClientTools;

use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Tools\DelegatesToEngine;
use SugarCraft\Code\Tools\ParallelSafe;
use SugarCraft\Code\Tools\RelaysClientToolCalls;
use SugarCraft\Code\Tools\TakesToolCallId;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolResult;

/**
 * The model-facing proxy of ONE client-registered tool.
 *
 * The model sees `client__<name>` carrying the client's own description and
 * input schema; what it cannot see is that the body of a call is a round trip
 * out of this process — turn child, serve parent, attached client, and back.
 * That distance decides every interface this class does and does not wear:
 *
 * - It is NOT {@see ParallelSafe}. A parallel member runs in its own grandchild
 *   fork, where the turn channel is not the channel — the C-TOOL-1.a
 *   grandchild refusal exists precisely because a question asked from below the
 *   owner process can never be answered. Executed as a barrier, this tool runs
 *   in the turn child itself, where {@see \SugarCraft\Code\Backend\ChildChannel::callClient()}
 *   owns the socket. Absence is the statement: there is no `isParallelSafe(): false`
 *   to implement, the interface simply is not there, and EngineExecutor's
 *   barrier rule handles the rest.
 * - It DOES implement {@see DelegatesToEngine}, for its documented side
 *   effect only — exactly the shape `AskUser` records for itself: every
 *   engine-bound tool is withheld from Task sub-agents and workflow stages.
 *   A sub-agent must not drive the user's browser; the no-op
 *   {@see withEngine()} carries that sentence.
 * - It DOES implement {@see TakesToolCallId} so the call id the cancel frames
 *   and the client both speak of is the engine's own, unforgeable.
 *
 * Refusals are model-facing results, never permission prompts: an absent
 * channel answers as an error ToolResult, which is prose for the model to
 * route around, and NOT a user string — this file carries no `Lang::t()` on
 * purpose (the same posture the MCP bridge keeps for its transport errors).
 *
 * Mirrors the client-hosted tool proxies of the comparison plan
 * (`client__*` over the turn channel), C-TOOL-6.h.
 */
final class ClientToolProxy implements Tool, DelegatesToEngine, RelaysClientToolCalls, TakesToolCallId
{
    /** The name space one client registration occupies. */
    public const NAME_PREFIX = 'client__';

    public function __construct(
        private readonly ClientToolSpec $spec,
        private readonly ?\Closure $caller = null,
    ) {}

    public function name(): string
    {
        return self::NAME_PREFIX . $this->spec->name;
    }

    public function description(): string
    {
        return $this->spec->description;
    }

    /** @return array<string, mixed> */
    public function inputSchema(): array
    {
        return $this->spec->inputSchema;
    }

    /** @param array<string, mixed> $args */
    public function execute(array $args): ToolResult
    {
        $toolCallId = (string) ($args['id'] ?? '');
        $arguments = $args;
        // The id is the engine's carrier, not part of the client's contract:
        // what the announced schema describes is everything else.
        unset($arguments['id']);

        if ($this->caller === null) {
            // No channel bound: an unforked blocking turn, a local single-shot
            // run. Refuse rather than block — there is no socket to wait on,
            // and inventing one would hold the event loop hostage to an answer
            // that structurally cannot arrive.
            return new ToolResult(
                toolCallId: $toolCallId,
                content: 'this turn has no client channel to carry the call to ' . $this->name() . '; the tool is only available on a serve-attached turn',
                isError: true,
            );
        }

        return ($this->caller)($toolCallId, $this->name(), $arguments, $this->spec->timeoutSeconds);
    }

    /**
     * Present for the sub-agent-withholding side effect alone (see the class
     * docblock); nothing on this tool reads the engine.
     */
    public function withEngine(
        EngineBackend $engine,
        ?\Closure $heartbeat = null,
        ?\Closure $subAgentEmitter = null,
    ): Tool {
        return $this;
    }

    /**
     * @param \Closure(string $toolCallId, string $name, array<string, mixed> $arguments, int $deadlineSeconds): ToolResult $caller
     */
    public function withClientCaller(\Closure $caller): Tool
    {
        return new self($this->spec, $caller);
    }

    /** The validated descriptor this proxy stands for. */
    public function spec(): ClientToolSpec
    {
        return $this->spec;
    }
}
