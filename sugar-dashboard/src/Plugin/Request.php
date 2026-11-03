<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Plugin;

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
     *
     * Parses at the boundary, mirroring {@see Response::fromJson()}:
     * a mistyped "type" or "data" field is a protocol violation raised as
     * InvalidArgumentException with a printable clipped preview — never a
     * PHP TypeError, and never raw hostile bytes inside the message.
     *
     * @throws \InvalidArgumentException If the line is not a valid request object.
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Invalid plugin JSON: ' . self::preview($json));
        }

        $type = $data['type'] ?? 'unknown';
        if (!is_string($type)) {
            throw new \InvalidArgumentException(
                'Plugin request "type" must be a string, got ' . get_debug_type($type) . ': ' . self::preview($json)
            );
        }

        $payload = $data['data'] ?? [];
        if (!is_array($payload)) {
            throw new \InvalidArgumentException(
                'Plugin request "data" must be an object, got ' . get_debug_type($payload) . ': ' . self::preview($json)
            );
        }

        return new self($type, $payload);
    }

    /**
     * Convert to JSON string.
     *
     * @throws \JsonException If the payload cannot be encoded (invalid UTF-8, inf/nan).
     */
    public function toJson(): string
    {
        return json_encode([
            'type' => $this->type,
            'data' => $this->data,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Printable, length-clipped rendering of an unparsable wire line.
     */
    private static function preview(string $json): string
    {
        $trimmed = trim($json);
        if (strlen($trimmed) > 120) {
            $trimmed = substr($trimmed, 0, 120) . '...';
        }

        return preg_replace('/[^\x20-\x7E]/', '?', $trimmed) ?? '';
    }
}
