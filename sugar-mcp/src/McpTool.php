<?php

declare(strict_types=1);

namespace SugarCraft\Mcp;

/**
 * One tool definition advertised by an MCP server: name, description,
 * JSON-Schema input contract, and the owning server.
 *
 * Extracted from the sugar-crush MCP stack (src/MCP/McpTool.php).
 *
 * WHY `tryFromArray()` gates construction: a misbehaving server used to be
 * able to send `{"tools":[{"name":5}]}`, and the resulting TypeError escaped
 * the per-server RuntimeException handling and killed every registered server
 * instead of dropping one bogus definition. Well-typed-or-nothing is the fixed
 * contract: a definition we cannot build safely is a definition we did not
 * receive.
 */
final class McpTool
{
    /**
     * Wire type contract for each tool definition field, mirroring the
     * subscripts in fromArray(). serverName is deliberately absent: it is the
     * second parameter of fromArray(), never a wire field.
     *
     * @var array<string,string>
     */
    private const TOOL_DEFINITION_TYPES = [
        'name' => 'is_string',
        'description' => 'is_string',
        'inputSchema' => 'is_array',
    ];

    /**
     * @param array<string,mixed> $inputSchema
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $inputSchema,
        public readonly string $serverName,
    ) {
    }

    /**
     * Build from a wire tool definition. Absent or explicitly-null fields
     * collapse to their defaults (`''`, `[]`) — the `??` reads match the
     * well-typedness gate below, which uses isset() semantics.
     *
     * @param array<string,mixed> $definition
     */
    public static function fromArray(array $definition, string $serverName): self
    {
        return new self(
            name: (string) ($definition['name'] ?? ''),
            description: (string) ($definition['description'] ?? ''),
            inputSchema: (array) ($definition['inputSchema'] ?? []),
            serverName: $serverName,
        );
    }

    /**
     * Parse-then-trust factory: null unless every wire field carries its
     * declared type, so a malformed definition degrades to "skipped" instead
     * of an exception at the construction subscript.
     *
     * @param array<string,mixed> $definition
     */
    public static function tryFromArray(array $definition, string $serverName): ?self
    {
        if (!self::toolDefinitionIsWellTyped($definition)) {
            return null;
        }

        return self::fromArray($definition, $serverName);
    }

    /**
     * Every wire field must be present with its declared type.
     *
     * WHY isset() and not array_key_exists(): fromArray() reads every field
     * with `??`, which supplies the typed default for an ABSENT key and for an
     * explicit `null` alike — so `{"name":"write","description":null}` is
     * perfectly well-typed as far as the constructor is concerned.
     * array_key_exists() would call that key present, find `null` failing
     * `is_string()`, and drop a legitimate tool on the floor. isset() is false
     * for both shapes, which is exactly the question this method asks.
     *
     * @param array<string,mixed> $definition
     */
    private static function toolDefinitionIsWellTyped(array $definition): bool
    {
        foreach (self::TOOL_DEFINITION_TYPES as $field => $predicate) {
            if (!isset($definition[$field])) {
                continue;
            }

            if (!$predicate($definition[$field])) {
                return false;
            }
        }

        return true;
    }
}
