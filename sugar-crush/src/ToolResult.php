<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

/**
 * Represents the result of a tool/function call.
 *
 * Tool results are added to the conversation history so the AI
 * can see the outcome of its requested action and respond
 * accordingly.
 */
final class ToolResult
{
    /**
     * @param string $name The name of the tool that was called
     * @param string $result The output/result from the tool
     * @param string|null $error Error message if the tool failed, null on success
     * @param string|null $id ID matching the corresponding ToolCall
     */
    public function __construct(
        public readonly string $name,
        public readonly string $result,
        public readonly ?string $error = null,
        public readonly ?string $id = null,
    ) {}

    /**
     * Create a successful result.
     */
    public static function ok(string $name, string $result, ?string $id = null): self
    {
        return new self($name, $result, null, $id);
    }

    /**
     * Create an error result.
     */
    public static function error(string $name, string $error, ?string $id = null): self
    {
        return new self($name, '', $error, $id);
    }

    /**
     * Convert to array for serialization (wire format).
     *
     * @return array{role:string,tool_call_id:string,name:string,content:string}
     */
    public function toWire(): array
    {
        return [
            'role' => 'tool',
            'tool_call_id' => $this->id ?? $this->name,
            'name' => $this->name,
            'content' => $this->error ?? $this->result,
        ];
    }

    public function isError(): bool
    {
        return $this->error !== null;
    }
}
