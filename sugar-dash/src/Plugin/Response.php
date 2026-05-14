<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plugin;

/**
 * Response DTO for plugin communication.
 *
 * Represents a response from a plugin executable to the host.
 * Sent as line-delimited JSON over stdout.
 *
 * Mirrors the lattice JSON protocol.
 *
 * @readonly
 */
final class Response
{
    public function __construct(
        public readonly string $type,
        public readonly array $data = [],
    ) {}

    /**
     * Create an init response.
     *
     * @param string $name Module name
     * @param array{0:int,1:int} $minSize Minimum dimensions
     * @param int $interval Update interval in seconds (0 = no auto-update)
     */
    public static function init(string $name, array $minSize, int $interval = 0): self
    {
        return new self('init', [
            'name' => $name,
            'minSize' => $minSize,
            'interval' => $interval,
        ]);
    }

    /**
     * Create an update response.
     *
     * @param array<string, mixed> $state Updated module state
     */
    public static function update(array $state): self
    {
        return new self('update', ['state' => $state]);
    }

    /**
     * Create a view response.
     *
     * @param string $content Rendered content
     */
    public static function view(string $content): self
    {
        return new self('view', ['content' => $content]);
    }

    /**
     * Create an error response.
     *
     * @param string $message Error message
     */
    public static function error(string $message): self
    {
        return new self('error', ['message' => $message]);
    }

    /**
     * Create from JSON string.
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Invalid JSON: ' . $json);
        }
        return new self(
            $data['type'] ?? 'unknown',
            $data['data'] ?? [],
        );
    }

    /**
     * Convert to JSON string.
     */
    public function toJson(): string
    {
        return json_encode([
            'type' => $this->type,
            'data' => $this->data,
        ]);
    }
}
