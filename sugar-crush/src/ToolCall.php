<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Crush\Tools\ToolCall as EngineToolCall;

/**
 * Represents a request to call a tool/function.
 *
 * Tool calls are returned by the AI backend when it needs to
 * execute an action (like running a shell command, reading a
 * file, etc.) rather than just returning text.
 *
 * This is the Chat/TUI-side half of the two type pairs crush_feat.md §1 D
 * flags ("Two separate ToolCall/ToolResult type pairs ... with different
 * field names ... is itself a maintenance hazard"): {@see EngineToolCall}
 * (`Tools\ToolCall`) is the canonical pair every `ProviderInterface`,
 * {@see Runtime}, {@see \SugarCraft\Crush\Backend\EngineBackend} and
 * {@see \SugarCraft\Crush\Events\ToolStarted} already speak, while this
 * type is what {@see Message}'s `$toolCalls`/{@see Renderer} render. Rather
 * than duplicate the engine pair's field set here (or rewrite every
 * consumer of either pair at once), the two are reconciled by the explicit,
 * lossless {@see toEngineCall()}/{@see fromEngineCall()} adapters below --
 * the bridge crush_feat.md §1 E1's "point Chat::beginToolCalls()/
 * finishToolCalls() at Tools\ToolCall/Tools\ToolResult" needs. The adapters
 * live on this side only, so the engine-side pair stays unaware of the
 * TUI-side one and no dependency cycle is created between the namespaces.
 */
final class ToolCall
{
    /**
     * @param string $name The name of the tool to call
     * @param array<string, mixed> $arguments The arguments to pass to the tool
     * @param string|null $id Optional ID to match with ToolResult
     */
    public function __construct(
        public readonly string $name,
        public readonly array $arguments = [],
        public readonly ?string $id = null,
    ) {}

    /**
     * Create from an array (e.g., from JSON parse of backend response).
     *
     * @param array{name:string,arguments?:array<string,mixed>,id?:string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            arguments: $data['arguments'] ?? [],
            id: $data['id'] ?? null,
        );
    }

    /**
     * Adapt this Chat-side call to the canonical engine-side
     * {@see EngineToolCall} the live Runtime/EngineBackend/provider pipeline
     * speaks (crush_feat.md §1 E1).
     *
     * `Tools\ToolCall::$id` is non-nullable, so an id-less call falls back to
     * its name -- deliberately the same fallback {@see
     * Message::toolRunning()} already uses for `$pendingToolCallId`, so a
     * placeholder and its engine-side call key identically across the seam
     * and the replace-by-id lookup in Chat still finds its match.
     */
    public function toEngineCall(): EngineToolCall
    {
        return new EngineToolCall($this->id ?? $this->name, $this->name, $this->arguments);
    }

    /**
     * Adapt a canonical engine-side {@see EngineToolCall} (what every
     * `ProviderInterface` puts on `CompleteResponse::$toolCalls`) into the
     * Chat/Renderer-side shape. Inverse of {@see toEngineCall()}.
     */
    public static function fromEngineCall(EngineToolCall $call): self
    {
        return new self($call->name(), $call->arguments(), $call->id());
    }

    /**
     * Convert to array for serialization.
     *
     * @return array{name:string,arguments:array<string,mixed>,id?:string}
     */
    public function toArray(): array
    {
        $arr = ['name' => $this->name, 'arguments' => $this->arguments];
        if ($this->id !== null) {
            $arr['id'] = $this->id;
        }
        return $arr;
    }
}
