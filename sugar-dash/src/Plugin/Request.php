<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plugin;

/**
 * Request DTO for plugin communication.
 *
 * Represents a request from the plugin host to a plugin executable.
 * Sent as line-delimited JSON over stdin.
 *
 * Mirrors the lattice JSON protocol.
 *
 * @readonly
 */
final class Request
{
    public function __construct(
        public readonly string $type,
        public readonly array $data = [],
    ) {}

    /**
     * Create an init request.
     */
    public static function init(): self
    {
        return new self('init');
    }

    /**
     * Create an update request.
     *
     * @param array<string, mixed> $state Current module state
     */
    public static function update(array $state): self
    {
        return new self('update', ['state' => $state]);
    }

    /**
     * Create a view request.
     *
     * @param int $width Available width
     * @param int $height Available height
     * @param array<string, mixed> $state Current module state
     */
    public static function view(int $width, int $height, array $state): self
    {
        return new self('view', [
            'width' => $width,
            'height' => $height,
            'state' => $state,
        ]);
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
