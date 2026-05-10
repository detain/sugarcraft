<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

/**
 * Represents a request to call a tool/function.
 *
 * Tool calls are returned by the AI backend when it needs to
 * execute an action (like running a shell command, reading a
 * file, etc.) rather than just returning text.
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
